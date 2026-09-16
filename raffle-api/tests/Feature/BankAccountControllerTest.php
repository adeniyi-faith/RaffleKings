<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class BankAccountControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_a_user_can_add_and_list_their_bank_accounts(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts', [
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'Jane Doe',
        ])->assertCreated()->assertJson(['bank_name' => 'GTBank', 'is_primary' => true]);

        $response = $this->getJson('/api/bank-accounts');
        $response->assertOk();
        $response->assertJsonCount(1, 'accounts');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/bank-accounts', [
            'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Jane Doe',
        ])->assertUnauthorized();
    }

    public function test_invalid_input_is_rejected_with_a_422(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts', [
            'bank_name' => 'GTBank', 'account_number' => '12', 'account_name' => 'Jane Doe',
        ])->assertStatus(422);
    }

    public function test_a_third_account_is_rejected_with_a_409(): void
    {
        $this->actingAsWordPressUser();
        $this->postJson('/api/bank-accounts', ['bank_name' => 'Access', 'account_number' => '1111111111', 'account_name' => 'A B'])->assertCreated();
        $this->postJson('/api/bank-accounts', ['bank_name' => 'Kuda', 'account_number' => '2222222222', 'account_name' => 'C D'])->assertCreated();

        $this->postJson('/api/bank-accounts', ['bank_name' => 'Zenith', 'account_number' => '3333333333', 'account_name' => 'E F'])
            ->assertStatus(409);
    }

    public function test_a_user_can_change_which_account_is_primary(): void
    {
        $this->actingAsWordPressUser();
        $first = $this->postJson('/api/bank-accounts', ['bank_name' => 'Access', 'account_number' => '1111111111', 'account_name' => 'A B'])->json();
        $second = $this->postJson('/api/bank-accounts', ['bank_name' => 'Kuda', 'account_number' => '2222222222', 'account_name' => 'C D'])->json();

        $this->patchJson("/api/bank-accounts/{$second['id']}/primary")
            ->assertOk()
            ->assertJson(['id' => $second['id'], 'is_primary' => true]);

        $this->assertFalse(BankAccount::find($first['id'])->is_primary);
    }

    public function test_a_user_can_delete_a_bank_account(): void
    {
        $this->actingAsWordPressUser();
        $account = $this->postJson('/api/bank-accounts', ['bank_name' => 'Access', 'account_number' => '1111111111', 'account_name' => 'A B'])->json();

        $this->deleteJson("/api/bank-accounts/{$account['id']}")->assertNoContent();

        $this->assertSame(0, BankAccount::count());
    }

    public function test_a_user_cannot_modify_another_users_bank_account(): void
    {
        $this->actingAsWordPressUser();
        $account = $this->postJson('/api/bank-accounts', ['bank_name' => 'Access', 'account_number' => '1111111111', 'account_name' => 'A B'])->json();

        // Switch to a second authenticated user.
        $this->actingAsWordPressUser();

        $this->deleteJson("/api/bank-accounts/{$account['id']}")->assertNotFound();
    }
}
