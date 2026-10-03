<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AlreadyClaimedTodayException;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\MinimumRedemptionNotMetException;
use App\Exceptions\TaskAlreadyCompletedException;
use App\Exceptions\TaskNotReadyException;
use App\Exceptions\UnknownTaskException;
use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Services\DailyClaimService;
use App\Services\Engagement\FreeSpinGifts;
use App\Services\Engagement\LuckyMeter;
use App\Services\Engagement\Perks;
use App\Services\Engagement\SeasonPass;
use App\Services\LoyaltyService;
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

    /**
     * Everything the Rewards hub (item 28) needs for one page load: points
     * balance, daily-streak position, the task catalog with per-task
     * completion, and the public spin odds/cost — so the page can render
     * daily streak, tasks, and Spin & Win as real, working features
     * instead of the static "Coming Soon" markup they used to be stuck
     * behind despite the backend for all three already existing (item 16).
     */
    public function state(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json($this->stateFor($user));
    }

    /**
     * The state above as an array, also sent with the /rewards page itself
     * so the page draws complete on the first paint instead of jumping as
     * the day boxes and loyalty card arrive a moment later.
     */
    public function stateFor(WpUser $user): array
    {
        // Phase 11: birthday / anniversary free spins arrive when Rewards opens.
        app(FreeSpinGifts::class)->checkOccasions($user);

        return [
            'points' => $this->points->balance($user),
            ...$this->dailyClaim->state($user),
            'daily_schedule' => $this->dailyClaim->schedule(),
            // When "today" ends for the daily claim (midnight in the business
            // time zone), for the page's "next reward in" countdown (item 47).
            'next_reset_at' => DailyClaimService::nextReset()->toIso8601String(),
            'tasks' => $this->taskClaim->catalog($user),
            'spin' => ['cost' => SpinService::cost(), 'odds' => $this->spin->odds()],
            // Raffle Rules Engine: loyalty tier and progress to the next one.
            'loyalty' => app(LoyaltyService::class)->profile($user->ID),
            // Phase 11: Season Pass level, free spins waiting.
            'season' => collect(app(SeasonPass::class)->state($user->ID))->only(['level', 'claimable', 'season'])->all(),
            'free_spins' => app(Perks::class)->freeSpins($user->ID),
            // Lucky Meter (null while it's switched off).
            'lucky_meter' => app(LuckyMeter::class)->state($user->ID),
        ];
    }

    /** Public — the odds are meant to be shown to players, not hidden. */
    public function spinOdds(): JsonResponse
    {
        return response()->json(['cost' => SpinService::cost(), 'odds' => $this->spin->odds()]);
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

    /** "Go" on a link task: starts the short wait before Claim works (item 47). */
    public function startTask(Request $request, string $task): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            return response()->json($this->taskClaim->start($user, $task));
        } catch (UnknownTaskException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TaskAlreadyCompletedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
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
        } catch (TaskNotReadyException $e) {
            return response()->json(['message' => $e->getMessage(), 'seconds_left' => $e->secondsLeft], 425);
        }

        return response()->json($result);
    }

    public function spin(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $result = $this->spin->spin($user, $request->boolean('free'));
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
