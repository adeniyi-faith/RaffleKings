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
 */
class DailyClaimService
{
    private const REWARDS = [50, 70, 100, 150, 200, 300, 1000];

    public function __construct(private readonly PointsService $points) {}

    /**
     * @return array{points_added: int, new_streak: int}
     *
     * @throws AlreadyClaimedTodayException
     */
    public function claim(WpUser $user, ?Carbon $now = null): array
    {
        $now ??= now();

        return DB::transaction(function () use ($user, $now) {
            $record = UserPoints::query()->where('user_id', $user->ID)->lockForUpdate()->first()
                ?? UserPoints::create(['user_id' => $user->ID, 'balance' => 0, 'streak_count' => 0]);

            if ($record->last_claim_date?->isSameDay($now)) {
                throw new AlreadyClaimedTodayException;
            }

            $streak = $this->nextStreak($record->last_claim_date, $record->streak_count, $now);
            $reward = self::REWARDS[$streak - 1];

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

        if ($lastClaimDate->isSameDay($now->copy()->subDay())) {
            $next = ($currentStreak ?: 1) + 1;

            return $next > 7 ? 1 : $next;
        }

        return 1; // missed at least a day — streak resets
    }
}
