<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One tap of a reaction, plus the fresh aggregate counts for every
 * reaction type on this raffle — so a viewer who reconnects a moment
 * later and re-fetches LiveDrawController::show() sees consistent
 * totals either way, not just a stream of deltas it has to sum itself.
 */
class LiveDrawReactionPosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, int>  $counts */
    public function __construct(
        public readonly int $raffleId,
        public readonly string $reactionType,
        public readonly array $counts,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("live-draw.{$this->raffleId}")];
    }

    public function broadcastAs(): string
    {
        return 'reaction.posted';
    }

    public function broadcastWith(): array
    {
        return [
            'reaction_type' => $this->reactionType,
            'counts' => $this->counts,
        ];
    }
}
