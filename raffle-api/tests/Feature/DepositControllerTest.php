<?php

namespace Tests\Feature;

use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class DepositControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_paystack']);
        config(['services.flutterwave.secret_key' => 'sk_test_flutterwave']);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/deposits', ['amount' => 5000])->assertUnauthorized();
    }

    public function test_a_user_can_initialize_a_deposit(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $this->actingAsWordPressUser();

        $response = $this->postJson('/api/deposits', ['amount' => 5000]);

        $response->assertCreated();
        $response->assertJson(['gateway' => 'paystack', 'status' => 'pending', 'authorization_url' => 'https://paystack.test/pay/abc']);
    }

    public function test_an_amount_below_the_minimum_is_rejected(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/deposits', ['amount' => 1])->assertStatus(422);
    }

    public function test_a_user_can_check_their_own_deposit_status(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $user = $this->actingAsWordPressUser();
        $deposit = app(DepositService::class)->initialize($user, 5000, 'https://app.test/callback');

        $this->getJson("/api/deposits/{$deposit->id}")->assertOk()->assertJson(['status' => 'pending']);
    }

    public function test_a_user_cannot_view_another_users_deposit(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $owner = $this->actingAsWordPressUser();
        $deposit = app(DepositService::class)->initialize($owner, 5000, 'https://app.test/callback');
        $this->actingAsWordPressUser();

        $this->getJson("/api/deposits/{$deposit->id}")->assertStatus(404);
    }

    public function test_both_gateways_failing_returns_a_502(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'down'], 503),
            'api.flutterwave.com/*' => Http::response(['status' => 'error', 'message' => 'down'], 503),
        ]);
        $this->actingAsWordPressUser();

        $this->postJson('/api/deposits', ['amount' => 5000])->assertStatus(502);
    }

    /**
     * Item 26 discovered a real gap: `initialize()` already passed a
     * `/api/deposits/callback` URL to the gateway as where to send the
     * user's browser back to, but no route ever received it — a real
     * payer would have hit a 404 right after paying. This proves the
     * fix: the callback re-verifies (idempotently) and lands the user
     * back on the wallet page with the deposit id to show its status.
     */
    public function test_the_gateway_callback_confirms_the_deposit_and_redirects_to_the_wallet_page(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 999]]),
        ]);
        $user = $this->actingAsWordPressUser();
        $deposit = app(DepositService::class)->initialize($user, 5000, 'https://app.test/api/deposits/callback');

        $response = $this->get('/api/deposits/callback?reference='.$deposit->reference);

        $response->assertRedirect('/account/wallet?deposit='.$deposit->id);
        $this->assertSame('successful', $deposit->fresh()->status);
    }

    public function test_the_gateway_callback_with_an_unknown_reference_redirects_without_error(): void
    {
        $this->get('/api/deposits/callback?reference=does-not-exist')->assertRedirect('/account/wallet');
    }
}
