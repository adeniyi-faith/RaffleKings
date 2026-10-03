<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\CustomerMessage;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\PlayLimit;
use App\Models\Raffle;
use App\Models\Retention\MemberProfile;
use App\Models\Retention\MessageDelivery;
use App\Models\Retention\RetentionOffer;
use App\Models\UserPoints;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Notifications\BroadcastMessage;
use App\Notifications\ComebackOfferMessage;
use App\Services\Messaging\Audience;
use App\Services\Messaging\BroadcastService;
use App\Services\Reminders\ReminderService;
use App\Services\Retention\ComebackOffers;
use App\Services\Retention\DeliveryTracker;
use App\Services\Retention\MemberSegments;
use App\Services\Retention\OfferWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class RetentionEngineTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    private int $ticket = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Midday in Lagos: outside quiet hours.
        Carbon::setTestNow(Carbon::parse('2026-10-03 11:00:00', 'UTC'));
        Notification::fake();
        config([
            'ai.enabled' => false,
            'retention.offers' => [
                'enabled' => true,
                'send_hour' => 12,
                'segments' => ['new_signup', 'never_played', 'first_timer', 'one_and_done', 'drifting', 'at_risk', 'lapsed'],
                'claim_hours' => 24,
                'last_call_hours' => 3,
                'credit_min' => 200,
                'credit_max' => 1000,
                'ticket_max_price' => 1000,
                'points' => 500,
                'per_member_30_days' => 1000,
                'monthly_budget' => 50000,
                'monthly_points_budget' => 250000,
                'daily_max' => 200,
                'cooldown_days' => 14,
                'give_up_after_ignored' => 3,
                'use_ai' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function member(int $joinedDaysAgo = 200, array $attributes = []): WpUser
    {
        return WpUser::create(array_merge([
            'user_login' => uniqid('m'),
            'user_pass' => 'x',
            'user_email' => uniqid().'@example.com',
            'display_name' => 'Ada',
            'user_registered' => now()->subDays($joinedDaysAgo),
        ], $attributes));
    }

    /** One paid order of $count tickets, $daysAgo days ago. */
    private function buy(WpUser $who, int $daysAgo, float $paid = 1000, ?Raffle $raffle = null, int $count = 1): void
    {
        $raffle ??= $this->createRaffle(['price' => 500, 'max' => 10000]);
        $at = now()->subDays($daysAgo);
        $txn = RaffleTransaction::forceCreate(['user_id' => $who->ID, 'claimed_amount' => $paid, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet', 'created_at' => $at]);

        for ($i = 0; $i < $count; $i++) {
            RaffleEntry::forceCreate(['user_id' => $who->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => ++$this->ticket, 'txn_id' => $txn->id, 'created_at' => $at]);
        }
    }

    private function segmentOf(WpUser $who): string
    {
        return MemberProfile::query()->findOrFail($who->ID)->segment;
    }

    private function openRaffle(float $price = 500, array $extra = []): Raffle
    {
        return $this->createRaffle(array_merge(['price' => $price, 'max' => 100000, 'title' => '₦500,000 Raffle Draw', 'grand_prize' => '₦500,000 cash'], $extra));
    }

    // -- Segments --------------------------------------------------------

    public function test_every_customer_is_sorted_into_the_right_segment(): void
    {
        $newbie = $this->member(5);
        $neverPlayed = $this->member(60);
        $firstTimer = $this->member(40);
        $this->buy($firstTimer, 3);
        $oneAndDone = $this->member(100);
        $this->buy($oneAndDone, 45);
        $consistent = $this->member(100);
        foreach ([1, 8, 15, 22, 29, 36] as $d) {
            $this->buy($consistent, $d);
        }
        $repeat = $this->member(100);
        $this->buy($repeat, 2);
        $this->buy($repeat, 40);
        $drifting = $this->member(100);
        $this->buy($drifting, 20);
        $this->buy($drifting, 25);
        $atRisk = $this->member(100);
        $this->buy($atRisk, 40);
        $this->buy($atRisk, 50);
        $lapsed = $this->member(300);
        $this->buy($lapsed, 90);
        $this->buy($lapsed, 100);
        $dormant = $this->member(400);
        $this->buy($dormant, 200);
        $this->buy($dormant, 250);

        app(MemberSegments::class)->refresh();

        $this->assertSame('new_signup', $this->segmentOf($newbie));
        $this->assertSame('never_played', $this->segmentOf($neverPlayed));
        $this->assertSame('first_timer', $this->segmentOf($firstTimer));
        $this->assertSame('one_and_done', $this->segmentOf($oneAndDone));
        $this->assertSame('consistent', $this->segmentOf($consistent));
        $this->assertSame('repeat', $this->segmentOf($repeat));
        $this->assertSame('drifting', $this->segmentOf($drifting));
        $this->assertSame('at_risk', $this->segmentOf($atRisk));
        $this->assertSame('lapsed', $this->segmentOf($lapsed));
        $this->assertSame('dormant', $this->segmentOf($dormant));

        $profile = MemberProfile::find($consistent->ID);
        $this->assertSame(6, $profile->play_days);
        $this->assertEquals(6000, $profile->spend_total);
        $this->assertEquals(1000, $profile->avg_order);
    }

    public function test_labels_and_moves_between_segments_are_recorded(): void
    {
        $who = $this->member(100);
        $this->buy($who, 5, 60000);
        $this->buy($who, 40, 1000);
        Wallet::create(['user_id' => $who->ID, 'wallet_balance' => 300, 'earnings_balance' => 0]);
        WpUserMeta::create(['user_id' => $who->ID, 'meta_key' => 'rk_onesignal_id', 'meta_value' => 'device-1']);
        foreach ([1, 2, 3, 4] as $d) {
            DB::table('member_visit_days')->insert(['user_id' => $who->ID, 'day' => now()->subDays($d)->toDateString()]);
        }

        app(MemberSegments::class)->refresh();

        $this->assertSame('repeat', $this->segmentOf($who));
        $flags = MemberSegments::flagsFor($who->ID);
        $this->assertContains('high_value', $flags);
        $this->assertContains('money_waiting', $flags);
        $this->assertContains('push_on', $flags);
        $this->assertContains('repeat_visitor', $flags);
        $this->assertNotContains('visits_no_buy', $flags);
        $this->assertSame('push', MemberProfile::find($who->ID)->best_channel);
        $this->assertSame(0, DB::table('member_segment_changes')->count(), 'a first sort is not a move');

        // Three weeks later with no tickets: drawing back.
        Carbon::setTestNow(now()->addDays(21));
        app(MemberSegments::class)->refresh();

        $this->assertSame('drifting', $this->segmentOf($who));
        $this->assertDatabaseHas('member_segment_changes', ['user_id' => $who->ID, 'from_segment' => 'repeat', 'to_segment' => 'drifting']);
        $this->assertSame([['from' => 'Repeat players', 'to' => 'Drawing back', 'count' => 1]], app(MemberSegments::class)->recentMoves());
    }

    public function test_a_won_then_quiet_player_and_a_visitor_who_does_not_buy_are_labelled(): void
    {
        $winner = $this->member(100);
        $raffle = $this->createRaffle();
        $this->buy($winner, 20, 1000, $raffle);
        RaffleWinner::forceCreate(['raffle_id' => $raffle->public_id, 'user_id' => $winner->ID, 'ticket_number' => 1, 'prize_name' => 'Prize', 'prize_rank' => 1, 'prize_cash_value' => 1000, 'is_credited' => true, 'is_visible' => true, 'won_at' => now()->subDays(15)]);

        $looker = $this->member(100);
        DB::table('member_visit_days')->insert([['user_id' => $looker->ID, 'day' => now()->subDays(2)->toDateString()], ['user_id' => $looker->ID, 'day' => now()->subDays(6)->toDateString()]]);

        app(MemberSegments::class)->refresh();

        $this->assertContains('winner_gone_quiet', MemberSegments::flagsFor($winner->ID));
        $this->assertContains('visits_no_buy', MemberSegments::flagsFor($looker->ID));
    }

    public function test_the_best_channel_is_the_one_the_customer_taps(): void
    {
        $stats = ['push' => ['sent' => 10, 'tapped' => 0], 'email' => ['sent' => 4, 'tapped' => 3]];

        $this->assertSame('email', MemberSegments::bestChannel(['push', 'email'], $stats));
        $this->assertSame('push', MemberSegments::bestChannel(['push', 'email'], []), 'no history: push first');
        $this->assertSame('email', MemberSegments::bestChannel(['email'], []));
        $this->assertNull(MemberSegments::bestChannel([], []));
    }

    public function test_messages_can_go_to_a_segment_and_an_empty_segment_choice_reaches_nobody(): void
    {
        $lapsed = $this->member(300);
        $this->buy($lapsed, 90);
        $this->buy($lapsed, 100);
        $this->member(300); // never played
        app(MemberSegments::class)->refresh();

        $audience = app(Audience::class);
        $this->assertSame([$lapsed->ID], $audience->query('segment', ['segments' => ['lapsed']])->pluck('ID')->all());
        $this->assertSame(0, $audience->count('segment', ['segments' => []]));
        $this->assertSame(1, $audience->count('custom', ['filters' => ['segments' => ['never_played']]]));
        $this->assertSame('In any of these segments: Lapsed', $audience->describe('segment', ['segments' => ['lapsed']]));
    }

    // -- Comeback offers -------------------------------------------------

    public function test_no_offers_while_switched_off(): void
    {
        config(['retention.offers.enabled' => false]);
        $this->openRaffle();
        $this->member(60);
        app(MemberSegments::class)->refresh();

        $this->assertSame(0, app(ComebackOffers::class)->makeOffers());
        $this->assertSame(0, RetentionOffer::query()->count());
    }

    public function test_each_segment_gets_a_gift_picked_for_it_and_it_is_sent_by_the_best_channel(): void
    {
        $raffle = $this->openRaffle(500);
        $this->openRaffle(5000, ['title' => 'Car Raffle']); // too dear to give as a free ticket

        $never = $this->member(60);
        $atRisk = $this->member(200);
        $this->buy($atRisk, 40, 2000);
        $this->buy($atRisk, 50, 2000);
        $drifting = $this->member(200);
        $this->buy($drifting, 20);
        $this->buy($drifting, 25);
        $consistent = $this->member(100);
        foreach ([1, 8, 15, 22, 29, 36] as $d) {
            $this->buy($consistent, $d);
        }
        WpUserMeta::create(['user_id' => $atRisk->ID, 'meta_key' => 'rk_onesignal_id', 'meta_value' => 'device-9']);
        app(MemberSegments::class)->refresh();

        $this->assertSame(3, app(ComebackOffers::class)->makeOffers());

        $first = RetentionOffer::query()->where('user_id', $never->ID)->firstOrFail();
        $this->assertSame('raffle_ticket', $first->kind);
        $this->assertEquals(500, $first->amount);
        $this->assertSame($raffle->id, $first->raffle_id);
        $this->assertStringContainsString('₦500', $first->body);
        $this->assertStringContainsString('₦500,000 Raffle Draw', $first->body);
        $this->assertSame('template', $first->written_by);
        $this->assertSame(['inbox', 'email'], $first->channels);

        $credit = RetentionOffer::query()->where('user_id', $atRisk->ID)->firstOrFail();
        $this->assertSame('credit', $credit->kind);
        $this->assertEquals(500, $credit->amount, 'a quarter of the usual ₦2,000 order');
        $this->assertSame(['inbox', 'push'], $credit->channels, 'push is their best channel');

        $this->assertSame('points', RetentionOffer::query()->where('user_id', $drifting->ID)->value('kind'));
        $this->assertNull(RetentionOffer::query()->where('user_id', $consistent->ID)->first(), 'consistent players are not paid to come back');

        // Inbox message, tracking rows and the outside message.
        $this->assertDatabaseHas('customer_messages', ['user_id' => $never->ID, 'kind' => 'offer', 'link_url' => $first->url()]);
        $this->assertSame(2, MessageDelivery::query()->where('source', 'offer')->where('source_id', $first->id)->count());
        Notification::assertSentTo($never, ComebackOfferMessage::class, fn ($n) => $n->channels === ['email'] && isset($n->tokens['email']));
        Notification::assertSentTo($atRisk, ComebackOfferMessage::class, fn ($n) => $n->channels === ['push']);

        // Nobody gets a second offer while one is open, or in the cooldown.
        $this->assertSame(0, app(ComebackOffers::class)->makeOffers());
    }

    public function test_offers_greet_members_by_full_name_or_username(): void
    {
        $this->openRaffle();
        $named = $this->member(60, ['user_login' => 'kingsley99']);
        WpUserMeta::create(['user_id' => $named->ID, 'meta_key' => 'first_name', 'meta_value' => 'Kingsley']);
        WpUserMeta::create(['user_id' => $named->ID, 'meta_key' => 'last_name', 'meta_value' => 'King']);
        $nameless = $this->member(60, ['user_login' => 'luckyking']);
        app(MemberSegments::class)->refresh();

        app(ComebackOffers::class)->makeOffers();

        $this->assertStringContainsString('Kingsley King', RetentionOffer::query()->where('user_id', $named->ID)->value('headline'));
        $this->assertStringContainsString('luckyking', RetentionOffer::query()->where('user_id', $nameless->ID)->value('headline'));
        $this->assertSame('luckyking', BroadcastService::fullName($nameless));
        $this->assertSame('Hi Kingsley King', BroadcastService::personalise('Hi {name}', $named));
    }

    public function test_budgets_are_hard_limits(): void
    {
        $this->openRaffle(500);
        $a = $this->member(200);
        $b = $this->member(200);
        foreach ([$a, $b] as $who) {
            $this->buy($who, 40, 2000);
            $this->buy($who, 50, 2000);
        }
        app(MemberSegments::class)->refresh();

        // Room for one ₦500 credit; the second customer gets points instead.
        config(['retention.offers.monthly_budget' => 700]);
        $this->assertSame(2, app(ComebackOffers::class)->makeOffers());
        $this->assertEqualsCanonicalizing(['credit', 'points'], RetentionOffer::query()->pluck('kind')->all());
        $this->assertSame(200.0, app(ComebackOffers::class)->budgetLeft()['money']);

        // No points budget either: nothing at all.
        RetentionOffer::query()->delete();
        config(['retention.offers.monthly_budget' => 0, 'retention.offers.monthly_points_budget' => 0]);
        $this->assertSame(0, app(ComebackOffers::class)->makeOffers());

        // An unclaimed offer that ran out frees its budget.
        config(['retention.offers.monthly_budget' => 500, 'retention.offers.monthly_points_budget' => 1000]);
        app(ComebackOffers::class)->makeOffers();
        $this->assertSame(0.0, app(ComebackOffers::class)->budgetLeft()['money']);
        Carbon::setTestNow(now()->addHours(25));
        app(ComebackOffers::class)->expire();
        $this->assertSame(500.0, app(ComebackOffers::class)->budgetLeft()['money']);
    }

    public function test_people_who_stopped_reminders_are_on_a_break_or_are_staff_get_nothing(): void
    {
        $this->openRaffle();
        $stopped = $this->member(60);
        app(ReminderService::class)->setOptedOut($stopped->ID, true);
        $resting = $this->member(60);
        PlayLimit::create(['user_id' => $resting->ID, 'excluded_until' => now()->addDays(30)]);
        $banned = $this->member(60);
        WpUserMeta::create(['user_id' => $banned->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);
        app(MemberSegments::class)->refresh();

        $this->assertSame(0, app(ComebackOffers::class)->makeOffers());
    }

    public function test_customers_who_ignore_offers_get_a_rest_and_ignored_gifts_are_switched(): void
    {
        $this->openRaffle();
        $who = $this->member(200);
        $this->buy($who, 20);
        $this->buy($who, 25);
        app(MemberSegments::class)->refresh();

        // Ignored points last time: ticket credit now.
        RetentionOffer::create(['user_id' => $who->ID, 'segment' => 'drifting', 'kind' => 'points', 'amount' => 500, 'headline' => 'h', 'body' => 'b', 'token' => str_repeat('a', 32), 'status' => 'expired', 'expires_at' => now()->subDays(15), 'created_at' => now()->subDays(16)]);
        app(ComebackOffers::class)->makeOffers();
        $this->assertSame('credit', RetentionOffer::query()->where('status', 'open')->value('kind'));

        // Three ignored in a row: no more for now.
        RetentionOffer::query()->delete();
        foreach ([20, 40, 60] as $i => $days) {
            RetentionOffer::create(['user_id' => $who->ID, 'segment' => 'drifting', 'kind' => 'points', 'amount' => 500, 'headline' => 'h', 'body' => 'b', 'token' => str_repeat((string) $i, 32), 'status' => 'expired', 'expires_at' => now()->subDays($days - 1), 'created_at' => now()->subDays($days)]);
        }
        $this->assertSame(0, app(ComebackOffers::class)->makeOffers());
    }

    public function test_claiming_pays_ticket_credit_once_and_only_to_its_owner(): void
    {
        $raffle = $this->openRaffle();
        $who = $this->member(60);
        app(MemberSegments::class)->refresh();
        app(ComebackOffers::class)->makeOffers();
        $offer = RetentionOffer::query()->firstOrFail();

        try {
            app(ComebackOffers::class)->claim($offer->token, $this->member());
            $this->fail('someone else claimed it');
        } catch (RuntimeException $e) {
            $this->assertSame('This offer could not be found.', $e->getMessage());
        }

        $result = app(ComebackOffers::class)->claim($offer->token, $who);
        $this->assertSame('/raffles/'.$raffle->public_id, $result['redirect']);
        $this->assertEquals(500, Wallet::query()->where('user_id', $who->ID)->value('wallet_balance'));
        $this->assertEquals(0, Wallet::query()->where('user_id', $who->ID)->value('earnings_balance'), 'never withdrawable money');
        $this->assertSame(1, WalletLedgerEntry::query()->where('reason', 'comeback_offer')->where('balance_type', 'wallet')->where('reference_id', $offer->id)->count());

        $this->expectExceptionMessage('You already claimed this offer.');
        app(ComebackOffers::class)->claim($offer->token, $who);
    }

    public function test_an_offer_that_ran_out_cannot_be_claimed_and_points_offers_pay_points(): void
    {
        $who = $this->member();
        $late = RetentionOffer::create(['user_id' => $who->ID, 'segment' => 'lapsed', 'kind' => 'credit', 'amount' => 500, 'headline' => 'h', 'body' => 'b', 'token' => str_repeat('b', 32), 'status' => 'open', 'expires_at' => now()->subMinute()]);
        $points = RetentionOffer::create(['user_id' => $who->ID, 'segment' => 'drifting', 'kind' => 'points', 'amount' => 300, 'headline' => 'h', 'body' => 'b', 'token' => str_repeat('c', 32), 'status' => 'open', 'expires_at' => now()->addHour()]);

        try {
            app(ComebackOffers::class)->claim($late->token, $who);
            $this->fail('claimed after it ran out');
        } catch (RuntimeException $e) {
            $this->assertSame('Sorry, this offer has run out.', $e->getMessage());
        }

        $this->assertSame('/rewards', app(ComebackOffers::class)->claim($points->token, $who)['redirect']);
        $this->assertSame(300, UserPoints::query()->where('user_id', $who->ID)->value('balance'));
        $this->assertNull(Wallet::query()->where('user_id', $who->ID)->value('wallet_balance'));
    }

    public function test_the_claim_page_and_api_work_for_the_signed_in_customer(): void
    {
        $who = $this->actingAsWordPressUser(['user_registered' => now()->subDays(60)]);
        $offer = RetentionOffer::create(['user_id' => $who->ID, 'segment' => 'never_played', 'kind' => 'credit', 'amount' => 400, 'headline' => 'A gift', 'body' => 'b', 'token' => str_repeat('d', 32), 'status' => 'open', 'expires_at' => now()->addHours(5)]);

        $this->get('/offers/'.$offer->token)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Offers/Show')->where('offer.prize_text', '₦400 ticket credit')->where('offer.status', 'open'));
        $this->get('/offers/'.str_repeat('e', 32))->assertNotFound();

        $this->postJson('/api/offers/'.$offer->token.'/claim')->assertOk()->assertJsonPath('redirect', '/raffles');
        $this->postJson('/api/offers/'.$offer->token.'/claim')->assertStatus(422)->assertJsonPath('message', 'You already claimed this offer.');
        $this->assertEquals(400, Wallet::query()->where('user_id', $who->ID)->value('wallet_balance'));
    }

    public function test_a_last_call_goes_once_before_the_offer_runs_out(): void
    {
        $this->openRaffle();
        $who = $this->member(60);
        app(MemberSegments::class)->refresh();
        app(ComebackOffers::class)->makeOffers();

        $this->assertSame(0, app(ComebackOffers::class)->sendLastCalls(), 'too early');

        Carbon::setTestNow(now()->addHours(22));
        $this->assertSame(1, app(ComebackOffers::class)->sendLastCalls());
        $this->assertSame(0, app(ComebackOffers::class)->sendLastCalls(), 'only once');
        Notification::assertSentToTimes($who, ComebackOfferMessage::class, 2);
        $this->assertSame(1, MessageDelivery::query()->where('source', 'last_call')->count());
    }

    public function test_results_count_what_they_spent_after_claiming(): void
    {
        $this->openRaffle();
        $who = $this->member(60);
        app(MemberSegments::class)->refresh();
        app(ComebackOffers::class)->makeOffers();
        $offer = RetentionOffer::query()->firstOrFail();
        app(ComebackOffers::class)->claim($offer->token, $who);

        Carbon::setTestNow(now()->addDay());
        $this->buy($who, 0, 1500);
        app(ComebackOffers::class)->trackResults();

        $offer->refresh();
        $this->assertEquals(1500, $offer->spend_after);
        $this->assertNotNull($offer->first_purchase_at);
        $this->assertSame(1, app(ComebackOffers::class)->performance(now()->subDays(30))['totals']['came_back']);
    }

    public function test_gemini_writes_the_message_when_on_and_bad_answers_fall_back_to_built_in_words(): void
    {
        config(['ai.enabled' => true, 'ai.daily_limit' => 50, 'services.gemini.api_key' => 'k']);
        $answer = fn (array $json) => ['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($answer(['headline' => '🎁 {name}, a ticket is yours', 'body' => 'Use {amount} on the {raffle} and you could win {prize}. Gone in {hours} hours!']))
            ->push($answer(['headline' => 'You will win!', 'body' => 'Claim {amount} now, {coupon}'])),
        ]);

        $writer = app(OfferWriter::class);
        $facts = ['name' => 'Ada', 'amount' => '₦500', 'raffle' => 'Big Draw', 'prize' => 'a car', 'hours' => 24];

        $ai = $writer->write('raffle_ticket', 'lapsed', $facts);
        $this->assertSame('ai', $ai['written_by']);
        $this->assertSame('🎁 Ada, a ticket is yours', $ai['headline']);
        $this->assertSame('Use ₦500 on the Big Draw and you could win a car. Gone in 24 hours!', $ai['body']);

        $fallback = $writer->write('credit', 'at_risk', $facts);
        $this->assertSame('template', $fallback['written_by']);
        $this->assertStringContainsString('₦500', $fallback['body']);
        $this->assertStringNotContainsString('{', $fallback['body']);
    }

    // -- Delivery tracking ------------------------------------------------

    public function test_broadcasts_record_each_channel_and_taps_are_tracked(): void
    {
        $with = $this->member();
        $without = $this->member();
        WpUserMeta::create(['user_id' => $with->ID, 'meta_key' => 'rk_onesignal_id', 'meta_value' => 'device-1']);

        $broadcast = Broadcast::create(['title' => 'Hi', 'body' => 'News', 'link_url' => '/raffles', 'channels' => ['inbox', 'email', 'push'], 'audience' => 'everyone', 'audience_options' => [], 'status' => 'sending', 'last_user_id' => 0, 'delivered_count' => 0, 'is_promotion' => true]);
        app(BroadcastService::class)->sendNextBatch($broadcast);

        $this->assertSame(3, MessageDelivery::query()->where('user_id', $with->ID)->count());
        $this->assertSame(2, MessageDelivery::query()->where('user_id', $without->ID)->count(), 'no push row without a device');
        $this->assertNotNull(CustomerMessage::query()->where('user_id', $with->ID)->value('delivery_id'));

        $email = MessageDelivery::query()->where('user_id', $with->ID)->where('channel', 'email')->firstOrFail();
        $this->assertSame('queued', $email->status);

        // The email left us.
        $notification = new BroadcastMessage($broadcast, ['email']);
        $this->assertSame($email->token, $notification->deliveryToken('email', $with));
        event(new NotificationSent($with, $notification, 'mail'));
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertStringContainsString('/m/'.$email->token, $notification->toMail($with)->actionUrl);

        // They opened it and tapped the button.
        $this->get('/m/'.$email->token.'/open.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get('/m/'.$email->token)->assertRedirect('/raffles');
        $email->refresh();
        $this->assertNotNull($email->opened_at);
        $this->assertNotNull($email->clicked_at);

        $stats = app(DeliveryTracker::class)->stats('broadcast', $broadcast->id);
        $this->assertSame(['total' => 2, 'sent' => 1, 'failed' => 0, 'queued' => 1, 'opened' => 1, 'clicked' => 1], $stats['email']);
        $this->assertSame(2, $stats['inbox']['sent']);
        $this->get('/m/'.str_repeat('z', 32))->assertRedirect('/');
    }

    public function test_reading_an_offer_in_the_inbox_counts_as_opened(): void
    {
        $who = $this->actingAsWordPressUser();
        $rows = app(DeliveryTracker::class)->createMany('offer', 1, [['user_id' => $who->ID, 'channel' => 'inbox', 'target_url' => '/offers/x']]);
        $message = CustomerMessage::create(['user_id' => $who->ID, 'kind' => 'offer', 'delivery_id' => $rows[$who->ID]['inbox']['id'], 'title' => 't', 'body' => 'b', 'created_at' => now()]);

        $this->postJson("/api/messages/{$message->id}/read")->assertOk();
        $this->postJson("/api/messages/{$message->id}/tapped")->assertOk();

        $row = MessageDelivery::query()->findOrFail($rows[$who->ID]['inbox']['id']);
        $this->assertNotNull($row->opened_at);
        $this->assertNotNull($row->clicked_at);
    }

    public function test_visits_are_recorded_once_a_day(): void
    {
        $who = $this->actingAsWordPressUser();

        $this->get('/raffles')->assertOk();
        $this->get('/raffles')->assertOk();

        $this->assertSame(1, DB::table('member_visit_days')->where('user_id', $who->ID)->count());
    }

    // -- Admin screens ---------------------------------------------------

    public function test_the_admin_screens_open(): void
    {
        $who = $this->member(300);
        $this->buy($who, 90);
        $this->buy($who, 100);
        app(MemberSegments::class)->refresh();
        RetentionOffer::create(['user_id' => $who->ID, 'segment' => 'lapsed', 'kind' => 'credit', 'amount' => 500, 'headline' => 'Come back', 'body' => 'b', 'token' => str_repeat('f', 32), 'status' => 'open', 'expires_at' => now()->addHours(5), 'channels' => ['inbox', 'email']]);
        $broadcast = Broadcast::create(['title' => 'Hi', 'body' => 'News', 'channels' => ['inbox', 'email'], 'audience' => 'segment', 'audience_options' => ['segments' => ['lapsed']], 'status' => 'sending', 'last_user_id' => 0, 'delivered_count' => 0, 'is_promotion' => true]);
        app(BroadcastService::class)->sendNextBatch($broadcast);

        $this->actingAsAdministrator();

        $this->get('/admin/member-segments')->assertOk()->assertSee('Lapsed')->assertSee('Drawing back')->assertSee('Comeback offers, last 30 days');
        $this->get('/admin/comeback-offers')->assertOk()->assertSee('Come back')->assertSee('₦500 ticket credit');
        $this->get('/admin/messages/new?segment=lapsed')->assertOk()->assertSee('Segments');
        $this->get('/admin/messages/'.$broadcast->id)->assertOk()->assertSee('Delivery')->assertSee('In any of these segments: Lapsed');
        $this->get('/admin/settings')->assertOk();
        $this->get(\App\Filament\Resources\Legacy\WpUserResource::getUrl('view', ['record' => $who]))->assertOk()->assertSee('Segment')->assertSee('Lapsed');
    }
}
