<?php

namespace App\Services;

use App\Models\Legacy\RaffleTransaction;
use Illuminate\Support\Collection;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the matching engine behind
 * legacy's Daily Audit page: given the credit transactions a bank
 * statement actually shows, find which of our own "verified" database
 * transactions for the same period are NOT backed by a real bank
 * credit — the exact same amount-matching logic legacy's own
 * rk_render_audit_page() uses (strict amount match, one bank credit
 * can only clear one database transaction, no double counting).
 *
 * Deliberately split from "get the credits out of an uploaded bank
 * statement" — legacy does that itself with a raw Gemini vision-model
 * call (base64-encoded file, a hand-written prompt, JSON parsing of
 * whatever text comes back). That's a file-upload + external-API
 * integration with its own real design questions (which AI provider,
 * how to validate/size-limit the upload, where the API key lives) —
 * out of scope for this pass. What IS built here is the actual audit
 * value: once a list of {amount, date} bank credits exists from ANY
 * source, this is what turns it into "these are the transactions that
 * don't check out" — the same list Transaction Monitor's new revoke
 * action (built in the previous item-36 PR) then acts on.
 *
 * @see TransactionMonitorService::revoke() for the action taken on a flagged transaction
 */
class AuditReconciliationService
{
    /**
     * @param  array<int, array{amount: float, date?: string, desc?: string}>  $bankCredits
     * @return Collection<int, array{transaction: RaffleTransaction, reason: string}>
     */
    public function reconcile(array $bankCredits, string $startDate, string $endDate): Collection
    {
        $transactions = RaffleTransaction::query()
            ->where('status', 'verified_final')
            ->where('type', '!=', 'withdrawal')
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->orderByDesc('id')
            ->get();

        $remainingCredits = array_map(static fn (array $c) => (float) $c['amount'], $bankCredits);

        $flagged = collect();

        foreach ($transactions as $transaction) {
            $matchIndex = null;

            foreach ($remainingCredits as $index => $amount) {
                if (abs($amount - (float) $transaction->claimed_amount) < 0.01) {
                    $matchIndex = $index;
                    break;
                }
            }

            if ($matchIndex === null) {
                $flagged->push([
                    'transaction' => $transaction,
                    'reason' => sprintf('Amount ₦%s not found in uploaded statement.', number_format((float) $transaction->claimed_amount)),
                ]);
            } else {
                // Prevent this same bank credit from clearing a second
                // database transaction at the same amount.
                unset($remainingCredits[$matchIndex]);
            }
        }

        return $flagged;
    }
}
