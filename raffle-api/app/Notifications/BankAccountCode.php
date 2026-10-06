<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6-digit code emailed before a bank account can be added. Sent while
 * the customer waits (not queued), so a failure shows on screen.
 */
class BankAccountCode extends Notification
{
    public function __construct(private readonly string $code, private readonly int $minutes) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' code to add a bank account: '.$this->code)
            ->greeting('Adding a bank account')
            ->line('Type this code to add a bank account to your '.config('app.name').' account:')
            ->line("**{$this->code}**")
            ->line("It works for {$this->minutes} minutes and only once.")
            ->line('If you did not ask for this, someone may know your password. Change it now (Forgot password) and contact support. Never share this code with anyone, including us.');
    }
}
