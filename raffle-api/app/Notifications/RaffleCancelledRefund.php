<?php

namespace App\Notifications;

use App\Models\Raffle;
use App\Notifications\Channels\InboxChannel;
use App\Support\Formats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Raffle X was cancelled; ₦N is back in your wallet." (site bell and email) */
class RaffleCancelledRefund extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /** @param  array{wallet: float, earnings: float}  $amounts */
    public function __construct(public readonly Raffle $raffle, public readonly array $amounts) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', InboxChannel::class];
    }

    public function viaConnections(): array
    {
        return [InboxChannel::class => 'sync'];
    }

    private function where(): string
    {
        $parts = array_filter([
            $this->amounts['wallet'] > 0 ? Formats::naira($this->amounts['wallet']).' to your spending wallet' : null,
            $this->amounts['earnings'] > 0 ? Formats::naira($this->amounts['earnings']).' to your winnings' : null,
        ]);

        return $parts === [] ? 'Your tickets have been removed.' : 'We have refunded '.implode(' and ', $parts).'.';
    }

    private function why(): string
    {
        return $this->raffle->cancel_reason ? ' Reason: '.$this->raffle->cancel_reason : '';
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->raffle->title} was cancelled: you've been refunded")
            ->greeting('Hi '.($notifiable->display_name ?: 'there').',')
            ->line("We're sorry: the raffle \"{$this->raffle->title}\" was cancelled.".$this->why())
            ->line($this->where())
            ->line('It is ready to use now, for another raffle or to withdraw (winnings only).')
            ->action('See raffles', url('/raffles'));
    }

    public function toInbox(mixed $notifiable): array
    {
        return [
            'kind' => 'wallet',
            'title' => "{$this->raffle->title} was cancelled",
            'body' => "We're sorry: this raffle was cancelled.".$this->why().' '.$this->where(),
            'link_url' => '/account/transactions',
            'link_label' => 'See my money',
        ];
    }
}
