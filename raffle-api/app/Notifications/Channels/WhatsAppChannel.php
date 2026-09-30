<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp through Meta's WhatsApp Business Cloud API (Settings →
 * Reminders → WhatsApp). A notification using it defines toWhatsApp(),
 * returning ['template' => name, 'parameters' => [text, …]]: messages to
 * customers who haven't written to you in the last 24 hours must use a
 * template Meta has approved, with {{1}}, {{2}}… filled from 'parameters'.
 *
 * Like the other channels, a customer with no usable phone number is
 * skipped quietly, and a refusal from Meta throws so the queue retries it.
 */
class WhatsAppChannel
{
    public static function configured(): bool
    {
        return filled(config('reminders.whatsapp.phone_number_id')) && filled(config('reminders.whatsapp.access_token'));
    }

    /**
     * A Nigerian number in the international form WhatsApp needs:
     * 08012345678 / +234 801 234 5678 → 2348012345678. Null if it can't be one.
     */
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (preg_match('/^0[789][01]\d{8}$/', $digits)) {
            return '234'.substr($digits, 1);
        }

        if (preg_match('/^234[789][01]\d{8}$/', $digits)) {
            return $digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 && ! str_starts_with($digits, '0') ? $digits : null;
    }

    public function send(mixed $notifiable, Notification $notification): void
    {
        $to = self::normalise($notifiable->routeNotificationFor('WhatsApp', $notification));

        if (! $to || ! self::configured()) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        $response = Http::withToken((string) config('reminders.whatsapp.access_token'))
            ->timeout(15)
            ->post('https://graph.facebook.com/v21.0/'.config('reminders.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $message['template'],
                    'language' => ['code' => config('reminders.whatsapp.language', 'en')],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => (string) $text], $message['parameters']),
                    ]],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("WhatsApp message failed: HTTP {$response->status()}: {$response->body()}");
        }
    }
}
