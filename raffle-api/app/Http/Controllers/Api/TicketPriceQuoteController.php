<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RaffleReadService;
use App\Services\TicketPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public price-quote endpoint — the fix for audit TD-20. The frontend
 * component library's price-display components call this instead of
 * recalculating TicketPricingService's formula by hand in JavaScript,
 * the way checkout.php/raffle-details.php/register-special.php each did
 * independently (and inconsistently) in the legacy app.
 *
 * Golden Box eligibility isn't accepted from the request: that value must
 * always be decided server-side once the promotions feature it belongs to
 * exists, never trusted from client input.
 */
class TicketPriceQuoteController extends Controller
{
    public function __construct(
        private readonly RaffleReadService $raffles,
        private readonly TicketPricingService $pricing,
    ) {}

    public function show(Request $request, int $raffle): JsonResponse
    {
        $found = $this->raffles->find($raffle);

        if (! $found) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $quantity = (int) $data['quantity'];
        $unitPrice = (float) $found['price'];
        $original = $quantity * $unitPrice;
        $discounted = $this->pricing->calculate($quantity, $unitPrice);

        return response()->json([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'original' => $original,
            'discounted' => $discounted,
            'savings' => round($original - $discounted, 2),
        ]);
    }
}
