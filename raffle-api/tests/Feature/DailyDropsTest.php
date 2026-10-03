<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyDropResource\Pages\CreateDailyDrop;
use App\Filament\Resources\DailyDropResource\Pages\EditDailyDrop;
use App\Filament\Resources\DailyDropResource\Pages\ListDailyDrops;
use App\Models\DailyDrop;
use App\Models\DailyDropRun;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Notifications\DailyDropWon;
use App\Services\Engagement\DailyDrops;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class DailyDropsTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    private int $ticket = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['raffles.timezone' => 'Africa/Lagos']);
        // 10:00 in Lagos, well before the 20:00 drop.
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Africa/Lagos'));
    }

    private function player(): WpUser
    {
        return WpUser::create(['user_login' => uniqid('p'), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Player', 'user_registered' => now()]);
    }

    /** One paid order of $count tickets. */
    private function buy(Raffle $raffle, WpUser $who, int $count, float $paid): void
    {
        $txn = RaffleTransaction::create(['user_id' => $who->ID, 'claimed_amount' => $paid, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet', 'created_at' => now()]);
        for ($i = 0; $i < $count; $i++) {
            RaffleEntry::create(['user_id' => $who->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => ++$this->ticket, 'txn_id' => $txn->id]);
        }
    }

    private function runningDrop(Raffle $raffle, array $attrs = []): DailyDrop
    {
        $drop = DailyDrop::create($attrs + ['raffle_id' => $raffle->id, 'status' => 'draft', 'pot_percent' => 10, 'winners_per_day' => 2, 'drop_time' => '20:00']);

        return app(DailyDrops::class)->activate($drop, $this->actingAsAdministrator());
    }

    private function earnings(WpUser $who): float
    {
        return (float) Wallet::query()->where('user_id', $who->ID)->value('earnings_balance');
    }

    public function test_it_pays_a_share_of_new_sales_to_random_ticket_holders_at_drop_time(): void
    {
        $raffle = $this->createRaffle(['price' => 500, 'max' => 1000]);
        $drop = $this->runningDrop($raffle);
        $lockedHash = $drop->next_seed_hash;
        [$ada, $bola, $chi] = [$this->player(), $this->player(), $this->player()];
        $this->travel(1)->minutes();
        $this->buy($raffle, $ada, 10, 5000);
        $this->buy($raffle, $bola, 6, 3000);
        $this->buy($raffle, $chi, 4, 2000);

        $this->travelTo(Carbon::parse('2026-10-05 19:59', 'Africa/Lagos'));
        $this->assertSame(0, app(DailyDrops::class)->runDue());

        $this->travelTo(Carbon::parse('2026-10-05 20:01', 'Africa/Lagos'));
        $this->assertSame(1, app(DailyDrops::class)->runDue());
        $this->assertSame(0, app(DailyDrops::class)->runDue(), 'a day is only ever paid once');

        $run = DailyDropRun::query()->sole();
        $this->assertSame('paid', $run->result);
        $this->assertEquals(10000, $run->sales_counted);
        $this->assertEquals(1000, $run->pot); // 10% of ₦10,000, split between 2 people
        $this->assertCount(2, $run->winners);
        $this->assertCount(2, array_unique(array_column($run->winners, 'user_id')), 'one share per person');
        $this->assertSame($lockedHash, $run->seed_hash, 'the pick used the seed locked in before the drop');

        $paidTotal = $this->earnings($ada) + $this->earnings($bola) + $this->earnings($chi);
        $this->assertEquals(1000, $paidTotal);
        $this->assertEquals(1000, WalletLedgerEntry::query()->where('reason', 'daily_drop')->where('balance_type', 'earnings')->sum('amount'));
        Notification::assertSentTimes(DailyDropWon::class, 2);

        // Anyone can redo the pick from the revealed seed and the ticket list.
        $pool = RaffleEntry::query()->where('raffle_id', $raffle->public_id)->orderBy('ticket_number')->get(['user_id', 'ticket_number'])
            ->map(fn ($e) => ['user_id' => (int) $e->user_id, 'ticket_number' => (int) $e->ticket_number])->all();
        $this->assertTrue(app(DailyDrops::class)->verify($run, $pool));

        // The next drop has a new locked seed, and only counts sales from now on.
        $drop->refresh();
        $this->assertNotSame($lockedHash, $drop->next_seed_hash);
        $this->travelTo(Carbon::parse('2026-10-06 20:05', 'Africa/Lagos'));
        app(DailyDrops::class)->runDue();
        $this->assertSame('no_sales', DailyDropRun::query()->latest('id')->first()->result);
        $this->assertEquals(1000, WalletLedgerEntry::query()->where('reason', 'daily_drop')->sum('amount'));
    }

    public function test_one_person_wins_at_most_one_share_and_the_cap_is_respected(): void
    {
        $raffle = $this->createRaffle(['price' => 1000, 'max' => 1000]);
        $this->runningDrop($raffle, ['winners_per_day' => 3, 'pot_percent' => 50, 'daily_cap' => 2500]);
        $only = $this->player();
        $this->travel(1)->minutes();
        $this->buy($raffle, $only, 20, 20000);

        $this->travelTo(Carbon::parse('2026-10-05 20:30', 'Africa/Lagos'));
        app(DailyDrops::class)->runDue();

        $run = DailyDropRun::query()->sole();
        $this->assertCount(1, $run->winners);
        $this->assertEquals(2500, $run->pot);
        $this->assertEquals(2500, $this->earnings($only));
    }

    public function test_a_paused_drop_and_a_draft_raffle_pay_nothing(): void
    {
        $raffle = $this->createRaffle(['price' => 100]);
        $drop = $this->runningDrop($raffle);
        $this->buy($raffle, $this->player(), 5, 500);
        app(DailyDrops::class)->pause($drop, $this->actingAsAdministrator());

        $draft = $this->createRaffle(['price' => 100], 'draft');
        $this->runningDrop($draft);

        $this->travelTo(Carbon::parse('2026-10-05 21:00', 'Africa/Lagos'));
        app(DailyDrops::class)->runDue();

        $this->assertSame(0, DailyDropRun::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->where('reason', 'daily_drop')->count());
    }

    public function test_a_cancelled_raffles_drop_ends_without_paying(): void
    {
        $raffle = $this->createRaffle(['price' => 100]);
        $drop = $this->runningDrop($raffle);
        $this->travel(1)->minutes();
        $this->buy($raffle, $this->player(), 5, 500);
        $raffle->update(['cancelled_at' => now()]);

        $this->travelTo(Carbon::parse('2026-10-05 21:00', 'Africa/Lagos'));
        app(DailyDrops::class)->runDue();

        $this->assertSame('ended', $drop->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('reason', 'daily_drop')->count());
    }

    public function test_a_drop_needs_a_raffle_before_it_can_run(): void
    {
        $drop = DailyDrop::create(['status' => 'draft', 'pot_percent' => 10, 'winners_per_day' => 1, 'drop_time' => '20:00']);

        $this->expectException(RuntimeException::class);
        app(DailyDrops::class)->activate($drop, $this->actingAsAdministrator());
    }

    public function test_the_raffle_page_shows_the_drop_without_names(): void
    {
        $raffle = $this->createRaffle(['price' => 500, 'max' => 1000]);
        $this->runningDrop($raffle);
        $this->travel(1)->minutes();
        $this->buy($raffle, $this->player(), 4, 2000);

        $this->get("/raffles/{$raffle->public_id}")->assertInertia(fn ($page) => $page
            ->where('dailyDrop.active', true)
            ->where('dailyDrop.pot_so_far', 200)
            ->where('dailyDrop.drop_time', '20:00')
            ->where('dailyDrop.winners_per_day', 2));

        $this->travelTo(Carbon::parse('2026-10-05 20:30', 'Africa/Lagos'));
        app(DailyDrops::class)->runDue();

        $this->get("/raffles/{$raffle->public_id}")->assertInertia(fn ($page) => $page
            ->has('dailyDrop.recent', 1)
            ->missing('dailyDrop.recent.0.tickets.0.user_id'));
    }

    public function test_only_staff_who_can_pay_money_can_switch_a_drop_on(): void
    {
        $raffle = $this->createRaffle();
        $drop = DailyDrop::create(['raffle_id' => $raffle->id, 'status' => 'draft', 'pot_percent' => 10, 'winners_per_day' => 1, 'drop_time' => '20:00']);
        $this->actingAsAdministrator();

        Livewire::test(ListDailyDrops::class)
            ->assertTableActionVisible('activate', $drop)
            ->callTableAction('activate', $drop);

        $this->assertSame('active', $drop->fresh()->status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'daily_drop.activated', 'subject_id' => $drop->id]);
    }

    public function test_staff_set_up_a_drop_as_a_draft_from_the_admin(): void
    {
        $raffle = $this->createRaffle(['title' => 'iPhone 17']);
        $this->actingAsAdministrator();

        Livewire::test(CreateDailyDrop::class)
            ->fillForm(['raffle_id' => $raffle->id, 'pot_percent' => 5, 'winners_per_day' => 4, 'drop_time' => '21:00', 'daily_cap' => 10000])
            ->call('create')
            ->assertHasNoFormErrors();

        $drop = DailyDrop::query()->sole();
        $this->assertSame('draft', $drop->status);
        $this->assertSame(4, $drop->winners_per_day);

        Livewire::test(EditDailyDrop::class, ['record' => $drop->getKey()])->assertSee('How it works');
        Livewire::test(ListDailyDrops::class)->assertSee('iPhone 17')->assertSee('5% of sales to 4 winner(s) at 21:00');
    }
}
