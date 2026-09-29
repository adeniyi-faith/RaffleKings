<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 47 (Phase 7): link tasks are "Go" then
 * "Claim" after a short wait, the daily reset time and the real spin cost
 * reach the page, guests get a real preview, and the bottom nav knows
 * when a reward is waiting.
 */
class RewardsThatFeelAliveTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'site.links.community' => 'https://t.me/example',
            'site.links.whatsapp_channel' => 'https://whatsapp.com/channel/x',
            'rewards.task_wait_seconds' => 10,
        ]);
    }

    // --- Go, wait, Claim -----------------------------------------------------

    public function test_claiming_a_link_task_without_tapping_go_gives_nothing(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/rewards/tasks/whatsapp_follow/claim')
            ->assertStatus(425)
            ->assertJson(['seconds_left' => null, 'message' => 'Tap "Go" and complete the task first, then come back to claim your points.']);

        $this->getJson('/api/rewards/state')->assertJson(['points' => 0]);
    }

    public function test_claim_unlocks_only_after_the_wait(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->actingAsWordPressUser();

        $this->postJson('/api/rewards/tasks/whatsapp_follow/start')
            ->assertOk()
            ->assertJson(['task_id' => 'whatsapp_follow', 'claimable_at' => '2026-10-05T12:00:10+00:00']);

        $this->travel(4)->seconds();
        $this->postJson('/api/rewards/tasks/whatsapp_follow/claim')
            ->assertStatus(425)
            ->assertJson(['seconds_left' => 6, 'message' => 'Almost there! Come back in 6 seconds to claim your points.']);

        $this->travel(6)->seconds();
        $this->postJson('/api/rewards/tasks/whatsapp_follow/claim')->assertOk()->assertJson(['points_added' => 800]);
        $this->getJson('/api/rewards/state')->assertJson(['points' => 800]);
    }

    public function test_the_page_keeps_the_countdown_after_a_reload(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->actingAsWordPressUser();
        $this->postJson('/api/rewards/tasks/join_community/start')->assertOk();

        $tasks = collect($this->getJson('/api/rewards/state')->json('tasks'))->keyBy('task_id');

        $this->assertSame('2026-10-05T12:00:10+00:00', $tasks['join_community']['claimable_at']);
        $this->assertTrue($tasks['join_community']['needs_visit']);
        $this->assertNull($tasks['whatsapp_follow']['claimable_at']);
        $this->assertFalse($tasks['push_notification']['needs_visit']);
    }

    public function test_a_go_from_over_an_hour_ago_no_longer_counts(): void
    {
        $this->actingAsWordPressUser();
        $this->postJson('/api/rewards/tasks/whatsapp_follow/start')->assertOk();

        $this->travel(61)->minutes();

        $this->postJson('/api/rewards/tasks/whatsapp_follow/claim')->assertStatus(425)->assertJson(['seconds_left' => null]);
    }

    public function test_the_daily_share_needs_a_new_go_each_day(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->actingAsWordPressUser();
        $this->postJson('/api/rewards/tasks/whatsapp_share/start')->assertOk();
        $this->travel(11)->seconds();
        $this->postJson('/api/rewards/tasks/whatsapp_share/claim')->assertOk();

        $this->postJson('/api/rewards/tasks/whatsapp_share/start')->assertStatus(409);

        $this->travel(1)->days();
        $this->postJson('/api/rewards/tasks/whatsapp_share/claim')->assertStatus(425);
        $this->postJson('/api/rewards/tasks/whatsapp_share/start')->assertOk();
        $this->travel(11)->seconds();
        $this->postJson('/api/rewards/tasks/whatsapp_share/claim')->assertOk();
    }

    public function test_the_notifications_task_needs_no_go(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/rewards/tasks/push_notification/start')->assertStatus(422);
        $this->postJson('/api/rewards/tasks/push_notification/claim')->assertOk()->assertJson(['points_added' => 1500]);
    }

    public function test_an_admin_can_remove_the_wait(): void
    {
        config(['rewards.task_wait_seconds' => 0]);
        $this->actingAsWordPressUser();

        $this->postJson('/api/rewards/tasks/join_community/start')->assertOk();
        $this->postJson('/api/rewards/tasks/join_community/claim')->assertOk();
    }

    public function test_a_task_whose_link_is_not_set_is_hidden_and_cannot_be_claimed(): void
    {
        config(['site.links.community' => null]);
        $this->actingAsWordPressUser();

        $ids = collect($this->getJson('/api/rewards/state')->json('tasks'))->pluck('task_id')->all();
        $this->assertNotContains('join_community', $ids);
        $this->assertContains('whatsapp_share', $ids); // needs no link setting

        $this->postJson('/api/rewards/tasks/join_community/start')->assertStatus(422);
        $this->postJson('/api/rewards/tasks/join_community/claim')->assertStatus(422);
    }

    public function test_a_guest_cannot_start_a_task(): void
    {
        $this->postJson('/api/rewards/tasks/whatsapp_share/start')->assertUnauthorized();
    }

    // --- Countdown, spin cost -------------------------------------------------

    public function test_the_state_says_when_the_next_daily_reward_unlocks(): void
    {
        Carbon::setTestNow('2026-10-05 21:30:00');
        $this->actingAsWordPressUser();

        $this->getJson('/api/rewards/state')->assertJson(['next_reset_at' => '2026-10-06T00:00:00+00:00']);
    }

    public function test_the_spin_cost_shown_is_the_real_one(): void
    {
        config(['rewards.spin_cost' => 75]);
        $this->actingAsWordPressUser();

        $this->getJson('/api/rewards/state')->assertJsonPath('spin.cost', 75);
        $this->getJson('/api/rewards/spin/odds')->assertJson(['cost' => 75]);
    }

    // --- Guest preview ---------------------------------------------------------

    public function test_a_guest_sees_the_real_rewards(): void
    {
        $this->get('/rewards')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Rewards/Index')
                ->where('preview.daily_schedule', [50, 70, 100, 150, 200, 300, 1000])
                ->has('preview.tasks', 4)
                ->where('preview.tasks.0.completed', false)
                ->where('preview.spin.cost', 50)
                ->has('preview.spin.odds', 4));
    }

    public function test_a_logged_in_customer_gets_no_preview(): void
    {
        $this->actingAsWordPressUser();

        $this->get('/rewards')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('preview', null));
    }

    // --- Red dot ----------------------------------------------------------------

    public function test_the_bottom_nav_dot_shows_until_todays_reward_is_claimed(): void
    {
        $this->actingAsWordPressUser();

        $this->get('/raffles')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.reward_ready', true));

        $this->postJson('/api/rewards/daily-claim')->assertOk();

        $this->get('/raffles')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.reward_ready', false));
    }

    public function test_no_dot_while_the_daily_claim_is_switched_off(): void
    {
        config(['site.switches.daily_claim' => false]);
        $this->actingAsWordPressUser();

        $this->get('/raffles')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.reward_ready', false));
    }

    // --- Spin & Win game page ----------------------------------------------------

    public function test_the_spin_game_page_shows_a_guest_the_real_odds_and_cost(): void
    {
        config(['rewards.spin_cost' => 60]);

        $this->get('/rewards/spin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Rewards/Spin')
                ->where('cost', 60)
                ->has('odds', 4)
                ->where('odds.3.outcome', 'jackpot')
                ->where('points', null));
    }

    public function test_the_spin_game_page_starts_with_the_customers_points(): void
    {
        $this->actingAsWordPressUser();
        $this->postJson('/api/rewards/tasks/push_notification/claim')->assertOk(); // +1500

        $this->get('/rewards/spin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('points', 1500));
    }
}
