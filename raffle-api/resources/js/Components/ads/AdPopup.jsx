import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { ICONS, SmartLink, themeOf } from '../home/homeTheme';
import { useAdView, useAds } from '../../hooks/useAds';
import { rememberClosed, track } from '../../lib/ads';

// The pop-up ad (Site → Ads → "Pop-up when someone opens the site"), like
// OPay's: a big card with a picture, headline and one button, and a round X
// under it. At most once every few hours per phone (Settings → General → Ads),
// never on sign-in, sign-up or checkout pages, and a moment after the page opens.
const LAST_KEY = 'rk_ad_popup_at';
const QUIET_PAGES = /^\/(login|register|forgot-password|reset-password|checkout|raffles\/\d+\/numbers|maintenance)/;

function lastShown() {
    try {
        return Number(window.localStorage.getItem(LAST_KEY) || 0);
    } catch {
        return 0;
    }
}

export default function AdPopup() {
    const { url } = usePage();
    // Decided once, on the first page of the visit.
    const [eligible] = useState(() => typeof window !== 'undefined' && ! QUIET_PAGES.test(window.location.pathname) && Date.now() - lastShown() > 60 * 60 * 1000);
    const { ads, popupGapHours, loaded } = useAds('popup', { enabled: eligible });
    const [ad, setAd] = useState(null);

    useEffect(() => {
        if (! loaded || ! ads[0] || Date.now() - lastShown() < popupGapHours * 3600 * 1000) return undefined;
        const timer = setTimeout(() => {
            if (QUIET_PAGES.test(window.location.pathname)) return;
            try {
                window.localStorage.setItem(LAST_KEY, String(Date.now()));
            } catch {
                // Blocked storage: the server's per-person limit still applies.
            }
            setAd(ads[0]);
        }, 1500);

        return () => clearTimeout(timer);
    }, [loaded, ads, popupGapHours]);

    // Moving to another page closes it without counting a "close".
    const firstUrl = useRef(url);
    useEffect(() => {
        if (url !== firstUrl.current) setAd(null);
    }, [url]);

    if (! ad) return null;

    return <PopupCard ad={ad} onClose={() => { track(ad, 'close'); rememberClosed(ad); setAd(null); }} />;
}

function PopupCard({ ad, onClose }) {
    const ref = useRef(null);
    useAdView(ref, ad);
    const theme = themeOf(ad.theme);
    const Icon = ICONS[ad.icon];

    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-[10000] flex flex-col items-center justify-center bg-black/60 px-6 backdrop-blur-[2px]" role="dialog" aria-modal="true" aria-label={ad.title}>
            <div ref={ref} className="w-full max-w-sm overflow-hidden rounded-3xl bg-white text-center shadow-2xl dark:bg-dark-card">
                {ad.image_url ? (
                    <img src={ad.image_url} alt="" className="max-h-72 w-full object-cover" />
                ) : (
                    <div className={`flex h-36 items-center justify-center ${theme.slide}`}>
                        {Icon ? <Icon className="h-16 w-16 text-white" /> : <span className="text-6xl">🎉</span>}
                    </div>
                )}
                <div className="px-6 pb-6 pt-4">
                    {ad.badge && <span className={`mb-2 inline-block rounded-full px-3 py-1 text-xs font-extrabold ${theme.tile}`}>{ad.badge}</span>}
                    <h2 className="text-2xl font-black leading-tight text-gray-900 dark:text-white">{ad.title}</h2>
                    {ad.text && <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">{ad.text}</p>}
                    <SmartLink
                        href={ad.href}
                        onClick={() => {
                            track(ad, 'click');
                            rememberClosed(ad);
                        }}
                        className="mt-5 block w-full rounded-full bg-gradient-to-b from-yellow-300 to-amber-500 px-6 py-3.5 text-lg font-extrabold text-gray-900 shadow-lg active:scale-95"
                    >
                        {ad.button_label}
                    </SmartLink>
                </div>
            </div>
            {/* A pop-up can always be closed, whatever the ad's own setting. */}
            <button type="button" onClick={onClose} aria-label="Close" className="mt-5 rounded-full border-2 border-white/80 p-2 text-white">
                <X className="h-6 w-6" />
            </button>
        </div>
    );
}
