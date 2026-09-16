<?php

namespace App\Events;

use App\Models\LiveDrawComment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast the instant a comment is posted (ShouldBroadcastNow — not
 * queued: a chat message that arrives after the queue gets around to it
 * defeats the point). Public channel, see routes/channels.php's
 * docblock for why.
 */
class LiveDrawCommentPosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly LiveDrawComment $comment) {}

    public function broadcastOn(): array
    {
        return [new Channel("live-draw.{$this->comment->raffle_id}")];
    }

    public function broadcastAs(): string
    {
        return 'comment.posted';
    }

    public function broadcastWith(): array
    {
        return $this->comment->toBroadcastArray();
    }
}
