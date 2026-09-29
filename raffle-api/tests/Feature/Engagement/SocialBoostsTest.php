<?php

namespace Tests\Feature\Engagement;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\RaffleBonusEntry;
use App\Models\RaffleTeam;
use App\Models\UnlockLink;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\SocialBoosts;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class SocialBoostsTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function player(string $login, ?int $ticketIn = null): WpUser
    {
        $user = WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com", 'display_name' => ucfirst($login).' Surname']);

        if ($ticketIn) {
            $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
            RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $ticketIn, 'ticket_number' => $user->ID, 'txn_id' => $txn->id]);
        }

        return $user;
    }

    public function test_three_friend_taps_unlock_a_bonus_entry_and_pay_the_friends(): void
    {
        config(['engagement.unlock.taps_needed' => 3, 'engagement.unlock.tapper_points' => 20]);
        $this->createRaffle(['public_id' => 5]);
        $owner = $this->player('ada', 5);
        $boosts = app(SocialBoosts::class);

        $this->expectExceptionOnce(fn () => $boosts->createUnlock($this->player('noticket'), 5), 'Buy at least one ticket');
        $link = $boosts->createUnlock($owner, 5, '1.1.1.1');
        $this->assertSame($link->id, $boosts->createUnlock($owner, 5)->id); // one link per raffle

        $this->expectExceptionOnce(fn () => $boosts->tap($owner, $link->code), 'your own link');
        $this->expectExceptionOnce(fn () => $boosts->tap($this->player('sameip'), $link->code, '1.1.1.1'), 'own phones');

        $friends = [$this->player('bola'), $this->player('chidi'), $this->player('dayo')];
        $boosts->tap($friends[0], $link->code, '2.2.2.2');
        $this->expectExceptionOnce(fn () => $boosts->tap($friends[0], $link->code, '2.2.2.2'), 'already helped');
        $boosts->tap($friends[1], $link->code, '3.3.3.3');
        $result = $boosts->tap($friends[2], $link->code, '4.4.4.4');

        $this->assertTrue($result['link']['completed']);
        $this->assertSame(20, app(PointsService::class)->balance($friends[2]));
        $this->assertSame(1, (int) RaffleBonusEntry::query()->where('user_id', $owner->ID)->where('reason', 'unlock')->value('entries'));
        $this->assertTrue(app(BadgeService::class)->has($owner->ID, 'unlocker'));
        $this->expectExceptionOnce(fn () => $boosts->tap($this->player('late'), $link->code), 'Already unlocked');
    }

    public function test_a_full_team_gets_everyone_a_bonus_entry(): void
    {
        config(['engagement.teams.size' => 3]);
        $this->createRaffle(['public_id' => 5]);
        $captain = $this->player('cap', 5);
        $boosts = app(SocialBoosts::class);
        $team = $boosts->createTeam($captain, 5);

        $this->expectExceptionOnce(fn () => $boosts->join($this->player('noticket'), $team->code), 'Buy at least one ticket');
        $boosts->join($this->player('m1', 5), $team->code);
        $this->assertNull($team->fresh()->completed_at);
        $last = $this->player('m2', 5);
        $boosts->join($last, $team->code);

        $this->assertNotNull($team->fresh()->completed_at);
        $this->assertSame(3, RaffleBonusEntry::query()->where('reason', 'team')->where('raffle_id', 5)->count());
        $this->assertTrue(app(BadgeService::class)->has($captain->ID, 'team_captain'));
        $this->assertTrue(app(BadgeService::class)->has($last->ID, 'team_player'));
        $this->expectExceptionOnce(fn () => $boosts->join($this->player('m3', 5), $team->code), 'already full');
    }

    public function test_a_team_shows_each_persons_username_not_the_first_word_of_their_display_name(): void
    {
        $this->createRaffle(['public_id' => 5]);
        $captain = $this->player('john_a', 5);
        $captain->update(['display_name' => 'Mr John Adeyemi']);
        $boosts = app(SocialBoosts::class);
        $team = $boosts->createTeam($captain, 5);

        $view = $boosts->presentTeam($team);

        $this->assertSame('john_a', $view['captain']);
        $this->assertSame('john_a', $view['members'][0]['name']);
    }

    public function test_an_expired_team_cannot_be_joined(): void
    {
        $this->createRaffle(['public_id' => 5]);
        $team = app(SocialBoosts::class)->createTeam($this->player('cap', 5), 5);
        $this->travel(25)->hours();

        $this->expectExceptionOnce(fn () => app(SocialBoosts::class)->join($this->player('m1', 5), $team->code), 'ran out of time');
    }

    public function test_the_api_and_share_pages_work(): void
    {
        $this->createRaffle(['public_id' => 5]);
        $user = $this->actingAsWordPressUser();
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => 1, 'txn_id' => $txn->id]);

        $this->getJson('/api/raffles/5/boosts')->assertOk()->assertJsonPath('has_tickets', true)->assertJsonPath('unlock', null);
        $code = $this->postJson('/api/raffles/5/unlock')->assertOk()->json('code');
        $teamCode = $this->postJson('/api/raffles/5/team')->assertOk()->assertJsonPath('members.0.is_you', true)->json('code');

        $this->get("/u/{$code}")->assertOk();
        $this->get("/team/{$teamCode}")->assertOk();
        $this->postJson('/api/unlock/nope123/tap')->assertNotFound();
        $this->assertSame(1, UnlockLink::count());
        $this->assertSame(1, RaffleTeam::count());
    }

    private function expectExceptionOnce(callable $fn, string $messagePart): void
    {
        try {
            $fn();
            $this->fail("Expected an error containing \"{$messagePart}\".");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }
}
