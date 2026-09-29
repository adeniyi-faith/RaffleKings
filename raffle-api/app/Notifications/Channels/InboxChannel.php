<?php

namespace App\Notifications\Channels;

use App\Models\CustomerMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Puts a notification in the customer's on-site inbox (the bell), next
 * to the email/push. A notification using it has a toInbox() returning
 * title, body, kind (see CustomerMessage::KINDS) and optionally link_url /
 * link_label.
 *
 * Notifications run it on the "sync" queue connection (viaConnections),
 * so the alert shows at once instead of waiting for the next background
 * run. A failure is logged, never thrown: a missed bell alert must not
 * stop a payout or a win from being recorded.
 */
class InboxChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        try {
            $data = $notification->toInbox($notifiable);

            CustomerMessage::create([
                'user_id' => $notifiable->getKey(),
                'kind' => $data['kind'] ?? 'news',
                'title' => mb_substr($data['title'], 0, 120),
                'body' => $data['body'],
                'link_url' => $data['link_url'] ?? null,
                'link_label' => isset($data['link_label']) ? mb_substr($data['link_label'], 0, 40) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
