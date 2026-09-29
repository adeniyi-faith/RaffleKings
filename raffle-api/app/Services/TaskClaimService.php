<?php

namespace App\Services;

use App\Exceptions\TaskAlreadyCompletedException;
use App\Exceptions\TaskNotReadyException;
use App\Exceptions\UnknownTaskException;
use App\Models\CompletedTask;
use App\Models\Legacy\WpUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One-off (and one repeatable) engagement task rewards — same task list
 * and reward amounts as the legacy rk_handle_task_claim()
 * (wp-core/api-gamification.php). Every task except "whatsapp_share" can
 * only ever be claimed once per user; "whatsapp_share" can be claimed
 * once per calendar day.
 *
 * Link tasks are "Go" then "Claim" (item 47): the old "Claim" button
 * gave the points for nothing. start() records when the customer opened
 * the link; claim() refuses until rewards.task_wait_seconds have passed.
 * WhatsApp can't tell anyone whether a person really followed or shared,
 * so this is the honest check available. A link task whose link isn't set
 * in Settings is hidden and can't be claimed, since there's nothing to do.
 * "Turn on notifications" isn't a link task: the page only claims it once
 * the browser reports permission was really granted.
 */
class TaskClaimService
{
    private const REPEATABLE_DAILY_TASKS = ['whatsapp_share'];

    /** Link tasks, and the setting their link comes from (null = no setting needed). */
    private const LINK_TASKS = [
        'join_community' => 'site.links.community',
        'whatsapp_follow' => 'site.links.whatsapp_channel',
        'whatsapp_share' => null,
    ];

    /** A "Go" older than this no longer counts; tap Go again. */
    private const START_VALID_MINUTES = 60;

    public function __construct(private readonly PointsService $points) {}

    /**
     * Points per task (config/rewards.php, editable in Settings → Rewards).
     *
     * @return array<string, int>
     */
    private static function rewards(): array
    {
        $boost = app(PointsBoost::class);

        // A running points boost (Settings → Rewards) multiplies every task.
        return array_map(fn ($p) => $boost->apply((int) $p), config('rewards.tasks'));
    }

