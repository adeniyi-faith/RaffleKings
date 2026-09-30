<?php

namespace Tests\Feature\Growth;

use App\Models\CheckoutVisit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\PlayLimit;
use App\Models\Raffle;
use App\Models\ReminderSend;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Reminder;
use App\Services\Reminders\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday in Lagos: outside quiet hours.
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'Africa/Lagos'));

        config([
            'features.reminders' => true,
            'raffles.timezone' => 'Africa/Lagos',
            'reminders.channels' => ['push' => false, 'email' => true, 'whatsapp' => false],
        ]);

        Notification::fake();
    }

    private function customer(): WpUser
    {
        return WpUser::create(['user_login' => 'c_'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Ada']);
    }

    private function endingRaffle(int $minutes = 50): Raffle
    {
        $raffle = $this->createRaffle(['title' => 'iPhone 17']);
        $raffle->update(['sales_end_at' => now()->addMinutes($minutes)]);

        return $raffle->refresh();
    }

    private function ticket(WpUser $user, Raffle $raffle, int $number = 1): void
    {
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => $number, 'txn_id' => 0]);
    }

    public function test_nothing_is_sent_or_recorded_while_switched_off(): void
    {
        config(['features.reminders' => false]);
        $user = $this->customer();
        $this->ticket($user, $this->endingRaffle());

        $this->assertSame(['raffle_ending' => 0, 'abandoned_checkout' => 0], app(ReminderService::class)->run());

        $this->actingAsWordPressUser();
        app(ReminderService::class)->rememberCheckout($user, ['id' => 5], [1, 2]);
        $this->assertSame(0, CheckoutVisit::count());
        Notification::assertNothingSent();
    }

    public function test_people_with_tickets_hear_the_raffle_ends_soon_once(): void
    {
        $user = $this->customer();
        $this->ticket($user, $raffle = $this->endingRaffle());

        $this->assertSame(1, app(ReminderService::class)->run()['raffle_ending']);
        $this->assertSame(0, app(ReminderService::class)->run()['raffle_ending']);

        Notification::assertSentTo($user, Reminder::class, fn (Reminder $r) => $r->kind === 'raffle_ending'
            && str_contains($r->title, 'iPhone 17 ends in 50 minutes')
            && str_contains($r->url, "/raffles/{$raffle->public_id}")
            && $r->channels === ['email']);
        Notification::assertSentToTimes($user, Reminder::class, 1);
    }

    public function test_raffles_far_from_their_end_or_closed_are_left_alone(): void
    {
        $user = $this->customer();
        $this->ticket($user, $this->endingRaffle(minutes: 180));
        $closed = $this->endingRaffle();
        $closed->update(['status' => 'closed']);
        $this->ticket($user, $closed);

        $this->assertSame(0, app(ReminderService::class)->run()['raffle_ending']);
    }

    public function test_quiet_hours_opt_out_and_play_breaks_are_respected(): void
    {
        $user = $this->customer();
        $this->ticket($user, $this->endingRaffle());

        $this->travelTo(Carbon::parse('2026-10-20 23:30', 'Africa/Lagos'));
        $this->ticket($user, $this->endingRaffle(), 2);
        $this->assertSame(0, app(ReminderService::class)->run()['raffle_ending']);

        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'Africa/Lagos'));
        app(ReminderService::class)->setOptedOut($user->ID, true);
        $this->assertSame(0, app(ReminderService::class)->run()['raffle_ending']);

        app(ReminderService::class)->setOptedOut($user->ID, false);
        PlayLimit::create(['user_id' => $user->ID, 'excluded_until' => now()->addWeek()]);
        $this->assertSame(0, app(ReminderService::class)->run()['raffle_ending']);

        Notification::assertNothingSent();
    }

    public function test_the_daily_limit_caps_reminders_per_person(): void
    {
        config(['reminders.max_per_day' => 1]);
        $user = $this->customer();
        $this->ticket($user, $this->endingRaffle());
        $this->ticket($user, $this->endingRaffle(40));

        $this->assertSame(1, app(ReminderService::class)->run()['raffle_ending']);
        $this->assertSame(1, ReminderSend::count());
    }

    public function test_a_checkout_left_unpaid_is_reminded_about_with_the_same_numbers(): void
    {
        $user = $this->actingAsWordPressUser();
        $raffle = $this->createRaffle(['title' => 'Generator', 'max' => 50]);

        $this->get("/checkout?raffle_id={$raffle->public_id}&qty=2&numbers=7,9")->assertOk();
        $this->assertSame(1, CheckoutVisit::count());

        // Not yet: too soon.
        $this->assertSame(0, app(ReminderService::class)->run()['abandoned_checkout']);

        $this->travel(46)->minutes();
        $this->assertSame(1, app(ReminderService::class)->run()['abandoned_checkout']);

        Notification::assertSentTo($user, Reminder::class, fn (Reminder $r) => $r->title === 'You left 2 tickets in checkout 🎟️'
            && str_contains($r->url, "/raffles/{$raffle->public_id}/numbers?qty=2&numbers=7%2C9"));
    }

    public function test_no_checkout_reminder_after_they_paid(): void
    {
        $user = $this->actingAsWordPressUser();
        $raffle = $this->createRaffle(['max' => 50]);
        $this->get("/checkout?raffle_id={$raffle->public_id}&qty=1&numbers=3")->assertOk();
        $this->ticket($user, $raffle, 3);

        $this->travel(60)->minutes();

        $this->assertSame(0, app(ReminderService::class)->run()['abandoned_checkout']);
        $this->assertNotNull(CheckoutVisit::first()->reminded_at);
    }

    public function test_nobody_is_reminded_when_no_channel_can_reach_them(): void
    {
        config(['reminders.channels' => ['push' => true, 'email' => false, 'whatsapp' => true]]);
        $user = $this->customer();
        $this->ticket($user, $this->endingRaffle());

        $this->assertSame(0, app(ReminderService::class)->run()['raffle_ending']);
    }

    public function test_the_stop_link_works_without_logging_in_and_can_be_undone(): void
    {
        $user = $this->customer();

        $this->get("/reminders/unsubscribe/{$user->ID}")->assertForbidden();

        $this->get(Reminder::unsubscribeUrl($user->ID))->assertOk();
        $this->assertTrue(app(ReminderService::class)->optedOut($user->ID));

        $this->get(\Illuminate\Support\Facades\URL::signedRoute('reminders.unsubscribe', ['user' => $user->ID, 'undo' => 1]))->assertOk();
        $this->assertFalse(app(ReminderService::class)->optedOut($user->ID));
    }

    public function test_whatsapp_sends_the_approved_template_to_a_nigerian_number(): void
    {
        $this->assertSame('2348012345678', WhatsAppChannel::normalise('0801 234 5678'));
        $this->assertSame('2348012345678', WhatsAppChannel::normalise('+234 801 234 5678'));
        $this->assertNull(WhatsAppChannel::normalise('12345'));

        config(['reminders.whatsapp.phone_number_id' => '555', 'reminders.whatsapp.access_token' => 'tok']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $user = $this->customer();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'phone', 'meta_value' => '08012345678']);

        (new WhatsAppChannel)->send($user, new Reminder('raffle_ending', 'T', 'B', 'https://x.test/r/1', 'Go', 'iPhone 17', ['whatsapp']));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/555/messages')
            && $r['to'] === '2348012345678'
            && $r['template']['name'] === 'raffle_ending_soon'
            && $r['template']['components'][0]['parameters'][1]['text'] === 'iPhone 17');
    }
}
