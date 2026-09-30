<?php

namespace App\Services;

use App\Models\Raffle;

/**
 * The chance of winning each prize level in a raffle, worked out one way for
 * everyone: the player's raffle page, the raffle setup screen in the admin,
 * and the API, so they can never disagree.
 *
 * How it is worked out: a draw picks winners from the tickets, and each
 * ticket can win at most one prize. So with `pool` tickets, `winners` of
 * them winning a level, and a player holding `tickets`, the chance of at
 * least one win is 1 − C(pool − winners, tickets) / C(pool, tickets).
 *
 * The pool is the raffle's full ticket count (the odds if every ticket
 * sells). The draw itself is over the tickets actually sold, so when fewer
 * sell, real odds are better than shown. Bonus entries and prize limits per
 * person also move the numbers a little, so these are honest estimates.
 */
final class OddsCalculator
{
    /**
     * Chance that at least one of `$tickets` tickets is among `$winners` winning tickets in a pool of `$pool`.
     */
    public static function atLeastOne(int $pool, int $winners, int $tickets): float
    {
        if ($pool <= 0 || $winners <= 0 || $tickets <= 0) {
            return 0.0;
        }

        if ($tickets >= $pool || $winners >= $pool) {
            return 1.0;
        }

        $none = 1.0;

        for ($i = 0; $i < $tickets; $i++) {
            $none *= ($pool - $winners - $i) / ($pool - $i);

            if ($none <= 0) {
                return 1.0;
            }
        }

        return max(0.0, min(1.0, 1 - $none));
    }

    /** "1 in N" for one ticket, or null when nothing can win. */
    public static function oneIn(int $pool, int $winners): ?int
    {
        return $winners > 0 && $pool > 0 ? max(1, (int) round($pool / min($winners, $pool))) : null;
    }

    /** A plain-words percentage: "45%", "1.5%", "<0.1%". */
    public static function percent(float $probability): string
    {
        return match (true) {
            $probability >= 0.995 => '99%+',
            $probability >= 0.1 => round($probability * 100).'%',
            $probability >= 0.001 => number_format($probability * 100, 1).'%',
            $probability > 0 => '<0.1%',
            default => '0%',
        };
    }

    /**
     * @param  list<array{name: string, description: ?string, value: float, winners: int}>  $tiers
     * @return array{pool: int, quantity: int, any: array{probability: float, percent: string, one_in: ?int}, tiers: list<array<string, mixed>>, winners_total: int, prize_total: float}
     */
    public function forTiers(array $tiers, int $pool, int $quantity): array
    {
        $pool = max(1, $pool);
        $quantity = max(1, min($quantity, $pool));
        $winnersTotal = 0;
        $prizeTotal = 0.0;
        $out = [];

        foreach ($tiers as $tier) {
            $winners = max(0, min((int) $tier['winners'], $pool - $winnersTotal));
            $probability = self::atLeastOne($pool, $winners, $quantity);
            $winnersTotal += $winners;
            $prizeTotal += $winners * (float) $tier['value'];

            $out[] = [
                'name' => (string) $tier['name'],
                'description' => $tier['description'] ?? null,
                'value' => round((float) $tier['value'], 2),
                'winners' => $winners,
                'one_in' => self::oneIn($pool, $winners),
                'probability' => round($probability, 6),
                'percent' => self::percent($probability),
            ];
        }

        $any = self::atLeastOne($pool, $winnersTotal, $quantity);

        return [
            'pool' => $pool,
            'quantity' => $quantity,
            // "1 in N" only reads sensibly when a win is less likely than not; above 50% the percentage says it better.
            'any' => ['probability' => round($any, 6), 'percent' => self::percent($any), 'one_in' => $any > 0 && $any <= 0.5 ? max(2, (int) round(1 / $any)) : null],
            'tiers' => $out,
            'winners_total' => $winnersTotal,
            'prize_total' => round($prizeTotal, 2),
        ];
    }

    /** The prize levels of a raffle, best first. A raffle with none has one grand prize. @return list<array{name: string, description: ?string, value: float, winners: int}> */
    public function tiersOf(Raffle $raffle): array
    {
        $tiers = $raffle->prizeTiers->map(fn ($t) => [
            'name' => (string) $t->tier_name,
            'description' => $t->prize_description,
            'value' => (float) $t->cash_value,
            'winners' => (int) $t->winner_count,
        ])->values()->all();

        return $tiers !== [] ? $tiers : [['name' => 'Grand prize', 'description' => $raffle->grand_prize, 'value' => 0.0, 'winners' => 1]];
    }

    public function forRaffle(Raffle $raffle, int $quantity): array
    {
        return $this->forTiers($this->tiersOf($raffle), (int) $raffle->max_tickets, $quantity);
    }

    /**
     * The one-line check shown to staff while they set prizes up: what the
     * prizes cost against what the raffle can take in, and the tax on the rest.
     *
     * @return array{sales: float, prize_total: float, prize_share: float, winners_total: int, pool: int, tax: float, left: float, warnings: list<string>, one_in_any: ?int}
     */
    public function setupSummary(Raffle $raffle): array
    {
        $result = $this->forRaffle($raffle, 1);
        $sales = round((float) $raffle->price * (int) $raffle->max_tickets, 2);
        $prizes = $result['prize_total'];
        $tax = GamingTaxService::work($sales, 0, $prizes, 0, (float) config('gaming_tax.rate', 2.5), 'zero')['tax_due'];
        $warnings = [];

        if (config('raffles.warn_prizes_exceed_sales', true) && $sales > 0 && $prizes > $sales) {
            $warnings[] = 'The prizes are worth more than the raffle can take in, so it would lose money.';
        }

        if (array_sum(array_column($this->tiersOf($raffle), 'winners')) > (int) $raffle->max_tickets) {
            $warnings[] = 'There are more winners than tickets.';
        }

        if ($raffle->prizeTiers->contains(fn ($t) => (float) $t->cash_value <= 0)) {
            $warnings[] = 'A prize level has no cash value. Set one so the gaming tax is not understated.';
        }

        return [
            'sales' => $sales,
            'prize_total' => $prizes,
            'prize_share' => $sales > 0 ? round($prizes / $sales * 100, 1) : 0.0,
            'winners_total' => $result['winners_total'],
            'pool' => $result['pool'],
            'tax' => $tax,
            'left' => round($sales - $prizes - $tax, 2),
            'warnings' => $warnings,
            'one_in_any' => $result['any']['one_in'],
        ];
    }
}
