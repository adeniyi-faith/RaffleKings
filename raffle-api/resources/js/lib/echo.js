import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * The site's live-update connection (live draws, ticket counters, live
 * chat, "N watching"), through Pusher since Phase 9. This file is the ONE
 * place that constructs the Echo client, and it degrades sanely when live
 * updates are off or unreachable: it never crashes the page. Every page
 * using this checks `echoOrNull() === null` and falls back to refreshing
 * on a timer, so a page always has real content either way.
 */
let echoInstance;
let attempted = false;

export function echoOrNull() {
    if (attempted) {
        return echoInstance ?? null;
    }

    attempted = true;

    // Phase 9: live updates go through Pusher (shared cPanel hosting can't
    // keep a WebSocket server of its own running). The page template sets
    // window.__rkLive only when they're switched on in Settings.
    const live = window.__rkLive;

    if (! live?.key) {
        // Live updates are off — degrade immediately instead of trying
        // (and logging noisy errors); pages fall back to refreshing.
        return null;
    }

    try {
        window.Pusher = Pusher;

        echoInstance = new Echo({
            broadcaster: 'pusher',
            key: live.key,
            cluster: live.cluster || 'mt1',
            forceTLS: true,
            // "N watching" (presence channels) signs in against the same
            // WordPress login cookie as the rest of the site.
            authEndpoint: '/broadcasting/auth',
            // Comments/reactions/winner reveals are broadcast on a
            // PUBLIC channel by design (see routes/channels.php) — no
            // auth handshake needed to subscribe, so a guest watching
            // the live draw still sees everything live. Only the
            // presence "N watching" channel actually authorizes.
        });

        return echoInstance;
    } catch (e) {
        console.warn('Live updates unavailable: this page will refresh every few seconds instead.', e);
        echoInstance = null;
        return null;
    }
}
