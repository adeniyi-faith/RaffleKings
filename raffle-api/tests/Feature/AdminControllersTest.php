<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class AdminControllersTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function actingAsAdministrator(): WpUser
    {
        $user = $this->actingAsWordPressUser();

        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        return $user;
    }

    public function test_a_regular_user_cannot_reach_any_admin_endpoint(): void
    {
        $this->actingAsWordPressUser();

        $this->getJson('/api/admin/withdrawals')->assertStatus(403);
        $this->getJson('/api/admin/audit-logs')->assertStatus(403);
    }

    public function test_an_admin_can_list_and_pay_a_pending_withdrawal(): void
    {
        $this->actingAsAdministrator();
        $user = WpUser::create(['user_login' => 'payee', 'user_pass' => 'x', 'user_email' => 'payee@example.com']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Payee', 'is_primary' => true]);
        app(WithdrawalService::class)->request($user, 3000, $account->id, authorizeVerificationFee: true);

        $list = $this->getJson('/api/admin/withdrawals');
        $list->assertOk();
        $list->assertJsonCount(1, 'withdrawals');

        $withdrawalId = $list->json('withdrawals.0.id');
        $this->postJson("/api/admin/withdrawals/{$withdrawalId}/mark-paid")
            ->assertOk()
            ->assertJson(['status' => 'paid']);
    }

    public function test_an_admin_can_credit_a_winner(): void
    {
        $this->actingAsAdministrator();
        $winnerUser = WpUser::create(['user_login' => 'w1', 'user_pass' => 'x', 'user_email' => 'w1@example.com']);
        $winner = RaffleWinner::create([
            'raffle_id' => 1, 'user_id' => $winnerUser->ID, 'ticket_number' => 3,
            'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 2000,
        ]);

        $response = $this->postJson("/api/admin/winners/{$winner->id}/credit");

        $response->assertOk();
        $response->assertJson(['is_credited' => true]);
        $this->assertEquals(2000, Wallet::where('user_id', $winnerUser->ID)->value('earnings_balance'));
    }

    public function test_an_admin_can_toggle_winner_visibility(): void
    {
        $this->actingAsAdministrator();
        $winnerUser = WpUser::create(['user_login' => 'w2', 'user_pass' => 'x', 'user_email' => 'w2@example.com']);
        $winner = RaffleWinner::create([
            'raffle_id' => 1, 'user_id' => $winnerUser->ID, 'ticket_number' => 3,
            'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 2000,
        ]);

        $this->patchJson("/api/admin/winners/{$winner->id}/visibility", ['is_visible' => true])
            ->assertOk()
            ->assertJson(['is_visible' => true]);
    }

    public function test_the_audit_log_records_admin_actions_and_is_readable(): void
    {
        $this->actingAsAdministrator();
        $winnerUser = WpUser::create(['user_login' => 'w3', 'user_pass' => 'x', 'user_email' => 'w3@example.com']);
        $winner = RaffleWinner::create([
            'raffle_id' => 1, 'user_id' => $winnerUser->ID, 'ticket_number' => 3,
            'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 2000,
        ]);
        $this->postJson("/api/admin/winners/{$winner->id}/credit")->assertOk();

        $response = $this->getJson('/api/admin/audit-logs');

        $response->assertOk();
        $response->assertJsonFragment(['action' => 'winner.credited']);
    }
}
