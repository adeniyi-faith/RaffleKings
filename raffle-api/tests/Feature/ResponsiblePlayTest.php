<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUser;
use App\Models\PlayLimit;
use App\Models\Wallet;
use App\Services\ResponsiblePlayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/** Responsible play: spending limits, breaks and odds (Phase 10, item 38). */
class ResponsiblePlayTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function buy(array $numbers, string $key)
    {
        return $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5, 'ticket_numbers' => $numbers, 'unit_price' => 100, 'submitted_amount' => 100 * count($numbers),
            'funding_source' => 'wallet', 'idempotency_key' => 'purchase-'.$key,
        ]);
    }

    private function customer(): WpUser
    {
        $this->createRaffle(['public_id' => 5]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 10000, 'earnings_balance' => 0]);

        return $user;
    }

    public function test_a_daily_limit_stops_a_purchase_that_would_go_over_it_and_takes_no_money(): void
    {
        $user = $this->customer();
        $this->postJson('/api/play-limits', ['daily' => 250])->assertOk();

        $this->buy([1], 'rp-1')->assertCreated();
        $this->buy([2], 'rp-2')->assertCreated();
        $this->buy([3], 'rp-3')
            ->assertStatus(422)
            ->assertJsonPath('play_limit', true)
            ->assertJsonPath('message', 'This would go over your daily spending limit of ₦250. You can spend ₦50 more today. No money has been taken.');

        $this->assertEquals(9800, (float) Wallet::where('user_id', $user->ID)->value('wallet_balance'));

        // A new day starts a new daily total.
        $this->travel(1)->days();
        $this->buy([3], 'rp-4')->assertCreated();
    }

    public function test_lowering_a_limit_is_immediate_but_raising_it_waits_24_hours(): void
    {
        $user = $this->customer();
        $play = app(ResponsiblePlayService::class);

        $this->postJson('/api/play-limits', ['weekly' => 1000])->assertOk()->assertJsonPath('state.limits.weekly', 1000);
        $this->postJson('/api/play-limits', ['weekly' => 500])->assertOk()->assertJsonPath('state.limits.weekly', 500);

        $this->postJson('/api/play-limits', ['weekly' => 5000])
            ->assertOk()
            ->assertJsonPath('state.limits.weekly', 500)
            ->assertJsonPath('state.pending.weekly', 5000);

        $this->travel(23)->hours();
        $this->assertSame(500.0, $play->state($user->ID)['limits']['weekly']);

        $this->travel(2)->hours();
        $this->assertSame(5000.0, $play->state($user->ID)['limits']['weekly']);
        $this->assertNull($play->state($user->ID)['pending']);
    }

    public function test_removing_a_limit_also_waits_and_going_back_cancels_the_wait(): void
    {
        $user = $this->customer();
        $this->postJson('/api/play-limits', ['daily' => 1000])->assertOk();

        $this->postJson('/api/play-limits', ['daily' => null])->assertJsonPath('state.pending.daily', null)->assertJsonPath('state.limits.daily', 1000);
        $this->postJson('/api/play-limits', ['daily' => 1000])->assertJsonPath('state.pending', null);

        $this->travel(25)->hours();
        $this->assertSame(1000.0, app(ResponsiblePlayService::class)->state($user->ID)['limits']['daily']);
    }

    public function test_a_limit_under_100_naira_is_refused(): void
    {
        $this->customer();

        $this->postJson('/api/play-limits', ['daily' => 50])->assertStatus(422);
    }

    public function test_a_break_blocks_buying_spinning_and_topping_up_but_not_withdrawing_or_support(): void
    {
        $user = $this->customer();

        $this->postJson('/api/play-limits/break', ['days' => 7])->assertStatus(422); // must confirm
        $this->postJson('/api/play-limits/break', ['days' => 7, 'confirm' => true])->assertOk();

        $this->buy([1], 'rp-break')->assertStatus(403)->assertJsonPath('play_limit', true);
        $this->postJson('/api/rewards/spin')->assertStatus(403);
        $this->postJson('/api/deposits', ['amount' => 1000])->assertStatus(403);

        // Still open: reading the account, withdrawals (refused only for their own reasons), support.
        $this->getJson('/api/play-limits')->assertOk()->assertJsonPath('excluded_until', fn ($v) => $v !== null);
        $this->assertNotEquals(403, $this->postJson('/api/withdrawals', [])->status());
        $this->postJson('/api/support/tickets', ['subject' => 'Help', 'message' => 'I need help with my account please'])->assertSuccessful();

        $this->travel(8)->days();
        $this->buy([1], 'rp-after')->assertCreated();
    }

    public function test_a_break_cannot_be_shortened(): void
    {
        $user = $this->customer();
        $play = app(ResponsiblePlayService::class);

        $long = $play->takeBreak($user->ID, 30);
        $play->takeBreak($user->ID, 1);

        $this->assertSame($long->timestamp, PlayLimit::find($user->ID)->excluded_until->timestamp);
        $this->postJson('/api/play-limits/break', ['days' => 3, 'confirm' => true])->assertStatus(422);
    }

    public function test_the_page_opens_and_raffles_say_how_many_prizes_there_are(): void
    {
        $this->customer();

        $this->get('/account/play-limits')->assertOk()->assertInertia(fn ($page) => $page->component('Account/PlayLimits'));
        $this->getJson('/api/raffles/5')->assertOk()->assertJsonPath('winner_count', 1);
    }
}
