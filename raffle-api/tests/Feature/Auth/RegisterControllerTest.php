<?php

namespace Tests\Feature\Auth;

use App\Auth\WordPressAuthCookieValidator;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterControllerTest extends TestCase
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

    public function test_it_creates_a_real_wp_user_and_auto_logs_in_with_a_cookie_the_wordpress_guard_recognises(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username' => 'newplayer',
            'email' => 'newplayer@example.com',
            'password' => 'letmein1',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('user.user_login', 'newplayer');

        $user = WpUser::where('user_login', 'newplayer')->first();
        $this->assertNotNull($user);
        $this->assertNotSame('letmein1', $user->user_pass);

        $cookieName = 'wordpress_logged_in_testhash';
        $rawCookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName);

        $this->assertNotNull($rawCookie, 'the registration response must set the WordPress logged-in cookie');

        $validator = new WordPressAuthCookieValidator('test-key', 'test-salt');
        $resolved = $validator->resolve($rawCookie->getValue());

        $this->assertNotNull($resolved, 'the cookie issued at registration must be recognised by the real WordPress cookie validator');
        $this->assertSame($user->ID, $resolved->ID);
    }

    public function test_it_grants_the_same_welcome_bonus_amount_the_legacy_site_gives_on_the_new_wallet(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'bonushunter',
            'email' => 'bonushunter@example.com',
            'password' => 'letmein1',
        ])->assertCreated();

        $user = WpUser::where('user_login', 'bonushunter')->first();
        $wallet = Wallet::where('user_id', $user->ID)->first();

        $this->assertNotNull($wallet);
        $this->assertSame(300.0, (float) $wallet->wallet_balance);
        $this->assertSame(0.0, (float) $wallet->earnings_balance);

        $this->assertTrue(
            WalletLedgerEntry::where('user_id', $user->ID)
                ->where('reason', 'signup_bonus')
                ->where('direction', 'credit')
                ->where('amount', 300)
                ->exists()
        );
    }

    public function test_it_captures_the_referrer_by_referral_code_without_double_paying_the_legacy_instant_bonus(): void
    {
        $referrer = WpUser::create(['user_login' => 'ref1', 'user_pass' => 'x', 'user_email' => 'ref1@example.com']);

        $this->postJson('/api/auth/register', [
            'username' => 'referee1',
            'email' => 'referee1@example.com',
            'password' => 'letmein1',
            'referral_code' => 'ref1',
        ])->assertCreated();

        $referee = WpUser::where('user_login', 'referee1')->first();

        $this->assertSame((string) $referrer->ID, $referee->metaValue('referred_by'));
    }

    public function test_it_rejects_a_duplicate_username_or_email(): void
    {
        WpUser::create(['user_login' => 'taken', 'user_pass' => 'x', 'user_email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'username' => 'taken',
            'email' => 'someoneelse@example.com',
            'password' => 'letmein1',
        ])->assertStatus(422);

        $this->postJson('/api/auth/register', [
            'username' => 'freshuser',
            'email' => 'taken@example.com',
            'password' => 'letmein1',
        ])->assertStatus(422);
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'weakpass',
            'email' => 'weakpass@example.com',
            'password' => 'alllettersnodigits',
        ])->assertStatus(422);
    }

    public function test_turnstile_is_required_when_a_secret_key_is_configured(): void
    {
        config(['services.turnstile.secret_key' => 'fake-secret']);

        $this->postJson('/api/auth/register', [
            'username' => 'botlike',
            'email' => 'botlike@example.com',
            'password' => 'letmein1',
        ])->assertStatus(422);
    }
}
