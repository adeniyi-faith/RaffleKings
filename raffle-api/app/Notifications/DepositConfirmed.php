<?php

namespace App\Notifications;

use App\Models\Deposit;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35d — a queued, retried replacement
 * for the legacy site's synchronous rk_send_deposit_receipt() (fired
 * from App\Services\DepositService::confirm(), the real Paystack/
 * Flutterwave gateway path built in item 13). Same idea as
 * TicketPurchaseReceipt/WinnerAnnounced: a slow or temporarily-down mail
 * server no longer makes the confirming request itself wait or fail,
 * and a failure that persists lands in `failed_jobs` instead of
 * vanishing silently.
 */
class DepositConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly Deposit $deposit) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', OneSignalChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deposit confirmed: ₦'.number_format((float) $this->deposit->amount))
            ->greeting('Wallet funded!')
            ->line('Your deposit of ₦'.number_format((float) $this->deposit->amount).' has been confirmed and added to your wallet.')
            ->line("Reference: {$this->deposit->reference}.");
    }

    public function toOneSignal(mixed $notifiable): array
    {
        return [
            'headings' => ['en' => 'Deposit confirmed'],
            'contents' => ['en' => '₦'.number_format((float) $this->deposit->amount).' has been added to your wallet.'],
        ];
    }
}
