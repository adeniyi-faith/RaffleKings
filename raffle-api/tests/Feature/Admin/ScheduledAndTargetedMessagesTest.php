<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\BroadcastResource\Pages\CreateBroadcast;
use App\Filament\Resources\BroadcastResource\Pages\ViewBroadcast;
use App\Jobs\SendBroadcast;
use App\Models\Admin\CustomerTag;
use App\Models\Broadcast;
use App\Models\CustomerMessage;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\Messaging\Audience;
use App\Services\Messaging\BroadcastService;
use App\Services\Reminders\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

class ScheduledAndTargetedMessagesTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function customer(string $login, array $extra = []): WpUser
    {
        $user = WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com", 'display_name' => ucfirst($login), 'user_registered' => $extra['joined'] ?? now()->subMonths(3)]);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => $extra['wallet'] ?? 0, 'earnings_balance' => 0]);

        return $user;
    }

    private function message(array $overrides = []): array
    {
        return [...['title' => 'Hi {name}', 'body' => 'Big raffle today', 'channels' => ['inbox'], 'audience' => 'everyone'], ...$overrides];
    }

    private function ids(string $type, array $options = [], bool $promo = false): array
    {
        return app(Audience::class)->query($type, $options, $promo)->orderBy('ID')->pluck('ID')->all();
    }

    // -- Building a group ---------------------------------------------------

    public function test_filters_combine_so_a_customer_must_match_all_of_them(): void
    {
        $newAndVip = $this->customer('newvip', ['joined' => now()->subDays(5)]);
        $newOnly = $this->customer('newonly', ['joined' => now()->subDays(5)]);
        $oldVip = $this->customer('oldvip');
        CustomerTag::create(['user_id' => $newAndVip->ID, 'tag' => 'VIP']);
        CustomerTag::create(['user_id' => $oldVip->ID, 'tag' => 'VIP']);

        $filters = ['joined_within_days' => 30, 'tags' => ['VIP']];

        $this->assertSame([$newAndVip->ID], $this->ids('custom', ['filters' => $filters]));
        $this->assertEqualsCanonicalizing([$newAndVip->ID, $newOnly->ID], $this->ids('custom', ['filters' => ['joined_within_days' => 30]]));
        $this->assertSame([$newOnly->ID, $oldVip->ID], array_values(array_diff($this->ids('custom', ['filters' => ['exclude_tags' => []]]), [$newAndVip->ID])));
    }

    public function test_money_and_play_filters(): void
    {
        $rich = $this->customer('rich', ['wallet' => 5000]);
        $poor = $this->customer('poor', ['wallet' => 100]);
        RaffleEntry::create(['user_id' => $poor->ID, 'raffle_id' => 1, 'ticket_number' => 1, 'txn_id' => 1]);

        $this->assertSame([$rich->ID], $this->ids('custom', ['filters' => ['min_wallet' => 1000]]));
        $this->assertSame([$rich->ID], $this->ids('custom', ['filters' => ['never_bought' => true]]));
        $this->assertSame([$rich->ID], $this->ids('custom', ['filters' => ['never_bought' => true, 'min_wallet' => 1000]]));
        $this->assertSame([], $this->ids('custom', ['filters' => ['never_bought' => true, 'min_wallet' => 9000]]));
    }

    public function test_an_exact_list_reaches_only_those_customers_and_still_leaves_out_banned_ones(): void
    {
        $ada = $this->customer('ada');
        $bola = $this->customer('bola');
        $cee = $this->customer('cee');
        WpUserMeta::create(['user_id' => $cee->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);
        WpUserMeta::create(['user_id' => $bola->ID, 'meta_key' => ReminderService::OPT_OUT_META, 'meta_value' => '1']);

        $list = ['user_ids' => [$ada->ID, $bola->ID, $cee->ID]];

        $this->assertSame([$ada->ID, $bola->ID], $this->ids('selected', $list));
        $this->assertSame([$ada->ID], $this->ids('selected', $list, promo: true), 'a promotion leaves out someone who stopped reminders');
    }

    public function test_empty_filter_boxes_and_switches_are_ignored(): void
    {
        $this->assertSame(['joined_within_days' => '7'], Audience::cleanFilters(['joined_within_days' => '7', 'min_wallet' => '', 'never_bought' => false, 'tags' => [], 'bogus' => 'x']));
        $this->assertSame('Joined in the last 7 days AND Has any of these tags: VIP', app(Audience::class)->describe('custom', ['filters' => ['joined_within_days' => 7, 'tags' => ['VIP']]]));
    }

    // -- Sending now, later, and carrying on --------------------------------

    public function test_a_scheduled_message_waits_until_its_time_then_goes_out_once(): void
    {
        $admin = $this->actingAsAdministrator();
        $ada = $this->customer('ada');

        $broadcast = app(BroadcastService::class)->send($this->message(['scheduled_at' => now()->addHours(2)]), $admin);

        $this->assertSame('scheduled', $broadcast->status);
        $this->assertSame(0, CustomerMessage::count(), 'nothing goes out before the time');

        $this->travel(1)->hour();
        $this->assertSame(0, app(BroadcastService::class)->startDue());
        $this->assertSame(0, CustomerMessage::count());

        $this->travel(2)->hours();
        $this->assertSame(1, app(BroadcastService::class)->startDue());
        $this->assertSame(0, app(BroadcastService::class)->startDue(), 'a second run does not start it again');

        $this->assertSame('sent', $broadcast->fresh()->status);
        $this->assertSame(1, CustomerMessage::where('user_id', $ada->ID)->count());
    }

    public function test_a_time_in_the_past_just_sends_now(): void
    {
        $admin = $this->actingAsAdministrator();
        $this->customer('ada');

        $broadcast = app(BroadcastService::class)->send($this->message(['scheduled_at' => now()->subMinute()]), $admin);

        $this->assertSame('sent', $broadcast->fresh()->status);
    }

    public function test_an_interrupted_send_carries_on_and_nobody_gets_it_twice(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdministrator();
        $people = collect(range(1, 5))->map(fn ($i) => $this->customer("c{$i}"));

        $broadcast = app(BroadcastService::class)->send($this->message(), $admin);
        Queue::assertPushed(SendBroadcast::class);

        // First run reaches two customers, then "the server stops".
        $this->assertTrue(app(BroadcastService::class)->sendNextBatch($broadcast->fresh(), 2));
        $this->assertSame(2, CustomerMessage::count());
        $this->assertSame('sending', $broadcast->fresh()->status);

        // Worst case: the progress note is lost, so the next run starts from the top again.
        Broadcast::whereKey($broadcast->id)->update(['last_user_id' => 0]);

        $service = app(BroadcastService::class);
        while ($service->sendNextBatch($broadcast->fresh(), 2)) {
            // keep going
        }

        $this->assertSame(5, CustomerMessage::count());
        $this->assertSame(5, CustomerMessage::distinct()->count('user_id'), 'no customer has two copies');
        $this->assertSame(5, DB::table('broadcast_deliveries')->where('broadcast_id', $broadcast->id)->count());
        $done = $broadcast->fresh();
        $this->assertSame('sent', $done->status);
        $this->assertSame(5, $done->recipients_count);
        $this->assertSame(5, $done->delivered_count);
    }

    public function test_a_stalled_message_is_picked_up_again_by_the_scheduler(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdministrator();
        $this->customer('ada');
        $broadcast = app(BroadcastService::class)->send($this->message(), $admin);
        Queue::assertPushed(SendBroadcast::class, 1);

        $this->assertSame(0, app(BroadcastService::class)->resumeStalled(), 'still fresh');

        $this->travel(10)->minutes();
        $this->assertSame(1, app(BroadcastService::class)->resumeStalled());
        Queue::assertPushed(SendBroadcast::class, 2);
    }

    public function test_promotions_skip_people_who_stopped_reminders_and_say_how_many(): void
    {
        $admin = $this->actingAsAdministrator();
        $ada = $this->customer('ada');
        $bola = $this->customer('bola');
        WpUserMeta::create(['user_id' => $bola->ID, 'meta_key' => ReminderService::OPT_OUT_META, 'meta_value' => '1']);

        $promo = app(BroadcastService::class)->send($this->message(['is_promotion' => true]), $admin)->fresh();
        $notice = app(BroadcastService::class)->send($this->message(['title' => 'Raffle cancelled']), $admin)->fresh();

        $this->assertSame([1, 1], [$promo->recipients_count, $promo->skipped_count]);
        $this->assertSame([2, 0], [$notice->recipients_count, $notice->skipped_count], 'an important notice still reaches them');
        $this->assertSame(1, CustomerMessage::where('user_id', $bola->ID)->count());
    }

    public function test_cancelling_stops_a_scheduled_message_and_one_part_way(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdministrator();
        foreach (range(1, 4) as $i) {
            $this->customer("c{$i}");
        }
        $service = app(BroadcastService::class);

        $later = $service->send($this->message(['scheduled_at' => now()->addDay()]), $admin);
        $this->assertTrue($service->cancel($later, $admin));
        $this->travel(2)->days();
        $this->assertSame(0, $service->startDue());
        $this->assertSame('cancelled', $later->fresh()->status);

        $now = $service->send($this->message(), $admin);
        $service->sendNextBatch($now->fresh(), 2);
        $this->assertTrue($service->cancel($now, $admin));
        (new SendBroadcast($now->id))->handle($service);
        $this->assertSame(2, CustomerMessage::count(), 'the rest never get it');
        $this->assertSame(2, $now->fresh()->recipients_count);
        $this->assertFalse($service->cancel($now, $admin), 'already stopped');
    }

    public function test_a_failed_message_can_be_carried_on(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdministrator();
        $ada = $this->customer('ada');
        $service = app(BroadcastService::class);
        $broadcast = $service->send($this->message(), $admin);
        (new SendBroadcast($broadcast->id))->failed(new \RuntimeException('Mail server down'));

        $this->assertSame(['failed', 'Mail server down'], [$broadcast->fresh()->status, $broadcast->fresh()->error]);

        $this->assertTrue($service->sendNow($broadcast->fresh(), $admin));
        (new SendBroadcast($broadcast->id))->handle($service);

        $this->assertSame(['sent', null], [$broadcast->fresh()->status, $broadcast->fresh()->error]);
        $this->assertSame(1, CustomerMessage::where('user_id', $ada->ID)->count());
    }

    public function test_rescheduling_only_works_for_the_future_and_before_it_starts(): void
    {
        $admin = $this->actingAsAdministrator();
        $service = app(BroadcastService::class);
        $broadcast = $service->send($this->message(['scheduled_at' => now()->addDay()]), $admin);

        $this->assertFalse($service->reschedule($broadcast, now()->subHour(), $admin));
        $this->assertTrue($service->reschedule($broadcast, now()->addDays(3), $admin));
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $broadcast->fresh()->scheduled_at->timestamp, 5);

        $service->cancel($broadcast, $admin);
        $this->assertFalse($service->reschedule($broadcast->fresh(), now()->addDays(4), $admin));
    }

    // -- The admin screens --------------------------------------------------

    public function test_the_form_can_schedule_a_message_for_a_built_group(): void
    {
        $admin = $this->actingAsAdministrator();
        $vip = $this->customer('vip');
        $this->customer('other');
        CustomerTag::create(['user_id' => $vip->ID, 'tag' => 'VIP']);

        Livewire::test(CreateBroadcast::class)
            ->fillForm([
                'audience' => 'custom',
                'filters' => ['tags' => ['VIP']],
                'is_promotion' => true,
                'channels' => ['inbox'],
                'title' => 'For VIPs',
                'body' => 'A treat',
                'when' => 'later',
                'scheduled_at' => now()->addDay()->timezone(config('raffles.timezone'))->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $broadcast = Broadcast::firstOrFail();
        $this->assertSame('scheduled', $broadcast->status);
        $this->assertSame('custom', $broadcast->audience);
        $this->assertSame(['filters' => ['tags' => ['VIP']]], $broadcast->audience_options);
        $this->assertTrue($broadcast->is_promotion);
        $this->assertSame(1, $broadcast->recipients_count);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $broadcast->scheduled_at->timestamp, 120);
        $this->assertSame(0, CustomerMessage::count());
    }

    public function test_the_form_sends_now_to_a_ticked_list_handed_over_from_the_customers_list(): void
    {
        $this->actingAsAdministrator();
        $ada = $this->customer('ada');
        $this->customer('bola');
        \Illuminate\Support\Facades\Cache::put(\App\Filament\Resources\BroadcastResource::TICKED_CACHE_PREFIX.'abc', [$ada->ID], 3600);

        Livewire::withQueryParams(['list' => 'abc'])->test(CreateBroadcast::class)
            ->assertFormSet(['audience' => 'selected', 'user_ids' => [$ada->ID]])
            ->fillForm(['title' => 'Just you', 'body' => 'Hello', 'channels' => ['inbox']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('sent', Broadcast::firstOrFail()->status);
        $this->assertSame([$ada->ID], CustomerMessage::pluck('user_id')->all());
    }

    public function test_the_message_page_shows_cancel_and_change_time_for_scheduled_messages(): void
    {
        $admin = $this->actingAsAdministrator();
        $broadcast = app(BroadcastService::class)->send($this->message(['scheduled_at' => now()->addDay()]), $admin);

        Livewire::test(ViewBroadcast::class, ['record' => $broadcast->id])
            ->assertActionVisible('cancel')->assertActionVisible('reschedule')->assertActionVisible('sendNow')
            ->callAction('cancel');

        $this->assertSame('cancelled', $broadcast->fresh()->status);

        Livewire::test(ViewBroadcast::class, ['record' => $broadcast->id])
            ->assertActionHidden('cancel')->assertActionHidden('reschedule');
    }
}
