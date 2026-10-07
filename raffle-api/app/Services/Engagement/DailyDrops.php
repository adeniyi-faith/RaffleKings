<?php

namespace App\Services\Engagement;

use App\Models\DailyDrop;
use App\Models\DailyDropRun;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Notifications\DailyDropWon;
use App\Services\AdminAuditLogService;
use App\Services\WalletLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Daily Drops: once a day, at a set time, a share of a raffle's NEW ticket
 * sales (everything sold since the last drop) is paid out to randomly picked
 * ticket holders of that raffle, straight into their winnings balance. It
 * gives players a reason to come back every day, not just on draw day.
 *
 * Fair and checkable, the same "commit then reveal" idea as the main draw:
 *  1. Before a drop, a secret random seed is fixed and only its fingerprint
 *     (SHA-256 hash) is shown on the raffle page.
 *  2. At drop time the ticket pool is fingerprinted too, and the winners are
 *     worked out from seed + pool. Every ticket is one equal chance; one
 *     person can win at most one share per day.
 *  3. The seed is then revealed, so anyone can redo the pick (verify()).
 *
 * Money safety: a day can only be paid once (unique key on drop + day), the
 * pot is a share of money actually taken, and an optional daily cap limits it.
 */
class DailyDrops
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly AdminAuditLogService $audit,
    ) {}

    /** Switch a drop on. Its first pot counts sales from now. */
    public function activate(DailyDrop $drop, WpUser $admin): DailyDrop
    {
        $raffle = $drop->raffle;

        if (! $raffle) {
            throw new RuntimeException('Choose which raffle this drop belongs to first.');
        }

        if ($raffle->cancelled_at || $raffle->closedReason() === 'ended' || $raffle->closedReason() === 'sold_out') {
            throw new RuntimeException('That raffle is no longer selling tickets, so a drop would have nothing to share.');
        }

        if ($drop->pot_percent <= 0 || $drop->pot_percent > 50) {
            throw new RuntimeException('The share of sales must be between 0.1% and 50%.');
        }

        $seed = $drop->next_seed ?: bin2hex(random_bytes(32));
        $drop->update([
            'status' => 'active',
            'next_seed' => $seed,
            'next_seed_hash' => hash('sha256', $seed),
            'counting_from' => $drop->counting_from ?? now(),
        ]);

        $this->audit->record($admin, 'daily_drop.activated', DailyDrop::class, $drop->id, ['raffle_id' => $raffle->id, 'pot_percent' => $drop->pot_percent]);

        return $drop;
    }

    public function pause(DailyDrop $drop, WpUser $admin): DailyDrop
    {
        $drop->update(['status' => 'paused']);
        $this->audit->record($admin, 'daily_drop.paused', DailyDrop::class, $drop->id);

        return $drop;
    }

    /** The scheduler calls this every minute: pay every drop whose time has come today. */
    public function runDue(): int
    {
        $tz = config('raffles.timezone', 'Africa/Lagos');
        $now = now($tz);
        $done = 0;

        DailyDrop::query()->where('status', 'active')->with('raffle')->get()
            ->filter(fn (DailyDrop $d) => $now->format('H:i') >= $d->drop_time)
            ->each(function (DailyDrop $drop) use ($now, &$done) {
                if ($drop->runs()->whereDate('run_date', $now->toDateString())->exists()) {
                    return;
                }

                try {
                    if ($this->run($drop, $now->toDateString())) {
                        $done++;
                    }
                } catch (\Throwable $e) {
                    Log::error('Daily drop failed', ['drop' => $drop->id, 'error' => $e->getMessage()]);
                }
            });

        return $done;
    }

    /** Pay one drop for one day. Safe to call twice: the drop row is locked and a day can only be paid once (unique key). */
    public function run(DailyDrop $drop, string $day): ?DailyDropRun
    {
        $paid = [];

        $run = DB::transaction(function () use ($drop, $day, &$paid) {
            $drop = DailyDrop::query()->with('raffle')->lockForUpdate()->findOrFail($drop->id);

            if ($drop->status !== 'active' || DailyDropRun::query()->where('daily_drop_id', $drop->id)->whereDate('run_date', $day)->exists()) {
                return null;
            }

            $raffle = $drop->raffle;

            // A raffle still being prepared (or deleted) has no sales yet: wait.
            if (! $raffle || $raffle->status === 'draft') {
                return null;
            }

            // A cancelled raffle refunds every ticket, so there is nothing to share.
            if ($raffle->cancelled_at) {
                $drop->update(['status' => 'ended']);

                return null;
            }

            $until = now();
            $sales = $this->salesBetween($raffle->public_id, $drop->counting_from ?? $drop->created_at, $until);
            $pot = floor($sales * $drop->pot_percent / 100);
            if ($drop->daily_cap) {
                $pot = min($pot, floor($drop->daily_cap));
            }

            $pool = RaffleEntry::query()
                ->where('raffle_id', $raffle->public_id)
                ->where('ticket_number', '>', 0)
                ->where('created_at', '<=', $until)
                ->orderBy('ticket_number')
                ->get(['user_id', 'ticket_number'])
                ->map(fn ($e) => ['user_id' => (int) $e->user_id, 'ticket_number' => (int) $e->ticket_number])
                ->all();

            $seed = $drop->next_seed ?: bin2hex(random_bytes(32));
            $poolHash = self::poolHash($pool);
            $winners = [];
            $result = match (true) {
                $pool === [] => 'no_tickets',
                $pot < 1 => 'no_sales',
                default => 'paid',
            };

            if ($result === 'paid') {
                // Never more winners than whole naira in the pot, so every share is at least ₦1.
                $picked = self::pick($seed, $poolHash, $pool, (int) min($drop->winners_per_day, $pot));
                $share = floor($pot / count($picked));
                $winners = array_map(fn ($t) => $t + ['amount' => $share], $picked);
            }

            $run = DailyDropRun::create([
                'daily_drop_id' => $drop->id,
                'run_date' => $day,
                'result' => $result,
                'sales_counted' => $sales,
                'pot' => $result === 'paid' ? array_sum(array_column($winners, 'amount')) : 0,
                'tickets_in_pool' => count($pool),
                'pool_hash' => $poolHash,
                'server_seed' => $seed,
                'seed_hash' => hash('sha256', $seed),
                'winners' => $winners,
                'created_at' => now(),
            ]);

            // Lock every winner's wallet in one fixed order first, so two
            // payouts running at once can never wait on each other forever.
            $this->ledger->lockWallets(array_values(array_unique(array_map(fn ($w) => (int) $w['user_id'], $winners))));

            foreach ($winners as $w) {
                if ($w['amount'] <= 0) {
                    continue;
                }

                $this->ledger->credit(
                    userId: (int) $w['user_id'],
                    balanceType: 'earnings',
                    amount: (float) $w['amount'],
                    reason: 'daily_drop',
                    key: "daily_drop:run:{$run->id}:ticket:{$w['ticket_number']}",
                    from: 'prizes',
                    referenceType: 'daily_drop_run',
                    referenceId: $run->id,
                    description: "Daily Drop on {$raffle->title}, ticket #{$w['ticket_number']}",
                );
                $paid[] = $w;
            }

            // Lock in the next drop's secret now, and start counting the next pot.
            $next = bin2hex(random_bytes(32));
            $drop->update([
                'next_seed' => $next,
                'next_seed_hash' => hash('sha256', $next),
                'counting_from' => $until,
                // A raffle that has stopped selling gets this last drop, then the drop ends.
                'status' => $raffle->closedReason() === null ? 'active' : 'ended',
            ]);

            return $run;
        });

        foreach ($paid as $w) {
            if ($user = WpUser::query()->find($w['user_id'])) {
                rescue(fn () => $user->notify(new DailyDropWon($drop->raffle, (int) $w['ticket_number'], (float) $w['amount'])), report: true);
            }
        }

        return $run;
    }

    /**
     * Redo a past drop's pick from its revealed seed and pool fingerprint.
     *
     * @param  list<array{user_id: int, ticket_number: int}>  $pool
     */
    public function verify(DailyDropRun $run, array $pool): bool
    {
        if ($run->result !== 'paid' || hash('sha256', (string) $run->server_seed) !== $run->seed_hash || self::poolHash($pool) !== $run->pool_hash) {
            return false;
        }

        $again = self::pick($run->server_seed, $run->pool_hash, $pool, count($run->winners));

        return array_column($again, 'ticket_number') === array_column($run->winners, 'ticket_number');
    }

    /** What the raffle page shows: the rules, the next drop, and recent drops (ticket numbers only). */
    public function forRafflePage(int $publicId): ?array
    {
        $drop = DailyDrop::query()
            ->whereIn('status', ['active', 'ended'])
            ->whereHas('raffle', fn ($q) => $q->where('public_id', $publicId))
            ->latest('id')
            ->first();

        if (! $drop) {
            return null;
        }

        $sofar = $drop->status === 'active' ? floor($this->salesBetween($publicId, $drop->counting_from ?? $drop->created_at, now()) * $drop->pot_percent / 100) : 0;

        return [
            'active' => $drop->status === 'active',
            'pot_percent' => $drop->pot_percent,
            'winners_per_day' => $drop->winners_per_day,
            'daily_cap' => $drop->daily_cap,
            'drop_time' => $drop->drop_time,
            'timezone' => config('raffles.timezone', 'Africa/Lagos'),
            'pot_so_far' => $drop->daily_cap ? min($sofar, $drop->daily_cap) : $sofar,
            'next_seed_hash' => $drop->status === 'active' ? $drop->next_seed_hash : null,
            'total_paid' => (float) $drop->runs()->sum('pot'),
            'recent' => $drop->runs()->where('result', 'paid')->limit(7)->get()->map(fn (DailyDropRun $r) => [
                'date' => $r->run_date->toDateString(),
                'pot' => $r->pot,
                'tickets' => array_map(fn ($w) => ['ticket_number' => $w['ticket_number'], 'amount' => $w['amount']], (array) $r->winners),
                'seed' => $r->server_seed,
                'seed_hash' => $r->seed_hash,
                'pool_hash' => $r->pool_hash,
            ])->all(),
        ];
    }

    /** Money actually paid for this raffle's tickets in a time window. */
    public function salesBetween(int $publicId, ?Carbon $from, Carbon $to): float
    {
        $txns = RaffleEntry::query()
            ->where('raffle_id', $publicId)
            ->where('txn_id', '>', 0)
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $to)
            ->distinct()
            ->pluck('txn_id');

        return $txns->isEmpty() ? 0.0 : round((float) RaffleTransaction::query()
            ->whereIn('id', $txns)
            ->where('status', 'verified_final')
            ->sum('claimed_amount'), 2);
    }

    /** @param  list<array{user_id: int, ticket_number: int}>  $pool */
    public static function poolHash(array $pool): string
    {
        return hash('sha256', implode(',', array_map(fn ($t) => $t['user_id'].':'.$t['ticket_number'], $pool)));
    }

    /**
     * Pick up to $count tickets, one per person, from seed + pool alone.
     *
     * @param  list<array{user_id: int, ticket_number: int}>  $pool
     * @return list<array{user_id: int, ticket_number: int}>
     */
    public static function pick(string $seed, string $poolHash, array $pool, int $count): array
    {
        $people = count(array_unique(array_column($pool, 'user_id')));
        $count = max(1, min($count, $people));
        $picked = [];
        $won = [];

        for ($i = 0; count($picked) < $count && $i < $count * 100; $i++) {
            $n = (int) (hexdec(substr(hash_hmac('sha256', $poolHash.':'.$i, $seed), 0, 12)) % count($pool));
            $ticket = $pool[$n];

            if (! isset($won[$ticket['user_id']])) {
                $won[$ticket['user_id']] = true;
                $picked[] = $ticket;
            }
        }

        return $picked;
    }
}
