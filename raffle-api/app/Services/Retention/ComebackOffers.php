<?php

namespace App\Services\Retention;

use App\Models\CustomerMessage;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\PlayLimit;
use App\Models\Raffle;
use App\Models\Retention\MemberProfile;
use App\Models\Retention\RetentionOffer;
use App\Models\Wallet;
use App\Notifications\ComebackOfferMessage;
use App\Services\AdminAuditLogService;
use App\Services\Messaging\Audience;
use App\Services\PointsService;
use App\Services\Reminders\ReminderService;
use App\Services\WalletLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Comeback offers: once a day, customers who are slipping away get a
 * personal gift they must claim within a short time ("It's been a while!
 * Here's ₦500 to play the ₦500,000 Raffle Draw. 24 hours to claim ⏳").
 *
 * The gift is picked for each person from their segment and history:
 *  - never played / new: one ticket for the cheapest open raffle, on us;
 *  - first-timers and one-time players: one ticket for a raffle priced
 *    like what they bought before (the second purchase is what matters);
 *  - drawing back: bonus points first (cheap), ticket credit if they
 *    ignored the points;
 *  - at risk: ticket credit, sized from what they usually spend;
 *  - lapsed / dormant: one ticket for a raffle, or ticket credit;
 *  - high-value customers get twice the ticket credit (within the caps).
 * Consistent and repeat players get nothing by default: they come anyway.
 *
 * Money safety: off until switched on (Settings → Reminders → Comeback
 * offers); a monthly budget, a per-customer 30-day cap and a daily limit;
 * money is paid only when the customer claims, as ticket credit (wallet
 * balance: spendable on tickets, never withdrawable); a claim is locked so
 * it can only be paid once. Offers that run out free their budget again.
 *
 * Never sent to: banned customers, staff, anyone who tapped "stop
 * reminders", anyone on a responsible-play break, anyone who already has
 * an open offer or had one in the last few days, and anyone who let
 * several offers in a row run out (they get a 90-day rest).
 *
 * Each offer goes to the site inbox plus the one channel the customer
 * answers best (push or email, learned from their taps), not everything
 * at once. A "last call" goes a few hours before it runs out.
 */
class ComebackOffers
{
    public function __construct(
        private readonly OfferWriter $writer,
        private readonly DeliveryTracker $tracker,
        private readonly WalletLedgerService $ledger,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('retention.offers.enabled');
    }

    /** Every hour (scheduler): end old offers, send last calls, and once a day make new offers. */
    public function runDue(): array
    {
        $result = ['expired' => $this->expire(), 'last_calls' => 0, 'offers' => 0];

        if (! self::enabled()) {
            return $result;
        }

        $result['last_calls'] = $this->sendLastCalls();

        $local = now()->setTimezone(config('raffles.timezone'));

        if ((int) $local->format('G') === (int) config('retention.offers.send_hour', 11)
            && Cache::add('retention-offers:'.$local->toDateString(), 1, now()->addDay())) {
            $result['offers'] = $this->makeOffers();
        }

        return $result;
    }

    /** Offers past their time are marked "ran out" (their budget is free again). */
    public function expire(): int
    {
        return RetentionOffer::query()->where('status', 'open')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);
    }

    /**
     * Make today's offers. Returns how many went out.
     *
     * @param  bool  $ignoreQuietHours  for "send now" from the admin in quiet hours (never used by the scheduler)
     */
    public function makeOffers(bool $ignoreQuietHours = false): int
    {
        if (! self::enabled() || (! $ignoreQuietHours && app(ReminderService::class)->quietHours())) {
            return 0;
        }

        $room = (int) config('retention.offers.daily_max', 200)
            - RetentionOffer::query()->where('created_at', '>=', self::businessStartOf('day'))->count();

        if ($room <= 0) {
            return 0;
        }

        $raffles = $this->openRaffles();
        $candidates = $this->candidates($room * 2);
        $history = RetentionOffer::query()->whereIn('user_id', $candidates->pluck('user_id'))->where('created_at', '>=', now()->subDays(120))
            ->orderByDesc('id')->get(['user_id', 'kind', 'amount', 'status', 'created_at'])->groupBy('user_id');
        $flags = DB::table('member_profile_flags')->whereIn('user_id', $candidates->pluck('user_id'))->get()->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('flag')->all());
        $budget = $this->budgetLeft();
        $made = 0;

        foreach ($candidates as $profile) {
            if ($made >= $room) {
                break;
            }

            $past = $history[$profile->user_id] ?? collect();

            if ($this->givenUp($past)) {
                continue;
            }

            $plan = $this->plan($profile, $past, $flags[$profile->user_id] ?? [], $raffles);
            $plan = $plan ? $this->fitBudget($plan, $profile->user_id, $budget) : null;

            if (! $plan) {
                continue;
            }

            try {
                $this->send($profile, $plan);
                $made++;
                $plan['kind'] === 'points' ? $budget['points'] -= $plan['amount'] : $budget['money'] -= $plan['amount'];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $made;
    }

    /**
     * Who could get an offer today, most valuable to win back first.
     *
     * @return Collection<int, MemberProfile>
     */
    public function candidates(int $limit): Collection
    {
        $segments = array_values(array_intersect((array) config('retention.offers.segments', []), array_keys(MemberSegments::SEGMENTS)));

        if ($segments === []) {
            return collect();
        }

        $eligible = app(Audience::class)->query('segment', ['segments' => $segments], isPromotion: true)
            ->whereNotIn('ID', RetentionOffer::query()->where('status', 'open')->select('user_id'))
            ->whereNotIn('ID', RetentionOffer::query()->where('created_at', '>=', now()->subDays(max(1, (int) config('retention.offers.cooldown_days', 14))))->select('user_id'))
            ->whereNotIn('ID', PlayLimit::query()->where('excluded_until', '>', now())->select('user_id'))
            ->select('ID');

        return MemberProfile::query()->whereIn('user_id', $eligible)->whereIn('segment', $segments)
            ->orderByDesc('spend_total')->orderByDesc('last_play_at')->orderBy('user_id')
            ->limit($limit)->get();
    }

    /** Let this many offers in a row run out, the newest within 90 days: rest for now. */
    private function givenUp(Collection $past): bool
    {
        $n = max(1, (int) config('retention.offers.give_up_after_ignored', 3));
        $recent = $past->take($n);

        return $recent->count() >= $n
            && $recent->every(fn ($o) => $o->status === 'expired')
            && $recent->first()->created_at->gt(now()->subDays(90));
    }

    /**
     * The gift for one customer, before budgets.
     *
     * @param  Collection<int, RetentionOffer>  $past  newest first
     * @param  list<string>  $flags
     * @param  Collection<int, Raffle>  $raffles
     * @return array{kind: string, amount: float, raffle: ?Raffle, style: string, segment: string}|null
     */
    public function plan(MemberProfile $profile, Collection $past, array $flags, Collection $raffles): ?array
    {
        $min = (float) config('retention.offers.credit_min', 200);
        $max = max($min, (float) config('retention.offers.credit_max', 1000));
        $credit = max($min, min($max, round($profile->avg_order * 0.25 / 100) * 100));
        if (in_array('high_value', $flags, true)) {
            $credit = min($max, $credit * 2);
        }

        $ignoredLast = $past->first()?->status === 'expired' ? $past->first()->kind : null;
        $featured = $this->featuredRaffle($raffles);
        $ticket = fn (bool $cheapest) => $this->ticketRaffle($raffles, $cheapest ? 0 : $profile->avg_order);

        $make = function (string $kind, ?Raffle $raffle = null, ?string $style = null) use ($profile, $credit, $featured) {
            return match ($kind) {
                'raffle_ticket' => ['kind' => 'raffle_ticket', 'amount' => (float) $raffle->price, 'raffle' => $raffle, 'style' => $style ?? 'raffle_ticket', 'segment' => $profile->segment],
                'credit' => ['kind' => 'credit', 'amount' => (float) $credit, 'raffle' => $featured, 'style' => $featured ? 'credit' : 'credit_any', 'segment' => $profile->segment],
                default => ['kind' => 'points', 'amount' => (float) max(1, (int) config('retention.offers.points', 500)), 'raffle' => $featured, 'style' => $featured ? 'points' : 'points_any', 'segment' => $profile->segment],
            };
        };

        $plan = match ($profile->segment) {
            'new_signup', 'never_played' => ($r = $ticket(true)) ? $make('raffle_ticket', $r, 'first_ticket') : $make('credit'),
            'first_timer', 'one_and_done', 'lapsed', 'dormant' => ($r = $ticket(false)) ? $make('raffle_ticket', $r) : $make('credit'),
            'drifting' => $ignoredLast === 'points' ? $make('credit') : $make('points'),
            'at_risk' => $make('credit'),
            default => $make('points'),
        };

        // They let this kind of gift run out last time: try the other kind of money gift.
        if ($ignoredLast !== null && $ignoredLast === $plan['kind'] && $plan['kind'] !== 'points') {
            $plan = $plan['kind'] === 'credit' && ($r = $ticket(false)) ? $make('raffle_ticket', $r) : $make('credit');
        }

        return $plan['amount'] > 0 ? $plan : null;
    }

    /**
     * Shrinks or swaps a gift to stay inside the budgets, or drops it.
     *
     * @param  array{money: float, points: float}  $budget
     */
    private function fitBudget(array $plan, int $userId, array $budget): ?array
    {
        if ($plan['kind'] !== 'points') {
            $cap = (float) config('retention.offers.per_member_30_days', 1000);
            $used = (float) RetentionOffer::query()->where('user_id', $userId)->where('kind', '!=', 'points')
                ->whereIn('status', ['open', 'claimed'])->where('created_at', '>=', now()->subDays(30))->sum('amount');

            if ($plan['amount'] <= $budget['money'] && $used + $plan['amount'] <= $cap) {
                return $plan;
            }

            // Out of money budget for this person or overall: points instead, if there are any.
            $plan = [...$plan, 'kind' => 'points', 'amount' => (float) max(1, (int) config('retention.offers.points', 500)), 'style' => $plan['raffle'] ? 'points' : 'points_any'];
        }

        return $plan['amount'] <= $budget['points'] ? $plan : null;
    }

    /**
     * What is left to give this month. Open and claimed offers count; ones
     * that ran out or were cancelled don't.
     *
     * @return array{money: float, points: float, money_used: float, points_used: float}
     */
    public function budgetLeft(): array
    {
        $month = self::businessStartOf('month');
        $base = fn () => RetentionOffer::query()->whereIn('status', ['open', 'claimed'])->where('created_at', '>=', $month);
        $money = (float) $base()->where('kind', '!=', 'points')->sum('amount');
        $points = (float) $base()->where('kind', 'points')->sum('amount');

        return [
            'money' => max(0.0, (float) config('retention.offers.monthly_budget', 0) - $money),
            'points' => max(0.0, (float) config('retention.offers.monthly_points_budget', 0) - $points),
            'money_used' => $money,
            'points_used' => $points,
        ];
    }

    /** @return Collection<int, Raffle> raffles on sale that stay open long enough to use an offer */
    public function openRaffles(): Collection
    {
        $until = now()->addHours((int) config('retention.offers.claim_hours', 24));

        return Raffle::query()->where('status', 'published')->whereNull('cancelled_at')->get()
            ->filter(fn (Raffle $r) => $r->closedReason() === null && (! $r->endsAt() || $r->endsAt()->gt($until)))
            ->values();
    }

    /** The raffle to talk about: the biggest one on sale (most ticket money at stake). */
    private function featuredRaffle(Collection $raffles): ?Raffle
    {
        return $raffles->sortByDesc(fn (Raffle $r) => (float) $r->price * (int) $r->max_tickets)->first();
    }

    /** A raffle whose single ticket we can give: priced closest to what they usually pay, or the cheapest. */
    private function ticketRaffle(Collection $raffles, float $usual): ?Raffle
    {
        $max = (float) config('retention.offers.ticket_max_price', 1000);

        return $raffles->filter(fn (Raffle $r) => (float) $r->price > 0 && (float) $r->price <= $max)
            ->sortBy(fn (Raffle $r) => [abs((float) $r->price - $usual), -1 * (float) $r->price * (int) $r->max_tickets])
            ->first();
    }

    /** Create the offer and send it. */
    private function send(MemberProfile $profile, array $plan): RetentionOffer
    {
        $user = WpUser::query()->findOrFail($profile->user_id);
        $hours = max(1, (int) config('retention.offers.claim_hours', 24));
        $raffle = $plan['raffle'];
        $words = $this->writer->write($plan['style'], $plan['segment'], [
            'name' => \App\Services\Messaging\BroadcastService::firstName($user),
            'amount' => self::amountText($plan['kind'], $plan['amount']),
            'raffle' => $raffle?->title,
            'prize' => $raffle ? ($raffle->grand_prize ?: $raffle->title) : null,
            'hours' => $hours,
        ], $user->ID);

        $channels = $this->channelsFor($user, $profile);

        $offer = RetentionOffer::create([
            'user_id' => $user->ID,
            'segment' => $plan['segment'],
            'kind' => $plan['kind'],
            'amount' => $plan['amount'],
            'raffle_id' => $raffle?->id,
            'headline' => $words['headline'],
            'body' => $words['body'],
            'written_by' => $words['written_by'],
            'channels' => ['inbox', ...$channels],
            'token' => Str::random(32),
            'status' => 'open',
            'expires_at' => now()->addHours($hours),
        ]);

        $this->deliver($offer, $user, ['inbox', ...$channels], 'offer', $offer->headline, $offer->body);

        return $offer;
    }

    /**
     * The one outside channel this customer answers best (push or email),
     * checked again now in case they turned notifications off.
     *
     * @return list<string>
     */
    public function channelsFor(WpUser $user, ?MemberProfile $profile = null): array
    {
        $reachable = array_values(array_filter([
            $user->routeNotificationForOneSignal() ? 'push' : null,
            filled($user->user_email) ? 'email' : null,
        ]));

        $best = $profile?->best_channel;

        if (! $best || ! in_array($best, $reachable, true)) {
            $best = MemberSegments::bestChannel($reachable, []);
        }

        return $best ? [$best] : [];
    }

    /** @param  list<string>  $channels */
    private function deliver(RetentionOffer $offer, WpUser $user, array $channels, string $source, string $headline, string $body): void
    {
        $tracked = $this->tracker->createMany($source, $offer->id, array_map(fn ($c) => [
            'user_id' => (int) $user->ID, 'channel' => $c, 'target_url' => $offer->url(),
        ], $channels))[(int) $user->ID] ?? [];

        if (isset($tracked['inbox'])) {
            CustomerMessage::create([
                'user_id' => $user->ID,
                'kind' => 'offer',
                'delivery_id' => $tracked['inbox']['id'],
                'title' => mb_substr($headline, 0, 120),
                'body' => $body,
                'link_url' => $offer->url(),
                'link_label' => $offer->isMoney() ? 'Claim my ₦'.number_format($offer->amount) : 'Claim my points',
                'created_at' => now(),
            ]);
        }

        $outside = array_values(array_intersect($channels, ['email', 'push']));

        if ($outside !== []) {
            $tokens = array_map(fn ($row) => $row['token'], array_intersect_key($tracked, array_flip($outside)));
            $user->notify(new ComebackOfferMessage($offer->id, $headline, $body, $offer->isMoney() ? 'Claim my ₦'.number_format($offer->amount) : 'Claim my points', $offer->expires_at, $outside, $tokens));
        }
    }

    /** "Only 3 hours left" to everyone with an offer about to run out (once each, never in quiet hours). */
    public function sendLastCalls(): int
    {
        $before = max(1, (int) config('retention.offers.last_call_hours', 3));

        if (app(ReminderService::class)->quietHours()) {
            return 0;
        }

        $sent = 0;

        RetentionOffer::query()->where('status', 'open')->whereNull('last_call_sent_at')
            ->whereBetween('expires_at', [now()->addMinutes(15), now()->addHours($before)])
            ->orderBy('expires_at')->limit(500)->get()
            ->each(function (RetentionOffer $offer) use (&$sent) {
                // Claim the last call first, so two runs can't both send it.
                if (! RetentionOffer::query()->whereKey($offer->id)->whereNull('last_call_sent_at')->update(['last_call_sent_at' => now()])) {
                    return;
                }

                $user = WpUser::query()->find($offer->user_id);

                if (! $user || app(ReminderService::class)->optedOut($user->ID)) {
                    return;
                }

                $channels = $this->channelsFor($user, MemberProfile::query()->find($user->ID));

                if ($channels === []) {
                    return;
                }

                $words = $this->writer->write('last_call', $offer->segment, [
                    'name' => \App\Services\Messaging\BroadcastService::firstName($user),
                    'amount' => self::amountText($offer->kind, $offer->amount),
                    'hours' => max(1, (int) ceil(now()->diffInMinutes($offer->expires_at) / 60)),
                ], $user->ID);

                try {
                    $this->deliver($offer, $user, $channels, 'last_call', $words['headline'], $words['body']);
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $sent;
    }

    /** Start of today / this month in business time, as the app's own time (how dates are saved). */
    private static function businessStartOf(string $unit): Carbon
    {
        return now()->setTimezone(config('raffles.timezone'))->startOf($unit)->setTimezone(config('app.timezone'));
    }

    public static function amountText(string $kind, float $amount): string
    {
        return $kind === 'points' ? number_format($amount).' points' : '₦'.number_format($amount);
    }

    /**
     * The customer claims their offer. Paid once, inside one database
     * transaction with the offer locked.
     *
     * @return array{offer: RetentionOffer, redirect: string, message: string}
     *
     * @throws RuntimeException with a message to show the customer
     */
    public function claim(string $token, WpUser $user): array
    {
        $offer = DB::transaction(function () use ($token, $user) {
            $offer = RetentionOffer::query()->where('token', $token)->lockForUpdate()->first();

            if (! $offer || (int) $offer->user_id !== (int) $user->ID) {
                throw new RuntimeException('This offer could not be found.');
            }
            if ($offer->status === 'claimed') {
                throw new RuntimeException('You already claimed this offer.');
            }
            if ($offer->status !== 'open' || $offer->expires_at->isPast()) {
                throw new RuntimeException('Sorry, this offer has run out.');
            }
            if (PlayLimit::query()->where('user_id', $user->ID)->where('excluded_until', '>', now())->exists()) {
                throw new RuntimeException('You\'re on a break from playing, so offers are paused until it ends.');
            }

            if ($offer->isMoney()) {
                $wallet = Wallet::query()->where('user_id', $user->ID)->lockForUpdate()->first()
                    ?? Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
                $wallet->wallet_balance = (float) $wallet->wallet_balance + $offer->amount;
                $wallet->save();

                $this->ledger->recordCredit(
                    userId: $user->ID,
                    balanceType: 'wallet',
                    amount: $offer->amount,
                    reason: 'comeback_offer',
                    referenceType: 'retention_offer',
                    referenceId: $offer->id,
                    description: 'Comeback offer claimed',
                );
            } else {
                app(PointsService::class)->credit($user, (int) $offer->amount, 'comeback_offer', 'retention_offer', $offer->id, 'Comeback offer claimed');
            }

            $offer->update(['status' => 'claimed', 'claimed_at' => now()]);

            return $offer;
        });

        $raffle = $offer->raffle_id ? Raffle::query()->find($offer->raffle_id) : null;
        $redirect = match (true) {
            $offer->kind === 'points' => '/rewards',
            $raffle !== null && $raffle->closedReason() === null => '/raffles/'.$raffle->public_id,
            default => '/raffles',
        };

        return [
            'offer' => $offer,
            'redirect' => $redirect,
            'message' => $offer->isMoney()
                ? '₦'.number_format($offer->amount).' ticket credit is now in your wallet. Use it on any raffle!'
                : number_format($offer->amount).' points added to your rewards.',
        ];
    }

    public function cancel(RetentionOffer $offer, WpUser $admin): bool
    {
        $done = RetentionOffer::query()->whereKey($offer->id)->where('status', 'open')
            ->update(['status' => 'cancelled', 'cancelled_by' => $admin->ID, 'updated_at' => now()]);

        if ($done) {
            app(AdminAuditLogService::class)->record($admin, 'retention_offer.cancelled', RetentionOffer::class, $offer->id, [
                'customer' => $offer->user_id, 'offer' => $offer->prizeText(),
            ]);
        }

        return (bool) $done;
    }

    /**
     * Did it work? For offers claimed in the last 8 days: the first ticket
     * purchase after claiming, and how much they paid for tickets in the 7
     * days after (verified payments only).
     */
    public function trackResults(): int
    {
        $updated = 0;

        RetentionOffer::query()->where('status', 'claimed')->where('claimed_at', '>=', now()->subDays(8))->get()
            ->each(function (RetentionOffer $offer) use (&$updated) {
                $until = $offer->claimed_at->copy()->addDays(7);
                $txns = RaffleTransaction::query()->where('user_id', $offer->user_id)->where('status', 'verified_final')
                    ->whereBetween('created_at', [$offer->claimed_at, $until])
                    ->whereIn('id', RaffleEntry::query()->where('user_id', $offer->user_id)->select('txn_id'))
                    ->get(['claimed_amount', 'created_at']);
                $first = RaffleEntry::query()->where('user_id', $offer->user_id)->whereBetween('created_at', [$offer->claimed_at, $until])->min('created_at');

                $offer->update([
                    'spend_after' => round((float) $txns->sum('claimed_amount'), 2),
                    'first_purchase_at' => $first ? Carbon::parse($first) : null,
                ]);
                $updated++;
            });

        return $updated;
    }

    /**
     * How offers did since a date, by segment and by kind.
     *
     * @return array{totals: array, by_segment: array, by_kind: array}
     */
    public function performance(Carbon $since): array
    {
        $offers = RetentionOffer::query()->where('created_at', '>=', $since)->get(['segment', 'kind', 'amount', 'status', 'first_purchase_at', 'spend_after']);

        $sum = fn (Collection $rows) => [
            'offers' => $rows->count(),
            'claimed' => $rows->where('status', 'claimed')->count(),
            'came_back' => $rows->whereNotNull('first_purchase_at')->count(),
            'credit_paid' => (float) $rows->where('status', 'claimed')->where('kind', '!=', 'points')->sum('amount'),
            'points_paid' => (float) $rows->where('status', 'claimed')->where('kind', 'points')->sum('amount'),
            'spend_after' => (float) $rows->sum('spend_after'),
        ];

        return [
            'totals' => $sum($offers),
            'by_segment' => $offers->groupBy('segment')->map($sum)->all(),
            'by_kind' => $offers->groupBy('kind')->map($sum)->all(),
        ];
    }
}
