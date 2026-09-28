<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * A time-limited points promotion (Settings → Rewards → Points boost):
 * e.g. ×2 from Friday 6pm to Sunday midnight. Applies to daily-claim and
 * task points only — never to Spin & Win payouts or refunds.
 */
final class PointsBoost
{
    public function active(?Carbon $at = null): bool
    {
        $at ??= now();
        $boost = config('rewards.boost', []);
        $starts = filled($boost['starts_at'] ?? null) ? Carbon::parse($boost['starts_at'], 'UTC') : null;
        $ends = filled($boost['ends_at'] ?? null) ? Carbon::parse($boost['ends_at'], 'UTC') : null;

        return (float) ($boost['multiplier'] ?? 1) > 1
            && $ends !== null
            && ($starts === null || $at->gte($starts))
            && $at->lt($ends);
    }

    public function multiplier(?Carbon $at = null): float
    {
        return $this->active($at) ? (float) config('rewards.boost.multiplier') : 1.0;
    }

    public function apply(int $points, ?Carbon $at = null): int
    {
        return (int) round($points * $this->multiplier($at));
    }

    /** What the customer site shows while a boost runs. @return array{label: string, multiplier: float, ends_at: string}|null */
    public function banner(): ?array
    {
        if (! $this->active()) {
            return null;
        }

        return [
            'label' => (string) (config('rewards.boost.label') ?: 'Points boost'),
            'multiplier' => (float) config('rewards.boost.multiplier'),
            'ends_at' => Carbon::parse(config('rewards.boost.ends_at'), 'UTC')->toIso8601String(),
        ];
    }
}