    /** Tasks a customer can actually do right now (a link task needs its link set). */
    private static function availableRewards(): array
    {
        return array_filter(
            self::rewards(),
            fn ($points, $taskId) => ! isset(self::LINK_TASKS[$taskId]) || self::LINK_TASKS[$taskId] === null || filled(config(self::LINK_TASKS[$taskId])),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * What a logged-out visitor sees (item 47's guest preview): the real
     * tasks and points, nothing about any account.
     *
     * @return list<array{task_id: string, points: int, completed: bool, repeatable: bool, needs_visit: bool, claimable_at: null}>
     */
    public function publicCatalog(): array
    {
        return collect(self::availableRewards())->map(fn ($points, $taskId) => [
            'task_id' => $taskId,
            'points' => $points,
            'completed' => false,
            'repeatable' => in_array($taskId, self::REPEATABLE_DAILY_TASKS, true),
            'needs_visit' => array_key_exists($taskId, self::LINK_TASKS),
            'claimable_at' => null,
        ])->values()->all();
    }

    /**
     * The customer tapped "Go": remember when, so Claim can unlock after
     * the wait. Tapping Go again restarts the wait.
     *
     * @return array{task_id: string, claimable_at: string}
     *
     * @throws UnknownTaskException for an unknown, hidden or non-link task
     * @throws TaskAlreadyCompletedException
     */
    public function start(WpUser $user, string $taskId): array
    {
        if (! array_key_exists($taskId, self::LINK_TASKS) || ! array_key_exists($taskId, self::availableRewards())) {
            throw new UnknownTaskException($taskId);
        }

        if ($this->alreadyDone($user, $taskId)) {
            throw new TaskAlreadyCompletedException($taskId);
        }

        $now = now();
        Cache::put($this->startKey($user, $taskId), $now->getTimestamp(), now()->addMinutes(self::START_VALID_MINUTES));

        return ['task_id' => $taskId, 'claimable_at' => $now->copy()->addSeconds(self::waitSeconds())->toIso8601String()];
    }

    /**
     * The task list with each one's reward and whether this user has
     * already claimed it — what the Rewards hub (item 28) renders as
     * "Quick Tasks", instead of the page guessing reward amounts or
     * completion state on its own.
     *
     * `needs_visit` marks a Go-then-Claim task; `claimable_at` is when
     * Claim unlocks after a Go (null if Go wasn't tapped), so a reload
     * keeps the countdown.
     *
     * @return list<array{task_id: string, points: int, completed: bool, repeatable: bool, needs_visit: bool, claimable_at: ?string}>
     */
    public function catalog(WpUser $user): array
    {
        $doneIds = CompletedTask::query()->where('user_id', $user->ID)
            ->whereNotIn('task_id', self::REPEATABLE_DAILY_TASKS)
            ->pluck('task_id');

        $doneToday = CompletedTask::query()->where('user_id', $user->ID)
            ->whereIn('task_id', self::REPEATABLE_DAILY_TASKS)
            ->whereBetween('completed_at', self::today())
            ->pluck('task_id');

        return collect(self::availableRewards())->map(function ($points, $taskId) use ($doneIds, $doneToday, $user) {
            $repeatable = in_array($taskId, self::REPEATABLE_DAILY_TASKS, true);
            $completed = $repeatable ? $doneToday->contains($taskId) : $doneIds->contains($taskId);
            $started = ! $completed ? $this->startedAt($user, $taskId) : null;

            return [
                'task_id' => $taskId,
                'points' => $points,
                'completed' => $completed,
                'repeatable' => $repeatable,
                'needs_visit' => array_key_exists($taskId, self::LINK_TASKS),
                'claimable_at' => $started?->copy()->addSeconds(self::waitSeconds())->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @return array{task_id: string, points_added: int, new_total_points: int}
     *
     * @throws UnknownTaskException
     * @throws TaskAlreadyCompletedException
     * @throws TaskNotReadyException for a link task claimed without Go, or too soon after it
     */
    public function claim(WpUser $user, string $taskId): array
    {
        if (! array_key_exists($taskId, self::availableRewards())) {
            throw new UnknownTaskException($taskId);
        }

        return DB::transaction(function () use ($user, $taskId) {
            if ($this->alreadyDone($user, $taskId)) {
                throw new TaskAlreadyCompletedException($taskId);
            }

            if (array_key_exists($taskId, self::LINK_TASKS)) {
                $started = $this->startedAt($user, $taskId);

                if (! $started) {
                    throw new TaskNotReadyException(null);
                }

                $secondsLeft = self::waitSeconds() - (now()->getTimestamp() - $started->getTimestamp());

                if ($secondsLeft > 0) {
                    throw new TaskNotReadyException($secondsLeft);
                }

                Cache::forget($this->startKey($user, $taskId));
            }

            CompletedTask::create(['user_id' => $user->ID, 'task_id' => $taskId, 'completed_at' => now()]);

            $reward = self::rewards()[$taskId];
            $newBalance = $this->points->credit($user, $reward, 'task_claim', description: "Completed task: {$taskId}");

            return ['task_id' => $taskId, 'points_added' => $reward, 'new_total_points' => $newBalance];
        });
    }

    private function alreadyDone(WpUser $user, string $taskId): bool
    {
        return in_array($taskId, self::REPEATABLE_DAILY_TASKS, true)
            ? CompletedTask::query()->where('user_id', $user->ID)->where('task_id', $taskId)->whereBetween('completed_at', self::today())->exists()
            : CompletedTask::query()->where('user_id', $user->ID)->where('task_id', $taskId)->exists();
    }

    private function startedAt(WpUser $user, string $taskId): ?Carbon
    {
        $timestamp = Cache::get($this->startKey($user, $taskId));

        return $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    private function startKey(WpUser $user, string $taskId): string
    {
        return "reward-task-started:{$user->ID}:{$taskId}";
    }

    private static function waitSeconds(): int
    {
        return max(0, (int) config('rewards.task_wait_seconds', 10));
    }

    /**
     * Today in the business time zone (Lagos by default), as the UTC
     * start and end the database compares with, so the daily share
     * resets at the same midnight as the daily reward.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function today(): array
    {
        $now = DailyClaimService::now();

        return [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()];
    }
}
