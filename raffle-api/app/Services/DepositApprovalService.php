<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Notifications\LegacyDepositApproved;
use App\Notifications\TicketPurchaseReceipt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the Financials admin page's
 * "Pending Deposits" queue has no equivalent anywhere on the new admin
 * console yet. Unlike Withdrawals/Support Tickets/Admin Audit Log, this
 * isn't two separate data stores to unify — legacy's manual bank-transfer
 * deposits (the ones its own AI screenshot check couldn't auto-clear)
 * only ever exist in ONE place, wp_raffle_transactions
 * (type='wallet_deposit'/'deposit_manual', status='pending'/'manual_review').
 * This service is a brand-new admin action that reads and settles that
 * same shared table directly — there's nothing to backfill.
 *
 * Since item 44 this also approves type='ticket_purchase' (a bank-
 * transfer ticket buy), issuing its tickets — previously only possible on
 * the legacy Financials page, which no longer exists.
 *
 * Always credits the new `wallets` table (plus a ledger entry) — the
 * only balance the live site reads since the WordPress site was retired.
 * This used to follow the legacy rk_wallets_unified_enabled switch,
 * which defaults to off, so an approval silently credited wp_usermeta
 * where the customer could never see or spend it.
 */
class DepositApprovalService
{
    private const APPROVABLE_TYPES = ['wallet_deposit', 'deposit_manual', 'ticket_purchase'];

    private const APPROVABLE_STATUSES = ['pending', 'manual_review'];

    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /** @return Collection<int, RaffleTransaction> */
    public function pending(): Collection
    {
        return RaffleTransaction::query()
            ->whereIn('type', self::APPROVABLE_TYPES)
            ->whereIn('status', self::APPROVABLE_STATUSES)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Approves a pending bank-transfer payment.
     *
     * A wallet top-up credits the customer's wallet (plus any configured
     * cashback). A ticket purchase (item 44 — previously only possible on
     * the retired legacy admin) issues exactly the tickets recorded on the
     * transaction; if that can't be done (numbers since taken, draw
     * already run, nothing recorded) it's refused with the reason, and
     * `$creditWalletInstead` lets the admin put the paid amount in the
     * customer's wallet so they can pick again — the money is never lost.
     *
     * The status check is repeated under a row lock inside the database
     * transaction, so two admins approving the same payment at the same
     * moment can't both credit it (the earlier version checked before
     * locking anything).
     *
     * @throws RuntimeException if this isn't an approvable pending payment, or its tickets can't be issued
     */
    public function approve(WpUser $admin, RaffleTransaction $transaction, bool $creditWalletInstead = false): RaffleTransaction
    {
        $this->guardApprovable($transaction);

        // Staff never approve their own payment (money-safety audit I2).
        if ((int) $admin->ID === (int) $transaction->user_id) {
            throw new RuntimeException('You can\'t approve your own payment. Ask another staff member.');
        }

        $bonusPercent = (float) config('payments.legacy_deposit_bonus_percent');
        $bonusAmount = 0.0;
        $ticketCount = 0;

        DB::transaction(function () use ($transaction, $bonusPercent, $creditWalletInstead, &$bonusAmount, &$ticketCount) {
            $locked = RaffleTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $this->guardApprovable($locked);

            if ($locked->type === 'ticket_purchase' && ! $creditWalletInstead) {
                $ticketCount = $this->issueTickets($locked);
                $locked->update(['status' => 'verified_final']);

                return;
            }

            $this->creditBalance($locked->user_id, 'wallet', (float) $locked->claimed_amount, 'deposit', $locked);

            $locked->update(['status' => 'verified_final']);

            // Same cashback mechanic Transaction Monitor's own approve
            // action already applies for this exact transaction type —
            // Financials page's own approve button is missing it (a
            // pre-existing legacy inconsistency between its two admin
            // pages, not something to carry over here).
            if ($locked->type === 'wallet_deposit' && $bonusPercent > 0) {
                $alreadyBonused = RaffleTransaction::query()
                    ->where('order_id', "Bonus for Txn #{$locked->id}")
                    ->where('type', 'deposit_bonus')
                    ->exists();

                if (! $alreadyBonused) {
                    $bonusAmount = round((float) $locked->claimed_amount * $bonusPercent, 2);
                    $this->creditBalance($locked->user_id, 'earnings', $bonusAmount, 'deposit_bonus', $locked);

                    RaffleTransaction::create([
                        'user_id' => $locked->user_id,
                        'claimed_amount' => $bonusAmount,
                        'status' => 'verified_final',
                        'type' => 'deposit_bonus',
                        'proof_url' => 'system_reward',
                        'order_id' => "Bonus for Txn #{$locked->id}",
                        'created_at' => now(),
                    ]);
                }
            }
        });

        $transaction->refresh();
        $isTicketIssue = $transaction->type === 'ticket_purchase' && ! $creditWalletInstead;

        $this->auditLog->record(
            $admin,
            match (true) {
                $isTicketIssue => 'ticket_purchase.approved',
                $transaction->type === 'ticket_purchase' => 'ticket_purchase.credited_to_wallet',
                default => 'deposit.approved',
            },
            RaffleTransaction::class,
            (int) $transaction->id,
            [
                'user_id' => $transaction->user_id,
                'amount' => (float) $transaction->claimed_amount,
                'bonus_amount' => $bonusAmount,
                'tickets_issued' => $ticketCount,
            ],
        );

        $transaction->user?->notify($isTicketIssue
            ? new TicketPurchaseReceipt($transaction, $ticketCount)
            : new LegacyDepositApproved($transaction));

        return $transaction;
    }

    /**
     * @throws RuntimeException if this transaction isn't an approvable pending deposit
     */
    public function reject(WpUser $admin, RaffleTransaction $transaction, ?string $reason = null): RaffleTransaction
    {
        $this->guardApprovable($transaction);

        DB::transaction(function () use ($transaction) {
            // Re-checked under a lock so a rejection can't overwrite an
            // approval that landed a moment earlier.
            $locked = RaffleTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $this->guardApprovable($locked);
            $locked->update(['status' => 'rejected']);
        });

        $this->auditLog->record($admin, 'deposit.rejected', RaffleTransaction::class, (int) $transaction->id, [
            'user_id' => $transaction->user_id,
            'amount' => (float) $transaction->claimed_amount,
            'type' => $transaction->type,
            'reason' => $reason,
        ]);

        return $transaction->fresh();
    }

    /**
     * The ticket numbers a pending bank-transfer ticket purchase is for.
     *
     * @return array<int, int>
     */
    public function pendingNumbers(RaffleTransaction $transaction): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $transaction->pending_numbers)),
            fn (int $n) => $n > 0,
        )));
    }

    /**
     * Issues the tickets recorded on a bank-transfer ticket purchase, the
     * same rows legacy's rk_issue_ticket_entries() wrote.
     *
     * @throws RuntimeException explaining why the tickets can't be issued
     */
    private function issueTickets(RaffleTransaction $transaction): int
    {
        $raffleId = (int) $transaction->pending_raffle_id;
        $numbers = $this->pendingNumbers($transaction);

        if ($raffleId <= 0 || $numbers === []) {
            throw new RuntimeException("Transaction #{$transaction->id} has no raffle or ticket numbers recorded, so no tickets can be issued. Use \"Credit wallet instead\" so the customer can pick their numbers.");
        }

        if (RaffleWinner::query()->where('raffle_id', $raffleId)->exists()) {
            throw new RuntimeException("Raffle #{$raffleId} has already been drawn, so these tickets can't take part. Use \"Credit wallet instead\" to give the customer their money back as wallet balance.");
        }

        $taken = RaffleEntry::query()->where('raffle_id', $raffleId)->whereIn('ticket_number', $numbers)->pluck('ticket_number')->all();

        if ($taken !== []) {
            throw new RuntimeException('Ticket number(s) '.implode(', ', $taken)." in raffle #{$raffleId} were bought by someone else while this payment waited. Use \"Credit wallet instead\" so the customer can pick new numbers.");
        }

        try {
            RaffleEntry::query()->insert(array_map(fn (int $number) => [
                'user_id' => $transaction->user_id,
                'raffle_id' => $raffleId,
                'ticket_number' => $number,
                'txn_id' => $transaction->id,
                'created_at' => now(),
            ], $numbers));
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException("Some of these numbers in raffle #{$raffleId} were just bought by someone else. Use \"Credit wallet instead\" so the customer can pick new numbers.");
        }

        return count($numbers);
    }

    private function guardApprovable(RaffleTransaction $transaction): void
    {
        if (! in_array($transaction->type, self::APPROVABLE_TYPES, true) || ! in_array($transaction->status, self::APPROVABLE_STATUSES, true)) {
            throw new RuntimeException("Transaction #{$transaction->id} is not a pending deposit (type: {$transaction->type}, status: {$transaction->status}).");
        }
    }

    private function creditBalance(int $userId, string $balanceType, float $amount, string $reason, RaffleTransaction $transaction): void
    {
        $this->ledger->credit(
            userId: $userId,
            balanceType: $balanceType,
            amount: round($amount, 2),
            reason: $reason,
            key: "{$reason}:raffle_transaction:{$transaction->id}",
            from: $reason === 'deposit' ? 'gateway_clearing' : 'promotions',
            referenceType: RaffleTransaction::class,
            referenceId: (int) $transaction->id,
        );
    }
}
