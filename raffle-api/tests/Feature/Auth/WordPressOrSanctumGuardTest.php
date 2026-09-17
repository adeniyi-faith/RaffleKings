<?php

namespace Tests\Feature\Auth;

use App\Models\Legacy\WpUser;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 34 — the `wordpress` guard now
 * accepts a Sanctum bearer token as a fully working, first-class way to
 * authenticate, alongside (not instead of) the original WordPress
 * cookie. These tests prove both halves keep working independently, and
 * that neither is required when the other is present.
 */
class WordPressOrSanctumGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'legacy.wp_logged_in_key' => 'test-key',
            'legacy.wp_logged_in_salt' => 'test-salt',
            'legacy.wp_cookiehash' => 'testhash',
        ]);
    }

    private function makeUser(string $login, string $password): WpUser
    {
        return WpUser::create([
            'user_login' => $login,
            'user_pass' => (new WordPressPasswordHasher)->make($password),
            'user_email' => $login.'@example.com',
        ]);
    }

    public function test_login_response_includes_a_sanctum_token_that_authenticates_a_protected_route(): void
    {
        $this->makeUser('jane', 'correcthorse1');

        $login = $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'correcthorse1']);
        $login->assertOk();
        $token = $login->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // No cookie sent at all — the bearer token alone must be enough.
        $me = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/me');

        $me->assertOk()->assertJsonPath('user_login', 'jane');
    }

    public function test_register_response_also_includes_a_working_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username' => 'newbie',
            'email' => 'newbie@example.com',
            'password' => 'correcthorse1',
        ]);

        $response->assertCreated();
        $token = $response->json('token');
        $this->assertIsString($token);

        $me = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/me');
        $me->assertOk()->assertJsonPath('user_login', 'newbie');
    }

    public function test_a_garbage_bearer_token_does_not_authenticate_and_falls_back_to_guest(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])->getJson('/api/me');

        $response->assertUnauthorized();
    }

    public function test_the_original_wordpress_cookie_still_works_with_no_token_at_all(): void
    {
        $user = $this->makeUser('cookie-user', 'correcthorse1');

        $login = $this->postJson('/api/auth/login', ['username' => 'cookie-user', 'password' => 'correcthorse1']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'wordpress_logged_in_testhash');
        $this->assertNotNull($cookie);

        // Reuse the real cookie the login response set, no Authorization header at all.
        $me = $this->withCredentials()
            ->withUnencryptedCookies(['wordpress_logged_in_testhash' => $cookie->getValue()])
            ->getJson('/api/me');

        $me->assertOk()->assertJsonPath('user_login', 'cookie-user');
    }

    public function test_logout_revokes_only_the_token_that_was_presented(): void
    {
        $user = $this->makeUser('multi-device', 'correcthorse1');

        $first = $this->postJson('/api/auth/login', ['username' => 'multi-device', 'password' => 'correcthorse1'])->json('token');
        $second = $this->postJson('/api/auth/login', ['username' => 'multi-device', 'password' => 'correcthorse1'])->json('token');

        $this->withHeaders(['Authorization' => "Bearer {$first}"])->postJson('/api/auth/logout')->assertOk();

        $this->withHeaders(['Authorization' => "Bearer {$first}"])->getJson('/api/me')->assertUnauthorized();
        $this->withHeaders(['Authorization' => "Bearer {$second}"])->getJson('/api/me')->assertOk();
    }
}
