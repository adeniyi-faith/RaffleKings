<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class RewardsControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_the_spin_odds_are_public(): void
    {
        $response = $this->getJson('/api/rewards/spin/odds');

        $response->assertOk();
        $response->assertJson(['cost' => 50]);
        $response->assertJsonCount(4, 'odds');
    }

    public function test_an_authenticated_user_can_see_their_points_balance(): void
    {
        $this->actingAsWordPressUser();

        $this->getJson('/api/rewards/state')->assertOk()->assertJson(['points' => 0]);
    }

    public function test_an_authenticated_user_can_claim_their_daily_reward(): void
    {
        $this->actingAsWordPressUser();

        $response = $this->postJson('/api/rewards/daily-claim');

        $response->assertOk();
        $response->assertJson(['points_added' => 50, 'new_streak' => 1]);
    }

    public function test_claiming_daily_twice_returns_409(): void
    {
        $this->actingAsWordPressUser();
        $this->postJson('/api/rewards/daily-claim')->assertOk();

        $this->postJson('/api/rewards/daily-claim')->assertStatus(409);
    }

    public function test_an_authenticated_user_can_claim_a_task(): void
    {
        $this->actingAsWordPressUser();

        $response = $this->postJson('/api/rewards/tasks/join_community/claim');

        $response->assertOk();
        $response->assertJson(['task_id' => 'join_community', 'points_added' => 1300]);
    }

    public function test_an_unknown_task_returns_422(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/rewards/tasks/not_real/claim')->assertStatus(422);
    }

    public function test_spinning_without_enough_points_returns_402(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/rewards/spin')->assertStatus(402);
    }

    public function test_an_authenticated_user_can_spin_once_they_have_enough_points(): void
    {
        $this->actingAsWordPressUser();
        $this->postJson('/api/rewards/tasks/join_community/claim')->assertOk(); // +1300 points

        $response = $this->postJson('/api/rewards/spin');

        $response->assertOk();
        $response->assertJsonStructure(['payout', 'outcome', 'visual_index', 'new_balance']);
    }

    public function test_redeeming_below_the_minimum_returns_422(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/rewards/redeem')->assertStatus(422);
    }

    public function test_an_authenticated_user_can_redeem_points_for_wallet_cash(): void
    {
        $this->actingAsWordPressUser();
        $this->postJson('/api/rewards/tasks/join_community/claim')->assertOk(); // +1300 points

        $response = $this->postJson('/api/rewards/redeem');

        $response->assertOk();
        $response->assertJson(['redeemed_points' => 1300, 'wallet_added' => 130]);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/rewards/state')->assertUnauthorized();
        $this->postJson('/api/rewards/daily-claim')->assertUnauthorized();
        $this->postJson('/api/rewards/spin')->assertUnauthorized();
    }
}
