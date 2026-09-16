<?php

namespace App\Notifications;

use App\Models\Legacy\RaffleWinner;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a winner they won — queued (retried on failure, and recorded to
 * `failed_jobs` if it keeps failing, instead of the legacy version's
 * silent failure). Sent over both mail and push so a winner isn't
 * missed just because one channel is unavailable.
 *
 * This fixes a real legacy bug along the way: rk_notify_all_channels()'s
 * broadcast push queries a column called `won_amount`, but the real
 * column on wp_raffle_winners is `prize_cash_value` — the query errors
 * out and the push silently never sends. Built fresh here against the
 * real schema (via App\Models\Legacy\RaffleWinner), so that mismatch
 * can't happen.
 */
class WinnerAnnounced extends Notification implements ShouldQueue
{
    use Queueable;

    /** Retry up to 3 times with backoff, rather than depending on however the queue worker happens to be invoked. */
    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly RaffleWinner $winner) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', OneSignalChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $prize = $this->winner->prize_cash_value > 0
            ? '₦'.number_format((float) $this->winner->prize_cash_value)
            : $this->winner->prize_name;

        return (new MailMessage)
            ->subject('You won! 🎉')
            ->greeting('Congratulations!')
            ->line("Ticket #{$this->winner->ticket_number} won: {$prize}.")
            ->line('Your prize is being reviewed and will be credited soon.');
    }

    public function toOneSignal(mixed $notifiable): array
    {
        $prize = $this->winner->prize_cash_value > 0
            ? '₦'.number_format((float) $this->winner->prize_cash_value)
            : $this->winner->prize_name;

        return [
            'headings' => ['en' => 'You won! 🎉'],
            'contents' => ['en' => "Ticket #{$this->winner->ticket_number} won {$prize}."],
        ];
    }
}
