<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUserMeta;
use App\Models\WithdrawalRequest;
use Illuminate\Console\Command;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — legacy withdrawal requests are
 * rows in wp_raffle_transactions (type='withdrawal'); this app's own
 * withdrawal_requests table (item 13/19) is a separate, real queue that,
 * until withdrawal-bridge.php, only the new frontend's own withdraw flow
 * ever populated. Before that bridge's flag can safely go live, every
 * withdrawal request legacy already has — pending, paid, or rejected —
 * needs a matching row here, or the new admin queue would either show
 * nothing (if only pending ones matter) or a payout history with huge,
 * silent gaps.
 *
 * Legacy only ever stored the amount actually sent (`claimed_amount`) on
 * this row — not the original requested amount or the verification fee
 * separately. Historical import is necessarily best-effort here:
 * requested_amount and amount_to_send are both set to claimed_amount and
 * fee_amount to 0, since there is no way to recover the original split
 * after the fact. This does not affect new requests going forward — those
 * get the real numbers from withdrawal-bridge.php at creation time.
 *
 * Bank account resolution mirrors withdrawal-bridge.php's own
 * rk_withdrawal_bridge_resolve_bank_account_id(): legacy's txn_ref holds
 * the bank account's own stable per-user 'id' from the rk_bank_accounts
 * usermeta array; this matches by that id, falls back to the primary (or
 * first) account on file, and creates a bank_accounts row on the fly by
 * account_number if the wallet backfill hasn't reached it yet. A request
 * from a user with no bank account on file anywhere is skipped and
 * reported — there's nothing valid to link it to.
 *
 * Idempotent: every imported row carries the source transaction's id in
 * `legacy_transaction_id` (a unique column), so re-running only imports
 * whatever's new since the last run.
 *
 * Usage:
 *   php artisan legacy:import-withdrawal-requests
 *   php artisan legacy:import-withdrawal-requests --dry-run
 */
class ImportLegacyWithdrawalRequests extends Command
{
    protected $signature = 'legacy:import-withdrawal-requests {--dry-run}';

    protected $description = 'One-time backfill of legacy withdrawal requests into the new withdrawal_requests table';

    private const STATUS_MAP = [
        'pending' => 'pending',
        'completed' => 'paid',
        'verified_final' => 'paid',
        'rejected' => 'rejected',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $imported = 0;
        $skippedNoBank = 0;
        $skippedUnknownStatus = 0;

        RaffleTransaction::query()
            ->where('type', 'withdrawal')
            ->orderBy('id')
            ->chunk(100, function ($legacyTxns) use ($dryRun, &$imported, &$skippedNoBank, &$skippedUnknownStatus) {
                foreach ($legacyTxns as $legacyTxn) {
                    $status = self::STATUS_MAP[$legacyTxn->status] ?? null;

                    if ($status === null) {
                        $this->warn("legacy txn #{$legacyTxn->id}: unrecognized status '{$legacyTxn->status}', skipped");
                        $skippedUnknownStatus++;

                        continue;
                    }

                    $bankAccountId = $this->resolveBankAccountId($legacyTxn->user_id, $legacyTxn->txn_ref, $dryRun);

                    if (! $bankAccountId) {
                        $this->warn("legacy txn #{$legacyTxn->id}: user {$legacyTxn->user_id} has no resolvable bank account, skipped");
                        $skippedNoBank++;

                        continue;
                    }

                    $this->line(sprintf('%s legacy txn #%d (user %d, %s, ₦%s)', $dryRun ? '[dry-run]' : '[import]', $legacyTxn->id, $legacyTxn->user_id, $status, $legacyTxn->claimed_amount));

                    if ($dryRun) {
                        continue;
                    }

                    $request = WithdrawalRequest::query()->firstOrCreate(
                        ['legacy_transaction_id' => $legacyTxn->id],
                        [
                            'user_id' => $legacyTxn->user_id,
                            'bank_account_id' => $bankAccountId,
                            'requested_amount' => $legacyTxn->claimed_amount,
                            'fee_amount' => 0,
                            'amount_to_send' => $legacyTxn->claimed_amount,
                            'status' => $status,
                            'created_at' => $legacyTxn->created_at,
                            'updated_at' => $legacyTxn->created_at,
                        ],
                    );

                    if ($request->wasRecentlyCreated) {
                        $imported++;
                    }
                }
            });

        $this->info(sprintf(
            '%s %d withdrawal request(s) imported, %d skipped (no bank account), %d skipped (unrecognized status).',
            $dryRun ? 'Dry run complete —' : 'Done —',
            $imported,
            $skippedNoBank,
            $skippedUnknownStatus,
        ));

        return self::SUCCESS;
    }

    private function resolveBankAccountId(int $userId, ?string $legacyAccountId, bool $dryRun): ?int
    {
        $metaRow = WpUserMeta::query()
            ->where('user_id', $userId)
            ->where('meta_key', 'rk_bank_accounts')
            ->first();

        $accounts = $metaRow ? $this->unserializeWpMeta($metaRow->meta_value) : null;
        $accounts = is_array($accounts) ? $accounts : [];

        $chosen = null;
        foreach ($accounts as $account) {
            if (isset($account['id']) && (string) $account['id'] === (string) $legacyAccountId) {
                $chosen = $account;
                break;
            }
        }
        if (! $chosen) {
            foreach ($accounts as $account) {
                if (! empty($account['is_primary'])) {
                    $chosen = $account;
                    break;
                }
            }
        }
        if (! $chosen && ! empty($accounts)) {
            $chosen = $accounts[0];
        }
        if (! $chosen || empty($chosen['account_number'])) {
            return null;
        }

        $existing = BankAccount::query()
            ->where('user_id', $userId)
            ->where('account_number', $chosen['account_number'])
            ->first();

        if ($existing) {
            return $existing->id;
        }

        if ($dryRun) {
            // Report as resolvable without actually creating the row.
            return -1;
        }

        return BankAccount::create([
            'user_id' => $userId,
            'bank_name' => $chosen['bank_name'] ?? 'Unknown',
            'account_number' => $chosen['account_number'],
            'account_name' => $chosen['account_name'] ?? '',
            'is_primary' => (bool) ($chosen['is_primary'] ?? false),
        ])->id;
    }

    /**
     * WordPress stores array meta values with PHP's native serialize(),
     * not JSON. Guard against a corrupt/unexpected value rather than
     * letting unserialize() throw or emit a warning into the console.
     */
    private function unserializeWpMeta(?string $value): mixed
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'a:') || str_starts_with($value, 'O:')) {
            $result = @unserialize($value);

            return $result === false && $value !== 'b:0;' ? null : $result;
        }

        return json_decode($value, true);
    }
}
