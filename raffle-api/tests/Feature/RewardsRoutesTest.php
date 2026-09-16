<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * The Rewards hub (item 28) — same server-side login guard as the
 * account section: legacy rewards.php gates on `is_user_logged_in()`
 * too.
 */
class RewardsRoutesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_a_guest_is_redirected_to_login_with_a_way_back(): void
    {
        $response = $this->get('/rewards');

        $response->assertRedirect();
        $this->assertStringContainsString('/login?redirect=', $response->headers->get('Location'));
        $this->assertStringContainsString(urlencode('/rewards'), $response->headers->get('Location'));
    }

    public function test_a_logged_in_user_sees_the_real_page_with_their_referral_code(): void
    {
        $user = $this->actingAsWordPressUser();

        $response = $this->get('/rewards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Rewards/Index')->where('referralCode', $user->user_login));
    }
}
