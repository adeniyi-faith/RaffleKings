<?php

namespace Tests\Feature\Engagement;

use App\Models\Legacy\RaffleWinner;
use App\Models\WinnerStory;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\WinnerStories;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class WinnerStoriesTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    public function test_a_winner_posts_a_story_that_shows_once_approved_and_can_be_reacted_to(): void
    {
        Storage::fake('public');
        $this->createRaffle(['public_id' => 5, 'title' => 'iPhone Raffle']);
        $user = $this->actingAsWordPressUser(['display_name' => 'Ada Obi']);
        $win = RaffleWinner::create(['raffle_id' => 5, 'user_id' => $user->ID, 'ticket_number' => 7, 'prize_name' => 'iPhone 16', 'prize_rank' => 1, 'prize_cash_value' => 0, 'is_visible' => true]);
        $hidden = RaffleWinner::create(['raffle_id' => 6, 'user_id' => $user->ID, 'ticket_number' => 8, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 0, 'is_visible' => false]);

        $this->getJson('/api/stories/my-wins')->assertOk()->assertJsonCount(1, 'wins')->assertJsonPath('wins.0.label', 'iPhone 16 · iPhone Raffle');

        $this->post('/api/stories', ['winner_id' => $hidden->id, 'media' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/stories', ['winner_id' => $win->id, 'caption' => 'So happy!', 'media' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'pending');
        $this->post('/api/stories', ['winner_id' => $win->id, 'media' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertStatus(422);

        $story = WinnerStory::first();
        Storage::disk('public')->assertExists($story->media_path);
        $this->assertSame([], app(WinnerStories::class)->wall(null)['stories']); // not approved yet

        app(WinnerStories::class)->approve($story);
        $this->assertSame(100, app(PointsService::class)->balance($user));
        $this->assertTrue(app(BadgeService::class)->has($user->ID, 'storyteller'));

        $this->postJson("/api/stories/{$story->id}/react", ['emoji' => '🔥'])->assertOk()->assertJsonPath('counts.🔥', 1)->assertJsonPath('yours', '🔥');
        $this->postJson("/api/stories/{$story->id}/react", ['emoji' => '🔥'])->assertOk()->assertJsonPath('counts.🔥', 0);
        $this->postJson("/api/stories/{$story->id}/react", ['emoji' => '💩'])->assertStatus(422);

        $this->get('/winners/stories')->assertOk();
        $this->assertSame('So happy!', app(WinnerStories::class)->wall(null)['stories'][0]['caption']);
    }

    public function test_declining_removes_the_file_and_the_admin_screen_opens(): void
    {
        Storage::fake('public');
        $this->createRaffle(['public_id' => 5]);
        $user = $this->actingAsAdministrator();
        $win = RaffleWinner::create(['raffle_id' => 5, 'user_id' => $user->ID, 'ticket_number' => 7, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 0, 'is_visible' => true]);
        $story = app(WinnerStories::class)->post($user, $win->id, null, UploadedFile::fake()->image('x.png'));

        app(WinnerStories::class)->reject($story, 'The prize is not in the photo');

        $this->assertSame('rejected', $story->fresh()->status);
        Storage::disk('public')->assertMissing($story->media_path);
        $this->get('/admin/winner-stories')->assertOk();
    }
}
