<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\BadgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase 11 badges: the customer's collection, and pinning badges to their profile. */
class BadgeController extends Controller
{
    public function __construct(private readonly BadgeService $badges) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->ID;

        return response()->json([
            'badges' => $this->badges->forUser($userId),
            'showcase_size' => $this->badges->showcaseSize(),
            'showcase' => $this->badges->showcase($userId),
        ]);
    }

    public function showcase(Request $request): JsonResponse
    {
        $data = $request->validate(['badges' => ['present', 'array', 'max:10'], 'badges.*' => ['string', 'max:40']]);

        return response()->json(['showcase' => $this->badges->setShowcase($request->user()->ID, $data['badges'])]);
    }
}
