<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 41 — the read-only "did everyone's old
 * balance make it across?" report.
 */
class LegacyMigrationStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private function legacyBalance(int $userId, float $wallet, float $earnings = 0): void
    {
        WpUserMeta::create(['user_id' => $userId, 'meta_key' => 'wallet_balance', 'meta_value' => $wallet]);
        WpUserMeta::create(['user_id' => $userId, 'meta_key' => 'earnings_balance', 'meta_value' => $earnings]);
    }

    private function ledger(int $userId, string $reason, float $amount): void
    {
        WalletLedgerEntry::create([
            'user_id' => $userId,
            'balance_type' => 'wallet',
            'direction' => 'credit',
            'amount' => $amount,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    public function test_it_sorts_customers_into_copied_not_copied_and_needs_review(): void
    {
        // Copied properly: wallet row + opening balance entry.
        $this->legacyBalance(1, 1000);
        Wallet::create(['user_id' => 1, 'wallet_balance' => 1000, 'earnings_balance' => 0]);
        $this->ledger(1, 'opening_balance', 1000);

        // Not copied: old balance, no wallet row at all.
        $this->legacyBalance(2, 2500, 500);

        // Needs review: already deposited on the new site, old ₦700 never carried.
        $this->legacyBalance(3, 700);
        Wallet::create(['user_id' => 3, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->ledger(3, 'deposit', 5000);

        // Nothing to copy: zero balance is ignored.
        $this->legacyBalance(4, 0);

        Artisan::call('legacy:migration-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Customers with a balance on the old site: 3', $output);
        $this->assertStringContainsString('Copied: 1', $output);
        $this->assertStringContainsString('Not copied yet: 1 (total ₦3,000.00)', $output);
        $this->assertStringContainsString('Needs manual review: 1 (total ₦700.00)', $output);
        $this->assertMatchesRegularExpression('/\|\s*3\s*\|\s*₦700\.00\s*\|/', $output);
    }

    public function test_it_writes_nothing(): void
    {
        $this->legacyBalance(2, 2500);

        Artisan::call('legacy:migration-status');

        $this->assertSame(0, Wallet::count());
        $this->assertSame(0, WalletLedgerEntry::count());
    }
}
