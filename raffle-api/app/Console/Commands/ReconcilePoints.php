<?php

namespace App\Console\Commands;

use App\Models\CompletedTask;
use App\Models\Legacy\WpUserMeta;
use App\Models\PointLedgerEntry;
use App\Models\UserPoints;
use Illuminate\Console\Command;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35c — before rewards-bridge.php's
 * unified path can be trusted, every real user's points balance,
 * streak, and completed tasks need to exist on the native
 * `user_points`/`completed_tasks` tables too, or they'd appear to reset
 * to zero the instant the flag is turned on. This is the same problem
 * `legacy:backfill-wallets` already solved for money, applied to points.
 *
 * Copies `rk_points`/`rk_streak_count`/`rk_last_claim_date` usermeta
 * into `user_points` (one row per user), gives each a real opening-
 * balance `point_ledger_entries` row so the ledger has an honest
 * starting point (same idea as ReconcileWalletLedger, folded into one
 * command here since both ideas belong together for points), and
 * copies `rk_completed_tasks` (a serialized array of one-off task ids)
 * into `completed_tasks` rows. The exact moment each one-off task was
 * originally completed was never recorded by legacy — only THAT it was
 * — so backfilled rows use "now" as their `completed_at`; that's an
 * honest gap, not invented precision. The one repeatable daily task
 * (`whatsapp_share`, tracked separately via `rk_last_share_date`) is
 * only backfilled as "completed" if that date is today — anything
 * older means the user is actually eligible to do it again right now,
 * and backfilling a stale date would incorrectly block that.
 *
 * Idempotent: skips (leaves completely untouched) any user whose
 * `user_points` row already has real activity — a `point_ledger_entries`
 * row for a reason other than 'opening_balance' — so re-running this
 * after real unified-path activity has happened can never clobber it
 * (same guard `legacy:backfill-wallets` gained in item 33).
 *
 * Usage:
 *   php artisan legacy:reconcile-points
 *   php artisan legacy:reconcile-points --dry-run
 */
class ReconcilePoints extends Command
{
    protected $signature = 'legacy:reconcile-points {--dry-run}';

    protected $description = 'Backfill user_points/completed_tasks from wp_usermeta so the unified rewards engine starts from real history instead of zero';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $userIds = WpUserMeta::query()
            ->whereIn('meta_key', ['rk_points', 'rk_streak_count', 'rk_last_claim_date', 'rk_completed_tasks', 'rk_last_share_date'])
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            $this->info('No legacy points/rewards usermeta found — nothing to reconcile.');

            return self::SUCCESS;
        }

        $metaByUser = WpUserMeta::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('meta_key', ['rk_points', 'rk_streak_count', 'rk_last_claim_date', 'rk_completed_tasks', 'rk_last_share_date'])
            ->get()
            ->groupBy('user_id');

        $reconciled = 0;
        $skipped = 0;

        foreach ($metaByUser as $userId => $rows) {
            $hasRealActivity = PointLedgerEntry::query()
                ->where('user_id', $userId)
                ->where('reason', '!=', 'opening_balance')
                ->exists();

            if ($hasRealActivity) {
                $this->warn("user {$userId}: skipped — already has real rewards activity, backfilling would overwrite it");
                $skipped++;

                continue;
            }

            $points = (int) ($rows->firstWhere('meta_key', 'rk_points')->meta_value ?? 0);
            $streak = (int) ($rows->firstWhere('meta_key', 'rk_streak_count')->meta_value ?? 0);
            $lastClaimRaw = $rows->firstWhere('meta_key', 'rk_last_claim_date')->meta_value ?? null;
            $lastClaimDate = $lastClaimRaw ? date('Y-m-d', strtotime($lastClaimRaw)) : null;

            $this->line(sprintf('%s user %d: points=%d streak=%d last_claim=%s', $dryRun ? '[dry-run]' : '[reconcile]', $userId, $points, $streak, $lastClaimDate ?? 'never'));

            if (! $dryRun) {
                UserPoints::updateOrCreate(
                    ['user_id' => $userId],
                    ['balance' => $points, 'streak_count' => $streak, 'last_claim_date' => $lastClaimDate],
                );

                $alreadyHasLedgerHistory = PointLedgerEntry::query()->where('user_id', $userId)->exists();

                if ($points > 0 && ! $alreadyHasLedgerHistory) {
                    PointLedgerEntry::create([
                        'user_id' => $userId,
                        'direction' => 'credit',
                        'amount' => $points,
                        'reason' => 'opening_balance',
                        'description' => 'Balance carried in from wp_usermeta at ledger adoption time — see legacy:reconcile-points.',
                        'created_at' => now(),
                    ]);
                }

                $this->reconcileCompletedTasks($userId, $rows);
            }

            $reconciled++;
        }

        $this->info(sprintf(
            '%s %d user(s) reconciled, %d skipped (already had real activity).',
            $dryRun ? 'Dry run complete —' : 'Done —',
            $reconciled,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function reconcileCompletedTasks(int $userId, $rows): void
    {
        $rawTasks = $rows->firstWhere('meta_key', 'rk_completed_tasks')->meta_value ?? null;
        $taskIds = $this->normalizeCompletedTasks($rawTasks);

        foreach ($taskIds as $taskId) {
            if ($taskId === 'whatsapp_share') {
                continue; // the repeatable task is handled separately below, from rk_last_share_date
            }

            CompletedTask::query()->firstOrCreate(
                ['user_id' => $userId, 'task_id' => $taskId],
                ['completed_at' => now()],
            );
        }

        $lastShareRaw = $rows->firstWhere('meta_key', 'rk_last_share_date')->meta_value ?? null;

        if ($lastShareRaw && date('Y-m-d', strtotime($lastShareRaw)) === now()->toDateString()) {
            CompletedTask::query()->firstOrCreate(
                ['user_id' => $userId, 'task_id' => 'whatsapp_share'],
                ['completed_at' => $lastShareRaw],
            );
        }
    }

    /**
     * WordPress stores array meta values with PHP's native serialize(),
     * not JSON — mirrors rk_normalize_completed_tasks() in
     * api-gamification.php, and BackfillWalletsFromUserMeta's own
     * unserializeWpMeta() helper for the same reason.
     */
    private function normalizeCompletedTasks(?string $value): array
    {
        if (! $value) {
            return [];
        }

        if (str_starts_with($value, 'a:') || str_starts_with($value, 'O:')) {
            $result = @unserialize($value);

            return is_array($result) ? array_values(array_filter($result, 'is_string')) : [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
