<?php

namespace Tests\Feature;

use App\Filament\Resources\RaffleResource\Pages\EditRaffle;
use App\Filament\Resources\RaffleResource\RelationManagers\PrizeTiersRelationManager;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use App\Services\OddsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * The odds calculator: the chance of winning each prize level. The maths is
 * checked against counting every possible draw, so it can't quietly drift.
 */
class OddsCalculatorTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    /** All the ways to choose $k from $n, by brute force (small numbers only). */
    private function choose(int $n, int $k): int
    {
        if ($k < 0 || $k > $n) {
            return 0;
        }

        $r = 1;
        for ($i = 1; $i <= $k; $i++) {
            $r = intdiv($r * ($n - $k + $i), $i);
        }

        return $r;
    }

    public function test_the_maths_matches_counting_every_possible_set_of_tickets(): void
    {
        foreach ([[10, 3, 2], [20, 1, 5], [50, 7, 4], [100, 12, 10], [8, 8, 1], [30, 5, 30]] as [$pool, $winners, $tickets]) {
            $exact = 1 - $this->choose($pool - $winners, $tickets) / $this->choose($pool, $tickets);

            $this->assertEqualsWithDelta($exact, OddsCalculator::atLeastOne($pool, $winners, $tickets), 1e-9, "pool {$pool}, winners {$winners}, tickets {$tickets}");
        }
    }

    public function test_one_ticket_wins_with_chance_winners_over_pool(): void
    {
        $this->assertEqualsWithDelta(0.001, OddsCalculator::atLeastOne(1000, 1, 1), 1e-12);
        $this->assertEqualsWithDelta(0.113, OddsCalculator::atLeastOne(1000, 113, 1), 1e-12);
    }

    public function test_edge_cases(): void
    {
        $this->assertSame(0.0, OddsCalculator::atLeastOne(1000, 0, 5));
        $this->assertSame(0.0, OddsCalculator::atLeastOne(1000, 5, 0));
        $this->assertSame(1.0, OddsCalculator::atLeastOne(1000, 5, 1000));
        $this->assertSame(1.0, OddsCalculator::atLeastOne(10, 10, 1));
        $this->assertSame(0.0, OddsCalculator::atLeastOne(0, 1, 1));
    }

    public function test_more_tickets_never_lower_the_chance(): void
    {
        $last = 0.0;

        foreach ([1, 2, 5, 10, 20, 50, 100, 500, 1000] as $q) {
            $p = OddsCalculator::atLeastOne(1000, 113, $q);
            $this->assertGreaterThanOrEqual($last, $p);
            $last = $p;
        }
    }

    public function test_one_in_and_plain_words_percent(): void
    {
        $this->assertSame(500, OddsCalculator::oneIn(1000, 2));
        $this->assertNull(OddsCalculator::oneIn(1000, 0));
        $this->assertSame('45%', OddsCalculator::percent(0.4516));
        $this->assertSame('1.5%', OddsCalculator::percent(0.0146));
        $this->assertSame('<0.1%', OddsCalculator::percent(0.0004));
        $this->assertSame('0%', OddsCalculator::percent(0));
        $this->assertSame('99%+', OddsCalculator::percent(0.999));
    }

    public function test_the_tiers_are_worked_out_together_with_an_any_prize_headline(): void
    {
        $result = (new OddsCalculator)->forTiers([
            ['name' => 'Grand', 'description' => null, 'value' => 300000, 'winners' => 1],
            ['name' => 'Second', 'description' => null, 'value' => 100000, 'winners' => 2],
            ['name' => 'Third', 'description' => null, 'value' => 20000, 'winners' => 10],
            ['name' => 'Airtime', 'description' => null, 'value' => 1000, 'winners' => 100],
        ], 1000, 5);

        $this->assertSame(113, $result['winners_total']);
        $this->assertSame(800000.0, $result['prize_total']);
        $this->assertSame(1000, $result['tiers'][0]['one_in']);
        $this->assertSame(10, $result['tiers'][3]['one_in']);
        $this->assertEqualsWithDelta(OddsCalculator::atLeastOne(1000, 113, 5), $result['any']['probability'], 1e-6);
        $this->assertSame('45%', $result['any']['percent']);
    }

    public function test_more_winners_than_tickets_is_capped_not_broken(): void
    {
        $result = (new OddsCalculator)->forTiers([
            ['name' => 'A', 'description' => null, 'value' => 10, 'winners' => 8],
            ['name' => 'B', 'description' => null, 'value' => 10, 'winners' => 8],
        ], 10, 1);

        $this->assertSame(10, $result['winners_total']);
        $this->assertSame(2, $result['tiers'][1]['winners']);
        $this->assertSame(1.0, $result['any']['probability']);
    }

    public function test_the_quantity_is_kept_within_the_raffle(): void
    {
        $result = (new OddsCalculator)->forTiers([['name' => 'A', 'description' => null, 'value' => 1, 'winners' => 1]], 10, 9999);

        $this->assertSame(10, $result['quantity']);
    }

    // --- The API ------------------------------------------------------------------

    private function raffleWithTiers(): Raffle
    {
        $raffle = $this->createRaffle(['public_id' => 21, 'price' => '1000', 'max' => '1000', 'grand_prize' => '₦300,000 cash']);
        foreach ([['Grand prize', 300000, 1, 1], ['Second prize', 100000, 2, 2], ['Third prize', 20000, 10, 3], ['Airtime', 1000, 100, 4]] as [$name, $value, $winners, $rank]) {
            RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => $name, 'cash_value' => $value, 'winner_count' => $winners, 'rank' => $rank]);
        }

        return $raffle;
    }

    public function test_anyone_can_ask_the_odds_for_a_raffle(): void
    {
        $this->raffleWithTiers();

        $this->getJson('/api/raffles/21/odds?quantity=5')
            ->assertOk()
            ->assertJsonPath('pool', 1000)
            ->assertJsonPath('quantity', 5)
            ->assertJsonPath('any.percent', '45%')
            ->assertJsonPath('tiers.0.name', 'Grand prize')
            ->assertJsonPath('tiers.0.one_in', 1000)
            ->assertJsonPath('tiers.3.one_in', 10)
            ->assertJsonPath('winners_total', 113);
    }

    public function test_a_raffle_with_no_prize_levels_has_one_grand_prize(): void
    {
        $this->createRaffle(['public_id' => 22, 'price' => '500', 'max' => '200', 'grand_prize' => 'A phone']);

        $this->getJson('/api/raffles/22/odds')
            ->assertOk()
            ->assertJsonPath('tiers.0.name', 'Grand prize')
            ->assertJsonPath('tiers.0.one_in', 200);
    }

    public function test_an_unknown_or_draft_raffle_has_no_odds(): void
    {
        $this->createRaffle(['public_id' => 23, 'max' => '50'], 'draft');

        $this->getJson('/api/raffles/999/odds')->assertNotFound();
        $this->getJson('/api/raffles/23/odds')->assertNotFound();
    }

    public function test_a_silly_quantity_is_kept_sensible(): void
    {
        $this->raffleWithTiers();

        $this->getJson('/api/raffles/21/odds?quantity=-4')->assertOk()->assertJsonPath('quantity', 1);
        $this->getJson('/api/raffles/21/odds?quantity=99999999')->assertOk()->assertJsonPath('quantity', 1000);
    }

    // --- The admin setup summary ---------------------------------------------------------

    public function test_the_setup_summary_shows_prizes_against_sales_and_the_tax(): void
    {
        config(['gaming_tax.rate' => 2.5]);
        $raffle = $this->raffleWithTiers()->load('prizeTiers');

        $sum = (new OddsCalculator)->setupSummary($raffle);

        $this->assertSame(1_000_000.0, $sum['sales']);
        $this->assertSame(800_000.0, $sum['prize_total']);
        $this->assertSame(80.0, $sum['prize_share']);
        $this->assertSame(5_000.0, $sum['tax']);
        $this->assertSame(195_000.0, $sum['left']);
        $this->assertSame([], $sum['warnings']);
    }

    public function test_the_setup_summary_warns_about_losing_raffles_and_missing_values(): void
    {
        $raffle = $this->createRaffle(['public_id' => 24, 'price' => '100', 'max' => '100']);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Big', 'cash_value' => 50000, 'winner_count' => 1, 'rank' => 1]);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Phone', 'cash_value' => 0, 'winner_count' => 1, 'rank' => 2]);

        $text = implode(' ', (new OddsCalculator)->setupSummary($raffle->load('prizeTiers'))['warnings']);

        $this->assertStringContainsString('lose money', $text);
        $this->assertStringContainsString('no cash value', $text);
    }

    public function test_the_prize_levels_table_shows_the_odds_columns_and_summary(): void
    {
        $this->actingAsAdministrator();
        $raffle = $this->raffleWithTiers();

        Livewire::test(PrizeTiersRelationManager::class, ['ownerRecord' => $raffle, 'pageClass' => EditRaffle::class])
            ->assertSuccessful()
            ->assertSee('Chance per ticket')
            ->assertSee('1 in 1,000')
            ->assertSee('Chance with 5 tickets')
            ->assertSee('80% of sales');
    }

    public function test_the_lose_money_warning_can_be_switched_off_and_other_warnings_stay(): void
    {
        config(['raffles.warn_prizes_exceed_sales' => false]);
        $raffle = $this->createRaffle(['public_id' => 25, 'price' => '100', 'max' => '100']);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Big', 'cash_value' => 50000, 'winner_count' => 1, 'rank' => 1]);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Phone', 'cash_value' => 0, 'winner_count' => 1, 'rank' => 2]);

        $text = implode(' ', (new OddsCalculator)->setupSummary($raffle->load('prizeTiers'))['warnings']);

        $this->assertStringNotContainsString('lose money', $text);
        $this->assertStringContainsString('no cash value', $text);
    }
}
