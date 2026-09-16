<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An admin-facing Telegram alert when a draw completes — same idea as
 * the legacy site's ad hoc Telegram pings for deposits/withdrawals/new
 * signups/draws (api-system.php / api-gamification.php), but queued
 * with retries instead of a synchronous, unretried curl call.
 */
class DrawCompletedAdminAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        private readonly int $raffleId,
        private readonly int $winnerCount,
    ) {}

    public function via(mixed $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(mixed $notifiable): string
    {
        return "🎉 Draw completed for raffle #{$this->raffleId} — {$this->winnerCount} winner(s) generated (hidden, pending admin review).";
    }
}
