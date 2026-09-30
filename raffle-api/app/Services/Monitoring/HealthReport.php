<?php

namespace App\Services\Monitoring;

use App\Console\Commands\HealthCheck;
use App\Models\BackupRun;
use App\Notifications\Channels\OneSignalChannel;
use App\Services\Maintenance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->checkMaintenance();
        $this->checkPhpExtensions();
        $this->checkBackupsAndUptime();

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
            $mailWorks ? "Sending via '{$mailer}'." : "Set to '{$mailer}', so no email is delivered (password-reset codes, receipts, winner notices).",
        );

        $this->optionalSetting('TELEGRAM_BOT_TOKEN / TELEGRAM_ADMIN_CHAT_IDS', filled(config('services.telegram.bot_token')) && ! empty(config('services.telegram.admin_chat_ids')), 'Admins get no Telegram alerts for server errors, new tickets, withdrawals or draws.');
        if (filled(config('services.onesignal.app_id')) && ! OneSignalChannel::appId()) {
            $this->addWarning('ONESIGNAL_APP_ID', 'Not a valid OneSignal App ID (it should look like 1a2b3c4d-1111-2222-3333-444455556666), so push notifications are skipped. Copy it from OneSignal → Settings → Keys & IDs.');
        } else {
            $this->optionalSetting('ONESIGNAL_APP_ID / ONESIGNAL_API_KEY', filled(config('services.onesignal.app_id')) && filled(config('services.onesignal.api_key')), 'No push notifications are sent.');
        }
        $this->optionalSetting('TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY', filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key')), 'Sign-up has no bot protection.');
        $this->optionalSetting('GEMINI_API_KEY', filled(config('services.gemini.api_key')), 'The admin Daily Audit statement reader is unavailable.');

        $broadcaster = (string) config('broadcasting.default');

        if ($broadcaster === 'pusher') {
            $pusher = config('broadcasting.connections.pusher');
            $complete = filled($pusher['app_id'] ?? null) && filled($pusher['key'] ?? null) && filled($pusher['secret'] ?? null);
            $complete
                ? $this->addOk('Live updates (Pusher)', 'On, cluster '.($pusher['options']['cluster'] ?? 'mt1').'.')
                : $this->addCritical('Live updates (Pusher)', 'Switched on but the app_id, key or secret is missing, so live events fail. Fill them in Settings → Alerts & push, or switch live updates off.');
        }

        $this->optionalSetting(
            'BROADCAST_CONNECTION',
            ! in_array($broadcaster, ['log', 'null'], true),
            "Set to '{$broadcaster}', so live draws, live ticket counts and live chat fall back to slow periodic refreshing.",
        );

        if (str_starts_with((string) config('app.url'), 'https://')) {
            $this->optionalSetting('SESSION_SECURE_COOKIE', (bool) config('session.secure'), 'Login cookies can be sent over plain HTTP.');
        }

        $this->optionalSetting(
            'SENTRY_LARAVEL_DSN',
            filled(config('sentry.dsn')),
            'Sentry error tracking is off. Errors still show here and in Telegram; Sentry adds full details and grouping. Create a free project at sentry.io and paste its DSN in Settings → Alerts & push.',
        );

        if (config('app.debug') && app()->environment('production')) {
            $this->addCritical('APP_DEBUG', 'Debug mode is on in production, so error pages show internal details to customers.');
        }
    }

    /** Backups (and whether they're proven to restore), uptime alerts and Sentry. */
    private function checkBackupsAndUptime(): void
    {
        try {
            $last = BackupRun::query()->where('status', 'ok')->latest('id')->first();
            $failed = BackupRun::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->exists();
        } catch (Throwable) {
            return; // not migrated yet
        }

        if (! config('backups.enabled', true)) {
            $this->addCritical('Database backups', 'Switched OFF (Settings → Backups & status). If the server fails, the data is gone.');
        } elseif ($failed) {
            $this->addCritical('Database backups', 'Last night\'s backup failed. Run `php artisan backup:run` to see why.');
        } elseif (! $last) {
            $this->addWarning('Database backups', 'No backup yet. The first runs tonight, or run `php artisan backup:run` now.');
        } elseif ($last->created_at->lt(now()->subHours(26))) {
            $this->addCritical('Database backups', 'The last backup is '.$last->created_at->diffForHumans().'. The nightly backup has stopped.');
        } else {
            $this->addOk('Database backups', 'Last backup '.$last->created_at->diffForHumans().' ('.round($last->size_bytes / 1048576, 1).' MB).'.($last->sent_offsite ? ' Copy sent to Telegram.' : ''));
        }

        $tested = BackupRun::query()->whereNotNull('restore_tested_at')->latest('restore_tested_at')->first();

        if (! app(DatabaseBackup::class)->restoreConfigured()) {
            $this->addWarning('Backup practice restore', 'Not set up, so no backup has been PROVEN to work. Add a practice database in Settings → Backups & status.');
        } elseif (! $tested) {
            $this->addWarning('Backup practice restore', 'Not run yet. It runs after tonight\'s backup, or run `php artisan backup:test-restore`.');
        } elseif (! $tested->restore_ok) {
            $this->addCritical('Backup practice restore', 'The last practice restore FAILED: '.$tested->restore_message);
        } elseif ($tested->restore_tested_at->lt(now()->subDays(8))) {
            $this->addWarning('Backup practice restore', 'Last proven '.$tested->restore_tested_at->diffForHumans().'.');
        } else {
            $this->addOk('Backup practice restore', 'Proven '.$tested->restore_tested_at->diffForHumans().': '.$tested->restore_message);
        }

        if (config('backups.enabled', true) && ! config('backups.send_to_telegram')) {
            $this->addWarning('Off-site backup copy', 'Backups are only on this server. Turn on "send each backup to Telegram" (Settings → Backups & status), or download them regularly.');
        }

        $this->optionalSetting('Uptime heartbeat (Settings → Backups & status)', filled(config('monitoring.heartbeat_url')), 'Nobody is told if the site or its cron job stops. Add a healthchecks.io ping URL.');
        $this->optionalSetting('Sentry error tracking (Settings → Alerts & push)', filled(config('sentry.dsn')), 'Errors are only in the log and Telegram, without full details.');
    }

    private function checkMaintenance(): void
    {
        if (app(Maintenance::class)->active()) {
            $this->addWarning('Maintenance mode', 'ON. Customers can\'t use the site. Switch it off in Settings → On / off.');
        }
    }

    /**
     * PHP add-ons the host must switch on (cPanel → Select PHP Version →
     * Extensions). The site copes without mbstring and intl, but runs
     * slower and some number formats are plainer, so they're a warning.
     */
    private function checkPhpExtensions(): void
    {
        $required = ['openssl', 'curl', 'fileinfo', 'tokenizer', 'ctype'];
        if (DB::connection()->getDriverName() === 'mysql') {
            $required[] = 'pdo_mysql';
        }

        $missing = array_values(array_filter($required, fn ($ext) => ! extension_loaded($ext)));
        $recommended = array_values(array_filter(['mbstring', 'intl'], fn ($ext) => ! extension_loaded($ext)));

        if ($missing !== []) {
            $this->addCritical('PHP extensions', 'Missing: '.implode(', ', $missing).'. Turn them on in cPanel → Select PHP Version → Extensions.');
        } elseif ($recommended !== []) {
            $this->addWarning('PHP extensions', 'Missing: '.implode(', ', $recommended).'. The site works, but turn them on in cPanel → Select PHP Version → Extensions for speed and full number formatting.');
        } else {
            $this->addOk('PHP extensions', 'All needed add-ons are on.');
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->addOk('Database connection', 'Connected.');
            $this->checkLegacyColumns();
        } catch (Throwable $e) {
            $this->addCritical('Database connection', 'Cannot connect. Check the DB_* settings.');
        }
    }

    /**
     * Columns the new site added to the OLD site's tables. One missing on
     * the live site (idempotency_key) broke every wallet purchase; the
     * deploy's `migrate` restores them, and this says so if it didn't.
     */
    private function checkLegacyColumns(): void
    {
        $prefix = (string) config('legacy.wp_prefix');
        $required = [
            'raffle_transactions' => ['idempotency_key', 'pending_raffle_id', 'pending_numbers'],
        ];
        $missing = [];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($prefix.$table)) {
                $missing[] = $prefix.$table.' (whole table)';

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($prefix.$table, $column)) {
                    $missing[] = $prefix.$table.'.'.$column;
                }
            }
        }

        $missing === []
            ? $this->addOk('Old-site table columns', 'All present.')
            : $this->addCritical('Old-site table columns', 'Missing: '.implode(', ', $missing).'. Ticket purchases fail until `php artisan migrate --force` adds them.');
    }

    private function checkBackgroundJobs(): void
    {
        // Stored as a plain Unix timestamp: the cache refuses to unserialize
        // objects (config/cache.php 'serializable_classes' => false).
        $heartbeat = (int) Cache::get(HealthCheck::SCHEDULER_HEARTBEAT_KEY, 0);
        $minutesAgo = intdiv(now()->timestamp - $heartbeat, 60);

        if ($heartbeat === 0) {
            $this->addCritical('Scheduler (cPanel cron job)', 'Has never run, so queued emails and alerts are never sent. Add the cron line from the deploy log.');
        } elseif ($minutesAgo > 5) {
            $this->addCritical('Scheduler (cPanel cron job)', "Last ran {$minutesAgo} minutes ago. The cron job seems to have stopped.");
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
            $this->addWarning('Queue tables', 'Could not read the jobs/failed_jobs tables. Have migrations run?');

            return;
        }

        if ($oldest && now()->timestamp - (int) $oldest > 300) {
            $this->addCritical('Queue backlog', "{$waiting} job(s) waiting, the oldest for over 5 minutes. Nothing is sending them.");
        } else {
            $this->addOk('Queue backlog', "{$waiting} job(s) waiting.");
        }

        if ($failedToday > 0) {
            $this->addWarning('Failed jobs (last 24h)', "{$failedToday} job(s) failed. See System → Health or `php artisan queue:failed`.");
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
