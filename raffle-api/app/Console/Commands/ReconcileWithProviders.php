<?php

namespace App\Console\Commands;

use App\Services\Money\ProviderReconciliation;
use Illuminate\Console\Command;

/** php artisan providers:reconcile: compare our payments and payouts with Paystack and Flutterwave. */
class ReconcileWithProviders extends Command
{
    protected $signature = 'providers:reconcile {--days=2 : how many days back to look}';

    protected $description = 'Compare our top-ups and payouts with what Paystack and Flutterwave say; differences go on the Needs checking list';

    public function handle(ProviderReconciliation $reconciliation): int
    {
        $r = $reconciliation->run((int) $this->option('days'));

        $this->info("Compared {$r['checked']} records: {$r['differences']} difference(s).");

        foreach ($r['skipped'] as $skipped) {
            $this->warn("Skipped: {$skipped}");
        }

        return $r['differences'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
