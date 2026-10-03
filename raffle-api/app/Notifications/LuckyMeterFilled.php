<?php

namespace App\Notifications;

use App\Notifications\Channels\InboxChannel;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your Lucky Meter filled up" (App\Services\Engagement\LuckyMeter). Already credited as ticket credit. */
class LuckyMeterFilled extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly float $amount) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', OneSignalChannel::class, InboxChannel::class];
    }

    public function viaConnections(): array
    {
        return [InboxChannel::class => 'sync'];
    }

    private function line(): string
    {
        return 'Your Lucky Meter filled up, so ₦'.number_format($this->amount).' of ticket credit is now in your wallet. Use it on any raffle.';
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Lucky Meter filled up! 🍀')
            ->greeting('No win this time, but this one is guaranteed.')
            ->line($this->line())
            ->line('Every ticket that doesn\'t win keeps filling your meter.');
    }

    public function toOneSignal(mixed $notifiable): array
    {
        return [
            'headings' => ['en' => 'Your Lucky Meter filled up! 🍀'],
            'contents' => ['en' => $this->line()],
        ];
    }

    public function toInbox(mixed $notifiable): array
    {
        return [
            'kind' => 'reward',
            'title' => 'Your Lucky Meter filled up! 🍀',
            'body' => $this->line(),
            'link_url' => '/raffles',
            'link_label' => 'Pick a raffle',
        ];
    }
}
