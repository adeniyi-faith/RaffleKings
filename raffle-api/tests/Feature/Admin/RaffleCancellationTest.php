<?php

namespace Tests\Feature\Admin;

use App\Exceptions\NoEligibleEntriesException;
use App\Filament\Resources\RaffleResource\Pages\ListRaffles;
use App\Models\CustomerMessage;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\Wallet;
use App\Services\ProvablyFairDrawService;
use App\Services\RaffleCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class RaffleCancellationTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    private function buyer(): WpUser
    {
        $user = WpUser::create(['user_login' => 'b'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Buyer']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        return $user;
    }

    private function purchase(WpUser $user, Raffle $raffle, array $numbers, float $paid, string $type = 'ticket_purchase_wallet'): RaffleTransaction
    {
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => $paid, 'status' => 'verified_final', 'type' => $type, 'created_at' => now()]);

        foreach ($numbers as $n) {
            RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => $n, 'txn_id' => $txn->id]);
        }

        return $txn;
    }

    public function test_everyone_gets_back_exactly_what_they_paid_where_they_paid_from(): void
    {
        $admin = $this->actingAsAdministrator();
        $raffle = $this->createRaffle(['title' => 'Generator', 'price' => 500]);
        [$ada, $bola] = [$this->buyer(), $this->buyer()];

        $walletBuy = $this->purchase($ada, $raffle, [1, 2], 900);
        $this->purchase($ada, $raffle, [3], 500, 'ticket_purchase_earnings');
        $this->purchase($bola, $raffle, [4], 500, 'ticket_purchase');
        RaffleEntry::create(['user_id' => $bola->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 5, 'txn_id' => 0]); // old-site ticket, no purchase record

        app(RaffleCancellationService::class)->cancel($admin, $raffle, 'Supplier could not deliver.');

        $this->assertEquals([900, 500], [Wallet::where('user_id', $ada->ID)->value('wallet_balance'), Wallet::where('user_id', $ada->ID)->value('earnings_balance')]);
        $this->assertEquals([1000, 0], [Wallet::where('user_id', $bola->ID)->value('wallet_balance'), Wallet::where('user_id', $bola->ID)->value('earnings_balance')]);
        $this->assertSame(0, RaffleEntry::query()->where('raffle_id', $raffle->public_id)->count());
        $this->assertSame('refunded', $walletBuy->refresh()->status);

        $raffle->refresh();
        $this->assertSame('refunded', $raffle->refund_status);
        $this->assertSame(2, $raffle->refunded_customers);
        $this->assertEquals(2400, $raffle->refunded_total);
        $this->assertSame('cancelled', $raffle->closedReason());
        $this->assertStringContainsString('Supplier could not deliver.', CustomerMessage::where('user_id', $ada->ID)->value('body'));
    }

    public function test_an_interrupted_refund_carries_on_without_paying_anyone_twice(): void
    {
        $admin = $this->actingAsAdministrator();
        $raffle = $this->createRaffle(['price' => 100]);
        $buyers = collect(range(1, 5))->map(fn () => $this->buyer());
        $buyers->each(fn ($b, $i) => $this->purchase($b, $raffle, [$i + 1], 100));

        // Only the cancel, not the background refunds.
        \Illuminate\Support\Facades\Bus::fake();
        app(RaffleCancellationService::class)->cancel($admin, $raffle, 'Test');
        $service = app(RaffleCancellationService::class);

        $this->assertSame(2, $service->refundBatch($raffle->refresh(), 2));
        $this->assertSame(3, $service->refundBatch($raffle->refresh(), 10));
        $this->assertSame(0, $service->refundBatch($raffle->refresh(), 10));
        $this->assertSame(0, $service->refundBatch($raffle->refresh(), 10));

        $buyers->each(fn ($b) => $this->assertEquals(100, Wallet::where('user_id', $b->ID)->value('wallet_balance')));
        $this->assertSame('refunded', $raffle->refresh()->refund_status);
    }

    public function test_it_is_refused_after_the_draw_and_a_cancelled_raffle_is_never_drawn_or_sold(): void
    {
        $admin = $this->actingAsAdministrator();
        $drawn = $this->createRaffle();
        RaffleWinner::create(['raffle_id' => $drawn->public_id, 'user_id' => 1, 'ticket_number' => 1, 'prize_name' => 'Car']);

        try {
            app(RaffleCancellationService::class)->cancel($admin, $drawn, 'x');
            $this->fail('Should be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('draw has already run', $e->getMessage());
        }

        $raffle = $this->createRaffle();
        \Illuminate\Support\Facades\Bus::fake();
        app(RaffleCancellationService::class)->cancel($admin, $raffle, 'x');
        $raffle->refresh()->update(['status' => 'published']); // someone tries to reopen it

        $this->assertSame('cancelled', $raffle->refresh()->closedReason());
        $this->expectException(NoEligibleEntriesException::class);
        app(ProvablyFairDrawService::class)->runDraw($raffle);
    }

    public function test_staff_cancel_from_the_raffle_list(): void
    {
        $this->actingAsAdministrator();
        $raffle = $this->createRaffle(['price' => 200]);
        $buyer = $this->buyer();
        $this->purchase($buyer, $raffle, [9], 200);

        Livewire::test(ListRaffles::class)
            ->callTableAction('cancelAndRefund', $raffle, ['reason' => 'Prize unavailable'])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(200, Wallet::where('user_id', $buyer->ID)->value('wallet_balance'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'raffle.cancelled', 'subject_id' => $raffle->id]);
    }
}
