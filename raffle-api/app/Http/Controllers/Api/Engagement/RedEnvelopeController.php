<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Exceptions\InsufficientPointsException;
use App\Http\Controllers\Controller;
use App\Models\Raffle;
use App\Models\RedEnvelope;
use App\Services\Engagement\RedEnvelopes;
use App\Services\PointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Red envelopes in a live-draw chat (Phase 11): send one, or grab a share. */
class RedEnvelopeController extends Controller
{
    public function __construct(private readonly RedEnvelopes $envelopes) {}

    public function send(Request $request, Raffle $raffle): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1'],
            'slots' => ['required', 'integer', 'min:1', 'max:50'],
            'message' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $envelope = $this->envelopes->send($request->user(), $raffle, (int) $data['points'], (int) $data['slots'], $data['message'] ?? null);
        } catch (InsufficientPointsException $e) {
            return response()->json(['message' => 'You don\'t have enough points for that.'], 402);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $envelope->id,
            'points_left' => app(PointsService::class)->balance($request->user()),
        ], 201);
    }

    public function claim(Request $request, RedEnvelope $envelope): JsonResponse
    {
        try {
            $points = $this->envelopes->claim($request->user(), $envelope);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['points' => $points, 'points_total' => app(PointsService::class)->balance($request->user())]);
    }
}
