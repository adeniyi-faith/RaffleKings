<?php

namespace Tests\Unit;

use App\Auth\WordPressAuthCookieValidator;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves App\Auth\WordPressAuthCookieValidator actually implements
 * WordPress's own cookie algorithm — not just "is internally
 * consistent with itself". The cookie-building helper below is written
 * independently from the production class (same public WordPress
 * algorithm, re-derived here), so a bug in one is very unlikely to be
 * mirrored by a matching bug in the other.
 */
class WordPressAuthCookieValidatorTest extends TestCase
{
    use RefreshDatabase;

    private const LOGGED_IN_KEY = 'test-logged-in-key';

    private const LOGGED_IN_SALT = 'test-logged-in-salt';

    private WordPressAuthCookieValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new WordPressAuthCookieValidator(self::LOGGED_IN_KEY, self::LOGGED_IN_SALT);
    }

    private function makeUser(string $login = 'jane', string $pass = 'irrelevant-hash-1234567890'): WpUser
    {
        return WpUser::create([
            'user_login' => $login,
            'user_pass' => $pass,
            'user_email' => $login.'@example.com',
            'display_name' => ucfirst($login),
        ]);
    }

    private function giveActiveSession(WpUser $user, string $token, int $expiresInSeconds = 3600): void
    {
        $sessions = [
            hash('sha256', $token) => [
                'expiration' => time() + $expiresInSeconds,
                'ip' => '127.0.0.1',
                'ua' => 'phpunit',
                'login' => time(),
            ],
        ];

        WpUserMeta::create([
            'user_id' => $user->getKey(),
            'meta_key' => 'session_tokens',
            'meta_value' => serialize($sessions),
        ]);
    }

    /** Independently re-implements wp_generate_auth_cookie()'s algorithm. */
    private function buildCookie(WpUser $user, string $token, int $expiration): string
    {
        $passFrag = substr($user->getAuthPassword(), 8, 4);

        $key = hash_hmac(
            'md5',
            "{$user->user_login}|{$passFrag}|{$expiration}|{$token}",
            self::LOGGED_IN_KEY.self::LOGGED_IN_SALT,
        );

        $hmac = hash_hmac('sha256', "{$user->user_login}|{$expiration}|{$token}", $key);

        return "{$user->user_login}|{$expiration}|{$token}|{$hmac}";
    }

    public function test_a_valid_cookie_resolves_to_the_correct_user(): void
    {
        $user = $this->makeUser();
        $token = 'raw-session-token';
        $this->giveActiveSession($user, $token);

        $cookie = $this->buildCookie($user, $token, time() + 3600);

        $resolved = $this->validator->resolve($cookie);

        $this->assertNotNull($resolved);
        $this->assertSame($user->ID, $resolved->ID);
    }

    public function test_a_missing_cookie_resolves_to_null(): void
    {
        $this->assertNull($this->validator->resolve(null));
        $this->assertNull($this->validator->resolve(''));
    }

    public function test_a_malformed_cookie_resolves_to_null(): void
    {
        $this->assertNull($this->validator->resolve('not-enough-parts'));
        $this->assertNull($this->validator->resolve('a|b|c|d|e'));
    }

    public function test_an_expired_cookie_resolves_to_null(): void
    {
        $user = $this->makeUser();
        $token = 'raw-session-token';
        $this->giveActiveSession($user, $token);

        $cookie = $this->buildCookie($user, $token, time() - 10);

        $this->assertNull($this->validator->resolve($cookie));
    }

    public function test_a_tampered_hmac_resolves_to_null(): void
    {
        $user = $this->makeUser();
        $token = 'raw-session-token';
        $this->giveActiveSession($user, $token);

        $cookie = $this->buildCookie($user, $token, time() + 3600);
        $tampered = substr($cookie, 0, -4).'0000';

        $this->assertNull($this->validator->resolve($tampered));
    }

    public function test_a_cookie_for_an_expiration_that_does_not_match_the_hmac_resolves_to_null(): void
    {
        $user = $this->makeUser();
        $token = 'raw-session-token';
        $this->giveActiveSession($user, $token);

        $cookie = $this->buildCookie($user, $token, time() + 3600);

        // Swap in a later, still-unexpired expiration without recomputing
        // the HMAC — simulates an attacker trying to extend their own
        // session's lifetime.
        $parts = explode('|', $cookie);
        $parts[1] = (string) (time() + 999999);
        $forged = implode('|', $parts);

        $this->assertNull($this->validator->resolve($forged));
    }

    public function test_an_unknown_username_resolves_to_null(): void
    {
        $ghost = new WpUser([
            'user_login' => 'nobody',
            'user_pass' => 'irrelevant-hash-1234567890',
        ]);
        $ghost->ID = 999999;

        $cookie = $this->buildCookie($ghost, 'token', time() + 3600);

        $this->assertNull($this->validator->resolve($cookie));
    }

    public function test_a_cookie_with_no_matching_session_token_resolves_to_null(): void
    {
        $user = $this->makeUser();

        // No giveActiveSession() call at all — no session_tokens usermeta exists.
        $cookie = $this->buildCookie($user, 'never-issued-token', time() + 3600);

        $this->assertNull($this->validator->resolve($cookie));
    }

    public function test_a_cookie_whose_session_entry_has_expired_resolves_to_null(): void
    {
        $user = $this->makeUser();
        $token = 'raw-session-token';
        $this->giveActiveSession($user, $token, expiresInSeconds: -10);

        $cookie = $this->buildCookie($user, $token, time() + 3600);

        $this->assertNull($this->validator->resolve($cookie));
    }

    public function test_logging_out_by_removing_the_session_token_invalidates_an_otherwise_valid_cookie(): void
    {
        $user = $this->makeUser();
        $token = 'raw-session-token';
        $this->giveActiveSession($user, $token);
        $cookie = $this->buildCookie($user, $token, time() + 3600);

        $this->assertNotNull($this->validator->resolve($cookie), 'sanity check: cookie is valid before logout');

        // This is what a *correct* logout does server-side: delete the
        // session_tokens row (mirrors WP_User_Meta_Session_Tokens'
        // destroy()), unlike the live site's logout.php bug (TD-04).
        WpUserMeta::where('user_id', $user->getKey())->where('meta_key', 'session_tokens')->delete();

        $this->assertNull($this->validator->resolve($cookie));
    }
}
