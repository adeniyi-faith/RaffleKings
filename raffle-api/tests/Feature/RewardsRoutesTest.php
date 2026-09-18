<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * The Rewards hub (item 28) is public, same as the legacy rewards.php:
 * that page only gates its POST mini-API (claiming, spinning) on
 * `is_user_logged_in()`, not the page itself -- a guest gets the same
 * page back with an empty/zeroed state, not a redirect.
 */
class RewardsRoutesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_a_guest_sees_the_page_with_no_referral_code(): void
    {
        $response = $this->get('/rewards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Rewards/Index')->where('referralCode', null));
    }

    public function test_a_logged_in_user_sees_the_real_page_with_their_referral_code(): void
    {
        $user = $this->actingAsWordPressUser();

        $response = $this->get('/rewards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Rewards/Index')->where('referralCode', $user->user_login));
    }
}
