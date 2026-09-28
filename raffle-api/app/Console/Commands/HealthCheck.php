<?php

namespace App\Console\Commands;

use App\Services\Monitoring\HealthReport;
use Illuminate\Console\Command;

/**
 * OVERHAUL_CHECKLIST.md item 41 — a plain answer to "is the live site
 * actually able to take money, send email and run its background jobs?"
 *
 * The September 2026 gap audit found the live site running with no
 * payment-gateway keys, email set to "log only", and no queue worker —
 * so top-ups, password-reset codes and receipts all silently failed,
 * and nothing anywhere said so. This runs at the end of every deploy
 * (and can be run by hand any time) and prints what's missing.
 *
 * Only ever prints setting NAMES and OK/MISSING — never a value — so its
 * output is safe to leave in a deploy log.
 *
 * `--strict` exits non-zero if anything critical is wrong.
 */
class HealthCheck extends Command
{
    /** Cache key the scheduler refreshes every minute (routes/console.php). */
    public const SCHEDULER_HEARTBEAT_KEY = 'health:scheduler_heartbeat';

    protected $signature = 'app:health-check {--strict : Exit with an error code if anything critical is wrong}';

    protected $description = 'Report missing settings and whether background jobs are running (never prints secret values)';

    public function handle(HealthReport $report): int
    {
        $this->table(['Status', 'Check', 'What it means'], $report->run());

        $this->line('');
        $this->line("Critical problems: {$report->criticalCount()}   Warnings: {$report->warningCount()}");

        return ($this->option('strict') && $report->criticalCount() > 0) ? self::FAILURE : self::SUCCESS;
    }
}
