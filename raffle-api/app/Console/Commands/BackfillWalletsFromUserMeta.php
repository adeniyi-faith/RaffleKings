<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Console\Command;

/**
 * One-time (repeatable) backfill: copies wallet_balance, earnings_balance,
 * and rk_bank_accounts out of wp_usermeta into the new wallets/bank_accounts
 * tables, per the audit's TD-26 recommendation.
 *
 * Safe to run more than once, PROVIDED the wallet hasn't started seeing
 * real activity of its own yet — it upserts wallets by user_id and only
 * inserts a bank account if an identical one (same user/bank/account
 * number) isn't already present.
 *
 * This command is READ-ONLY against wp_usermeta. It does not delete or
 * modify anything in WordPress, so the existing rk-core plugin keeps
 * working unchanged until you deliberately switch its write paths over —
 * do that module by module, not by dropping usermeta afterward.
 *
 * IMPORTANT (Phase 3 item 33): once wallet-bridge.php's
 * rk_wallets_unified_enabled() flag is turned ON for real traffic, this
 * command becomes UNSAFE to run unscoped — it is a full-snapshot
 * overwrite of each `wallets` row from whatever wp_usermeta currently
 * holds, not an incremental sync. Legacy usermeta stops being updated for
 * users going through the unified path (see wallet-bridge.php), so
 * re-running this would stomp a real, current `wallets` balance with a
 * now-stale legacy number. To prevent that: any user whose ledger already
 * has an entry for a reason OTHER than 'opening_balance' (i.e. has seen
 * real activity recorded by WalletLedgerService — a purchase, a deposit,
 * a withdrawal, a transfer) is skipped entirely, not just left alone —
 * their `wallets` row is not touched even to update it.
 *
 * Usage:
 *   php artisan legacy:backfill-wallets            # backfill everyone eligible
 *   php artisan legacy:backfill-wallets --dry-run   # report counts only
 */
class BackfillWalletsFromUserMeta extends Command
{
    protected $signature = 'legacy:backfill-wallets {--dry-run}';

    protected $description = 'Copy wallet_balance/earnings_balance/rk_bank_accounts out of wp_usermeta into wallets and bank_accounts';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->backfillWallets($dryRun);
        $this->backfillBankAccounts($dryRun);

        return self::SUCCESS;
    }

    private function backfillWallets(bool $dryRun): void
    {
        $balances = WpUserMeta::query()
            ->whereIn('meta_key', ['wallet_balance', 'earnings_balance'])
            ->get()
            ->groupBy('user_id');

        $count = 0;
        $skipped = 0;

        foreach ($balances as $userId => $rows) {
            $hasRealActivity = WalletLedgerEntry::query()
                ->where('user_id', $userId)
                ->where('reason', '!=', 'opening_balance')
                ->exists();

            if ($hasRealActivity) {
                $this->warn("user {$userId}: skipped — wallet already has real activity, backfilling would overwrite it");
                $skipped++;

                continue;
            }

            $wallet = $rows->firstWhere('meta_key', 'wallet_balance');
            $earnings = $rows->firstWhere('meta_key', 'earnings_balance');

            $data = [
                'wallet_balance' => (float) ($wallet->meta_value ?? 0),
                'earnings_balance' => (float) ($earnings->meta_value ?? 0),
            ];

            $this->line(sprintf(
                'user %d: wallet=%.2f earnings=%.2f',
                $userId,
                $data['wallet_balance'],
                $data['earnings_balance']
            ));

            if (! $dryRun) {
                Wallet::updateOrCreate(['user_id' => $userId], $data);
            }

            $count++;
        }

        $this->info("Wallets: {$count} user(s) processed, {$skipped} skipped (already have real activity)".($dryRun ? ' (dry run, nothing written)' : '.'));
    }

    private function backfillBankAccounts(bool $dryRun): void
    {
        $rows = WpUserMeta::query()
            ->where('meta_key', 'rk_bank_accounts')
            ->get();

        $created = 0;

        foreach ($rows as $row) {
            $accounts = $this->unserializeWpMeta($row->meta_value);

            if (! is_array($accounts)) {
                $this->warn("user {$row->user_id}: rk_bank_accounts value could not be parsed, skipped");

                continue;
            }

            foreach ($accounts as $account) {
                $bankName = $account['bank_name'] ?? null;
                $accountNumber = $account['account_number'] ?? null;
                $accountName = $account['account_name'] ?? null;

                if (! $bankName || ! $accountNumber || ! $accountName) {
                    continue;
                }

                $exists = BankAccount::query()
                    ->where('user_id', $row->user_id)
                    ->where('account_number', $accountNumber)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $this->line("user {$row->user_id}: {$bankName} / {$accountNumber}");

                if (! $dryRun) {
                    BankAccount::create([
                        'user_id' => $row->user_id,
                        'bank_name' => $bankName,
                        'account_number' => $accountNumber,
                        'account_name' => $accountName,
                        'is_primary' => (bool) ($account['is_primary'] ?? false),
                    ]);
                }

                $created++;
            }
        }

        $this->info("Bank accounts: {$created} account(s) processed".($dryRun ? ' (dry run, nothing written)' : '.'));
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
