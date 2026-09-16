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
}
