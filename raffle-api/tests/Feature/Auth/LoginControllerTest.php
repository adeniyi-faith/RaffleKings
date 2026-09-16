<?php

namespace Tests\Feature\Auth;

use App\Auth\WordPressAuthCookieValidator;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginControllerTest extends TestCase
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

    private function cookieFrom($response)
    {
        return collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'wordpress_logged_in_testhash');
    }

    public function test_a_correct_login_issues_a_cookie_the_real_wordpress_guard_recognises(): void
    {
        $user = $this->makeUser('jane', 'correcthorse1');

        $response = $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'correcthorse1']);

        $response->assertOk()->assertJsonPath('user.user_login', 'jane');

        $cookie = $this->cookieFrom($response);
        $this->assertNotNull($cookie);

        $resolved = (new WordPressAuthCookieValidator('test-key', 'test-salt'))->resolve($cookie->getValue());
        $this->assertNotNull($resolved);
        $this->assertSame($user->ID, $resolved->ID);
    }

    public function test_login_accepts_email_as_well_as_username(): void
    {
        $this->makeUser('mike', 'correcthorse1');

        $this->postJson('/api/auth/login', ['username' => 'mike@example.com', 'password' => 'correcthorse1'])
            ->assertOk();
    }

    public function test_a_wrong_password_is_rejected_without_leaking_which_part_was_wrong(): void
    {
        $this->makeUser('jane', 'correcthorse1');

        $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_an_unknown_username_is_rejected_the_same_way_as_a_wrong_password(): void
    {
        $this->postJson('/api/auth/login', ['username' => 'ghost', 'password' => 'whatever1'])
            ->assertStatus(422);
    }

    public function test_a_banned_user_cannot_log_in(): void
    {
        $user = $this->makeUser('banned', 'correcthorse1');
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        $this->postJson('/api/auth/login', ['username' => 'banned', 'password' => 'correcthorse1'])
            ->assertStatus(422);
    }

    public function test_logging_out_revokes_the_session_so_the_same_cookie_no_longer_works(): void
    {
        $this->makeUser('jane', 'correcthorse1');

        $loginResponse = $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'correcthorse1']);
        $cookie = $this->cookieFrom($loginResponse);

        $validator = new WordPressAuthCookieValidator('test-key', 'test-salt');
        $this->assertNotNull($validator->resolve($cookie->getValue()), 'sanity check: cookie works before logout');

        $this->withCredentials()
            ->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertNull($validator->resolve($cookie->getValue()), 'the cookie must stop working immediately after logout');
    }
}
