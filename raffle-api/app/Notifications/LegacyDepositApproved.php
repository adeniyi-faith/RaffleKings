<?php

namespace App\Notifications;

use App\Models\Legacy\RaffleTransaction;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the new admin console's own
 * deposit-approval queue's equivalent of DepositConfirmed, for legacy's
 * manual bank-transfer deposits (wp_raffle_transactions) rather than a
 * real payment-gateway Deposit. Legacy's own Financials page fires its
 * own synchronous rk_send_deposit_receipt() when approving from there —
 * this is what fires when an admin instead approves from the new console.
 */
class LegacyDepositApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly RaffleTransaction $transaction) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', OneSignalChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deposit confirmed: ₦'.number_format((float) $this->transaction->claimed_amount))
            ->greeting('Wallet funded!')
            ->line('Your deposit of ₦'.number_format((float) $this->transaction->claimed_amount).' has been confirmed and added to your wallet.');
    }

    public function toOneSignal(mixed $notifiable): array
    {
        return [
            'headings' => ['en' => 'Deposit confirmed'],
            'contents' => ['en' => '₦'.number_format((float) $this->transaction->claimed_amount).' has been added to your wallet.'],
        ];
    }
}
