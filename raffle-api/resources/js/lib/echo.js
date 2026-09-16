import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Item 27's real-time layer: Laravel Reverb, spoken to with the same
 * laravel-echo + pusher-js client Laravel's own docs use for Reverb
 * (Reverb speaks the Pusher protocol). This file is the ONE place that
 * constructs the Echo client, and it is written to degrade sanely when
 * Reverb isn't running (a local dev box that never started
 * `php artisan reverb:start`, or a REVERB_APP_KEY that was never
 * configured for this environment) — per the product owner's
 * requirement, that must never crash the page, only silently drop the
 * "live" part of Live Draw. Every page using this checks
 * `echoOrNull() === null` and falls back to its own initial HTTP fetch,
 * so a page always has real content on load either way.
 */
let echoInstance;
let attempted = false;

export function echoOrNull() {
    if (attempted) {
        return echoInstance ?? null;
    }

    attempted = true;

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (! key) {
        // No Reverb configured for this environment at all — degrade
        // immediately instead of trying (and logging noisy errors).
        return null;
    }

    try {
        window.Pusher = Pusher;

        echoInstance = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            // Comments/reactions/winner reveals are broadcast on a
            // PUBLIC channel by design (see routes/channels.php) — no
            // auth handshake needed to subscribe, so a guest watching
            // the live draw still sees everything live. Only the
            // presence "N watching" channel actually authorizes.
        });

        return echoInstance;
    } catch (e) {
        console.warn('Reverb/Echo unavailable — live updates are disabled for this session.', e);
        echoInstance = null;
        return null;
    }
}
