<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ResponsiblePlayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Responsible play (Phase 10, item 38): the customer's own limits and breaks. */
class ResponsiblePlayController extends Controller
{
    public function __construct(private readonly ResponsiblePlayService $play) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->play->state((int) $request->user()->getAuthIdentifier()));
    }

    public function updateLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'daily' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'weekly' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'monthly' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);
        $userId = (int) $request->user()->getAuthIdentifier();

        try {
            $result = $this->play->setLimits($userId, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = match (true) {
            $result['pending'] && $result['applied'] => 'Lower limits are on now. Higher or removed limits start in 24 hours.',
            (bool) $result['pending'] => 'Saved. Higher or removed limits start in 24 hours, to give you time to think it over.',
            (bool) $result['applied'] => 'Saved. Your new limits are on now.',
            default => 'Nothing changed.',
        };

        return response()->json(['message' => $message, 'state' => $this->play->state($userId)]);
    }

    public function takeBreak(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $userId = (int) $request->user()->getAuthIdentifier();

        try {
            $until = $this->play->takeBreak($userId, (int) $data['days']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $this->play->breakMessage($until),
            'state' => $this->play->state($userId),
        ]);
    }
}
