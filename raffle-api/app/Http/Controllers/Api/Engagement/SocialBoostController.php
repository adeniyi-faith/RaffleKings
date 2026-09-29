<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\SocialBoosts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** "Help me unlock" links and Team Up (Phase 11). */
class SocialBoostController extends Controller
{
    public function __construct(private readonly SocialBoosts $boosts) {}

    public function show(Request $request, int $raffle): JsonResponse
    {
        return response()->json($this->boosts->forRaffle($request->user()->ID, $raffle));
    }

    public function createUnlock(Request $request, int $raffle): JsonResponse
    {
        return $this->attempt(fn () => $this->boosts->presentUnlock($this->boosts->createUnlock($request->user(), $raffle, $request->ip())));
    }

    public function tap(Request $request, string $code): JsonResponse
    {
        return $this->attempt(fn () => $this->boosts->tap($request->user(), $code, $request->ip()));
    }

    public function createTeam(Request $request, int $raffle): JsonResponse
    {
        return $this->attempt(fn () => $this->boosts->presentTeam($this->boosts->createTeam($request->user(), $raffle), $request->user()->ID));
    }

    public function join(Request $request, string $code): JsonResponse
    {
        return $this->attempt(fn () => $this->boosts->presentTeam($this->boosts->join($request->user(), $code), $request->user()->ID));
    }

    private function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
