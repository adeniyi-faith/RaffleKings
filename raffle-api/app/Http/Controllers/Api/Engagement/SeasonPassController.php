<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\SeasonPass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Season Pass page (Phase 11): progress, and collecting level rewards. */
class SeasonPassController extends Controller
{
    public function __construct(private readonly SeasonPass $season) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->season->state($request->user()->ID));
    }

    public function claim(Request $request): JsonResponse
    {
        $given = $this->season->claimAll($request->user());

        return response()->json(['given' => $given, 'state' => $this->season->state($request->user()->ID)]);
    }
}
