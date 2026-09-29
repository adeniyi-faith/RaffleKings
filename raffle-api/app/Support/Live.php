<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a live update (ticket counters, live-draw reveals, chat) without
 * ever letting it break what triggered it. With Pusher switched on
 * (Phase 9) a network hiccup or wrong key would otherwise make a ticket
 * purchase that already went through answer "Server Error", or lose a
 * chat message. The pages refresh on a timer anyway, so a missed update
 * only means it arrives a few seconds later.
 */
final class Live
{
    public static function send(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Live update not sent: '.$e->getMessage(), ['event' => $event::class]);
        }
    }
}
