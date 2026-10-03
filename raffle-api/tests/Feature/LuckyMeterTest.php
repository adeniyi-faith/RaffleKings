<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\LuckyMeter as Meter;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Notifications\LuckyMeterFilled;
use App\Services\Engagement\LuckyMeter;
use App\Services\ProvablyFairDrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class LuckyMeterTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private int $ticket = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['engagement.lucky_meter' => ['enabled' => true, 'target' => 10000, 'reward' => 500]]);
    }

    private function player(): WpUser
    {
        return WpUser::create(['user_login' => uniqid('p'), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Player', 'user_registered' => now()]);
    }

    /** One order of $count tickets. */
    private function buy(Raffle $raffle, WpUser $who, int $count, float $paid, string $status = 'verified_final'): void
    {
        $txn = RaffleTransaction::create(['user_id' => $who->ID, 'claimed_amount' => $paid, 'status' => $status, 'type' => 'ticket_purchase_wallet', 'created_at' => now()]);
        for ($i = 0; $i < $count; $i++) {
            RaffleEntry::create(['user_id' => $who->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => ++$this->ticket, 'txn_id' => $txn->id]);
        }
    }

    private function win(Raffle $raffle, WpUser $who): void
    {
        RaffleWinner::create(['raffle_id' => $raffle->public_id, 'user_id' => $who->ID, 'ticket_number' => 1, 'prize_name' => 'Prize', 'prize_rank' => 1, 'prize_cash_value' => 1000, 'is_credited' => false, 'is_visible' => false]);
    }

    private function credit(WpUser $who): float
    {
        return (float) Wallet::query()->where('user_id', $who->ID)->value('wallet_balance');
    }

    public function test_non_winners_fill_their_meter_and_a_full_meter_pays_ticket_credit(): void
    {
        $raffle = $this->createRaffle(['price' => 500]);
        [$winner, $big, $small] = [$this->player(), $this->player(), $this->player()];
        $this->buy($raffle, $winner, 10, 5000);
        $this->buy($raffle, $big, 20, 7000);
        $this->buy($raffle, $big, 10, 5000); // two orders: ₦12,000 in all
        $this->buy($raffle, $small, 4, 2000);
        $this->buy($raffle, $small, 2, 1000, 'pending'); // not paid, doesn't count
        $this->win($raffle, $winner);

        $this->assertTrue(app(LuckyMeter::class)->countRaffle($raffle));
        $this->assertFalse(app(LuckyMeter::class)->countRaffle($raffle), 'a raffle is only ever counted once');

        // ₦12,000 fills the meter once (₦500 credit) with ₦2,000 carried on.
        $this->assertEquals(500, $this->credit($big));
        $this->assertEquals(2000, Meter::find($big->ID)->progress);
        $this->assertSame(1, Meter::find($big->ID)->fills);
        $this->assertEquals(2000, Meter::find($small->ID)->progress);
        $this->assertEquals(0, $this->credit($small));
        $this->assertNull(Meter::find($winner->ID), 'winning a prize in the raffle means it does not fill the meter');

        $this->assertEquals(500, WalletLedgerEntry::query()->where('reason', 'lucky_meter')->where('balance_type', 'wallet')->sum('amount'));
        Notification::assertSentTo($big, LuckyMeterFilled::class);
        Notification::assertNotSentTo($small, LuckyMeterFilled::class);

        // The next raffle carries on from where the meter was.
        $next = $this->createRaffle(['price' => 1000]);
        $this->buy($next, $small, 8, 8000);
        $this->win($next, $this->player());
        app(LuckyMeter::class)->countRaffle($next);

        $this->assertEquals(500, $this->credit($small));
        $this->assertEquals(0, Meter::find($small->ID)->progress);
    }

    public function test_it_pays_nothing_while_switched_off_or_for_a_cancelled_or_undrawn_raffle(): void
    {
        $raffle = $this->createRaffle();
        $this->buy($raffle, $this->player(), 5, 20000);
        $this->assertFalse(app(LuckyMeter::class)->countRaffle($raffle), 'not drawn yet');

        $this->win($raffle, $this->player());
        config(['engagement.lucky_meter.enabled' => false]);
        $this->assertFalse(app(LuckyMeter::class)->countRaffle($raffle));

        config(['engagement.lucky_meter.enabled' => true]);
        $raffle->update(['cancelled_at' => now()]);
        $this->assertFalse(app(LuckyMeter::class)->countRaffle($raffle));

        $this->assertSame(0, Meter::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->where('reason', 'lucky_meter')->count());
    }

    public function test_a_real_draw_fills_the_meters_of_everyone_who_did_not_win(): void
    {
        $raffle = $this->createRaffle(['price' => 1000]);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Prize', 'cash_value' => 1000, 'winner_count' => 1, 'rank' => 1]);
        $players = [$this->player(), $this->player(), $this->player()];
        foreach ($players as $p) {
            $this->buy($raffle, $p, 10, 10000);
        }

        $draws = app(ProvablyFairDrawService::class);
        $draws->commitSeed($raffle);
        $winners = $draws->runDraw($raffle->fresh());

        $winnerId = (int) $winners[0]->user_id;
        foreach ($players as $p) {
            $this->assertEquals($p->ID === $winnerId ? 0 : 500, $this->credit($p));
        }
        $this->assertSame(2, (int) \DB::table('lucky_meter_raffles')->where('raffle_id', $raffle->id)->value('fills'));

        // The safety-net sweep finds nothing more to do.
        $this->assertSame(0, app(LuckyMeter::class)->countRecentDraws());
    }

    public function test_the_safety_net_counts_a_recent_draw_the_hook_missed(): void
    {
        $raffle = $this->createRaffle();
        $loser = $this->player();
        $this->buy($raffle, $loser, 5, 10000);
        $this->win($raffle, $this->player());

        $this->assertSame(1, app(LuckyMeter::class)->countRecentDraws());
        $this->assertEquals(500, $this->credit($loser));
    }

    public function test_the_rewards_page_shows_the_meter_only_while_it_is_on(): void
    {
        $user = $this->actingAsWordPressUser();
        Meter::create(['user_id' => $user->ID, 'progress' => 2500, 'fills' => 1, 'total_paid' => 500]);

        $this->get('/rewards')->assertInertia(fn ($page) => $page
            ->where('initialState.lucky_meter.percent', 25)
            ->where('initialState.lucky_meter.reward', 500)
            ->where('initialState.lucky_meter.fills', 1));

        config(['engagement.lucky_meter.enabled' => false]);
        $this->get('/rewards')->assertInertia(fn ($page) => $page->where('initialState.lucky_meter', null));
    }

    public function test_guests_see_the_rules(): void
    {
        $this->get('/rewards')->assertInertia(fn ($page) => $page
            ->where('preview.lucky_meter.target', 10000)
            ->where('preview.lucky_meter.progress', 0));
    }
}
