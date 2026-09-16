<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\TicketUnavailableException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Notifications\TicketPurchaseReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single settlement path for "a user paid, now give them tickets."
 *
 * This exists to close two confirmed problems from the audit:
 *
 *  - TD-06: the old code (api-financials.php) debits the wallet BEFORE
 *    inserting ticket rows, with no rollback if a ticket number turns out
 *    to be taken. A user can pay and get nothing. Here, the debit and the
 *    ticket allocation happen inside ONE database transaction — if any
 *    ticket number is taken, EVERYTHING rolls back, including the debit,
 *    before the caller ever sees a response.
 *
 *  - TD-05: the old code has three separate payment branches (wallet,
 *    earnings, bank-transfer) and only two of them insert ticket rows —
 *    the bank-transfer branch appears to never call the equivalent of
 *    recordEntriesForVerifiedTransaction() below. Here there is exactly
 *    ONE function that creates ticket entries, called by every funding
 *    path, so it cannot be silently skipped for one of them again.
 *
 * IMPORTANT — this operates on the NEW `wallets` table (see
 * app/Models/Wallet.php), not the old wp_usermeta wallet_balance/
 * earnings_balance rows the live PHP site still reads and writes. Do not
 * call this from anything user-facing until a real, deliberate cutover has
 * happened (see raffle-api/LEGACY_MIGRATION.md) — otherwise the old site
 * and this one will each think they know the "real" balance and disagree.
 */
class TicketPurchaseService
{
    public function __construct(
        private readonly TicketPricingService $pricing,
        private readonly WalletLedgerService $ledger,
    ) {}

