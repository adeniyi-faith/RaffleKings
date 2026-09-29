<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 11: a red envelope was dropped in a live-draw chat, or someone
 * grabbed a share of it. Same public live-draw channel as the chat.
 */
class RedEnvelopeUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $raffleId, public readonly string $kind, public readonly array $payload) {}

    public function broadcastOn(): array
    {
        return [new Channel("live-draw.{$this->raffleId}")];
    }

    public function broadcastAs(): string
    {
        return "envelope.{$this->kind}"; // envelope.dropped / envelope.claimed
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
