<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\Legacy\WpUserResource;
use App\Models\Growth\AffiliateEarning;
use App\Models\Legacy\WpUser;
use App\Models\ReferralCommission;
use App\Services\Growth\AffiliateService;
use App\Services\ReferralCommissionService;
use App\Services\Risk\AbuseDetector;
use App\Services\Risk\FraudWatchService;
use App\Support\Features;
use Filament\Notifications\Notification;
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

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $watch = app(FraudWatchService::class);
        $shared = $watch->sharedBankAccounts();
        $rapid = $watch->rapidTopUps($this->days);
        $cashouts = $watch->quickCashOuts($this->days);

        // Multi-account protection (Settings → On / off → New features).
        $abuseOn = Features::on('abuse_detection');
        $detector = app(AbuseDetector::class);
        $phones = $abuseOn ? $detector->sharedPhones() : collect();
        $devices = $abuseOn ? $detector->sharedDevices() : collect();
        $heldReferrals = ReferralCommission::query()->where('status', 'held')->latest('id')->limit(100)->get();
        $heldAffiliate = AffiliateEarning::query()->with('affiliate')->where('status', 'on_hold')->latest('id')->limit(100)->get();

        $ids = $shared->pluck('user_ids')->flatten()
            ->merge($rapid->pluck('user_id'))
            ->merge($cashouts->pluck('user_id'))
            ->merge($phones->pluck('user_ids')->flatten())
            ->merge($devices->pluck('user_ids')->flatten())
            ->merge($heldReferrals->pluck('referrer_user_id'))->merge($heldReferrals->pluck('referee_user_id'))
            ->merge($heldAffiliate->pluck('customer_id'))->merge($heldAffiliate->pluck('affiliate.user_id'))
            ->filter()->unique();
        $users = WpUser::query()->whereIn('ID', $ids)->get()->keyBy('ID');
        $abuseNotice = Features::offNotice('abuse_detection');

        return compact('shared', 'rapid', 'cashouts', 'users', 'phones', 'devices', 'heldReferrals', 'heldAffiliate', 'abuseOn', 'abuseNotice');
    }

    /** A held referral commission looks fine: pay it. */
    public function releaseReferral(int $id): void
    {
        $this->guardMoney();
        $commission = ReferralCommission::query()->find($id);
        $done = $commission && app(ReferralCommissionService::class)->releaseHeld($commission);

        Notification::make()->title($done ? 'Commission paid' : 'It was already handled')->{$done ? 'success' : 'warning'}()->send();
    }

    public function cancelReferral(int $id): void
    {
        $this->guardMoney();
        $commission = ReferralCommission::query()->find($id);
        $done = $commission && app(ReferralCommissionService::class)->cancelHeld($commission);

        Notification::make()->title($done ? 'Commission cancelled. It will never be paid.' : 'It was already handled')->{$done ? 'success' : 'warning'}()->send();
    }

    public function releaseAffiliate(int $id): void
    {
        $this->guardMoney();
        $earning = AffiliateEarning::query()->find($id);
        $done = $earning && app(AffiliateService::class)->approve($earning);

        Notification::make()->title($done ? 'Approved. It is paid when its hold ends.' : 'It was already handled')->{$done ? 'success' : 'warning'}()->send();
    }

    public function cancelAffiliate(int $id): void
    {
        $this->guardMoney();
        $earning = AffiliateEarning::query()->find($id);
        $done = $earning && app(AffiliateService::class)->cancel($earning, 'Cancelled by staff on Fraud watch.');

        Notification::make()->title($done ? 'Earning cancelled' : 'It was already handled')->{$done ? 'success' : 'warning'}()->send();
    }

    private function guardMoney(): void
    {
        abort_unless(static::staffCan('money.pay'), 403);
    }

    public function profileUrl(int $userId): string
    {
        return WpUserResource::getUrl('view', ['record' => $userId]);
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = app(FraudWatchService::class)->quickCashOuts(30, pendingOnly: true)->count()
                + ReferralCommission::query()->where('status', 'held')->count()
                + AffiliateEarning::query()->where('status', 'on_hold')->count();
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
        return 'Pending withdrawals that look like a quick cash-out, and rewards held for a check';
    }
}
