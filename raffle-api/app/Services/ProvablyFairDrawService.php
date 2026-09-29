<?php

namespace App\Services;

use App\Exceptions\DrawAlreadyRunException;
use App\Exceptions\DrawNotCommittedException;
use App\Exceptions\NoEligibleEntriesException;
use App\Exceptions\NoPrizeStructureException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\RaffleBonusEntry;
use App\Models\RaffleDraw;
use App\Models\RafflePrizeTier;
use App\Notifications\DrawCompletedAdminAlert;
use App\Notifications\WinnerAnnounced;
use App\Services\Draw\DrawRules;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
 * Raffle Rules Engine: each raffle's published draw rules (DrawRules —
 * prize caps per person, winner cooldowns, loyalty bonus entries,
 * consolation points) are locked into the draw with a fingerprint when the
 * seed is committed. They shape the pool and how prizes spread; the
 * shuffle itself is unchanged, and verify() re-checks rules too. Draws
 * committed before the Rules Engine use DrawRules::legacy(), which is
 * exactly what this engine always did, so they still verify.
 *
 * Winners are written to the SAME wp_raffle_winners table the legacy
 * draw uses (via App\Models\Legacy\RaffleWinner), so the rest of the
 * system (Hall of Fame, admin winner manager) doesn't need to know or
 * care which engine ran the draw.
 */
class ProvablyFairDrawService
{
    public function __construct(private readonly PointsService $points) {}

