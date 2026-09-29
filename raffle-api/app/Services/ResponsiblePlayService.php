<?php

namespace App\Services;

use App\Exceptions\PlayLimitException;
use App\Models\Legacy\RaffleTransaction;
use App\Models\PlayLimit;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Responsible play (Phase 10, OVERHAUL_CHECKLIST.md item 38).
 *
 * - Spending limits: a customer caps what they spend on tickets per day,
 *   week (Monday to Sunday) and month, in the business's time zone.
 *   Lowering or adding a limit works at once; raising or removing one
 *   waits 24 hours (a "cooling-off" period, so a limit can't be undone in
 *   the heat of the moment).
 * - Take a break (self-exclusion): from 1 day to 1 year, and it can't be
 *   ended early. During a break the customer can't buy tickets, top up,
 *   spin or claim offers — but can still log in, withdraw their money
 *   and contact support.
 */
class ResponsiblePlayService
{
    public const PERIODS = ['daily', 'weekly', 'monthly'];

    /** The break lengths a customer can choose, in days. */
    public const BREAK_DAYS = [1, 7, 30, 180, 365];

    public const COOLING_OFF_HOURS = 24;

    /** Money spent on tickets (both balances). */
    private const SPEND_TYPES = ['ticket_purchase_wallet', 'ticket_purchase_earnings'];

    /**
     * Everything the settings page shows.
     *
     * @return array{limits: array<string, ?float>, spent: array<string, float>, pending: ?array<string, ?float>, pending_from: ?string, excluded_until: ?string, break_days: list<int>}
     */
    public function state(int $userId): array
    {
        $row = $this->row($userId);

        return [
            'limits' => $this->limits($row),
            'spent' => collect(self::PERIODS)->mapWithKeys(fn ($p) => [$p => $this->spent($userId, $p)])->all(),
            'pending' => $row?->pending_limits,
            'pending_from' => $row?->pending_from?->toIso8601String(),
            'excluded_until' => $this->excludedUntil($userId)?->toIso8601String(),
            'break_days' => self::BREAK_DAYS,
        ];
    }

    /** When the customer's break ends, or null if they aren't on one. */
    public function excludedUntil(int $userId): ?Carbon
    {
        $until = PlayLimit::query()->whereKey($userId)->value('excluded_until');

        return $until && Carbon::parse($until)->isFuture() ? Carbon::parse($until) : null;
    }

    /** @throws PlayLimitException when the customer is on a break */
    public function assertNotOnBreak(int $userId): void
    {
        if ($until = $this->excludedUntil($userId)) {
            throw new PlayLimitException($this->breakMessage($until));
        }
    }

    public function breakMessage(Carbon $until): string
    {
        return 'You are taking a break until '.$this->local($until)->format('j M Y, g:ia')
            .'. You can still withdraw your money and contact support.';
    }

    /**
     * Refuses a ticket purchase that the customer's break or limits don't
     * allow. Call inside the purchase's database transaction, after the
     * wallet row is locked, so two purchases at once can't both slip in.
     *
     * @throws PlayLimitException
     */
    public function assertCanSpend(int $userId, float $amount): void
    {
        $this->assertNotOnBreak($userId);

        $limits = $this->limits($this->row($userId));
        $names = ['daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly'];
        $when = ['daily' => 'today', 'weekly' => 'this week', 'monthly' => 'this month'];

        foreach (self::PERIODS as $period) {
            $limit = $limits[$period];

            if ($limit === null) {
                continue;
            }

            $spent = $this->spent($userId, $period);

            if ($spent + $amount > $limit + 0.001) {
                $left = max(0, $limit - $spent);

                throw new PlayLimitException(sprintf(
                    'This would go over your %s spending limit of ₦%s. You can spend ₦%s more %s. No money has been taken.',
                    $names[$period],
                    number_format($limit),
                    number_format($left, $left == floor($left) ? 0 : 2),
                    $when[$period],
                ));
            }
        }
    }

