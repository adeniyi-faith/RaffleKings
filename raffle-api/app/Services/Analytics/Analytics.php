<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends "this happened" events to PostHog from the server.
 *
 * Money events (purchases, top-ups, withdrawals, wins) are recorded here
 * rather than in the browser: ad-blockers hide browser tracking and people
 * close the tab, but the server knows for certain what happened.
 *
 * - Off unless a project key is saved under Settings → Analytics.
 * - Sent AFTER the reply has gone back to the customer (never slows a
 *   payment down) and any failure is swallowed — tracking can never break
 *   a purchase, a top-up or a withdrawal.
 * - Events are tied to the customer's numeric user id, the same id the
 *   browser uses, so a visit and a purchase land on the same person.
 *   Never pass names, emails, bank details or card details as properties.
 */
class Analytics
{
    public function enabled(): bool
    {
        return filled(config('services.posthog.project_key'));
    }

    /**
     * @param  array<string, mixed>  $properties  extra details about the event
     * @param  array<string, mixed>  $set  facts about the person to remember (e.g. signed_up_at)
     */
    public function capture(int|string|null $userId, string $event, array $properties = [], array $set = []): void
    {
        if (! $this->enabled() || $userId === null || $userId === '' || EventCatalog::isDisabled($event)) {
            return;
        }

        $payload = [
            'api_key' => (string) config('services.posthog.project_key'),
            'event' => $event,
            'distinct_id' => (string) $userId,
            'timestamp' => now()->toIso8601String(),
            'properties' => array_merge($properties, [
                'source' => 'server',
                '$lib' => 'raffle-api',
                ...($set !== [] ? ['$set' => $set] : []),
            ]),
        ];
        $host = rtrim((string) config('services.posthog.host'), '/');

        app()->terminating(function () use ($host, $payload) {
            $this->send($host, $payload);
        });
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $host, array $payload): void
    {
        try {
            Http::timeout(3)->connectTimeout(2)->asJson()->post($host.'/capture/', $payload);
        } catch (Throwable $e) {
            Log::debug('Analytics event not sent: '.$e->getMessage());
        }
    }
}
