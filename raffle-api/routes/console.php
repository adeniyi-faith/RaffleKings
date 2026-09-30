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

// Affiliates: earnings past their hold are paid into the affiliate's winnings.
Schedule::call(fn () => app(\App\Services\Growth\AffiliateService::class)->releaseDue())
    ->hourly()
    ->name('affiliate-payouts')
    ->withoutOverlapping(30);
