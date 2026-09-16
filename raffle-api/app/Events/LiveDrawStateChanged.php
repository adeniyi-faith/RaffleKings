<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The reveal's own lifecycle (idle -> revealing -> completed), so a
 * page that's already open shows the "starting…" / "all winners
 * revealed" chrome in sync too, not just the winner cards themselves.
 */
class LiveDrawStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $raffleId, public readonly string $status) {}

    public function broadcastOn(): array
    {
        return [new Channel("live-draw.{$this->raffleId}")];
    }

    public function broadcastAs(): string
    {
        return 'state.changed';
    }

    public function broadcastWith(): array
    {
        return ['status' => $this->status];
    }
}
