<?php

namespace App\Services;

use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Notifications\LegacyDepositApproved;
use Illuminate\Database\Eloquent\Collection;
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
 * Deliberately scoped to wallet top-ups only (NOT type='ticket_purchase',
 * legacy's manual-review path for a bank-transfer ticket buy). Approving
 * one of those must also issue real ticket numbers
 * (rk_issue_ticket_entries() in wp-core/admin-panel.php) — a delicate,
 * stateful operation this pass deliberately does not duplicate in a
 * second language. Those stay on the legacy Financials page for now.
 *
 * Respects whichever balance store legacy is currently using
 * (wp_usermeta vs. the new `wallets` table), read from
 * rk_wallets_unified_enabled in wp_options — same flag wallet-bridge.php
 * itself checks — so an approval from here lands wherever legacy's OWN
 * approve button would have put it, whichever page an admin happens to use.
 */
class DepositApprovalService
{
    private const APPROVABLE_TYPES = ['wallet_deposit', 'deposit_manual'];

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
     * @throws RuntimeException if this transaction isn't an approvable pending deposit
     */
    public function approve(WpUser $admin, RaffleTransaction $transaction): RaffleTransaction
    {
        $this->guardApprovable($transaction);

        $bonusPercent = (float) config('payments.legacy_deposit_bonus_percent');
        $bonusAmount = 0.0;

        DB::transaction(function () use ($transaction, $bonusPercent, &$bonusAmount) {
            $this->creditBalance($transaction->user_id, 'wallet', (float) $transaction->claimed_amount, 'deposit', $transaction);

            $transaction->update(['status' => 'verified_final']);

            // Same cashback mechanic Transaction Monitor's own approve
            // action already applies for this exact transaction type —
            // Financials page's own approve button is missing it (a
            // pre-existing legacy inconsistency between its two admin
            // pages, not something to carry over here).
            if ($transaction->type === 'wallet_deposit' && $bonusPercent > 0) {
                $alreadyBonused = RaffleTransaction::query()
                    ->where('order_id', "Bonus for Txn #{$transaction->id}")
                    ->where('type', 'deposit_bonus')
                    ->exists();

                if (! $alreadyBonused) {
                    $bonusAmount = round((float) $transaction->claimed_amount * $bonusPercent, 2);
                    $this->creditBalance($transaction->user_id, 'earnings', $bonusAmount, 'deposit_bonus', $transaction);

                    RaffleTransaction::create([
                        'user_id' => $transaction->user_id,
                        'claimed_amount' => $bonusAmount,
                        'status' => 'verified_final',
                        'type' => 'deposit_bonus',
                        'proof_url' => 'system_reward',
                        'order_id' => "Bonus for Txn #{$transaction->id}",
                        'created_at' => now(),
                    ]);
                }
            }
        });

        $this->auditLog->record($admin, 'deposit.approved', RaffleTransaction::class, (int) $transaction->id, [
            'user_id' => $transaction->user_id,
            'amount' => (float) $transaction->claimed_amount,
            'bonus_amount' => $bonusAmount,
        ]);

        $transaction->user->notify(new LegacyDepositApproved($transaction));

        return $transaction->fresh();
    }

    /**
     * @throws RuntimeException if this transaction isn't an approvable pending deposit
     */
    public function reject(WpUser $admin, RaffleTransaction $transaction, ?string $reason = null): RaffleTransaction
    {
        $this->guardApprovable($transaction);

        $transaction->update(['status' => 'rejected']);

        $this->auditLog->record($admin, 'deposit.rejected', RaffleTransaction::class, (int) $transaction->id, [
            'user_id' => $transaction->user_id,
            'amount' => (float) $transaction->claimed_amount,
            'reason' => $reason,
        ]);

        return $transaction->fresh();
    }

    private function guardApprovable(RaffleTransaction $transaction): void
    {
        if (! in_array($transaction->type, self::APPROVABLE_TYPES, true) || ! in_array($transaction->status, self::APPROVABLE_STATUSES, true)) {
            throw new RuntimeException("Transaction #{$transaction->id} is not a pending deposit (type: {$transaction->type}, status: {$transaction->status}).");
        }
    }

    private function creditBalance(int $userId, string $balanceType, float $amount, string $reason, RaffleTransaction $transaction): void
    {
        if (WpOption::flagEnabled('rk_wallets_unified_enabled')) {
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

            return;
        }

        $metaKey = $balanceType === 'wallet' ? 'wallet_balance' : 'earnings_balance';
        $meta = WpUserMeta::query()->where('user_id', $userId)->where('meta_key', $metaKey)->first();
        $current = (float) ($meta->meta_value ?? 0);

        if ($meta) {
            $meta->update(['meta_value' => $current + $amount]);
        } else {
            WpUserMeta::create(['user_id' => $userId, 'meta_key' => $metaKey, 'meta_value' => $current + $amount]);
        }
    }
}
