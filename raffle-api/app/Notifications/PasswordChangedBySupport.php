<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a customer their password was changed by RaffleKings support (never
 * includes the password). If they didn't ask for it, this is how they find out.
 */
class PasswordChangedBySupport extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly string $how) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your RaffleKings password was changed by support')
            ->greeting('Your password was changed')
            ->line($this->how)
            ->line('You were signed out on all your devices.')
            ->line("If you didn't ask support for this, please contact us straight away from the Support page.");
    }
}
