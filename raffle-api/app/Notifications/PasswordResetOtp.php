<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password-reset OTP email — queued like every other outbound
 * notification since item 17, replacing the legacy site's synchronous
 * rk_send_email()/wp_mail() call in rk_handle_forgot_password().
 */
class PasswordResetOtp extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly string $code) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your RaffleKings password reset code')
            ->greeting('Reset your password')
            ->line("Your verification code is: {$this->code}")
            ->line('This code expires in 15 minutes.')
            ->line("If you didn't request this, you can safely ignore this email.");
    }
}
