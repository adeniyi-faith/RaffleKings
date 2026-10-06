<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\MoneyReviewItem;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Admin\StaffTodo;
use App\Services\Money\LedgerIntegrity;
use App\Services\Money\ProviderReconciliation;
use App\Services\Payments\PaystackApi;
use App\Services\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoneyChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clean_book_raises_nothing(): void
    {
        app(WalletLedgerService::class)->credit(5, 'wallet', 1000, 'deposit', 'k1', 'gateway_clearing');

        $result = app(LedgerIntegrity::class)->run();

        $this->assertSame(0, $result['drifts'] + $result['unbalanced'] + $result['negative']);
        $this->assertSame(0, MoneyReviewItem::count());
    }

    public function test_a_wallet_that_no_longer_matches_its_ledger_is_flagged_once_and_closes_when_fixed(): void
    {
        $ledger = app(WalletLedgerService::class);
        $ledger->credit(5, 'wallet', 1000, 'deposit', 'k1', 'gateway_clearing');
        DB::table('wallets')->where('user_id', 5)->update(['wallet_balance' => 1500]);

        $this->assertSame(1, app(LedgerIntegrity::class)->run()['drifts']);
        app(LedgerIntegrity::class)->run();

        $this->assertSame(1, MoneyReviewItem::where('kind', 'ledger_drift')->where('status', 'open')->count());

        DB::table('wallets')->where('user_id', 5)->update(['wallet_balance' => 1000]);
        MoneyReviewItem::query()->update(['updated_at' => now()->subHour()]);
        app(LedgerIntegrity::class)->run();

        $this->assertSame('resolved', MoneyReviewItem::first()->status);
    }

    public function test_money_with_no_ledger_history_is_reported_separately(): void
    {
        Wallet::create(['user_id' => 9]);
        DB::table('wallets')->where('user_id', 9)->update(['wallet_balance' => 700]);

        $result = app(LedgerIntegrity::class)->run();

        $this->assertSame(1, $result['no_history']);
        $this->assertSame(0, $result['drifts']);
    }

    public function test_the_ledger_and_audit_log_cannot_be_edited_or_deleted_even_by_raw_queries(): void
    {
        app(WalletLedgerService::class)->credit(5, 'wallet', 1000, 'deposit', 'k1', 'gateway_clearing');

        foreach (['wallet_ledger_entries', 'ledger_system_entries', 'ledger_journals'] as $table) {
            try {
                DB::table($table)->update(['id' => DB::raw('id')]);
                $this->fail("{$table} could be updated");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot', strtolower($e->getMessage()));
            }

            try {
                DB::table($table)->delete();
                $this->fail("{$table} could be deleted");
            } catch (QueryException $e) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, WalletLedgerEntry::count());
    }

    public function test_provider_payments_we_never_credited_and_credits_the_provider_does_not_know_are_flagged(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_abc']);

        Deposit::forceCreate(['user_id' => 5, 'reference' => 'dep_ok', 'gateway' => 'paystack', 'amount' => 500, 'currency' => 'NGN', 'status' => 'successful', 'verified_at' => now()->subHours(3)]);
        Deposit::forceCreate(['user_id' => 5, 'reference' => 'dep_ghost', 'gateway' => 'paystack', 'amount' => 800, 'currency' => 'NGN', 'status' => 'successful', 'verified_at' => now()->subHours(3)]);
        Deposit::forceCreate(['user_id' => 6, 'reference' => 'dep_lost', 'gateway' => 'paystack', 'amount' => 900, 'currency' => 'NGN', 'status' => 'pending']);

        $this->mock(PaystackApi::class, function ($m) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('successfulTransactions')->andReturn([
                ['reference' => 'dep_ok', 'amount' => 500.0, 'currency' => 'NGN'],
                ['reference' => 'dep_lost', 'amount' => 900.0, 'currency' => 'NGN'],
                ['reference' => 'someone_elses', 'amount' => 1.0, 'currency' => 'NGN'],
            ]);
            $m->shouldReceive('transfersBetween')->andReturn([]);
        });

        $result = app(ProviderReconciliation::class)->run();

        $this->assertSame(2, $result['differences']);
        $this->assertSame(1, MoneyReviewItem::where('kind', 'provider_missing_here')->count());
        $this->assertSame(1, MoneyReviewItem::where('kind', 'we_have_no_provider_record')->count());
    }

    public function test_open_items_show_up_on_the_team_to_do_list(): void
    {
        MoneyReviewItem::raise('ledger_drift', '5:wallet', 'Customer #5: wallet balance does not match the ledger', 'details');

        app(StaffTodo::class)->sync();

        $this->assertDatabaseHas('staff_tasks', ['source' => 'money_check', 'title' => 'Customer #5: wallet balance does not match the ledger']);
    }

    public function test_a_missing_database_lock_on_the_books_is_reported(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Drops a SQLite trigger.');
        }

        $this->assertArrayNotHasKey('unprotected', app(LedgerIntegrity::class)->run());

        DB::unprepared('DROP TRIGGER wallet_ledger_entries_no_update');

        $this->assertSame(1, app(LedgerIntegrity::class)->run()['unprotected']);
        $this->assertSame(1, MoneyReviewItem::where('kind', 'books_unprotected')->where('status', 'open')->count());
    }
}
