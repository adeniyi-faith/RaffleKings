import { useEffect, useState } from 'react';

/** Fetches a server-computed price quote (TicketPricingService, via
 *  GET /api/raffles/{id}/price-quotes) for several quantities in ONE request —
 *  what the ticket-bundle tier cards need to show real, never-hand-
 *  calculated discounted prices per tier. */
export function useTicketPriceQuotes(raffleId, quantities) {
    const [quotes, setQuotes] = useState({});
    const [loading, setLoading] = useState(false);
    const key = quantities.join(',');

    useEffect(() => {
        if (! raffleId) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);

        const params = new URLSearchParams();
        quantities.forEach((qty) => params.append('quantities[]', qty));

        fetch(`/api/raffles/${raffleId}/price-quotes?${params}`, { signal: controller.signal })
            .then((res) => res.json())
            .then((data) => setQuotes(data.quotes ?? {}))
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    // Leave whatever quotes we have; the UI falls back to a loading state per-tier.
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [raffleId, key]);

    return { quotes, loading };
}
