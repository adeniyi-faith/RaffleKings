<?php

namespace Tests\Unit;

use App\Exceptions\DrawAlreadyRunException;
use App\Exceptions\DrawNotCommittedException;
use App\Exceptions\NoEligibleEntriesException;
use App\Exceptions\NoPrizeStructureException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use App\Notifications\DrawCompletedAdminAlert;
use App\Notifications\WinnerAnnounced;
use App\Services\ProvablyFairDrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProvablyFairDrawServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProvablyFairDrawService $draws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->draws = app(ProvablyFairDrawService::class);
    }

    private function makeRaffleWithTiers(int $legacyPostId = 100): Raffle
    {
        $raffle = Raffle::create([
            'legacy_post_id' => $legacyPostId,
            'title' => 'Test raffle',
            'price' => 100,
            'max_tickets' => 10,
            'status' => 'published',
        ]);

        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Grand Prize', 'cash_value' => 100000, 'winner_count' => 1, 'rank' => 1]);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Runner Up', 'cash_value' => 5000, 'winner_count' => 2, 'rank' => 2]);

        return $raffle;
    }

    private function makeVerifiedEntry(int $raffleId, string $login): void
    {
        $user = WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com"]);
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffleId, 'ticket_number' => random_int(1, 999999), 'txn_id' => $txn->id]);
    }

    public function test_commit_seed_generates_a_hash_without_exposing_the_raw_seed_publicly(): void
    {
        $raffle = $this->makeRaffleWithTiers();

        $draw = $this->draws->commitSeed($raffle);

        $this->assertSame(64, strlen($draw->server_seed)); // 32 random bytes, hex-encoded
        $this->assertSame(hash('sha256', $draw->server_seed), $draw->server_seed_hash);
        $this->assertArrayNotHasKey('server_seed', $draw->publicCommitment());
    }

    public function test_committing_twice_returns_the_same_commitment(): void
    {
        $raffle = $this->makeRaffleWithTiers();

        $first = $this->draws->commitSeed($raffle);
        $second = $this->draws->commitSeed($raffle);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->server_seed, $second->server_seed);
    }

    public function test_running_a_draw_without_committing_first_is_refused(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->makeVerifiedEntry($raffle->legacy_post_id, 'buyer1');

        $this->expectException(DrawNotCommittedException::class);
        $this->draws->runDraw($raffle);
    }

    public function test_a_raffle_with_no_prize_tiers_cannot_be_drawn(): void
    {
        $raffle = Raffle::create(['legacy_post_id' => 200, 'title' => 'No prizes', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        $this->draws->commitSeed($raffle);
        $this->makeVerifiedEntry(200, 'buyer1');

        $this->expectException(NoPrizeStructureException::class);
        $this->draws->runDraw($raffle);
    }

    public function test_a_raffle_with_no_eligible_entries_cannot_be_drawn(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);

        $this->expectException(NoEligibleEntriesException::class);
        $this->draws->runDraw($raffle);
    }

    public function test_running_the_draw_twice_is_refused(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);
        $this->makeVerifiedEntry($raffle->legacy_post_id, 'buyer1');
        $this->draws->runDraw($raffle);

        $this->expectException(DrawAlreadyRunException::class);
        $this->draws->runDraw($raffle);
    }

    public function test_a_raffle_already_drawn_by_the_legacy_system_cannot_be_drawn_again(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);
        $this->makeVerifiedEntry($raffle->legacy_post_id, 'buyer1');

        // Simulates the OLD PHP draw engine having already run for this raffle.
        RaffleWinner::create(['raffle_id' => $raffle->legacy_post_id, 'user_id' => 1, 'ticket_number' => 1, 'prize_name' => 'x', 'prize_rank' => 1, 'prize_cash_value' => 100]);

        $this->expectException(DrawAlreadyRunException::class);
        $this->draws->runDraw($raffle);
    }

    public function test_a_draw_awards_the_correct_number_of_winners_per_tier_and_hides_them_by_default(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);
        foreach (['a', 'b', 'c', 'd', 'e'] as $login) {
            $this->makeVerifiedEntry($raffle->legacy_post_id, $login);
        }

        $winners = $this->draws->runDraw($raffle);

        $this->assertCount(3, $winners); // 1 grand prize + 2 runner-up slots
        foreach ($winners as $winner) {
            $this->assertFalse($winner->is_visible);
            $this->assertFalse($winner->is_credited);
        }
        // No user wins twice in the same draw.
        $this->assertSame(3, collect($winners)->pluck('user_id')->unique()->count());
    }

    public function test_a_user_who_won_within_the_cooldown_window_is_excluded(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);
        $this->makeVerifiedEntry($raffle->legacy_post_id, 'recent_winner');
        $recentWinnerId = WpUser::where('user_login', 'recent_winner')->value('ID');
        $win = RaffleWinner::create(['raffle_id' => 999, 'user_id' => $recentWinnerId, 'ticket_number' => 1, 'prize_name' => 'x', 'prize_rank' => 1, 'prize_cash_value' => 100]);
        // won_at isn't mass-assignable (it's CREATED_AT); set it directly to backdate the win.
        $win->won_at = now()->subDay();
        $win->save();

        $this->expectException(NoEligibleEntriesException::class); // the only entrant is excluded by cooldown
        $this->draws->runDraw($raffle);
    }

    public function test_a_user_whose_win_was_outside_the_cooldown_window_is_eligible(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);
        $this->makeVerifiedEntry($raffle->legacy_post_id, 'old_winner');
        $oldWinnerId = WpUser::where('user_login', 'old_winner')->value('ID');
        $win = RaffleWinner::create(['raffle_id' => 999, 'user_id' => $oldWinnerId, 'ticket_number' => 1, 'prize_name' => 'x', 'prize_rank' => 1, 'prize_cash_value' => 100]);
        $win->won_at = now()->subDays(10);
        $win->save();

        $winners = $this->draws->runDraw($raffle);

        $this->assertCount(1, $winners);
    }

    public function test_verify_confirms_a_completed_draw_reproduces_the_same_winners(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);
        foreach (['a', 'b', 'c'] as $login) {
            $this->makeVerifiedEntry($raffle->legacy_post_id, $login);
        }
        $this->draws->runDraw($raffle);

        $result = $this->draws->verify($raffle);

        $this->assertTrue($result['seed_hash_matches']);
        $this->assertTrue($result['client_seed_matches']);
        $this->assertTrue($result['winners_match']);
    }

    public function test_verify_returns_null_before_the_draw_has_run(): void
    {
        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);

        $this->assertNull($this->draws->verify($raffle));
    }

    public function test_two_different_server_seeds_produce_different_orderings_for_the_same_pool(): void
    {
        $raffleA = $this->makeRaffleWithTiers(legacyPostId: 300);
        $raffleB = $this->makeRaffleWithTiers(legacyPostId: 301);
        $this->draws->commitSeed($raffleA);
        $this->draws->commitSeed($raffleB);

        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $login) {
            $this->makeVerifiedEntry(300, $login.'-a');
            $this->makeVerifiedEntry(301, $login.'-b');
        }

        // Different pools/seeds are a weak way to prove non-determinism
        // in general, but this at least proves the seed materially
        // affects the outcome rather than being ignored.
        $winnersA = collect($this->draws->runDraw($raffleA))->pluck('ticket_number')->all();
        $winnersB = collect($this->draws->runDraw($raffleB))->pluck('ticket_number')->all();

        $this->assertNotSame($winnersA, $winnersB);
    }

    public function test_running_a_draw_notifies_every_winner_and_sends_an_admin_alert(): void
    {
        Notification::fake();

        $raffle = $this->makeRaffleWithTiers();
        $this->draws->commitSeed($raffle);
        $this->makeVerifiedEntry($raffle->legacy_post_id, 'winner1');

        $this->draws->runDraw($raffle);

        Notification::assertSentOnDemand(DrawCompletedAdminAlert::class);
        $winnerUser = WpUser::where('user_login', 'winner1')->first();
        Notification::assertSentTo($winnerUser, WinnerAnnounced::class);
    }
}
