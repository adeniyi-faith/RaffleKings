<?php

namespace Tests\Unit;

use App\Auth\StaffRoles;
use App\Models\AccountRestriction;
use App\Models\AdminAuditLog;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserPoints;
use App\Models\Wallet;
use App\Services\AccountRestrictions;
use App\Services\PointsLedgerService;
use App\Services\UserManagementService;
use App\Services\WalletLedgerService;
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

    private function makeAdmin(): WpUser
    {
        $admin = $this->makeUser();
        WpUserMeta::create(['user_id' => $admin->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => 'owner']);

        return $admin;
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

        // Lifting a ban takes two staff: one asks, a different one approves.
        $this->users->unban($admin, $target, 'Banned by mistake');
        $this->assertTrue($target->fresh()->isBanned());

        $second = $this->makeUser();
        app(AccountRestrictions::class)->approveLift($second, AccountRestriction::where('user_id', $target->ID)->firstOrFail());

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

    public function test_adding_to_wallet_credits_the_wallet_customers_actually_see(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'wallet', 1000, 'add', 'Test correction');

        $this->assertEquals(1000, (float) Wallet::where('user_id', $target->ID)->value('wallet_balance'));
        $this->assertNull(WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'wallet_balance')->first());
        $this->assertEquals(1000, app(WalletLedgerService::class)->reconstructBalance($target->ID, 'wallet'));
    }

    public function test_adjusting_ignores_the_retired_legacy_wallet_switch_being_off(): void
    {
        WpOption::create(['option_name' => 'rk_wallets_unified_enabled', 'option_value' => '0']);
        $admin = $this->makeAdmin();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'earnings', 2500, 'add', 'Test correction');

        $this->assertEquals(2500, (float) Wallet::where('user_id', $target->ID)->value('earnings_balance'));
    }

    public function test_subtracting_from_wallet_clamps_at_zero_and_the_ledger_records_only_what_was_taken(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser();
        $this->users->adjustBalance($admin, $target, 'wallet', 500, 'add', 'Test correction');

        $this->users->adjustBalance($admin, $target, 'wallet', 2000, 'subtract', 'Test correction');

        $this->assertEquals(0, (float) Wallet::where('user_id', $target->ID)->value('wallet_balance'));
        // The ledger must agree with the balance, or Financial
        // Reconciliation would flag a drift that never really happened.
        $this->assertEquals(0, app(WalletLedgerService::class)->reconstructBalance($target->ID, 'wallet'));
    }

    public function test_adjusting_a_balance_logs_a_raffle_transaction_and_an_audit_entry(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'wallet', 750, 'add', 'Test correction');

        $txn = RaffleTransaction::where('user_id', $target->ID)->where('type', 'admin_adjustment')->first();
        $this->assertNotNull($txn);
        $this->assertEquals(750, (float) $txn->claimed_amount);
        $this->assertSame(1, AdminAuditLog::where('action', 'user.balance_add')->where('subject_id', $target->ID)->count());
    }

    public function test_adjusting_points_uses_the_user_points_table_even_with_the_legacy_switch_off(): void
    {
        WpOption::create(['option_name' => 'rk_rewards_unified_enabled', 'option_value' => '0']);
        $admin = $this->makeAdmin();
        $target = $this->makeUser();

        $this->users->adjustBalance($admin, $target, 'points', 100, 'add', 'Test correction');

        $this->assertSame(100, UserPoints::where('user_id', $target->ID)->first()->balance);
        $this->assertNull(WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'rk_points')->first());
    }

    public function test_subtracting_points_clamps_at_zero_and_the_ledger_agrees(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser();
        $this->users->adjustBalance($admin, $target, 'points', 50, 'add', 'Test correction');

        $this->users->adjustBalance($admin, $target, 'points', 80, 'subtract', 'Test correction');

        $this->assertSame(0, UserPoints::where('user_id', $target->ID)->first()->balance);
        $this->assertSame(0, app(PointsLedgerService::class)->reconstructBalance($target->ID));
    }

    public function test_it_rejects_a_zero_or_negative_amount(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser();

        $this->expectException(InvalidArgumentException::class);
        $this->users->adjustBalance($admin, $target, 'wallet', 0, 'add', 'Test correction');
    }

    public function test_updating_restrictions_sets_every_flag_and_logs_it(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->users->updateRestrictions($admin, $target, true, true, false, '2026-12-31', 'Test reason');

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

        $this->users->updateRestrictions($admin, $target, true, false, false, null, 'Test reason');
        $this->users->updateRestrictions($admin, $target, false, false, false, null, 'Test reason');

        $this->assertSame(1, WpUserMeta::where('user_id', $target->ID)->where('meta_key', 'rk_is_banned')->count());
        // Switching a ban off only asks for it to be lifted; a second staff member approves.
        $this->assertTrue($target->fresh()->isBanned());
    }
}
