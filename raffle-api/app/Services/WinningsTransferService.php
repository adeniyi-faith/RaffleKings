<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves money from a customer's winnings into their spending wallet:
 * free, instant, any amount, and one way only (spending money can never
 * be moved into winnings, because winnings are what can be withdrawn).
 * The old site's Profile "Transfer" button only opened the top-up page;
 * there was no code for this at all.
 *
 * Same discipline as every other balance change: the wallet row is
 * locked, both balances change in one database transaction, and each
 * side gets a permanent ledger entry (reason "earnings_transfer").
 */
class WinningsTransferService
{
    public function __construct(private readonly WalletLedgerService $ledger) {}

    /**
     * @throws InvalidArgumentException for a zero or negative amount
     * @throws InsufficientBalanceException if the winnings don't cover it
     */
    public function transfer(WpUser $user, float $amount): Wallet
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Enter an amount to move.');
        }

        return DB::transaction(function () use ($user, $amount) {
            $wallet = Wallet::query()->where('user_id', $user->ID)->lockForUpdate()->first();

            $this->moveWithinLockedWallet($wallet, $amount, $user->ID);

            return $wallet;
        });
    }

    /**
     * The move itself, for a caller that already holds the wallet row's
     * lock inside its own transaction (TicketPurchaseService's "use
     * winnings to cover it"), so the move and the purchase succeed or
     * roll back together.
     *
     * @throws InsufficientBalanceException
     */
    public function moveWithinLockedWallet(?Wallet $wallet, float $amount, int $userId): void
    {
        $earnings = (float) ($wallet->earnings_balance ?? 0);

        if (! $wallet || $earnings + 0.001 < $amount) {
            throw new InsufficientBalanceException(round($amount - $earnings, 2));
        }

        $wallet->earnings_balance = round($earnings - $amount, 2);
        $wallet->wallet_balance = round((float) $wallet->wallet_balance + $amount, 2);
        $wallet->save();

        $debit = $this->ledger->recordDebit(
            userId: $userId,
            balanceType: 'earnings',
            amount: $amount,
            reason: 'earnings_transfer',
            description: 'Moved to spending wallet',
        );

        $this->ledger->recordCredit(
            userId: $userId,
            balanceType: 'wallet',
            amount: $amount,
            reason: 'earnings_transfer',
            referenceType: 'wallet_ledger_entry',
            referenceId: $debit->id,
            description: 'Moved from winnings',
        );
    }
}
