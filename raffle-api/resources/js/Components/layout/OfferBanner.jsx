import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Gift } from 'lucide-react';

function timeLeft(expiresAt) {
    const s = Math.max(0, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000));
    const pad = (n) => String(n).padStart(2, '0');

    return s > 0 ? `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}` : null;
}

// A comeback offer waiting to be claimed (App\Services\Retention\ComebackOffers):
// a thin strip with a live countdown on every page, until it's claimed or runs out.
export default function OfferBanner() {
    const offer = usePage().props.auth?.user?.offer;
    const [left, setLeft] = useState(() => (offer ? timeLeft(offer.expires_at) : null));

    useEffect(() => {
        if (! offer) return undefined;
        setLeft(timeLeft(offer.expires_at));
        const timer = setInterval(() => setLeft(timeLeft(offer.expires_at)), 1000);

        return () => clearInterval(timer);
    }, [offer?.expires_at]);

    if (! offer || ! left || (typeof window !== 'undefined' && window.location.pathname.startsWith('/offers/'))) {
        return null;
    }

    return (
        <Link href={offer.url} className="flex items-center justify-center gap-2 bg-gradient-to-r from-orange-500 to-rose-500 px-4 py-2 text-center text-xs font-semibold text-white">
            <Gift className="h-3.5 w-3.5 flex-shrink-0" />
            <span>
                {offer.prize_text} is waiting for you · <span className="font-mono tabular-nums">{left}</span> left ·{' '}
                <span className="underline">Claim</span>
            </span>
        </Link>
    );
}
