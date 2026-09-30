<?php

namespace App\Jobs;

use App\Models\Raffle;
use App\Services\RaffleCancellationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Refunds a cancelled raffle's customers in the background, a batch at a
 * time. Shared hosting stops each background run after a few minutes, so
 * this works for about a minute, then queues itself again to carry on.
 * One copy at a time (a lock); the scheduler restarts it if it ever stops
 * (routes/console.php). Refunds can't repeat: see RaffleCancellationService.
 */
class RefundCancelledRaffle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    private const SECONDS_PER_RUN = 60;

    public function __construct(public readonly int $raffleId) {}

    public function handle(RaffleCancellationService $cancellations): void
    {
        $lock = Cache::lock("raffle-refunds:{$this->raffleId}", self::SECONDS_PER_RUN + 60);

        if (! $lock->get()) {
            return; // another copy is already refunding this raffle
        }

        try {
            $started = microtime(true);

            do {
                $raffle = Raffle::query()->find($this->raffleId);

                if (! $raffle || $raffle->refund_status !== 'refunding') {
                    return;
                }

                $done = $cancellations->refundBatch($raffle, 50);
            } while ($done > 0 && microtime(true) - $started < self::SECONDS_PER_RUN);
        } finally {
            $lock->release();
        }

        if (Raffle::query()->whereKey($this->raffleId)->value('refund_status') === 'refunding') {
            self::dispatch($this->raffleId);
        }
    }
}
