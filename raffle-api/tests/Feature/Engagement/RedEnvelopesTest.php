<?php

namespace Tests\Feature\Engagement;

use App\Models\Legacy\WpUser;
use App\Models\RedEnvelope;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\RedEnvelopes;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class RedEnvelopesTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    public function test_the_split_always_adds_up_and_gives_everyone_something(): void
    {
        $service = app(RedEnvelopes::class);

        foreach ([[50, 1], [100, 5], [1000, 10], [10, 10], [5000, 7]] as [$total, $slots]) {
            $shares = $service->split($total, $slots);
            $this->assertCount($slots, $shares);
            $this->assertSame($total, array_sum($shares));
            $this->assertGreaterThanOrEqual(1, min($shares));
        }
    }

    public function test_sending_opening_and_refunding_an_envelope(): void
    {
        $raffle = $this->createRaffle(['public_id' => 5]);
        $raffle->update(['is_live_draw_enabled' => true]);
        $sender = $this->actingAsWordPressUser();
        $points = app(PointsService::class);
        $points->credit($sender, 500, 'test');

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/envelopes", ['points' => 10, 'slots' => 2])->assertStatus(422); // below the minimum
        $this->postJson("/api/raffles/{$raffle->id}/live-draw/envelopes", ['points' => 9000, 'slots' => 2])->assertStatus(422);
        $id = $this->postJson("/api/raffles/{$raffle->id}/live-draw/envelopes", ['points' => 200, 'slots' => 2, 'message' => 'Good luck!'])
            ->assertCreated()->assertJsonPath('points_left', 300)->json('id');
        $this->assertTrue(app(BadgeService::class)->has($sender->ID, 'generous'));

        $this->postJson("/api/envelopes/{$id}/claim")->assertStatus(422); // can't open your own

        $friend = WpUser::create(['user_login' => 'friend', 'user_pass' => 'x', 'user_email' => 'f@example.com']);
        $got = app(RedEnvelopes::class)->claim($friend, RedEnvelope::find($id));
        $this->assertSame($got, $points->balance($friend));

        $this->getJson("/api/raffles/{$raffle->id}/live-draw")->assertOk()->assertJsonPath('envelopes.0.claimed_count', 1);

        // Ten minutes later the unopened share goes back to the sender.
        $this->travel(11)->minutes();
        app(RedEnvelopes::class)->refundExpired();
        $this->assertSame(300 + (200 - $got), $points->balance($sender));
        $this->assertSame(0, app(RedEnvelopes::class)->refundExpired());
    }

    public function test_staff_can_drop_a_site_envelope_without_spending_points(): void
    {
        $raffle = $this->createRaffle(['public_id' => 5]);
        $envelope = app(RedEnvelopes::class)->send(null, $raffle, 1000, 20, 'From RaffleKings');

        $this->assertNull($envelope->sender_user_id);
        $this->assertSame(1000, array_sum($envelope->amounts));
        $this->actingAsAdministrator();
        $this->get('/admin/live-chat')->assertOk();
    }
}
