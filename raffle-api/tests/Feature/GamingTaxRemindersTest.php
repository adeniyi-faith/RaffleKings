<?php

namespace Tests\Feature;

use App\Models\GamingTaxReminder;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Notifications\GamingTaxReminder as ReminderNotification;
use App\Services\GamingTaxReminders;
use App\Services\GamingTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Reminders that a month's gaming tax needs locking, filing or paying. */
class GamingTaxRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['raffles.timezone' => 'Africa/Lagos', 'gaming_tax.rate' => 2.5, 'gaming_tax.shortfall' => 'zero', 'gaming_tax.due_day' => 21, 'gaming_tax.remind' => true, 'gaming_tax.remind_days' => [7, 3, 1]]);
        Cache::flush();

        // September had activity: due 21 October.
        $sale = RaffleTransaction::create(['user_id' => 1, 'claimed_amount' => 1_000_000, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        $sale->forceFill(['created_at' => Carbon::parse('2026-09-10 10:00', 'Africa/Lagos')->utc()])->save();
        $win = RaffleWinner::create(['raffle_id' => 7, 'user_id' => 1, 'ticket_number' => 1, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 800_000, 'is_credited' => true]);
        $win->forceFill(['won_at' => Carbon::parse('2026-09-11 10:00', 'Africa/Lagos')->utc()])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $lagosTime): void
    {
        Carbon::setTestNow(Carbon::parse($lagosTime, 'Africa/Lagos'));
        Cache::flush();
    }

    private function reminders(): GamingTaxReminders
    {
        return app(GamingTaxReminders::class);
    }

    private function kindOn(string $lagosTime): ?string
    {
        $this->at($lagosTime);

        return collect($this->reminders()->dueToday())->firstWhere('period', '2026-09')['kind'] ?? null;
    }

    private function staff(string $role, string $email): WpUser
    {
        $u = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => $email, 'display_name' => $role]);
        WpUserMeta::create(['user_id' => $u->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => $role]);

        return $u;
    }

    // --- Which reminder is due when ------------------------------------------------

    public function test_the_first_day_after_the_month_ends_asks_staff_to_lock_it(): void
    {
        $this->assertSame('lock', $this->kindOn('2026-10-01 10:00'));
        $this->assertNull($this->kindOn('2026-10-02 10:00'));
    }

    public function test_reminders_come_seven_three_and_one_days_before_and_on_the_day(): void
    {
        $this->assertSame('before_7', $this->kindOn('2026-10-14 10:00'));
        $this->assertNull($this->kindOn('2026-10-15 10:00'));
        $this->assertSame('before_3', $this->kindOn('2026-10-18 10:00'));
        $this->assertSame('before_1', $this->kindOn('2026-10-20 10:00'));
        $this->assertSame('due_today', $this->kindOn('2026-10-21 10:00'));
    }

    public function test_once_overdue_reminders_come_after_1_3_and_7_days_then_weekly(): void
    {
        $this->assertSame('overdue_1', $this->kindOn('2026-10-22 10:00'));
        $this->assertNull($this->kindOn('2026-10-23 10:00'));
        $this->assertSame('overdue_3', $this->kindOn('2026-10-24 10:00'));
        $this->assertSame('overdue_7', $this->kindOn('2026-10-28 10:00'));
        $this->assertNull($this->kindOn('2026-11-01 10:00'));
        $this->assertSame('overdue_14', $this->kindOn('2026-11-04 10:00'));
        $this->assertSame('overdue_21', $this->kindOn('2026-11-11 10:00'));
    }

    public function test_the_days_before_can_be_changed_in_settings(): void
    {
        config(['gaming_tax.remind_days' => ['10', '2']]);

        $this->assertSame('before_10', $this->kindOn('2026-10-11 10:00'));
        $this->assertNull($this->kindOn('2026-10-14 10:00'));
        $this->assertSame('before_2', $this->kindOn('2026-10-19 10:00'));
    }

    public function test_a_paid_month_a_running_month_and_a_month_with_no_activity_are_never_reminded(): void
    {
        $this->at('2026-10-14 10:00');
        $this->assertNotEmpty($this->reminders()->attention());

        // October is still running, and August had nothing in it: only September appears.
        $this->assertSame(['2026-09'], array_column($this->reminders()->attention(), 'period'));

        $admin = $this->staff('owner', 'owner@example.com');
        $service = app(GamingTaxService::class);
        $this->at('2026-10-14 10:00');
        $service->lock($admin, '2026-09');
        $service->markFiled($admin, '2026-09', 'REF');
        $service->recordPayment($admin, '2026-09', 5000, 'PAY');

        $this->assertSame([], $this->reminders()->attention());
        $this->assertSame([], $this->reminders()->dueToday());
    }

    public function test_the_wording_follows_where_the_month_is(): void
    {
        $admin = $this->staff('owner', 'owner@example.com');
        $service = app(GamingTaxService::class);
        $this->at('2026-10-14 10:00');

        $this->assertStringContainsString('Lock the month', $this->reminders()->attention()[0]['next_step']);
        $service->lock($admin, '2026-09');
        $this->assertStringContainsString('File the return', $this->reminders()->attention()[0]['next_step']);
        $service->markFiled($admin, '2026-09', 'REF');
        $this->assertStringContainsString('Record the payment', $this->reminders()->attention()[0]['next_step']);
    }

    // --- Sending -----------------------------------------------------------------------

    public function test_a_due_reminder_is_emailed_to_payout_staff_and_sent_to_telegram_once(): void
    {
        Notification::fake();
        $owner = $this->staff('owner', 'owner@example.com');
        $finance = $this->staff('finance', 'finance@example.com');
        $support = $this->staff('support', 'support@example.com');
        $this->at('2026-10-18 10:00');

        $this->assertSame(1, $this->reminders()->sendDue());

        Notification::assertSentTo($owner, ReminderNotification::class, fn ($n, $channels) => $channels === ['mail']);
        Notification::assertSentTo($finance, ReminderNotification::class);
        Notification::assertNotSentTo($support, ReminderNotification::class);
        Notification::assertSentTo(new AnonymousNotifiable, ReminderNotification::class);
        $this->assertSame(1, GamingTaxReminder::where('period', '2026-09')->where('kind', 'before_3')->count());

        // The hourly check runs again: nothing is sent twice.
        $this->assertSame(0, $this->reminders()->sendDue());
        Notification::assertSentToTimes($owner, ReminderNotification::class, 1);
    }

    public function test_the_email_says_what_is_due_and_links_to_the_page(): void
    {
        Notification::fake();
        $owner = $this->staff('owner', 'owner@example.com');
        $this->at('2026-10-20 10:00');

        $this->reminders()->sendDue();

        Notification::assertSentTo($owner, ReminderNotification::class, function ($n) use ($owner) {
            $mail = $n->toMail($owner);

            return str_contains($mail->subject, 'due in 1 day')
                && str_contains(implode(' ', $mail->introLines), '₦5,000.00')
                && str_contains(implode(' ', $mail->introLines), '21 October 2026')
                && str_contains($mail->actionUrl, 'month=2026-09');
        });
    }

    public function test_nothing_is_sent_before_nine_in_the_morning(): void
    {
        Notification::fake();
        $this->staff('owner', 'owner@example.com');
        $this->at('2026-10-18 08:30');

        $this->assertSame(0, $this->reminders()->sendDue());
        Notification::assertNothingSent();
        $this->assertSame(0, GamingTaxReminder::count());

        $this->at('2026-10-18 09:05');
        $this->assertSame(1, $this->reminders()->sendDue());
    }

    public function test_nothing_is_sent_while_reminders_are_switched_off(): void
    {
        Notification::fake();
        $this->staff('owner', 'owner@example.com');
        config(['gaming_tax.remind' => false]);
        $this->at('2026-10-18 10:00');

        $this->assertSame(0, $this->reminders()->sendDue());
        Notification::assertNothingSent();
    }

    public function test_a_missing_email_address_is_skipped_and_does_not_block_the_reminder(): void
    {
        Notification::fake();
        $owner = $this->staff('owner', 'owner@example.com');
        $noEmail = $this->staff('finance', 'not-an-email');
        $this->at('2026-10-18 10:00');

        $this->reminders()->sendDue();

        Notification::assertSentTo($owner, ReminderNotification::class);
        Notification::assertNotSentTo($noEmail, ReminderNotification::class);
    }

    public function test_recipients_are_the_staff_who_can_pay_out(): void
    {
        $owner = $this->staff('owner', 'o@example.com');
        $manager = $this->staff('manager', 'm@example.com');
        $finance = $this->staff('finance', 'f@example.com');
        $this->staff('support', 's@example.com');
        $this->staff('content', 'c@example.com');
        $this->staff('none', 'n@example.com');
        $admin = WpUser::create(['user_login' => 'wpadmin', 'user_pass' => 'x', 'user_email' => 'admin@example.com']);
        WpUserMeta::create(['user_id' => $admin->ID, 'meta_key' => config('legacy.wp_prefix').'capabilities', 'meta_value' => serialize(['administrator' => true])]);

        $ids = $this->reminders()->recipients()->pluck('ID')->sort()->values()->all();

        $this->assertSame(collect([$owner->ID, $manager->ID, $finance->ID, $admin->ID])->sort()->values()->all(), $ids);
    }

    // --- The menu badge and the page --------------------------------------------------------

    public function test_the_menu_badge_counts_months_that_are_due_soon_or_overdue_and_turns_red_when_overdue(): void
    {
        $this->at('2026-10-05 10:00'); // 16 days to go: not urgent yet
        $this->assertSame(0, $this->reminders()->badge()['count']);

        $this->at('2026-10-18 10:00');
        $this->assertSame(['count' => 1, 'danger' => false], $this->reminders()->badge());

        $this->at('2026-10-25 10:00');
        $this->assertSame(['count' => 1, 'danger' => true], $this->reminders()->badge());
    }

    public function test_the_badge_updates_as_soon_as_a_month_is_paid(): void
    {
        $admin = $this->staff('owner', 'o@example.com');
        $service = app(GamingTaxService::class);
        $this->at('2026-10-25 10:00');
        $this->assertSame(1, $this->reminders()->badge()['count']);

        $service->lock($admin, '2026-09');
        $service->markFiled($admin, '2026-09', 'R');
        $service->recordPayment($admin, '2026-09', 5000, 'P');

        $this->assertSame(0, $this->reminders()->badge()['count']);
    }

    public function test_the_page_shows_the_reminder_at_the_top(): void
    {
        $this->at('2026-10-25 10:00');
        $user = $this->staff('finance', 'f@example.com');
        // sign in as that finance user for the Livewire page
        $this->actingAsWordPressUserFor($user);

        \Livewire\Livewire::test(\App\Filament\Pages\GamingTax::class)
            ->assertSee('September 2026: overdue by 4 days')
            ->assertSee('Lock the month, then file and pay');
    }

    private function actingAsWordPressUserFor(WpUser $user): void
    {
        $token = 'raw-session-token-'.uniqid();
        WpUserMeta::create(['user_id' => $user->getKey(), 'meta_key' => 'session_tokens', 'meta_value' => serialize([hash('sha256', $token) => ['expiration' => time() + 3600]])]);
        $expiration = time() + 3600;
        $passFrag = substr($user->user_pass, 8, 4);
        $key = hash_hmac('md5', "{$user->user_login}|{$passFrag}|{$expiration}|{$token}", config('legacy.wp_logged_in_key').config('legacy.wp_logged_in_salt'));
        $hmac = hash_hmac('sha256', "{$user->user_login}|{$expiration}|{$token}", $key);
        $cookies = [('wordpress_logged_in_'.config('legacy.wp_cookiehash')) => "{$user->user_login}|{$expiration}|{$token}|{$hmac}"];
        $this->withUnencryptedCookies($cookies);
        \Livewire\Livewire::withCookies($cookies);
    }
}
