<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpUserMeta;
use App\Models\PointLedgerEntry;
use App\Models\Raffle;
use App\Models\UserPoints;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * OVERHAUL_CHECKLIST.md item 41 — read-only: "has every customer's money,
 * points and bank details from the old site made it into the new tables?"
 *
 * The live site now reads balances ONLY from `wallets` / `user_points` /
 * `bank_accounts`. The one-time copy commands (legacy:backfill-wallets,
 * legacy:reconcile-points, ...) are never run by the deploy, so anyone
 * they missed sees ₦0 / 0 points / no bank account.
 *
 * Three states per customer:
 *  - copied:        a new-table row exists (or there was nothing to copy)
 *  - not copied:    old-site value > 0, no new-table row yet — running
 *                   the copy commands fixes these automatically
 *  - needs review:  old-site value > 0, but the customer has ALREADY used
 *                   the new wallet/points without their old balance ever
 *                   being carried over (no opening-balance entry). The copy
 *                   commands deliberately skip these to avoid overwriting
 *                   real new activity, so an admin must credit the old
 *                   amount by hand (Users → Adjust balance). Listed by
 *                   user id and amount so nobody is forgotten.
 *
 * Writes nothing, anywhere.
 */
class LegacyMigrationStatus extends Command
{
    protected $signature = 'legacy:migration-status {--limit=25 : How many "needs review" customers to list}';

    protected $description = 'Read-only report of old-site balances, points and bank accounts not yet carried into the new tables';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->reportWallets($limit);
        $this->reportPoints($limit);
        $this->reportBankAccounts();
        $this->reportRaffles();

        return self::SUCCESS;
    }

    private function reportWallets(int $limit): void
    {
        $legacy = $this->legacyAmounts(['wallet_balance', 'earnings_balance']);
        $wallets = Wallet::query()->whereIn('user_id', $legacy->keys())->pluck('user_id')->flip();
        $opened = WalletLedgerEntry::query()->whereIn('user_id', $legacy->keys())->where('reason', 'opening_balance')->distinct()->pluck('user_id')->flip();
        $active = WalletLedgerEntry::query()->whereIn('user_id', $legacy->keys())->where('reason', '!=', 'opening_balance')->distinct()->pluck('user_id')->flip();

        $this->summarise('Wallet & winnings balances', '₦', $legacy, $wallets, $opened, $active, $limit, 'legacy:backfill-wallets, then legacy:reconcile-wallet-ledger');
    }

    private function reportPoints(int $limit): void
    {
        $legacy = $this->legacyAmounts(['rk_points']);
        $records = UserPoints::query()->whereIn('user_id', $legacy->keys())->pluck('user_id')->flip();
        $opened = PointLedgerEntry::query()->whereIn('user_id', $legacy->keys())->where('reason', 'opening_balance')->distinct()->pluck('user_id')->flip();
        $active = PointLedgerEntry::query()->whereIn('user_id', $legacy->keys())->where('reason', '!=', 'opening_balance')->distinct()->pluck('user_id')->flip();

        $this->summarise('Reward points', '', $legacy, $records, $opened, $active, $limit, 'legacy:reconcile-points');
    }

    private function reportBankAccounts(): void
    {
        $withLegacy = WpUserMeta::query()
            ->where('meta_key', 'rk_bank_accounts')
            ->where('meta_value', '!=', '')
            ->where('meta_value', '!=', 'a:0:{}')
            ->distinct()
            ->pluck('user_id');

        $withNew = BankAccount::query()->whereIn('user_id', $withLegacy)->distinct()->pluck('user_id')->flip();
        $missing = $withLegacy->reject(fn ($id) => $withNew->has($id))->count();

        $this->newLine();
        $this->info('Bank accounts');
        $this->line("  Customers with saved bank details on the old site: {$withLegacy->count()}");
        $this->line('  Copied: '.($withLegacy->count() - $missing)."   Not copied: {$missing}".($missing > 0 ? '  → run legacy:backfill-wallets' : ''));
    }

    private function reportRaffles(): void
    {
        $posts = WpPost::query()->where('post_type', 'raffle')->pluck('ID');
        $imported = Raffle::query()->whereIn('legacy_post_id', $posts)->count();
        $missing = $posts->count() - $imported;

        $this->newLine();
        $this->info('Raffles');
        $this->line("  Raffles on the old site: {$posts->count()}");
        $this->line("  Copied: {$imported}   Not copied: {$missing}".($missing > 0 ? '  → run legacy:import-raffles (the deploy does this automatically)' : ''));
    }

    /**
     * Old-site total per user (summing the given usermeta keys), for users
     * where that total is above zero.
     *
     * @param  array<int, string>  $keys
     * @return Collection<int, float> keyed by user id
     */
    private function legacyAmounts(array $keys): Collection
    {
        return WpUserMeta::query()
            ->whereIn('meta_key', $keys)
            ->get(['user_id', 'meta_value'])
            ->groupBy('user_id')
            ->map(fn ($rows) => round($rows->sum(fn ($r) => (float) $r->meta_value), 2))
            ->filter(fn (float $total) => $total > 0);
    }

    /**
     * @param  Collection<int, float>  $legacy
     * @param  Collection<int, int>  $hasRow
     * @param  Collection<int, int>  $hasOpening
     * @param  Collection<int, int>  $hasActivity
     */
    private function summarise(string $title, string $unit, Collection $legacy, Collection $hasRow, Collection $hasOpening, Collection $hasActivity, int $limit, string $fixCommand): void
    {
        $notCopied = $legacy->filter(fn ($amount, $userId) => ! $hasRow->has($userId));
        $needsReview = $legacy->filter(fn ($amount, $userId) => $hasRow->has($userId) && ! $hasOpening->has($userId) && $hasActivity->has($userId));
        $copied = $legacy->count() - $notCopied->count() - $needsReview->count();

        $fmt = fn (float $v) => $unit.number_format($v, $unit === '' ? 0 : 2);

        $this->newLine();
        $this->info($title);
        $this->line("  Customers with a balance on the old site: {$legacy->count()} (total {$fmt($legacy->sum())})");
        $this->line("  Copied: {$copied}");
        $this->line("  Not copied yet: {$notCopied->count()} (total {$fmt($notCopied->sum())})".($notCopied->isNotEmpty() ? "  → run {$fixCommand}" : ''));
        $this->line("  Needs manual review: {$needsReview->count()} (total {$fmt($needsReview->sum())})");

        if ($needsReview->isNotEmpty()) {
            $this->warn('  These customers used the new site before their old balance was carried over.');
            $this->warn('  Credit each old amount by hand (admin → Users → Adjust balance):');
            $this->table(['User id', 'Old-site amount'], $needsReview->sortDesc()->take($limit)->map(fn ($amount, $userId) => [$userId, $fmt($amount)])->values()->all());
        }
    }
}
