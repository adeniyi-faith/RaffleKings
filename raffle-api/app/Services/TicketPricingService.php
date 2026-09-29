<?php

namespace App\Services;

/**
 * The ONE place ticket pricing is calculated. Ported from
 * wp/wp-content/mu-plugins/rk-core/api-financials.php's
 * rk_calculate_ticket_price() — same tiers, same Golden Box multiplier —
 * so behaviour doesn't change for users during the migration.
 *
 * Audit TD-20: today this formula is duplicated by hand in checkout.php's
 * JavaScript. Once the frontend is rebuilt, it should call an endpoint
 * backed by this service for a price quote instead of recalculating it
 * client-side — that's the only way to guarantee display price and
 * charged price can never drift apart again.
 */
class TicketPricingService
{
    /**
     * @param  int  $quantity  Number of tickets being purchased.
     * @param  float  $unitPrice  Single-ticket price for this raffle.
     * @param  bool  $isGoldenBox  Whether the extra 10% Golden Box discount applies.
     * @return float The total price to charge, in naira.
     */
    public function calculate(int $quantity, float $unitPrice, bool $isGoldenBox = false): float
    {
        $originalPrice = $quantity * $unitPrice;
        $multiplier = 1 - $this->percentOff($quantity, $unitPrice) / 100;

        if ($isGoldenBox) {
            $multiplier *= 1 - (float) config('pricing.golden_box_percent_off', 0) / 100;
        }

        return round($originalPrice * $multiplier, 2);
    }

    /**
     * The bulk discount for this quantity at this ticket price — the
     * rules and numbers are in config/pricing.php, editable in the admin
     * (Settings → Raffles & pricing).
     */
    public function percentOff(int $quantity, float $unitPrice): float
    {
        $pricing = config('pricing');

        if ($unitPrice <= (float) $pricing['cheap_ticket_max_price']) {
            return $quantity >= (int) $pricing['cheap_bulk_min_quantity'] ? (float) $pricing['cheap_bulk_percent_off'] : 0.0;
        }

        foreach ($pricing['bundles'] ?? [] as $bundle) {
            if ((int) $bundle['quantity'] === $quantity) {
                return (float) $bundle['percent_off'];
            }
        }

        $above = (int) ($pricing['above_quantity'] ?? 0);

        return $above > 0 && $quantity > $above ? (float) $pricing['above_percent_off'] : 0.0;
    }

    /**
     * The ticket counts the raffle page offers as one-tap buttons: a
     * single ticket plus every bundle size.
     *
     * @return list<int>
     */
    public function bundleQuantities(): array
    {
        return collect(config('pricing.bundles', []))
            ->pluck('quantity')
            ->map(fn ($q) => (int) $q)
            ->prepend(1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * True if $submittedAmount matches what this quantity/price/Golden Box
     * combination should actually cost, within a kobo of rounding error.
     * Mirrors the server-side check already present in rk_handle_payment_ai()
     * — kept here so the new settlement path enforces the same guarantee.
     */
    public function matchesExpectedPrice(float $submittedAmount, int $quantity, float $unitPrice, bool $isGoldenBox = false): bool
    {
        return abs($submittedAmount - $this->calculate($quantity, $unitPrice, $isGoldenBox)) <= 0.01;
    }
}
