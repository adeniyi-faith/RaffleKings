<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — legacy's Transaction Monitor
 * page (and, with slightly different completeness, its Daily Audit
 * page's own AI bank-statement reconciliation) let an admin browse
 * every non-withdrawal transaction and REVOKE one that was verified in
 * error — undoing the wallet credit, deleting any tickets it bought,
 * and reversing any cashback bonus it triggered. Nothing on the new
 * admin console could do this at all before this pass; approving a
 * still-pending deposit is already covered by DepositApprovalService,
 * so this is specifically the "undo something that already happened"
 * capability.
 *
 * Same single-shared-table situation as the deposit-approval queue —
 * this isn't two data stores to unify, just a real admin action with no
 * new-side equivalent yet.
 *
 * Revocable types deliberately match legacy's own Transaction Monitor
 * revoke logic (wallet_deposit, wallet_payment) PLUS deposit_manual —
 * legacy's own revoke omits deposit_manual, but that type really does
 * move real money once DepositApprovalService::approve() credits it, so
 * leaving it out here would mean a deposit_manual mistake approved
 * through the NEW queue could never be undone through the NEW monitor.
 * That's a real correctness gap this new code introduces, not a
 * pre-existing legacy inconsistency to faithfully reproduce.
 */
class TransactionMonitorService
{
    private const REVOCABLE_BALANCE_TYPES = ['wallet_deposit', 'wallet_payment', 'deposit_manual'];

    /**
     * Ticket purchases paid from a balance (item 45): reversing one
     * cancels the tickets AND gives the money back to where it came from.
     * These used to fall through to "delete the tickets" only, so the
     * customer lost both the tickets and the money.
     */
    private const REFUNDABLE_PURCHASE_TYPES = [
        'ticket_purchase_wallet' => 'wallet',
        'ticket_purchase_earnings' => 'earnings',
    ];

    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /** @return Collection<int, RaffleTransaction> */
    public function recent(?string $period = null, int $limit = 100): Collection
    {
        $query = RaffleTransaction::query()
            ->where('type', '!=', 'withdrawal')
            ->orderByDesc('id')
            ->limit($limit);

        match ($period) {
            'today' => $query->whereDate('created_at', now()->toDateString()),
            'yesterday' => $query->whereDate('created_at', now()->subDay()->toDateString()),
            'week' => $query->where('created_at', '>=', now()->subDays(7)),
            'month' => $query->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
            default => null,
        };

        return $query->get();
    }

    /**
     * @throws RuntimeException if the transaction isn't currently verified
     */
    public function revoke(WpUser $admin, RaffleTransaction $transaction, ?string $reason = null): RaffleTransaction
    {
        $this->guardRevocable($transaction);

        $reversedBonus = 0.0;
        $refunded = 0.0;

        DB::transaction(function () use ($transaction, &$reversedBonus, &$refunded) {
            // Re-checked under a lock (item 45) so two clicks can't reverse
            // the same transaction twice.
            $this->guardRevocable(RaffleTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail());

            if (in_array($transaction->type, self::REVOCABLE_BALANCE_TYPES, true)) {
                $this->debitBalance($transaction->user_id, 'wallet', (float) $transaction->claimed_amount, 'transaction_revoked', $transaction);
            }

            if ($source = self::REFUNDABLE_PURCHASE_TYPES[$transaction->type] ?? null) {
                $refunded = (float) $transaction->claimed_amount;
                $this->creditBalance($transaction->user_id, $source, $refunded, 'ticket_purchase_refunded', $transaction);
            }

            $transaction->update(['status' => 'rejected']);

            RaffleEntry::query()->where('txn_id', $transaction->id)->delete();

            $bonusTxn = RaffleTransaction::query()
                ->where('order_id', "Bonus for Txn #{$transaction->id}")
                ->where('type', 'deposit_bonus')
                ->where('status', 'verified_final')
                ->first();

            if ($bonusTxn) {
                $reversedBonus = (float) $bonusTxn->claimed_amount;
                $this->debitBalance($bonusTxn->user_id, 'earnings', $reversedBonus, 'transaction_revoked_bonus', $bonusTxn);
                $bonusTxn->update(['status' => 'reversed']);
            }
        });

        $this->auditLog->record($admin, 'transaction.revoked', RaffleTransaction::class, (int) $transaction->id, [
            'user_id' => $transaction->user_id,
            'amount' => (float) $transaction->claimed_amount,
            'reversed_bonus' => $reversedBonus,
            'refunded' => $refunded,
            'reason' => $reason,
        ]);

        return $transaction->fresh();
    }

    /** @throws RuntimeException if the transaction isn't currently verified */
    private function guardRevocable(RaffleTransaction $transaction): void
    {
        if ($transaction->status !== 'verified_final') {
            throw new RuntimeException("Transaction #{$transaction->id} is not verified (status: {$transaction->status}), nothing to revoke.");
        }
    }

    private function creditBalance(int $userId, string $balanceType, float $amount, string $reason, RaffleTransaction $transaction): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->lockForUpdate()->first()
            ?? Wallet::create(['user_id' => $userId, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        $column = $balanceType === 'wallet' ? 'wallet_balance' : 'earnings_balance';
        $wallet->{$column} = (float) $wallet->{$column} + $amount;
        $wallet->save();

        $this->ledger->recordCredit(
            userId: $userId,
            balanceType: $balanceType,
            amount: $amount,
            reason: $reason,
            referenceType: RaffleTransaction::class,
            referenceId: (int) $transaction->id,
        );
    }

    private function debitBalance(int $userId, string $balanceType, float $amount, string $reason, RaffleTransaction $transaction): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->lockForUpdate()->first()
            ?? Wallet::create(['user_id' => $userId, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        $column = $balanceType === 'wallet' ? 'wallet_balance' : 'earnings_balance';
        $wallet->{$column} = (float) $wallet->{$column} - $amount;
        $wallet->save();

        $this->ledger->recordDebit(
            userId: $userId,
            balanceType: $balanceType,
            amount: $amount,
            reason: $reason,
            referenceType: RaffleTransaction::class,
            referenceId: (int) $transaction->id,
        );
    }
}
