<?php

namespace Tests\Feature;

use App\Events\LiveDrawCommentPosted;
use App\Events\LiveDrawReactionPosted;
use App\Events\LiveDrawStateChanged;
use App\Events\LiveDrawWinnerRevealed;
use App\Jobs\RunLiveDrawRevealJob;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\LiveDrawComment;
use App\Models\LiveDrawReveal;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Models\RafflePrizeTier;
use App\Services\LiveDrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class LiveDrawControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function makeRaffle(bool $liveDrawEnabled = true): Raffle
    {
        return Raffle::create([
            'legacy_post_id' => 700,
            'title' => 'Live Draw Test Raffle',
            'price' => 100,
            'max_tickets' => 10,
            'status' => 'published',
            'is_live_draw_enabled' => $liveDrawEnabled,
            'live_draw_pace_ms' => 10, // fast in tests
        ]);
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

    public function test_a_guest_can_view_live_draw_state_without_logging_in(): void
    {
        $raffle = $this->makeRaffle();

        $this->getJson("/api/raffles/{$raffle->id}/live-draw")
            ->assertOk()
            ->assertJson([
                'raffle_id' => $raffle->id,
                'is_live_draw_enabled' => true,
                'live_draw_status' => 'idle',
                'revealed' => [],
            ]);
    }

    public function test_a_guest_cannot_post_a_comment(): void
    {
        $raffle = $this->makeRaffle();

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/comments", ['body' => 'Good luck everyone!'])
            ->assertStatus(401);
    }

    public function test_a_logged_in_user_can_post_a_comment_and_it_broadcasts(): void
    {
        Event::fake([LiveDrawCommentPosted::class]);
        $raffle = $this->makeRaffle();
        $this->actingAsWordPressUser();

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/comments", ['body' => 'Good luck everyone!'])
            ->assertCreated()
            ->assertJsonFragment(['body' => 'Good luck everyone!']);

        $this->assertDatabaseHas('live_draw_comments', ['raffle_id' => $raffle->id, 'body' => 'Good luck everyone!']);
        Event::assertDispatched(LiveDrawCommentPosted::class);
    }

    public function test_a_comment_over_the_length_limit_is_rejected(): void
    {
        $raffle = $this->makeRaffle();
        $this->actingAsWordPressUser();

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/comments", ['body' => str_repeat('x', 281)])
            ->assertStatus(422);
    }

    public function test_a_logged_in_user_can_post_a_reaction_and_counts_aggregate(): void
    {
        Event::fake([LiveDrawReactionPosted::class]);
        $raffle = $this->makeRaffle();
        $this->actingAsWordPressUser();

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/reactions", ['reaction_type' => 'fire'])
            ->assertCreated()
            ->assertJson(['reaction_type' => 'fire', 'counts' => ['fire' => 1]]);

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/reactions", ['reaction_type' => 'fire'])
            ->assertJson(['counts' => ['fire' => 2]]);

        Event::assertDispatched(LiveDrawReactionPosted::class, 2);
    }

    public function test_an_unknown_reaction_type_is_rejected(): void
    {
        $raffle = $this->makeRaffle();
        $this->actingAsWordPressUser();

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/reactions", ['reaction_type' => 'poop'])
            ->assertStatus(422);
    }

    public function test_a_regular_user_cannot_start_the_live_reveal(): void
    {
        $raffle = $this->makeRaffle();
        $this->actingAsWordPressUser();

        $this->postJson("/api/admin/raffles/{$raffle->id}/live-draw/start")->assertStatus(403);
    }

    public function test_starting_the_reveal_requires_a_completed_draw(): void
    {
        $raffle = $this->makeRaffle();
        $this->actingAsAdministrator();

        $this->postJson("/api/admin/raffles/{$raffle->id}/live-draw/start")->assertStatus(422);
    }

    public function test_an_administrator_can_start_the_live_reveal_after_the_draw_runs(): void
    {
        Bus::fake();
        $raffle = $this->makeRaffle();
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Grand Prize', 'cash_value' => 5000, 'winner_count' => 1, 'rank' => 1]);
        $buyer = WpUser::create(['user_login' => 'liveDrawBuyer', 'user_pass' => 'x', 'user_email' => 'ldb@example.com']);
        $txn = RaffleTransaction::create(['user_id' => $buyer->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 700, 'ticket_number' => 1, 'txn_id' => $txn->id]);

        $this->actingAsAdministrator();
        $this->postJson("/api/admin/raffles/{$raffle->id}/draw/commit")->assertCreated();
        $this->postJson("/api/admin/raffles/{$raffle->id}/draw/run")->assertCreated();

        $this->postJson("/api/admin/raffles/{$raffle->id}/live-draw/start")
            ->assertOk()
            ->assertJson(['live_draw_status' => 'revealing']);

        $this->assertSame('revealing', $raffle->fresh()->live_draw_status);
        Bus::assertDispatched(RunLiveDrawRevealJob::class, fn ($job) => $job->raffleId === $raffle->id);
    }

    public function test_the_reveal_job_broadcasts_one_event_per_winner_in_order_and_marks_them_visible(): void
    {
        Event::fake([LiveDrawWinnerRevealed::class, LiveDrawStateChanged::class]);

        $raffle = $this->makeRaffle();
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Grand Prize', 'cash_value' => 5000, 'winner_count' => 1, 'rank' => 1]);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Runner Up', 'cash_value' => 1000, 'winner_count' => 1, 'rank' => 2]);

        $buyer1 = WpUser::create(['user_login' => 'b1', 'user_pass' => 'x', 'user_email' => 'b1@example.com']);
        $buyer2 = WpUser::create(['user_login' => 'b2', 'user_pass' => 'x', 'user_email' => 'b2@example.com']);
        foreach ([$buyer1, $buyer2] as $i => $buyer) {
            $txn = RaffleTransaction::create(['user_id' => $buyer->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
            RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 700, 'ticket_number' => $i + 1, 'txn_id' => $txn->id]);
        }

        $this->actingAsAdministrator();
        $this->postJson("/api/admin/raffles/{$raffle->id}/draw/commit")->assertCreated();
        $this->postJson("/api/admin/raffles/{$raffle->id}/draw/run")->assertCreated();

        $draw = RaffleDraw::where('raffle_id', $raffle->id)->firstOrFail();
        $raffle->update(['live_draw_status' => 'revealing']);

        (new RunLiveDrawRevealJob($raffle->id))->handle(app(LiveDrawService::class));

        $this->assertSame(2, LiveDrawReveal::where('raffle_draw_id', $draw->id)->count());
        $this->assertSame('completed', $raffle->fresh()->live_draw_status);
        $this->assertSame(2, RaffleWinner::where('raffle_id', 700)->where('is_visible', true)->count());

        Event::assertDispatched(LiveDrawWinnerRevealed::class, 2);
        Event::assertDispatched(LiveDrawStateChanged::class, fn ($e) => $e->status === 'completed');

        // Catch-up: a viewer loading the page after the fact sees exactly what aired.
        $state = $this->getJson("/api/raffles/{$raffle->id}/live-draw")->json();
        $this->assertCount(2, $state['revealed']);
        $this->assertSame('completed', $state['live_draw_status']);
    }

    public function test_a_reconnecting_viewer_sees_recent_comments_and_reaction_counts_on_catch_up(): void
    {
        $raffle = $this->makeRaffle();
        $user = $this->actingAsWordPressUser();

        LiveDrawComment::create(['raffle_id' => $raffle->id, 'user_id' => $user->ID, 'body' => 'hello']);

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/reactions", ['reaction_type' => 'heart'])->assertCreated();

        $state = $this->getJson("/api/raffles/{$raffle->id}/live-draw")->json();

        $this->assertCount(1, $state['comments']);
        $this->assertSame('hello', $state['comments'][0]['body']);
        $this->assertSame(1, $state['reaction_counts']['heart']);
    }
}
