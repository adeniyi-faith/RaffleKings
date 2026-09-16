<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end proof that a real HTTP request carrying WordPress's own
 * "logged in" cookie authenticates against a Laravel route protected by
 * `auth:wordpress` (see routes/api.php, AuthBridgeController::me()).
 */
class WordPressSessionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function cookieName(): string
    {
        return 'wordpress_logged_in_'.config('legacy.wp_cookiehash');
    }

    private function makeAuthenticatedUser(): array
    {
        $user = WpUser::create([
            'user_login' => 'winnie',
            'user_pass' => 'irrelevant-hash-1234567890',
            'user_email' => 'winnie@example.com',
            'display_name' => 'Winnie',
        ]);

        $token = 'raw-session-token';

        WpUserMeta::create([
            'user_id' => $user->getKey(),
            'meta_key' => 'session_tokens',
            'meta_value' => serialize([
                hash('sha256', $token) => ['expiration' => time() + 3600],
            ]),
        ]);

        $expiration = time() + 3600;
        $passFrag = substr($user->user_pass, 8, 4);
        $key = hash_hmac(
            'md5',
            "{$user->user_login}|{$passFrag}|{$expiration}|{$token}",
            config('legacy.wp_logged_in_key').config('legacy.wp_logged_in_salt'),
        );
        $hmac = hash_hmac('sha256', "{$user->user_login}|{$expiration}|{$token}", $key);
        $cookie = "{$user->user_login}|{$expiration}|{$token}|{$hmac}";

        return [$user, $cookie];
    }

    public function test_a_request_with_a_valid_wordpress_cookie_can_reach_a_protected_route(): void
    {
        [$user, $cookie] = $this->makeAuthenticatedUser();

        // This cookie is WordPress's own, never Laravel's encrypted cookie
        // jar — withCookie() would encrypt it as if Laravel itself had
        // set it, which is not how a real browser request looks.
        // withCredentials() is required too — Laravel's JSON test helpers
        // silently drop all cookies otherwise.
        $response = $this->withCredentials()
            ->withUnencryptedCookies([$this->cookieName() => $cookie])
            ->getJson('/api/me');

        $response->assertOk();
        $response->assertJson([
            'id' => $user->ID,
            'user_login' => 'winnie',
            'user_email' => 'winnie@example.com',
            'display_name' => 'Winnie',
        ]);
    }

    public function test_a_request_with_no_cookie_is_rejected(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertUnauthorized();
    }

    public function test_a_request_with_an_invalid_cookie_is_rejected(): void
    {
        $response = $this->withCredentials()
            ->withUnencryptedCookies([$this->cookieName() => 'garbage|123|abc|def'])
            ->getJson('/api/me');

        $response->assertUnauthorized();
    }
}
