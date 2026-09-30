<?php

namespace App\Services\Reminders;

use App\Models\CheckoutVisit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\PlayLimit;
use App\Models\Raffle;
use App\Models\ReminderSend;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Reminder;
use App\Support\Features;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reminders that bring people back (Settings → On / off → New features,
 * numbers in Settings → Reminders):
 *
 *  - raffle_ending:      "Raffle X ends in 1 hour", before a raffle stops selling.
 *  - abandoned_checkout: "You left 3 tickets in checkout", a while after
 *                        someone opened checkout and didn't pay.
 *
 * Rules that keep reminders welcome rather than spammy:
 *  - the same reminder never goes twice (reminder_sends' unique key);
 *  - at most max_per_day reminders per person, all kinds together;
 *  - nothing during quiet hours (checkout reminders wait until morning,
 *    "ends soon" ones are skipped because they'd be too late);
 *  - anyone can stop them with one tap (the link in every email), and
 *    people on a responsible-play break are never reminded to play.
 */
class ReminderService
{
    public const OPT_OUT_META = 'rk_reminders_off';

    /** Checkouts older than this are forgotten, not reminded about. */
    private const CHECKOUT_MAX_AGE_HOURS = 24;

    /** An "ends soon" reminder closer to the end than this would be useless. */
    private const MIN_MINUTES_LEFT = 5;

    /** Called when the checkout page opens. */
    public function rememberCheckout(WpUser $user, array $raffle, array $ticketNumbers): void
    {
        if (! Features::on('reminders')) {
            return;
        }

        CheckoutVisit::query()->updateOrCreate(
            ['user_id' => $user->ID, 'reminded_at' => null],
            ['raffle_id' => $raffle['id'], 'quantity' => count($ticketNumbers), 'ticket_numbers' => array_values($ticketNumbers), 'opened_at' => now()],
        );
    }

    /**
     * One run of the scheduler.
     *
     * @return array{raffle_ending: int, abandoned_checkout: int}
     */
    public function run(): array
    {
        if (! Features::on('reminders') || $this->quietHours()) {
            return ['raffle_ending' => 0, 'abandoned_checkout' => 0];
        }

        return [
            'raffle_ending' => config('reminders.raffle_ending.enabled') ? $this->raffleEnding() : 0,
            'abandoned_checkout' => config('reminders.abandoned_checkout.enabled') ? $this->abandonedCheckouts() : 0,
        ];
    }

    public function raffleEnding(): int
    {
        $window = max(10, (int) config('reminders.raffle_ending.minutes_before', 60));
        $sent = 0;

        $raffles = Raffle::query()
            ->where('status', 'published')
            ->where(fn ($q) => $q
                ->whereBetween('sales_end_at', [now(), now()->addMinutes($window)])
                ->orWhere(fn ($q) => $q->whereNull('sales_end_at')->whereBetween('expiry', [now()->subDay()->toDateString(), now()->addDay()->toDateString()])))
            ->get()
            ->filter(function (Raffle $raffle) use ($window) {
                $ends = $raffle->endsAt();

                return $ends
                    && $ends->between(now()->addMinutes(self::MIN_MINUTES_LEFT), now()->addMinutes($window))
                    && $raffle->closedReason() === null;
            });

        foreach ($raffles as $raffle) {
            $entrants = RaffleEntry::query()->where('raffle_id', $raffle->public_id)->distinct()->pluck('user_id');

            foreach ($this->audienceFor($raffle, $entrants) as $userId) {
                $user = WpUser::query()->find($userId);

                if (! $user) {
                    continue;
                }

                $minutes = (int) ceil(now()->diffInMinutes($raffle->endsAt()));
                $left = $minutes >= 55 ? 'in '.max(1, (int) round($minutes / 60)).' hour'.(round($minutes / 60) > 1 ? 's' : '') : "in {$minutes} minutes";
                $hasTickets = $entrants->contains($userId);

                $sent += (int) $this->deliver($user, 'raffle_ending', "raffle:{$raffle->public_id}", fn (array $channels) => new Reminder(
                    kind: 'raffle_ending',
                    title: "⏰ {$raffle->title} ends {$left}",
                    body: $hasTickets
                        ? "Sales for {$raffle->title} close {$left}. Add a few more tickets for extra chances before it's too late."
                        : "Last chance: {$raffle->title} stops selling tickets {$left}.",
                    url: url("/raffles/{$raffle->public_id}"),
                    buttonLabel: 'Get tickets',
                    raffleTitle: (string) $raffle->title,
                    channels: $channels,
                ));
            }
        }

        return $sent;
    }

    public function abandonedCheckouts(): int
    {
        $after = max(5, (int) config('reminders.abandoned_checkout.after_minutes', 45));
        $sent = 0;

        $visits = CheckoutVisit::query()
            ->whereNull('reminded_at')
            ->where('opened_at', '<=', now()->subMinutes($after))
            ->where('opened_at', '>=', now()->subHours(self::CHECKOUT_MAX_AGE_HOURS))
            ->orderBy('opened_at')
            ->limit(500)
            ->get();

        foreach ($visits as $visit) {
            $visit->update(['reminded_at' => now()]);

            $raffle = Raffle::query()->where('public_id', $visit->raffle_id)->first();
            $user = WpUser::query()->find($visit->user_id);

            $bought = RaffleEntry::query()
                ->where('user_id', $visit->user_id)
                ->where('raffle_id', $visit->raffle_id)
                ->where('created_at', '>=', $visit->opened_at)
                ->exists();

            if (! $raffle || ! $user || $bought || $raffle->closedReason() !== null) {
                continue;
            }

            $count = $visit->quantity === 1 ? 'a ticket' : "{$visit->quantity} tickets";

            $sent += (int) $this->deliver($user, 'abandoned_checkout', "checkout:{$visit->id}", fn (array $channels) => new Reminder(
                kind: 'abandoned_checkout',
                title: "You left {$count} in checkout 🎟️",
                body: "Your numbers for {$raffle->title} aren't yours yet. Finish checkout before someone else picks them.",
                url: url("/raffles/{$raffle->public_id}/numbers?".http_build_query(['qty' => $visit->quantity, 'numbers' => implode(',', (array) $visit->ticket_numbers)])),
                buttonLabel: 'Finish checkout',
                raffleTitle: (string) $raffle->title,
                channels: $channels,
            ));
        }

        return $sent;
    }

    /** Which channels can reach this person right now. */
    public function channelsFor(WpUser $user): array
    {
        return array_values(array_filter([
            config('reminders.channels.push') && OneSignalChannel::appId() && $user->routeNotificationForOneSignal() ? 'push' : null,
            config('reminders.channels.email') && filled($user->user_email) ? 'email' : null,
            config('reminders.channels.whatsapp') && WhatsAppChannel::configured() && WhatsAppChannel::normalise($user->routeNotificationForWhatsApp()) ? 'whatsapp' : null,
        ]));
    }

    public function optedOut(int $userId): bool
    {
        return WpUserMeta::query()->where('user_id', $userId)->where('meta_key', self::OPT_OUT_META)->value('meta_value') === '1';
    }

    public function setOptedOut(int $userId, bool $off): void
    {
        WpUserMeta::query()->updateOrCreate(['user_id' => $userId, 'meta_key' => self::OPT_OUT_META], ['meta_value' => $off ? '1' : '0']);
    }

    public function quietHours(?Carbon $at = null): bool
    {
        $hour = (int) ($at ?? now())->copy()->setTimezone(config('raffles.timezone'))->format('G');
        $from = (int) config('reminders.quiet_from', 22);
        $until = (int) config('reminders.quiet_until', 8);

        if ($from === $until) {
            return false;
        }

        return $from > $until ? ($hour >= $from || $hour < $until) : ($hour >= $from && $hour < $until);
    }

    /** @return Collection<int, int> */
    private function audienceFor(Raffle $raffle, Collection $entrants): Collection
    {
        $audience = config('reminders.raffle_ending.audience', 'entrants_and_checkout');
        $ids = $entrants;

        if (in_array($audience, ['entrants_and_checkout', 'recent_players'], true)) {
            $ids = $ids->merge(CheckoutVisit::query()->where('raffle_id', $raffle->public_id)->where('opened_at', '>=', now()->subDays(3))->pluck('user_id'));
        }

        if ($audience === 'recent_players') {
            $ids = $ids->merge(RaffleEntry::query()->where('created_at', '>=', now()->subDays(30))->distinct()->limit(5000)->pluck('user_id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Sends one reminder unless a rule says not to. True if it went.
     *
     * @param  callable(list<string>): Reminder  $make  builds it for the channels that can reach this person
     */
    private function deliver(WpUser $user, string $kind, string $subject, callable $make): bool
    {
        if ($this->optedOut($user->ID) || $this->onBreak($user->ID)) {
            return false;
        }

        $sentToday = ReminderSend::query()->where('user_id', $user->ID)->where('sent_at', '>=', now()->subDay())->count();

        if ($sentToday >= max(1, (int) config('reminders.max_per_day', 2))) {
            return false;
        }

        $channels = $this->channelsFor($user);

        if ($channels === []) {
            return false;
        }

        try {
            ReminderSend::create(['user_id' => $user->ID, 'kind' => $kind, 'subject' => $subject, 'channels' => $channels, 'sent_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return false; // already sent
        }

        try {
            $user->notify($make($channels));
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /** Someone on a responsible-play break is never nudged to play. */
    private function onBreak(int $userId): bool
    {
        try {
            return PlayLimit::query()->where('user_id', $userId)->where('excluded_until', '>', now())->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
