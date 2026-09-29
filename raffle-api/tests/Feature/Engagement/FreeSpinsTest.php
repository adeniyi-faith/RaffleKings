<?php

namespace Tests\Feature\Engagement;

use App\Models\UserEngagement;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\Perks;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class FreeSpinsTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_a_free_spin_is_used_instead_of_points(): void
    {
        $user = $this->actingAsWordPressUser();
        app(Perks::class)->addFreeSpins($user->ID, 1);

        $this->postJson('/api/rewards/spin', ['free' => true])->assertOk()
            ->assertJsonPath('free', true)
            ->assertJsonPath('free_spins_left', 0);

        // With no free spins and no points, a spin is refused and nothing is taken.
        $this->postJson('/api/rewards/spin', ['free' => true])->assertStatus(402);
        $this->assertGreaterThanOrEqual(0, app(PointsService::class)->balance($user));
    }

    public function test_the_birthday_is_set_once_and_gives_one_spin_a_year(): void
    {
        $this->travelTo(now('Africa/Lagos')->setDate(2026, 11, 3)->setTime(10, 0));
        $user = $this->actingAsWordPressUser(['user_registered' => now()->subMonths(2)]);

        $this->postJson('/api/profile', ['display_name' => 'Ada', 'email' => $user->user_email, 'birthday' => '02-30'])->assertStatus(422);
        $this->postJson('/api/profile', ['display_name' => 'Ada', 'email' => $user->user_email, 'birthday' => '11-03'])->assertOk();
        $this->postJson('/api/profile', ['display_name' => 'Ada', 'email' => $user->user_email, 'birthday' => '01-01'])->assertOk();
        $this->assertSame('11-03', UserEngagement::for($user->ID)->birthday);

        $this->getJson('/api/rewards/state')->assertOk()->assertJsonPath('free_spins', 1);
        $this->getJson('/api/rewards/state')->assertOk()->assertJsonPath('free_spins', 1); // still only one
        $this->assertTrue(app(BadgeService::class)->has($user->ID, 'birthday'));
    }

    public function test_an_account_anniversary_gives_free_spins_once(): void
    {
        config(['engagement.free_spins.milestones.anniversary' => 2]);
        $this->actingAsWordPressUser(['user_registered' => now()->subYear()->subDay()]);

        $this->getJson('/api/rewards/state')->assertOk()->assertJsonPath('free_spins', 2);
        $this->getJson('/api/rewards/state')->assertOk()->assertJsonPath('free_spins', 2);
        $this->get('/rewards/spin')->assertOk();
    }
}
