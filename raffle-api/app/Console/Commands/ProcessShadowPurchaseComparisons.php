<?php

namespace App\Console\Commands;

use App\Models\ShadowPurchaseComparison;
use App\Services\ShadowPurchaseComparisonService;
use Illuminate\Console\Command;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 32 — works through the rows legacy
 * PHP queued in shadow_purchase_comparisons (one per real wallet/earnings
 * ticket purchase) and compares each against the new Laravel settlement
 * path. Idempotent: only ever touches 'pending' rows, so running it
 * again (or on a schedule — see routes/console.php) never re-compares
 * something already marked 'compared'.
 *
 * Usage:
 *   php artisan shadow:process-purchases
 *   php artisan shadow:process-purchases --limit=500
 */
class ProcessShadowPurchaseComparisons extends Command
{
    protected $signature = 'shadow:process-purchases {--limit=100}';

    protected $description = 'Compare queued real ticket purchases against what the new Laravel settlement path would have produced';

    public function handle(ShadowPurchaseComparisonService $comparisons): int
    {
        $limit = (int) $this->option('limit');
        $mismatches = 0;
        $processed = 0;

        ShadowPurchaseComparison::query()
            ->pending()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ShadowPurchaseComparison $row) use ($comparisons, &$mismatches, &$processed) {
                $comparisons->compare($row);
                $processed++;

                if ($row->hasMismatch()) {
                    $mismatches++;
                    $this->warn("Mismatch on shadow comparison #{$row->id} (user {$row->user_id}, raffle {$row->raffle_id}): ".json_encode($row->mismatch_details));
                }
            });

        $this->info("Compared {$processed} purchase(s), {$mismatches} mismatch(es) found.");

        return self::SUCCESS;
    }
}
