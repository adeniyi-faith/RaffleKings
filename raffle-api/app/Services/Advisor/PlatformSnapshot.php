<?php

namespace App\Services\Advisor;

use App\Models\AdvisorReport;
use App\Models\DailyDrop;
use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use Illuminate\Support\Carbon;

/**
 * The facts the Raffle advisor is shown: how players have behaved over the
 * last 90 days and how recent raffles sold. Only totals, averages and raffle
 * details leave the site: never a name, email, phone number or user id.
 */
class PlatformSnapshot
{
    public const DAYS = 90;

    private const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /** @return array<string, mixed> */
    public function build(): array
    {
        $tz = config('raffles.timezone', 'Africa/Lagos');
        $since = now($tz)->subDays(self::DAYS)->startOfDay()->utc();

        $behaviour = $this->behaviour($since, $tz);
        $raffles = $this->recentRaffles();

        return [
            'generated_at' => now($tz)->toIso8601String(),
            'currency' => 'NGN',
            'period_days' => self::DAYS,
            'overview' => $behaviour['overview'],
            'weekly' => $behaviour['weekly'],
            'players' => $behaviour['players'],
            'order_sizes' => $behaviour['order_sizes'],
            'busiest_times' => $behaviour['busiest_times'],
            'recent_raffles' => $raffles,
            'by_ticket_price' => $this->groupSellThrough($raffles, fn ($r) => $this->priceBand($r['ticket_price'])),
            'by_prize_type' => $this->groupSellThrough($raffles, fn ($r) => $r['prize_type']),
            'raffles_on_sale_now' => collect($raffles)->where('state', 'on sale')->count(),
            'past_advice' => $this->pastAdvice(),
            'daily_drops' => $this->dailyDrops(),
        ];
    }

