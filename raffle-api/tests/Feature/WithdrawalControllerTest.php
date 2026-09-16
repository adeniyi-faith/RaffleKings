<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class WithdrawalControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_an_authenticated_user_can_see_their_withdrawal_requirements(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        $response = $this->getJson('/api/withdrawals/requirements');

        $response->assertOk();
        $response->assertJson(['minimum_amount' => 2000, 'requires_verification_fee' => true, 'verification_fee' => 1000]);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/withdrawals/requirements')->assertUnauthorized();
    }

    public function test_a_withdrawal_request_needing_authorization_returns_403(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);

        $response = $this->postJson('/api/withdrawals', ['amount' => 3000, 'bank_account_id' => $account->id]);

        $response->assertStatus(403);
        $response->assertJson(['requires_verification_fee' => true, 'verification_fee' => 1000]);
    }

    public function test_an_authorized_withdrawal_succeeds(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);

        $response = $this->postJson('/api/withdrawals', [
            'amount' => 3000,
            'bank_account_id' => $account->id,
            'authorize_verification_fee' => true,
        ]);

        $response->assertCreated();
        $response->assertJson(['requested_amount' => 3000, 'fee_amount' => 1000, 'status' => 'pending']);
    }

    public function test_below_minimum_returns_422(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);

        $this->postJson('/api/withdrawals', ['amount' => 500, 'bank_account_id' => $account->id])->assertStatus(422);
    }
}
