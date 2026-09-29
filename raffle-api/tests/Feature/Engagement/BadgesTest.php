<?php

namespace Tests\Feature\Engagement;

use App\Models\CustomerMessage;
use App\Models\UserBadge;
use App\Models\Wallet;
use App\Services\DailyClaimService;
use App\Services\Engagement\BadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class BadgesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    public function test_a_first_purchase_earns_the_first_ticket_badge_and_an_inbox_alert(): void
    {
        $this->createRaffle(['public_id' => 5]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5, 'ticket_numbers' => [3], 'unit_price' => 100, 'submitted_amount' => 100,
            'funding_source' => 'wallet', 'idempotency_key' => 'badge-first-1',
        ])->assertCreated();

        $this->assertTrue(app(BadgeService::class)->has($user->ID, 'first_ticket'));
        $this->assertSame(1, CustomerMessage::query()->where('user_id', $user->ID)->where('kind', 'reward')->count());
    }

    public function test_badges_are_awarded_only_once(): void
    {
        $user = $this->actingAsWordPressUser();
        $badges = app(BadgeService::class);

        $this->assertTrue($badges->award($user->ID, 'first_win'));
        $this->assertFalse($badges->award($user->ID, 'first_win'));
        $this->assertFalse($badges->award($user->ID, 'not_a_badge'));
        $this->assertSame(1, UserBadge::query()->count());
    }

    public function test_only_earned_badges_can_be_pinned_and_at_most_three(): void
    {
        $user = $this->actingAsWordPressUser();
        $badges = app(BadgeService::class);
        foreach (['first_ticket', 'first_win', 'streak_7', 'generous'] as $b) {
            $badges->award($user->ID, $b);
        }

        $this->postJson('/api/badges/showcase', ['badges' => ['season_30', 'first_win', 'generous', 'streak_7', 'first_ticket']])
            ->assertOk()->assertJson(['showcase' => ['first_win', 'generous', 'streak_7']]);

        $this->getJson('/api/badges')->assertOk()
            ->assertJsonPath('showcase.0.key', 'first_win')
            ->assertJsonCount(count(config('engagement.badges')), 'badges');

        $this->get('/account/badges')->assertOk();
    }

    public function test_a_seven_day_streak_earns_its_badge(): void
    {
        $user = $this->actingAsWordPressUser();
        $claims = app(DailyClaimService::class);

        foreach (range(6, 0) as $daysAgo) {
            $claims->claim($user, now()->subDays($daysAgo));
        }

        $this->assertTrue(app(BadgeService::class)->has($user->ID, 'streak_7'));
    }
}
