<?php

namespace App\Services;

use App\Exceptions\MinimumRedemptionNotMetException;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
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
    private const CONVERSION_RATE = 10; // points per naira

    private const MINIMUM_POINTS = 100;

    public function __construct(
        private readonly PointsService $points,
        private readonly WalletLedgerService $walletLedger,
    ) {}

    /**
     * @return array{redeemed_points: int, wallet_added: float, new_wallet_balance: float}
     *
     * @throws MinimumRedemptionNotMetException
     */
    public function redeem(WpUser $user): array
    {
        return DB::transaction(function () use ($user) {
            $currentPoints = $this->points->balance($user);

            if ($currentPoints < self::MINIMUM_POINTS) {
                throw new MinimumRedemptionNotMetException(self::MINIMUM_POINTS, $currentPoints);
            }

            $walletValue = intdiv($currentPoints, self::CONVERSION_RATE);

            $this->points->debit($user, $currentPoints, 'redemption', description: "Redeemed {$currentPoints} points for ₦{$walletValue}");

            $wallet = Wallet::query()->where('user_id', $user->ID)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $wallet->wallet_balance = (float) $wallet->wallet_balance + $walletValue;
            $wallet->save();

            $this->walletLedger->recordCredit(
                userId: $user->ID,
                balanceType: 'wallet',
                amount: $walletValue,
                reason: 'points_redemption',
                description: "Redeemed {$currentPoints} points.",
            );

            return [
                'redeemed_points' => $currentPoints,
                'wallet_added' => (float) $walletValue,
                'new_wallet_balance' => (float) $wallet->wallet_balance,
            ];
        });
    }
}
