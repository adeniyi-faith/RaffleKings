<?php

namespace Tests\Unit;

use App\Models\AdminAuditLog;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserPoints;
use App\Models\Wallet;
use App\Services\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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

    public function test_adding_to_wallet_credits_wp_usermeta_when_the_flag_is_off(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'wallet', 1000, 'add');

        $meta = WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'wallet_balance')->first();
        $this->assertEquals(1000, (float) $meta->meta_value);
    }

    public function test_subtracting_from_wallet_clamps_at_zero(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();
        WpUserMeta::create(['user_id' => $target->ID, 'meta_key' => 'wallet_balance', 'meta_value' => 500]);

        $this->users->adjustBalance($admin, $target, 'wallet', 2000, 'subtract');

        $meta = WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'wallet_balance')->first();
        $this->assertEquals(0, (float) $meta->meta_value);
    }

    public function test_adjusting_earnings_credits_the_unified_wallet_when_the_flag_is_on(): void
    {
        WpOption::create(['option_name' => 'rk_wallets_unified_enabled', 'option_value' => '1']);
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'earnings', 2500, 'add');

        $wallet = Wallet::where('user_id', $target->ID)->first();
        $this->assertEquals(2500, (float) $wallet->earnings_balance);
    }

    public function test_adjusting_a_balance_logs_a_raffle_transaction_and_an_audit_entry(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'wallet', 750, 'add');

        $txn = RaffleTransaction::where('user_id', $target->ID)->where('type', 'admin_adjustment')->first();
        $this->assertNotNull($txn);
        $this->assertEquals(750, (float) $txn->claimed_amount);
        $this->assertSame(1, AdminAuditLog::where('action', 'user.balance_add')->where('subject_id', $target->ID)->count());
    }

    public function test_adjusting_points_uses_wp_usermeta_when_the_rewards_flag_is_off(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'points', 100, 'add');

        $meta = WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'rk_points')->first();
        $this->assertEquals(100, (float) $meta->meta_value);
    }

    public function test_adjusting_points_uses_user_points_table_when_the_rewards_flag_is_on(): void
    {
        WpOption::create(['option_name' => 'rk_rewards_unified_enabled', 'option_value' => '1']);
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'points', 50, 'add');

        $record = UserPoints::where('user_id', $target->ID)->first();
        $this->assertSame(50, $record->balance);
    }

    public function test_it_rejects_a_zero_or_negative_amount(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->expectException(InvalidArgumentException::class);
        $this->users->adjustBalance($admin, $target, 'wallet', 0, 'add');
    }

    public function test_updating_restrictions_sets_every_flag_and_logs_it(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->updateRestrictions($admin, $target, true, true, false, '2026-12-31');

        $this->assertTrue($target->fresh()->isBanned());
        $this->assertSame('1', WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'rk_ban_withdraw')->value('meta_value'));
        $this->assertSame('0', WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'rk_ban_transfer')->value('meta_value'));
        $this->assertSame('2026-12-31', WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'rk_ban_expiry')->value('meta_value'));
        $this->assertSame(1, AdminAuditLog::where('action', 'user.restrictions_updated')->where('subject_id', $target->ID)->count());
    }

    public function test_updating_restrictions_twice_does_not_leave_stale_duplicate_meta_rows(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->updateRestrictions($admin, $target, true, false, false, null);
        $this->users->updateRestrictions($admin, $target, false, false, false, null);

        $this->assertSame(1, WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'rk_is_banned')->count());
        $this->assertFalse($target->fresh()->isBanned());
    }
}
