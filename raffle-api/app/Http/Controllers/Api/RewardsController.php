<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AlreadyClaimedTodayException;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\MinimumRedemptionNotMetException;
use App\Exceptions\TaskAlreadyCompletedException;
use App\Exceptions\UnknownTaskException;
use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Services\DailyClaimService;
use App\Services\PointRedemptionService;
use App\Services\PointsService;
use App\Services\SpinService;
use App\Services\TaskClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardsController extends Controller
{
    public function __construct(
        private readonly PointsService $points,
        private readonly DailyClaimService $dailyClaim,
        private readonly TaskClaimService $taskClaim,
        private readonly SpinService $spin,
        private readonly PointRedemptionService $redemption,
    ) {}

    public function state(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json(['points' => $this->points->balance($user)]);
    }

    /** Public — the odds are meant to be shown to players, not hidden. */
    public function spinOdds(): JsonResponse
    {
        return response()->json(['cost' => 50, 'odds' => $this->spin->odds()]);
    }

    public function claimDaily(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $result = $this->dailyClaim->claim($user);
        } catch (AlreadyClaimedTodayException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    public function claimTask(Request $request, string $task): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $result = $this->taskClaim->claim($user, $task);
        } catch (UnknownTaskException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TaskAlreadyCompletedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    public function spin(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $result = $this->spin->spin($user);
        } catch (InsufficientPointsException $e) {
            return response()->json(['message' => $e->getMessage(), 'shortfall' => $e->shortfall], 402);
        }

        return response()->json($result);
    }

    public function redeem(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $result = $this->redemption->redeem($user);
        } catch (MinimumRedemptionNotMetException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
