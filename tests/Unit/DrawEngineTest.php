<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers the pure, database-free parts of draw-bridge.php — the port of
 * App\Services\ProvablyFairDrawService's deterministic Fisher-Yates
 * shuffle and prize-assignment logic (see draw-bridge.php's own
 * docblock: the two must stay in sync). These are exactly the two
 * functions responsible for "who wins" — everything else in that file
 * is database plumbing already covered by the same kind of manual
 * verification item 33's wallet-bridge.php got, since neither this repo
 * nor CI has a live WordPress/MySQL instance to test against.
 */
final class DrawEngineTest extends TestCase
{
    private function pool(int $count): array
    {
        $pool = [];
        for ($i = 1; $i <= $count; $i++) {
            $pool[] = ['uid' => $i, 'ticket' => 1000 + $i];
        }

        return $pool;
    }

    public function test_shuffle_is_deterministic_for_the_same_seeds(): void
    {
        $pool = $this->pool(10);

        $a = rk_draw_bridge_deterministic_shuffle($pool, 'server-seed-1', 'client-seed-1');
        $b = rk_draw_bridge_deterministic_shuffle($pool, 'server-seed-1', 'client-seed-1');

        $this->assertSame($a, $b);
    }

    public function test_shuffle_output_is_a_permutation_of_the_input(): void
    {
        $pool = $this->pool(25);

        $shuffled = rk_draw_bridge_deterministic_shuffle($pool, 'some-server-seed', 'some-client-seed');

        $this->assertCount(25, $shuffled);
        $this->assertEqualsCanonicalizing(
            array_map(fn ($e) => $e['uid'], $pool),
            array_map(fn ($e) => $e['uid'], $shuffled),
        );
    }

    public function test_a_different_server_seed_produces_a_different_order(): void
    {
        $pool = $this->pool(20);

        $a = rk_draw_bridge_deterministic_shuffle($pool, 'seed-a', 'client-seed');
        $b = rk_draw_bridge_deterministic_shuffle($pool, 'seed-b', 'client-seed');

        $this->assertNotSame($a, $b);
    }

    /**
     * Golden-value regression: locks in the exact output for a fixed
     * seed pair, so an accidental change to the HMAC/shuffle mechanics
     * (which would silently desync this from
     * ProvablyFairDrawService::deterministicShuffle() on the Laravel
     * side) fails a test instead of shipping quietly.
     */
    public function test_known_seed_pair_produces_a_stable_known_order(): void
    {
        $pool = $this->pool(5);

        $shuffled = rk_draw_bridge_deterministic_shuffle($pool, 'fixed-server-seed', 'fixed-client-seed');

        $this->assertSame([3, 1, 4, 2, 5], array_map(fn ($e) => $e['uid'], $shuffled));
    }

    public function test_assign_prizes_gives_each_tier_its_own_winner_count_in_rank_order(): void
    {
        $pool = $this->pool(5); // uids 1..5, already in pool order (no shuffle needed for this test)

        $tiers = [
            ['tier_name' => 'Grand Prize', 'prize_description' => null, 'cash_value' => 50000, 'winner_count' => 1],
            ['tier_name' => 'Consolation', 'prize_description' => 'Airtime', 'cash_value' => 1000, 'winner_count' => 2],
        ];

        $winners = rk_draw_bridge_assign_prizes(42, $pool, $tiers);

        $this->assertCount(3, $winners);
        $this->assertSame(['raffle_id' => 42, 'user_id' => 1, 'ticket_number' => 1001, 'prize_name' => 'Grand Prize', 'prize_rank' => 1, 'prize_cash_value' => 50000], $winners[0]);
        $this->assertSame('Consolation: Airtime', $winners[1]['prize_name']);
        $this->assertSame(2, $winners[1]['prize_rank']);
        $this->assertSame(3, $winners[2]['prize_rank']);

        // No user appears twice across tiers.
        $uids = array_map(fn ($w) => $w['user_id'], $winners);
        $this->assertSame($uids, array_unique($uids));
    }

    public function test_assign_prizes_stops_gracefully_when_the_pool_runs_out_of_unique_users(): void
    {
        $pool = $this->pool(2);

        $tiers = [
            ['tier_name' => 'Grand Prize', 'prize_description' => null, 'cash_value' => 50000, 'winner_count' => 5],
        ];

        $winners = rk_draw_bridge_assign_prizes(1, $pool, $tiers);

        // Only 2 unique users existed to award, even though the tier asked for 5.
        $this->assertCount(2, $winners);
    }
}
