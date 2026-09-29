<?php

namespace App\Notifications;

use App\Models\SupportTicketMessage;
use App\Notifications\Channels\InboxChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Tells a user an admin replied to their support ticket — real, unlike the legacy fake support form. */
class SupportTicketReply extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly SupportTicketMessage $message) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', InboxChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New reply on your support ticket')
            ->greeting('You have a new reply')
            ->line($this->message->message);
    }

    /** The bell alert is written straight away, not on the next background run. */
    public function viaConnections(): array
    {
        return [InboxChannel::class => 'sync'];
    }

    public function toInbox(mixed $notifiable): array
    {
        $subject = $this->message->ticket?->subject;

        return [
            'kind' => 'support',
            'title' => 'Support replied'.($subject ? ": {$subject}" : ''),
            'body' => Str::limit($this->message->message, 300),
            'link_url' => '/support?ticket='.$this->message->support_ticket_id,
            'link_label' => 'Open ticket',
        ];
    }
}
