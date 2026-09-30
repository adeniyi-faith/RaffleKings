import { useEffect, useState } from 'react';

// The chance of winning each prize level for a number of tickets, from the
// server's own calculator (App\Services\OddsCalculator), so the page never
// works the maths out itself and can't disagree with the admin or the API.
export function useRaffleOdds(raffleId, quantity) {
    const [odds, setOdds] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (! raffleId || ! quantity || quantity < 1) {
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
    }, [raffleId, quantity]);

    return { odds, failed };
}
