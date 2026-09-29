<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\ReferralLadder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The referral page (Phase 11): link, friends, commission and the ladder. */
class ReferralLadderController extends Controller
{
    public function __invoke(Request $request, ReferralLadder $ladder): JsonResponse
    {
        return response()->json($ladder->overview($request->user()));
    }
}
