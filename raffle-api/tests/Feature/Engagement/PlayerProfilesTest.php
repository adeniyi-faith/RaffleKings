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
}
