<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
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
        if ($transaction->status !== 'verified_final') {
            throw new RuntimeException("Transaction #{$transaction->id} is not verified (status: {$transaction->status}), nothing to revoke.");
        }

        $reversedBonus = 0.0;

        DB::transaction(function () use ($transaction, &$reversedBonus) {
            if (in_array($transaction->type, self::REVOCABLE_BALANCE_TYPES, true)) {
                $this->debitBalance($transaction->user_id, 'wallet', (float) $transaction->claimed_amount, 'transaction_revoked', $transaction);
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
            'reason' => $reason,
        ]);

        return $transaction->fresh();
    }

    private function debitBalance(int $userId, string $balanceType, float $amount, string $reason, RaffleTransaction $transaction): void
    {
        if (WpOption::flagEnabled('rk_wallets_unified_enabled')) {
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

            return;
        }

        $metaKey = $balanceType === 'wallet' ? 'wallet_balance' : 'earnings_balance';
        $meta = WpUserMeta::query()->where('user_id', $userId)->where('meta_key', $metaKey)->first();
        $current = (float) ($meta->meta_value ?? 0);

        if ($meta) {
            $meta->update(['meta_value' => $current - $amount]);
        } else {
            WpUserMeta::create(['user_id' => $userId, 'meta_key' => $metaKey, 'meta_value' => -$amount]);
        }
    }
}
