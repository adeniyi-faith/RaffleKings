<?php

namespace App\Services\Money;

use App\Models\MoneyReviewItem;
use App\Models\Wallet;
use App\Services\Monitoring\StaffAlerts;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The nightly books check (money-safety audit G1, G4):
 *
 *  1. Every wallet balance equals what its ledger entries add up to.
 *  2. Every journal adds up to zero (what the customer gained, the business
 *     gave up, in whole kobo).
 *  3. No balance is below zero.
 *
 * Anything wrong becomes an item on the "Needs checking" list (so it has a
 * date and an owner) and staff get one alert with the count. A wallet whose
 * ledger never had an opening balance (money from before the ledger) is
 * reported as "no history", not as a drift.
 */
class LedgerIntegrity
{
    /**
     * @return array{wallets: int, drifts: int, unbalanced: int, negative: int, no_history: int, unprotected?: int}
     */
    public function run(): array
    {
        $result = ['wallets' => 0, 'drifts' => 0, 'unbalanced' => 0, 'negative' => 0, 'no_history' => 0];

        $columns = ['wallet' => 'wallet_balance', 'earnings' => 'earnings_balance', 'held' => 'held_balance'];

        // 1 and 3: one pass over wallets, the ledger totals fetched per batch in one query.
        Wallet::query()->orderBy('id')->chunkById(500, function ($wallets) use (&$result, $columns) {
            $ids = $wallets->pluck('user_id')->all();

            $totals = DB::table('wallet_ledger_entries')
                ->whereIn('user_id', $ids)
                ->selectRaw("user_id, balance_type, SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS total, COUNT(*) AS entries")
                ->groupBy('user_id', 'balance_type')
                ->get()
                ->groupBy('user_id');

            foreach ($wallets as $wallet) {
                $result['wallets']++;

                foreach ($columns as $type => $column) {
                    $stored = Money::kobo((string) $wallet->getRawOriginal($column));
                    $row = $totals->get($wallet->user_id)?->firstWhere('balance_type', $type);
                    $ledger = $row ? Money::kobo((string) $row->total) : 0;

                    if ($stored < 0) {
                        $result['negative']++;
                        MoneyReviewItem::raise('negative_balance', "{$wallet->user_id}:{$type}", "Customer #{$wallet->user_id} has a negative {$type} balance", 'Stored '.Money::naira($stored));
                    }

                    if ($stored === $ledger) {
                        continue;
                    }

                    if (! $row) {
                        $result['no_history']++;
                        MoneyReviewItem::raise('ledger_no_history', "{$wallet->user_id}:{$type}", "Customer #{$wallet->user_id}: {$type} balance of ₦".number_format($stored / 100, 2).' has no ledger history', 'Money from before the ledger. Run legacy:reconcile-wallet-ledger to record it as an opening balance.');

                        continue;
                    }

                    $result['drifts']++;
                    MoneyReviewItem::raise(
                        'ledger_drift',
                        "{$wallet->user_id}:{$type}",
                        "Customer #{$wallet->user_id}: {$type} balance does not match the ledger",
                        'The wallet says ₦'.number_format($stored / 100, 2).' but the ledger adds up to ₦'.number_format($ledger / 100, 2).'.',
                    );
                }
            }
        });

        // 2: journals that don't add up to zero.
        $unbalanced = DB::select("
            SELECT j.id, j.business_key,
                   COALESCE((SELECT SUM(CASE WHEN e.direction = 'credit' THEN e.amount ELSE -e.amount END) FROM wallet_ledger_entries e WHERE e.journal_id = j.id), 0)
                 + COALESCE((SELECT SUM(CASE WHEN s.direction = 'credit' THEN s.amount ELSE -s.amount END) FROM ledger_system_entries s WHERE s.journal_id = j.id), 0) AS total
            FROM ledger_journals j
        ");

        foreach ($unbalanced as $journal) {
            if (Money::kobo(number_format((float) $journal->total, 2, '.', '')) !== 0) {
                $result['unbalanced']++;
                MoneyReviewItem::raise('unbalanced_journal', (string) $journal->id, "Money movement {$journal->business_key} does not add up to zero", 'It is off by ₦'.number_format((float) $journal->total, 2).'.');
            }
        }

        // 4: the database's own lock on the books (refuses edits and deletes) is still in place.
        $missing = $this->unprotectedTables();

        if ($missing !== []) {
            $result['unprotected'] = count($missing);
            MoneyReviewItem::raise('books_unprotected', 'triggers', 'The database is not locking the ledger and audit log against edits', 'Missing on: '.implode(', ', $missing).'. The database user may lack the right to create triggers (on MySQL with binary logging, log_bin_trust_function_creators must be on). Ask the host to allow it; the triggers are created by the 2026_10_24_000005 migration.');
        }

        // Anything that was open before and is fine now closes by itself.
        $this->closeFixed($result);

        $problems = $result['drifts'] + $result['unbalanced'] + $result['negative'];

        if ($problems > 0) {
            StaffAlerts::send("The nightly money check found {$problems} problem(s): {$result['drifts']} balance(s) not matching the ledger, {$result['unbalanced']} movement(s) not adding up, {$result['negative']} negative balance(s). See Needs checking.", 'ledger-check-'.now()->toDateString(), 1440);
        }

        return $result;
    }

    /** @return list<string> the protected tables that have no "refuse edits" trigger */
    private function unprotectedTables(): array
    {
        $tables = ['wallet_ledger_entries', 'ledger_system_entries', 'ledger_journals', 'admin_audit_logs'];
        $driver = DB::getDriverName();

        try {
            $names = match (true) {
                $driver === 'sqlite' => DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all(),
                in_array($driver, ['mysql', 'mariadb'], true) => DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')->pluck('TRIGGER_NAME')->all(),
                default => null,
            };
        } catch (\Throwable) {
            return [];
        }

        if ($names === null) {
            return [];
        }

        return array_values(array_filter($tables, fn ($t) => ! in_array("{$t}_no_update", $names, true)));
    }

    /** An open ledger item the check no longer finds is resolved by the system. */
    private function closeFixed(array $result): void
    {
        $seen = fn (string $kind) => MoneyReviewItem::query()->where('kind', $kind)->where('status', 'open')->where('updated_at', '<', now()->subMinutes(30))->get();

        foreach (['ledger_drift', 'ledger_no_history', 'negative_balance', 'unbalanced_journal', 'books_unprotected'] as $kind) {
            foreach ($seen($kind) as $item) {
                $item->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => 'The nightly check no longer finds this.']);
            }
        }
    }
}
