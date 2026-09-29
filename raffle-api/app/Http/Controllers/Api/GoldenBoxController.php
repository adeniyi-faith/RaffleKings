<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Services\GoldenBoxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The raffle list's gold "you left something behind" banner (item 46) — see GoldenBoxService. */
class GoldenBoxController extends Controller
{
    public function __construct(private readonly GoldenBoxService $goldenBox) {}

    public function show(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json(['offer' => $this->goldenBox->offerFor($user)]);
    }

    public function claim(Request $request, int $offer): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            return response()->json(['offer' => $this->goldenBox->claim($user, $offer)]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 410);
        }
    }
}
