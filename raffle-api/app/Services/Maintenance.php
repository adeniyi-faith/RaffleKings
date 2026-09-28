<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Maintenance mode (Settings → On / off). Either switched on by hand, or
 * scheduled with a start time; either way it switches itself off at the
 * "back at" time if one is set. While it's on, customers see the
 * maintenance page (App\Http\Middleware\MaintenanceMode) — staff, the
 * admin and payment-provider confirmations are never blocked.
 */
final class Maintenance
{
    private function at(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value, 'UTC') : null;
    }

    public function active(?Carbon $now = null): bool
    {
        $now ??= now();
        $config = config('site.maintenance', []);
        $starts = $this->at($config['starts_at'] ?? null);
        $back = $this->at($config['back_at'] ?? null);

        if ($back && $now->gte($back)) {
            return false; // the planned end has passed — back to normal on its own
        }

        return ! empty($config['enabled']) || ($starts && $now->gte($starts));
    }

    public function backAt(): ?Carbon
    {
        return $this->at(config('site.maintenance.back_at'));
    }

    /** Scheduled maintenance starting within the warning window — for the heads-up banner. */
    public function upcoming(?Carbon $now = null): ?array
    {
        $now ??= now();
        $starts = $this->at(config('site.maintenance.starts_at'));
        $hours = (int) config('site.maintenance.warn_hours', 12);

        if (! $starts || $hours <= 0 || $this->active($now) || $now->gte($starts) || $now->diffInHours($starts, true) > $hours) {
            return null;
        }

        return [
            'starts_at' => $starts->toIso8601String(),
            'back_at' => $this->backAt()?->toIso8601String(),
        ];
    }

    /** What the maintenance page shows. */
    public function page(): array
    {
        return [
            'message' => (string) config('site.maintenance.message'),
            'back_at' => $this->backAt()?->toIso8601String(),
        ];
    }
}
