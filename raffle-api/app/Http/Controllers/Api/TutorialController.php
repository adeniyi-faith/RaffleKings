<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TutorialReadService;
use Illuminate\Http\JsonResponse;

class TutorialController extends Controller
{
    public function __construct(private readonly TutorialReadService $tutorials) {}

    public function index(): JsonResponse
    {
        return response()->json($this->tutorials->list());
    }

    public function markHelpful(int $tutorial): JsonResponse
    {
        $newCount = $this->tutorials->markHelpful($tutorial);

        if ($newCount === null) {
            return response()->json(['message' => 'Tutorial not found.'], 404);
        }

        return response()->json(['new_count' => $newCount]);
    }
}
