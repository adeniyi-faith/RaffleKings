<?php

namespace Tests\Feature\Engagement;

use App\Models\RaffleBonusEntry;
use App\Models\Wallet;
use App\Services\DailyClaimService;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\Perks;
use App\Services\Engagement\SeasonPass;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class SeasonPassTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function buy(int $raffle, array $numbers, string $key): void
    {
        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => $raffle, 'ticket_numbers' => $numbers, 'unit_price' => 100, 'submitted_amount' => 100 * count($numbers),
            'funding_source' => 'wallet', 'idempotency_key' => $key,
        ])->assertCreated();
    }

    public function test_seasons_are_four_weeks_back_to_back(): void
    {
        config(['engagement.season.starts_on' => '2026-10-05']);
        $season = app(SeasonPass::class);

        $this->assertSame(1, $season->current(Carbon::parse('2026-10-10 12:00', 'Africa/Lagos'))['number']);
        $this->assertSame(2, $season->current(Carbon::parse('2026-11-02 00:30', 'Africa/Lagos'))['number']);
        $this->assertSame('2026-11-30', $season->current(Carbon::parse('2026-11-10', 'Africa/Lagos'))['ends_at']->toDateString());
    }

    public function test_ticket_xp_is_capped_per_day_and_other_play_adds_xp(): void
    {
        config(['engagement.season.xp.ticket' => 10, 'engagement.season.xp.ticket_daily_cap' => 30]);
        $this->createRaffle(['public_id' => 5]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 10000, 'earnings_balance' => 0]);

        foreach ([1, 2, 3, 4] as $n) {
            $this->buy(5, [$n], "season-buy-{$n}"); // 10 XP each, but only 30 a day
        }
        app(DailyClaimService::class)->claim($user); // +25

        $this->getJson('/api/season')->assertOk()->assertJsonPath('xp', 55)->assertJsonPath('level', 0);
    }

    public function test_collecting_rewards_pays_every_reached_level_once_and_tokens_go_into_the_next_purchase(): void
    {
        $this->createRaffle(['public_id' => 5]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);
        app(SeasonPass::class)->addXp($user->ID, 1000); // level 10

        $this->postJson('/api/season/claim')->assertOk()
            ->assertJsonPath('given.levels', range(1, 10))
            ->assertJsonPath('given.free_spins', 2)
            ->assertJsonPath('given.bonus_entries', 1)
            ->assertJsonPath('state.claimable', 0);
        $this->postJson('/api/season/claim')->assertOk()->assertJsonPath('given.levels', []);

        // Points: 25 + 30 + ... + 70 = 475.
        $this->assertSame(475, app(PointsService::class)->balance($user));
        $this->assertSame(2, app(Perks::class)->freeSpins($user->ID));
        $this->assertTrue(app(BadgeService::class)->has($user->ID, 'season_10'));

        $this->buy(5, [9], 'season-token-1');
        $this->assertSame(1, (int) RaffleBonusEntry::query()->where('user_id', $user->ID)->where('reason', 'token')->value('entries'));
        $this->assertSame(0, app(Perks::class)->bonusTokens($user->ID));
    }

    public function test_the_page_opens_for_guests_and_members(): void
    {
        $this->get('/rewards/season')->assertOk();
        $this->actingAsWordPressUser();
        $this->getJson('/api/rewards/state')->assertOk()->assertJsonPath('season.level', 0);
    }
}
