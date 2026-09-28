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
 * logged in or not, past checkout): these routes now check the REAL
 * `wordpress` guard server-side and redirect a guest to /login with a
 * `redirect` back to where they were, instead of ever rendering the page
 * for someone who isn't actually logged in.
 */
class RaffleCheckoutFlowRoutesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function makeRaffle(): Raffle
    {
        return $this->createRaffle(['price' => '500', 'max' => '20', 'grand_prize' => 'A Prize']);
    }

    public function test_a_guest_visiting_number_selection_is_redirected_to_login_with_a_way_back(): void
    {
        $raffle = $this->makeRaffle();

        $response = $this->get("/raffles/{$raffle->public_id}/numbers?qty=3");

        $response->assertRedirect();
        $this->assertStringContainsString('/login?redirect=', $response->headers->get('Location'));
    }

    public function test_a_guest_visiting_checkout_is_redirected_to_login(): void
    {
        $response = $this->get('/checkout?raffle_id=1&qty=1&numbers=5');

        $response->assertRedirect();
        $this->assertStringContainsString('/login?redirect=', $response->headers->get('Location'));
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
