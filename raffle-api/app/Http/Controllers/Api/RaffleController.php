<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RaffleReadService;
use Illuminate\Http\JsonResponse;

/**
 * Public raffle-listing/detail endpoints — no auth required, same as the
 * legacy `get_raffles`/`get_raffle` actions in ajax-router.php.
 */
class RaffleController extends Controller
{
    public function __construct(private readonly RaffleReadService $raffles) {}

    public function index(): JsonResponse
    {
        return response()->json(['raffles' => $this->raffles->listActive()]);
    }

    public function show(int $raffle): JsonResponse
    {
        $found = $this->raffles->find($raffle);

        if (! $found) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        return response()->json($found);
    }
}
