<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\Legacy\LegacyAdminAuditLog;
use Illuminate\Console\Command;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — a one-time backfill, not an
 * ongoing sync: since this item, every NEW call to legacy's
 * rk_log_admin_action() (wp-core/api-system.php) already writes into
 * both the old `wp_raffle_admin_audit_logs` table AND the new
 * `admin_audit_logs` table directly, in real time. This command exists
 * only to bring in the HISTORY that existed before that dual-write
 * started, so the new console's audit trail doesn't start with a gap
 * where every admin action taken through the legacy panel before this
 * item is invisible.
 *
 * Idempotent: every row this command creates carries the source row's
 * id in `context.legacy_audit_log_id`, so re-running (or running after
 * the dual-write has already covered a row) never creates a duplicate.
 *
 * Usage:
 *   php artisan legacy:import-admin-audit-logs
 *   php artisan legacy:import-admin-audit-logs --dry-run
 */
class ImportLegacyAdminAuditLogs extends Command
{
    protected $signature = 'legacy:import-admin-audit-logs {--dry-run}';

    protected $description = 'One-time backfill of wp_raffle_admin_audit_logs history into the new admin_audit_logs table';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $alreadyImported = AdminAuditLog::query()
            ->whereNotNull('context->legacy_audit_log_id')
            ->pluck('context')
            ->map(fn ($context) => $context['legacy_audit_log_id'] ?? null)
            ->filter()
            ->all();

        $imported = 0;
        $skipped = 0;

        LegacyAdminAuditLog::query()->orderBy('id')->chunk(200, function ($rows) use (&$imported, &$skipped, $alreadyImported, $dryRun) {
            foreach ($rows as $row) {
                if (in_array($row->id, $alreadyImported, true)) {
                    $skipped++;

                    continue;
                }

                $this->line(sprintf(
                    '%s legacy log #%d: admin %d — %s',
                    $dryRun ? '[dry-run]' : '[import]',
                    $row->id,
                    $row->admin_id,
                    $row->action,
                ));

                if (! $dryRun) {
                    AdminAuditLog::create([
                        'admin_user_id' => $row->admin_id,
                        'action' => $row->action ?: 'legacy_action',
                        'subject_type' => $row->target_type !== null && $row->target_type !== '' ? $row->target_type : 'legacy',
                        'subject_id' => ctype_digit((string) $row->target_id) ? (int) $row->target_id : 0,
                        'context' => [
                            'legacy_audit_log_id' => $row->id,
                            'admin_name' => $row->admin_name,
                            'details' => $row->details,
                            'ip_address' => $row->ip_address,
                            'raw_target_id' => $row->target_id,
                        ],
                        'created_at' => $row->created_at,
                    ]);
                }

                $imported++;
            }
        });

        $this->info(sprintf(
            '%s %d log(s) imported, %d already present (dual-written since item 36, or a previous run of this command).',
            $dryRun ? 'Dry run complete —' : 'Done —',
            $imported,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
