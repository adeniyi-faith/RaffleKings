<?php

namespace Tests\Unit;

use App\Exceptions\PaymentGatewayException;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralCommission;
use App\Models\Wallet;
use App\Notifications\DepositConfirmed;
use App\Notifications\ReferralCommissionEarned;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DepositServiceTest extends TestCase
{
    use RefreshDatabase;

    private DepositService $deposits;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_paystack']);
        config(['services.flutterwave.secret_key' => 'sk_test_flutterwave']);
        config(['services.flutterwave.secret_hash' => 'whsec_test']);
        $this->deposits = app(DepositService::class);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_initializing_a_deposit_uses_paystack_by_default(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']]),
        ]);
        $user = $this->makeUser();

        $deposit = $this->deposits->initialize($user, 5000, 'https://app.test/callback');

        $this->assertSame('paystack', $deposit->gateway);
        $this->assertSame('pending', $deposit->status);
        $this->assertSame('https://paystack.test/pay/abc', $deposit->authorization_url);
    }

    public function test_a_below_minimum_amount_is_refused(): void
    {
        $user = $this->makeUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->deposits->initialize($user, 1, 'https://app.test/callback');
    }

    public function test_a_paystack_failure_falls_back_to_flutterwave(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Service unavailable'], 503),
            'api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => ['link' => 'https://flutterwave.test/pay/xyz']]),
        ]);
        $user = $this->makeUser();

        $deposit = $this->deposits->initialize($user, 5000, 'https://app.test/callback');

        $this->assertSame('flutterwave', $deposit->gateway);
        $this->assertSame('https://flutterwave.test/pay/xyz', $deposit->authorization_url);
    }

    public function test_both_gateways_failing_marks_the_deposit_failed(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'down'], 503),
            'api.flutterwave.com/*' => Http::response(['status' => 'error', 'message' => 'down'], 503),
        ]);
        $user = $this->makeUser();

        try {
            $this->deposits->initialize($user, 5000, 'https://app.test/callback');
            $this->fail('Expected PaymentGatewayException was not thrown.');
        } catch (PaymentGatewayException) {
            // expected
        }

        $deposit = Deposit::query()->where('user_id', $user->ID)->first();
        $this->assertSame('failed', $deposit->status);
    }

    public function test_confirming_a_successful_deposit_credits_the_wallet(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']]),
        ]);
        $user = $this->makeUser();
        $deposit = $this->deposits->initialize($user, 5000, 'https://app.test/callback');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'data' => ['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 998877],
            ]),
        ]);

        Notification::fake();

        $confirmed = $this->deposits->confirm('paystack', $deposit->reference);

        $this->assertSame('successful', $confirmed->status);
        $this->assertSame('998877', $confirmed->gateway_transaction_id);
        $this->assertEquals(5000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));

        Notification::assertSentTo($user, DepositConfirmed::class);
    }

    public function test_a_failed_or_mismatched_confirmation_does_not_notify(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $user = $this->makeUser();
        $deposit = $this->deposits->initialize($user, 5000, 'https://app.test/callback');

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => ['status' => 'failed', 'amount' => 0, 'currency' => 'NGN']])]);

        Notification::fake();

        $this->deposits->confirm('paystack', $deposit->reference);

        Notification::assertNothingSent();
    }

    public function test_confirming_the_same_deposit_twice_only_credits_once(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $user = $this->makeUser();
        $deposit = $this->deposits->initialize($user, 5000, 'https://app.test/callback');

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => ['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 998877]])]);

        $this->deposits->confirm('paystack', $deposit->reference);
        $this->deposits->confirm('paystack', $deposit->reference);

        $this->assertEquals(5000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_a_gateway_reported_failure_marks_the_deposit_failed_without_crediting(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $user = $this->makeUser();
        $deposit = $this->deposits->initialize($user, 5000, 'https://app.test/callback');

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => ['status' => 'failed', 'amount' => 0, 'currency' => 'NGN']])]);

        $confirmed = $this->deposits->confirm('paystack', $deposit->reference);

        $this->assertSame('failed', $confirmed->status);
        $this->assertNull(Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_a_gateway_confirmed_amount_mismatch_is_flagged_not_credited(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $user = $this->makeUser();
        $deposit = $this->deposits->initialize($user, 5000, 'https://app.test/callback');

        // Gateway confirms a real payment, but for a different amount than requested.
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => ['status' => 'success', 'amount' => 100000, 'currency' => 'NGN', 'id' => 111]])]);

        $confirmed = $this->deposits->confirm('paystack', $deposit->reference);

        $this->assertSame('amount_mismatch', $confirmed->status);
        $this->assertNull(Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_confirming_a_deposit_pays_the_referrers_commission(): void
    {
        $referrer = $this->makeUser();
        $referee = $this->makeUser();
        WpUserMeta::create([
            'user_id' => $referee->ID,
            'meta_key' => 'referred_by',
            'meta_value' => (string) $referrer->ID,
        ]);

        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']])]);
        $deposit = $this->deposits->initialize($referee, 5000, 'https://app.test/callback');

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => ['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 111]])]);

        Notification::fake();

        $this->deposits->confirm('paystack', $deposit->reference);

        $this->assertSame(1, ReferralCommission::where('referrer_user_id', $referrer->ID)->count());
        Notification::assertSentTo($referrer, ReferralCommissionEarned::class);
    }
}
