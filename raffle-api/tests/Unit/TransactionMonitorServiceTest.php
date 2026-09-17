<?php

namespace Tests\Unit;

use App\Models\AdminAuditLog;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\TransactionMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — proves the new "revoke a
 * verified transaction" admin action correctly reverses the wallet
 * credit (whichever balance store is currently live), deletes any
 * tickets it bought, and reverses a linked cashback bonus — matching
 * legacy's own Transaction Monitor revoke logic, plus deposit_manual
 * (a real gap in legacy's own revoke this pass does not reproduce, see
 * the service's docblock).
 */
class TransactionMonitorServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransactionMonitorService $transactions;

    private WpUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactions = app(TransactionMonitorService::class);
        $this->admin = WpUser::create(['user_login' => 'admin'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_revoking_a_wallet_deposit_reverses_wp_usermeta_when_the_flag_is_off(): void
    {
        $user = $this->makeUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'wallet_balance', 'meta_value' => 5000]);
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 5000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->transactions->revoke($this->admin, $txn);

        $this->assertSame('rejected', $txn->fresh()->status);
        $meta = WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'wallet_balance')->first();
        $this->assertEquals(0, (float) $meta->meta_value);
    }

    public function test_revoking_reverses_the_unified_wallet_when_the_flag_is_on(): void
    {
        WpOption::create(['option_name' => 'rk_wallets_unified_enabled', 'option_value' => '1']);
        $user = $this->makeUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 4000, 'earnings_balance' => 0]);
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 4000, 'status' => 'verified_final', 'type' => 'wallet_payment', 'created_at' => now()]);

        $this->transactions->revoke($this->admin, $txn);

        $this->assertEquals(0, (float) Wallet::where('user_id', $user->ID)->first()->wallet_balance);
    }

    public function test_revoking_deletes_associated_ticket_entries(): void
    {
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'wallet_payment', 'created_at' => now()]);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => 1, 'ticket_number' => 7, 'txn_id' => $txn->id]);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => 1, 'ticket_number' => 8, 'txn_id' => $txn->id]);

        $this->transactions->revoke($this->admin, $txn);

        $this->assertSame(0, RaffleEntry::where('txn_id', $txn->id)->count());
    }

    public function test_revoking_reverses_a_linked_cashback_bonus(): void
    {
        $user = $this->makeUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'earnings_balance', 'meta_value' => 300]);
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);
        $bonus = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 300, 'status' => 'verified_final', 'type' => 'deposit_bonus', 'order_id' => "Bonus for Txn #{$txn->id}", 'created_at' => now()]);

        $this->transactions->revoke($this->admin, $txn);

        $this->assertSame('reversed', $bonus->fresh()->status);
        $earningsMeta = WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'earnings_balance')->first();
        $this->assertEquals(0, (float) $earningsMeta->meta_value);
    }

    public function test_revoking_a_deposit_manual_transaction_also_reverses_the_balance(): void
    {
        $user = $this->makeUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'wallet_balance', 'meta_value' => 2000]);
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 2000, 'status' => 'verified_final', 'type' => 'deposit_manual', 'created_at' => now()]);

        $this->transactions->revoke($this->admin, $txn);

        $meta = WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'wallet_balance')->first();
        $this->assertEquals(0, (float) $meta->meta_value);
    }

    public function test_revoking_a_ticket_purchase_deletes_tickets_but_does_not_touch_balance(): void
    {
        $user = $this->makeUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'wallet_balance', 'meta_value' => 1000]);
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 500, 'status' => 'verified_final', 'type' => 'ticket_purchase', 'created_at' => now()]);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => 1, 'ticket_number' => 3, 'txn_id' => $txn->id]);

        $this->transactions->revoke($this->admin, $txn);

        $this->assertSame(0, RaffleEntry::where('txn_id', $txn->id)->count());
        $meta = WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'wallet_balance')->first();
        $this->assertEquals(1000, (float) $meta->meta_value);
    }

    public function test_it_cannot_revoke_a_transaction_that_is_not_verified(): void
    {
        $user = $this->makeUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'pending', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->transactions->revoke($this->admin, $txn);
    }

    public function test_revoking_logs_an_admin_audit_entry(): void
    {
        $user = $this->makeUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'wallet_balance', 'meta_value' => 1000]);
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);

        $this->transactions->revoke($this->admin, $txn, 'Not found in bank statement.');

        $log = AdminAuditLog::where('action', 'transaction.revoked')->first();
        $this->assertNotNull($log);
        $this->assertSame('Not found in bank statement.', $log->context['reason']);
    }

    public function test_recent_excludes_withdrawals(): void
    {
        $user = $this->makeUser();
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'pending', 'type' => 'withdrawal', 'created_at' => now()]);

        $results = $this->transactions->recent();

        $this->assertCount(1, $results);
        $this->assertSame('wallet_deposit', $results->first()->type);
    }

    public function test_recent_filters_by_today(): void
    {
        $user = $this->makeUser();
        RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);
        $old = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'wallet_deposit', 'created_at' => now()]);
        // 'created_at' isn't mass-assignable (matching legacy's own dbDelta default), so
        // backdate it directly rather than via create().
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $results = $this->transactions->recent('today');

        $this->assertCount(1, $results);
    }
}
