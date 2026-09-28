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

    public function test_refresh_reimports_an_edited_legacy_raffle_with_updated_values(): void
    {
        $post = $this->makeLegacyRaffleWithPrizes();
        $this->artisan('legacy:import-raffles')->assertSuccessful();

        WpPostMeta::where('post_id', $post->ID)->where('meta_key', 'price')->update(['meta_value' => '2500']);

        $this->artisan('legacy:import-raffles --refresh')->assertSuccessful();

        $this->assertEquals(2500, Raffle::where('legacy_post_id', $post->ID)->value('price'));
    }

    public function test_a_normal_rerun_never_overwrites_edits_made_in_the_admin(): void
    {
        // Item 43: the native table is now the only source, so the deploy
        // runs this on every release — it must never undo an admin's edit.
        $post = $this->makeLegacyRaffleWithPrizes();
        $this->artisan('legacy:import-raffles')->assertSuccessful();
        $raffle = Raffle::where('legacy_post_id', $post->ID)->first();
        $raffle->update(['price' => 750, 'title' => 'Edited in admin']);
        $tierIds = $raffle->prizeTiers()->pluck('id')->all();

        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $raffle->refresh();
        $this->assertEquals(750, $raffle->price);
        $this->assertSame('Edited in admin', $raffle->title);
        $this->assertSame($tierIds, $raffle->prizeTiers()->pluck('id')->all());
    }

    public function test_an_imported_raffle_keeps_its_wordpress_id_as_its_public_number_and_publish_date(): void
    {
        $post = $this->makeLegacyRaffleWithPrizes();
        $post->update(['post_date' => '2026-01-15 10:00:00']);
        WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => 'prize_type', 'meta_value' => 'gadgets']);
        WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => 'prize_list', 'meta_value' => '2nd: ₦50,000']);

        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $raffle = Raffle::where('legacy_post_id', $post->ID)->first();
        $this->assertSame($post->ID, $raffle->public_id);
        $this->assertSame('gadgets', $raffle->prize_type);
        $this->assertSame('2nd: ₦50,000', $raffle->prize_list);
        $this->assertSame('2026-01-15', $raffle->created_at->toDateString());
    }

    public function test_a_raffle_imported_before_item_43_gets_its_blank_new_fields_filled_in(): void
    {
        $post = $this->makeLegacyRaffleWithPrizes();
        WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => 'prize_type', 'meta_value' => 'cash']);
        Raffle::create(['legacy_post_id' => $post->ID, 'title' => 'Old copy', 'price' => 1000, 'max_tickets' => 50, 'status' => 'published']);

        $this->artisan('legacy:import-raffles')->assertSuccessful();

        $raffle = Raffle::where('legacy_post_id', $post->ID)->first();
        $this->assertSame('cash', $raffle->prize_type);
        $this->assertSame('Old copy', $raffle->title); // everything else untouched
    }
}
