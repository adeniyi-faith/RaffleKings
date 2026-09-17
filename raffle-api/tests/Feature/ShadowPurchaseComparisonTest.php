<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\ShadowPurchaseComparison;
use App\Models\Wallet;
use App\Services\ShadowPurchaseComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 32 — the shadow-traffic period.
 * These tests stand in for legacy PHP: they create a
 * shadow_purchase_comparisons row exactly the way
 * rk_queue_shadow_purchase_comparison() does after a real purchase
 * commits, then assert the Laravel-side comparison correctly agrees or
 * disagrees with it — without ever touching a wallet or a ticket entry
 * itself.
 */
class ShadowPurchaseComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): WpUser
    {
        return WpUser::create([
            'user_login' => 'shadow-tester',
            'user_pass' => 'irrelevant-for-this-test',
            'user_email' => 'shadow-tester@example.com',
            'display_name' => 'Shadow Tester',
        ]);
    }

    public function test_a_matching_purchase_is_marked_compared_with_no_mismatch(): void
    {
        $user = $this->makeUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 500, 'earnings_balance' => 0]);
        RaffleEntry::insert([
            ['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => 10, 'txn_id' => 1, 'created_at' => now()],
            ['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => 11, 'txn_id' => 1, 'created_at' => now()],
            ['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => 12, 'txn_id' => 1, 'created_at' => now()],
        ]);

        // 3 tickets @ 100 (<=200 tier, qty>=2 => 10% off) = 270, matching
        // rk_calculate_ticket_price()/TicketPricingService exactly.
        $row = ShadowPurchaseComparison::create([
            'user_id' => $user->ID,
            'raffle_id' => 5,
            'ticket_numbers' => [10, 11, 12],
            'quantity' => 3,
            'unit_price' => 100,
            'is_golden_box' => false,
            'funding_source' => 'wallet',
            'legacy_charged_amount' => 270,
            'legacy_new_balance' => 730,
        ]);

        app(ShadowPurchaseComparisonService::class)->compare($row);
        $row->refresh();

        $this->assertSame('compared', $row->status);
        $this->assertFalse($row->price_mismatch);
        $this->assertFalse($row->entries_missing);
        $this->assertFalse($row->hasMismatch());
        $this->assertEquals(270, $row->laravel_expected_price);
        $this->assertEquals(500, $row->laravel_wallet_balance); // the SEPARATE new-wallets balance, unaffected by this legacy purchase
        $this->assertNull($row->mismatch_details);
    }

    public function test_a_price_mismatch_is_flagged_and_logged(): void
    {
        Log::spy();

        $user = $this->makeUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        RaffleEntry::insert([
            ['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => 20, 'txn_id' => 1, 'created_at' => now()],
        ]);

        $row = ShadowPurchaseComparison::create([
            'user_id' => $user->ID,
            'raffle_id' => 5,
            'ticket_numbers' => [20],
            'quantity' => 1,
            'unit_price' => 100,
            'is_golden_box' => false,
            'funding_source' => 'wallet',
            // Legacy somehow charged 999 for what should cost 100 (qty 1, no discount).
            'legacy_charged_amount' => 999,
            'legacy_new_balance' => 0,
        ]);

        app(ShadowPurchaseComparisonService::class)->compare($row);
        $row->refresh();

        $this->assertSame('compared', $row->status);
        $this->assertTrue($row->price_mismatch);
        $this->assertTrue($row->hasMismatch());
        $this->assertEquals(100, $row->laravel_expected_price);
        $this->assertSame(999.0, (float) $row->mismatch_details['price']['legacy_charged_amount']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message) => $message === 'Shadow purchase comparison found a mismatch');
    }

    public function test_missing_ticket_entries_are_flagged(): void
    {
        $user = $this->makeUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        // Deliberately do NOT insert a raffle_entries row for ticket 30 —
        // simulates legacy having recorded a purchase without the
        // matching entry ever landing (the exact kind of bug this exists to catch).

        $row = ShadowPurchaseComparison::create([
            'user_id' => $user->ID,
            'raffle_id' => 5,
            'ticket_numbers' => [30],
            'quantity' => 1,
            'unit_price' => 100,
            'is_golden_box' => false,
            'funding_source' => 'wallet',
            'legacy_charged_amount' => 100,
            'legacy_new_balance' => 0,
        ]);

        app(ShadowPurchaseComparisonService::class)->compare($row);
        $row->refresh();

        $this->assertTrue($row->entries_missing);
        $this->assertTrue($row->hasMismatch());
        $this->assertFalse($row->price_mismatch);
    }

    public function test_the_command_only_processes_pending_rows_and_is_idempotent(): void
    {
        $user = $this->makeUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 500, 'earnings_balance' => 0]);
        RaffleEntry::insert([
            ['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => 40, 'txn_id' => 1, 'created_at' => now()],
        ]);

        $pending = ShadowPurchaseComparison::create([
            'user_id' => $user->ID,
            'raffle_id' => 5,
            'ticket_numbers' => [40],
            'quantity' => 1,
            'unit_price' => 100,
            'is_golden_box' => false,
            'funding_source' => 'wallet',
            'legacy_charged_amount' => 100,
            'legacy_new_balance' => 400,
        ]);

        $alreadyCompared = ShadowPurchaseComparison::create([
            'user_id' => $user->ID,
            'raffle_id' => 5,
            'ticket_numbers' => [41],
            'quantity' => 1,
            'unit_price' => 100,
            'is_golden_box' => false,
            'funding_source' => 'wallet',
            'legacy_charged_amount' => 100,
            'legacy_new_balance' => 300,
            'status' => 'compared',
            'compared_at' => now(),
        ]);

        Artisan::call('shadow:process-purchases');

        $this->assertSame('compared', $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->compared_at);
        // Untouched — was already compared before the command ran.
        $this->assertNull($alreadyCompared->fresh()->laravel_expected_price);

        // Running it again is a no-op (nothing left pending).
        $comparedAt = $pending->fresh()->compared_at;
        Artisan::call('shadow:process-purchases');
        $this->assertEquals($comparedAt, $pending->fresh()->compared_at);
    }
}
