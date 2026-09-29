<?php

namespace App\Services;

use App\Exceptions\InsufficientPointsException;
use App\Models\Legacy\WpUser;
use App\Services\Engagement\Perks;
use Illuminate\Support\Facades\DB;

/**
 * The "Spin & Win" points minigame — same cost and prize table as the
 * legacy rk_execute_spin_logic() (wp-core/api-gamification.php), with
 * two real fixes called for in the audit:
 *
 *  - Cryptographically strong randomness (random_int(), backed by the
 *    OS CSPRNG) instead of PHP's ordinary, predictable rand().
 *  - The odds are a public method (odds()) meant to be shown to players,
 *    not a number buried in server code — turning the built-in ~8%
 *    house edge (expected return 46 points against a 50-point cost)
 *    into something disclosed rather than hidden.
 *
 * Concurrency safety: the legacy version uses a MySQL-specific
 * GET_LOCK()/RELEASE_LOCK() pair. Here, PointsService's row-level
 * lockForUpdate() on the user's own points row gives the same
 * guarantee (no double-spend from two concurrent spins) portably,
 * without a database-specific primitive — and it's already how every
 * other point/money mutation in this app protects itself.
 */
class SpinService
{
    /**
     * [payout, weight, outcome] rows, in the same win-loss-tie-jackpot
     * order as the legacy table so `visual_index` means the same thing to
     * any frontend built against this odds table. Editable in Settings →
     * Rewards (config/rewards.php); a prize's chance is its weight out of
     * the total of all weights.
     *
     * @return list<array{payout: int, weight: int, outcome: string}>
     */
    private static function prizes(): array
    {
        return array_values(array_map(fn ($p) => [
            'payout' => (int) $p['payout'],
            'weight' => (int) $p['weight'],
            'outcome' => (string) $p['outcome'],
        ], config('rewards.spin_prizes')));
    }

    public static function cost(): int
    {
        return (int) config('rewards.spin_cost');
    }

    public function __construct(private readonly PointsService $points) {}

    /** The real odds, safe (and meant) to be shown to players. */
    public function odds(): array
    {
        $total = max(1, array_sum(array_column(self::prizes(), 'weight')));

        return array_map(fn ($p) => [
            'outcome' => $p['outcome'],
            'payout' => $p['payout'],
            'probability' => $p['weight'] / $total,
        ], self::prizes());
    }

    /**
     * @return array{payout: int, outcome: string, visual_index: int, new_balance: int, free: bool, free_spins_left: int}
     *
     * @throws InsufficientPointsException
     */
    public function spin(WpUser $user, bool $free = false): array
    {
        return DB::transaction(function () use ($user, $free) {
            // Phase 11: a free spin (birthday, milestones, Season Pass, referral
            // ladder) is used instead of points when the customer has one.
            $free = $free && app(Perks::class)->useFreeSpin($user->ID);

            $balanceAfterCost = $free
                ? $this->points->balance($user)
                : $this->points->debit($user, self::cost(), 'spin_cost', description: 'Spin & Win entry fee');

            [$prize, $index] = $this->draw();

            $newBalance = $balanceAfterCost;

            if ($prize['payout'] > 0) {
                $newBalance = $this->points->credit($user, $prize['payout'], 'spin_win', description: 'Spin & Win prize: '.$prize['outcome']);
            }

            return [
                'payout' => $prize['payout'],
                'outcome' => $prize['outcome'],
                'visual_index' => $index,
                'new_balance' => $newBalance,
                'free' => $free,
                'free_spins_left' => app(Perks::class)->freeSpins($user->ID),
            ];
        });
    }

    /** @return array{0: array{payout:int,weight:int,outcome:string}, 1: int} */
    private function draw(): array
    {
        $prizes = self::prizes();
        $roll = random_int(1, max(1, array_sum(array_column($prizes, 'weight'))));
        $cumulative = 0;

        foreach ($prizes as $index => $prize) {
            $cumulative += $prize['weight'];

            if ($roll <= $cumulative) {
                return [$prize, $index];
            }
        }

        // Unreachable, but never leave a spin unresolved.
        $lastIndex = count($prizes) - 1;

        return [$prizes[$lastIndex], $lastIndex];
    }
}
