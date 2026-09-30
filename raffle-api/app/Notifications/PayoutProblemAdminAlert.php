<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Automatic payouts: tells staff on Telegram when Paystack could not send
 * a withdrawal (it goes back to "waiting to be paid"), or took back money
 * it had already sent (a reversal after "paid").
 */
class PayoutProblemAdminAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly WithdrawalRequest $withdrawal, private readonly string $problem) {}

    public function via(mixed $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(mixed $notifiable): string
    {
        return sprintf(
            "⚠️ Paystack payout problem\nWithdrawal #%d, user #%d, ₦%s\n%s",
            $this->withdrawal->id,
            $this->withdrawal->user_id,
            number_format((float) $this->withdrawal->amount_to_send),
            $this->problem,
        );
    }
}
