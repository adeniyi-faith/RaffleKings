<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\Legacy\WpUserResource;
use App\Models\Legacy\WpUser;
use App\Services\Risk\FraudWatchService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Users → Fraud watch (item 45b): warning signs across all customers,
 * worked out fresh on each visit. Nothing here blocks anyone — it's a
 * list of things to look at; open a customer to decide (restrict, ban,
 * or reject a withdrawal).
 */
class FraudWatch extends Page
{
    use GuardedByStaffRole;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Fraud watch';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.fraud-watch';

    public int $days = 30;

    public function getSubheading(): ?string
    {
        return 'Warning signs, not proof. Open a customer to look closer before paying them.';
    }

    /** @return array{shared: Collection, rapid: Collection, cashouts: Collection, users: Collection} */
    public function getViewData(): array
    {
        $watch = app(FraudWatchService::class);
        $shared = $watch->sharedBankAccounts();
        $rapid = $watch->rapidTopUps($this->days);
        $cashouts = $watch->quickCashOuts($this->days);

        $ids = $shared->pluck('user_ids')->flatten()->merge($rapid->pluck('user_id'))->merge($cashouts->pluck('user_id'))->unique();
        $users = WpUser::query()->whereIn('ID', $ids)->get()->keyBy('ID');

        return compact('shared', 'rapid', 'cashouts', 'users');
    }

    public function profileUrl(int $userId): string
    {
        return WpUserResource::getUrl('view', ['record' => $userId]);
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = app(FraudWatchService::class)->quickCashOuts(30, pendingOnly: true)->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pending withdrawals that look like a quick cash-out';
    }
}
