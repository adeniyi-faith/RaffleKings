<?php

namespace App\Services\Engagement;

use App\Models\Legacy\WpUser;
use App\Models\SeasonClaim;
use App\Models\SeasonProgress;
use App\Notifications\EngagementAlert;
use App\Services\PointsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Season Pass (Phase 11): a free 4-week track with 30 levels. XP comes
 * from playing (capped per day, so spending more never races ahead),
 * daily check-ins, tasks and predictions. Each level has a reward the
 * customer taps to collect: points, and on some levels a free spin, a
 * free bonus-entry token or a badge. Seasons run back to back.
 */
class SeasonPass
{
    public function __construct(
        private readonly PointsService $points,
        private readonly Perks $perks,
        private readonly BadgeService $badges,
    ) {}

    private function tz(): string
    {
        return (string) config('raffles.timezone', 'Africa/Lagos');
    }

    public function levels(): int
    {
        return max(1, (int) config('engagement.season.levels', 30));
    }

    public function xpPerLevel(): int
    {
        return max(1, (int) config('engagement.season.xp_per_level', 100));
    }

    /** @return array{number: int, starts_at: Carbon, ends_at: Carbon} */
    public function current(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->setTimezone($this->tz());
        $days = max(7, (int) config('engagement.season.weeks', 4) * 7);
        $anchor = Carbon::parse((string) config('engagement.season.starts_on', '2026-10-05'), $this->tz())->startOfDay();
        $index = (int) floor($anchor->diffInDays($now->copy()->startOfDay(), false) / $days);
        $start = $anchor->copy()->addDays($index * $days);

        return ['number' => max(1, $index + 1), 'starts_at' => $start, 'ends_at' => $start->copy()->addDays($days)];
    }

    /** What reaching one level gives. */
    public function reward(int $level): array
    {
        $points = max(0, (int) config('engagement.season.level_points', 25) + ($level - 1) * (int) config('engagement.season.level_points_step', 5));

        return [
            'points' => $points,
            'free_spins' => $level % 5 === 0 ? 1 : 0,
            'bonus_entries' => in_array($level, [10, 20, 30], true) ? 1 : 0,
            'badge' => match (true) {
                $level === 10 => 'season_10',
                $level === $this->levels() => 'season_30',
                default => null,
            },
        ];
    }

    public function addXp(int $userId, int $xp, ?string $source = null): void
    {
        if ($xp < 1) {
            return;
        }

        $season = $this->current()['number'];
        $levelBefore = $this->levelFor($this->progress($userId, $season)->xp);

        DB::transaction(function () use ($userId, $xp, $season, $source) {
            $row = $this->lockedProgress($userId, $season);

            if ($source === 'ticket') {
                $today = now()->setTimezone($this->tz())->toDateString();
                $used = $row->ticket_xp_day?->toDateString() === $today ? $row->ticket_xp_today : 0;
                $xp = min($xp, max(0, (int) config('engagement.season.xp.ticket_daily_cap', 150) - $used));
                $row->ticket_xp_day = $today;
                $row->ticket_xp_today = $used + $xp;
            }

            $row->xp += $xp;
            $row->save();
        });

        $levelAfter = $this->levelFor($this->progress($userId, $season)->xp);

        if ($levelAfter > $levelBefore) {
            WpUser::find($userId)?->notify(new EngagementAlert(
                "Season Pass: level {$levelAfter}! ⬆️",
                'You have a new reward waiting. Tap to collect it.',
                '/rewards/season',
                'Collect',
            ));
        }
    }

    public function levelFor(int $xp): int
    {
        return min($this->levels(), intdiv(max(0, $xp), $this->xpPerLevel()));
    }

