<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\WalletLedgerService;
use Illuminate\Console\Command;

/**
 * One-time (repeatable) step after legacy:backfill-wallets: gives every
 * wallet an "opening balance" ledger entry, so the ledger becomes the
 * complete, reconstructable record of a balance going forward — this
 * command deliberately does NOT invent a history for money that moved
 * before this migration existed; it honestly records "this is what the
 * balance was the day the ledger started," the same way a real set of
 * books would when adopted mid-business.
 *
 * Idempotent: a user/balance_type that already has ANY ledger entry
 * (from this command or from real activity via WalletLedgerService) is
 * left alone, so this never double-counts an opening balance.
 *
 * Usage:
 *   php artisan legacy:reconcile-wallet-ledger
 *   php artisan legacy:reconcile-wallet-ledger --dry-run
 */
class ReconcileWalletLedger extends Command
{
    protected $signature = 'legacy:reconcile-wallet-ledger {--dry-run}';

    protected $description = 'Give every wallet without ledger history an opening-balance entry, so the ledger fully explains every balance from here on';

    public function handle(WalletLedgerService $ledger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;

        foreach (Wallet::all() as $wallet) {
            foreach (['wallet_balance' => 'wallet', 'earnings_balance' => 'earnings'] as $column => $balanceType) {
                $amount = (float) $wallet->{$column};

                if ($amount <= 0) {
                    continue; // nothing to explain — an empty ledger already reconstructs to 0
                }

                $alreadyHasHistory = WalletLedgerEntry::query()
                    ->where('user_id', $wallet->user_id)
                    ->where('balance_type', $balanceType)
                    ->exists();

                if ($alreadyHasHistory) {
                    continue;
                }

                $this->line(sprintf('%s user %d: opening %s balance %.2f', $dryRun ? '[dry-run]' : '[reconcile]', $wallet->user_id, $balanceType, $amount));

                if (! $dryRun) {
                    $ledger->recordCredit(
                        userId: $wallet->user_id,
                        balanceType: $balanceType,
                        amount: $amount,
                        reason: 'opening_balance',
                        description: 'Balance carried in from wp_usermeta at ledger adoption time — see legacy:backfill-wallets.',
                    );
                }

                $created++;
            }
        }

        $this->info($dryRun
            ? "Dry run complete — {$created} opening-balance entr(y/ies) would be created."
            : "Created {$created} opening-balance entr(y/ies).");

        return self::SUCCESS;
    }
}
