<?php

namespace App\Services\Draw;

/**
 * One raffle's draw rules (the Raffle Rules Engine). They shape WHO can
 * enter, HOW MANY entries loyal players get and HOW prizes spread — the
 * draw itself stays a random, verifiable shuffle, and nothing here can
 * pick a particular person.
 *
 * Rules are published on the raffle page ("How this draw works"), can't
 * change once the first ticket is sold or the draw seed is locked, and a
 * copy is locked into the draw with its fingerprint, which the public
 * Verify page re-checks.
 */
final class DrawRules
{
    public const TIERS_ORDER = ['bronze', 'silver', 'gold', 'diamond'];

    private function __construct(
        /** Most prizes one person can win in this raffle. */
        public readonly int $maxWinsPerPerson,
        /** Anyone who won any raffle in this many days before the draw sits it out (0 = no cooldown). */
        public readonly int $recentWinnerCooldownDays,
        /** Anyone who won a top prize in this many days can't win this raffle's top prize (0 = off). */
        public readonly int $topPrizeCooldownDays,
        /** Only customers at this loyalty tier or above can buy tickets (null = everyone). */
        public readonly ?string $minTier,
        /** Only customers who have never bought a ticket before can enter. */
        public readonly bool $newPlayersOnly,
        /** Loyalty tiers earn free extra entries (Settings → Loyalty). */
        public readonly bool $loyaltyBonusEntries,
        /** Non-winners with at least this many tickets get consolation points (0 = off). */
        public readonly int $consolationMinTickets,
        public readonly int $consolationPoints,
    ) {}

    /** A raffle's saved rules, with the site defaults filling any gaps and values kept sensible. */
    public static function fromArray(?array $rules): self
    {
        $r = array_merge((array) config('raffles.default_draw_rules'), array_filter((array) $rules, fn ($v) => $v !== null && $v !== ''));
        $minTier = in_array($r['min_tier'] ?? null, self::TIERS_ORDER, true) && $r['min_tier'] !== 'bronze' ? $r['min_tier'] : null;

        return new self(
            maxWinsPerPerson: max(1, min(50, (int) ($r['max_wins_per_person'] ?? 1))),
            recentWinnerCooldownDays: max(0, min(365, (int) ($r['recent_winner_cooldown_days'] ?? 0))),
            topPrizeCooldownDays: max(0, min(365, (int) ($r['top_prize_cooldown_days'] ?? 0))),
            minTier: $minTier,
            newPlayersOnly: (bool) ($r['new_players_only'] ?? false),
            loyaltyBonusEntries: (bool) ($r['loyalty_bonus_entries'] ?? false),
            consolationMinTickets: max(0, (int) ($r['consolation_min_tickets'] ?? 0)),
            consolationPoints: max(0, (int) ($r['consolation_points'] ?? 0)),
        );
    }

    /**
     * The rules of a draw that ran before the Rules Engine existed: exactly
     * what the engine always did, so old draws still verify.
     */
    public static function legacy(): self
    {
        return self::fromArray(['max_wins_per_person' => 1, 'recent_winner_cooldown_days' => 3, 'top_prize_cooldown_days' => 0, 'loyalty_bonus_entries' => false, 'consolation_min_tickets' => 0, 'consolation_points' => 0, 'new_players_only' => false]);
    }

    public function toArray(): array
    {
        return [
            'max_wins_per_person' => $this->maxWinsPerPerson,
            'recent_winner_cooldown_days' => $this->recentWinnerCooldownDays,
            'top_prize_cooldown_days' => $this->topPrizeCooldownDays,
            'min_tier' => $this->minTier,
            'new_players_only' => $this->newPlayersOnly,
            'loyalty_bonus_entries' => $this->loyaltyBonusEntries,
            'consolation_min_tickets' => $this->consolationMinTickets,
            'consolation_points' => $this->consolationPoints,
        ];
    }

    /** The fingerprint locked into the draw: any change to the rules changes it. */
    public function hash(): string
    {
        $rules = $this->toArray();
        ksort($rules);

        return hash('sha256', json_encode($rules));
    }

    public function consolationOn(): bool
    {
        return $this->consolationMinTickets > 0 && $this->consolationPoints > 0;
    }

    /**
     * "How this draw works", in plain words, for the raffle page.
     *
     * @param  list<array{name: string, bonus_entries: int}>  $tiers  from LoyaltyService::tiers()
     * @return list<string>
     */
    public function describe(array $tiers = []): array
    {
        $lines = ['Winners are drawn at random from every ticket sold, using a secret code locked in before the draw. Anyone can check it on the Verify page.'];

        $lines[] = $this->maxWinsPerPerson === 1
            ? 'Each person can win at most one prize.'
            : "Each person can win up to {$this->maxWinsPerPerson} prizes.";

        if ($this->recentWinnerCooldownDays > 0) {
            $lines[] = "Anyone who won a raffle in the {$this->recentWinnerCooldownDays} ".($this->recentWinnerCooldownDays === 1 ? 'day' : 'days').' before the draw sits this one out, so wins spread around.';
        }

        if ($this->topPrizeCooldownDays > 0) {
            $lines[] = "Anyone who won a top prize in the last {$this->topPrizeCooldownDays} days can't win this raffle's top prize (they can still win the others).";
        }

        if ($this->minTier) {
            $lines[] = 'Only '.ucfirst($this->minTier).' loyalty members and above can enter.';
        }

        if ($this->newPlayersOnly) {
            $lines[] = 'Only for players buying their first ever ticket.';
        }

        if ($this->loyaltyBonusEntries) {
            $perks = collect($tiers)->filter(fn ($t) => ($t['bonus_entries'] ?? 0) > 0)
                ->map(fn ($t) => $t['name'].' +'.$t['bonus_entries'])
                ->implode(', ');
            $lines[] = 'Loyal players get free extra entries: '.($perks ?: 'set by tier').'. They take part in the draw just like tickets.';
        }

        if ($this->consolationOn()) {
            $lines[] = "Buy {$this->consolationMinTickets}+ tickets and don't win? You get {$this->consolationPoints} reward points.";
        }

        return $lines;
    }
}
