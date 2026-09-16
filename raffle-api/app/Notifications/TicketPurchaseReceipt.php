<?php

namespace App\Notifications;

use App\Models\Legacy\RaffleTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A purchase receipt — same idea as the legacy site's synchronous
 * receipt email (rk_handle_payment_ai() in api-financials.php), but
 * queued: a slow or temporarily-down mail server no longer makes the
 * purchase request itself wait or fail.
 */
class TicketPurchaseReceipt extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly RaffleTransaction $transaction, private readonly int $ticketCount) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your ticket purchase receipt')
            ->greeting('Thanks for your purchase!')
            ->line("You bought {$this->ticketCount} ticket(s) for ₦{$this->transaction->claimed_amount}.")
            ->line("Reference: transaction #{$this->transaction->id}.");
    }
}
