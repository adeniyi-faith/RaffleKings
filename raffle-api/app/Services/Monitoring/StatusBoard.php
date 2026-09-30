<?php

namespace App\Services\Monitoring;

use App\Services\Maintenance;

/**
 * What the public /status page shows (Settings → On / off → New
 * features): each part of the site, live from the on/off switches and
 * maintenance mode, plus the staff's own message (Settings → Backups &
 * status). Nothing here is secret or technical.
 */
final class StatusBoard
{
    /** Customer-facing parts of the site and the switch behind each. */
    private const PARTS = [
        'Buying tickets' => ['ticket_sales'],
        'Wallet top-ups' => ['deposits'],
        'Withdrawal requests' => ['withdrawals'],
        'New sign-ups' => ['registrations'],
        'Live draws and chat' => ['live_chat'],
        'Rewards and games' => ['daily_claim', 'tasks', 'spin'],
    ];

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $maintenance = app(Maintenance::class)->active();
        $level = (string) config('status.level', 'ok');
        $message = trim((string) config('status.message')) ?: null;

        $parts = collect(self::PARTS)->map(function (array $switches, string $name) use ($maintenance) {
            $paused = collect($switches)->filter(fn ($s) => config("site.switches.{$s}", true) === false)->count();

            return [
                'name' => $name,
                'state' => match (true) {
                    $maintenance => 'maintenance',
                    $paused === count($switches) => 'paused',
                    $paused > 0 => 'partly',
                    default => 'working',
                },
            ];
        })->values()->all();

        $anyDown = collect($parts)->contains(fn ($p) => $p['state'] !== 'working');

        return [
            'overall' => match (true) {
                $maintenance => 'maintenance',
                $level === 'outage' => 'outage',
                $level === 'degraded' || $anyDown => 'degraded',
                default => 'ok',
            },
            'level' => $message ? $level : 'ok',
            'message' => $level === 'ok' ? null : $message,
            'parts' => $parts,
            'maintenance' => $maintenance ? app(Maintenance::class)->page() : null,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** The banner on every page, or null. */
    public function banner(): ?array
    {
        $level = (string) config('status.level', 'ok');
        $message = trim((string) config('status.message'));

        return $level !== 'ok' && $message !== '' && config('status.banner', true)
            ? ['level' => $level, 'message' => $message]
            : null;
    }
}
