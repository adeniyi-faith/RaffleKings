<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35d — an admin-facing alert when a
 * user requests a withdrawal, replacing the legacy site's synchronous
 * rk_notify_telegram_withdrawal() (fired from the legacy
 * rk_withdrawal_requested action hook). Fired from
 * App\Services\WithdrawalService::request(), the same shape as
 * DrawCompletedAdminAlert — queued with retries instead of a
 * synchronous, unretried curl call.
 */
class WithdrawalRequestSubmittedAdminAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly WithdrawalRequest $withdrawal) {}

    public function via(mixed $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(mixed $notifiable): string
    {
        return sprintf(
            "💸 <b>New Withdrawal</b>\nUser: #%d\nRequested: ₦%s\nSending: ₦%s",
            $this->withdrawal->user_id,
            number_format((float) $this->withdrawal->requested_amount),
            number_format((float) $this->withdrawal->amount_to_send),
        );
    }
}
