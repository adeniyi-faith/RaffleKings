<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Services\Draw\DrawRules;
use Illuminate\Support\Carbon;

/**
 * Loyalty tiers (Raffle Rules Engine): Bronze → Silver → Gold → Diamond,
 * earned by CONSISTENCY — how many of the last few weeks a customer
 * played, and how many tickets they bought in that time (config/
 * loyalty.php, Settings → Loyalty). A tier's perk is free bonus entries
 * in raffles whose draw rules switch them on, and access to members-only
 * raffles. Everything here is shown to the customer too.
 */
class LoyaltyService
{
    /** @var array<int, array> per-request cache */
    private array $profiles = [];

    /**
     * The tiers, lowest first, cleaned up (Bronze always exists and needs nothing).
     *
     * @return list<array{key: string, name: string, min_active_weeks: int, min_tickets: int, bonus_entries: int}>
     */
    public function tiers(): array
    {
        $byKey = collect((array) config('loyalty.tiers'))->keyBy('key');

        // A tier missing from the settings is simply unreachable (never "free").
        return collect(DrawRules::TIERS_ORDER)
            ->filter(fn ($key) => $key === 'bronze' || $byKey->has($key))
            ->map(fn ($key) => [
                'key' => $key,
                'name' => (string) ($byKey[$key]['name'] ?? ucfirst($key)),
                'min_active_weeks' => $key === 'bronze' ? 0 : max(0, (int) ($byKey[$key]['min_active_weeks'] ?? 0)),
                'min_tickets' => $key === 'bronze' ? 0 : max(0, (int) ($byKey[$key]['min_tickets'] ?? 0)),
                'bonus_entries' => max(0, min(20, (int) ($byKey[$key]['bonus_entries'] ?? 0))),
            ])
            ->values()
            ->all();
    }

    public function windowWeeks(): int
    {
        return max(1, min(52, (int) config('loyalty.window_weeks', 8)));
    }

    /**
     * Where a customer stands: their tier, the numbers behind it, and what
     * the next tier needs.
     *
     * @return array{tier: array, active_weeks: int, tickets: int, window_weeks: int, next: ?array, tiers: list<array>}
     */
    public function profile(int $userId, ?Carbon $now = null): array
    {
        if (! $now && isset($this->profiles[$userId])) {
            return $this->profiles[$userId];
        }

        $tz = config('raffles.timezone', 'Africa/Lagos');
        $now = ($now ?? now())->copy()->setTimezone($tz);
        $weeks = $this->windowWeeks();
        $from = $now->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($weeks - 1);

        $dates = RaffleEntry::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $from->copy()->utc())
            ->pluck('created_at');

        $tickets = $dates->count();
        $activeWeeks = $dates
            ->map(fn ($d) => Carbon::parse($d, 'UTC')->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->toDateString())
            ->unique()
            ->count();

        $tiers = $this->tiers();
        $meets = fn (array $tier) => $activeWeeks >= $tier['min_active_weeks'] && $tickets >= $tier['min_tickets'];

        // The highest tier whose requirements are met.
        $current = collect($tiers)->filter($meets)->last() ?? $tiers[0];

        // The first tier above it, and exactly what it still needs.
        $nextTier = collect($tiers)->first(fn ($tier) => $this->rank($tier['key']) > $this->rank($current['key']));
        $next = $nextTier ? $nextTier + [
            'weeks_needed' => max(0, $nextTier['min_active_weeks'] - $activeWeeks),
            'tickets_needed' => max(0, $nextTier['min_tickets'] - $tickets),
        ] : null;

        $profile = [
            'tier' => $current,
            'active_weeks' => $activeWeeks,
            'tickets' => $tickets,
            'window_weeks' => $weeks,
            'next' => $next,
            'tiers' => $tiers,
        ];

        return $this->profiles[$userId] = $profile;
    }

    public function rank(?string $tierKey): int
    {
        $index = array_search($tierKey, DrawRules::TIERS_ORDER, true);

        return $index === false ? 0 : $index;
    }

    public function meets(int $userId, ?string $minTier): bool
    {
        return ! $minTier || $this->rank($this->profile($userId)['tier']['key']) >= $this->rank($minTier);
    }

    /** Forget cached profiles (after a purchase changes the numbers). */
    public function forget(int $userId): void
    {
        unset($this->profiles[$userId]);
    }
}
