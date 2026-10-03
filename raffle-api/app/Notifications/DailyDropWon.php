<?php

namespace App\Notifications;

use App\Models\Raffle;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your ticket caught today's Daily Drop" (App\Services\Engagement\DailyDrops). Already credited. */
class DailyDropWon extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly Raffle $raffle, private readonly int $ticketNumber, private readonly float $amount) {}

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
        return 'Ticket #'.$this->ticketNumber.' in '.$this->raffle->title.' caught today\'s Daily Drop: ₦'.number_format($this->amount).'. It is already in your winnings.';
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You caught the Daily Drop! 🎁')
            ->greeting('Congratulations!')
            ->line($this->line())
            ->line('Your ticket is still in the main draw too.');
    }

    public function toOneSignal(mixed $notifiable): array
    {
        return [
            'headings' => ['en' => 'You caught the Daily Drop! 🎁'],
            'contents' => ['en' => $this->line()],
        ];
    }

    public function toInbox(mixed $notifiable): array
    {
        return [
            'kind' => 'win',
            'title' => 'You caught the Daily Drop! 🎁',
            'body' => $this->line().' Your ticket is still in the main draw too.',
            'link_url' => '/raffles/'.$this->raffle->public_id,
            'link_label' => 'See the raffle',
        ];
    }
}
