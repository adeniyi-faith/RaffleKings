import { useEffect, useState } from 'react';

// Replaces the copy-pasted `verifyRealPrice()` fetch-and-recompute pattern
// from checkout.php/raffle-details.php/register-special.php: those each
// fetched the raw per-ticket price and then recalculated the discount by
// hand in JavaScript (TD-20). This calls the server's own price-quote
// endpoint instead, so the displayed price can never drift from
// TicketPricingService, the one place that math actually lives.
// promoCode: a code typed at checkout; the server says what it takes off.
export function useTicketPriceQuote(raffleId, quantity, promoCode = null) {
    const [quote, setQuote] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [version, setVersion] = useState(0);

    useEffect(() => {
        if (! raffleId || ! quantity || quantity < 1) {
            setQuote(null);
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setError(null);

        const params = new URLSearchParams({ quantity: String(quantity) });
        if (promoCode) params.set('promo_code', promoCode);

        fetch(`/api/raffles/${raffleId}/price-quote?${params}`, {
            signal: controller.signal,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
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
    }, [raffleId, quantity, promoCode, version]);

    // Ask again, e.g. after the server says the price changed (a Golden
    // Box discount ran out while the page was open).
    const refresh = () => setVersion((v) => v + 1);

    return { quote, loading, error, refresh };
}
