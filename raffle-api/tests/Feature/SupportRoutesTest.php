<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * The Help & Support hub (item 29) — the ticket panel needs a real
 * logged-in user, same server-side guard as the account section; the
 * Learning Hub is public content, same as legacy tutorials.php.
 */
class SupportRoutesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_a_guest_is_redirected_to_login_with_a_way_back(): void
    {
        $response = $this->get('/support');

        $response->assertRedirect();
        $this->assertStringContainsString('/login?redirect=', $response->headers->get('Location'));
        $this->assertStringContainsString(urlencode('/support'), $response->headers->get('Location'));
    }

    public function test_a_logged_in_user_sees_the_real_support_page(): void
    {
        $this->actingAsWordPressUser();

        $response = $this->get('/support');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Support/Index'));
    }

    public function test_the_learning_hub_is_public(): void
    {
        $response = $this->get('/support/tutorials');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Support/Tutorials'));
    }
}
