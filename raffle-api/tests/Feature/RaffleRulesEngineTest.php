<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\RaffleBonusEntry;
use App\Models\RaffleDraw;
use App\Models\RafflePrizeTier;
use App\Models\Wallet;
use App\Services\Draw\DrawRules;
use App\Services\LoyaltyService;
use App\Services\PointsService;
use App\Services\ProvablyFairDrawService;
use App\Services\RaffleRulesService;
use App\Settings\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * The Raffle Rules Engine: open, published draw rules that shape how
 * prizes spread (never who wins), locked into the fairness proof.
 */
class RaffleRulesEngineTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private ProvablyFairDrawService $draws;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->draws = app(ProvablyFairDrawService::class);
    }

    private function raffle(array $rules = [], int $publicId = 300, array $tiers = [[1], [2]]): Raffle
    {
        $raffle = $this->createRaffle(['public_id' => $publicId]);
        $raffle->update(['draw_rules' => $rules]);

        foreach ($tiers as $i => [$count]) {
            RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Prize '.($i + 1), 'cash_value' => 1000, 'winner_count' => $count, 'rank' => $i + 1]);
        }

        return $raffle;
    }

    private function user(string $login): WpUser
    {
        return WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com"]);
    }

    private function tickets(WpUser $user, int $raffleId, array $numbers): void
    {
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);

        foreach ($numbers as $n) {
            RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffleId, 'ticket_number' => $n, 'txn_id' => $txn->id]);
        }
    }

    public function test_rules_are_locked_into_the_draw_and_later_edits_do_not_change_it(): void
    {
        $raffle = $this->raffle(['max_wins_per_person' => 2]);
        $draw = $this->draws->commitSeed($raffle);

        $this->assertSame(2, $draw->rules['max_wins_per_person']);
        $this->assertSame(DrawRules::fromArray(['max_wins_per_person' => 2])->hash(), $draw->rules_hash);
        $this->assertSame($draw->rules_hash, $draw->publicCommitment()['rules_hash']);

        $solo = $this->user('solo');
        $this->tickets($solo, 300, [1, 2, 3]);

        $raffle->update(['draw_rules' => ['max_wins_per_person' => 1]]); // too late: the draw keeps its locked rules
        $winners = $this->draws->runDraw($raffle->fresh());

        $this->assertCount(2, $winners); // one person, allowed two prizes
        $result = $this->draws->verify($raffle);
        $this->assertTrue($result['winners_match']);
        $this->assertTrue($result['rules_match']);
    }

    public function test_draws_from_before_the_rules_engine_still_verify(): void
    {
        $raffle = $this->raffle();
        $this->draws->commitSeed($raffle);
        RaffleDraw::query()->update(['rules' => null, 'rules_hash' => null]);

        foreach (['a', 'b', 'c', 'd'] as $i => $login) {
            $this->tickets($this->user($login), 300, [$i + 1]);
        }

        $winners = $this->draws->runDraw($raffle);

        $this->assertCount(3, $winners);
        $this->assertCount(3, collect($winners)->pluck('user_id')->unique()); // legacy: one prize each
        $this->assertTrue($this->draws->verify($raffle)['winners_match']);
    }

    public function test_the_top_prize_cooldown_keeps_a_recent_top_winner_off_the_top_prize_only(): void
    {
        $raffle = $this->raffle(['top_prize_cooldown_days' => 30, 'recent_winner_cooldown_days' => 0], tiers: [[1], [1]]);
        $this->draws->commitSeed($raffle);
        $recent = $this->user('recent');
        $other = $this->user('other');
        $this->tickets($recent, 300, [1]);
        $this->tickets($other, 300, [2]);
        RaffleWinner::create(['raffle_id' => 999, 'user_id' => $recent->ID, 'ticket_number' => 5, 'prize_name' => 'x', 'prize_rank' => 1, 'prize_cash_value' => 1])
            ->forceFill(['won_at' => now()->subDays(5)])->save();

        $winners = $this->draws->runDraw($raffle);

        $this->assertSame($other->ID, (int) $winners[0]->user_id);   // top prize
        $this->assertSame($recent->ID, (int) $winners[1]->user_id);  // still wins the second
        $this->assertTrue($this->draws->verify($raffle)['winners_match']);
    }

    public function test_loyalty_bonus_entries_join_the_draw_and_a_bonus_win_verifies(): void
    {
        config(['loyalty.tiers' => [['key' => 'bronze', 'name' => 'Bronze', 'bonus_entries' => 2]]]);
        $raffle = $this->raffle(['loyalty_bonus_entries' => true, 'max_wins_per_person' => 3], tiers: [[3]]);
        $this->draws->commitSeed($raffle);

        $player = $this->user('player');
        $this->tickets($player, 300, [7]);
        app(RaffleRulesService::class)->grantBonusEntries($player->ID, 300);
        app(RaffleRulesService::class)->grantBonusEntries($player->ID, 300); // never doubles up

        $this->assertSame(2, (int) RaffleBonusEntry::query()->value('entries'));

        $winners = $this->draws->runDraw($raffle);

        // 1 ticket + 2 bonus entries = 3 pool slots, so all three prizes, two from bonus entries.
        $this->assertEqualsCanonicalizing([0, 0, 7], collect($winners)->pluck('ticket_number')->map(fn ($n) => (int) $n)->all());
        $this->assertTrue($this->draws->verify($raffle)['winners_match']);
    }

    public function test_consolation_points_go_to_non_winners_who_bought_enough(): void
    {
        $raffle = $this->raffle(['consolation_min_tickets' => 2, 'consolation_points' => 150], tiers: [[1]]);
        $this->draws->commitSeed($raffle);
        $a = $this->user('a');
        $b = $this->user('b');
        $c = $this->user('c');
        $this->tickets($a, 300, [1, 2]);
        $this->tickets($b, 300, [3, 4]);
        $this->tickets($c, 300, [5]);

        $winner = (int) $this->draws->runDraw($raffle)[0]->user_id;
        $points = app(PointsService::class);

        foreach ([$a, $b] as $u) {
            $this->assertSame($u->ID === $winner ? 0 : 150, $points->balance($u));
        }
        $this->assertSame(0, $points->balance($c)); // only one ticket
    }

    public function test_members_only_and_new_players_only_raffles_refuse_ineligible_buyers(): void
    {
        $this->raffle(['min_tier' => 'gold'], publicId: 5);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5, 'ticket_numbers' => [3], 'unit_price' => 100, 'submitted_amount' => 100,
            'funding_source' => 'wallet', 'idempotency_key' => 'gold-only-1',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'This raffle is for Gold loyalty members and above. Keep playing each week to move up — see your progress on the Rewards page. No money has been taken.']);

        $this->get('/raffles/5')->assertInertia(fn ($page) => $page->where('drawInfo.not_eligible', fn ($m) => str_contains($m, 'Gold')));
        $this->get('/raffles/5/numbers?qty=1')->assertRedirect('/raffles/5');

        $this->tickets($user, 77, [1]); // has played another raffle before
        $this->raffle(['new_players_only' => true], publicId: 6);
        $this->assertNotNull(app(RaffleRulesService::class)->whyNotEligible($user->ID, 6));
        $this->assertNull(app(RaffleRulesService::class)->whyNotEligible($this->user('fresh')->ID, 6));
    }

    public function test_a_purchase_in_a_bonus_raffle_grants_the_buyers_tier_entries(): void
    {
        config(['loyalty.tiers' => [['key' => 'bronze', 'name' => 'Bronze', 'bonus_entries' => 1]]]);
        $this->raffle(['loyalty_bonus_entries' => true], publicId: 5);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5, 'ticket_numbers' => [3], 'unit_price' => 100, 'submitted_amount' => 100,
            'funding_source' => 'wallet', 'idempotency_key' => 'bonus-entry-1',
        ])->assertCreated();

        $this->assertSame(1, app(RaffleRulesService::class)->bonusEntries($user->ID, 5));
        $this->getJson('/api/account/tickets')->assertOk()->assertJsonFragment(['bonus_entries' => 1]);
    }

    public function test_loyalty_tiers_reward_playing_across_weeks(): void
    {
        $user = $this->user('regular');
        $loyalty = app(LoyaltyService::class);

        $this->assertSame('bronze', $loyalty->profile($user->ID)['tier']['key']);

        // Three different weeks, 12 tickets: meets Silver (3 weeks, 10 tickets).
        foreach ([0, 1, 2] as $weeksAgo) {
            $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
            foreach (range(1, 4) as $n) {
                $entry = RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => 40 + $weeksAgo, 'ticket_number' => $n, 'txn_id' => $txn->id]);
                $entry->forceFill(['created_at' => now()->subWeeks($weeksAgo)])->save();
            }
        }

        $loyalty->forget($user->ID);
        $profile = $loyalty->profile($user->ID);

        $this->assertSame('silver', $profile['tier']['key']);
        $this->assertSame(3, $profile['active_weeks']);
        $this->assertSame('gold', $profile['next']['key']);
        $this->assertSame(2, $profile['next']['weeks_needed']);
    }

    public function test_the_simulator_runs_without_saving_anything(): void
    {
        $raffle = $this->raffle();
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $login) {
            $this->tickets($this->user($login), 300, [$i + 1]);
        }

        $result = $this->draws->simulate($raffle, 200);

        $this->assertSame(200, $result['runs']);
        $this->assertSame(5, $result['players']);
        $this->assertSame(3, $result['prizes']);
        $this->assertEqualsWithDelta(60.0, $result['by_tier'][0]['chance_to_win_any'], 0.01); // 3 prizes, 5 players, one each
        $this->assertSame(0, RaffleWinner::query()->count());
    }

    public function test_the_raffle_page_explains_the_rules_in_plain_words(): void
    {
        $this->raffle(['consolation_min_tickets' => 5, 'consolation_points' => 100], publicId: 5);

        $this->get('/raffles/5')->assertInertia(fn ($page) => $page
            ->where('drawInfo.rules', fn ($lines) => collect($lines)->contains(fn ($l) => str_contains($l, "Buy 5+ tickets and don't win? You get 100 reward points.")))
            ->where('drawInfo.not_eligible', null));
    }

    public function test_the_loyalty_tiers_setting_keeps_bronze_free_and_values_sensible(): void
    {
        $setting = new Setting('loyalty.tiers', 'Tiers', 'loyalty_tiers');

        $saved = $setting->fromForm([
            ['key' => 'bronze', 'name' => '', 'min_active_weeks' => 4, 'min_tickets' => 9, 'bonus_entries' => 0],
            ['key' => 'silver', 'name' => 'Silver', 'min_active_weeks' => '3', 'min_tickets' => '10', 'bonus_entries' => 99],
        ]);

        $this->assertSame(['key' => 'bronze', 'name' => 'Bronze', 'min_active_weeks' => 0, 'min_tickets' => 0, 'bonus_entries' => 0], $saved[0]);
        $this->assertSame(20, $saved[1]['bonus_entries']);
    }
}
