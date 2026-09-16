<?php

namespace Tests\Feature;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class WalletControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_it_returns_the_authenticated_users_wallet_balance(): void
    {
        $user = $this->actingAsWordPressUser();

        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1500, 'earnings_balance' => 250]);

        $response = $this->getJson('/api/wallet');

        $response->assertOk()->assertJson(['wallet_balance' => 1500, 'earnings_balance' => 250]);
    }

    public function test_a_user_with_no_wallet_row_yet_has_a_zero_balance_not_an_error(): void
    {
        $this->actingAsWordPressUser();

        $this->getJson('/api/wallet')->assertOk()->assertJson(['wallet_balance' => 0, 'earnings_balance' => 0]);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/wallet')->assertUnauthorized();
    }
}
