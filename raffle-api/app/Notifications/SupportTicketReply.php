<?php

namespace App\Notifications;

use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a user an admin replied to their support ticket — real, unlike the legacy fake support form. */
class SupportTicketReply extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly SupportTicketMessage $message) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New reply on your support ticket')
            ->greeting('You have a new reply')
            ->line($this->message->message);
    }
}
