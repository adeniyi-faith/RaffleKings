<?php

namespace App\Filament\Resources\ReferralCommissionResource\Widgets;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralCommission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Referral totals shown above the Referrals list (item 45). */
class ReferralStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $top = ReferralCommission::query()
            ->selectRaw('referrer_user_id, COUNT(*) as friends, SUM(commission_amount) as earned')
            ->groupBy('referrer_user_id')
            ->orderByDesc('earned')
            ->first();

        $topName = $top ? (WpUser::find($top->referrer_user_id)?->display_name ?? "User #{$top->referrer_user_id}") : null;

        // Signed up with someone's link but haven't topped up yet — the
        // commission for these is still to come.
        $referredTotal = WpUserMeta::query()->where('meta_key', 'referred_by')->where('meta_value', '!=', '')->count();
        $paidCount = ReferralCommission::query()->count();

        return [
            Stat::make('Commissions paid', '₦'.number_format((float) ReferralCommission::query()->sum('commission_amount')))
                ->description($paidCount.' referred friend(s) have topped up'),
            Stat::make('Referred, not topped up yet', (string) max(0, $referredTotal - $paidCount))
                ->description('Signed up with a referral link'),
            Stat::make('Top referrer', $topName ?? '—')
                ->description($top ? $top->friends.' friend(s) · ₦'.number_format((float) $top->earned).' earned' : 'No referrals yet'),
        ];
    }
}
