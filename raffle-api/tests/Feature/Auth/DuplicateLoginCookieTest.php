<?php

namespace Tests\Feature\Auth;

use App\Auth\WordPressAuthCookieValidator;
use App\Models\Legacy\WpUser;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A browser can hold more than one "logged in" cookie with the same name
 * (e.g. a leftover one the old WordPress site set for the whole domain,
 * plus the fresh one a login here sets) and sends them all, oldest first.
 * PHP only keeps the first, so before this fix a stale leftover made a
 * user who had just logged in successfully look like a guest everywhere.
 */
class DuplicateLoginCookieTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'wordpress_logged_in_testhash';

    private const STALE = 'jane|1999999999|old-token-from-the-old-site|0000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'legacy.wp_logged_in_key' => 'test-key',
            'legacy.wp_logged_in_salt' => 'test-salt',
            'legacy.wp_cookiehash' => 'testhash',
        ]);

        WpUser::create([
            'user_login' => 'jane',
            'user_pass' => (new WordPressPasswordHasher)->make('correcthorse1'),
            'user_email' => 'jane@example.com',
            'display_name' => 'Jane',
        ]);
    }

    private function freshLoginCookie(): string
    {
        $response = $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'correcthorse1'])->assertOk();

        return collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === self::COOKIE && $c->getDomain() === null)
            ->getValue();
    }

    private function cookieHeader(string ...$values): string
    {
        return implode('; ', array_map(fn ($v) => self::COOKIE.'='.rawurlencode($v), $values));
    }

    public function test_a_stale_cookie_sent_first_no_longer_hides_a_valid_one(): void
    {
        $valid = $this->freshLoginCookie();

        $this->withHeaders(['Cookie' => $this->cookieHeader(self::STALE, $valid)])
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user_login', 'jane');
    }

    public function test_pages_also_see_the_user_as_logged_in_with_a_stale_cookie_first(): void
    {
        $valid = $this->freshLoginCookie();

        $this->withHeaders(['Cookie' => $this->cookieHeader(self::STALE, $valid)])
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('auth.user.name', 'Jane'));
    }

    public function test_only_stale_cookies_still_means_a_guest(): void
    {
        $this->withHeaders(['Cookie' => $this->cookieHeader(self::STALE, 'jane|1999999999|another|ffff')])
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_login_clears_leftover_domain_wide_copies_without_touching_the_new_cookie(): void
    {
        $response = $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'correcthorse1'])->assertOk();

        $cookies = collect($response->headers->getCookies())->filter(fn ($c) => $c->getName() === self::COOKIE);

        $fresh = $cookies->first(fn ($c) => $c->getDomain() === null);
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->isCleared());

        $leftover = $cookies->first(fn ($c) => $c->getDomain() === '.localhost');
        $this->assertNotNull($leftover);
        $this->assertTrue($leftover->isCleared());
    }

    public function test_logout_revokes_the_valid_session_even_when_a_stale_cookie_comes_first(): void
    {
        $valid = $this->freshLoginCookie();

        $this->withHeaders(['Cookie' => $this->cookieHeader(self::STALE, $valid)])
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertNull((new WordPressAuthCookieValidator('test-key', 'test-salt'))->resolve($valid));
    }
}
