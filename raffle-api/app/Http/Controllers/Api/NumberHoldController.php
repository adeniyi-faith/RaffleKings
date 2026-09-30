<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HoldNumbersRequest;
use App\Services\NumberHoldService;
use App\Services\RaffleReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Holding numbers for a few minutes while a player signs in and pays — see
 * App\Services\NumberHoldService. Public: a guest holds numbers too, using
 * the random token their browser keeps; a signed-in customer is recognised
 * by their login.
 */
class NumberHoldController extends Controller
{
    public function __construct(
        private readonly RaffleReadService $raffles,
        private readonly NumberHoldService $holds,
    ) {}

    public function store(HoldNumbersRequest $request, int $raffle): JsonResponse
    {
        $found = $this->raffles->find($raffle);

        if (! $found) {
            return response()->json(['message' => 'Raffle not found.'], 404);
        }

        if ($found['is_closed']) {
            return response()->json(['message' => 'This raffle is closed.', 'closed_reason' => $found['closed_reason'] ?? null], 409);
        }

        $userId = Auth::guard('wordpress')->id();
        $token = NumberHoldService::cleanToken($request->input('guest_token'));

        if (! $userId && ! $token) {
            return response()->json(['message' => 'Could not save your numbers. Please refresh the page and try again.'], 422);
        }

        $numbers = array_values(array_unique(array_map('intval', $request->input('numbers'))));

        if (array_filter($numbers, fn (int $n) => $n < 1 || $n > $found['max_tickets']) !== []) {
            return response()->json(['message' => 'Ticket numbers must be between 1 and '.$found['max_tickets'].'.'], 422);
        }

        $result = $this->holds->claim($raffle, $numbers, $userId ? (int) $userId : null, $token);

        if ($result['too_many']) {
            return response()->json(['message' => 'You are holding too many numbers at once. Finish or release some first.'], 422);
        }

        if (! $result['ok']) {
            $unavailable = array_values(array_unique([...$result['sold'], ...$result['held']]));
            sort($unavailable);

            return response()->json([
                'message' => count($unavailable) === 1
                    ? "Number {$unavailable[0]} was just taken."
                    : 'Numbers '.implode(', ', $unavailable).' were just taken.',
                'unavailable_numbers' => $unavailable,
                'sold_numbers' => $result['sold'],
                'held_numbers' => $result['held'],
            ], 409);
        }

        return response()->json([
            'numbers' => $numbers,
            'expires_at' => $result['expires_at']->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($result['expires_at'], false)),
        ]);
    }

    /** Let go of numbers this player was holding (they picked different ones). */
    public function destroy(HoldNumbersRequest $request, int $raffle): JsonResponse
    {
        $this->holds->release(
            $raffle,
            array_map('intval', $request->input('numbers')),
            Auth::guard('wordpress')->id() ? (int) Auth::guard('wordpress')->id() : null,
            $request->input('guest_token'),
        );

        return response()->json(['released' => true]);
    }
}
