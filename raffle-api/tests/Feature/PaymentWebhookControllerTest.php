<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_paystack']);
        config(['services.flutterwave.secret_key' => 'sk_test_flutterwave']);
        config(['services.flutterwave.secret_hash' => 'whsec_test']);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_a_paystack_webhook_with_a_valid_signature_credits_the_wallet(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $user = $this->makeUser();
        $deposit = app(DepositService::class)->initialize($user, 5000, 'https://app.test/callback');

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => ['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 12345]])]);

        $payload = json_encode(['event' => 'charge.success', 'data' => ['reference' => $deposit->reference]]);
        $signature = hash_hmac('sha512', $payload, 'sk_test_paystack');

        $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'HTTP_x-paystack-signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $this->assertEquals(5000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_a_paystack_webhook_with_an_invalid_signature_is_rejected(): void
    {
        $payload = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'dep_bogus']]);

        $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'HTTP_x-paystack-signature' => 'not-the-real-signature',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);
    }

    public function test_a_flutterwave_webhook_with_a_valid_hash_credits_the_wallet(): void
    {
        // Paystack fails at initialization, so the deposit automatically
        // falls back to Flutterwave — the same path a real Paystack outage
        // would take.
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'down'], 503),
            'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://flutterwave.test/pay/xyz']]),
        ]);
        $user = $this->makeUser();
        $deposit = app(DepositService::class)->initialize($user, 3000, 'https://app.test/callback');
        $this->assertSame('flutterwave', $deposit->gateway);

        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['data' => ['status' => 'successful', 'amount' => 3000, 'currency' => 'NGN', 'id' => 777]])]);

        $payload = json_encode(['event' => 'charge.completed', 'data' => ['tx_ref' => $deposit->reference]]);

        $response = $this->call('POST', '/api/webhooks/flutterwave', [], [], [], [
            'HTTP_verif-hash' => 'whsec_test',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $this->assertEquals(3000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_a_flutterwave_webhook_with_a_wrong_hash_is_rejected(): void
    {
        $payload = json_encode(['event' => 'charge.completed', 'data' => ['tx_ref' => 'dep_bogus']]);

        $response = $this->call('POST', '/api/webhooks/flutterwave', [], [], [], [
            'HTTP_verif-hash' => 'wrong-hash',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);
    }
}
