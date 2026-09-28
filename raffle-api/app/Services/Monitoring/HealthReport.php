<?php

namespace App\Services\Monitoring;

use App\Console\Commands\HealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The health checks behind both `php artisan app:health-check` and the
 * admin's System → Health page: missing settings, database, scheduler and
 * queue. Only ever reports setting NAMES and OK/MISSING — never a value.
 */
final class HealthReport
{
    /** @var array<int, array{0: string, 1: string, 2: string}> [OK|WARNING|CRITICAL, check, what it means] */
    private array $rows = [];

    private int $critical = 0;

    private int $warnings = 0;

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    public function run(): array
    {
        $this->rows = [];
        $this->critical = $this->warnings = 0;

        $this->checkSettings();
        $this->checkDatabase();
        $this->checkBackgroundJobs();

        return $this->rows;
    }

    public function criticalCount(): int
    {
        return $this->critical;
    }

    public function warningCount(): int
    {
        return $this->warnings;
    }

    private function checkSettings(): void
    {
        $this->requireSetting('APP_KEY', filled(config('app.key')), 'Needed to run at all.');

        $this->requireSetting(
            'WP_LOGGED_IN_KEY / WP_LOGGED_IN_SALT / WP_COOKIEHASH',
            filled(config('legacy.wp_logged_in_key')) && filled(config('legacy.wp_logged_in_salt')) && filled(config('legacy.wp_cookiehash')),
            'Needed for anyone to stay logged in.',
        );

        $paystack = filled(config('services.paystack.secret_key'));
        $flutterwave = filled(config('services.flutterwave.secret_key'));

        $this->requireSetting(
            'PAYSTACK_SECRET_KEY or FLUTTERWAVE_SECRET_KEY',
            $paystack || $flutterwave,
            'Without at least one, nobody can top up their wallet.',
        );

        if ($paystack xor $flutterwave) {
            $this->addWarning(($paystack ? 'FLUTTERWAVE_SECRET_KEY' : 'PAYSTACK_SECRET_KEY').' (backup gateway)', 'Top-ups have no fallback if the other gateway has an outage.');
        } elseif ($paystack && $flutterwave) {
            $this->addOk('Backup payment gateway', 'Both gateways configured.');
        }

        if ($flutterwave && blank(config('services.flutterwave.secret_hash'))) {
            $this->addWarning('FLUTTERWAVE_SECRET_HASH', 'Flutterwave payment confirmations cannot be verified.');
        }

        $mailer = (string) config('mail.default');
        $mailWorks = ! in_array($mailer, ['log', 'array'], true)
            && ($mailer !== 'smtp' || filled(config('mail.mailers.smtp.host')));

        $this->requireSetting(
            'MAIL_MAILER (+ MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD)',
            $mailWorks,
            $mailWorks ? "Sending via '{$mailer}'." : "Set to '{$mailer}' — no email is delivered (password-reset codes, receipts, winner notices).",
        );

        $this->optionalSetting('TELEGRAM_BOT_TOKEN / TELEGRAM_ADMIN_CHAT_IDS', filled(config('services.telegram.bot_token')) && ! empty(config('services.telegram.admin_chat_ids')), 'Admins get no Telegram alerts for server errors, new tickets, withdrawals or draws.');
        $this->optionalSetting('ONESIGNAL_APP_ID / ONESIGNAL_API_KEY', filled(config('services.onesignal.app_id')) && filled(config('services.onesignal.api_key')), 'No push notifications are sent.');
        $this->optionalSetting('TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY', filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key')), 'Sign-up has no bot protection.');
        $this->optionalSetting('GEMINI_API_KEY', filled(config('services.gemini.api_key')), 'The admin Daily Audit statement reader is unavailable.');

        $broadcaster = (string) config('broadcasting.default');
        $this->optionalSetting(
            'BROADCAST_CONNECTION',
            ! in_array($broadcaster, ['log', 'null'], true),
            "Set to '{$broadcaster}' — live draws, live ticket counts and live chat fall back to slow periodic refreshing.",
        );

        if (str_starts_with((string) config('app.url'), 'https://')) {
            $this->optionalSetting('SESSION_SECURE_COOKIE', (bool) config('session.secure'), 'Login cookies can be sent over plain HTTP.');
        }

        if (config('app.debug') && app()->environment('production')) {
            $this->addCritical('APP_DEBUG', 'Debug mode is on in production — error pages show internal details to customers.');
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->addOk('Database connection', 'Connected.');
        } catch (Throwable $e) {
            $this->addCritical('Database connection', 'Cannot connect — check the DB_* settings.');
        }
    }

    private function checkBackgroundJobs(): void
    {
        // Stored as a plain Unix timestamp: the cache refuses to unserialize
        // objects (config/cache.php 'serializable_classes' => false).
        $heartbeat = (int) Cache::get(HealthCheck::SCHEDULER_HEARTBEAT_KEY, 0);
        $minutesAgo = intdiv(now()->timestamp - $heartbeat, 60);

        if ($heartbeat === 0) {
            $this->addCritical('Scheduler (cPanel cron job)', 'Has never run — queued emails and alerts are never sent. Add the cron line from the deploy log.');
        } elseif ($minutesAgo > 5) {
            $this->addCritical('Scheduler (cPanel cron job)', "Last ran {$minutesAgo} minutes ago — the cron job seems to have stopped.");
        } else {
            $this->addOk('Scheduler (cPanel cron job)', 'Running.');
        }

        if (config('queue.default') !== 'database') {
            return;
        }

        try {
            $waiting = DB::table(config('queue.connections.database.table', 'jobs'))->count();
            $oldest = DB::table(config('queue.connections.database.table', 'jobs'))->min('available_at');
            $failedToday = DB::table(config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable $e) {
            $this->addWarning('Queue tables', 'Could not read the jobs/failed_jobs tables — have migrations run?');

            return;
        }

        if ($oldest && now()->timestamp - (int) $oldest > 300) {
            $this->addCritical('Queue backlog', "{$waiting} job(s) waiting, the oldest for over 5 minutes — nothing is sending them.");
        } else {
            $this->addOk('Queue backlog', "{$waiting} job(s) waiting.");
        }

        if ($failedToday > 0) {
            $this->addWarning('Failed jobs (last 24h)', "{$failedToday} job(s) failed — see the failed_jobs table or `php artisan queue:failed`.");
        }
    }

    private function requireSetting(string $name, bool $present, string $whenMissing): void
    {
        $present ? $this->addOk($name, 'Set.') : $this->addCritical($name, $whenMissing);
    }

    private function optionalSetting(string $name, bool $present, string $whenMissing): void
    {
        $present ? $this->addOk($name, 'Set.') : $this->addWarning($name, $whenMissing);
    }

    private function addOk(string $name, string $detail): void
    {
        $this->rows[] = ['OK', $name, $detail];
    }

    private function addWarning(string $name, string $detail): void
    {
        $this->warnings++;
        $this->rows[] = ['WARNING', $name, $detail];
    }

    private function addCritical(string $name, string $detail): void
    {
        $this->critical++;
        $this->rows[] = ['CRITICAL', $name, $detail];
    }
}
