<?php

namespace App\Services;

use App\Models\GoldenBoxOffer;
use App\Models\Legacy\WpUser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Golden Box: the old site's "you left something behind" offer
 * (raffles.php's gold banner), rebuilt so the SERVER decides who gets it.
 * On the old site the browser simply told the server "I have the
 * discount" (?discount_applied=true), so anyone could award it to
 * themselves; item 43 switched it off for that reason.
 *
 * How it works now:
 *  1. Opening checkout records the order (rememberCheckout).
 *  2. If the customer leaves without paying and the order is big enough,
 *     the raffle list shows the offer (offerFor) for a limited time,
 *     counted from when they first see it.
 *  3. Tapping it (claim) starts a short window in which that raffle and
 *     number of tickets costs golden_box_percent_off less. The price
 *     quote and the purchase both ask discountFor(), never the browser.
 *  4. Paying uses it up (markUsed, inside the purchase's own database
 *     transaction), and a customer who used one waits
 *     golden_box_cooldown_days for the next. Paying without it closes
 *     the offer (markCompleted), so nobody sees it right after buying.
 *
 * All numbers live in config/pricing.php (Settings → Raffles & pricing).
 */
class GoldenBoxService
{
    /** An unpaid checkout older than this is stale and never offered. */
    private const OPEN_CHECKOUT_HOURS = 24;

    public function __construct(
        private readonly RaffleReadService $raffles,
        private readonly TicketPricingService $pricing,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('pricing.golden_box_enabled', false)
            && (float) config('pricing.golden_box_percent_off', 0) > 0;
    }

    /**
     * Called when the checkout page opens. Keeps ONE open row per customer,
     * following their latest order. The offer's display window, once
     * started, is kept, so going back to checkout never restarts the timer.
     *
     * @param  int[]  $ticketNumbers
     */
    public function rememberCheckout(WpUser $user, array $raffle, array $ticketNumbers): void
    {
        if (! $this->enabled() || $this->claimedOffer($user->ID)) {
            return;
        }

        $quantity = count($ticketNumbers);
        $attributes = [
            'raffle_id' => $raffle['id'],
            'quantity' => $quantity,
            'ticket_numbers' => array_values($ticketNumbers),
            'order_total' => $this->pricing->calculate($quantity, (float) $raffle['price']),
        ];

        $open = GoldenBoxOffer::query()->where('user_id', $user->ID)->where('status', 'open')->latest('id')->first();

        if ($open) {
            $open->fill($attributes)->save();
            $open->touch();

            return;
        }

        GoldenBoxOffer::create($attributes + ['user_id' => $user->ID, 'status' => 'open']);
    }

    /**
     * What the gold banner shows, or null when there's nothing to offer.
     * A claimed, still-running discount is returned too (state "claimed"),
     * so the banner can say "your discount is waiting" with a countdown.
     *
     * @return array<string, mixed>|null
     */
    public function offerFor(WpUser $user): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($claimed = $this->claimedOffer($user->ID)) {
            return $this->present($claimed, 'claimed');
        }

        $offer = $this->eligibleOpenOffer($user->ID);

        if (! $offer) {
            return null;
        }

        if (! $offer->offered_until) {
            $offer->update(['offered_until' => now()->addMinutes((int) config('pricing.golden_box_offer_minutes', 30))]);
        }

        return $this->present($offer, 'offered');
    }

    /**
     * The customer tapped the banner: start the discount window.
     *
     * @throws InvalidArgumentException if the offer is gone, expired or not theirs
     */
    public function claim(WpUser $user, int $offerId): array
    {
        if (! $this->enabled()) {
            throw new InvalidArgumentException('This offer has ended.');
        }

        return DB::transaction(function () use ($user, $offerId) {
            $offer = GoldenBoxOffer::query()->whereKey($offerId)->where('user_id', $user->ID)->lockForUpdate()->first();

            if ($offer && $offer->status === 'claimed' && $offer->claim_expires_at?->isFuture()) {
                return $this->present($offer, 'claimed'); // a double tap
            }

            $eligible = $this->eligibleOpenOffer($user->ID);

            if (! $offer || ! $eligible || $eligible->id !== $offer->id || ! $offer->offered_until) {
                throw new InvalidArgumentException('This offer has ended.');
            }

            $offer->update([
                'status' => 'claimed',
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinutes((int) config('pricing.golden_box_claim_minutes', 25)),
            ]);

            return $this->present($offer, 'claimed');
        });
    }

    /**
     * The claimed offer that discounts THIS order, if any: same customer,
     * raffle and number of tickets, still inside its window.
     */
    public function discountFor(int $userId, int $raffleId, int $quantity): ?GoldenBoxOffer
    {
        if (! $this->enabled()) {
            return null;
        }

        $offer = $this->claimedOffer($userId);

        return $offer && $offer->raffle_id === $raffleId && $offer->quantity === $quantity ? $offer : null;
    }

    /**
     * Uses the discount up. Call inside the purchase's database
     * transaction: the row is locked and re-checked, so two purchases at
     * once can't both get it.
     */
    public function markUsed(GoldenBoxOffer $offer, int $raffleTransactionId): bool
    {
        $locked = GoldenBoxOffer::query()->whereKey($offer->id)->lockForUpdate()->first();

        if (! $locked || $locked->status !== 'claimed' || ! $locked->claim_expires_at?->isFuture()) {
            return false;
        }

        $locked->update(['status' => 'used', 'used_at' => now(), 'raffle_transaction_id' => $raffleTransactionId]);

        return true;
    }

    /** The customer paid: no offer should follow them around afterwards. */
    public function markCompleted(int $userId): void
    {
        GoldenBoxOffer::query()->where('user_id', $userId)->where('status', 'open')->update(['status' => 'completed', 'updated_at' => now()]);
    }

    private function claimedOffer(int $userId): ?GoldenBoxOffer
    {
        return GoldenBoxOffer::query()
            ->where('user_id', $userId)
            ->where('status', 'claimed')
            ->where('claim_expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    private function eligibleOpenOffer(int $userId): ?GoldenBoxOffer
    {
        $offer = GoldenBoxOffer::query()
            ->where('user_id', $userId)
            ->where('status', 'open')
            ->where('updated_at', '>=', now()->subHours(self::OPEN_CHECKOUT_HOURS))
            ->latest('id')
            ->first();

        if (! $offer || (float) $offer->order_total < (float) config('pricing.golden_box_minimum_order', 0)) {
            return null;
        }

        if ($offer->offered_until && $offer->offered_until->isPast()) {
            $offer->update(['status' => 'expired']);

            return null;
        }

        $cooldownDays = (int) config('pricing.golden_box_cooldown_days', 0);

        if ($cooldownDays > 0 && GoldenBoxOffer::query()
            ->where('user_id', $userId)
            ->where('status', 'used')
            ->where('used_at', '>=', now()->subDays($cooldownDays))
            ->exists()) {
            return null;
        }

        $raffle = $this->raffles->find($offer->raffle_id);

        return $raffle && ! $raffle['is_closed'] ? $offer : null;
    }

    private function present(GoldenBoxOffer $offer, string $state): array
    {
        $raffle = $this->raffles->find($offer->raffle_id);
        $unitPrice = (float) ($raffle['price'] ?? 0);
        $before = $this->pricing->calculate($offer->quantity, $unitPrice);
        $after = $this->pricing->calculate($offer->quantity, $unitPrice, true);

        return [
            'id' => $offer->id,
            'state' => $state,
            'raffle_id' => $offer->raffle_id,
            'raffle_title' => $raffle['title'] ?? null,
            'quantity' => $offer->quantity,
            'ticket_numbers' => array_map('intval', (array) $offer->ticket_numbers),
            'percent_off' => (float) config('pricing.golden_box_percent_off'),
            'price_before' => $before,
            'price_after' => $after,
            'savings' => round($before - $after, 2),
            'ends_at' => ($state === 'claimed' ? $offer->claim_expires_at : $offer->offered_until)?->toIso8601String(),
        ];
    }
}
