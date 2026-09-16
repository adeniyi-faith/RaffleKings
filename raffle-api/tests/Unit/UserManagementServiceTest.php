<?php

namespace Tests\Unit;

use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;
use App\Services\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserManagementService $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = app(UserManagementService::class);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_banning_a_user_sets_the_flag_and_logs_it(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->ban($admin, $target, 'fraudulent tickets');

        $this->assertTrue($target->fresh()->isBanned());
        $this->assertSame(1, AdminAuditLog::where('action', 'user.banned')->where('subject_id', $target->ID)->count());
    }

    public function test_unbanning_a_user_clears_the_flag_and_logs_it(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();
        $this->users->ban($admin, $target);

        $this->users->unban($admin, $target);

        $this->assertFalse($target->fresh()->isBanned());
        $this->assertSame(1, AdminAuditLog::where('action', 'user.unbanned')->where('subject_id', $target->ID)->count());
    }

    public function test_banning_twice_does_not_leave_a_stale_duplicate_flag(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->ban($admin, $target);
        $this->users->ban($admin, $target);

        $this->assertTrue($target->fresh()->isBanned());
    }
}
