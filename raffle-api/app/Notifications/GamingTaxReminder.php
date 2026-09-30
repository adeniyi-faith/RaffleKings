<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The gaming tax for September is due in 3 days": sent by email to staff
 * who handle payouts, and to the staff Telegram chat when it is set up.
 * The wording is worked out by App\Services\GamingTaxReminders.
 */
class GamingTaxReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /** @param  'mail'|'telegram'  $channel */
    public function __construct(
        private readonly string $channel,
        private readonly string $title,
        private readonly string $body,
        private readonly string $url,
    ) {}

    public function via(mixed $notifiable): array
    {
        return $this->channel === 'telegram' ? [TelegramChannel::class] : ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting($this->title)
            ->line($this->body)
            ->action('Open Gaming tax', $this->url)
            ->line('You get this reminder because you handle payouts. It can be switched off in Settings, Payments, Gaming tax.');
    }

    public function toTelegram(mixed $notifiable): string
    {
        return $this->title."\n".$this->body."\n".$this->url;
    }
}
