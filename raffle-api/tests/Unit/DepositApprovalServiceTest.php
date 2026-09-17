<?php

namespace Tests\Unit;

use App\Models\AdminAuditLog;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\DepositApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the Financials admin page's
 * "Pending Deposits" queue has no equivalent on the new console yet.
 * Proves the new DepositApprovalService correctly settles legacy's
 * manual bank-transfer deposits (wp_raffle_transactions), respecting
 * whichever balance store the rk_wallets_unified_enabled flag currently
 * points at, and applies the same cashback bonus Transaction Monitor's
 * own approve action already grants.
 */
class DepositApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private DepositApprovalService $deposits;

    private WpUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deposits = app(DepositApprovalService::class);
        $this->admin = WpUser::create(['user_login' => 'admin'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        Notification::fake();
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_approving_a_pending_deposit_credits_wp_usermeta_when_the_wallet_flag_is_off(): void
    {
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 5000, 'status' => 'pending', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->deposits->approve($this->admin, $txn);

        $this->assertSame('verified_final', $txn->fresh()->status);
        $meta = WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'wallet_balance')->first();
        $this->assertEquals(5000, (float) $meta->meta_value);
        $this->assertNull(Wallet::where('user_id', $user->ID)->first());
    }

    public function test_approving_credits_the_unified_wallet_when_the_flag_is_on(): void
    {
        WpOption::create(['option_name' => 'rk_wallets_unified_enabled', 'option_value' => '1']);
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 3000, 'status' => 'manual_review', 'type' => 'deposit_manual', 'created_at' => now()]);

        $this->deposits->approve($this->admin, $txn);

        $wallet = Wallet::where('user_id', $user->ID)->first();
        $this->assertEquals(3000, (float) $wallet->wallet_balance);
        $this->assertNull(WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'wallet_balance')->first());
    }

    public function test_approving_a_wallet_deposit_applies_the_configured_cashback_bonus(): void
    {
        config(['payments.legacy_deposit_bonus_percent' => 0.3]);
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'pending', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->deposits->approve($this->admin, $txn);

        $earningsMeta = WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'earnings_balance')->first();
        $this->assertEquals(300, (float) $earningsMeta->meta_value);

        $bonusTxn = RaffleTransaction::where('order_id', "Bonus for Txn #{$txn->id}")->first();
        $this->assertNotNull($bonusTxn);
        $this->assertEquals(300, (float) $bonusTxn->claimed_amount);
    }

    public function test_deposit_manual_type_never_gets_the_bonus(): void
    {
        config(['payments.legacy_deposit_bonus_percent' => 0.3]);
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'pending', 'type' => 'deposit_manual', 'created_at' => now()]);

        $this->deposits->approve($this->admin, $txn);

        $this->assertNull(RaffleTransaction::where('order_id', "Bonus for Txn #{$txn->id}")->first());
    }

    public function test_rejecting_marks_it_rejected_without_touching_any_balance(): void
    {
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 2000, 'status' => 'pending', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->deposits->reject($this->admin, $txn, 'Screenshot did not match.');

        $this->assertSame('rejected', $txn->fresh()->status);
        $this->assertNull(WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'wallet_balance')->first());
    }

    public function test_it_cannot_approve_a_transaction_that_is_not_a_pending_deposit(): void
    {
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 2000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->deposits->approve($this->admin, $txn);
    }

    public function test_it_cannot_approve_a_withdrawal_transaction(): void
    {
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 2000, 'status' => 'pending', 'type' => 'withdrawal', 'created_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->deposits->approve($this->admin, $txn);
    }

    public function test_approving_logs_an_admin_audit_entry(): void
    {
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1500, 'status' => 'pending', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->deposits->approve($this->admin, $txn);

        $log = AdminAuditLog::where('action', 'deposit.approved')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->ID, $log->admin_user_id);
    }

    public function test_pending_only_returns_approvable_types_and_statuses(): void
    {
        $user = $this->makeUser();
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'pending', 'type' => 'wallet_deposit', 'created_at' => now()]);
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'manual_review', 'type' => 'deposit_manual', 'created_at' => now()]);
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'pending', 'type' => 'withdrawal', 'created_at' => now()]);
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'pending', 'type' => 'ticket_purchase', 'created_at' => now()]);

        $this->assertCount(2, $this->deposits->pending());
    }
}
