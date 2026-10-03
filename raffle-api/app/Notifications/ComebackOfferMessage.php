<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Contracts\TracksDelivery;
use App\Services\Retention\DeliveryTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The email / push copy of a comeback offer, or its "last call"
 * (App\Services\Retention\ComebackOffers). Its button goes through a
 * tracked link to the claim page, so we learn which channel each
 * customer answers.
 */
class ComebackOfferMessage extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * @param  list<string>  $channels  email and/or push
     * @param  array<string, string>  $tokens  channel => tracking token
     */
    public function __construct(
        public readonly int $offerId,
        public readonly string $headline,
        public readonly string $body,
        public readonly string $buttonLabel,
        public readonly Carbon $expiresAt,
        public readonly array $channels,
        public readonly array $tokens,
    ) {}

    public function via(mixed $notifiable): array
    {
        return array_values(array_filter([
            in_array('email', $this->channels, true) ? 'mail' : null,
            in_array('push', $this->channels, true) ? OneSignalChannel::class : null,
        ]));
    }

    public function deliveryToken(string $channel, mixed $notifiable): ?string
    {
        return $this->tokens[$channel] ?? null;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $token = $this->tokens['email'] ?? null;
        $mail = (new MailMessage)
            ->subject($this->headline)
            ->greeting($this->headline)
            ->line($this->body)
            ->action($this->buttonLabel, $token ? DeliveryTracker::linkUrl($token) : url('/messages'))
            ->line('Claim by '.$this->expiresAt->copy()->setTimezone(config('raffles.timezone'))->format('l, j M \a\t g:ia').'. After that it\'s gone.')
            ->line('Don\'t want offers like this? [Stop reminders]('.Reminder::unsubscribeUrl($notifiable->getKey()).').');

        if ($token) {
            $mail->line(DeliveryTracker::pixel($token));
        }

        return $mail;
    }

    public function toOneSignal(mixed $notifiable): array
    {
        $token = $this->tokens['push'] ?? null;

        return [
            'headings' => ['en' => $this->headline],
            'contents' => ['en' => Str::limit($this->body, 180)],
            'url' => $token ? DeliveryTracker::linkUrl($token) : url('/messages'),
        ];
    }
}
