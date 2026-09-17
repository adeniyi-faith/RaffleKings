<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Legacy\LegacyAdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — proves the one-time admin
 * audit log backfill correctly imports legacy history, is idempotent,
 * and never double-imports a row the real-time dual-write already
 * covered.
 */
class ImportLegacyAdminAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legacy_log_row_is_imported(): void
    {
        $legacy = LegacyAdminAuditLog::create([
            'admin_id' => 7,
            'admin_name' => 'Ada Admin',
            'action' => 'withdrawal_approved',
            'target_type' => 'withdrawal',
            'target_id' => '42',
            'details' => 'Paid via bank transfer',
            'ip_address' => '10.0.0.1',
            'created_at' => now()->subDays(3),
        ]);

        Artisan::call('legacy:import-admin-audit-logs');

        $imported = AdminAuditLog::where('admin_user_id', 7)->first();
        $this->assertNotNull($imported);
        $this->assertSame('withdrawal_approved', $imported->action);
        $this->assertSame('withdrawal', $imported->subject_type);
        $this->assertSame(42, $imported->subject_id);
        $this->assertSame($legacy->id, $imported->context['legacy_audit_log_id']);
    }

    public function test_a_non_numeric_target_id_falls_back_to_zero(): void
    {
        LegacyAdminAuditLog::create([
            'admin_id' => 1,
            'action' => 'settings_save',
            'target_type' => 'site_settings',
            'target_id' => '',
            'created_at' => now(),
        ]);

        Artisan::call('legacy:import-admin-audit-logs');

        $imported = AdminAuditLog::where('action', 'settings_save')->first();
        $this->assertSame(0, $imported->subject_id);
    }

    public function test_it_is_idempotent(): void
    {
        LegacyAdminAuditLog::create(['admin_id' => 2, 'action' => 'ban_user', 'target_type' => 'user', 'target_id' => '9', 'created_at' => now()]);

        Artisan::call('legacy:import-admin-audit-logs');
        Artisan::call('legacy:import-admin-audit-logs');

        $this->assertSame(1, AdminAuditLog::where('action', 'ban_user')->count());
    }

    public function test_a_row_already_dual_written_in_real_time_is_not_duplicated(): void
    {
        $legacy = LegacyAdminAuditLog::create(['admin_id' => 3, 'action' => 'winner_credited', 'target_type' => 'winner', 'target_id' => '5', 'created_at' => now()]);

        // Simulates rk_log_admin_action()'s real-time dual-write already having created this.
        AdminAuditLog::create([
            'admin_user_id' => 3,
            'action' => 'winner_credited',
            'subject_type' => 'winner',
            'subject_id' => 5,
            'context' => ['legacy_audit_log_id' => $legacy->id],
            'created_at' => now(),
        ]);

        Artisan::call('legacy:import-admin-audit-logs');

        $this->assertSame(1, AdminAuditLog::where('action', 'winner_credited')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        LegacyAdminAuditLog::create(['admin_id' => 4, 'action' => 'test_action', 'created_at' => now()]);

        Artisan::call('legacy:import-admin-audit-logs', ['--dry-run' => true]);

        $this->assertSame(0, AdminAuditLog::count());
    }
}
