<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — proves the real gap closed in
 * this pass: an admin flipping rk_is_banned/rk_ban_withdraw from the
 * new console (UserManagementService) now actually blocks a withdrawal
 * submitted through the new Laravel frontend, which nothing checked
 * before.
 */
class WithdrawalRestrictionTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function fundedUserWithBankAccount(): array
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);

        return [$user, $account];
    }

    public function test_a_globally_banned_user_cannot_withdraw(): void
    {
        [$user, $account] = $this->fundedUserWithBankAccount();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        $response = $this->postJson('/api/withdrawals', [
            'amount' => 3000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true,
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Account suspended. Contact support.']);
    }

    public function test_a_user_with_withdrawals_specifically_blocked_cannot_withdraw(): void
    {
        [$user, $account] = $this->fundedUserWithBankAccount();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_ban_withdraw', 'meta_value' => '1']);

        $response = $this->postJson('/api/withdrawals', [
            'amount' => 3000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true,
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Withdrawals are currently disabled for your account.']);
    }

    public function test_an_unrestricted_user_can_still_withdraw(): void
    {
        [, $account] = $this->fundedUserWithBankAccount();

        $this->postJson('/api/withdrawals', [
            'amount' => 3000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true,
        ])->assertCreated();
    }

    public function test_an_expired_ban_is_lifted_automatically_and_the_withdrawal_succeeds(): void
    {
        [$user, $account] = $this->fundedUserWithBankAccount();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_ban_expiry', 'meta_value' => now()->subDay()->toDateString()]);

        $this->postJson('/api/withdrawals', [
            'amount' => 3000, 'bank_account_id' => $account->id, 'authorize_verification_fee' => true,
        ])->assertCreated();

        $this->assertDatabaseMissing('wp_usermeta', ['user_id' => $user->ID, 'meta_key' => 'rk_is_banned']);
    }
}
