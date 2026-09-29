<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use App\Services\Engagement\Predictions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Daily predictions (Phase 11): today's questions, and answering one. */
class PredictionController extends Controller
{
    public function __construct(private readonly Predictions $predictions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->predictions->board($request->user()?->ID));
    }

    public function answer(Request $request, Prediction $prediction): JsonResponse
    {
        $data = $request->validate(['option' => ['required', 'integer', 'min:0', 'max:5']]);

        try {
            return response()->json($this->predictions->answer($request->user(), $prediction, (int) $data['option']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
