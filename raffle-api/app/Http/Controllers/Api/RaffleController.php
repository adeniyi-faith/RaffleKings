<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use App\Services\NumberHoldService;
use App\Services\OddsCalculator;
use App\Services\RaffleReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Public raffle-listing/detail endpoints — no auth required, same as the
 * legacy `get_raffles`/`get_raffle` actions in ajax-router.php.
 */
class RaffleController extends Controller
{
    public function __construct(
        private readonly RaffleReadService $raffles,
        private readonly NumberHoldService $holds,
    ) {}

    /**
     * Item 24: search/prize_type/price filters and sort, replacing the
     * legacy raffles.php's client-side-only filtering over an unfiltered
     * fetch (and its two dead "Cash"/"Gadgets" buttons — see
     * RaffleReadService's docblock).
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->raffles->listActive($request->only([
            'search', 'prize_type', 'min_price', 'max_price', 'sort', 'page', 'per_page',
        ])));
    }

    public function show(int $raffle): JsonResponse
    {
        $found = $this->raffles->find($raffle);

        if (! $found) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        return response()->json($found);
    }

    /**
     * The taken ticket numbers for the number-selection grid (item 25) —
     * public, since knowing which numbers are gone doesn't require being
     * logged in, the same way seeing the raffle itself doesn't.
     */
    public function tickets(Request $request, int $raffle): JsonResponse
    {
        $found = $this->raffles->find($raffle);

        if (! $found) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        $taken = RaffleEntry::where('raffle_id', $raffle)->pluck('ticket_number')->map(fn ($n) => (int) $n)->values();

        return response()->json([
            'max_tickets' => $found['max_tickets'],
            'taken_numbers' => $taken,
            // Not sold, but another player is holding them for a few minutes.
            // The caller's own held numbers are left out (they can still use them).
            'held_numbers' => $this->holds->heldByOthers(
                $raffle,
                Auth::guard('wordpress')->id() ? (int) Auth::guard('wordpress')->id() : null,
                $request->header('X-Guest-Token'),
            ),
        ]);
    }

    /**
     * The chance of winning each prize level, for a number of tickets. Public,
     * like the raffle itself. The odds assume every ticket sells; fewer sold
     * only improves them (see App\Services\OddsCalculator).
     */
    public function odds(Request $request, OddsCalculator $odds, int $raffle): JsonResponse
    {
        $model = Raffle::query()->with('prizeTiers')->where('public_id', $raffle)->where('status', '!=', 'draft')->first();

        if (! $model) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        $quantity = max(1, min(10000, (int) $request->query('quantity', 1)));

        return response()->json(['raffle_id' => $raffle, ...$odds->forRaffle($model, $quantity)]);
    }
}
