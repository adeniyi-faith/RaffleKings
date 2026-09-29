import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Gift, Loader2 } from 'lucide-react';
import { useTimeLeft } from '../../hooks/useTimeLeft';
import { formatNaira } from '../../lib/format';
import { apiPost } from '../../lib/api';

// The Golden Box (item 46): the old raffles.php's gold "your order is
// waiting" banner, back on the raffle list and home page. The offer and
// its price come from the server (GoldenBoxService), which alone decides
// who gets it; tapping it starts the discount and returns the customer
// to the checkout they left.
export default function GoldenBoxBanner({ className = '' }) {
    const { auth, goldenBox } = usePage().props;
    // Pages that send the offer with the page (home, raffle list) show it
    // straight away; anywhere else it's fetched.
    const sentWithPage = goldenBox !== undefined;
    const [offer, setOffer] = useState(sentWithPage ? goldenBox : null);
    const [claiming, setClaiming] = useState(false);
    const [error, setError] = useState(null);
    const { label, done } = useTimeLeft(offer?.ends_at);

    useEffect(() => {
        if (! auth?.user || sentWithPage) {
            return;
        }

        fetch('/api/golden-box', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => setOffer(data?.offer ?? null))
            .catch(() => {});
    }, [auth?.user]);

    if (! offer || done) {
        return null;
    }

    const checkoutUrl = `/checkout?${new URLSearchParams({
        raffle_id: String(offer.raffle_id),
        qty: String(offer.quantity),
        numbers: offer.ticket_numbers.join(','),
    })}`;

    async function claim() {
        if (offer.state === 'claimed') {
            router.visit(checkoutUrl);
            return;
        }

        setClaiming(true);
        setError(null);

        try {
            await apiPost(`/api/golden-box/${offer.id}/claim`, {});
            router.visit(checkoutUrl);
        } catch (err) {
            setError(err.message);
            setClaiming(false);
        }
    }

    return (
        <button
            type="button"
            onClick={claim}
            disabled={claiming}
            className={`relative block w-full overflow-hidden rounded-2xl bg-gradient-to-r from-yellow-400 via-orange-300 to-yellow-500 p-4 text-left text-gray-900 shadow-xl shadow-orange-500/20 transition-transform active:scale-[0.98] ${className}`}
        >
            <div className="pointer-events-none absolute -left-[75%] top-0 h-full w-1/2 -skew-x-12 animate-shine bg-gradient-to-r from-transparent via-white/40 to-transparent" />
            <div className="relative flex items-center gap-3">
                <div className="min-w-0 flex-1">
                    <span className="mb-1 inline-block rounded bg-black px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-yellow-400">
                        Golden Offer
                    </span>
                    <p className="text-sm font-black leading-tight">
                        {offer.state === 'claimed'
                            ? 'Your Golden Box discount is waiting'
                            : `Wait! Your ${offer.quantity} ticket${offer.quantity === 1 ? '' : 's'} for ${offer.raffle_title} ${offer.quantity === 1 ? 'is' : 'are'} still waiting`}
                    </p>
                    <p className="mt-1 text-xs font-semibold">
                        <span className="line-through opacity-70">{formatNaira(offer.price_before)}</span>{' '}
                        <span className="text-base font-black">{formatNaira(offer.price_after)}</span>{' '}
                        ({offer.percent_off}% off) · ends in {label}
                    </p>
                    {error && <p className="mt-1 text-xs font-bold text-red-800">{error}</p>}
                </div>
                <div className="flex h-14 w-14 flex-shrink-0 flex-col items-center justify-center rounded-xl border border-white/40 bg-white/30 text-[10px] font-black uppercase backdrop-blur-sm">
                    {claiming ? <Loader2 className="h-5 w-5 animate-spin" /> : <Gift className="h-6 w-6" />}
                    {! claiming && (offer.state === 'claimed' ? 'Pay' : 'Claim')}
                </div>
            </div>
        </button>
    );
}
