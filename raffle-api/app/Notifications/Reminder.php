<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * A reminder that brings a customer back (App\Services\Reminders\ReminderService):
 * "This raffle ends in 1 hour" or "You left tickets in checkout".
 * Goes by whichever channels are switched on in Settings → Reminders.
 * Every email has a one-tap "stop reminders" link.
 */
class Reminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  string  $kind  raffle_ending | abandoned_checkout
     * @param  list<string>  $channels  push | email | whatsapp
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $buttonLabel,
        public readonly string $raffleTitle,
        public readonly array $channels,
    ) {}

    public function via(mixed $notifiable): array
    {
        return array_values(array_filter([
            in_array('email', $this->channels, true) ? 'mail' : null,
            in_array('push', $this->channels, true) ? OneSignalChannel::class : null,
            in_array('whatsapp', $this->channels, true) ? WhatsAppChannel::class : null,
        ]));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Hi '.($notifiable->display_name ?: 'there').',')
            ->line($this->body)
            ->action($this->buttonLabel, $this->url)
            ->line('Don\'t want reminders like this? [Stop reminders]('.self::unsubscribeUrl($notifiable->getKey()).').');
    }

    public function toOneSignal(mixed $notifiable): array
    {
        return [
            'headings' => ['en' => $this->title],
            'contents' => ['en' => $this->body],
            'url' => $this->url,
        ];
    }

    /** Meta-approved template: {{1}} name, {{2}} raffle, {{3}} link. */
    public function toWhatsApp(mixed $notifiable): array
    {
        return [
            'template' => (string) config("reminders.whatsapp.templates.{$this->kind}"),
            'parameters' => [$notifiable->display_name ?: 'there', $this->raffleTitle, $this->url],
        ];
    }

    /** A signed link that works without logging in and can't be forged for someone else. */
    public static function unsubscribeUrl(int $userId): string
    {
        return URL::signedRoute('reminders.unsubscribe', ['user' => $userId]);
    }
}
