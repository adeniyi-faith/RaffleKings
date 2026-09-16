<?php

namespace App\Services;

use App\Exceptions\DrawAlreadyRunException;
use App\Exceptions\DrawNotCommittedException;
use App\Exceptions\NoEligibleEntriesException;
use App\Exceptions\NoPrizeStructureException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Models\RafflePrizeTier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A provably-fair replacement for the legacy draw
 * (wp-core/api-gamification.php's rk_run_raffle_draw()), which uses
 * PHP's ordinary non-cryptographic shuffle() and shows users a
 * "verification hash" that isn't real proof of anything — it's a hash of
 * already-public fields with a hardcoded salt, forgeable by anyone
 * (audit TD-09/TD-10).
 *
 * The scheme (standard "commit/reveal" provably-fair design):
 *
 *  1. commitSeed() — BEFORE the draw, generate a random server seed and
 *     publish only its SHA-256 hash. This proves the seed was fixed in
 *     advance, without revealing it (revealing it early would let
 *     someone work out the result before entries even close).
 *  2. runDraw() — derive a CLIENT seed from the actual eligible pool
 *     itself (a hash of every participating user/ticket pair, in a
 *     deterministic order) — this means neither the operator (who fixed
 *     the server seed before the pool existed) nor a buyer (who can't
 *     predict the server seed) can steer the result. Combine both seeds
 *     into a deterministic Fisher-Yates shuffle of the pool.
 *  3. Reveal the server seed. Anyone can now re-run the exact same
 *     recomputation (see verify()) and confirm the published winners are
 *     really what that seed+pool produce — real proof, not a hash of
 *     public fields.
 *
 * Winners are written to the SAME wp_raffle_winners table the legacy
 * draw uses (via App\Models\Legacy\RaffleWinner), so the rest of the
 * system (Hall of Fame, admin winner manager) doesn't need to know or
 * care which engine ran the draw.
 */
class ProvablyFairDrawService
{
    private const WINNER_COOLDOWN_DAYS = 3; // mirrors the legacy draw's own cooldown

    public function commitSeed(Raffle $raffle): RaffleDraw
    {
        $existing = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        if ($existing) {
            return $existing; // committing twice just returns the existing commitment — never regenerates one
        }

        $seed = bin2hex(random_bytes(32));

        return RaffleDraw::create([
            'raffle_id' => $raffle->id,
            'server_seed' => $seed,
            'server_seed_hash' => hash('sha256', $seed),
            'committed_at' => now(),
        ]);
    }

    /**
     * @return RaffleWinner[]
     *
     * @throws DrawNotCommittedException
     * @throws DrawAlreadyRunException
     * @throws NoPrizeStructureException
     * @throws NoEligibleEntriesException
     */
    public function runDraw(Raffle $raffle): array
    {
        $legacyRaffleId = $raffle->legacy_post_id ?? $raffle->id;

        $draw = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        if (! $draw) {
            throw new DrawNotCommittedException($raffle->id);
        }

        if ($draw->hasRun()) {
            throw new DrawAlreadyRunException($raffle->id);
        }

        // Mirrors the legacy guard exactly — checked against the real
        // winners table so this holds even if the OTHER draw engine ran
        // first.
        if (RaffleWinner::query()->where('raffle_id', $legacyRaffleId)->exists()) {
            throw new DrawAlreadyRunException($raffle->id);
        }

        $prizeTiers = $raffle->prizeTiers()->get();

        if ($prizeTiers->isEmpty()) {
            throw new NoPrizeStructureException($raffle->id);
        }

        $pool = $this->eligiblePool($legacyRaffleId);

        if ($pool->isEmpty()) {
            throw new NoEligibleEntriesException($raffle->id);
        }

        $clientSeed = $this->deriveClientSeed($pool);
        $shuffled = $this->deterministicShuffle($pool->all(), $draw->server_seed, $clientSeed);

        $winners = $this->assignPrizes($legacyRaffleId, $shuffled, $prizeTiers);

        return DB::transaction(function () use ($winners, $draw, $clientSeed) {
            $created = array_map(fn ($winner) => RaffleWinner::create($winner), $winners);

            $draw->update(['client_seed' => $clientSeed, 'executed_at' => now()]);

            return $created;
        });
    }

    /**
     * Recomputes a draw from its revealed seed and the same pool query,
     * and reports whether that matches what's actually stored in
     * wp_raffle_winners — this is the "anyone can independently
     * recompute the result" proof the audit calls for. Returns null if
     * the draw hasn't run yet (nothing to verify).
     */
    public function verify(Raffle $raffle): ?array
    {
        $draw = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        if (! $draw || ! $draw->hasRun()) {
            return null;
        }

        $legacyRaffleId = $raffle->legacy_post_id ?? $raffle->id;

        $recomputedPool = $this->eligiblePool($legacyRaffleId, before: $draw->executed_at);
        $recomputedClientSeed = $this->deriveClientSeed($recomputedPool);
        $shuffled = $this->deterministicShuffle($recomputedPool->all(), $draw->server_seed, $recomputedClientSeed);
        $recomputedWinners = $this->assignPrizes($legacyRaffleId, $shuffled, $raffle->prizeTiers()->get());

        $actualWinners = RaffleWinner::query()
            ->where('raffle_id', $legacyRaffleId)
            ->orderBy('prize_rank')
            ->get(['user_id', 'ticket_number', 'prize_rank'])
            ->map(fn ($w) => ['user_id' => $w->user_id, 'ticket_number' => (int) $w->ticket_number, 'prize_rank' => $w->prize_rank])
            ->all();

        $recomputedComparable = array_map(
            fn ($w) => ['user_id' => $w['user_id'], 'ticket_number' => $w['ticket_number'], 'prize_rank' => $w['prize_rank']],
            $recomputedWinners
        );

        return [
            'server_seed' => $draw->server_seed,
            'server_seed_hash' => $draw->server_seed_hash,
            'client_seed' => $draw->client_seed,
            'seed_hash_matches' => hash('sha256', $draw->server_seed) === $draw->server_seed_hash,
            'client_seed_matches' => $recomputedClientSeed === $draw->client_seed,
            'winners_match' => $recomputedComparable === $actualWinners,
        ];
    }

    /**
     * The pool of ticket-holders eligible to win, in a stable,
     * reproducible order (by entry id) — same rules as the legacy draw:
     * only tickets funded by a verified_final transaction, excluding
     * anyone who won any raffle in the last 3 days.
     */
    private function eligiblePool(int $legacyRaffleId, ?Carbon $before = null): Collection
    {
        $excludedUserIds = RaffleWinner::query()
            ->where('won_at', '>', ($before ?? now())->copy()->subDays(self::WINNER_COOLDOWN_DAYS))
            ->when($before, fn ($q) => $q->where('won_at', '<', $before))
            ->pluck('user_id');

        return RaffleEntry::query()
            ->where('raffle_id', $legacyRaffleId)
            ->whereIn('txn_id', RaffleTransaction::query()->where('status', 'verified_final')->select('id'))
            ->whereNotIn('user_id', $excludedUserIds)
            ->orderBy('id')
            ->get(['user_id', 'ticket_number']);
    }

    /**
     * A client seed derived purely from the eligible pool itself, so it
     * can't be known or influenced by anyone (operator included) before
     * entries close, and can't be recomputed to anything different later
     * without changing who was actually eligible.
     */
    private function deriveClientSeed(Collection $pool): string
    {
        $material = $pool->map(fn ($entry) => "{$entry->user_id}:{$entry->ticket_number}")->implode(',');

        return hash('sha256', $material);
    }

    /**
     * A deterministic Fisher-Yates shuffle: every swap decision comes
     * from HMAC-SHA256(serverSeed:clientSeed:i), never from PHP's own
     * random state — so the exact same seeds and starting pool always
     * produce the exact same order, which is what makes this verifiable
     * after the fact.
     *
     * @param  array<int, object{user_id:int, ticket_number:int}>  $pool
     */
    private function deterministicShuffle(array $pool, string $serverSeed, string $clientSeed): array
    {
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $digest = hash_hmac('sha256', "{$serverSeed}:{$clientSeed}:{$i}", $serverSeed);
            $j = hexdec(substr($digest, 0, 8)) % ($i + 1);

            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }

        return $pool;
    }

    /**
     * Walks prize tiers in rank order, awarding each tier's winner_count
     * slots to the next unique users in the shuffled pool — mirrors the
     * legacy draw's own tier-expansion and single-winner-per-user-per-
     * draw rule exactly (rk_run_raffle_draw() in api-gamification.php).
     *
     * @param  array<int, object{user_id:int, ticket_number:int}>  $shuffledPool
     * @param  Collection<int, RafflePrizeTier>  $prizeTiers
     * @return array<int, array>
     */
    private function assignPrizes(int $legacyRaffleId, array $shuffledPool, Collection $prizeTiers): array
    {
        $pool = $shuffledPool;
        $sessionWinners = [];
        $winners = [];
        $rank = 1;

        foreach ($prizeTiers as $tier) {
            for ($slot = 0; $slot < $tier->winner_count; $slot++) {
                $index = null;

                foreach ($pool as $i => $entry) {
                    if (! in_array($entry->user_id, $sessionWinners, true)) {
                        $index = $i;
                        break;
                    }
                }

                if ($index === null) {
                    break 2; // no more unique eligible users left
                }

                $entry = $pool[$index];
                array_splice($pool, $index, 1);
                $sessionWinners[] = $entry->user_id;

                $winners[] = [
                    'raffle_id' => $legacyRaffleId,
                    'user_id' => $entry->user_id,
                    'ticket_number' => $entry->ticket_number,
                    'prize_name' => $tier->displayName(),
                    'prize_rank' => $rank,
                    'prize_cash_value' => $tier->cash_value,
                    'is_credited' => false,
                    'is_visible' => false, // requires a separate admin approval, same as the legacy draw
                ];

                $rank++;
            }
        }

        return $winners;
    }
}