    public function commitSeed(Raffle $raffle): RaffleDraw
    {
        $existing = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        if ($existing) {
            return $existing; // committing twice just returns the existing commitment — never regenerates one
        }

        $seed = bin2hex(random_bytes(32));
        $rules = $raffle->drawRules();

        return RaffleDraw::create([
            'raffle_id' => $raffle->id,
            'server_seed' => $seed,
            'server_seed_hash' => hash('sha256', $seed),
            'committed_at' => now(),
            // The rules are locked in with the seed; the Verify page re-checks both.
            'rules' => $rules->toArray(),
            'rules_hash' => $rules->hash(),
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
        $legacyRaffleId = $raffle->public_id; // the number every ticket and winner row uses (item 43)

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

        $rules = $this->rulesFor($draw);
        $drawAt = now();
        $pool = $this->eligiblePool($legacyRaffleId, $rules, $drawAt);

        if ($pool->isEmpty()) {
            throw new NoEligibleEntriesException($raffle->id);
        }

        $clientSeed = $this->deriveClientSeed($pool);
        $shuffled = $this->deterministicShuffle($pool->all(), $draw->server_seed, $clientSeed);

        $winners = $this->assignPrizes($legacyRaffleId, $shuffled, $prizeTiers, $rules, $drawAt);

        $created = DB::transaction(function () use ($winners, $draw, $clientSeed, $drawAt) {
            $created = array_map(fn ($winner) => RaffleWinner::create($winner), $winners);

            $draw->update(['client_seed' => $clientSeed, 'executed_at' => $drawAt]);

            return $created;
        });

        // Notifications fire AFTER the transaction commits — never
        // inside it, so a winner can't be told they won a draw that
        // then gets rolled back.
        $this->notifyWinnersAndAdmins($raffle->id, $created);
        $this->payConsolation($legacyRaffleId, $rules, $created);

        return $created;
    }

    /** The rules locked into this draw (legacy draws: exactly the old behaviour). */
    public function rulesFor(RaffleDraw $draw): DrawRules
    {
        return $draw->rules !== null ? DrawRules::fromArray($draw->rules) : DrawRules::legacy();
    }

    /**
     * "Buy N+ tickets and don't win? Get points" — paid once, right after
     * the draw, to every non-winner with enough tickets in this raffle.
     *
     * @param  RaffleWinner[]  $winners
     */
    private function payConsolation(int $legacyRaffleId, DrawRules $rules, array $winners): void
    {
        if (! $rules->consolationOn()) {
            return;
        }

        $winnerIds = collect($winners)->pluck('user_id')->unique();

        RaffleEntry::query()
            ->where('raffle_id', $legacyRaffleId)
            ->whereIn('txn_id', RaffleTransaction::query()->where('status', 'verified_final')->select('id'))
            ->selectRaw('user_id, count(*) as tickets')
            ->groupBy('user_id')
            ->havingRaw('count(*) >= ?', [$rules->consolationMinTickets])
            ->pluck('user_id')
            ->reject(fn ($userId) => $winnerIds->contains($userId))
            ->each(function ($userId) use ($rules, $legacyRaffleId) {
                $user = WpUser::find($userId);
                $user && $this->points->credit($user, $rules->consolationPoints, 'raffle_consolation', description: "Consolation points for raffle #{$legacyRaffleId}");
            });
    }

    /** @param  RaffleWinner[]  $winners */
    private function notifyWinnersAndAdmins(int $raffleId, array $winners): void
    {
        foreach ($winners as $winner) {
            $user = WpUser::find($winner->user_id);
            $user?->notify(new WinnerAnnounced($winner));
        }

        Notification::send(new AnonymousNotifiable, new DrawCompletedAdminAlert($raffleId, count($winners)));
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

        $legacyRaffleId = $raffle->public_id; // the number every ticket and winner row uses (item 43)

        $rules = $this->rulesFor($draw);
        $recomputedPool = $this->eligiblePool($legacyRaffleId, $rules, $draw->executed_at, verifying: true);
        $recomputedClientSeed = $this->deriveClientSeed($recomputedPool);
        $shuffled = $this->deterministicShuffle($recomputedPool->all(), $draw->server_seed, $recomputedClientSeed);
        $recomputedWinners = $this->assignPrizes($legacyRaffleId, $shuffled, $raffle->prizeTiers()->get(), $rules, $draw->executed_at);

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
            // Raffle Rules Engine: the rules used are the ones locked in before the draw.
            'rules' => $rules->toArray(),
            'rules_described' => $rules->describe(app(LoyaltyService::class)->tiers()),
            'rules_match' => $draw->rules === null || hash_equals((string) $draw->rules_hash, $rules->hash()),
        ];
    }

    /**
     * Everyone who can win, in a stable, reproducible order: tickets funded
     * by a verified_final transaction (by entry id), then loyalty bonus
     * entries (by id, one pool slot per entry) when the rules switch them
     * on — minus anyone inside the rules' recent-winner cooldown. When
     * verifying, only what existed at the moment of the draw counts.
     */
    private function eligiblePool(int $legacyRaffleId, DrawRules $rules, Carbon $drawAt, bool $verifying = false): Collection
    {
        $excludedUserIds = $rules->recentWinnerCooldownDays > 0
            ? RaffleWinner::query()
                ->where('won_at', '>', $drawAt->copy()->subDays($rules->recentWinnerCooldownDays))
                ->where('won_at', '<', $drawAt)
                ->where('raffle_id', '!=', $legacyRaffleId)
                ->pluck('user_id')
            : collect();

        $tickets = RaffleEntry::query()
            ->where('raffle_id', $legacyRaffleId)
            ->whereIn('txn_id', RaffleTransaction::query()->where('status', 'verified_final')->select('id'))
            ->whereNotIn('user_id', $excludedUserIds)
            ->when($verifying, fn ($q) => $q->where('created_at', '<=', $drawAt))
            ->orderBy('id')
            ->get(['user_id', 'ticket_number'])
            ->map(fn ($e) => (object) ['user_id' => (int) $e->user_id, 'ticket_number' => (int) $e->ticket_number, 'key' => null]);

        if (! $rules->loyaltyBonusEntries) {
            return $tickets->values();
        }

        $bonus = RaffleBonusEntry::query()
            ->where('raffle_id', $legacyRaffleId)
            ->whereNotIn('user_id', $excludedUserIds)
            ->when($verifying, fn ($q) => $q->where('created_at', '<=', $drawAt))
            ->orderBy('id')
            ->get()
            ->flatMap(fn (RaffleBonusEntry $b) => collect(range(1, max(1, $b->entries)))
                ->map(fn ($k) => (object) ['user_id' => $b->user_id, 'ticket_number' => 0, 'key' => "B{$b->id}.{$k}"]));

        return $tickets->concat($bonus)->values();
    }

    /**
     * A client seed derived purely from the eligible pool itself, so it
     * can't be known or influenced by anyone (operator included) before
     * entries close, and can't be recomputed to anything different later
     * without changing who was actually eligible.
     */
    private function deriveClientSeed(Collection $pool): string
    {
        // Tickets read "user:ticket" (unchanged, so earlier draws still
        // verify); bonus entries "user:B<id>.<n>".
        $material = $pool->map(fn ($entry) => $entry->key ? "{$entry->user_id}:{$entry->key}" : "{$entry->user_id}:{$entry->ticket_number}")->implode(',');

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
     * Walks prize tiers in rank order, giving each slot to the next entry
     * in the shuffled pool whose owner the rules still allow: no more than
     * max_wins_per_person prizes each (1 = the legacy "one prize per
     * person"), and nobody inside the top-prize cooldown takes a slot of
     * the first (top) tier.
     *
     * @param  array<int, object{user_id:int, ticket_number:int}>  $shuffledPool
     * @param  Collection<int, RafflePrizeTier>  $prizeTiers
     * @return array<int, array>
     */
    private function assignPrizes(int $legacyRaffleId, array $shuffledPool, Collection $prizeTiers, DrawRules $rules, Carbon $drawAt): array
    {
        $pool = array_values($shuffledPool);
        $wins = [];
        $winners = [];
        $rank = 1;

        $topPrizeBlocked = $rules->topPrizeCooldownDays > 0
            ? RaffleWinner::query()
                ->where('prize_rank', 1)
                ->where('raffle_id', '!=', $legacyRaffleId)
                ->where('won_at', '>', $drawAt->copy()->subDays($rules->topPrizeCooldownDays))
                ->where('won_at', '<', $drawAt)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        foreach ($prizeTiers->values() as $tierIndex => $tier) {
            for ($slot = 0; $slot < $tier->winner_count; $slot++) {
                $index = null;

                foreach ($pool as $i => $entry) {
                    $allowed = ($wins[$entry->user_id] ?? 0) < $rules->maxWinsPerPerson
                        && ! ($tierIndex === 0 && in_array($entry->user_id, $topPrizeBlocked, true));

                    if ($allowed) {
                        $index = $i;
                        break;
                    }
                }

                if ($index === null) {
                    break 2; // nobody left who can win
                }

                $entry = $pool[$index];
                array_splice($pool, $index, 1);
                $wins[$entry->user_id] = ($wins[$entry->user_id] ?? 0) + 1;

                $winners[] = [
                    'raffle_id' => $legacyRaffleId,
                    'user_id' => $entry->user_id,
                    'ticket_number' => $entry->ticket_number, // 0 = a loyalty bonus entry
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

    /**
     * Run the draw many times with random seeds against the current pool,
     * WITHOUT saving anything (Raffle Rules Engine simulator): how often
     * each group of players would win, before anything is locked in.
     *
     * @return array{runs: int, pool_size: int, players: int, prizes: int, by_tier: list<array>, top_players: list<array>}
     */
    public function simulate(Raffle $raffle, int $runs = 1000, ?DrawRules $rules = null): array
    {
        $rules ??= $raffle->drawRules();
        $legacyRaffleId = $raffle->public_id;
        $prizeTiers = $raffle->prizeTiers()->get();
        $now = now();
        $pool = $this->eligiblePool($legacyRaffleId, $rules, $now)->all();
        $prizes = (int) $prizeTiers->sum('winner_count');
        // Keep it quick on big raffles: fewer runs when the pool is large.
        $runs = max(100, min($runs, intdiv(2_000_000, max(1, count($pool)))));

        $loyalty = app(LoyaltyService::class);
        $players = collect($pool)->groupBy('user_id')->map(fn ($entries, $userId) => [
            'user_id' => (int) $userId,
            'entries' => $entries->count(),
            'tier' => $loyalty->profile((int) $userId)['tier']['key'],
        ]);

        $wonAny = [];
        $topWins = [];

        for ($run = 0; $run < $runs && $pool && $prizes; $run++) {
            $shuffled = $this->deterministicShuffle($pool, bin2hex(random_bytes(16)), (string) $run);
            $result = $this->assignPrizes($legacyRaffleId, $shuffled, $prizeTiers, $rules, $now);

            foreach (collect($result)->pluck('user_id')->unique() as $userId) {
                $wonAny[$userId] = ($wonAny[$userId] ?? 0) + 1;
            }

            if (isset($result[0])) {
                $topWins[$result[0]['user_id']] = ($topWins[$result[0]['user_id']] ?? 0) + 1;
            }
        }

        $runs = max(1, $run);
        $names = WpUser::query()->whereIn('ID', $players->keys())->get()->keyBy('ID');

        $byTier = collect($loyalty->tiers())->map(function ($tier) use ($players, $wonAny, $runs) {
            $group = $players->where('tier', $tier['key']);

            return [
                'tier' => $tier['name'],
                'players' => $group->count(),
                'avg_entries' => $group->count() ? round($group->avg('entries'), 1) : 0,
                'chance_to_win_any' => $group->count() ? round($group->avg(fn ($p) => ($wonAny[$p['user_id']] ?? 0) / $runs) * 100, 1) : 0,
            ];
        })->filter(fn ($row) => $row['players'] > 0)->values()->all();

        $topPlayers = $players->sortByDesc(fn ($p) => $wonAny[$p['user_id']] ?? 0)->take(10)->map(fn ($p) => [
            'name' => ($u = $names->get($p['user_id'])) ? ($u->display_name ?: $u->user_login) : 'User #'.$p['user_id'],
            'tier' => ucfirst($p['tier']),
            'entries' => $p['entries'],
            'chance_to_win_any' => round((($wonAny[$p['user_id']] ?? 0) / $runs) * 100, 1),
            'chance_top_prize' => round((($topWins[$p['user_id']] ?? 0) / $runs) * 100, 1),
        ])->values()->all();

        return [
            'runs' => $runs,
            'pool_size' => count($pool),
            'players' => $players->count(),
            'prizes' => $prizes,
            'by_tier' => $byTier,
            'top_players' => $topPlayers,
        ];
    }
}
