<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — proves the one-time withdrawal
 * backfill correctly imports legacy withdrawal transactions into the new
 * admin queue's withdrawal_requests table, resolves bank accounts by the
 * legacy per-user account id, maps legacy's inconsistent status strings,
 * and is idempotent.
 */
class ImportLegacyWithdrawalRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithLegacyBankAccounts(int $userId, array $accounts): void
    {
        WpUser::create(['ID' => $userId, 'user_login' => 'u'.$userId, 'user_pass' => 'x', 'user_email' => "u{$userId}@example.com"]);
        WpUserMeta::create(['user_id' => $userId, 'meta_key' => 'rk_bank_accounts', 'meta_value' => serialize($accounts)]);
    }

    public function test_a_pending_legacy_withdrawal_is_imported(): void
    {
        $this->makeUserWithLegacyBankAccounts(10, [
            ['id' => 'acc1', 'bank_name' => 'GTBank', 'account_number' => '0011223344', 'account_name' => 'Jane Doe', 'is_primary' => true],
        ]);
        $txn = RaffleTransaction::create([
            'user_id' => 10,
            'claimed_amount' => 5000,
            'status' => 'pending',
            'type' => 'withdrawal',
            'txn_ref' => 'acc1',
            'created_at' => now(),
        ]);

        Artisan::call('legacy:import-withdrawal-requests');

        $request = WithdrawalRequest::where('legacy_transaction_id', $txn->id)->first();
        $this->assertNotNull($request);
        $this->assertSame(10, $request->user_id);
        $this->assertSame('pending', $request->status);
        $this->assertEquals(5000, $request->requested_amount);
        $this->assertEquals(5000, $request->amount_to_send);

        $bankAccount = BankAccount::find($request->bank_account_id);
        $this->assertSame('0011223344', $bankAccount->account_number);
    }

    public function test_it_maps_both_legacy_paid_statuses_to_paid(): void
    {
        $this->makeUserWithLegacyBankAccounts(11, [
            ['id' => 'acc1', 'bank_name' => 'Zenith', 'account_number' => '1112223334', 'account_name' => 'A', 'is_primary' => true],
        ]);
        $completed = RaffleTransaction::create(['user_id' => 11, 'claimed_amount' => 1000, 'status' => 'completed', 'type' => 'withdrawal', 'txn_ref' => 'acc1', 'created_at' => now()]);
        $verifiedFinal = RaffleTransaction::create(['user_id' => 11, 'claimed_amount' => 2000, 'status' => 'verified_final', 'type' => 'withdrawal', 'txn_ref' => 'acc1', 'created_at' => now()]);

        Artisan::call('legacy:import-withdrawal-requests');

        $this->assertSame('paid', WithdrawalRequest::where('legacy_transaction_id', $completed->id)->first()->status);
        $this->assertSame('paid', WithdrawalRequest::where('legacy_transaction_id', $verifiedFinal->id)->first()->status);
    }

    public function test_a_request_falls_back_to_the_primary_account_when_txn_ref_does_not_match(): void
    {
        $this->makeUserWithLegacyBankAccounts(12, [
            ['id' => 'acc1', 'bank_name' => 'Access', 'account_number' => '5556667778', 'account_name' => 'B', 'is_primary' => true],
        ]);
        $txn = RaffleTransaction::create(['user_id' => 12, 'claimed_amount' => 750, 'status' => 'rejected', 'type' => 'withdrawal', 'txn_ref' => 'no-such-id', 'created_at' => now()]);

        Artisan::call('legacy:import-withdrawal-requests');

        $request = WithdrawalRequest::where('legacy_transaction_id', $txn->id)->first();
        $this->assertNotNull($request);
        $this->assertSame('rejected', $request->status);
        $this->assertSame('5556667778', BankAccount::find($request->bank_account_id)->account_number);
    }

    public function test_a_user_with_no_bank_account_on_file_is_skipped(): void
    {
        WpUser::create(['ID' => 13, 'user_login' => 'u13', 'user_pass' => 'x', 'user_email' => 'u13@example.com']);
        $txn = RaffleTransaction::create(['user_id' => 13, 'claimed_amount' => 500, 'status' => 'pending', 'type' => 'withdrawal', 'txn_ref' => 'acc1', 'created_at' => now()]);

        Artisan::call('legacy:import-withdrawal-requests');

        $this->assertNull(WithdrawalRequest::where('legacy_transaction_id', $txn->id)->first());
    }

    public function test_it_is_idempotent(): void
    {
        $this->makeUserWithLegacyBankAccounts(14, [
            ['id' => 'acc1', 'bank_name' => 'UBA', 'account_number' => '9998887776', 'account_name' => 'C', 'is_primary' => true],
        ]);
        $txn = RaffleTransaction::create(['user_id' => 14, 'claimed_amount' => 300, 'status' => 'pending', 'type' => 'withdrawal', 'txn_ref' => 'acc1', 'created_at' => now()]);

        Artisan::call('legacy:import-withdrawal-requests');
        Artisan::call('legacy:import-withdrawal-requests');

        $this->assertSame(1, WithdrawalRequest::where('legacy_transaction_id', $txn->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->makeUserWithLegacyBankAccounts(15, [
            ['id' => 'acc1', 'bank_name' => 'UBA', 'account_number' => '4443332221', 'account_name' => 'D', 'is_primary' => true],
        ]);
        RaffleTransaction::create(['user_id' => 15, 'claimed_amount' => 300, 'status' => 'pending', 'type' => 'withdrawal', 'txn_ref' => 'acc1', 'created_at' => now()]);

        Artisan::call('legacy:import-withdrawal-requests', ['--dry-run' => true]);

        $this->assertSame(0, WithdrawalRequest::count());
        $this->assertSame(0, BankAccount::count());
    }
}
