<?php

namespace App\Services;

use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Admin actions on a draw's winners — crediting a prize and toggling
 * Hall of Fame visibility. The legacy site's rk_credit_raffle_winner()
 * checks `is_credited` and updates it in two separate, non-atomic
 * steps, so two concurrent "Pay Now" clicks on the same winner can both
 * pass the check before either flips the flag (audit TD-12: a real
 * double-payout race). Here, the winner row itself is locked for the
 * duration of the check-and-credit, closing that race.
 */
class WinnerManagementService
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /**
     * @throws RuntimeException if this winner has already been credited
     */
    public function credit(WpUser $admin, RaffleWinner $winner): RaffleWinner
    {
        return DB::transaction(function () use ($admin, $winner) {
            /** @var RaffleWinner $locked */
            $locked = RaffleWinner::query()->whereKey($winner->id)->lockForUpdate()->first();

            if ($locked->is_credited) {
                throw new RuntimeException("Winner #{$locked->id} has already been credited.");
            }

            $amount = (float) $locked->prize_cash_value;

            if ($amount > 0) {
                $wallet = Wallet::query()->where('user_id', $locked->user_id)->lockForUpdate()->first()
                    ?? Wallet::create(['user_id' => $locked->user_id, 'wallet_balance' => 0, 'earnings_balance' => 0]);

                $wallet->earnings_balance = (float) $wallet->earnings_balance + $amount;
                $wallet->save();

                $this->ledger->recordCredit(
                    userId: $locked->user_id,
                    balanceType: 'earnings',
                    amount: $amount,
                    reason: 'prize_payout',
                    referenceType: 'raffle_winner',
                    referenceId: $locked->id,
                );
            }

            $locked->update(['is_credited' => true]);

            $this->auditLog->record($admin, 'winner.credited', RaffleWinner::class, $locked->id, [
                'amount' => $amount,
                'user_id' => $locked->user_id,
            ]);

            return $locked;
        });
    }

    public function setVisibility(WpUser $admin, RaffleWinner $winner, bool $visible): RaffleWinner
    {
        $winner->update(['is_visible' => $visible]);

        $this->auditLog->record($admin, 'winner.visibility_toggled', RaffleWinner::class, $winner->id, [
            'is_visible' => $visible,
        ]);

        return $winner;
    }
}
