<?php

namespace Tests\Unit;

use App\Exceptions\MinimumRedemptionNotMetException;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\PointRedemptionService;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointRedemptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PointRedemptionService $redemption;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redemption = app(PointRedemptionService::class);
        $this->points = app(PointsService::class);
    }

    private function makeUserWithPoints(int $points): WpUser
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $this->points->credit($user, $points, 'daily_claim');

        return $user;
    }

    public function test_it_converts_the_entire_balance_at_10_points_per_naira(): void
    {
        $user = $this->makeUserWithPoints(250);

        $result = $this->redemption->redeem($user);

        $this->assertSame(250, $result['redeemed_points']);
        $this->assertEquals(25, $result['wallet_added']);
        $this->assertSame(0, $this->points->balance($user));
        $this->assertEquals(25, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_a_partial_naira_remainder_is_rounded_down(): void
    {
        $user = $this->makeUserWithPoints(105); // 10.5 naira

        $result = $this->redemption->redeem($user);

        $this->assertEquals(10, $result['wallet_added']);
    }

    public function test_below_the_minimum_is_refused_and_nothing_changes(): void
    {
        $user = $this->makeUserWithPoints(99);

        try {
            $this->redemption->redeem($user);
            $this->fail('Expected MinimumRedemptionNotMetException was not thrown.');
        } catch (MinimumRedemptionNotMetException $e) {
            $this->assertSame(100, $e->minimumPoints);
            $this->assertSame(99, $e->currentPoints);
        }

        $this->assertSame(99, $this->points->balance($user));
    }

    public function test_redeeming_adds_to_an_existing_wallet_balance_rather_than_overwriting_it(): void
    {
        $user = $this->makeUserWithPoints(200);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 500, 'earnings_balance' => 0]);

        $this->redemption->redeem($user);

        $this->assertEquals(520, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }
}
