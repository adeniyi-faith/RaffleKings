<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the OLD address when support changes a customer's email, so a wrong or unwanted change is noticed. */
class EmailChangedBySupport extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly string $newEmail) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('The email on your RaffleKings account was changed')
            ->greeting('Your account email was changed')
            ->line("RaffleKings support changed the email on your account to {$this->newEmail}.")
            ->line("If you didn't ask for this, please contact us straight away from the Support page.");
    }
}
