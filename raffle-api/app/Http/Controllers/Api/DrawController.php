<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DrawAlreadyRunException;
use App\Exceptions\DrawNotCommittedException;
use App\Exceptions\NoEligibleEntriesException;
use App\Exceptions\NoPrizeStructureException;
use App\Http\Controllers\Controller;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Services\ProvablyFairDrawService;
use Illuminate\Http\JsonResponse;

class DrawController extends Controller
{
    public function __construct(private readonly ProvablyFairDrawService $draws) {}

    /**
     * Public — anyone can see a raffle's committed seed hash before the
     * draw, and its full verification result after. This is the "expose
     * an endpoint where anyone can independently recompute the result"
     * requirement — real proof, unlike the legacy "verification hash."
     */
    public function show(Raffle $raffle): JsonResponse
    {
        $commitment = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        if (! $commitment) {
            return response()->json(['message' => 'No draw has been committed for this raffle yet.'], 404);
        }

        return response()->json(array_merge(
            $commitment->publicCommitment(),
            ['verification' => $this->draws->verify($raffle)],
        ));
    }

    /**
     * Admin-only. Fixes a random seed for this raffle before entries
     * close — must happen before run(), and can safely be called more
     * than once (it never regenerates an existing commitment).
     */
    public function commit(Raffle $raffle): JsonResponse
    {
        $draw = $this->draws->commitSeed($raffle);

        return response()->json($draw->publicCommitment(), 201);
    }

    /** Admin-only. Runs the draw itself — see ProvablyFairDrawService::runDraw(). */
    public function run(Raffle $raffle): JsonResponse
    {
        try {
            $winners = $this->draws->runDraw($raffle);
        } catch (DrawNotCommittedException|NoPrizeStructureException|NoEligibleEntriesException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DrawAlreadyRunException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'winner_count' => count($winners),
            'message' => 'Winners generated (hidden by default — visibility and payout still require a separate admin action).',
        ], 201);
    }
}
