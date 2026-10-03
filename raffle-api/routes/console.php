<?php

use App\Console\Commands\HealthCheck;
use App\Services\Engagement\RedEnvelopes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OVERHAUL_CHECKLIST.md Phase 3 item 32 — keep the shadow-traffic backlog
// caught up so a mismatch is visible within a minute of a real purchase,
// not discovered hours later by someone manually running the command.
Schedule::command('shadow:process-purchases')->everyMinute();

// OVERHAUL_CHECKLIST.md item 41 — shared cPanel hosting can't keep a
// long-running `queue:work` process alive, and nothing was ever started
// on the live server, so every queued email (password-reset codes,
// receipts, winner notices), push and Telegram alert just sat in the
// `jobs` table forever. The one cPanel cron line the deploy installs
// (`* * * * * php artisan schedule:run`) now starts a worker every
// minute that drains the queue and exits. withoutOverlapping() keeps it
// to one worker at a time, so a long job (a paced live-draw reveal)
// simply delays the next run instead of racing a second copy of itself.
// --timeout stays below DB_QUEUE_RETRY_AFTER (set to 900 by the deploy)
// so a slow job is never mistaken for a crashed one and run twice.
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=600 --max-time=240')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground();

// Clear out failed-job records older than a month so the table can't
// grow without limit; anything still worth investigating is recent.
Schedule::command('queue:prune-failed --hours=720')->daily();

// Heartbeat for `php artisan app:health-check`: proves the cPanel cron
// job is actually running, rather than assuming it.
Schedule::call(fn () => Cache::put(HealthCheck::SCHEDULER_HEARTBEAT_KEY, now()->timestamp, now()->addDay()))
    ->everyMinute()
    ->name('health-heartbeat');

// Phase 11: unclaimed red-envelope points go back to their senders.
Schedule::call(fn () => app(RedEnvelopes::class)->refundExpired())
    ->everyMinute()
    ->name('red-envelope-refunds')
    ->withoutOverlapping(5);

// Automatic payouts: ask Paystack about any payout whose answer never
// arrived (a timeout or a lost webhook). Does nothing while it's off.
Schedule::call(function () {
    if (\App\Support\Features::on('auto_payouts')) {
        app(\App\Services\PayoutService::class)->checkStuck();
    }
})
    ->everyFiveMinutes()
    ->name('payout-status-check')
    ->withoutOverlapping(10);

// Reminders ("raffle ends soon", "you left tickets in checkout").
// Does nothing while switched off in Settings → On / off → New features.
Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping(10);

// Number holds: clear out ones that have run out (they already stop
// counting the moment they expire; this only tidies the table).
Schedule::call(fn () => app(\App\Services\NumberHoldService::class)->pruneExpired())
    ->everyFiveMinutes()
    ->name('number-holds-prune')
    ->withoutOverlapping(10);

// Gaming tax: reminds staff when a month needs locking, filing or paying.
// Does nothing while switched off in Settings → Payments → Gaming tax, and
// never before 9am business time. Each reminder is sent once.
Schedule::call(fn () => app(\App\Services\GamingTaxReminders::class)->sendDue())
    ->hourly()
    ->name('gaming-tax-reminders')
    ->withoutOverlapping(10);

// Affiliates: earnings past their hold are paid into the affiliate's winnings.
Schedule::call(fn () => app(\App\Services\Growth\AffiliateService::class)->releaseDue())
    ->hourly()
    ->name('affiliate-payouts')
    ->withoutOverlapping(30);

// Nightly database backup, then a practice restore into the separate
// practice database to prove it works (Settings → Backups & status).
Schedule::command('backup:run')
    ->dailyAt(sprintf('%02d:00', (int) config('backups.hour', 3)))
    ->timezone(config('raffles.timezone'))
    ->when(fn () => (bool) config('backups.enabled', true))
    ->withoutOverlapping(120);
