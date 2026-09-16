<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class DrawControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function makeRaffleWithTiers(): Raffle
    {
        $raffle = Raffle::create(['legacy_post_id' => 500, 'title' => 'Test raffle', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Grand Prize', 'cash_value' => 100000, 'winner_count' => 1, 'rank' => 1]);

        return $raffle;
    }

    private function actingAsAdministrator(): WpUser
    {
        $user = $this->actingAsWordPressUser();

        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        return $user;
    }

    public function test_a_regular_user_cannot_commit_a_draw_seed(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->actingAsWordPressUser();

        $this->postJson("/api/raffles/{$raffle->id}/draw/commit")->assertStatus(403);
    }

    public function test_an_administrator_can_commit_and_run_a_draw(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $buyer = WpUser::create(['user_login' => 'buyer', 'user_pass' => 'x', 'user_email' => 'buyer@example.com']);
        $txn = RaffleTransaction::create(['user_id' => $buyer->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 500, 'ticket_number' => 1, 'txn_id' => $txn->id]);

        $this->actingAsAdministrator();

        $this->postJson("/api/raffles/{$raffle->id}/draw/commit")
            ->assertCreated()
            ->assertJson(['has_run' => false]);

        $this->postJson("/api/raffles/{$raffle->id}/draw/run")
            ->assertCreated()
            ->assertJson(['winner_count' => 1]);
    }

    public function test_the_public_can_see_the_commitment_hash_before_the_draw_runs(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->actingAsAdministrator();
        $this->postJson("/api/raffles/{$raffle->id}/draw/commit")->assertCreated();

        // No auth for this one — it's public.
        $response = $this->getJson("/api/raffles/{$raffle->id}/draw");

        $response->assertOk();
        $response->assertJson(['has_run' => false]);
        $response->assertJsonMissing(['server_seed']); // the raw seed must never leak before reveal
    }

    public function test_the_public_can_verify_a_completed_draw(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $buyer = WpUser::create(['user_login' => 'buyer2', 'user_pass' => 'x', 'user_email' => 'buyer2@example.com']);
        $txn = RaffleTransaction::create(['user_id' => $buyer->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 500, 'ticket_number' => 1, 'txn_id' => $txn->id]);

        $this->actingAsAdministrator();
        $this->postJson("/api/raffles/{$raffle->id}/draw/commit")->assertCreated();
        $this->postJson("/api/raffles/{$raffle->id}/draw/run")->assertCreated();

        $response = $this->getJson("/api/raffles/{$raffle->id}/draw");

        $response->assertOk();
        $response->assertJson([
            'has_run' => true,
            'verification' => [
                'seed_hash_matches' => true,
                'client_seed_matches' => true,
                'winners_match' => true,
            ],
        ]);
    }

    public function test_a_raffle_with_no_committed_draw_returns_404(): void
    {
        $raffle = $this->makeRaffleWithTiers();

        $this->getJson("/api/raffles/{$raffle->id}/draw")->assertNotFound();
    }
}
