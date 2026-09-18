<?php

namespace App\Services;

use App\Models\Legacy\WpUser;
use App\Models\ReferralClick;
use App\Models\ReferralCommission;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Pays a referrer a commission on their referred user's first verified
 * deposit — same rule as the legacy site's rk_process_referral_commission()
 * (wp-core/api-financials.php), with two real fixes:
 *
 *  - The commission rate is a config value (config/referrals.php), not a
 *    number hardcoded inside a PHP function.
 *  - Whether a referee's commission has been paid is answered by a
 *    single real database row (one ReferralCommission per referee, ever
 *    — enforced by a unique constraint), not a usermeta boolean read
 *    under a different key than it's written (audit TD-33).
 *
 * NOT YET WIRED to a live trigger — there is no deposit code path in
 * Laravel yet (that's Phase 1 item 13, the payment gateway integration).
 * Call this once that exists, right after a deposit transaction is
 * marked verified.
 */
class ReferralCommissionService
{
    public function __construct(private readonly WalletLedgerService $ledger) {}

    /**
     * Who, if anyone, referred this user — read from the SAME
     * wp_usermeta `referred_by` value the legacy site sets at
     * registration (registration itself isn't in Laravel yet, so this
     * is a read-only bridge to that existing data).
     */
    public function referrerOf(WpUser $referee): ?WpUser
    {
        $referrerId = $referee->metaValue('referred_by');

        if (! $referrerId) {
            return null;
        }

        return WpUser::find((int) $referrerId);
    }

    /**
     * Pays the referrer their commission on this referee's deposit, if
     * eligible. Returns null (and pays nothing) if there's no referrer,
     * it would be a self-referral, the amount is non-positive, or a
     * commission for this referee has already been paid — mirrors every
     * safety check the legacy function makes.
     */
    public function payCommissionForFirstDeposit(WpUser $referee, float $depositAmount, ?int $depositTransactionId = null): ?ReferralCommission
    {
        $referrer = $this->referrerOf($referee);

        if (! $referrer || $referrer->ID === $referee->ID || $depositAmount <= 0) {
            return null;
        }

        if (ReferralCommission::query()->where('referee_user_id', $referee->ID)->exists()) {
            return null;
        }

        $rate = (float) config('referrals.commission_rate');
        $commission = round($depositAmount * $rate, 2);

        if ($commission <= 0) {
            return null;
        }

        return DB::transaction(function () use ($referrer, $referee, $depositAmount, $commission, $rate, $depositTransactionId) {
            $wallet = Wallet::query()->where('user_id', $referrer->ID)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $referrer->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $wallet->earnings_balance = (float) $wallet->earnings_balance + $commission;
            $wallet->save();

            $this->ledger->recordCredit(
                userId: $referrer->ID,
                balanceType: 'earnings',
                amount: $commission,
                reason: 'referral_commission',
                referenceType: 'raffle_transaction',
                referenceId: $depositTransactionId,
                description: "Referral commission for user #{$referee->ID}'s first deposit.",
            );

            return ReferralCommission::create([
                'referrer_user_id' => $referrer->ID,
                'referee_user_id' => $referee->ID,
                'deposit_amount' => $depositAmount,
                'commission_amount' => $commission,
                'commission_rate' => $rate,
                'deposit_transaction_id' => $depositTransactionId,
            ]);
        });
    }

    /**
     * A correct pending-vs-paid breakdown of everyone this user has
     * referred — reading `referred_by` for the "who did I refer" side is
     * still a legacy-data lookup (referrals themselves aren't created in
     * Laravel yet), but paid/pending status now comes from the single
     * real ReferralCommission row per referee, not two differently-named
     * usermeta keys. Same response shape as the now-fixed legacy
     * rk_get_referral_stats() (clicks/signups/earnings/history), so
     * either app's referral page can render off this endpoint.
     */
    public function stats(WpUser $referrer): array
    {
        $referees = WpUser::query()
            ->whereHas('meta', fn ($q) => $q->where('meta_key', 'referred_by')->where('meta_value', $referrer->ID))
            ->orderByDesc('user_registered')
            ->limit(20)
            ->get(['ID', 'display_name', 'user_registered']);

        $paid = ReferralCommission::query()
            ->whereIn('referee_user_id', $referees->pluck('ID'))
            ->orderByDesc('created_at')
            ->get();
        $paidByReferee = $paid->keyBy('referee_user_id');

        $history = [];
        foreach ($referees as $referee) {
            $commission = $paidByReferee->get($referee->ID);
            $history[] = $commission
                ? [
                    'user' => $referee->display_name,
                    'date' => $commission->created_at->diffForHumans(),
                    'status' => 'verified',
                    'amount' => (float) $commission->commission_amount,
                ]
                : [
                    'user' => $referee->display_name,
                    'date' => 'Registered',
                    'status' => 'pending',
                    'amount' => 0,
                ];
        }

        return [
            'referral_count' => $referees->count(),
            'signups' => $referees->count(),
            'total_earned' => (float) $paid->sum('commission_amount'),
            'earnings' => (float) $paid->sum('commission_amount'),
            'paid_count' => $paidByReferee->count(),
            'pending_count' => $referees->count() - $paidByReferee->count(),
            'clicks' => ReferralClick::query()->where('referrer_user_id', $referrer->ID)->count(),
            'referral_code' => $referrer->user_login,
            'history' => $history,
        ];
    }
}
