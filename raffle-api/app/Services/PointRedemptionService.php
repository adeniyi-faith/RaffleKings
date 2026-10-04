<?php

namespace App\Services;

use App\Exceptions\MinimumRedemptionNotMetException;
use App\Models\Legacy\WpUser;
use App\Models\PointLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Converts a user's ENTIRE points balance to wallet cash in one shot —
 * same rule as the legacy rk_handle_redeem_points()
 * (wp-core/api-gamification.php): 10 points = ₦1, minimum 100 points,
 * no partial redemption. Credits the NEW `wallets` table (see
 * TicketPurchaseService's docblock for why — this is the same
 * "don't write real money to two different places" discipline).
 */
class PointRedemptionService
{
    public function __construct(
        private readonly PointsService $points,
        private readonly WalletLedgerService $walletLedger,
        private readonly AccountRestrictions $restrictions,
    ) {}

    /**
     * @return array{redeemed_points: int, wallet_added: float, new_wallet_balance: float}
     *
     * @throws MinimumRedemptionNotMetException
     */
    public function redeem(WpUser $user): array
    {
        return DB::transaction(function () use ($user) {
            $this->restrictions->assertCanMoveMoney($user->ID, 'redeem');

            $currentPoints = $this->points->balance($user);
            // Both editable in Settings → Rewards (config/rewards.php).
            $minimum = (int) config('rewards.minimum_redeem_points');

            if ($currentPoints < $minimum) {
                throw new MinimumRedemptionNotMetException($minimum, $currentPoints);
            }

            $walletValue = intdiv($currentPoints, max(1, (int) config('rewards.points_per_naira')));

            $this->points->debit($user, $currentPoints, 'redemption', description: "Redeemed {$currentPoints} points for ₦{$walletValue}");

            // Keyed by the points-ledger row that paid for it: one redemption, one credit.
            $pointsEntryId = PointLedgerEntry::query()->where('user_id', $user->ID)->where('reason', 'redemption')->max('id');

            $this->walletLedger->credit(
                userId: $user->ID,
                balanceType: 'wallet',
                amount: $walletValue,
                reason: 'points_redemption',
                key: "points_redemption:{$pointsEntryId}",
                from: 'promotions',
                referenceType: 'point_ledger_entry',
                referenceId: $pointsEntryId,
                description: "Redeemed {$currentPoints} points.",
                customerAction: 'redeem',
            );

            $wallet = $this->walletLedger->lockWallet($user->ID);

            return [
                'redeemed_points' => $currentPoints,
                'wallet_added' => (float) $walletValue,
                'new_wallet_balance' => (float) $wallet->wallet_balance,
            ];
        });
    }
}
