<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\Messaging\Audience;
use App\Services\Messaging\BroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Works through a message's audience 500 customers at a time. Emails and
 * phone notifications are each queued on their own (retried if a provider
 * hiccups; failures show on System → Health).
 */
class SendBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(Audience $audience, BroadcastService $messages): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);

        if (! $broadcast || $broadcast->status !== 'sending') {
            return; // already sent (or deleted) — never send twice
        }

        $sent = 0;

        $audience->query($broadcast->audience, $broadcast->audience_options ?? [])
            ->chunkById(500, function ($users) use ($broadcast, $messages, &$sent) {
                $messages->deliver($broadcast, $users);
                $sent += $users->count();
            }, 'ID');

        $broadcast->update(['status' => 'sent', 'recipients_count' => $sent, 'sent_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        Broadcast::query()->whereKey($this->broadcastId)->update(['status' => 'failed']);
    }
}