Schedule::command('backup:test-restore')
    ->dailyAt(sprintf('%02d:40', (int) config('backups.hour', 3)))
    ->timezone(config('raffles.timezone'))
    ->when(fn () => (bool) config('backups.enabled', true) && app(\App\Services\Monitoring\DatabaseBackup::class)->restoreConfigured())
    ->withoutOverlapping(120);

// Uptime heartbeat: an outside service (healthchecks.io, Better Stack…)
// expects this ping every few minutes and alerts you when it stops, which
// catches both "site down" and "cron job stopped".
Schedule::call(function () {
    if ($url = config('monitoring.heartbeat_url')) {
        try {
            \Illuminate\Support\Facades\Http::timeout(10)->get($url);
        } catch (\Throwable) {
            // The outside service alerts on the missing ping; nothing to do here.
        }
    }
})->everyFiveMinutes()->name('uptime-heartbeat');

// Cancelled raffles: restart any refunds that stopped part-way (the job
// is locked, so this never runs two at once and never refunds twice).
Schedule::call(function () {
    \App\Models\Raffle::query()->where('refund_status', 'refunding')->pluck('id')
        ->each(fn ($id) => \App\Jobs\RefundCancelledRaffle::dispatch($id));
})->everyFiveMinutes()->name('raffle-refund-watchdog');

// Messages to customers: start the ones scheduled for now, and pick up any
// that stopped part-way. Nobody is messaged twice (BroadcastService).
Schedule::call(function () {
    $messages = app(\App\Services\Messaging\BroadcastService::class);
    $messages->startDue();
    $messages->resumeStalled();
})->everyMinute()->name('scheduled-messages')->withoutOverlapping(5);

// Staff two-step sign-in: forget "passed the code" marks that have run out.
Schedule::call(fn () => app(\App\Services\Auth\StaffTwoStep::class)->prune())
    ->daily()
    ->name('staff-two-step-prune');

// Raffle advisor: a fresh report waiting every Monday morning (Lagos time),
// when the AI is on, a Gemini key is saved and the weekly report is switched on.
Schedule::call(function () {
    if (config('ai.advisor_weekly') && app(\App\Services\Ai\GeminiClient::class)->available()) {
        app(\App\Services\Advisor\RaffleAdvisor::class)->request(trigger: 'weekly');
    }
})
    ->weeklyOn(1, '07:00')
    ->timezone(config('raffles.timezone', 'Africa/Lagos'))
    ->name('raffle-advisor-weekly');

// Daily Drops: pay each running drop once its time comes each day. A day can
// never be paid twice (App\Services\Engagement\DailyDrops).
Schedule::call(fn () => app(\App\Services\Engagement\DailyDrops::class)->runDue())
    ->everyMinute()
    ->name('daily-drops')
    ->withoutOverlapping(10);

// Lucky Meter safety net: count any recent draw the draw itself didn't
// (a raffle can only ever be counted once; App\Services\Engagement\LuckyMeter).
Schedule::call(fn () => app(\App\Services\Engagement\LuckyMeter::class)->countRecentDraws())
    ->everyTenMinutes()
    ->name('lucky-meter')
    ->withoutOverlapping(10);

// Member segments: sort every customer again each night (Lagos time), and
// check how claimed comeback offers did (App\Services\Retention).
Schedule::call(function () {
    app(\App\Services\Retention\MemberSegments::class)->refresh();
    app(\App\Services\Retention\ComebackOffers::class)->trackResults();
    app(\App\Services\Retention\DeliveryTracker::class)->prune();
})
    ->dailyAt('04:30')
    ->timezone(config('raffles.timezone', 'Africa/Lagos'))
    ->name('member-segments')
    ->withoutOverlapping(120);

// Comeback offers: end old ones, send "last call" reminders, and once a day
// at the chosen hour make new offers. Makes nothing while switched off in
// Settings → Reminders → Comeback offers (App\Services\Retention\ComebackOffers).
Schedule::call(fn () => app(\App\Services\Retention\ComebackOffers::class)->runDue())
    ->hourly()
    ->name('comeback-offers')
    ->withoutOverlapping(30);
