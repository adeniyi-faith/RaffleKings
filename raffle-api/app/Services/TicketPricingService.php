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
        $multiplier = 1.0;

        if ($unitPrice <= 200) {
            if ($quantity >= 2) {
                $multiplier = 0.90;
            }
        } else {
            $multiplier = match (true) {
                $quantity === 1 => 1.0,
                $quantity === 2 => 0.75,
                $quantity === 3 => 0.65,
                $quantity === 5 => 0.60,
                $quantity === 10 => 0.55,
                $quantity > 10 => 0.50,
                default => 1.0,
            };
        }

        if ($isGoldenBox) {
            $multiplier *= 0.90;
        }

        return round($originalPrice * $multiplier, 2);
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