    /**
     * Pay for tickets out of the user's wallet or earnings balance and
     * allocate the chosen ticket numbers, atomically.
     *
     * @param  int[]  $ticketNumbers
     * @param  'wallet'|'earnings'  $fundingSource
     * @param  string  $idempotencyKey  A key the CLIENT generates once per purchase attempt
     *                                  (e.g. a UUID created when the "Pay" button is first
     *                                  pressed) and resends unchanged on any retry. Prevents
     *                                  a double-tap or a retried request from charging twice.
     *
     * @throws InsufficientBalanceException
     * @throws TicketUnavailableException
     * @throws InvalidArgumentException if the submitted amount doesn't match the server-calculated price
     */
    public function purchaseFromBalance(
        WpUser $user,
        int $raffleId,
        array $ticketNumbers,
        float $unitPrice,
        bool $isGoldenBox,
        float $submittedAmount,
        string $fundingSource,
        string $idempotencyKey,
    ): RaffleTransaction {
        if (! in_array($fundingSource, ['wallet', 'earnings'], true)) {
            throw new InvalidArgumentException("Unknown funding source: {$fundingSource}");
        }

        if ($ticketNumbers === []) {
            throw new InvalidArgumentException('At least one ticket number is required.');
        }

        // Idempotency check FIRST, outside any lock — a retried request
        // with the same key gets back the original result instead of
        // being processed twice.
        $existing = RaffleTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        if (! $this->pricing->matchesExpectedPrice($submittedAmount, count($ticketNumbers), $unitPrice, $isGoldenBox)) {
            $expected = $this->pricing->calculate(count($ticketNumbers), $unitPrice, $isGoldenBox);

            throw new InvalidArgumentException(
                sprintf('Price mismatch: expected %.2f for %d ticket(s), received %.2f.', $expected, count($ticketNumbers), $submittedAmount)
            );
        }

        $balanceColumn = $fundingSource === 'wallet' ? 'wallet_balance' : 'earnings_balance';
        $transactionType = $fundingSource === 'wallet' ? 'ticket_purchase_wallet' : 'ticket_purchase_earnings';

        try {
            $transaction = DB::transaction(function () use (
                $user, $raffleId, $ticketNumbers, $submittedAmount,
                $balanceColumn, $transactionType, $idempotencyKey, $fundingSource
            ) {
                // Lock this user's wallet row for the duration of the
                // transaction — a concurrent purchase or transfer by the
                // same user has to wait, not read a stale balance.
                $wallet = Wallet::query()
                    ->where('user_id', $user->ID)
                    ->lockForUpdate()
                    ->first();

                $currentBalance = (float) ($wallet->{$balanceColumn} ?? 0);

                if ($currentBalance < $submittedAmount) {
                    throw new InsufficientBalanceException($submittedAmount - $currentBalance);
                }

                $wallet->{$balanceColumn} = $currentBalance - $submittedAmount;
                $wallet->save();

                $transaction = RaffleTransaction::create([
                    'user_id' => $user->ID,
                    'claimed_amount' => $submittedAmount,
                    'status' => 'verified_final',
                    'type' => $transactionType,
                    'proof_url' => $balanceColumn === 'wallet_balance' ? 'wallet_debit' : 'earnings_debit',
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Every balance mutation gets a permanent ledger entry,
                // in the same transaction as the mutation itself — see
                // WalletLedgerService.
                $this->ledger->recordDebit(
                    userId: $user->ID,
                    balanceType: $fundingSource,
                    amount: $submittedAmount,
                    reason: 'ticket_purchase',
                    referenceType: 'raffle_transaction',
                    referenceId: $transaction->id,
                );

                // Ticket allocation happens INSIDE the same transaction as
                // the debit above. If this throws, the debit and the
                // transaction row created a moment ago are rolled back too
                // — the fix for TD-06.
                $this->allocateEntries($raffleId, $user->ID, $ticketNumbers, $transaction->id);

                return $transaction;
            });

            // Fires AFTER the transaction commits — never inside it, so
            // a receipt can't go out for a purchase that then rolls back.
            $user->notify(new TicketPurchaseReceipt($transaction, count($ticketNumbers)));

            return $transaction;
        } catch (UniqueConstraintViolationException) {
            // The transaction above has already been rolled back by this
            // point — no balance was actually debited. Work out which of
            // the requested numbers are the problem so the caller can
            // show the user something useful (e.g. "pick again").
            throw new TicketUnavailableException($this->findUnavailable($raffleId, $ticketNumbers));
        }
    }

    /**
     * For a transaction that was verified by some OTHER means (a bank
     * transfer confirmed by admin or AI review, a future payment gateway
     * webhook) — this is the one function that must be called afterward
     * to actually create the tickets. Fixes TD-05: there is no longer a
     * payment path that can mark a transaction "verified" without also
     * being required to call this.
     *
     * @param  int[]  $ticketNumbers
     *
     * @throws TicketUnavailableException
     */
    public function recordEntriesForVerifiedTransaction(RaffleTransaction $transaction, int $raffleId, array $ticketNumbers): void
    {
        if ($transaction->status !== 'verified_final') {
            throw new InvalidArgumentException(
                "Transaction #{$transaction->id} is not verified_final (status: {$transaction->status}); refusing to allocate tickets for it."
            );
        }

        if ($ticketNumbers === []) {
            throw new InvalidArgumentException('At least one ticket number is required.');
        }

        try {
            DB::transaction(function () use ($raffleId, $transaction, $ticketNumbers) {
                $this->allocateEntries($raffleId, $transaction->user_id, $ticketNumbers, $transaction->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw new TicketUnavailableException($this->findUnavailable($raffleId, $ticketNumbers));
        }
    }

    /**
     * @param  int[]  $ticketNumbers
     */
    private function allocateEntries(int $raffleId, int $userId, array $ticketNumbers, int $transactionId): void
    {
        $now = now();

        RaffleEntry::query()->insert(array_map(
            fn (int $number) => [
                'user_id' => $userId,
                'raffle_id' => $raffleId,
                'ticket_number' => $number,
                'txn_id' => $transactionId,
                'created_at' => $now,
            ],
            $ticketNumbers
        ));
    }

    /**
     * @param  int[]  $requestedNumbers
     * @return int[]
     */
    private function findUnavailable(int $raffleId, array $requestedNumbers): array
    {
        return RaffleEntry::query()
            ->where('raffle_id', $raffleId)
            ->whereIn('ticket_number', $requestedNumbers)
            ->pluck('ticket_number')
            ->map(fn ($n) => (int) $n)
            ->values()
            ->all();
    }
}
