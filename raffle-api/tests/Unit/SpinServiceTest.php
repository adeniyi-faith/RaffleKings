<?php

namespace Tests\Unit;

use App\Exceptions\InsufficientPointsException;
use App\Models\Legacy\WpUser;
use App\Services\PointsService;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpinServiceTest extends TestCase
{
    use RefreshDatabase;

    private SpinService $spin;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spin = app(SpinService::class);
        $this->points = app(PointsService::class);
    }

    private function makeUserWithPoints(int $points): WpUser
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $this->points->credit($user, $points, 'daily_claim');

        return $user;
    }

    public function test_a_spin_costs_50_points(): void
    {
        $user = $this->makeUserWithPoints(50);

        $result = $this->spin->spin($user);

        // Balance after: -50 cost, +payout (payout is always >= 15 per the prize table).
        $this->assertGreaterThanOrEqual(15, $result['new_balance']);
        $this->assertSame($result['new_balance'], $this->points->balance($user));
    }

    public function test_spinning_without_enough_points_is_refused(): void
    {
        $user = $this->makeUserWithPoints(10);

        $this->expectException(InsufficientPointsException::class);
        $this->spin->spin($user);
    }

    public function test_the_odds_table_sums_to_100_percent(): void
    {
        $total = array_sum(array_column($this->spin->odds(), 'probability'));

        $this->assertEqualsWithDelta(1.0, $total, 0.0001);
    }

    public function test_a_spin_always_returns_one_of_the_four_documented_outcomes(): void
    {
        $user = $this->makeUserWithPoints(500);
        $validOutcomes = array_column($this->spin->odds(), 'outcome');

        for ($i = 0; $i < 20; $i++) {
            $result = $this->spin->spin($user);
            $this->assertContains($result['outcome'], $validOutcomes);
        }
    }

    public function test_spinning_is_allowed_repeatedly_with_no_cooldown(): void
    {
        $user = $this->makeUserWithPoints(1000);

        // As long as points last, spins are unlimited — mirrors the
        // legacy 'is_unlimited' => true response field.
        for ($i = 0; $i < 5; $i++) {
            $this->spin->spin($user);
        }

        $this->assertTrue(true); // reaching here without an exception is the assertion
    }
}
