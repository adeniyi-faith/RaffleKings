<?php

namespace Tests\Unit;

use App\Exceptions\AlreadyClaimedTodayException;
use App\Models\Legacy\WpUser;
use App\Services\DailyClaimService;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyClaimService $service;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DailyClaimService::class);
        $this->points = app(PointsService::class);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_the_first_ever_claim_is_day_1_for_50_points(): void
    {
        $user = $this->makeUser();

        $result = $this->service->claim($user);

        $this->assertSame(1, $result['new_streak']);
        $this->assertSame(50, $result['points_added']);
        $this->assertSame(50, $this->points->balance($user));
    }

    public function test_claiming_twice_the_same_day_is_refused(): void
    {
        $user = $this->makeUser();
        $this->service->claim($user);

        $this->expectException(AlreadyClaimedTodayException::class);
        $this->service->claim($user);
    }

    public function test_claiming_the_next_consecutive_day_advances_the_streak(): void
    {
        $user = $this->makeUser();
        $day1 = Carbon::parse('2026-01-01 09:00:00');
        $this->service->claim($user, $day1);

        $result = $this->service->claim($user, $day1->copy()->addDay());

        $this->assertSame(2, $result['new_streak']);
        $this->assertSame(70, $result['points_added']);
    }

    public function test_missing_a_day_resets_the_streak_to_1(): void
    {
        $user = $this->makeUser();
        $day1 = Carbon::parse('2026-01-01 09:00:00');
        $this->service->claim($user, $day1);

        $result = $this->service->claim($user, $day1->copy()->addDays(3)); // skipped days 2-3

        $this->assertSame(1, $result['new_streak']);
        $this->assertSame(50, $result['points_added']);
    }

    public function test_the_streak_cycles_back_to_day_1_after_day_7(): void
    {
        $user = $this->makeUser();
        $day = Carbon::parse('2026-01-01 09:00:00');

        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $this->service->claim($user, $day);
            $day = $day->copy()->addDay();
        }
        $this->assertSame(7, $last['new_streak']);
        $this->assertSame(1000, $last['points_added']);

        $eighth = $this->service->claim($user, $day);

        $this->assertSame(1, $eighth['new_streak']);
        $this->assertSame(50, $eighth['points_added']);
    }
}
