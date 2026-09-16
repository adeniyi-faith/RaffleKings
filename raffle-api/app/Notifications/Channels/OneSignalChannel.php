<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A queueable OneSignal push channel — same provider the legacy site
 * uses (rk_send_push_notification_direct() in cron-system.php /
 * api-gamification.php), but with two real fixes:
 *
 *  - Laravel's HTTP client verifies TLS certificates by default. The
 *    legacy calls set CURLOPT_SSL_VERIFYPEER => FALSE on every single
 *    OneSignal request (audit TD-37) — nothing here disables that.
 *  - A failed send THROWS instead of being silently discarded (the
 *    legacy code never even checks curl_exec()'s return value). Thrown
 *    inside a queued Notification, that's what makes the job retry and,
 *    if it keeps failing, land in `failed_jobs` — the "dead-letter
 *    list" OVERHAUL_CHECKLIST.md item 17 calls for, using Laravel's own
 *    built-in table rather than a bespoke one.
 *
 * A Notification class using this channel must define toOneSignal(),
 * returning the OneSignal payload fields (e.g. ['headings' => [...],
 * 'contents' => [...]]) — app_id and include_player_ids are added here.
 */
class OneSignalChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $playerId = $notifiable->routeNotificationFor('OneSignal', $notification);

        if (! $playerId) {
            return; // no device registered for this user — nothing to send, not a failure
        }

        $response = Http::withBasicAuth('', (string) config('services.onesignal.api_key'))
            ->timeout(10)
            ->post('https://onesignal.com/api/v1/notifications', array_merge(
                [
                    'app_id' => config('services.onesignal.app_id'),
                    'include_player_ids' => [$playerId],
                ],
                $notification->toOneSignal($notifiable),
            ));

        if ($response->failed()) {
            throw new RuntimeException("OneSignal push failed: HTTP {$response->status()} — {$response->body()}");
        }
    }
}
