import { useEffect, useState } from 'react';
import { echoOrNull } from '../lib/echo';

/**
 * Item 30's real-time ticket counter + honest "N viewing" count, shared
 * by the raffle detail and checkout pages — the exact two places the
 * audit found fabricated urgency (raffles.php's dead random-walk
 * "viewing count", checkout.php's hardcoded "3 other people are viewing
 * this raffle"). Same architecture as LiveDraw/Show.jsx's Echo usage:
 * a public channel for the live counters (so a guest sees them too,
 * same as the raffle page itself), a presence channel for the viewer
 * count (only counts logged-in viewers — a real number, not a fake
 * one), and a polling fallback if Reverb isn't reachable in this
 * environment so the page is never frozen either way.
 *
 * @param  {number}  raffleId
 * @param  {{sold_tickets: number, remaining_tickets: number, is_closed: boolean}}  initial
 * @param  {boolean}  isAuthenticated
 */
export function useLiveRaffle(raffleId, initial, isAuthenticated) {
    const [counts, setCounts] = useState({
        soldTickets: initial.sold_tickets,
        remainingTickets: initial.remaining_tickets,
        isClosed: initial.is_closed,
    });
    const [viewerCount, setViewerCount] = useState(null);

    useEffect(() => {
        let poll;

        function refetch() {
            fetch(`/api/raffles/${raffleId}`)
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => {
                    if (! data) return;
                    setCounts({
                        soldTickets: data.sold_tickets,
                        remainingTickets: data.remaining_tickets,
                        isClosed: data.is_closed,
                    });
                })
                .catch(() => {});
        }

        const echo = echoOrNull();

        if (echo) {
            const channel = echo.channel(`raffle.${raffleId}`);

            channel.listen('.tickets.updated', (payload) => {
                setCounts({
                    soldTickets: payload.sold_tickets,
                    remainingTickets: payload.remaining_tickets,
                    isClosed: payload.is_closed,
                });
            });

            if (isAuthenticated) {
                try {
                    const presence = echo.join(`raffle-presence.${raffleId}`);
                    presence.here((users) => setViewerCount(users.length));
                    presence.joining(() => setViewerCount((c) => (c ?? 0) + 1));
                    presence.leaving(() => setViewerCount((c) => Math.max(0, (c ?? 1) - 1)));
                } catch {
                    // presence is a nice-to-have — never block the rest of the page on it
                }
            }

            return () => {
                echo.leave(`raffle.${raffleId}`);
                if (isAuthenticated) echo.leave(`raffle-presence.${raffleId}`);
            };
        }

        // Documented fallback ONLY — Reverb isn't reachable in this
        // environment, so we poll instead of leaving the counters stale.
        poll = setInterval(refetch, 8000);

        return () => clearInterval(poll);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [raffleId, isAuthenticated]);

    return { ...counts, viewerCount };
}
