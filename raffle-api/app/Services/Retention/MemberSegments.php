<?php

namespace App\Services\Retention;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Retention\MemberProfile;
use App\Models\Retention\MessageDelivery;
use App\Models\Wallet;
use App\Services\Reminders\ReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sorts every customer into one segment (where they are in their life
 * with us: new, first-timer, consistent, drawing back, lapsed…) and adds
 * any number of flags (high value, slowing down, visits but doesn't buy…).
 * Worked out every night from tickets, payments, wins, wallet balances,
 * site visits and how they respond to messages, and saved in
 * member_profiles so the admin, the message form and the comeback offers
 * can use it instantly.
 *
 * "Played" means bought at least one ticket; a "play day" is a calendar
 * day (business time zone) with at least one purchase. Spend counts only
 * verified payments, never free bonus entries.
 */
class MemberSegments
{
    /** key => [name, what it means]. In the order the admin shows them. */
    public const SEGMENTS = [
        'new_signup' => ['New, not played yet', 'Joined in the last 14 days and hasn\'t bought a ticket yet.'],
        'never_played' => ['Signed up, never played', 'Joined more than 14 days ago and never bought a ticket.'],
        'first_timer' => ['First-timers', 'Bought on one day only, in the last 30 days. The second purchase is the one that matters.'],
        'repeat' => ['Repeat players', 'Bought on more than one day, most recently in the last 14 days.'],
        'consistent' => ['Consistent players', 'Bought in at least 5 of the last 8 weeks, and in the last 14 days.'],
        'drifting' => ['Drawing back', 'Played on more than one day before, but no tickets for 15 to 30 days.'],
        'at_risk' => ['At risk', 'No tickets for 31 to 60 days.'],
        'lapsed' => ['Lapsed', 'No tickets for 61 to 180 days.'],
        'dormant' => ['Dormant', 'No tickets for more than 180 days.'],
        'one_and_done' => ['Played once, never came back', 'Bought on one day only, more than 30 days ago.'],
    ];

    /** key => [name, what it means]. A customer can have any number of these. */
    public const FLAGS = [
        'high_value' => ['High value', 'Spent at least the "high value" amount on tickets in total (Settings → Reminders → Member segments).'],
        'slowing_down' => ['Slowing down', 'Still playing, but spent less than half as much in the last 30 days as in the 30 days before.'],
        'repeat_visitor' => ['Repeat visitor', 'Opened the site on 4 or more days in the last 30.'],
        'visits_no_buy' => ['Visits but doesn\'t buy', 'Opened the site on 2 or more days in the last 30, but bought nothing in that time.'],
        'winner_gone_quiet' => ['Won, then went quiet', 'Won a prize in the last 90 days and hasn\'t bought a ticket since, for at least 7 days.'],
        'money_waiting' => ['Money waiting', 'At least ₦100 sitting in their wallet or winnings.'],
        'push_on' => ['Phone notifications on', 'Can be reached by push notification.'],
        'stopped_reminders' => ['Stopped reminders', 'Tapped "stop reminders". Never sent promotions or offers.'],
    ];

    private const CHUNK = 500;

    public static function label(?string $segment): string
    {
        return self::SEGMENTS[$segment][0] ?? ($segment ? ucfirst(str_replace('_', ' ', $segment)) : 'Not sorted yet');
    }

    public static function flagLabel(string $flag): string
    {
        return self::FLAGS[$flag][0] ?? ucfirst(str_replace('_', ' ', $flag));
    }

    /** @return list<string> */
    public static function flagsFor(int $userId): array
    {
        return DB::table('member_profile_flags')->where('user_id', $userId)->orderBy('flag')->pluck('flag')->all();
    }

    /**
     * Notes that a signed-in customer opened the site today. At most one
     * database write per customer per day.
     */
    public static function recordVisit(int $userId): void
    {
        $day = now()->setTimezone(config('raffles.timezone'))->toDateString();

        try {
            if (Cache::add("member-visit:{$userId}:{$day}", 1, now()->addDay())) {
                DB::table('member_visit_days')->insertOrIgnore(['user_id' => $userId, 'day' => $day]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Work out every customer's profile again. Returns how many were done.
     * Safe to run at any time; each run simply replaces the last.
     */
    public function refresh(): int
    {
        $done = 0;
        $now = now();

        WpUser::query()->select(['ID', 'user_registered', 'user_email'])
            ->chunkById(self::CHUNK, function (Collection $users) use (&$done, $now) {
                $this->refreshChunk($users, $now);
                $done += $users->count();
            }, 'ID');

        Cache::forever('member-segments:refreshed_at', $now->toIso8601String());

        return $done;
    }

    /** Just these customers (e.g. tests, or one customer's page). */
    public function refreshUsers(array $userIds): void
    {
        $users = WpUser::query()->whereIn('ID', $userIds)->get(['ID', 'user_registered', 'user_email']);

        if ($users->isNotEmpty()) {
            $this->refreshChunk($users, now());
        }
    }

    public static function lastRefreshedAt(): ?Carbon
    {
        $at = Cache::get('member-segments:refreshed_at');

        return $at ? Carbon::parse($at) : null;
    }

    /** @param  Collection<int, WpUser>  $users */
    private function refreshChunk(Collection $users, Carbon $now): void
    {
        $ids = $users->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $orders = $this->orders($ids);
        $wins = RaffleWinner::query()->whereIn('user_id', $ids)->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as wins, MAX(won_at) as last_win_at')->get()->keyBy('user_id');
        $wallets = Wallet::query()->whereIn('user_id', $ids)->get(['user_id', 'wallet_balance', 'earnings_balance'])->keyBy('user_id');
        $monthAgo = $now->copy()->setTimezone(config('raffles.timezone'))->subDays(30)->toDateString();
        $visits = DB::table('member_visit_days')->whereIn('user_id', $ids)->groupBy('user_id')
            ->selectRaw('user_id, MAX(day) as last_day, SUM(CASE WHEN day >= ? THEN 1 ELSE 0 END) as recent', [$monthAgo])
            ->get()->keyBy('user_id');
        $push = WpUserMeta::query()->whereIn('user_id', $ids)->where('meta_key', 'rk_onesignal_id')->where('meta_value', '!=', '')->pluck('user_id')->map(fn ($id) => (int) $id)->flip();
        $stopped = WpUserMeta::query()->whereIn('user_id', $ids)->where('meta_key', ReminderService::OPT_OUT_META)->where('meta_value', '1')->pluck('user_id')->map(fn ($id) => (int) $id)->flip();
        $response = $this->channelResponse($ids, $now);
        $existing = MemberProfile::query()->whereIn('user_id', $ids)->get(['user_id', 'segment', 'segment_since'])->keyBy('user_id');

        $rows = [];
        $flagRows = [];
        $changes = [];

        foreach ($users as $user) {
            $id = (int) $user->ID;
            $m = $this->measure($orders[$id] ?? [], $now);
            $win = $wins[$id] ?? null;
            $wallet = $wallets[$id] ?? null;
            $visit = $visits[$id] ?? null;

            $m['joined_at'] = $user->user_registered ? Carbon::parse($user->user_registered) : null;
            $m['wins'] = (int) ($win->wins ?? 0);
            $m['last_win_at'] = $win?->last_win_at ? Carbon::parse($win->last_win_at) : null;
            $m['wallet_balance'] = round((float) ($wallet->wallet_balance ?? 0), 2);
            $m['earnings_balance'] = round((float) ($wallet->earnings_balance ?? 0), 2);
            $m['visit_days_30'] = (int) ($visit->recent ?? 0);
            $m['last_visit_at'] = $visit?->last_day ? Carbon::parse($visit->last_day, config('raffles.timezone'))->setTimezone(config('app.timezone')) : null;

            $segment = self::classify($m, $now);
            $before = $existing[$id] ?? null;
            $since = $before && $before->segment === $segment ? $before->segment_since : $now;

            if ($before && $before->segment !== $segment) {
                $changes[] = ['user_id' => $id, 'from_segment' => $before->segment, 'to_segment' => $segment, 'changed_at' => $now];
            }

            $reachable = array_values(array_filter([
                isset($push[$id]) ? 'push' : null,
                filled($user->user_email) ? 'email' : null,
            ]));

            foreach (self::flags($m, $now, isset($push[$id]), isset($stopped[$id])) as $flag) {
                $flagRows[] = ['user_id' => $id, 'flag' => $flag];
            }

            $rows[] = [
                'user_id' => $id,
                'segment' => $segment,
                'segment_since' => $since,
                'joined_at' => $m['joined_at'],
                'first_play_at' => $m['first_play_at'],
                'last_play_at' => $m['last_play_at'],
                'last_visit_at' => $m['last_visit_at'],
                'play_days' => $m['play_days'],
                'play_days_30' => $m['play_days_30'],
                'active_weeks_8' => $m['active_weeks_8'],
                'visit_days_30' => $m['visit_days_30'],
                'spend_total' => $m['spend_total'],
                'spend_30' => $m['spend_30'],
                'spend_prev_30' => $m['spend_prev_30'],
                'avg_order' => $m['avg_order'],
                'wins' => $m['wins'],
                'last_win_at' => $m['last_win_at'],
                'wallet_balance' => $m['wallet_balance'],
                'earnings_balance' => $m['earnings_balance'],
                'best_channel' => self::bestChannel($reachable, $response[$id] ?? []),
                'refreshed_at' => $now,
            ];
        }

        DB::transaction(function () use ($ids, $rows, $flagRows, $changes) {
            MemberProfile::query()->upsert($rows, ['user_id'], array_keys($rows[0]));
            DB::table('member_profile_flags')->whereIn('user_id', $ids)->delete();

            foreach (array_chunk($flagRows, 500) as $chunk) {
                DB::table('member_profile_flags')->insert($chunk);
            }

            foreach (array_chunk($changes, 500) as $chunk) {
                DB::table('member_segment_changes')->insert($chunk);
            }
        });
    }

    /**
     * Each customer's purchases: [at, txn id, amount paid or 0].
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{at: Carbon, txn: int, amount: float}>>
     */
    private function orders(array $ids): array
    {
        $groups = RaffleEntry::query()->whereIn('user_id', $ids)
            ->select(['user_id', 'txn_id', 'created_at'])
            ->groupBy('user_id', 'txn_id', 'created_at')
            ->get();

        $txnIds = $groups->pluck('txn_id')->filter(fn ($t) => (int) $t > 0)->unique()->values();
        $amounts = [];

        foreach ($txnIds->chunk(1000) as $chunk) {
            RaffleTransaction::query()->whereIn('id', $chunk->all())->where('status', 'verified_final')
                ->get(['id', 'claimed_amount'])
                ->each(function ($t) use (&$amounts) {
                    $amounts[(int) $t->id] = (float) $t->claimed_amount;
                });
        }

        $orders = [];
        $counted = [];

        foreach ($groups as $g) {
            if (! $g->created_at) {
                continue;
            }

            $txn = (int) $g->txn_id;
            // One payment can cover tickets saved a second apart: count its money once.
            $amount = $txn > 0 && ! isset($counted[$txn]) ? ($amounts[$txn] ?? 0.0) : 0.0;
            $counted[$txn] = true;
            $orders[(int) $g->user_id][] = ['at' => Carbon::parse($g->created_at), 'txn' => $txn, 'amount' => $amount];
        }

        return $orders;
    }

    /**
     * The numbers a segment is decided from.
     *
     * @param  list<array{at: Carbon, txn: int, amount: float}>  $orders
     */
    public function measure(array $orders, Carbon $now): array
    {
        $tz = config('raffles.timezone');
        $days = [];
        $recentDays = [];
        $weeks = [];
        $paid = [];
        $spend30 = 0.0;
        $spendPrev = 0.0;

        foreach ($orders as $o) {
            $day = $o['at']->copy()->setTimezone($tz)->toDateString();
            $ago = $o['at']->diffInDays($now, true);
            $days[$day] = true;

            if ($ago < 30) {
                $recentDays[$day] = true;
                $spend30 += $o['amount'];
            } elseif ($ago < 60) {
                $spendPrev += $o['amount'];
            }

            if ($ago < 56) {
                $weeks[(int) floor($ago / 7)] = true;
            }

            if ($o['txn'] > 0 && $o['amount'] > 0) {
                $paid[$o['txn']] = $o['amount'];
            }
        }

        $times = array_map(fn ($o) => $o['at'], $orders);
        $total = array_sum($paid);

        return [
            'first_play_at' => $times ? min($times) : null,
            'last_play_at' => $times ? max($times) : null,
            'play_days' => count($days),
            'play_days_30' => count($recentDays),
            'active_weeks_8' => count($weeks),
            'spend_total' => round($total, 2),
            'spend_30' => round($spend30, 2),
            'spend_prev_30' => round($spendPrev, 2),
            'avg_order' => $paid ? round($total / count($paid), 2) : 0.0,
        ];
    }

    /** Which one segment a customer is in. */
    public static function classify(array $m, Carbon $now): string
    {
        if ($m['play_days'] === 0 || ! $m['last_play_at']) {
            $joined = $m['joined_at'] ?? null;

            return $joined && $joined->diffInDays($now, true) <= 14 ? 'new_signup' : 'never_played';
        }

        $since = $m['last_play_at']->diffInDays($now, true);

        if ($m['play_days'] === 1) {
            return $since <= 30 ? 'first_timer' : 'one_and_done';
        }

        return match (true) {
            $since <= 14 => $m['active_weeks_8'] >= 5 ? 'consistent' : 'repeat',
            $since <= 30 => 'drifting',
            $since <= 60 => 'at_risk',
            $since <= 180 => 'lapsed',
            default => 'dormant',
        };
    }

    /** @return list<string> */
    public static function flags(array $m, Carbon $now, bool $push, bool $stopped): array
    {
        $since = $m['last_play_at']?->diffInDays($now, true);
        $wonAgo = $m['last_win_at']?->diffInDays($now, true);

        return array_keys(array_filter([
            'high_value' => $m['spend_total'] > 0 && $m['spend_total'] >= (float) config('retention.high_value_spend', 50000),
            'slowing_down' => $m['spend_prev_30'] > 0 && $m['play_days_30'] > 0 && $m['spend_30'] < $m['spend_prev_30'] / 2,
            'repeat_visitor' => $m['visit_days_30'] >= 4,
            'visits_no_buy' => $m['visit_days_30'] >= 2 && $m['play_days_30'] === 0,
            'winner_gone_quiet' => $wonAgo !== null && $wonAgo <= 90 && $since !== null && $since >= 7 && $m['last_play_at']->lte($m['last_win_at']),
            'money_waiting' => $m['wallet_balance'] + $m['earnings_balance'] >= 100,
            'push_on' => $push,
            'stopped_reminders' => $stopped,
        ]));
    }

    /**
     * How each customer has responded, by channel, in the last 90 days.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, array{sent: int, tapped: int}>>
     */
    private function channelResponse(array $ids, Carbon $now): array
    {
        $out = [];

        MessageDelivery::query()->whereIn('user_id', $ids)->whereIn('channel', ['email', 'push'])
            ->where('created_at', '>=', $now->copy()->subDays(90))->where('status', '!=', 'failed')
            ->groupBy('user_id', 'channel')
            ->selectRaw('user_id, channel, COUNT(*) as sent, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as tapped')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->user_id][$r->channel] = ['sent' => (int) $r->sent, 'tapped' => (int) $r->tapped];
            });

        return $out;
    }

    /**
     * The channel this customer answers best: the share of messages they
     * tapped, with a gentle starting guess so one lucky tap doesn't decide
     * everything. Push wins a tie (it's instant and free). Null when they
     * can't be reached outside the site at all.
     *
     * @param  list<string>  $reachable
     * @param  array<string, array{sent: int, tapped: int}>  $stats
     */
    public static function bestChannel(array $reachable, array $stats): ?string
    {
        $best = null;
        $bestScore = -1.0;

        foreach (['push', 'email'] as $channel) {
            if (! in_array($channel, $reachable, true)) {
                continue;
            }

            $s = $stats[$channel] ?? ['sent' => 0, 'tapped' => 0];
            $score = ($s['tapped'] + 1) / ($s['sent'] + 2);

            if ($score > $bestScore + 0.0001) {
                $best = $channel;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Counts for the admin: how many in each segment and flag, and how many
     * moved into each segment in the last 7 days.
     *
     * @return array{segments: array<string, array{count: int, joined_7d: int, left_7d: int}>, flags: array<string, int>, total: int}
     */
    public function summary(): array
    {
        $counts = MemberProfile::query()->groupBy('segment')->selectRaw('segment, COUNT(*) as n')->pluck('n', 'segment');
        $weekAgo = now()->subDays(7);
        $in = DB::table('member_segment_changes')->where('changed_at', '>=', $weekAgo)->groupBy('to_segment')->selectRaw('to_segment as s, COUNT(*) as n')->pluck('n', 's');
        $out = DB::table('member_segment_changes')->where('changed_at', '>=', $weekAgo)->groupBy('from_segment')->selectRaw('from_segment as s, COUNT(*) as n')->pluck('n', 's');
        $flags = DB::table('member_profile_flags')->groupBy('flag')->selectRaw('flag, COUNT(*) as n')->pluck('n', 'flag');

        $segments = [];
        foreach (array_keys(self::SEGMENTS) as $key) {
            $segments[$key] = ['count' => (int) ($counts[$key] ?? 0), 'joined_7d' => (int) ($in[$key] ?? 0), 'left_7d' => (int) ($out[$key] ?? 0)];
        }

        return [
            'segments' => $segments,
            'flags' => collect(array_keys(self::FLAGS))->mapWithKeys(fn ($f) => [$f => (int) ($flags[$f] ?? 0)])->all(),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * The biggest moves between segments in the last 7 days, e.g.
     * "Consistent players → Drawing back: 14".
     *
     * @return list<array{from: string, to: string, count: int}>
     */
    public function recentMoves(int $limit = 8): array
    {
        return DB::table('member_segment_changes')->where('changed_at', '>=', now()->subDays(7))
            ->groupBy('from_segment', 'to_segment')
            ->selectRaw('from_segment, to_segment, COUNT(*) as n')
            ->orderByDesc('n')->limit($limit)->get()
            ->map(fn ($r) => ['from' => self::label($r->from_segment), 'to' => self::label($r->to_segment), 'count' => (int) $r->n])
            ->all();
    }
}
