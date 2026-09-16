<?php

namespace App\Services;

use App\Exceptions\InsufficientPointsException;
use App\Models\Legacy\WpUser;
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
    private const COST = 50;

    /**
     * [payout, weight out of 1000, outcome] — weights must sum to 1000.
     * Kept in the same win-loss-tie-jackpot order as the legacy table so
     * `visual_index` means the same thing to any frontend built against
     * this odds table.
     */
    private const PRIZES = [
        ['payout' => 15, 'weight' => 600, 'outcome' => 'loss'],
        ['payout' => 50, 'weight' => 300, 'outcome' => 'tie'],
        ['payout' => 150, 'weight' => 80, 'outcome' => 'win'],
        ['payout' => 500, 'weight' => 20, 'outcome' => 'jackpot'],
    ];

    public function __construct(private readonly PointsService $points) {}

    /** The real odds, safe (and meant) to be shown to players. */
    public function odds(): array
    {
        return array_map(fn ($p) => [
            'outcome' => $p['outcome'],
            'payout' => $p['payout'],
            'probability' => $p['weight'] / 1000,
        ], self::PRIZES);
    }

    /**
     * @return array{payout: int, outcome: string, visual_index: int, new_balance: int}
     *
     * @throws InsufficientPointsException
     */
    public function spin(WpUser $user): array
    {
        return DB::transaction(function () use ($user) {
            $balanceAfterCost = $this->points->debit($user, self::COST, 'spin_cost', description: 'Spin & Win entry fee');

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
            ];
        });
    }

    /** @return array{0: array{payout:int,weight:int,outcome:string}, 1: int} */
    private function draw(): array
    {
        $roll = random_int(1, 1000);
        $cumulative = 0;

        foreach (self::PRIZES as $index => $prize) {
            $cumulative += $prize['weight'];

            if ($roll <= $cumulative) {
                return [$prize, $index];
            }
        }

        // Unreachable if weights sum to 1000, but never leave a spin unresolved.
        $lastIndex = count(self::PRIZES) - 1;

        return [self::PRIZES[$lastIndex], $lastIndex];
    }
}
