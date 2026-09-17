<?php

namespace Tests\Feature;

use App\Models\CompletedTask;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\PointLedgerEntry;
use App\Models\UserPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35c — proves the points/streak/
 * tasks backfill correctly reconstructs from legacy usermeta, gives the
 * ledger an honest opening balance, is idempotent, and never clobbers a
 * user who already has real activity on the unified path.
 */
class ReconcilePointsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $login): WpUser
    {
        return WpUser::create([
            'user_login' => $login,
            'user_pass' => 'irrelevant-for-this-test',
            'user_email' => "{$login}@example.com",
            'display_name' => ucfirst($login),
        ]);
    }

    public function test_points_streak_and_last_claim_are_backfilled(): void
    {
        $user = $this->makeUser('grinder');
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_points', 'meta_value' => '850']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_streak_count', 'meta_value' => '4']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_last_claim_date', 'meta_value' => '2026-01-05 10:00:00']);

        Artisan::call('legacy:reconcile-points');

        $points = UserPoints::where('user_id', $user->ID)->first();
        $this->assertEquals(850, $points->balance);
        $this->assertEquals(4, $points->streak_count);
        $this->assertSame('2026-01-05', $points->last_claim_date->toDateString());

        $ledger = PointLedgerEntry::where('user_id', $user->ID)->first();
        $this->assertSame('opening_balance', $ledger->reason);
        $this->assertEquals(850, $ledger->amount);
    }

    public function test_completed_one_off_tasks_are_backfilled_from_the_serialized_array(): void
    {
        $user = $this->makeUser('tasker');
        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => 'rk_completed_tasks',
            'meta_value' => serialize(['push_notification', 'join_community']),
        ]);

        Artisan::call('legacy:reconcile-points');

        $taskIds = CompletedTask::where('user_id', $user->ID)->pluck('task_id')->sort()->values()->all();
        $this->assertSame(['join_community', 'push_notification'], $taskIds);
    }

    public function test_the_repeatable_task_is_backfilled_only_if_shared_today(): void
    {
        $today = $this->makeUser('shared-today');
        WpUserMeta::create(['user_id' => $today->ID, 'meta_key' => 'rk_last_share_date', 'meta_value' => now()->toDateTimeString()]);

        $yesterday = $this->makeUser('shared-yesterday');
        WpUserMeta::create(['user_id' => $yesterday->ID, 'meta_key' => 'rk_last_share_date', 'meta_value' => now()->subDay()->toDateTimeString()]);

        Artisan::call('legacy:reconcile-points');

        $this->assertTrue(CompletedTask::where('user_id', $today->ID)->where('task_id', 'whatsapp_share')->exists());
        $this->assertFalse(CompletedTask::where('user_id', $yesterday->ID)->where('task_id', 'whatsapp_share')->exists());
    }

    public function test_it_is_idempotent(): void
    {
        $user = $this->makeUser('repeat-runner');
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_points', 'meta_value' => '300']);

        Artisan::call('legacy:reconcile-points');
        Artisan::call('legacy:reconcile-points');

        $this->assertSame(1, UserPoints::where('user_id', $user->ID)->count());
        $this->assertSame(1, PointLedgerEntry::where('user_id', $user->ID)->count());
    }

    public function test_a_user_with_real_activity_is_not_clobbered(): void
    {
        $user = $this->makeUser('already-active');
        UserPoints::create(['user_id' => $user->ID, 'balance' => 9999, 'streak_count' => 7]);
        PointLedgerEntry::create([
            'user_id' => $user->ID, 'direction' => 'debit', 'amount' => 50,
            'reason' => 'spin_cost', 'created_at' => now(),
        ]);

        // Stale legacy usermeta the unified path has since moved past.
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_points', 'meta_value' => '1']);

        Artisan::call('legacy:reconcile-points');

        $this->assertEquals(9999, UserPoints::where('user_id', $user->ID)->first()->balance);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $user = $this->makeUser('dry-runner');
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_points', 'meta_value' => '500']);

        Artisan::call('legacy:reconcile-points', ['--dry-run' => true]);

        $this->assertSame(0, UserPoints::count());
    }
}
