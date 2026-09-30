<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6-digit code emailed at staff sign-in (App\Services\Auth\StaffTwoStep).
 * Never queued: it is sent while the person waits, and a failure must show
 * on the sign-in screen rather than vanish into the queue.
 */
class StaffSignInCode extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly int $minutes,
        private readonly ?string $ip = null,
        private readonly ?string $device = null,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your '.config('app.name').' admin sign-in code: '.$this->code)
            ->greeting('Your sign-in code')
            ->line('Type this code to finish signing in to the '.config('app.name').' admin:')
            ->line("**{$this->code}**")
            ->line("It works for {$this->minutes} minutes and only once.");

        if ($this->ip || $this->device) {
            $mail->line('Sign-in started from: '.trim(($this->ip ?: 'unknown place').' · '.($this->device ?: 'unknown device'), ' ·'));
        }

        return $mail->line('If this was not you, someone knows your password. Change it now (Forgot password) and tell the site owner. Never share this code with anyone.');
    }
}
