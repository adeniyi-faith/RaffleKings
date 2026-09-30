<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoldenBoxService;
use App\Services\Growth\PromoCodeService;
use App\Services\RaffleReadService;
use App\Services\TicketPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Public price-quote endpoint — the fix for audit TD-20. The frontend
 * component library's price-display components call this instead of
 * recalculating TicketPricingService's formula by hand in JavaScript,
 * the way checkout.php/raffle-details.php/register-special.php each did
 * independently (and inconsistently) in the legacy app.
 *
 * Golden Box eligibility isn't accepted from the request: it is decided
 * here from the signed-in customer's own claimed offer (GoldenBoxService),
 * exactly as the purchase itself decides it, so the quote and the charge
 * always agree.
 */
class TicketPriceQuoteController extends Controller
{
    public function __construct(
        private readonly RaffleReadService $raffles,
        private readonly TicketPricingService $pricing,
        private readonly GoldenBoxService $goldenBox,
    ) {}

    public function show(Request $request, int $raffle): JsonResponse
    {
        $found = $this->raffles->find($raffle);

        if (! $found) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);

        $quantity = (int) $data['quantity'];
        $unitPrice = (float) $found['price'];
        $original = $quantity * $unitPrice;
        $bulkPrice = $this->pricing->calculate($quantity, $unitPrice);

        $user = Auth::guard('wordpress')->user();
        $golden = $user ? $this->goldenBox->discountFor($user->getKey(), $raffle, $quantity) : null;
        $discounted = $golden ? $this->pricing->calculate($quantity, $unitPrice, true) : $bulkPrice;
        $goldenPrice = $discounted;

        // A promo code typed at checkout: the same quote() the purchase uses.
        $promo = null;
        $promoError = null;

        if ($user && PromoCodeService::normalise($data['promo_code'] ?? null) !== '') {
            try {
                $promoQuote = app(PromoCodeService::class)->quote($user, $data['promo_code'], $discounted);
                $promo = ['code' => $promoQuote['promo']->code, 'summary' => $promoQuote['promo']->summary(), 'savings' => $promoQuote['discount'], 'price_before' => $discounted];
                $discounted = round($discounted - $promoQuote['discount'], 2);
            } catch (InvalidArgumentException $e) {
                $promoError = $e->getMessage();
            }
        }

        return response()->json([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'original' => $original,
            'discounted' => $discounted,
            'savings' => round($original - $discounted, 2),
            'golden_box' => $golden ? [
                'percent_off' => (float) config('pricing.golden_box_percent_off'),
                'savings' => round($bulkPrice - $goldenPrice, 2),
                'price_before' => $bulkPrice,
                'ends_at' => $golden->claim_expires_at?->toIso8601String(),
            ] : null,
            'promo' => $promo,
            'promo_error' => $promoError,
        ]);
    }
}
