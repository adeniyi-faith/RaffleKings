<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\WithdrawalRequest;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\MakesBankAccounts;
use Tests\TestCase;

/** One tap is one request: a double click or a retry never moves money twice (money-safety audit D1, D3). */
class RepeatClickSafetyTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, MakesBankAccounts, RefreshDatabase;

    private function withdrawer(): array
    {
        $user = $this->actingAsWordPressUser();
        app(WalletLedgerService::class)->credit($user->ID, 'earnings', 10000, 'raffle_win', 'seed:'.$user->ID, 'prizes');
        $account = $this->oldBankAccount(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);

        return [$user, $account];
    }

    public function test_the_same_withdrawal_tap_sent_twice_makes_one_request_and_holds_the_money_once(): void
    {
        [$user, $account] = $this->withdrawer();
        $body = ['amount' => 3000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true, 'idempotency_key' => 'tap-0001-abcdef'];

        $first = $this->postJson('/api/withdrawals', $body)->assertCreated()->json('id');
        $second = $this->postJson('/api/withdrawals', $body)->assertStatus(201)->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, WithdrawalRequest::count());
        // ₦10,000 less the ₦3,000 and the ₦1,000 fee, taken once (in kobo).
        $this->assertSame(600000, app(WalletLedgerService::class)->balances($user->ID)['earnings']);
    }

    public function test_an_older_app_with_no_key_still_cannot_send_the_same_request_twice_in_a_minute(): void
    {
        [, $account] = $this->withdrawer();
        $body = ['amount' => 3000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true];

        $this->postJson('/api/withdrawals', $body)->assertCreated();
        $this->postJson('/api/withdrawals', $body)->assertStatus(201);

        $this->assertSame(1, WithdrawalRequest::count());
    }

    public function test_a_changed_amount_with_the_same_key_is_a_new_request(): void
    {
        [, $account] = $this->withdrawer();

        $this->postJson('/api/withdrawals', ['amount' => 2000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true, 'idempotency_key' => 'tap-0002-abcdef'])->assertCreated();
        $this->postJson('/api/withdrawals', ['amount' => 3000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true, 'idempotency_key' => 'tap-0002-abcdef'])->assertCreated();

        $this->assertSame(2, WithdrawalRequest::count());
    }

    public function test_a_top_up_tap_sent_twice_starts_one_top_up_and_a_different_amount_is_refused(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_abc', 'payments.gateway_order' => ['paystack']]);
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://pay.test/x', 'reference' => 'r']])]);
        $this->actingAsWordPressUser();

        $first = $this->postJson('/api/deposits', ['amount' => 5000, 'idempotency_key' => 'topup-0001-abcdef']);
        $first->assertCreated();
        $second = $this->postJson('/api/deposits', ['amount' => 5000, 'idempotency_key' => 'topup-0001-abcdef']);

        $second->assertStatus(201);
        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertSame(1, Deposit::count());

        $this->postJson('/api/deposits', ['amount' => 9000, 'idempotency_key' => 'topup-0001-abcdef'])->assertStatus(409);
        $this->assertSame(1, Deposit::count());
    }
}
