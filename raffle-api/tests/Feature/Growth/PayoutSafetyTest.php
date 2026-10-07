<?php

namespace Tests\Feature\Growth;

use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\PayoutAttempt;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Notifications\PayoutProblemAdminAlert;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\HoldsWithdrawalMoney;
use Tests\TestCase;

/**
 * Money-safety audit (E5, F3, G2, E9): a payout can never be paid twice.
 */
class PayoutSafetyTest extends TestCase
{
    use ActsAsAdministrator, HoldsWithdrawalMoney, RefreshDatabase;

    private array $transfer = ['status' => 200, 'body' => ['status' => true, 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_1']]];

    /** @var array<string, array{status: int, body: array}> reference => Paystack's answer */
    private array $verify = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_abc', 'features.auto_payouts' => true, 'withdrawals.auto_payout_max' => 50000]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/transferrecipient') {
                return Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_1']]);
            }

            if ($path === '/transfer') {
                return Http::response($this->transfer['body'], $this->transfer['status']);
            }

            if (str_starts_with($path, '/transfer/verify/')) {
                $reference = substr($path, strlen('/transfer/verify/'));
                $answer = $this->verify[$reference] ?? ['status' => 404, 'body' => ['status' => false, 'message' => 'Transfer not found']];

                return Http::response($answer['body'], $answer['status']);
            }

            return Http::response(['status' => false], 404);
        });
    }

    private function withdrawal(): WithdrawalRequest
    {
        $user = WpUser::create(['user_login' => 'c'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Customer']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        $bank = BankAccount::create([
            'user_id' => $user->ID, 'bank_name' => 'GTBank', 'bank_code' => '058', 'account_number' => '0123456789',
            'account_name' => 'JANE DOE', 'is_primary' => true, 'name_verified_at' => now(),
        ]);

        return $this->holdMoneyFor(WithdrawalRequest::create([
            'user_id' => $user->ID, 'bank_account_id' => $bank->id, 'requested_amount' => 5000,
            'fee_amount' => 0, 'amount_to_send' => 5000, 'status' => 'pending',
        ]));
    }

    private function answer(string $status, ?string $reason = null): array
    {
        return ['status' => 200, 'body' => ['status' => true, 'data' => ['status' => $status, 'reason' => $reason]]];
    }

    public function test_a_paystack_server_error_is_not_treated_as_a_failed_payout(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $this->transfer = ['status' => 503, 'body' => ['status' => false, 'message' => 'Service unavailable']];

        $message = app(PayoutService::class)->send($admin, $withdrawal);

        $this->assertStringContainsString('check with Paystack', $message);
        $this->assertSame('sending', $withdrawal->refresh()->payout_status);
        $this->assertSame('checking', PayoutAttempt::where('withdrawal_request_id', $withdrawal->id)->value('status'));
    }

    public function test_paystack_saying_too_busy_is_also_unclear_not_failed(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $this->transfer = ['status' => 429, 'body' => ['status' => false, 'message' => 'Too many requests']];

        app(PayoutService::class)->send($admin, $withdrawal);

        $this->assertSame('sending', $withdrawal->refresh()->payout_status);
    }

    public function test_a_clear_refusal_from_paystack_is_a_definite_failure_and_can_be_retried(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $this->transfer = ['status' => 400, 'body' => ['status' => false, 'message' => 'Invalid recipient']];

        try {
            app(PayoutService::class)->send($admin, $withdrawal);
            $this->fail('should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid recipient', $e->getMessage());
        }

        $this->assertSame('failed', $withdrawal->refresh()->payout_status);
        $this->assertSame('failed', PayoutAttempt::where('withdrawal_request_id', $withdrawal->id)->value('status'));
    }

    public function test_sending_again_is_refused_while_the_earlier_attempt_is_still_travelling(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $service = app(PayoutService::class);
        $service->send($admin, $withdrawal);
        $first = $withdrawal->refresh()->payout_reference;

        // Staff-side state says "failed", but Paystack still has the first one queued.
        $withdrawal->update(['payout_status' => 'failed']);
        $this->verify[$first] = $this->answer('pending');

        try {
            $service->send($admin, $withdrawal);
            $this->fail('a second transfer was allowed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still pending', $e->getMessage());
        }

        $this->assertSame(1, PayoutAttempt::where('withdrawal_request_id', $withdrawal->id)->count());
    }

    public function test_if_the_earlier_attempt_actually_succeeded_sending_again_just_marks_it_paid(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $service = app(PayoutService::class);
        $service->send($admin, $withdrawal);
        $first = $withdrawal->refresh()->payout_reference;
        $withdrawal->update(['payout_status' => 'failed']);
        $this->verify[$first] = $this->answer('success');

        try {
            $service->send($admin, $withdrawal);
            $this->fail('should not send again');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('actually went through', $e->getMessage());
        }

        $this->assertSame('paid', $withdrawal->refresh()->status);
        $this->assertSame(1, PayoutAttempt::where('withdrawal_request_id', $withdrawal->id)->count());
    }

    public function test_a_late_success_for_an_older_attempt_after_a_resend_raises_a_double_payment_alarm(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $service = app(PayoutService::class);

        $service->send($admin, $withdrawal);
        $first = $withdrawal->refresh()->payout_reference;
        $this->verify[$first] = $this->answer('failed', 'bank hiccup');
        $service->handleWebhook($first);

        $service->send($admin, $withdrawal);
        $second = $withdrawal->refresh()->payout_reference;
        $this->assertNotSame($first, $second);

        // The second one lands and the withdrawal is paid.
        $this->verify[$second] = $this->answer('success');
        $service->handleWebhook($second);
        $this->assertSame('paid', $withdrawal->refresh()->status);

        // Then Paystack says the FIRST one went through as well.
        $this->verify[$first] = $this->answer('success');
        $service->handleWebhook($first);

        Notification::assertSentTo(new \Illuminate\Notifications\AnonymousNotifiable, PayoutProblemAdminAlert::class, fn ($n) => true);
        $this->assertSame('success', PayoutAttempt::where('reference', $first)->value('status'));
    }

    public function test_an_older_attempt_that_succeeds_late_completes_a_withdrawal_that_was_not_yet_paid(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $service = app(PayoutService::class);

        $service->send($admin, $withdrawal);
        $first = $withdrawal->refresh()->payout_reference;
        $this->verify[$first] = $this->answer('failed');
        $service->handleWebhook($first);
        $service->send($admin, $withdrawal);

        $this->verify[$first] = $this->answer('success');
        $service->handleWebhook($first);

        $this->assertSame('paid', $withdrawal->refresh()->status);
        $this->assertSame($first, $withdrawal->payout_reference);
    }

    public function test_paying_the_same_withdrawal_twice_only_ever_moves_the_held_money_once(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        $service = app(\App\Services\WithdrawalService::class);

        $service->markPaid($admin, $withdrawal);

        try {
            $service->markPaid($admin, $withdrawal->refresh());
            $this->fail('paid twice');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0.0, (float) Wallet::where('user_id', $withdrawal->user_id)->value('held_balance'));
    }
}
