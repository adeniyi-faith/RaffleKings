<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleEntry;
use App\Services\RaffleReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public raffle-listing/detail endpoints — no auth required, same as the
 * legacy `get_raffles`/`get_raffle` actions in ajax-router.php.
 */
class RaffleController extends Controller
{
    public function __construct(private readonly RaffleReadService $raffles) {}

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
    public function tickets(int $raffle): JsonResponse
    {
        $found = $this->raffles->find($raffle);

        if (! $found) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        $taken = RaffleEntry::where('raffle_id', $raffle)->pluck('ticket_number')->map(fn ($n) => (int) $n)->values();

        return response()->json([
            'max_tickets' => $found['max_tickets'],
            'taken_numbers' => $taken,
        ]);
    }
}
