<?php

namespace Tests\Feature;

use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportLegacyRafflesTest extends TestCase
{
    use RefreshDatabase;

    private function makeLegacyRaffleWithPrizes(): WpPost
    {
        $post = WpPost::create([
            'post_title' => 'Car Giveaway',
            'post_excerpt' => 'Win a car.',
            'post_type' => 'raffle',
            'post_status' => 'publish',
            'post_date' => now(),
        ]);

        $meta = [
            'price' => '1000',
            'max' => '50',
            'grand_prize' => 'Toyota Corolla',
            'expiry' => '2030-06-01',
            'is_sold_out' => '0',
            // ACF repeater "prize_structure" raw storage convention —
            // row 0 is the grand prize, row 1 a runner-up tier, and one
            // deliberately empty tier that must be skipped.
            'prize_structure_0_tier_name' => 'Grand Prize',
            'prize_structure_0_prize_description' => 'Toyota Corolla',
            'prize_structure_0_cash_value' => '8000000',
            'prize_structure_0_winner_count' => '1',
            'prize_structure_1_tier_name' => 'Runner Up',
            'prize_structure_1_prize_description' => 'Cash prize',
            'prize_structure_1_cash_value' => '50000',
            'prize_structure_1_winner_count' => '3',
            'prize_structure_2_tier_name' => '', // empty tier — must be skipped
            'prize_structure_2_cash_value' => '0',
        ];

        foreach ($meta as $key => $value) {
            WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => $key, 'meta_value' => $value]);
        }

        return $post;
    }

    public function test_it_imports_a_raffle_and_its_prize_tiers(): void
    {
        $post = $this->makeLegacyRaffleWithPrizes();

        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $raffle = Raffle::where('legacy_post_id', $post->ID)->first();

        $this->assertNotNull($raffle);
        $this->assertSame('Car Giveaway', $raffle->title);
        $this->assertEquals(1000, $raffle->price);
        $this->assertSame(50, $raffle->max_tickets);
        $this->assertSame('Toyota Corolla', $raffle->grand_prize);
        $this->assertSame('published', $raffle->status);

        $tiers = $raffle->prizeTiers()->get();
        $this->assertCount(2, $tiers); // the empty third row must be skipped

        $this->assertSame('Grand Prize', $tiers[0]->tier_name);
        $this->assertEquals(8000000, $tiers[0]->cash_value);
        $this->assertSame(1, $tiers[0]->winner_count);
        $this->assertSame(1, $tiers[0]->rank);

        $this->assertSame('Runner Up', $tiers[1]->tier_name);
        $this->assertSame(3, $tiers[1]->winner_count);
        $this->assertSame(2, $tiers[1]->rank);
    }

    public function test_a_manually_sold_out_raffle_imports_as_closed_regardless_of_post_status(): void
    {
        $post = $this->makeLegacyRaffleWithPrizes();
        WpPostMeta::where('post_id', $post->ID)->where('meta_key', 'is_sold_out')->update(['meta_value' => '1']);

        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $this->assertSame('closed', Raffle::where('legacy_post_id', $post->ID)->value('status'));
    }

    public function test_a_draft_post_imports_as_a_draft_raffle(): void
    {
        $post = WpPost::create([
            'post_title' => 'Unpublished raffle', 'post_type' => 'raffle', 'post_status' => 'draft', 'post_date' => now(),
        ]);

        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $this->assertSame('draft', Raffle::where('legacy_post_id', $post->ID)->value('status'));
    }

    public function test_dry_run_makes_no_database_changes(): void
    {
        $this->makeLegacyRaffleWithPrizes();

        $this->artisan('legacy:import-raffles --dry-run')->assertSuccessful();

        $this->assertSame(0, Raffle::count());
        $this->assertSame(0, RafflePrizeTier::count());
    }

    public function test_running_the_import_twice_replaces_rather_than_duplicates(): void
    {
        $post = $this->makeLegacyRaffleWithPrizes();

        $this->artisan('legacy:import-raffles')->assertSuccessful();
        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $this->assertSame(1, Raffle::where('legacy_post_id', $post->ID)->count());
        $this->assertSame(2, Raffle::where('legacy_post_id', $post->ID)->first()->prizeTiers()->count());
    }

    public function test_an_edited_legacy_raffle_reimports_with_updated_values(): void
    {
        $post = $this->makeLegacyRaffleWithPrizes();
        $this->artisan('legacy:import-raffles')->assertSuccessful();

        WpPostMeta::where('post_id', $post->ID)->where('meta_key', 'price')->update(['meta_value' => '2500']);

        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $this->assertEquals(2500, Raffle::where('legacy_post_id', $post->ID)->value('price'));
    }
}
