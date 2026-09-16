<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * Proves every Inertia page gets the REAL logged-in state via the
 * `wordpress` guard, not Laravel's default `web` guard (which never
 * carries the WordPress session cookie and would always report a guest)
 * — the server-side half of fixing item 25's dead localStorage-token
 * login check.
 */
class InertiaAuthSharingTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_a_guest_visiting_a_page_sees_a_null_auth_user(): void
    {
        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page->where('auth.user', null));
    }

    public function test_a_logged_in_wordpress_user_sees_their_real_identity(): void
    {
        $user = $this->actingAsWordPressUser(['display_name' => 'Jane Doe']);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->ID)
            ->where('auth.user.name', 'Jane Doe'));
    }
}
