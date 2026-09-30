<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\Messaging\BroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Works through a message's audience 200 customers at a time. Shared
 * hosting stops each background run after a few minutes, so this works
 * for about a minute, then queues itself again to carry on. One copy at a
 * time (a lock), and the scheduler restarts it if it ever stops
 * (routes/console.php). Every customer reached is written down, so
 * carrying on never sends anyone the message twice (BroadcastService).
 * Emails and phone notifications are queued on their own (retried if a
 * provider hiccups; failures show on System → Health).
 */
class SendBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    private const SECONDS_PER_RUN = 60;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(BroadcastService $messages): void
    {
        $lock = Cache::lock("broadcast-send:{$this->broadcastId}", self::SECONDS_PER_RUN + 120);

        if (! $lock->get()) {
            return; // another copy is already sending this message
        }

        try {
            $started = microtime(true);

            do {
                $broadcast = Broadcast::query()->find($this->broadcastId);

                if (! $broadcast || $broadcast->status !== 'sending') {
                    return; // finished, cancelled or deleted: never send twice
                }

                $more = $messages->sendNextBatch($broadcast);
            } while ($more && microtime(true) - $started < self::SECONDS_PER_RUN);
        } finally {
            $lock->release();
        }

        if (Broadcast::query()->whereKey($this->broadcastId)->value('status') === 'sending') {
            self::dispatch($this->broadcastId);
        }
    }

    public function failed(?Throwable $e): void
    {
        Broadcast::query()->whereKey($this->broadcastId)->where('status', 'sending')
            ->update(['status' => 'failed', 'error' => mb_substr((string) $e?->getMessage(), 0, 480)]);
    }
}
