import { useEffect, useState } from 'react';

// Replaces the copy-pasted `verifyRealPrice()` fetch-and-recompute pattern
// from checkout.php/raffle-details.php/register-special.php: those each
// fetched the raw per-ticket price and then recalculated the discount by
// hand in JavaScript (TD-20). This calls the server's own price-quote
// endpoint instead, so the displayed price can never drift from
// TicketPricingService, the one place that math actually lives.
export function useTicketPriceQuote(raffleId, quantity) {
    const [quote, setQuote] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (! raffleId || ! quantity || quantity < 1) {
            setQuote(null);
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setError(null);

        fetch(`/api/raffles/${raffleId}/price-quote?quantity=${quantity}`, { signal: controller.signal })
            .then((res) => {
                if (! res.ok) {
                    throw new Error('Could not price this raffle.');
                }
                return res.json();
            })
            .then((data) => setQuote(data))
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    setError(err.message);
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [raffleId, quantity]);

    return { quote, loading, error };
}