    /**
     * Saves new limits (null = no limit). Stricter ones apply now; looser
     * ones (a higher amount, or removing a limit) wait 24 hours.
     *
     * @param  array<string, float|int|string|null>  $wanted  keyed by period
     * @return array{applied: list<string>, pending: list<string>}
     */
    public function setLimits(int $userId, array $wanted): array
    {
        $row = PlayLimit::query()->firstOrNew(['user_id' => $userId]);
        $this->applyDuePending($row);

        $pending = [];
        $applied = [];
        $settled = []; // periods whose earlier waiting change no longer applies

        foreach (self::PERIODS as $period) {
            if (! array_key_exists($period, $wanted)) {
                continue;
            }

            $new = $wanted[$period] === null || $wanted[$period] === '' ? null : round((float) $wanted[$period], 2);

            if ($new !== null && $new < 100) {
                throw new InvalidArgumentException('A limit must be at least ₦100, or leave it empty for no limit.');
            }

            $current = $row->{$period.'_limit'} === null ? null : (float) $row->{$period.'_limit'};

            if ($new === $current) {
                $settled[] = $period; // back to the current limit: cancel any waiting change

                continue;
            }

            $stricter = $new !== null && ($current === null || $new < $current);

            if ($stricter) {
                $row->{$period.'_limit'} = $new;
                $applied[] = $period;
                $settled[] = $period;
            } else {
                $pending[$period] = $new;
            }
        }

        // A new looser request replaces any earlier one and restarts the wait.
        $left = array_diff_key((array) $row->pending_limits, array_flip($settled));

        if ($pending) {
            $row->pending_limits = $pending + $left;
            $row->pending_from = now()->addHours(self::COOLING_OFF_HOURS);
        } else {
            $row->pending_limits = $left ?: null;
            $row->pending_from = $left ? $row->pending_from : null;
        }

        $row->save();

        return ['applied' => $applied, 'pending' => array_keys($pending)];
    }

    /** Starts (or lengthens) a break. It can't be shortened or ended early. */
    public function takeBreak(int $userId, int $days): Carbon
    {
        if (! in_array($days, self::BREAK_DAYS, true)) {
            throw new InvalidArgumentException('Choose one of the break lengths shown.');
        }

        $row = PlayLimit::query()->firstOrNew(['user_id' => $userId]);
        $until = now()->addDays($days);

        if ($row->excluded_until && $row->excluded_until->greaterThan($until)) {
            $until = $row->excluded_until;
        }

        $row->excluded_until = $until;
        $row->save();

        return $until;
    }

    /** Ticket spending in the current day, week or month (business time zone). */
    public function spent(int $userId, string $period): float
    {
        $local = now(config('raffles.timezone', 'Africa/Lagos'));
        $from = match ($period) {
            'daily' => $local->copy()->startOfDay(),
            'weekly' => $local->copy()->startOfWeek(Carbon::MONDAY),
            'monthly' => $local->copy()->startOfMonth(),
        };

        return round((float) RaffleTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('type', self::SPEND_TYPES)
            ->where('status', 'verified_final')
            ->where('created_at', '>=', $from->utc())
            ->sum('claimed_amount'), 2);
    }

    private function row(int $userId): ?PlayLimit
    {
        $row = PlayLimit::query()->find($userId);

        if ($row && $this->applyDuePending($row)) {
            $row->save();
        }

        return $row;
    }

    /** Applies looser limits whose 24-hour wait is over. True if it changed anything. */
    private function applyDuePending(PlayLimit $row): bool
    {
        if (! $row->pending_limits || ! $row->pending_from || $row->pending_from->isFuture()) {
            return false;
        }

        foreach ($row->pending_limits as $period => $value) {
            if (in_array($period, self::PERIODS, true)) {
                $row->{$period.'_limit'} = $value;
            }
        }

        $row->pending_limits = null;
        $row->pending_from = null;

        return true;
    }

    /** @return array<string, ?float> */
    private function limits(?PlayLimit $row): array
    {
        return collect(self::PERIODS)->mapWithKeys(fn ($p) => [
            $p => $row?->{$p.'_limit'} === null ? null : (float) $row->{$p.'_limit'},
        ])->all();
    }

    private function local(Carbon $time): Carbon
    {
        return $time->copy()->setTimezone(config('raffles.timezone', 'Africa/Lagos'));
    }
}
