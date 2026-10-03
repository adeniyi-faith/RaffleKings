<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\BroadcastResource;
use App\Filament\Resources\RetentionOfferResource;
use App\Jobs\RefreshMemberSegments;
use App\Models\Legacy\WpUser;
use App\Services\Retention\ComebackOffers;
use App\Services\Retention\DeliveryTracker;
use App\Services\Retention\MemberSegments as Segments;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Growth → Member segments: how many customers are in each segment (new,
 * first-timers, consistent, drawing back, lapsed…), who moved this week,
 * how comeback offers are doing, and which channels customers answer.
 * Every segment has a "Message them" button. See App\Services\Retention.
 */
class MemberSegments extends Page
{
    use GuardedByStaffRole;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Growth';

    protected static ?string $navigationLabel = 'Member segments';

    protected static ?string $title = 'Member segments';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.member-segments';

    public function getSubheading(): ?string
    {
        $at = Segments::lastRefreshedAt();

        return 'Every customer is sorted again each night from their tickets, payments, wins, visits and how they answer messages. '
            .($at ? 'Last sorted '.$at->diffForHumans().'.' : 'Not sorted yet: press "Sort everyone now".');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Sort everyone now')->icon('heroicon-o-arrow-path')->color('gray')
                ->action(function () {
                    RefreshMemberSegments::dispatch();
                    Notification::make()->title('Sorting customers')->body('This runs in the background and usually takes a minute or two. Reload the page to see the new numbers.')->success()->send();
                }),
            Action::make('offers')->label('Comeback offers')->icon('heroicon-o-gift')->color('gray')
                ->url(RetentionOfferResource::getUrl()),
            Action::make('makeOffers')->label('Send today\'s offers now')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => ComebackOffers::enabled() && $this->staff()?->staffCan('money.pay'))
                ->requiresConfirmation()
                ->modalDescription('Offers normally go out by themselves once a day. This sends them now instead, within the budgets in Settings → Reminders → Comeback offers. Customers who already got one recently are skipped.')
                ->action(function () {
                    $made = app(ComebackOffers::class)->makeOffers();
                    Cache::put('retention-offers:'.now()->setTimezone(config('raffles.timezone'))->toDateString(), 1, now()->addDay());
                    Notification::make()->title($made === 1 ? '1 offer sent' : "{$made} offers sent")
                        ->body($made === 0 ? 'Nobody needed one right now, the budget is used up, or it is quiet hours.' : null)->success()->send();
                }),
        ];
    }

    private function staff(): ?WpUser
    {
        $user = auth('wordpress')->user();

        return $user instanceof WpUser ? $user : null;
    }

    public function summary(): array
    {
        return app(Segments::class)->summary();
    }

    public function moves(): array
    {
        return app(Segments::class)->recentMoves();
    }

    public function offers(): array
    {
        $offers = app(ComebackOffers::class);

        return [
            'enabled' => ComebackOffers::enabled(),
            'budget' => $offers->budgetLeft(),
            'performance' => $offers->performance(now()->subDays(30)),
        ];
    }

    public function channels(): array
    {
        return app(DeliveryTracker::class)->stats(['broadcast', 'offer', 'last_call'], since: now()->subDays(30));
    }

    public function messageUrl(?string $segment = null, ?string $flag = null): string
    {
        return BroadcastResource::getUrl('create', array_filter(['segment' => $segment, 'flag' => $flag]));
    }

    public static function percent(int|float $part, int|float $whole): string
    {
        return $whole > 0 ? round($part / $whole * 100).'%' : '–';
    }
}
