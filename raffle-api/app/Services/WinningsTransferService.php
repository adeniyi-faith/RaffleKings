<?php

namespace App\Services;

use App\Exceptions\DuplicatePostingException;
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
     * @param  string  $idempotencyKey  made once per tap by the app; a retry with
     *                                  the same key moves nothing a second time
     *
     * @throws InvalidArgumentException for a zero or negative amount
     * @throws InsufficientBalanceException if the winnings don't cover it
     */
    public function transfer(WpUser $user, float $amount, string $idempotencyKey): Wallet
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Enter an amount to move.');
        }

        return DB::transaction(function () use ($user, $amount, $idempotencyKey) {
            try {
                $this->ledger->move(
                    userId: $user->ID,
                    fromBalance: 'earnings',
                    toBalance: 'wallet',
                    amount: $amount,
                    reason: 'earnings_transfer',
                    key: "earnings_transfer:{$user->ID}:{$idempotencyKey}",
                    description: 'Moved from winnings to spending wallet',
                    customerAction: 'transfer',
                );
            } catch (DuplicatePostingException) {
                // The same tap arrived twice: the first one already moved it.
            }

            return $this->ledger->lockWallet($user->ID);
        }, 3);
    }

    /**
     * The move itself, for a caller already inside its own transaction
     * (TicketPurchaseService's "use winnings to cover it"), so the move and
     * the purchase succeed or roll back together.
     *
     * @throws InsufficientBalanceException
     */
    public function moveWithinLockedWallet(int $userId, float $amount, string $key): void
    {
        $this->ledger->move(
            userId: $userId,
            fromBalance: 'earnings',
            toBalance: 'wallet',
            amount: round($amount, 2),
            reason: 'earnings_transfer',
            key: $key,
            description: 'Moved from winnings to cover a ticket purchase',
            customerAction: 'spend',
        );
    }
}
