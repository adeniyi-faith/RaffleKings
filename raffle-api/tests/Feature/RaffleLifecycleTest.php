<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpPost;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use App\Models\Wallet;
use App\Services\TicketPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 43 — the native raffles table is the public
 * site's only source: raffles made in the admin appear, get a number that
 * can never collide with an old raffle's tickets, close by themselves at
 * the end of their last day, and can't be bought when closed or at a
 * price the customer made up.
 */
class RaffleLifecycleTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function buy(int $raffleId, array $numbers, float $unitPrice, float $amount, bool $goldenBox = false)
    {
        return $this->postJson('/api/tickets/purchase', [
            'raffle_id' => $raffleId,
            'ticket_numbers' => $numbers,
            'unit_price' => $unitPrice,
            'is_golden_box' => $goldenBox,
            'submitted_amount' => $amount,
            'funding_source' => 'wallet',
            'idempotency_key' => 'key-'.uniqid(),
        ]);
    }

    private function customerWith(float $balance)
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => $balance, 'earnings_balance' => 0]);

        return $user;
    }

    public function test_a_raffle_created_in_the_admin_appears_on_the_site(): void
    {
        $raffle = Raffle::create(['title' => 'Made in the new admin', 'price' => 200, 'max_tickets' => 50, 'status' => 'published']);

        $this->getJson('/api/raffles')->assertJsonFragment(['title' => 'Made in the new admin']);
        $this->get("/raffles/{$raffle->public_id}")->assertOk();
    }

    public function test_a_new_raffle_gets_a_number_no_old_raffle_or_ticket_ever_used(): void
    {
        WpPost::create(['ID' => 500, 'post_title' => 'Old page', 'post_type' => 'page', 'post_status' => 'publish', 'post_date' => now()]);
        RaffleEntry::create(['user_id' => 1, 'raffle_id' => 900, 'ticket_number' => 1, 'txn_id' => 1]);

        $raffle = Raffle::create(['title' => 'New', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);

        $this->assertSame(901, $raffle->public_id);
        $this->assertSame(0, $raffle->soldTickets(), 'Tickets from old raffle 900 must never count toward the new one.');
    }

    public function test_drafts_are_hidden_from_customers(): void
    {
        $raffle = $this->createRaffle(['title' => 'Secret draft'], 'draft');

        $this->getJson('/api/raffles')->assertJsonMissing(['title' => 'Secret draft']);
        $this->getJson("/api/raffles/{$raffle->public_id}")->assertNotFound();
    }

    public function test_a_raffle_stays_on_sale_until_the_end_of_its_last_day_in_lagos(): void
    {
        $this->travelTo(now('Africa/Lagos')->setTime(22, 30)); // 10:30pm Lagos, 9:30pm UTC
        $raffle = $this->createRaffle(['expiry' => now('Africa/Lagos')->toDateString()]);

        $this->getJson("/api/raffles/{$raffle->public_id}")->assertJson(['is_closed' => false, 'closed_reason' => null]);

        $this->travelTo(now('Africa/Lagos')->addDay()->setTime(0, 5));

        $this->getJson("/api/raffles/{$raffle->public_id}")->assertJson(['is_closed' => true, 'closed_reason' => 'ended']);
    }

    public function test_an_ended_raffle_cannot_be_bought_and_nothing_is_charged(): void
    {
        $user = $this->customerWith(1000);
        $raffle = $this->createRaffle(['price' => '100', 'expiry' => now()->subDays(2)->toDateString()]);

        $this->buy($raffle->public_id, [1], 100, 100)
            ->assertStatus(409)
            ->assertJson(['closed_reason' => 'ended']);

        $this->assertEquals(1000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleEntry::count());
    }

    public function test_draft_and_admin_closed_raffles_cannot_be_bought(): void
    {
        $this->customerWith(1000);
        $draft = $this->createRaffle(['price' => '100'], 'draft');
        $closed = $this->createRaffle(['price' => '100', 'is_sold_out' => '1']);

        $this->buy($draft->public_id, [1], 100, 100)->assertStatus(409);
        $this->buy($closed->public_id, [1], 100, 100)->assertStatus(409)->assertJson(['closed_reason' => 'closed']);
        $this->assertSame(0, RaffleEntry::count());
    }

    public function test_a_customer_cannot_choose_their_own_ticket_price(): void
    {
        $user = $this->customerWith(1000);
        $raffle = $this->createRaffle(['price' => '500']);

        // A ₦500 ticket "bought" at ₦0.01 — used to go through.
        $this->buy($raffle->public_id, [1], 0.01, 0.01)->assertStatus(422);

        $this->assertEquals(1000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleEntry::count());
    }

    public function test_the_golden_box_discount_cannot_be_self_awarded(): void
    {
        $this->customerWith(10000);
        $raffle = $this->createRaffle(['price' => '1000']);
        $goldenPrice = app(TicketPricingService::class)->calculate(1, 1000, true);

        $this->buy($raffle->public_id, [1], 1000, $goldenPrice, goldenBox: true)->assertStatus(422);
        $this->assertSame(0, RaffleEntry::count());
    }

    public function test_ticket_numbers_must_be_in_range_and_not_repeated(): void
    {
        $this->customerWith(10000);
        $raffle = $this->createRaffle(['price' => '100', 'max' => '10']);
        $twoTickets = app(TicketPricingService::class)->calculate(2, 100, false);

        $this->buy($raffle->public_id, [5, 11], 100, $twoTickets)->assertStatus(422);
        $this->buy($raffle->public_id, [5, 5], 100, $twoTickets)->assertStatus(422);
        $this->assertSame(0, RaffleEntry::count());
    }

    public function test_a_normal_purchase_still_works(): void
    {
        $user = $this->customerWith(1000);
        $raffle = $this->createRaffle(['price' => '100', 'max' => '10']);

        $this->buy($raffle->public_id, [3], 100, 100)->assertCreated();

        $this->assertSame(1, $raffle->soldTickets());
        $this->assertEquals(900, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_open_raffles_are_listed_before_closed_ones(): void
    {
        $this->createRaffle(['title' => 'Ended yesterday', 'expiry' => now()->subDay()->toDateString()]);
        $this->createRaffle(['title' => 'Still open']);

        $titles = collect($this->getJson('/api/raffles')->json('raffles'))->pluck('title')->all();

        $this->assertSame(['Still open', 'Ended yesterday'], $titles);
    }

    public function test_long_finished_raffles_drop_off_the_list(): void
    {
        config(['raffles.list_closed_for_days' => 14]);
        $this->createRaffle(['title' => 'Ended last month', 'expiry' => now()->subDays(30)->toDateString()]);

        $this->getJson('/api/raffles')->assertJsonMissing(['title' => 'Ended last month']);
    }

    public function test_the_number_picker_for_a_closed_raffle_goes_to_the_raffle_page(): void
    {
        $this->actingAsWordPressUser();
        $raffle = $this->createRaffle(['expiry' => now()->subDays(2)->toDateString()]);

        $this->get("/raffles/{$raffle->public_id}/numbers?qty=1")->assertRedirect("/raffles/{$raffle->public_id}");
    }

    public function test_what_you_can_win_falls_back_to_the_prize_tiers(): void
    {
        $raffle = $this->createRaffle(['grand_prize' => '₦100,000']);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Grand Prize', 'cash_value' => 100000, 'winner_count' => 1, 'rank' => 1]);
        RafflePrizeTier::create(['raffle_id' => $raffle->id, 'tier_name' => 'Runner-up', 'cash_value' => 5000, 'winner_count' => 3, 'rank' => 2]);

        $this->getJson("/api/raffles/{$raffle->public_id}")
            ->assertJson(['prize_list' => ['Runner-up: ₦5,000 × 3 winners']]);
    }
}
