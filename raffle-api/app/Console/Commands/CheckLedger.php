<?php

namespace App\Console\Commands;

use App\Services\Money\LedgerIntegrity;
use Illuminate\Console\Command;

/** php artisan ledger:check: the nightly books check (see LedgerIntegrity). */
class CheckLedger extends Command
{
    protected $signature = 'ledger:check';

    protected $description = 'Check every wallet against the ledger and every money movement adds up; anything wrong goes on the Needs checking list';

    public function handle(LedgerIntegrity $integrity): int
    {
        $r = $integrity->run();

        $this->info("Checked {$r['wallets']} wallets: {$r['drifts']} not matching the ledger, {$r['unbalanced']} movements not adding up, {$r['negative']} negative balances, {$r['no_history']} with no ledger history.");

        return $r['drifts'] + $r['unbalanced'] + $r['negative'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
