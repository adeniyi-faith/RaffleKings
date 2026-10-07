<?php

namespace Tests\Feature;

use App\Models\Wallet;
use App\Services\Payments\PaystackGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\ConfirmsBankAccountCode;
use Tests\TestCase;

/** Phase 10 security pass (OVERHAUL_CHECKLIST.md item 39). */
class SecurityHardeningTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, ConfirmsBankAccountCode, RefreshDatabase;

    public function test_a_change_sent_from_another_website_is_blocked(): void
    {
        $this->actingAsWordPressUser();

        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/bank-accounts', ['bank_name' => 'X', 'account_number' => '0123456789', 'account_name' => 'Thief'])
            ->assertStatus(419);

        $this->withHeader('Origin', 'null')->postJson('/api/rewards/daily-claim')->assertStatus(419);

        // Same machine, different port: a different website.
        $this->withHeader('Origin', 'http://localhost:8124')->postJson('/api/rewards/daily-claim')->assertStatus(419);
    }

    public function test_the_referer_is_checked_when_there_is_no_origin(): void
    {
        $this->actingAsWordPressUser();

        $this->withHeader('Referer', 'https://evil.example/page')->postJson('/api/rewards/daily-claim')->assertStatus(419);
    }

    public function test_requests_from_this_site_still_work(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        // The site's own address, whatever APP_URL is in this environment.
        $this->withHeader('Origin', rtrim(config('app.url'), '/'))->postJson('/api/rewards/daily-claim')->assertOk();
    }

    public function test_extra_trusted_hosts_can_be_allowed(): void
    {
        config(['security.trusted_origins' => ['www.example.ng']]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        $this->withHeader('Origin', 'https://www.example.ng')->postJson('/api/rewards/daily-claim')->assertOk();
    }

    public function test_reading_is_never_blocked_and_webhooks_are_exempt(): void
    {
        $this->withHeader('Origin', 'https://evil.example')->getJson('/api/raffles')->assertOk();

        // Refused for its signature, not for where it came from.
        $this->withHeader('Origin', 'https://evil.example')->postJson('/api/webhooks/paystack', [])->assertStatus(401);
    }

    public function test_pages_and_api_responses_carry_security_headers(): void
    {
        foreach (['/', '/api/raffles'] as $url) {
            $this->get($url)
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        }
    }

    public function test_money_actions_are_rate_limited_per_person(): void
    {
        $this->actingAsWordPressUser();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/rewards/redeem');
        }

        $this->postJson('/api/rewards/redeem')->assertStatus(429);
    }

    public function test_a_paystack_webhook_is_refused_when_no_secret_key_is_set(): void
    {
        $body = '{"event":"charge.success"}';
        $request = Request::create('/api/webhooks/paystack', 'POST', [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, ''),
        ], $body);

        $this->assertFalse((new PaystackGateway(null))->verifyWebhookSignature($request));
        $this->assertFalse((new PaystackGateway(''))->verifyWebhookSignature($request));
    }
}
