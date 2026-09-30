<?php

namespace Tests\Feature;

use App\Exceptions\TicketUnavailableException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\NumberHold;
use App\Models\Raffle;
use App\Models\Wallet;
use App\Services\NumberHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * Number holds: a player's picked numbers are kept for them for a few
 * minutes while they sign in and pay, so nobody else can pay for them.
 */
class NumberHoldsTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private const TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Raffle $raffle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raffle = $this->createRaffle(['public_id' => 9, 'price' => '500', 'max' => '50']);
    }

    private function service(): NumberHoldService
    {
        return app(NumberHoldService::class);
    }

    private function otherUser(): WpUser
    {
        return WpUser::create(['user_login' => 'other_'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    // --- The service ---------------------------------------------------------

    public function test_a_guest_can_hold_numbers_for_ten_minutes(): void
    {
        $result = $this->service()->claim(9, [3, 4], null, self::TOKEN_A);

        $this->assertTrue($result['ok']);
        $this->assertEqualsWithDelta(600, now()->diffInSeconds($result['expires_at']), 2);
        $this->assertSame(2, NumberHold::count());
    }

    public function test_someone_else_cannot_hold_numbers_that_are_already_held(): void
    {
        $this->service()->claim(9, [3, 4], null, self::TOKEN_A);

        $result = $this->service()->claim(9, [4, 5], null, self::TOKEN_B);

        $this->assertFalse($result['ok']);
        $this->assertSame([4], $result['held']);
        // All or nothing: number 5 was free but is not held for them either.
        $this->assertSame(0, NumberHold::where('guest_token', self::TOKEN_B)->count());
    }

    public function test_sold_numbers_cannot_be_held(): void
    {
        RaffleEntry::create(['user_id' => 1, 'raffle_id' => 9, 'ticket_number' => 7, 'txn_id' => 1]);

        $result = $this->service()->claim(9, [7, 8], null, self::TOKEN_A);

        $this->assertFalse($result['ok']);
        $this->assertSame([7], $result['sold']);
    }

    public function test_asking_again_does_not_restart_the_clock(): void
    {
        $this->service()->claim(9, [3], null, self::TOKEN_A);
        NumberHold::query()->update(['expires_at' => now()->addMinutes(4)]);

        $again = $this->service()->claim(9, [3], null, self::TOKEN_A);

        $this->assertTrue($again['ok']);
        $this->assertEqualsWithDelta(240, now()->diffInSeconds($again['expires_at']), 2);
    }

    public function test_numbers_are_free_for_anyone_once_a_hold_has_run_out(): void
    {
        $this->service()->claim(9, [3], null, self::TOKEN_A);
        NumberHold::query()->update(['expires_at' => now()->subSecond()]);

        $this->assertSame([], $this->service()->heldByOthers(9, null, self::TOKEN_B));
        $this->assertTrue($this->service()->claim(9, [3], null, self::TOKEN_B)['ok']);
    }

    public function test_a_guests_hold_is_handed_to_their_account_when_they_sign_in(): void
    {
        $this->service()->claim(9, [3, 4], null, self::TOKEN_A);
        NumberHold::query()->update(['expires_at' => now()->addMinutes(7)]);
        $user = $this->otherUser();

        $result = $this->service()->claim(9, [3, 4], (int) $user->ID, self::TOKEN_A);

        $this->assertTrue($result['ok']);
        // Same deadline as before, not a fresh ten minutes.
        $this->assertEqualsWithDelta(420, now()->diffInSeconds($result['expires_at']), 2);
        $this->assertSame(2, NumberHold::where('user_id', $user->ID)->whereNull('guest_token')->count());
        // Now nobody else, guest or member, can take them.
        $this->assertFalse($this->service()->claim(9, [3], null, self::TOKEN_B)['ok']);
    }

    public function test_your_own_held_numbers_are_not_reported_as_held_by_others(): void
    {
        $this->service()->claim(9, [3], null, self::TOKEN_A);

        $this->assertSame([], $this->service()->heldByOthers(9, null, self::TOKEN_A));
        $this->assertSame([3], $this->service()->heldByOthers(9, null, self::TOKEN_B));
    }

    public function test_one_person_cannot_hold_more_than_the_limit(): void
    {
        config(['holds.max_per_owner' => 3]);

        $this->assertTrue($this->service()->claim(9, [1, 2], null, self::TOKEN_A)['ok']);
        $result = $this->service()->claim(9, [3, 4], null, self::TOKEN_A);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['too_many']);
    }

    public function test_release_only_lets_go_of_your_own_numbers(): void
    {
        $this->service()->claim(9, [3], null, self::TOKEN_A);

        $this->service()->release(9, [3], null, self::TOKEN_B);
        $this->assertSame(1, NumberHold::count());

        $this->service()->release(9, [3], null, self::TOKEN_A);
        $this->assertSame(0, NumberHold::count());
    }

    public function test_pruning_removes_only_expired_holds(): void
    {
        $this->service()->claim(9, [3], null, self::TOKEN_A);
        $this->service()->claim(9, [4], null, self::TOKEN_B);
        NumberHold::where('ticket_number', 3)->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, $this->service()->pruneExpired());
        $this->assertSame([4], NumberHold::pluck('ticket_number')->all());
    }

    // --- The HTTP API --------------------------------------------------------

    public function test_a_guest_can_hold_numbers_through_the_api(): void
    {
        $this->postJson('/api/raffles/9/holds', ['numbers' => [3, 4], 'guest_token' => self::TOKEN_A])
            ->assertOk()
            ->assertJsonPath('numbers', [3, 4])
            ->assertJsonPath('seconds_left', fn ($s) => $s > 590 && $s <= 600);
    }

    public function test_holding_taken_numbers_says_which_ones_to_replace(): void
    {
        $this->service()->claim(9, [4], null, self::TOKEN_B);
        RaffleEntry::create(['user_id' => 1, 'raffle_id' => 9, 'ticket_number' => 7, 'txn_id' => 1]);

        $this->postJson('/api/raffles/9/holds', ['numbers' => [3, 4, 7], 'guest_token' => self::TOKEN_A])
            ->assertStatus(409)
            ->assertJsonPath('unavailable_numbers', [4, 7])
            ->assertJsonPath('sold_numbers', [7])
            ->assertJsonPath('held_numbers', [4]);
    }

    public function test_holding_needs_a_login_or_a_valid_guest_token(): void
    {
        $this->postJson('/api/raffles/9/holds', ['numbers' => [3]])->assertStatus(422);
        $this->postJson('/api/raffles/9/holds', ['numbers' => [3], 'guest_token' => 'short'])->assertStatus(422);
        $this->assertSame(0, NumberHold::count());
    }

    public function test_holding_numbers_outside_the_raffle_or_on_a_closed_raffle_is_refused(): void
    {
        $this->postJson('/api/raffles/9/holds', ['numbers' => [51], 'guest_token' => self::TOKEN_A])->assertStatus(422);
        $this->postJson('/api/raffles/999/holds', ['numbers' => [1], 'guest_token' => self::TOKEN_A])->assertNotFound();

        $this->createRaffle(['public_id' => 10, 'max' => '50', 'is_sold_out' => '1']);
        $this->postJson('/api/raffles/10/holds', ['numbers' => [1], 'guest_token' => self::TOKEN_A])->assertStatus(409);
    }

    public function test_a_signed_in_customer_takes_over_the_guests_hold_through_the_api(): void
    {
        $this->service()->claim(9, [3, 4], null, self::TOKEN_A);
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/raffles/9/holds', ['numbers' => [3, 4], 'guest_token' => self::TOKEN_A])->assertOk();

        $this->assertSame(2, NumberHold::where('user_id', $user->ID)->count());
    }

    public function test_the_ticket_list_shows_numbers_held_by_others_but_not_your_own(): void
    {
        $this->service()->claim(9, [3], null, self::TOKEN_A);
        $this->service()->claim(9, [4], null, self::TOKEN_B);

        $this->getJson('/api/raffles/9/tickets', ['X-Guest-Token' => self::TOKEN_A])
            ->assertOk()
            ->assertJsonPath('held_numbers', [4]);

        $this->getJson('/api/raffles/9/tickets')->assertOk()->assertJsonPath('held_numbers', [3, 4]);
    }

    public function test_the_number_picker_marks_numbers_another_player_holds(): void
    {
        $this->service()->claim(9, [3], null, self::TOKEN_B);

        $this->get('/raffles/9/numbers?qty=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('heldNumbers', [3]));
    }

    // --- Payment can't get around a hold ------------------------------------

    private function buyAs(WpUser $user, array $numbers)
    {
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 10000, 'earnings_balance' => 0]);

        return $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 9,
            'ticket_numbers' => $numbers,
            'unit_price' => 500,
            'submitted_amount' => 500 * count($numbers) * (count($numbers) > 1 ? 0.9 : 1),
            'funding_source' => 'wallet',
            'idempotency_key' => 'key-'.uniqid(),
        ]);
    }

    public function test_paying_for_numbers_another_player_holds_is_refused_and_nothing_is_charged(): void
    {
        $this->service()->claim(9, [7], null, self::TOKEN_B);
        $buyer = $this->actingAsWordPressUser();

        $this->buyAs($buyer, [7])
            ->assertStatus(409)
            ->assertJsonPath('unavailable_numbers', [7])
            ->assertJsonPath('held', true);

        $this->assertEquals(10000, Wallet::where('user_id', $buyer->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleEntry::where('raffle_id', 9)->count());
    }

    public function test_the_holder_can_pay_and_the_hold_is_cleared_afterwards(): void
    {
        $buyer = $this->actingAsWordPressUser();
        $this->service()->claim(9, [7], (int) $buyer->ID, null);

        $this->buyAs($buyer, [7])->assertCreated();

        $this->assertSame(1, RaffleEntry::where('raffle_id', 9)->where('ticket_number', 7)->count());
        $this->assertSame(0, NumberHold::count());
    }

    public function test_a_held_number_can_be_paid_for_once_the_hold_has_run_out(): void
    {
        $this->service()->claim(9, [7], null, self::TOKEN_B);
        NumberHold::query()->update(['expires_at' => now()->subSecond()]);
        $buyer = $this->actingAsWordPressUser();

        $this->buyAs($buyer, [7])->assertCreated();
    }

    public function test_the_purchase_service_reports_held_numbers_as_held_not_sold(): void
    {
        $this->service()->claim(9, [7, 8], null, self::TOKEN_B);

        try {
            $this->service()->assertNotHeldByOthers(9, [8, 7, 9], 123);
            $this->fail('Expected the held numbers to be refused.');
        } catch (TicketUnavailableException $e) {
            $this->assertTrue($e->held);
            $this->assertSame([7, 8], $e->unavailableNumbers);
        }
    }
}
