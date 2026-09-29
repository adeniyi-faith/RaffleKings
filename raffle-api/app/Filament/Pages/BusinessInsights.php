<?php

namespace App\Filament\Pages;

use App\Exceptions\AiUnavailableException;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Services\Ai\GeminiClient;
use App\Services\Reports\BusinessInsights as Insights;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Business insights (OVERHAUL_CHECKLIST.md item 36): the old WordPress
 * admin's "AI Insights (Brain)" page, rebuilt. The figures always show;
 * the AI summary is optional and needs the Gemini key.
 */
class BusinessInsights extends Page
{
    use GuardedByStaffRole;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Business insights';

    protected static ?string $title = 'Business insights';

    protected static string $view = 'filament.pages.business-insights';

    public string $period = 'week';

    public ?array $figures = null;

    public ?string $summary = null;

    public function mount(): void
    {
        $this->loadFigures();
    }

    public function getSubheading(): ?string
    {
        return 'How the business is doing, compared with the period before. Totals only: no customer details leave the site.';
    }

    public function updatedPeriod(): void
    {
        $this->summary = null;
        $this->loadFigures();
    }

    public function loadFigures(): void
    {
        if (! array_key_exists($this->period, Insights::PERIODS)) {
            $this->period = 'week';
        }

        $this->figures = app(Insights::class)->figures($this->period);
    }

    public function aiAvailable(): bool
    {
        return app(GeminiClient::class)->available();
    }

    public function writeSummary(): void
    {
        $this->loadFigures();

        try {
            $this->summary = app(Insights::class)->summary($this->figures);
        } catch (AiUnavailableException $e) {
            Notification::make()->title('The AI summary is not available right now')->body($e->getMessage())->danger()->send();
        }
    }
}
