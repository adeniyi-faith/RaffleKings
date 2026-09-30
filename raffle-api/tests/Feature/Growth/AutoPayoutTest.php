<?php

namespace Tests\Feature\Growth;

use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\PayoutService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

class AutoPayoutTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    /** What Paystack answers to POST /transfer. */
    private array $transferReply = ['status' => true, 'message' => 'Transfer has been queued', 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_1']];

    /** What Paystack answers to GET /transfer/verify. */
    private array $verifyReply = ['status' => true, 'data' => ['status' => 'success']];

    private int $verifyStatus = 200;

    private bool $transferTimesOut = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_abc', 'features.auto_payouts' => true, 'withdrawals.auto_payout_max' => 50000]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_123']]),
                $path === '/transfer' => $this->transferTimesOut
                    ? throw new \Illuminate\Http\Client\ConnectionException('timed out')
                    : Http::response($this->transferReply),
                str_starts_with($path, '/transfer/verify/') => Http::response($this->verifyReply, $this->verifyStatus),
                $path === '/balance' => Http::response(['status' => true, 'data' => [['currency' => 'NGN', 'balance' => 100000000]]]),
                default => Http::response(['status' => false], 404),
            };
        });
    }

    private function withdrawal(array $account = [], float $amount = 5000): WithdrawalRequest
    {
        $user = WpUser::create(['user_login' => 'cust_'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Customer']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        $bank = BankAccount::create($account + [
            'user_id' => $user->ID, 'bank_name' => 'Guaranty Trust Bank', 'bank_code' => '058', 'account_number' => '0123456789',
            'account_name' => 'JANE DOE', 'is_primary' => true, 'name_verified_at' => now(),
        ]);

        return WithdrawalRequest::create([
            'user_id' => $user->ID, 'bank_account_id' => $bank->id, 'requested_amount' => $amount,
            'fee_amount' => 0, 'amount_to_send' => $amount, 'status' => 'pending',
        ]);
    }

    public function test_sending_asks_paystack_and_waits_for_the_bank_before_marking_paid(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();

        $message = app(PayoutService::class)->send($admin, $withdrawal);

        $withdrawal->refresh();
        $this->assertStringContainsString('Paystack is sending', $message);
        $this->assertSame('pending', $withdrawal->status);
        $this->assertSame('sending', $withdrawal->payout_status);
        $this->assertStringStartsWith("rkwd-{$withdrawal->id}-1-", $withdrawal->payout_reference);
        $this->assertSame('RCP_123', $withdrawal->bankAccount->paystack_recipient_code);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transfer') && $r['amount'] === 500000 && $r['recipient'] === 'RCP_123');

        // Paystack's webhook: success.
        app(PayoutService::class)->handleWebhook($withdrawal->payout_reference);

        $withdrawal->refresh();
        $this->assertSame('paid', $withdrawal->status);
        $this->assertSame('success', $withdrawal->payout_status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'withdrawal.paid', 'subject_id' => $withdrawal->id]);
    }

    public function test_while_sending_it_cannot_be_paid_by_hand_rejected_or_sent_twice(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        app(PayoutService::class)->send($admin, $withdrawal);
        $withdrawal->refresh();

        foreach ([
            fn () => app(WithdrawalService::class)->markPaid($admin, $withdrawal),
            fn () => app(WithdrawalService::class)->reject($admin, $withdrawal, 'no'),
            fn () => app(PayoutService::class)->send($admin, $withdrawal),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Should have been refused.');
            } catch (RuntimeException) {
            }
        }

        $this->assertSame(0.0, (float) Wallet::where('user_id', $withdrawal->user_id)->value('earnings_balance'));
        Http::assertSentCount(2); // one recipient, one transfer
    }

    public function test_a_failed_transfer_goes_back_to_the_queue_and_can_be_sent_again(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        app(PayoutService::class)->send($admin, $withdrawal);

        $this->verifyReply = ['status' => true, 'data' => ['status' => 'failed', 'reason' => 'Account closed']];
        app(PayoutService::class)->handleWebhook($withdrawal->refresh()->payout_reference);

        $withdrawal->refresh();
        $this->assertSame('pending', $withdrawal->status);
        $this->assertSame('failed', $withdrawal->payout_status);
        $this->assertStringContainsString('Account closed', $withdrawal->payout_error);

        $firstReference = $withdrawal->payout_reference;
        app(PayoutService::class)->send($admin, $withdrawal);
        $this->assertNotSame($firstReference, $withdrawal->refresh()->payout_reference);
        $this->assertSame(2, $withdrawal->payout_attempts);
    }

    public function test_an_immediate_success_is_marked_paid_at_once(): void
    {
        $this->transferReply['data']['status'] = 'success';
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();

        $message = app(PayoutService::class)->send($admin, $withdrawal);

        $this->assertStringStartsWith('Sent.', $message);
        $this->assertSame('paid', $withdrawal->refresh()->status);
    }

    public function test_paystack_asking_for_an_otp_explains_how_to_switch_it_off(): void
    {
        $this->transferReply['data']['status'] = 'otp';
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();

        app(PayoutService::class)->send($admin, $withdrawal);

        $this->assertSame('failed', $withdrawal->refresh()->payout_status);
        $this->assertStringContainsString('Confirm transfers before sending', $withdrawal->payout_error);
    }

    public function test_a_timeout_stays_sending_until_the_check_finds_out(): void
    {
        $this->transferTimesOut = true;
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();

        $message = app(PayoutService::class)->send($admin, $withdrawal);
        $this->assertStringContainsString('check with Paystack', $message);
        $this->assertSame('sending', $withdrawal->refresh()->payout_status);

        // Paystack never got it: after the give-up time it's safe to send again.
        $this->verifyStatus = 404;
        $this->verifyReply = ['status' => false, 'message' => 'Transfer not found'];
        $this->travel(PayoutService::GIVE_UP_AFTER_MINUTES + 1)->minutes();
        app(PayoutService::class)->checkStuck();

        $this->assertSame('failed', $withdrawal->refresh()->payout_status);
        $this->assertSame('pending', $withdrawal->status);
    }

    public function test_a_timeout_that_did_reach_paystack_is_completed_by_the_check(): void
    {
        $this->transferTimesOut = true;
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        app(PayoutService::class)->send($admin, $withdrawal);

        $this->travel(PayoutService::CHECK_AFTER_MINUTES + 1)->minutes();
        app(PayoutService::class)->checkStuck();

        $this->assertSame('paid', $withdrawal->refresh()->status);
    }

    public function test_unchecked_accounts_big_amounts_and_the_switch_block_sending(): void
    {
        $admin = $this->actingAsAdministrator();
        $service = app(PayoutService::class);

        $old = $this->withdrawal(['name_verified_at' => null, 'bank_code' => null]);
        $this->assertStringContainsString('pay by hand', $service->blocker($old));

        $big = $this->withdrawal(amount: 60000);
        $this->assertStringContainsString('Above the automatic limit of ₦50,000', $service->blocker($big));

        config(['features.auto_payouts' => false]);
        $this->assertSame('Automatic payouts are switched off.', $service->blocker($this->withdrawal()));

        $this->expectException(RuntimeException::class);
        $service->send($admin, $old);
    }

    public function test_the_webhook_route_passes_transfer_events_to_payouts(): void
    {
        $admin = $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();
        app(PayoutService::class)->send($admin, $withdrawal);

        $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => $withdrawal->refresh()->payout_reference]]);

        $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_abc'),
        ], $body)->assertOk();

        $this->assertSame('paid', $withdrawal->refresh()->status);
    }

    public function test_the_queue_offers_send_with_paystack_only_when_switched_on(): void
    {
        $this->actingAsAdministrator();
        $withdrawal = $this->withdrawal();

        Livewire::test(ListWithdrawalRequests::class)
            ->assertTableActionVisible('sendWithPaystack', $withdrawal)
            ->callTableAction('sendWithPaystack', $withdrawal);

        $this->assertSame('sending', $withdrawal->refresh()->payout_status);

        Livewire::test(ListWithdrawalRequests::class)
            ->assertTableActionHidden('markPaid', $withdrawal)
            ->assertTableActionVisible('checkPaystack', $withdrawal);

        config(['features.auto_payouts' => false]);
        $other = $this->withdrawal();
        Livewire::test(ListWithdrawalRequests::class)
            ->assertTableActionHidden('sendWithPaystack', $other)
            ->assertTableActionVisible('markPaid', $other);
    }
}
