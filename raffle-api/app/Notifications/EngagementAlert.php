<?php

namespace App\Notifications;

use App\Notifications\Channels\InboxChannel;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A short "you got something" alert for the Phase 11 community features
 * (a badge, a Season Pass level, a free spin, a filled team…): the bell
 * inbox straight away, plus a push notification.
 */
class EngagementAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $linkUrl = null,
        public readonly ?string $linkLabel = null,
    ) {}

    public function via(mixed $notifiable): array
    {
        return [OneSignalChannel::class, InboxChannel::class];
    }

    public function viaConnections(): array
    {
        return [InboxChannel::class => 'sync'];
    }

    public function toOneSignal(mixed $notifiable): array
    {
        return ['headings' => ['en' => $this->title], 'contents' => ['en' => $this->body]];
    }

    public function toInbox(mixed $notifiable): array
    {
        return [
            'kind' => 'reward',
            'title' => $this->title,
            'body' => $this->body,
            'link_url' => $this->linkUrl,
            'link_label' => $this->linkLabel,
        ];
    }
}