    /**
     * One pass over the period's tickets: weekly trend, returning players,
     * order sizes and the busiest days and hours.
     *
     * @return array<string, mixed>
     */
    private function behaviour(Carbon $since, string $tz): array
    {
        $weeks = [];
        $orders = [];
        $weekday = array_fill_keys(self::WEEKDAYS, 0);
        $hours = ['00-06' => 0, '06-12' => 0, '12-18' => 0, '18-24' => 0];
        $rafflesPerPlayer = [];
        $buyers = [];
        $prices = Raffle::query()->pluck('price', 'public_id');
        $tickets = 0;
        $sales = 0.0;

        $query = RaffleEntry::query()
            ->where('created_at', '>=', $since)
            ->where('ticket_number', '>', 0)
            ->select(['id', 'user_id', 'raffle_id', 'txn_id', 'created_at']);

        foreach ($query->lazyById(2000) as $entry) {
            $at = Carbon::parse($entry->getRawOriginal('created_at'), 'UTC')->setTimezone($tz);
            $week = $at->copy()->startOfWeek()->toDateString();
            $price = (float) ($prices[$entry->raffle_id] ?? 0);

            $weeks[$week]['tickets'] = ($weeks[$week]['tickets'] ?? 0) + 1;
            $weeks[$week]['sales'] = ($weeks[$week]['sales'] ?? 0) + $price;
            $weeks[$week]['buyers'][$entry->user_id] = true;

            $weekday[self::WEEKDAYS[$at->dayOfWeekIso - 1]]++;
            $hours[match (true) {
                $at->hour < 6 => '00-06', $at->hour < 12 => '06-12', $at->hour < 18 => '12-18', default => '18-24'
            }]++;

            if ($entry->txn_id > 0) {
                $orders[$entry->txn_id] = ($orders[$entry->txn_id] ?? 0) + 1;
            }

            $rafflesPerPlayer[$entry->user_id][$entry->raffle_id] = true;
            $buyers[$entry->user_id] ??= $at->toDateString();
            $tickets++;
            $sales += $price;
        }

        // A player is "new" in the week of the first ticket they ever bought.
        $firstEver = $buyers === [] ? collect() : RaffleEntry::query()
            ->whereIn('user_id', array_keys($buyers))
            ->where('ticket_number', '>', 0)
            ->selectRaw('user_id, min(created_at) as first_at')
            ->groupBy('user_id')
            ->pluck('first_at', 'user_id');

        $newPerWeek = [];
        foreach ($firstEver as $first) {
            $at = Carbon::parse($first, 'UTC')->setTimezone($tz);
            if ($at->greaterThanOrEqualTo($since)) {
                $newPerWeek[$at->copy()->startOfWeek()->toDateString()] = ($newPerWeek[$at->copy()->startOfWeek()->toDateString()] ?? 0) + 1;
            }
        }

        ksort($weeks);
        $weekly = [];
        foreach ($weeks as $start => $w) {
            $weekly[] = [
                'week_starting' => $start,
                'tickets' => $w['tickets'],
                'sales' => round($w['sales']),
                'buyers' => count($w['buyers']),
                'new_buyers' => $newPerWeek[$start] ?? 0,
            ];
        }

        $rafflesEach = array_map('count', $rafflesPerPlayer);
        $orderSizes = ['1 ticket' => 0, '2-5 tickets' => 0, '6-10 tickets' => 0, '11-50 tickets' => 0, 'over 50 tickets' => 0];
        foreach ($orders as $n) {
            $orderSizes[match (true) {
                $n === 1 => '1 ticket', $n <= 5 => '2-5 tickets', $n <= 10 => '6-10 tickets', $n <= 50 => '11-50 tickets', default => 'over 50 tickets'
            }]++;
        }

        return [
            'overview' => [
                'tickets_sold' => $tickets,
                'ticket_sales' => round($sales),
                'different_players' => count($buyers),
                'orders' => count($orders),
                'average_tickets_per_order' => $orders ? round(array_sum($orders) / count($orders), 1) : 0,
                'average_spend_per_player' => $buyers ? round($sales / count($buyers)) : 0,
            ],
            'weekly' => $weekly,
            'players' => [
                'played_1_raffle' => count(array_filter($rafflesEach, fn ($n) => $n === 1)),
                'played_2_to_3_raffles' => count(array_filter($rafflesEach, fn ($n) => $n >= 2 && $n <= 3)),
                'played_4_or_more_raffles' => count(array_filter($rafflesEach, fn ($n) => $n >= 4)),
                'new_players_in_period' => array_sum($newPerWeek),
                'returning_players_in_period' => count($buyers) - array_sum($newPerWeek),
            ],
            'order_sizes' => $orderSizes,
            'busiest_times' => ['tickets_by_weekday' => $weekday, 'tickets_by_hour_lagos' => $hours],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentRaffles(): array
    {
        $opened = $this->openedFromAdvice();

        return Raffle::query()
            ->whereIn('status', Raffle::PUBLIC_STATUSES)
            ->with('prizeTiers')
            ->withCount('entries as sold')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(function (Raffle $raffle) use ($opened) {
                $sold = (int) $raffle->sold;
                $span = $sold > 0 ? $raffle->entries()->selectRaw('min(created_at) as first_at, max(created_at) as last_at')->first() : null;
                $prizeCash = (float) $raffle->prizeTiers->sum(fn ($t) => (float) $t->cash_value * max(1, (int) $t->winner_count));
                $fullRevenue = (float) $raffle->price * (int) $raffle->max_tickets;
                $rules = $raffle->drawRules();

                return [
                    'title' => $raffle->title,
                    'state' => match ($raffle->closedReason($sold)) {
                        null => 'on sale',
                        'sold_out' => 'sold out',
                        'ended' => 'ended',
                        'cancelled' => 'cancelled',
                        default => 'closed',
                    },
                    'started' => $raffle->created_at?->toDateString(),
                    'ticket_price' => (float) $raffle->price,
                    'tickets_available' => (int) $raffle->max_tickets,
                    'tickets_sold' => $sold,
                    'sell_through_percent' => $raffle->max_tickets > 0 ? round($sold / $raffle->max_tickets * 100) : null,
                    'days_selling' => $span ? round(Carbon::parse($span->first_at)->diffInHours(Carbon::parse($span->last_at)) / 24, 1) : null,
                    'different_players' => $sold > 0 ? $raffle->entries()->distinct()->count('user_id') : 0,
                    'prize_type' => $raffle->prize_type,
                    'grand_prize' => $raffle->grand_prize,
                    'prize_tiers' => $raffle->prizeTiers->count(),
                    'winners' => (int) $raffle->prizeTiers->sum('winner_count'),
                    'cash_prizes_total' => round($prizeCash),
                    'cash_prizes_as_percent_of_full_sales' => $fullRevenue > 0 && $prizeCash > 0 ? round($prizeCash / $fullRevenue * 100) : null,
                    'flash' => (bool) $raffle->is_flash,
                    'live_draw' => (bool) $raffle->is_live_draw_enabled,
                    'rules' => array_filter([
                        'new_players_only' => $rules->newPlayersOnly ?: null,
                        'loyalty_bonus_entries' => $rules->loyaltyBonusEntries ?: null,
                        'members_tier_and_above' => $rules->minTier,
                        'consolation_points' => $rules->consolationPoints > 0 ? "{$rules->consolationPoints} points for {$rules->consolationMinTickets}+ tickets" : null,
                        'max_wins_per_person' => $rules->maxWinsPerPerson,
                    ]),
                    'opened_from_advice' => isset($opened[$raffle->id]),
                ];
            })
            ->all();
    }

    /**
     * Average sell-through for groups of raffles that have finished selling.
     *
     * @param  list<array<string, mixed>>  $raffles
     * @return array<string, array{raffles: int, average_sell_through_percent: int}>
     */
    private function groupSellThrough(array $raffles, \Closure $key): array
    {
        return collect($raffles)
            ->reject(fn ($r) => $r['state'] === 'on sale' || $r['state'] === 'cancelled' || $r['sell_through_percent'] === null)
            ->groupBy($key)
            ->map(fn ($group) => ['raffles' => $group->count(), 'average_sell_through_percent' => (int) round($group->avg('sell_through_percent'))])
            ->all();
    }

    /**
     * How Daily Drops have done: the setup, how much they paid, and how
     * their raffle sold, so the advisor can tell whether drops help.
     *
     * @return list<array<string, mixed>>
     */
    private function dailyDrops(): array
    {
        return DailyDrop::query()->whereIn('status', ['active', 'paused', 'ended'])->with('raffle')->latest('id')->limit(10)->get()
            ->map(fn (DailyDrop $drop) => [
                'raffle' => $drop->raffle?->title,
                'status' => $drop->status,
                'share_of_sales_percent' => $drop->pot_percent,
                'winners_per_day' => $drop->winners_per_day,
                'daily_cap' => $drop->daily_cap,
                'drop_time' => $drop->drop_time,
                'days_paid' => $drop->runs()->where('result', 'paid')->count(),
                'total_paid' => round((float) $drop->runs()->sum('pot')),
                'raffle_tickets_sold' => $drop->raffle ? $drop->raffle->soldTickets() : null,
                'raffle_tickets_available' => $drop->raffle?->max_tickets,
            ])
            ->all();
    }

    private function priceBand(float $price): string
    {
        return match (true) {
            $price <= 100 => '₦100 or less',
            $price <= 500 => '₦101-500',
            $price <= 1000 => '₦501-1,000',
            $price <= 5000 => '₦1,001-5,000',
            default => 'over ₦5,000',
        };
    }

    /** @return array<int, true> raffle id => true, for raffles opened from a recommendation */
    private function openedFromAdvice(): array
    {
        $ids = [];
        AdvisorReport::query()->where('status', 'ready')->latest('id')->limit(50)->get(['recommendations'])
            ->each(function (AdvisorReport $report) use (&$ids) {
                foreach ((array) $report->recommendations as $rec) {
                    if (! empty($rec['opened_raffle_id'])) {
                        $ids[(int) $rec['opened_raffle_id']] = true;
                    }
                }
            });

        return $ids;
    }

    /**
     * What the advisor suggested lately, and how any raffle opened from that
     * advice actually sold, so it can learn from what worked.
     *
     * @return list<array<string, mixed>>
     */
    private function pastAdvice(): array
    {
        return AdvisorReport::query()->where('status', 'ready')->latest('id')->limit(3)->get()
            ->map(fn (AdvisorReport $report) => [
                'date' => $report->created_at?->toDateString(),
                'suggestions' => collect((array) $report->recommendations)->map(function ($rec) {
                    $raffle = ! empty($rec['opened_raffle_id']) ? Raffle::query()->withCount('entries as sold')->find($rec['opened_raffle_id']) : null;

                    return array_filter([
                        'title' => $rec['title'] ?? '',
                        'kind' => $rec['kind'] ?? null,
                        'acted_on' => $raffle !== null,
                        'result' => $raffle ? [
                            'status' => $raffle->status,
                            'tickets_sold' => (int) $raffle->sold,
                            'tickets_available' => (int) $raffle->max_tickets,
                        ] : null,
                    ], fn ($v) => $v !== null);
                })->values()->all(),
            ])
            ->all();
    }
}
