import { useEffect, useRef, useState } from 'react';
import { ChevronRight, X } from 'lucide-react';
import { ICONS, SmartLink, themeOf } from '../home/homeTheme';
import { useAdView, useAds } from '../../hooks/useAds';
import { rememberClosed, track } from '../../lib/ads';

// One ad spot (Site → Ads in the admin). Shows nothing until there is an ad
// for it. Several ads in one spot swipe sideways with dots, like OPay's
// home cards, and move on by themselves every 6 seconds.
// Space between ads in one spot, in pixels (matches gap-4 below).
const GAP = 16;

export default function AdSlot({ placement, className = '' }) {
    const { ads: loaded } = useAds(placement);
    const [ads, setAds] = useState([]);
    const [active, setActive] = useState(0);
    const trackRef = useRef(null);

    useEffect(() => setAds(loaded), [loaded]);

    useEffect(() => {
        const el = trackRef.current;
        if (! el || ads.length < 2) return undefined;

        const step = () => el.offsetWidth + GAP;
        const onScroll = () => setActive(Math.round(el.scrollLeft / Math.max(1, step())));
        let paused = false;
        const pause = () => {
            paused = true;
        };
        const timer = setInterval(() => {
            if (paused) return;
            const next = el.scrollLeft + step() >= el.scrollWidth - 4 ? 0 : el.scrollLeft + step();
            el.scrollTo({ left: next, behavior: 'smooth' });
        }, 6000);

        el.addEventListener('scroll', onScroll, { passive: true });
        el.addEventListener('touchstart', pause, { passive: true });

        return () => {
            clearInterval(timer);
            el.removeEventListener('scroll', onScroll);
            el.removeEventListener('touchstart', pause);
        };
    }, [ads.length]);

    if (ads.length === 0) return null;

    function close(ad) {
        track(ad, 'close');
        rememberClosed(ad);
        setAds((list) => list.filter((a) => a.token !== ad.token));
        setActive(0);
    }

    return (
        <section aria-label="Promotions" className={className}>
            <div ref={trackRef} className="no-scrollbar flex snap-x snap-mandatory gap-4 overflow-x-auto py-1">
                {ads.map((ad, i) => (
                    <div key={ad.token} className="flex w-full shrink-0 snap-center">
                        <AdView ad={ad} active={i === active} onClose={() => close(ad)} />
                    </div>
                ))}
            </div>
            {ads.length > 1 && (
                <div className="mt-2 flex justify-center gap-1.5" aria-hidden="true">
                    {ads.map((ad, i) => (
                        <span key={ad.token} className={`h-1.5 rounded-full transition-all ${i === active ? 'w-4 bg-gray-700 dark:bg-gray-200' : 'w-1.5 bg-gray-300 dark:bg-gray-600'}`} />
                    ))}
                </div>
            )}
        </section>
    );
}

export function AdView({ ad, active = true, onClose }) {
    const ref = useRef(null);
    useAdView(ref, ad, active);

    const theme = themeOf(ad.theme);
    const Icon = ICONS[ad.icon];
    const onClick = () => track(ad, 'click');
    const closeButton = ad.can_close && onClose && (
        <button
            type="button"
            onClick={onClose}
            aria-label="Hide this ad"
            className="absolute right-2 top-2 z-10 rounded-full p-1 text-current opacity-50 transition-opacity hover:opacity-100"
        >
            <X className="h-4 w-4" />
        </button>
    );

    if (ad.look === 'banner') {
        return (
            <div
                ref={ref}
                className={`relative w-full overflow-hidden rounded-2xl p-5 text-white shadow-lg ${theme.slide}`}
                style={ad.image_url ? { backgroundImage: `linear-gradient(rgba(0,0,0,.4), rgba(0,0,0,.4)), url("${ad.image_url}")`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined}
            >
                {closeButton}
                {ad.badge && <span className={`mb-2 inline-block rounded px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide ${theme.badge}`}>{ad.badge}</span>}
                <h3 className="max-w-[85%] text-xl font-extrabold leading-tight">{ad.title}</h3>
                {ad.text && <p className="mt-1 max-w-[85%] text-xs font-medium text-white/85">{ad.text}</p>}
                <SmartLink href={ad.href} onClick={onClick} className={`mt-4 inline-flex items-center gap-1 rounded-full bg-white px-5 py-2.5 text-sm font-bold shadow active:scale-95 ${theme.button}`}>
                    {ad.button_label} {Icon && <Icon className="h-4 w-4" />}
                </SmartLink>
                <span className="absolute bottom-2 right-3 text-[9px] font-semibold uppercase tracking-wider text-white/50">Ad</span>
            </div>
        );
    }

    if (ad.look === 'strip') {
        return (
            <div ref={ref} className="w-full">
            <SmartLink
                href={ad.href}
                onClick={onClick}
                className="flex items-center gap-3 rounded-xl border border-gray-100 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-dark-card"
            >
                <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${theme.tile}`}>
                    {Icon ? <Icon className="h-4 w-4" /> : <span className="text-sm">✨</span>}
                </span>
                <span className="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white">
                    {ad.title}
                    {ad.text && <span className="font-normal text-gray-500 dark:text-gray-400"> · {ad.text}</span>}
                </span>
                <ChevronRight className="h-4 w-4 shrink-0 text-gray-400" />
            </SmartLink>
            </div>
        );
    }

    return (
        <div ref={ref} className="relative flex w-full items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-dark-card">
            {closeButton}
            {ad.image_url ? (
                <img src={ad.image_url} alt="" loading="lazy" className="h-14 w-14 shrink-0 rounded-xl object-cover" />
            ) : (
                <span className={`flex h-14 w-14 shrink-0 items-center justify-center rounded-xl ${theme.tile}`}>
                    {Icon ? <Icon className="h-7 w-7" /> : <span className="text-2xl">✨</span>}
                </span>
            )}
            <div className="min-w-0 flex-1 pr-4">
                {ad.badge && <span className={`mb-0.5 inline-block rounded px-1.5 py-0.5 text-[10px] font-extrabold ${theme.tile}`}>{ad.badge}</span>}
                <p className="text-[15px] font-bold leading-snug text-gray-900 dark:text-white">{ad.title}</p>
                {ad.text && <p className="mt-0.5 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{ad.text}</p>}
            </div>
            <SmartLink href={ad.href} onClick={onClick} className={`shrink-0 rounded-full px-5 py-2 text-sm font-bold text-white shadow active:scale-95 ${theme.card}`}>
                {ad.button_label}
            </SmartLink>
        </div>
    );
}
