<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * Item 25's fix for the dead `localStorage.getItem('token')` login check
 * (which always returned null and silently misrouted every visitor,
 * logged in or not, past checkout): these routes check the REAL
 * `wordpress` guard server-side.
 *
 * Guests are welcome to see and pick numbers. At checkout a guest gets a
 * sign-in page for the same order (their numbers are held for a few
 * minutes) instead of the payment page, and comes straight back after
 * signing in — see NumberHoldsTest for the hold itself.
 */
class RaffleCheckoutFlowRoutesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function makeRaffle(): Raffle
    {
        return $this->createRaffle(['price' => '500', 'max' => '20', 'grand_prize' => 'A Prize']);
    }

    public function test_a_guest_can_see_and_pick_numbers_without_an_account(): void
    {
        $raffle = $this->makeRaffle();
        RaffleEntry::create(['user_id' => 1, 'raffle_id' => $raffle->public_id, 'ticket_number' => 4, 'txn_id' => 1]);

        $this->get("/raffles/{$raffle->public_id}/numbers?qty=3")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Raffles/SelectNumbers')
                ->where('qty', 3)
                ->where('takenNumbers', [4]));
    }

    public function test_a_guest_visiting_checkout_gets_a_sign_in_page_for_the_same_order(): void
    {
        $raffle = $this->makeRaffle();

        $this->get("/checkout?raffle_id={$raffle->public_id}&qty=2&numbers=5,6")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/GuestGate')
                ->where('qty', 2)
                ->where('ticketNumbers', [5, 6])
                // After signing in they land back on this exact order.
                ->where('returnTo', "/checkout?raffle_id={$raffle->public_id}&qty=2&numbers=5,6"));
    }

    public function test_a_guest_at_checkout_with_the_wrong_number_of_tickets_is_refused(): void
    {
        $raffle = $this->makeRaffle();

        $this->get("/checkout?raffle_id={$raffle->public_id}&qty=3&numbers=1,2")->assertStatus(422);
    }

    public function test_a_guest_at_checkout_for_an_unknown_raffle_gets_a_404(): void
    {
        $this->get('/checkout?raffle_id=999999&qty=1&numbers=5')->assertNotFound();
    }

    public function test_a_logged_in_user_sees_the_number_selection_page_with_real_taken_numbers(): void
    {
        $this->actingAsWordPressUser();
        $raffle = $this->makeRaffle();

        $buyer = WpUser::create(['user_login' => 'other', 'user_pass' => 'x', 'user_email' => 'o@example.com']);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 4, 'txn_id' => 1]);

        $response = $this->get("/raffles/{$raffle->public_id}/numbers?qty=2");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Raffles/SelectNumbers')
            ->where('qty', 2)
            ->where('takenNumbers', [4]));
    }

    public function test_checkout_rejects_a_ticket_count_that_does_not_match_the_quantity(): void
    {
        $this->actingAsWordPressUser();
        $raffle = $this->makeRaffle();

        $this->get("/checkout?raffle_id={$raffle->public_id}&qty=3&numbers=1,2")->assertStatus(422);
    }

    public function test_checkout_renders_for_a_logged_in_user_with_matching_numbers(): void
    {
        $this->actingAsWordPressUser();
        $raffle = $this->makeRaffle();

        $response = $this->get("/checkout?raffle_id={$raffle->public_id}&qty=2&numbers=1,2");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Checkout/Index')
            ->where('qty', 2)
            ->where('ticketNumbers', [1, 2]));
    }

    public function test_the_raffle_details_page_404s_for_an_unknown_raffle(): void
    {
        $this->get('/raffles/999999')->assertNotFound();
    }
}
