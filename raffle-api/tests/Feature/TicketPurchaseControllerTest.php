<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class TicketPurchaseControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_an_authenticated_user_can_purchase_tickets_from_their_wallet(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $response = $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5,
            'ticket_numbers' => [10, 11],
            'unit_price' => 100,
            'is_golden_box' => false,
            'submitted_amount' => 180, // 2 tickets, <=200 tier: 10% off
            'funding_source' => 'wallet',
            'idempotency_key' => 'http-test-key-1',
        ]);

        $response->assertCreated();
        $response->assertJson(['status' => 'verified_final']);
        $this->assertSame(2, RaffleEntry::where('raffle_id', 5)->count());
        $this->assertEquals(820, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5,
            'ticket_numbers' => [1],
            'unit_price' => 100,
            'submitted_amount' => 100,
            'funding_source' => 'wallet',
            'idempotency_key' => 'http-test-key-2',
        ]);

        $response->assertUnauthorized();
    }

    public function test_insufficient_balance_returns_a_402_with_the_shortfall(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 10, 'earnings_balance' => 0]);

        $response = $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5,
            'ticket_numbers' => [1],
            'unit_price' => 100,
            'submitted_amount' => 100,
            'funding_source' => 'wallet',
            'idempotency_key' => 'http-test-key-3',
        ]);

        $response->assertStatus(402);
        $response->assertJson(['shortfall' => 90]);
    }

    public function test_a_ticket_number_collision_returns_a_409_and_leaves_the_wallet_untouched(): void
    {
        $firstUser = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $firstUser->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);
        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 7,
            'ticket_numbers' => [42],
            'unit_price' => 100,
            'submitted_amount' => 100,
            'funding_source' => 'wallet',
            'idempotency_key' => 'http-test-key-first',
        ])->assertCreated();

        $secondUser = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $secondUser->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $response = $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 7,
            'ticket_numbers' => [42],
            'unit_price' => 100,
            'submitted_amount' => 100,
            'funding_source' => 'wallet',
            'idempotency_key' => 'http-test-key-second',
        ]);

        $response->assertStatus(409);
        $response->assertJson(['unavailable_numbers' => [42]]);
        $this->assertEquals(1000, Wallet::where('user_id', $secondUser->ID)->value('wallet_balance'));
    }

    public function test_a_price_mismatch_returns_a_422(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $response = $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5,
            'ticket_numbers' => [1, 2],
            'unit_price' => 300,
            'submitted_amount' => 600, // correct discounted price is 450, not 600
            'funding_source' => 'wallet',
            'idempotency_key' => 'http-test-key-4',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_malformed_request_is_rejected_by_validation(): void
    {
        $this->actingAsWordPressUser();

        $response = $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5,
            // missing ticket_numbers, unit_price, etc.
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ticket_numbers', 'unit_price', 'submitted_amount', 'funding_source', 'idempotency_key']);
    }
}
