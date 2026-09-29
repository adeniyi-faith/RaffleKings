<?php

namespace App\Services;

use App\Exceptions\AlreadyClaimedTodayException;
use App\Models\Legacy\WpUser;
use App\Models\UserPoints;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The daily login-streak bonus — same reward schedule and streak rules
 * as the legacy rk_handle_daily_claim() (wp-core/api-gamification.php):
 * 7 escalating rewards, cycling back to day 1 after day 7, reset to day
 * 1 if a day is missed, one claim per calendar day.
 *
 * Unlike the legacy version (raw wp_usermeta reads before the credit,
 * no locking), the whole read-decide-write sequence happens inside one
 * locked transaction via PointsService, so two rapid claims can't both
 * see "not claimed yet" and both succeed.
 *
 * "A day" is a day in the business time zone (raffles.timezone, Lagos by
 * default; Settings → General), so the reward resets at midnight there,
 * not at midnight on the server's UTC clock (1am in Lagos).
 */
class DailyClaimService
{
    public function __construct(private readonly PointsService $points) {}

    /** "Now" in the business time zone, which decides where one day ends. */
    public static function now(): Carbon
    {
        return now(config('raffles.timezone', 'Africa/Lagos'));
    }

    /** When the next daily reward unlocks: the coming midnight in the business time zone. */
    public static function nextReset(): Carbon
    {
        return self::now()->addDay()->startOfDay();
    }

    /**
     * Points for day 1..7 (config/rewards.php, editable in Settings →
     * Rewards). Always exactly seven days.
     *
     * @return list<int>
     */
    private static function rewards(): array
    {
        $boost = app(PointsBoost::class);

        // A running points boost (Settings → Rewards) multiplies every day.
        return array_map(fn ($p) => $boost->apply((int) $p), array_values(config('rewards.daily_claim')));
    }

    /** The 7-day reward schedule, safe to show to players. */
    public function schedule(): array
    {
        return self::rewards();
    }

    /**
     * Where this user currently stands in the streak — what the Rewards
     * hub's daily-streak row (item 28) renders instead of re-deriving
     * streak/claim state on its own from raw ledger rows. `streak` is
     * the day already claimed if `is_claimed_today`, otherwise the day
     * claiming right now would land on (the same value `claim()` itself
     * would compute), so the UI always has a real "today" to highlight.
     *
     * @return array{streak: int, is_claimed_today: bool}
     */
    public function state(WpUser $user, ?Carbon $now = null): array
    {
        $now = ($now ?? self::now())->copy()->setTimezone(config('raffles.timezone', 'Africa/Lagos'));
        $record = UserPoints::query()->where('user_id', $user->ID)->first();
        $claimedToday = $record?->last_claim_date?->toDateString() === $now->toDateString();

        $streak = $claimedToday
            ? $record->streak_count
            : $this->nextStreak($record?->last_claim_date, $record->streak_count ?? 0, $now);

        return ['streak' => $streak, 'is_claimed_today' => $claimedToday];
    }

    /**
     * @return array{points_added: int, new_streak: int}
     *
     * @throws AlreadyClaimedTodayException
     */
    public function claim(WpUser $user, ?Carbon $now = null): array
    {
        $now = ($now ?? self::now())->copy()->setTimezone(config('raffles.timezone', 'Africa/Lagos'));

        return DB::transaction(function () use ($user, $now) {
            $record = UserPoints::query()->where('user_id', $user->ID)->lockForUpdate()->first()
                ?? UserPoints::create(['user_id' => $user->ID, 'balance' => 0, 'streak_count' => 0]);

            if ($record->last_claim_date?->toDateString() === $now->toDateString()) {
                throw new AlreadyClaimedTodayException;
            }

            $streak = $this->nextStreak($record->last_claim_date, $record->streak_count, $now);
            $reward = self::rewards()[$streak - 1];

            $record->streak_count = $streak;
            $record->last_claim_date = $now->toDateString();
            $record->save();

            $newBalance = $this->points->credit($user, $reward, 'daily_claim', description: "Day {$streak} login streak reward");

            return ['points_added' => $reward, 'new_streak' => $streak, 'new_total_points' => $newBalance];
        });
    }

    private function nextStreak(?Carbon $lastClaimDate, int $currentStreak, Carbon $now): int
    {
        if (! $lastClaimDate) {
            return 1;
        }

        if ($lastClaimDate->toDateString() === $now->copy()->subDay()->toDateString()) {
            $next = ($currentStreak ?: 1) + 1;

            return $next > 7 ? 1 : $next;
        }

        return 1; // missed at least a day — streak resets
    }
}
