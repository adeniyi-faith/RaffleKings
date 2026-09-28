<?php

namespace App\Events;

use App\Models\LiveDrawComment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * OVERHAUL_CHECKLIST.md item 45 — tells every viewer of a live draw to
 * remove a message an admin just hid. Sent immediately (not queued), like
 * the message itself was.
 */
class LiveDrawCommentHidden implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly LiveDrawComment $comment) {}

    public function broadcastOn(): array
    {
        return [new Channel("live-draw.{$this->comment->raffle_id}")];
    }

    public function broadcastAs(): string
    {
        return 'comment.hidden';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->comment->id];
    }
}
