<?php

namespace App\Filament\Pages;

use App\Console\Commands\HealthCheck;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Models\SiteError;
use App\Services\AdminAuditLogService;
use App\Services\Monitoring\HealthReport;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * System → Health (item 45b): is the site working behind the scenes?
 * The same checks as `php artisan app:health-check`, plus background
 * jobs that failed (emails, alerts — with Retry) and recent server errors,
 * so staff don't need server access to spot a problem.
 */
class SystemHealth extends Page
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Health';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'health';

    protected static ?string $title = 'System health';

    protected static string $view = 'filament.pages.system-health';

    public function getSubheading(): ?string
    {
        return 'Refreshes every 30 seconds.';
    }

    public function getViewData(): array
    {
        $report = app(HealthReport::class);
        $checks = $report->run();
        $heartbeat = (int) Cache::get(HealthCheck::SCHEDULER_HEARTBEAT_KEY, 0);

        return [
            'checks' => collect($checks)->sortBy(fn ($row) => ['CRITICAL' => 0, 'WARNING' => 1, 'OK' => 2][$row[0]])->values(),
            'critical' => $report->criticalCount(),
            'warnings' => $report->warningCount(),
            'lastRun' => $heartbeat ? Carbon::createFromTimestamp($heartbeat)->diffForHumans() : 'never',
            'waiting' => $this->safe(fn () => DB::table(config('queue.connections.database.table', 'jobs'))->count()),
            'failed' => $this->failedJobs(),
            'errors' => $this->safe(fn () => SiteError::query()->orderByDesc('last_seen_at')->limit(30)->get(), collect()),
        ];
    }

    private function failedJobs()
    {
        return $this->safe(fn () => DB::table(config('queue.failed.table', 'failed_jobs'))
            ->orderByDesc('failed_at')->limit(50)->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true) ?: [];
                $name = class_basename($payload['displayName'] ?? 'Job');

                return (object) [
                    'uuid' => $job->uuid,
                    'name' => Str::headline(str_replace('Notification', '', $name)),
                    'error' => Str::limit(strtok((string) $job->exception, "\n"), 220),
                    'failed_at' => Carbon::parse($job->failed_at),
                ];
            }), collect());
    }

    private function safe(callable $read, mixed $fallback = null): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return $fallback;
        }
    }

    public function retry(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->log('system.job_retried', ['job' => $uuid]);
        Notification::make()->title('Sent back to the queue. It will run within a minute.')->success()->send();
    }

    public function retryAll(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->log('system.job_retried', ['job' => 'all']);
        Notification::make()->title('All failed jobs sent back to the queue.')->success()->send();
    }

    public function forget(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->log('system.job_deleted', ['job' => $uuid]);
        Notification::make()->title('Removed.')->success()->send();
    }

    public function clearErrors(): void
    {
        SiteError::query()->delete();
        $this->log('system.errors_cleared');
        Notification::make()->title('Error list cleared. New errors will appear here again.')->success()->send();
    }

    private function log(string $action, array $context = []): void
    {
        app(AdminAuditLogService::class)->record(static::admin(), $action, 'system', 0, $context);
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return null;
        }

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
