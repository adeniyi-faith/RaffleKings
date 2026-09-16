<?php

namespace Tests\Unit;

use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_an_entry_with_context(): void
    {
        $admin = WpUser::create(['user_login' => 'admin', 'user_pass' => 'x', 'user_email' => 'admin@example.com']);

        $entry = app(AdminAuditLogService::class)->record($admin, 'withdrawal.paid', 'WithdrawalRequest', 5, ['amount' => 3000]);

        $this->assertSame('withdrawal.paid', $entry->action);
        $this->assertSame(5, $entry->subject_id);
        $this->assertEquals(['amount' => 3000], $entry->context);
        $this->assertSame(1, AdminAuditLog::where('admin_user_id', $admin->ID)->count());
    }
}
