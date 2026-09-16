<?php

namespace App\Events;

use App\Models\LiveDrawReveal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One step of a live reveal — a single winner "airing". Fired by
 * RunLiveDrawRevealJob, one per winner, on a real server-side timer, so
 * every connected viewer sees the exact same winner appear at the exact
 * same moment (this is the actual fix for the legacy page's fake,
 * per-browser Math.random() flashing loop with no connection to the
 * real result).
 */
class LiveDrawWinnerRevealed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly LiveDrawReveal $reveal, public readonly array $winner) {}

    public function broadcastOn(): array
    {
        return [new Channel("live-draw.{$this->reveal->raffle_id}")];
    }

    public function broadcastAs(): string
    {
        return 'winner.revealed';
    }

    public function broadcastWith(): array
    {
        return [
            'sequence' => $this->reveal->sequence,
            'revealed_at' => $this->reveal->revealed_at->toIso8601String(),
            'winner' => $this->winner,
        ];
    }
}
