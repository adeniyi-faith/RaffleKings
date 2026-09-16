<?php

namespace Tests\Feature;

use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileWalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_opening_balance_entries_for_wallets_with_no_ledger_history(): void
    {
        Wallet::create(['user_id' => 1, 'wallet_balance' => 500, 'earnings_balance' => 200]);

        $this->artisan('legacy:reconcile-wallet-ledger')->assertSuccessful();

        $ledger = app(WalletLedgerService::class);
        $this->assertEquals(500, $ledger->reconstructBalance(1, 'wallet'));
        $this->assertEquals(200, $ledger->reconstructBalance(1, 'earnings'));
    }

    public function test_it_skips_a_zero_balance_and_creates_no_entry(): void
    {
        Wallet::create(['user_id' => 2, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        $this->artisan('legacy:reconcile-wallet-ledger')->assertSuccessful();

        $this->assertSame(0, WalletLedgerEntry::where('user_id', 2)->count());
    }

    public function test_it_does_not_double_count_a_user_who_already_has_ledger_history(): void
    {
        Wallet::create(['user_id' => 3, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        app(WalletLedgerService::class)->recordCredit(3, 'wallet', 1000, 'deposit');

        $this->artisan('legacy:reconcile-wallet-ledger')->assertSuccessful();

        // Still only the one real entry — no extra "opening_balance" entry was added.
        $this->assertSame(1, WalletLedgerEntry::where('user_id', 3)->where('balance_type', 'wallet')->count());
    }

    public function test_dry_run_makes_no_database_changes(): void
    {
        Wallet::create(['user_id' => 4, 'wallet_balance' => 500, 'earnings_balance' => 0]);

        $this->artisan('legacy:reconcile-wallet-ledger --dry-run')->assertSuccessful();

        $this->assertSame(0, WalletLedgerEntry::count());
    }
}
