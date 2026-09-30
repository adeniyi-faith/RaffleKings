import { useEffect, useState } from 'react';

// The chance of winning each prize level for a number of tickets, from the
// server's own calculator (App\Services\OddsCalculator), so the page never
// works the maths out itself and can't disagree with the admin or the API.
//
// The raffle page sends the chances for the usual ticket counts along with the
// page (seedRaffleOdds), and every answer is remembered here, so they show
// straight away. Only an unusual count (say 37 tickets) asks the server.
const cache = new Map();

export function seedRaffleOdds(raffleId, byQuantity) {
    Object.entries(byQuantity ?? {}).forEach(([qty, odds]) => cache.set(`${raffleId}:${qty}`, odds));
}

export function useRaffleOdds(raffleId, quantity) {
    const key = `${raffleId}:${quantity}`;
    const [odds, setOdds] = useState(() => cache.get(key) ?? null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (! raffleId || ! quantity || quantity < 1) {
            return undefined;
        }

        const known = cache.get(key);

        if (known) {
            setOdds(known);
            setFailed(false);

            return undefined;
        }

        const controller = new AbortController();
        // A short pause so tapping through ticket counts doesn't fire a request for each.
        const timer = setTimeout(() => {
            fetch(`/api/raffles/${raffleId}/odds?quantity=${quantity}`, {
                signal: controller.signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(new Error('odds'))))
                .then((data) => {
                    cache.set(key, data);
                    setOdds(data);
                    setFailed(false);
                })
                .catch((err) => {
                    if (err.name !== 'AbortError') {
                        setFailed(true);
                    }
                });
        }, 120);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [raffleId, quantity, key]);

    return { odds, failed };
}
