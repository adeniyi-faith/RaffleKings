<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 33 — legacy:backfill-wallets is a
 * full-snapshot overwrite, not an incremental sync. Once a user's wallet
 * has seen real activity via the new unified path (a WalletLedgerEntry
 * for any reason other than 'opening_balance'), re-running the backfill
 * must skip them entirely rather than clobbering a real balance with a
 * stale legacy number.
 */
class BackfillWalletsFromUserMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_no_ledger_history_is_backfilled_normally(): void
    {
        WpUserMeta::create(['user_id' => 1, 'meta_key' => 'wallet_balance', 'meta_value' => '500']);
        WpUserMeta::create(['user_id' => 1, 'meta_key' => 'earnings_balance', 'meta_value' => '200']);

        Artisan::call('legacy:backfill-wallets');

        $wallet = Wallet::where('user_id', 1)->first();
        $this->assertEquals(500, $wallet->wallet_balance);
        $this->assertEquals(200, $wallet->earnings_balance);
    }

    public function test_a_user_with_real_ledger_activity_is_not_clobbered(): void
    {
        Wallet::create(['user_id' => 2, 'wallet_balance' => 999, 'earnings_balance' => 50]);
        WalletLedgerEntry::create([
            'user_id' => 2,
            'balance_type' => 'wallet',
            'direction' => 'debit',
            'amount' => 100,
            'reason' => 'ticket_purchase',
            'created_at' => now(),
        ]);

        // Stale legacy usermeta — the wallet has since moved on via the
        // unified path and usermeta was never updated for it.
        WpUserMeta::create(['user_id' => 2, 'meta_key' => 'wallet_balance', 'meta_value' => '1']);
        WpUserMeta::create(['user_id' => 2, 'meta_key' => 'earnings_balance', 'meta_value' => '1']);

        Artisan::call('legacy:backfill-wallets');

        $wallet = Wallet::where('user_id', 2)->first();
        $this->assertEquals(999, $wallet->wallet_balance);
        $this->assertEquals(50, $wallet->earnings_balance);
    }

    public function test_a_user_with_only_an_opening_balance_entry_is_still_backfilled(): void
    {
        Wallet::create(['user_id' => 3, 'wallet_balance' => 300, 'earnings_balance' => 0]);
        WalletLedgerEntry::create([
            'user_id' => 3,
            'balance_type' => 'wallet',
            'direction' => 'credit',
            'amount' => 300,
            'reason' => 'opening_balance',
            'created_at' => now(),
        ]);

        WpUserMeta::create(['user_id' => 3, 'meta_key' => 'wallet_balance', 'meta_value' => '450']);
        WpUserMeta::create(['user_id' => 3, 'meta_key' => 'earnings_balance', 'meta_value' => '0']);

        Artisan::call('legacy:backfill-wallets');

        $wallet = Wallet::where('user_id', 3)->first();
        $this->assertEquals(450, $wallet->wallet_balance);
    }
}
