<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\BankTransferResource\Pages\ListBankTransfers;
use App\Filament\Resources\PaymentMismatchResource\Pages\ListPaymentMismatches;
use App\Filament\Resources\RaffleDrawResource\Pages\ListRaffleDraws;
use App\Filament\Resources\RaffleWinnerResource\Pages\ListRaffleWinners;
use App\Filament\Resources\SupportTicketResource\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Models\AdminAuditLog;
use App\Models\Deposit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Models\RafflePrizeTier;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Wallet;
use App\Notifications\SupportTicketReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 44 — the admin screens for daily operations,
 * exercised by clicking their real buttons as a signed-in administrator.
 */
class OperationsScreensTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function customer(): WpUser
    {
        return WpUser::create(['user_login' => 'c'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Customer']);
    }

    private function raffleWithTickets(int $sold = 3): Raffle
    {
        $raffle = Raffle::create(['legacy_post_id' => 700, 'title' => 'Cash Jackpot', 'price' => 100, 'max_tickets' => 10, 'status' => 'published', 'expiry' => now()->subDay()->toDateString()]);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Grand Prize', 'cash_value' => 5000, 'winner_count' => 1, 'rank' => 1]);

        // Only tickets backed by a confirmed payment can win.
        foreach (range(1, $sold) as $n) {
            $buyer = $this->customer();
            $txn = RaffleTransaction::create(['user_id' => $buyer->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet', 'created_at' => now()]);
            RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 700, 'ticket_number' => $n, 'txn_id' => $txn->id]);
        }

        return $raffle;
    }

    // ---- Bank transfers -------------------------------------------------

    public function test_approving_a_bank_transfer_top_up_credits_the_wallet(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        $txn = RaffleTransaction::create(['user_id' => $customer->ID, 'claimed_amount' => 2500, 'status' => 'manual_review', 'type' => 'wallet_deposit', 'created_at' => now()]);

        Livewire::test(ListBankTransfers::class)
            ->assertCanSeeTableRecords([$txn])
            ->assertTableActionHidden('creditWalletInstead', $txn)
            ->callTableAction('approve', $txn);

        $this->assertSame('verified_final', $txn->fresh()->status);
        $this->assertEquals(2500, (float) Wallet::where('user_id', $customer->ID)->value('wallet_balance'));
    }

    public function test_a_refused_ticket_approval_shows_the_reason_and_changes_nothing(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        RaffleEntry::create(['user_id' => 1, 'raffle_id' => 42, 'ticket_number' => 7, 'txn_id' => 99999]);
        $txn = RaffleTransaction::create(['user_id' => $customer->ID, 'claimed_amount' => 1000, 'status' => 'manual_review', 'type' => 'ticket_purchase', 'pending_raffle_id' => 42, 'pending_numbers' => '7', 'created_at' => now()]);

        Livewire::test(ListBankTransfers::class)
            ->callTableAction('approve', $txn)
            ->assertNotified('Not done');

        $this->assertSame('manual_review', $txn->fresh()->status);

        Livewire::test(ListBankTransfers::class)->callTableAction('creditWalletInstead', $txn);

        $this->assertSame('verified_final', $txn->fresh()->status);
        $this->assertEquals(1000, (float) Wallet::where('user_id', $customer->ID)->value('wallet_balance'));
    }

    // ---- Payment mismatches ---------------------------------------------

    public function test_a_mismatch_is_credited_with_the_amount_the_gateway_confirms_now(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        $deposit = Deposit::create(['user_id' => $customer->ID, 'reference' => 'dep_x1', 'gateway' => 'paystack', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'amount_mismatch', 'failure_reason' => 'Paid 4,000']);
        config(['services.paystack.secret_key' => 'sk_test']);
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 400000, 'currency' => 'NGN', 'id' => 9]])]);

        Livewire::test(ListPaymentMismatches::class)->callTableAction('credit', $deposit);

        $this->assertSame('successful', $deposit->fresh()->status);
        $this->assertEquals(4000, (float) Wallet::where('user_id', $customer->ID)->value('wallet_balance'));
        $this->assertEquals(5000, AdminAuditLog::where('action', 'deposit.mismatch_resolved_credited')->first()->context['expected_amount']);
    }

    // ---- Draws ----------------------------------------------------------

    public function test_the_draw_screen_walks_through_lock_then_generate(): void
    {
        Bus::fake();
        $this->actingAsAdministrator();
        $raffle = $this->raffleWithTickets();

        Livewire::test(ListRaffleDraws::class)
            ->assertTableActionVisible('lockSeed', $raffle)
            ->assertTableActionHidden('generateWinners', $raffle)
            ->callTableAction('lockSeed', $raffle);

        $this->assertNotNull(RaffleDraw::where('raffle_id', $raffle->id)->first());

        Livewire::test(ListRaffleDraws::class)
            ->assertTableActionHidden('lockSeed', $raffle)
            ->callTableAction('generateWinners', $raffle);

        $this->assertSame(1, RaffleWinner::where('raffle_id', 700)->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'draw.seed_locked')->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'draw.winners_generated')->count());
    }

    public function test_a_raffle_without_prize_tiers_explains_what_to_do(): void
    {
        $this->actingAsAdministrator();
        $raffle = $this->raffleWithTickets();
        $raffle->prizeTiers()->delete();
        RaffleDraw::create(['raffle_id' => $raffle->id, 'server_seed' => 'x', 'server_seed_hash' => hash('sha256', 'x'), 'committed_at' => now()]);

        Livewire::test(ListRaffleDraws::class)
            ->callTableAction('generateWinners', $raffle)
            ->assertNotified('Not done');

        $this->assertSame(0, RaffleWinner::count());
    }

    // ---- Winners --------------------------------------------------------

    public function test_paying_a_winner_credits_their_winnings_once(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        $winner = RaffleWinner::create(['raffle_id' => 700, 'user_id' => $customer->ID, 'ticket_number' => 3, 'prize_name' => 'Grand Prize', 'prize_rank' => 1, 'prize_cash_value' => 5000, 'is_credited' => false, 'is_visible' => false]);

        Livewire::test(ListRaffleWinners::class)
            ->assertCanSeeTableRecords([$winner])
            ->callTableAction('pay', $winner);

        // Paid winners leave the default "still to pay" view.
        Livewire::test(ListRaffleWinners::class)
            ->filterTable('is_credited', true)
            ->callTableAction('show', $winner);

        $winner->refresh();
        $this->assertTrue($winner->is_credited);
        $this->assertTrue($winner->is_visible);
        $this->assertEquals(5000, (float) Wallet::where('user_id', $customer->ID)->value('earnings_balance'));
    }

    // ---- Support --------------------------------------------------------

    public function test_staff_can_read_and_reply_to_a_ticket(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        $ticket = SupportTicket::create(['user_id' => $customer->ID, 'subject' => 'Where is my payout?', 'status' => 'open']);
        SupportTicketMessage::create(['support_ticket_id' => $ticket->id, 'author_id' => $customer->ID, 'is_from_admin' => false, 'message' => 'I won last week', 'created_at' => now()]);

        Livewire::test(ListSupportTickets::class)->assertCanSeeTableRecords([$ticket]);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->id])
            ->assertSee('I won last week')
            ->callAction('reply', data: ['message' => 'Paid today — check your winnings.', 'resolve' => true]);

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame(1, $ticket->messages()->where('is_from_admin', true)->count());
        Notification::assertSentTo($customer, SupportTicketReply::class);
        $this->assertSame(1, AdminAuditLog::where('action', 'support_ticket.replied')->count());
    }

    // ---- Access & menu --------------------------------------------------

    public function test_every_new_screen_loads_for_an_admin(): void
    {
        $this->actingAsAdministrator();

        foreach (['withdrawals', 'bank-transfers', 'payment-mismatches', 'draws', 'winners', 'support-tickets'] as $page) {
            $this->get("/admin/{$page}")->assertOk();
        }
    }

    public function test_customers_cannot_open_any_of_them(): void
    {
        $this->actingAsWordPressUser();

        foreach (['withdrawals', 'bank-transfers', 'payment-mismatches', 'draws', 'winners', 'support-tickets'] as $page) {
            $this->get("/admin/{$page}")->assertForbidden();
        }
    }
}
