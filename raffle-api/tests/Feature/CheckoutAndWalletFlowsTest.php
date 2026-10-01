<?php

namespace Tests\Feature;

use App\Events\RaffleTicketsUpdated;
use App\Models\GoldenBoxOffer;
use App\Models\Legacy\RaffleEntry;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 46 (Phase 6): checkout and wallet flows —
 * moving winnings to the wallet, "use winnings to cover it", coming back
 * to checkout after a top-up, the live number grid's data, the success
 * screen's ticket numbers, and the Golden Box discount.
 */
class CheckoutAndWalletFlowsTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ₦1,000 a ticket, so one ticket (no bulk discount) is exactly the
        // Golden Box's default ₦1,000 minimum order; 10% off makes it ₦900.
        $this->createRaffle(['public_id' => 9, 'price' => '1000', 'max' => '100', 'title' => 'Big Prize']);
        // A cheap raffle, for an order below the Golden Box minimum.
        $this->createRaffle(['public_id' => 3, 'price' => '100', 'max' => '100']);
    }

    private function buy(array $overrides = [])
    {
        return $this->postJson('/api/tickets/purchase', array_merge([
            'raffle_id' => 9,
            'ticket_numbers' => [7],
            'unit_price' => 1000,
            'submitted_amount' => 1000,
            'funding_source' => 'wallet',
            'idempotency_key' => 'key-'.uniqid(),
        ], $overrides));
    }

    // --- Winnings → wallet --------------------------------------------------

    public function test_winnings_move_to_the_wallet_instantly_and_free_with_a_ledger_entry_on_each_side(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 200, 'earnings_balance' => 1500]);

        $this->postJson('/api/wallet/transfer', ['amount' => 1000])
            ->assertOk()
            ->assertJson(['moved' => 1000, 'wallet_balance' => 1200, 'earnings_balance' => 500]);

        $wallet = Wallet::where('user_id', $user->ID)->first();
        $this->assertEquals(1200, $wallet->wallet_balance);
        $this->assertEquals(500, $wallet->earnings_balance);

        $entries = WalletLedgerEntry::where('user_id', $user->ID)->where('reason', 'earnings_transfer')->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(1000, $entries->firstWhere('balance_type', 'earnings')->amount);
        $this->assertSame('debit', $entries->firstWhere('balance_type', 'earnings')->direction);
        $this->assertSame('credit', $entries->firstWhere('balance_type', 'wallet')->direction);
    }

    public function test_the_customers_history_shows_a_transfer_once_as_money_in(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 300]);

        $this->postJson('/api/wallet/transfer', ['amount' => 300])->assertOk();

        $rows = collect($this->getJson('/api/account/transactions')->assertOk()->json())
            ->flatten(1)
            ->filter(fn ($row) => is_array($row) && ($row['type'] ?? null) === 'earnings_transfer');

        $this->assertCount(1, $rows);
    }

    public function test_moving_more_than_the_winnings_is_refused_and_nothing_changes(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 50, 'earnings_balance' => 100]);

        $this->postJson('/api/wallet/transfer', ['amount' => 100.01])->assertStatus(422);
        $this->postJson('/api/wallet/transfer', ['amount' => 0])->assertStatus(422);

        $this->assertEquals(50, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertEquals(100, Wallet::where('user_id', $user->ID)->value('earnings_balance'));
        $this->assertSame(0, WalletLedgerEntry::count());
    }

    public function test_a_customer_without_a_wallet_row_gets_a_plain_refusal(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/wallet/transfer', ['amount' => 10])
            ->assertStatus(422)
            ->assertJson(['message' => "You don't have that much in your winnings."]);
    }

    public function test_a_guest_cannot_transfer(): void
    {
        $this->postJson('/api/wallet/transfer', ['amount' => 10])->assertUnauthorized();
    }

    public function test_the_transfer_keeps_the_ledger_and_the_stored_balances_in_step(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        $ledger = app(WalletLedgerService::class);
        $ledger->recordCredit($user->ID, 'earnings', 800, 'prize_payout');
        Wallet::where('user_id', $user->ID)->update(['earnings_balance' => 800]);

        $this->postJson('/api/wallet/transfer', ['amount' => 350])->assertOk();

        $wallet = Wallet::where('user_id', $user->ID)->first();
        $this->assertEquals((float) $wallet->earnings_balance, $ledger->reconstructBalance($user->ID, 'earnings'));
        $this->assertEquals((float) $wallet->wallet_balance, $ledger->reconstructBalance($user->ID, 'wallet'));
    }

    // --- "Use winnings to cover it" ----------------------------------------

    public function test_winnings_cover_exactly_the_wallets_shortfall_in_the_same_purchase(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 300, 'earnings_balance' => 2000]);

        $this->buy(['use_winnings_for_shortfall' => true])->assertCreated();

        $wallet = Wallet::where('user_id', $user->ID)->first();
        $this->assertEquals(0, $wallet->wallet_balance);
        $this->assertEquals(1300, $wallet->earnings_balance); // only the ₦700 gap moved
        $this->assertSame(1, RaffleEntry::where('raffle_id', 9)->count());
    }

    public function test_without_the_flag_a_short_wallet_is_still_refused(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 300, 'earnings_balance' => 2000]);

        $this->buy()->assertStatus(402)->assertJson(['shortfall' => 700]);
        $this->assertEquals(2000, Wallet::where('user_id', $user->ID)->value('earnings_balance'));
    }

    public function test_when_wallet_and_winnings_together_are_not_enough_nothing_moves(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 300, 'earnings_balance' => 400]);

        $this->buy(['use_winnings_for_shortfall' => true])->assertStatus(402)->assertJson(['shortfall' => 300]);

        $wallet = Wallet::where('user_id', $user->ID)->first();
        $this->assertEquals(300, $wallet->wallet_balance);
        $this->assertEquals(400, $wallet->earnings_balance);
        $this->assertSame(0, WalletLedgerEntry::count());
    }

    public function test_a_taken_number_rolls_back_the_winnings_move_too(): void
    {
        $other = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $other->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->buy(['ticket_numbers' => [7]])->assertCreated();

        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 300, 'earnings_balance' => 2000]);

        $this->buy(['ticket_numbers' => [7], 'use_winnings_for_shortfall' => true])
            ->assertStatus(409)
            ->assertJson(['unavailable_numbers' => [7]]);

        $wallet = Wallet::where('user_id', $user->ID)->first();
        $this->assertEquals(300, $wallet->wallet_balance);
        $this->assertEquals(2000, $wallet->earnings_balance);
        $this->assertSame(0, WalletLedgerEntry::where('user_id', $user->ID)->count());
    }

    // --- Success screen and live grid ---------------------------------------

    public function test_a_purchase_returns_the_ticket_numbers_for_the_success_screen(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);

        $this->buy(['raffle_id' => 3, 'ticket_numbers' => [42, 5], 'unit_price' => 100, 'submitted_amount' => 180])
            ->assertCreated()
            ->assertJson(['raffle_id' => 3, 'ticket_numbers' => [5, 42]]);
    }

    public function test_the_live_update_carries_the_numbers_just_taken(): void
    {
        Event::fake([RaffleTicketsUpdated::class]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);

        $this->buy(['ticket_numbers' => [12]])->assertCreated();

        Event::assertDispatched(RaffleTicketsUpdated::class, fn (RaffleTicketsUpdated $e) => $e->raffleId === 9
            && $e->broadcastWith()['taken_numbers'] === [12]);
    }

    public function test_returning_to_the_number_grid_keeps_only_the_picks_that_are_still_free(): void
    {
        $other = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $other->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->buy(['ticket_numbers' => [4]])->assertCreated();

        $this->actingAsWordPressUser();

        $this->get('/raffles/9/numbers?qty=3&numbers=4,8,999,8,15')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Raffles/SelectNumbers')
                ->where('preselected', [8, 15])
                ->where('takenNumbers', [4]));
    }

    // --- Top up and come back ----------------------------------------------

    public function test_a_top_up_started_at_checkout_comes_back_to_that_checkout(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_paystack']);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 70000, 'currency' => 'NGN', 'id' => 5]]),
        ]);
        $this->actingAsWordPressUser();

        $response = $this->postJson('/api/deposits', ['amount' => 700, 'return_to' => '/checkout?raffle_id=9&qty=1&numbers=7'])->assertCreated();
        $reference = $response->json('reference');

        $this->get('/api/deposits/callback?reference='.$reference)
            ->assertRedirect('/checkout?raffle_id=9&qty=1&numbers=7&deposit='.$response->json('id'));
    }

    public function test_a_return_address_on_another_website_is_refused(): void
    {
        $this->actingAsWordPressUser();

        foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', 'checkout'] as $bad) {
            $this->postJson('/api/deposits', ['amount' => 700, 'return_to' => $bad])->assertStatus(422)->assertJsonValidationErrors('return_to');
        }
    }

    public function test_the_checkout_page_gets_the_smallest_top_up(): void
    {
        config(['payments.minimum_deposit' => 250]);
        $this->actingAsWordPressUser();

        $this->get('/checkout?raffle_id=9&qty=1&numbers=7')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('minimumDeposit', 250));
    }

    // --- Golden Box ----------------------------------------------------------

    private function leaveCheckout(string $query = 'raffle_id=9&qty=1&numbers=7'): void
    {
        $this->get('/checkout?'.$query)->assertOk();
    }

    public function test_leaving_checkout_unpaid_earns_a_golden_box_offer(): void
    {
        $this->actingAsWordPressUser();
        $this->leaveCheckout();

        $offer = $this->getJson('/api/golden-box')->assertOk()->json('offer');

        $this->assertSame('offered', $offer['state']);
        $this->assertSame(9, $offer['raffle_id']);
        $this->assertSame('Big Prize', $offer['raffle_title']);
        $this->assertSame([7], $offer['ticket_numbers']);
        $this->assertEquals(1000, $offer['price_before']);
        $this->assertEquals(900, $offer['price_after']);
        $this->assertNotNull($offer['ends_at']);
    }

    public function test_a_small_order_gets_no_offer(): void
    {
        $this->actingAsWordPressUser();
        $this->leaveCheckout('raffle_id=3&qty=1&numbers=7');

        $this->getJson('/api/golden-box')->assertOk()->assertJson(['offer' => null]);
    }

    public function test_no_offer_when_the_admin_switched_it_off(): void
    {
        config(['pricing.golden_box_enabled' => false]);
        $this->actingAsWordPressUser();
        $this->leaveCheckout();

        $this->getJson('/api/golden-box')->assertOk()->assertJson(['offer' => null]);
        $this->assertSame(0, GoldenBoxOffer::count());
    }

    public function test_the_offer_disappears_after_its_display_window(): void
    {
        $this->actingAsWordPressUser();
        $this->leaveCheckout();
        $offerId = $this->getJson('/api/golden-box')->json('offer.id');

        $this->travel(31)->minutes();

        $this->getJson('/api/golden-box')->assertJson(['offer' => null]);
        $this->postJson("/api/golden-box/{$offerId}/claim")->assertStatus(410);
    }

    public function test_the_golden_price_is_refused_without_a_claimed_offer(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->leaveCheckout();
        $this->getJson('/api/golden-box'); // shown, but never tapped

        $this->buy(['submitted_amount' => 900, 'is_golden_box' => true])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Your Golden Box discount has ended. The price is now ₦1,000.00. Please review your order before paying.']);
        $this->assertEquals(5000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_claiming_the_offer_discounts_that_order_once(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->leaveCheckout();
        $offerId = $this->getJson('/api/golden-box')->json('offer.id');

        $this->postJson("/api/golden-box/{$offerId}/claim")->assertOk()->assertJsonPath('offer.state', 'claimed');

        // The quote the checkout page shows now includes it...
        $this->getJson('/api/raffles/9/price-quote?quantity=1')
            ->assertOk()
            ->assertJson(['discounted' => 900, 'golden_box' => ['percent_off' => 10, 'savings' => 100]]);

        // ...a different ticket count doesn't get it...
        $this->getJson('/api/raffles/9/price-quote?quantity=2')->assertJson(['golden_box' => null]);

        // ...and the purchase charges exactly that (with different numbers, too).
        $this->buy(['ticket_numbers' => [11], 'submitted_amount' => 900])->assertCreated();
        $this->assertEquals(4100, Wallet::where('user_id', $user->ID)->value('wallet_balance'));

        $offer = GoldenBoxOffer::find($offerId);
        $this->assertSame('used', $offer->status);
        $this->assertNotNull($offer->raffle_transaction_id);

        // It can't be used a second time.
        $this->getJson('/api/raffles/9/price-quote?quantity=1')->assertJson(['discounted' => 1000, 'golden_box' => null]);
        $this->buy(['ticket_numbers' => [12], 'submitted_amount' => 900])->assertStatus(422);
    }

    public function test_a_discount_that_runs_out_before_paying_charges_nothing(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->leaveCheckout();
        $offerId = $this->getJson('/api/golden-box')->json('offer.id');
        $this->postJson("/api/golden-box/{$offerId}/claim")->assertOk();

        $this->travel(26)->minutes();

        $this->buy(['submitted_amount' => 900])->assertStatus(422);
        $this->assertEquals(5000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleEntry::count());
    }

    public function test_after_using_one_there_is_no_new_offer_until_the_cooldown_ends(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->leaveCheckout();
        $this->postJson('/api/golden-box/'.$this->getJson('/api/golden-box')->json('offer.id').'/claim')->assertOk();
        $this->buy(['submitted_amount' => 900])->assertCreated();

        $this->leaveCheckout('raffle_id=9&qty=1&numbers=20');
        $this->getJson('/api/golden-box')->assertJson(['offer' => null]);

        $this->travel(8)->days();
        $this->leaveCheckout('raffle_id=9&qty=1&numbers=21');
        $this->assertNotNull($this->getJson('/api/golden-box')->json('offer'));
    }

    public function test_paying_normally_closes_the_offer(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->leaveCheckout();

        $this->buy()->assertCreated();

        $this->getJson('/api/golden-box')->assertJson(['offer' => null]);
    }

    public function test_going_back_to_checkout_after_paying_shows_the_tickets_and_brings_no_offer(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $this->leaveCheckout();
        $this->buy()->assertCreated();

        // The phone's Back button reloads the checkout address.
        $this->get('/checkout?raffle_id=9&qty=1&numbers=7')->assertRedirect('/account/tickets');

        $this->getJson('/api/golden-box')->assertJson(['offer' => null]);
        $this->assertSame(0, GoldenBoxOffer::query()->where('status', 'open')->count());
    }

    public function test_an_offer_for_numbers_already_bought_is_not_shown(): void
    {
        $user = $this->actingAsWordPressUser();
        $this->leaveCheckout();
        RaffleEntry::query()->insert(['user_id' => $user->ID, 'raffle_id' => 9, 'ticket_number' => 7, 'txn_id' => 1, 'created_at' => now()]);

        $this->getJson('/api/golden-box')->assertJson(['offer' => null]);
        $this->assertSame('completed', GoldenBoxOffer::query()->first()->status);
    }

    public function test_one_customer_cannot_claim_anothers_offer(): void
    {
        $this->actingAsWordPressUser();
        $this->leaveCheckout();
        $offerId = $this->getJson('/api/golden-box')->json('offer.id');

        $this->actingAsWordPressUser();

        $this->postJson("/api/golden-box/{$offerId}/claim")->assertStatus(410);
        $this->assertSame('open', GoldenBoxOffer::find($offerId)->status);
    }

    public function test_a_guest_gets_no_golden_box_and_the_public_quote_has_none(): void
    {
        $this->getJson('/api/golden-box')->assertUnauthorized();
        $this->getJson('/api/raffles/9/price-quote?quantity=1')->assertOk()->assertJson(['discounted' => 1000, 'golden_box' => null]);
    }
}
