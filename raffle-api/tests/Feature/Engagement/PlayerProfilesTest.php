<?php

namespace Tests\Feature\Engagement;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\PlayerProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * The public player card at /player/{username}: username, picture and badges.
 * Everyone can see it unless the customer made it private; wins only show if
 * they chose to; nothing about money or private details ever does.
 */
class PlayerProfilesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function player(string $login = 'john_a'): WpUser
    {
        return WpUser::create([
            'user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@secret-mail.test",
            'display_name' => 'Mr John Adeyemi', 'user_registered' => now(),
        ]);
    }

    public function test_a_profile_is_visible_to_everyone_by_default(): void
    {
        $user = $this->player();
        app(BadgeService::class)->award($user->ID, 'first_ticket');

        $this->get('/player/john_a')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Player/Show')
            ->where('profile.username', 'john_a')
            ->where('profile.badges.0.key', 'first_ticket'));
    }

    public function test_the_card_never_includes_private_details(): void
    {
        $this->player();
        $html = $this->get('/player/john_a')->getContent();

        $this->assertStringNotContainsString('secret-mail.test', $html);
        $this->assertStringNotContainsString('Adeyemi', $html);
        $this->assertStringNotContainsString('balance', strtolower(json_encode(app(PlayerProfiles::class)->card('john_a'))));
    }

    public function test_a_private_profile_looks_exactly_like_a_username_that_does_not_exist(): void
    {
        $user = $this->player();
        app(PlayerProfiles::class)->update($user->ID, ['visibility' => 'private']);

        $private = $this->get('/player/john_a');
        $missing = $this->get('/player/nobody_here');

        $private->assertNotFound();
        $missing->assertNotFound();
        $private->assertInertia(fn ($page) => $page->where('profile', null));
    }

    public function test_staff_and_banned_accounts_have_no_public_card(): void
    {
        $staff = $this->player('the_boss');
        WpUserMeta::create(['user_id' => $staff->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'owner']);
        $banned = $this->player('bad_guy');
        WpUserMeta::create(['user_id' => $banned->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        $this->get('/player/the_boss')->assertNotFound();
        $this->get('/player/bad_guy')->assertNotFound();
    }

    public function test_wins_are_hidden_until_the_player_chooses_to_show_them(): void
    {
        $user = $this->player();
        app(BadgeService::class)->award($user->ID, 'first_win');
        app(BadgeService::class)->award($user->ID, 'first_ticket');

        $hidden = app(PlayerProfiles::class)->card('john_a');
        $this->assertNull($hidden['wins']);
        $this->assertSame(['first_ticket'], array_column($hidden['badges'], 'key'));

        app(PlayerProfiles::class)->update($user->ID, ['show_wins' => true]);
        $shown = app(PlayerProfiles::class)->card('john_a');
        $this->assertSame(0, $shown['wins']);
        $this->assertContains('first_win', array_column($shown['badges'], 'key'));
    }

    public function test_a_player_changes_their_own_privacy_from_the_badges_page(): void
    {
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/badges/privacy', ['visibility' => 'private', 'show_wins' => true])
            ->assertOk()
            ->assertJsonPath('privacy.visibility', 'private')
            ->assertJsonPath('privacy.show_wins', true)
            ->assertJsonPath('privacy.path', '/player/'.rawurlencode($user->user_login));

        $this->getJson('/api/badges')->assertJsonPath('privacy.visibility', 'private');
        $this->postJson('/api/badges/privacy', ['visibility' => 'nonsense'])->assertStatus(422);
    }

    public function test_changing_privacy_needs_a_login(): void
    {
        $this->postJson('/api/badges/privacy', ['visibility' => 'private'])->assertUnauthorized();
    }

    public function test_team_members_link_to_their_card_only_when_it_is_public(): void
    {
        $this->createRaffle(['public_id' => 5]);
        $captain = $this->teamPlayer('cap_pub');
        $boosts = app(\App\Services\Engagement\SocialBoosts::class);
        $team = $boosts->createTeam($captain, 5);

        $this->assertSame('/player/cap_pub', $boosts->presentTeam($team)['members'][0]['profile']);

        app(PlayerProfiles::class)->update($captain->ID, ['visibility' => 'private']);
        $this->assertNull($boosts->presentTeam($team)['members'][0]['profile']);
    }

    private function teamPlayer(string $login): WpUser
    {
        $user = $this->player($login);
        $txn = \App\Models\Legacy\RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        \App\Models\Legacy\RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => $user->ID, 'txn_id' => $txn->id]);

        return $user;
    }

    public function test_winners_on_the_hall_of_fame_link_to_their_card_unless_it_is_private(): void
    {
        $user = $this->player('lucky_one');
        \App\Models\Legacy\RaffleWinner::create([
            'raffle_id' => 900, 'user_id' => $user->ID, 'ticket_number' => 42, 'prize_name' => 'Cash', 'prize_rank' => 1,
            'prize_cash_value' => 5000, 'is_credited' => false, 'is_visible' => true,
        ]);

        $this->getJson('/api/hall-of-fame')->assertJsonPath('recent.0.name', 'lucky_one')->assertJsonPath('recent.0.profile', '/player/lucky_one');

        app(PlayerProfiles::class)->update($user->ID, ['visibility' => 'private']);
        $this->getJson('/api/hall-of-fame')->assertJsonPath('recent.0.profile', null);
    }

    public function test_live_chat_comments_and_revealed_winners_carry_the_link(): void
    {
        $user = $this->player('chatty');
        $raffle = \App\Models\Raffle::create(['legacy_post_id' => 901, 'title' => 'Live', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        $comment = \App\Models\LiveDrawComment::create(['raffle_id' => $raffle->id, 'user_id' => $user->ID, 'body' => 'hello']);

        $this->assertSame('chatty', $comment->fresh()->toBroadcastArray()['user_name']);
        $this->assertSame('/player/chatty', $comment->fresh()->toBroadcastArray()['profile']);

        app(PlayerProfiles::class)->update($user->ID, ['visibility' => 'private']);
        $this->assertNull($comment->fresh()->toBroadcastArray()['profile']);

        $winner = \App\Models\Legacy\RaffleWinner::create([
            'raffle_id' => 901, 'user_id' => $this->player('reveal_me')->ID, 'ticket_number' => 3, 'prize_name' => 'Cash', 'prize_rank' => 1,
            'prize_cash_value' => 1000, 'is_credited' => false, 'is_visible' => true,
        ]);
        $payload = app(\App\Services\LiveDrawService::class)->winnerPayload($winner);
        $this->assertSame('reveal_me', $payload['name']);
        $this->assertSame('/player/reveal_me', $payload['profile']);
    }

    public function test_the_card_shows_how_many_different_raffles_they_have_played(): void
    {
        $user = $this->player('regular');
        $txn = \App\Models\Legacy\RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);

        foreach ([[5, 1], [5, 2], [6, 1]] as [$raffle, $number]) { // two tickets in raffle 5, one in raffle 6
            \App\Models\Legacy\RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffle, 'ticket_number' => $number, 'txn_id' => $txn->id]);
        }

        $this->assertSame(2, app(PlayerProfiles::class)->card('regular')['raffles_entered']);
    }

    public function test_a_shared_card_link_previews_with_the_players_name_and_badge_count(): void
    {
        $user = $this->player('sharer');
        app(BadgeService::class)->award($user->ID, 'first_ticket');

        $html = $this->get('/player/sharer')->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="sharer on', $html);
        $this->assertStringContainsString('1 badges collected', $html);
    }

    public function test_a_private_or_unknown_card_previews_generically_and_never_confirms_the_account(): void
    {
        $user = $this->player('hidden_one');
        app(PlayerProfiles::class)->update($user->ID, ['visibility' => 'private']);

        $html = $this->get('/player/hidden_one')->getContent();

        // The address the visitor typed is echoed either way; nothing about the account is.
        $this->assertStringNotContainsString('hidden_one on', $html);
        $this->assertStringNotContainsString('badges collected', $html);
        $this->assertStringNotContainsString('raffles played', $html);
    }

    public function test_the_privacy_panel_gives_the_link_and_username_to_share(): void
    {
        $user = $this->actingAsWordPressUser();

        $this->getJson('/api/badges')
            ->assertJsonPath('privacy.username', $user->user_login)
            ->assertJsonPath('privacy.path', '/player/'.rawurlencode($user->user_login))
            ->assertJsonPath('privacy.url', url('/player/'.rawurlencode($user->user_login)));
    }
}
