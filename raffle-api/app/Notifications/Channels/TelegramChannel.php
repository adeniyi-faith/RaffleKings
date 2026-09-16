<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A queueable Telegram channel for admin-facing system alerts (deposits,
 * withdrawals, new signups, draw completions — same use as the legacy
 * rk_send_telegram_alert() in api-system.php). A Notification using this
 * channel defines toTelegram(), returning a plain message string.
 *
 * Sends to every configured admin chat ID (services.telegram.admin_chat_ids)
 * — this is a system alert, not routed through a Notifiable's own
 * preferences, so notifiable/routeNotificationFor() aren't used here.
 *
 * Fixes the legacy version's silent failure mode: a non-200 response
 * throws, so a queued job retries and — if the failure persists — lands
 * in `failed_jobs` instead of vanishing with no record at all.
 */
class TelegramChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $token = config('services.telegram.bot_token');
        $chatIds = config('services.telegram.admin_chat_ids', []);

        if (! $token || empty($chatIds)) {
            return; // not configured — nothing to send, not a failure
        }

        $message = $notification->toTelegram($notifiable);

        foreach ($chatIds as $chatId) {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Telegram alert failed for chat {$chatId}: HTTP {$response->status()} — {$response->body()}");
            }
        }
    }
}
