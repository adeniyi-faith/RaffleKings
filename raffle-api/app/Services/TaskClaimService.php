<?php

namespace App\Services;

use App\Exceptions\TaskAlreadyCompletedException;
use App\Exceptions\UnknownTaskException;
use App\Models\CompletedTask;
use App\Models\Legacy\WpUser;
use Illuminate\Support\Facades\DB;

/**
 * One-off (and one repeatable) engagement task rewards — same task list
 * and reward amounts as the legacy rk_handle_task_claim()
 * (wp-core/api-gamification.php). Every task except "whatsapp_share" can
 * only ever be claimed once per user; "whatsapp_share" can be claimed
 * once per calendar day.
 */
class TaskClaimService
{
    private const REWARDS = [
        'push_notification' => 1500,
        'join_community' => 1300,
        'whatsapp_follow' => 800,
        'whatsapp_share' => 500,
    ];

    private const REPEATABLE_DAILY_TASKS = ['whatsapp_share'];

    public function __construct(private readonly PointsService $points) {}

    /**
     * @return array{task_id: string, points_added: int, new_total_points: int}
     *
     * @throws UnknownTaskException
     * @throws TaskAlreadyCompletedException
     */
    public function claim(WpUser $user, string $taskId): array
    {
        if (! array_key_exists($taskId, self::REWARDS)) {
            throw new UnknownTaskException($taskId);
        }

        return DB::transaction(function () use ($user, $taskId) {
            $alreadyDone = in_array($taskId, self::REPEATABLE_DAILY_TASKS, true)
                ? CompletedTask::query()->where('user_id', $user->ID)->where('task_id', $taskId)->whereDate('completed_at', now())->exists()
                : CompletedTask::query()->where('user_id', $user->ID)->where('task_id', $taskId)->exists();

            if ($alreadyDone) {
                throw new TaskAlreadyCompletedException($taskId);
            }

            CompletedTask::create(['user_id' => $user->ID, 'task_id' => $taskId, 'completed_at' => now()]);

            $reward = self::REWARDS[$taskId];
            $newBalance = $this->points->credit($user, $reward, 'task_claim', description: "Completed task: {$taskId}");

            return ['task_id' => $taskId, 'points_added' => $reward, 'new_total_points' => $newBalance];
        });
    }
}