    /** Collects every reached level's reward not yet collected. Returns what was given. */
    public function claimAll(WpUser $user): array
    {
        $season = $this->current()['number'];
        $level = $this->levelFor($this->progress($user->ID, $season)->xp);
        $claimed = SeasonClaim::query()->where('user_id', $user->ID)->where('season', $season)->pluck('level')->all();
        $total = ['levels' => [], 'points' => 0, 'free_spins' => 0, 'bonus_entries' => 0, 'badges' => []];

        for ($l = 1; $l <= $level; $l++) {
            if (in_array($l, $claimed, true)) {
                continue;
            }

            try {
                SeasonClaim::create(['user_id' => $user->ID, 'season' => $season, 'level' => $l, 'claimed_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                continue; // collected by another tap at the same moment
            }

            $r = $this->reward($l);

            if ($r['points'] > 0) {
                $this->points->credit($user, $r['points'], 'season_pass', description: "Season {$season} level {$l} reward");
            }

            $this->perks->addFreeSpins($user->ID, $r['free_spins']);
            $this->perks->addBonusTokens($user->ID, $r['bonus_entries']);

            if ($r['badge'] && $this->badges->award($user->ID, $r['badge'])) {
                $total['badges'][] = $r['badge'];
            }

            $total['levels'][] = $l;
            $total['points'] += $r['points'];
            $total['free_spins'] += $r['free_spins'];
            $total['bonus_entries'] += $r['bonus_entries'];
        }

        return $total;
    }

    /** Everything the Season Pass page shows. */
    public function state(int $userId): array
    {
        $season = $this->current();
        $xp = $this->progress($userId, $season['number'])->xp;
        $level = $this->levelFor($xp);
        $claimed = SeasonClaim::query()->where('user_id', $userId)->where('season', $season['number'])->pluck('level')->all();
        $catalog = $this->badges->catalog();

        return [
            'season' => $season['number'],
            'starts_at' => $season['starts_at']->toIso8601String(),
            'ends_at' => $season['ends_at']->toIso8601String(),
            'xp' => $xp,
            'level' => $level,
            'xp_per_level' => $this->xpPerLevel(),
            'xp_into_level' => $level >= $this->levels() ? $this->xpPerLevel() : $xp % $this->xpPerLevel(),
            'claimable' => max(0, $level - count(array_filter($claimed, fn ($l) => $l <= $level))),
            'xp_sources' => (array) config('engagement.season.xp'),
            'levels' => collect(range(1, $this->levels()))->map(function ($l) use ($level, $claimed, $catalog) {
                $r = $this->reward($l);

                return $r + [
                    'level' => $l,
                    'badge_emoji' => $r['badge'] ? ($catalog[$r['badge']]['emoji'] ?? null) : null,
                    'badge_name' => $r['badge'] ? ($catalog[$r['badge']]['name'] ?? null) : null,
                    'reached' => $l <= $level,
                    'claimed' => in_array($l, $claimed, true),
                ];
            })->all(),
        ];
    }

    // ---- Progress listener -------------------------------------------------

    public function ticketsBought(int $userId, int $count, int $total): void
    {
        $this->addXp($userId, $count * (int) config('engagement.season.xp.ticket', 10), 'ticket');
    }

    public function dailyClaimed(int $userId, int $streak): void
    {
        $this->addXp($userId, (int) config('engagement.season.xp.daily_claim', 25));
    }

    public function taskCompleted(int $userId, string $taskId): void
    {
        $this->addXp($userId, (int) config('engagement.season.xp.task', 15));
    }

    // ------------------------------------------------------------------------

    private function progress(int $userId, int $season): SeasonProgress
    {
        return SeasonProgress::query()->where('user_id', $userId)->where('season', $season)->first()
            ?? new SeasonProgress(['user_id' => $userId, 'season' => $season, 'xp' => 0]);
    }

    private function lockedProgress(int $userId, int $season): SeasonProgress
    {
        SeasonProgress::query()->firstOrCreate(['user_id' => $userId, 'season' => $season]);

        return SeasonProgress::query()->where('user_id', $userId)->where('season', $season)->lockForUpdate()->first();
    }
}
