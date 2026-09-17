<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35d — tells a user their withdrawal
 * was paid or rejected. Legacy has no equivalent notification for a
 * REJECTED withdrawal at all (only rk_send_withdrawal_confirmation() on
 * approval) — a user whose request was declined found out only by
 * checking the site themselves. Fired from both
 * App\Services\WithdrawalService::markPaid() and ::reject().
 */
class WithdrawalProcessed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        private readonly WithdrawalRequest $withdrawal,
        private readonly string $outcome, // 'paid' | 'rejected'
        private readonly ?string $reason = null,
    ) {
        if (! in_array($outcome, ['paid', 'rejected'], true)) {
            throw new InvalidArgumentException("Unknown withdrawal outcome: {$outcome}");
        }
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        if ($this->outcome === 'paid') {
            return (new MailMessage)
                ->subject('Withdrawal sent: ₦'.number_format((float) $this->withdrawal->amount_to_send))
                ->greeting('Your withdrawal is on its way!')
                ->line('₦'.number_format((float) $this->withdrawal->amount_to_send).' has been sent to your bank account.');
        }

        $message = (new MailMessage)
            ->subject('Withdrawal request declined')
            ->greeting('Your withdrawal request was declined.')
            ->line('₦'.number_format((float) $this->withdrawal->requested_amount).' has been refunded to your earnings balance.');

        if ($this->reason) {
            $message->line("Reason: {$this->reason}");
        }

        return $message;
    }
}
