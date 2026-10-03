<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\DailyDropResource;
use App\Filament\Resources\RaffleResource;
use App\Models\AdvisorReport;
use App\Models\Legacy\WpUser;
use App\Services\Advisor\PlatformSnapshot;
use App\Services\Advisor\RaffleAdvisor as Advisor;
use App\Services\Ai\GeminiClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Raffles → Raffle advisor. Shows what the advisor sees (totals only),
 * its latest advice, and lets staff ask for fresh advice or open a
 * suggested raffle as a draft. See App\Services\Advisor\RaffleAdvisor.
 */
class RaffleAdvisor extends Page
{
    use GuardedByStaffRole;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Raffles';

    protected static ?string $navigationLabel = 'Raffle advisor';

    protected static ?string $title = 'Raffle advisor';

    protected static string $view = 'filament.pages.raffle-advisor';

    public ?int $reportId = null;

    public string $focus = '';

    public function getSubheading(): ?string
    {
        return 'Advice on raffles, prizes, prices and offers, based on how your players behave. It only suggests: nothing changes on the site unless you open a suggestion as a draft and publish it yourself.';
    }

    public function aiAvailable(): bool
    {
        return app(GeminiClient::class)->available();
    }

    public function report(): ?AdvisorReport
    {
        return $this->reportId
            ? AdvisorReport::query()->find($this->reportId)
            : AdvisorReport::query()->latest('id')->first();
    }

    /** @return Collection<int, AdvisorReport> */
    public function history(): Collection
    {
        return AdvisorReport::query()->latest('id')->limit(10)->get(['id', 'status', 'trigger', 'focus', 'created_at']);
    }

    /** The totals to show: the report's own, or today's when there is no report yet. */
    public function snapshot(): array
    {
        return $this->report()?->snapshot
            ?? Cache::remember('raffle-advisor:snapshot', now()->addMinutes(10), fn () => app(PlatformSnapshot::class)->build());
    }

    public function showReport(int $id): void
    {
        $this->reportId = $id;
    }

    public function askAdvisor(): void
    {
        $this->validate(['focus' => ['nullable', 'string', 'max:1000']]);

        if (! $this->aiAvailable()) {
            Notification::make()->title('The advisor is not set up yet')->body('Add a Gemini key in Settings → AI, and make sure "AI helpers on" is switched on.')->danger()->send();

            return;
        }

        if (AdvisorReport::query()->where('status', 'pending')->where('created_at', '>=', now()->subMinutes(15))->exists()) {
            Notification::make()->title('The advisor is already working on a report')->body('It will appear here when it is ready.')->warning()->send();

            return;
        }

        $admin = auth('wordpress')->user();
        $report = app(Advisor::class)->request($this->focus, $admin instanceof WpUser ? $admin : null);

        $this->reportId = $report->id;
        $this->focus = '';
        Notification::make()->title('The advisor is thinking')->body('This usually takes a minute or two. The page updates by itself.')->success()->send();
    }

    public function openDraft(int $index): void
    {
        $report = $this->report();
        $admin = auth('wordpress')->user();

        if (! $report || ! $admin instanceof WpUser) {
            return;
        }

        try {
            ['raffle' => $raffle, 'drop' => $drop] = app(Advisor::class)->openAsDraft($report, $index, $admin);
        } catch (RuntimeException $e) {
            Notification::make()->title('Not opened')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title(match (true) {
                $raffle && $drop => 'Draft raffle and Daily Drop created',
                $drop !== null => 'Draft Daily Drop created',
                default => 'Draft raffle created',
            })
            ->body(collect([
                $raffle ? 'The raffle is hidden from customers: check the prizes, dates and wording, then set it to Published.' : null,
                $drop ? 'The Daily Drop pays nothing until it is switched on in Raffles → Daily Drops'.($raffle ? '' : ' (choose its raffle there first)').'.' : null,
            ])->filter()->implode(' '))
            ->success()
            ->persistent()
            ->send();

        $this->redirect($raffle
            ? RaffleResource::getUrl('edit', ['record' => $raffle])
            : DailyDropResource::getUrl('edit', ['record' => $drop]));
    }
}
