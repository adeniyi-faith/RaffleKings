<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use App\Notifications\SystemProblemAdminAlert;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Money-safety audit (D5, E5, E8, K4, M2): a paid top-up is never lost, a
 * top-up that is still processing is never called failed, and a gateway
 * answer with no amount is never read as ₦0.
 */
class TopUpSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_paystack', 'payments.default_gateway' => 'paystack']);

        // One fake whose answer to "verify" can be changed between calls.
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/transaction/initialize')) {
                return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']]);
            }

            return Http::response($this->verifyBody, $this->verifyStatus);
        });
    }

    private array $verifyBody = ['status' => true, 'data' => []];

    private int $verifyStatus = 200;

    private function startDeposit(float $amount = 5000): Deposit
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);

        return app(DepositService::class)->initialize($user, $amount, 'https://app.test/callback');
    }

    private function webhook(Deposit $deposit, string $id = '9001')
    {
        $payload = json_encode(['event' => 'charge.success', 'data' => ['id' => $id, 'reference' => $deposit->reference]]);

        return $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'HTTP_x-paystack-signature' => hash_hmac('sha512', $payload, 'sk_test_paystack'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function verifyReturns(array $data, int $status = 200): void
    {
        $this->verifyBody = ['status' => true, 'data' => $data];
        $this->verifyStatus = $status;
    }

    public function test_when_the_gateway_cannot_be_asked_the_webhook_answers_with_an_error_so_it_is_sent_again(): void
    {
        $deposit = $this->startDeposit();
        $this->verifyBody = ['status' => false, 'message' => 'down'];
        $this->verifyStatus = 503;

        $this->webhook($deposit)->assertStatus(500);

        $this->assertSame('failed', WebhookEvent::first()->status);
        $this->assertEquals(0, Wallet::where('user_id', $deposit->user_id)->value('wallet_balance') ?? 0);

        // Paystack sends it again once we can ask; now it credits, once.
        $this->verifyReturns(['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 1]);
        $this->webhook($deposit)->assertOk();
        $this->webhook($deposit)->assertOk();

        $this->assertEquals(5000, Wallet::where('user_id', $deposit->user_id)->value('wallet_balance'));
        $this->assertSame(1, WebhookEvent::count());
        $event = WebhookEvent::first();
        $this->assertSame('processed', $event->status);
        $this->assertSame(3, $event->deliveries);
    }

    public function test_an_event_for_something_that_is_not_our_top_up_is_acknowledged_not_retried(): void
    {
        $payload = json_encode(['event' => 'charge.success', 'data' => ['id' => 5, 'reference' => 'someone-elses-ref']]);

        $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'HTTP_x-paystack-signature' => hash_hmac('sha512', $payload, 'sk_test_paystack'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();
    }

    public function test_a_top_up_still_processing_is_not_marked_failed(): void
    {
        $deposit = $this->startDeposit();
        $this->verifyReturns(['status' => 'ongoing', 'amount' => 500000, 'currency' => 'NGN', 'id' => 1]);

        app(DepositService::class)->confirm('paystack', $deposit->reference);

        $this->assertSame('pending', $deposit->refresh()->status);
        $this->assertSame(1, $deposit->check_count);
    }

    public function test_a_definite_failure_is_marked_failed(): void
    {
        $deposit = $this->startDeposit();
        $this->verifyReturns(['status' => 'failed', 'amount' => 500000, 'currency' => 'NGN', 'id' => 1]);

        app(DepositService::class)->confirm('paystack', $deposit->reference);

        $this->assertSame('failed', $deposit->refresh()->status);
    }

    public function test_a_success_with_no_amount_or_currency_is_an_error_not_zero_naira(): void
    {
        $deposit = $this->startDeposit();

        foreach ([['status' => 'success', 'currency' => 'NGN', 'id' => 1], ['status' => 'success', 'amount' => 500000, 'id' => 1]] as $data) {
            $this->verifyReturns($data);

            try {
                app(DepositService::class)->confirm('paystack', $deposit->reference);
                $this->fail('credited without an amount or currency');
            } catch (\App\Exceptions\PaymentGatewayException $e) {
                $this->assertStringContainsString('left out the amount or currency', $e->getMessage());
            }
        }

        $this->assertSame('pending', $deposit->refresh()->status);
        $this->assertEquals(0, Wallet::where('user_id', $deposit->user_id)->value('wallet_balance') ?? 0);
    }

    public function test_the_scheduled_check_credits_a_top_up_whose_webhook_never_came(): void
    {
        $deposit = $this->startDeposit();
        $this->verifyReturns(['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 1]);

        $this->travel(10)->minutes();
        $this->assertSame(1, app(DepositService::class)->recheckPending());

        $this->assertSame('successful', $deposit->refresh()->status);
        $this->assertEquals(5000, Wallet::where('user_id', $deposit->user_id)->value('wallet_balance'));
    }

    public function test_the_scheduled_check_gives_up_after_two_days_and_tells_staff(): void
    {
        Notification::fake();
        $deposit = $this->startDeposit();
        $this->verifyReturns(['status' => 'pending', 'amount' => 500000, 'currency' => 'NGN', 'id' => 1]);

        $this->travel(DepositService::RECHECK_DAYS * 24 * 60 + 60)->minutes();

        $this->assertSame(0, app(DepositService::class)->recheckPending());
        Notification::assertSentTo(new AnonymousNotifiable, SystemProblemAdminAlert::class);
    }

    public function test_a_wrong_signature_alerts_staff(): void
    {
        Notification::fake();

        $this->call('POST', '/api/webhooks/paystack', [], [], [], ['HTTP_x-paystack-signature' => 'bad', 'CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401);

        Notification::assertSentTo(new AnonymousNotifiable, SystemProblemAdminAlert::class);
    }
}
