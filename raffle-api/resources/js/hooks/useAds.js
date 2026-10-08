import { useEffect, useRef, useState } from 'react';
import { loadAds, track } from '../lib/ads';

/** The ads for one spot on the page (Site → Ads). Empty until loaded, and on any error. */
export function useAds(placement, { enabled = true } = {}) {
    const [state, setState] = useState({ ads: [], popupGapHours: 12, loaded: false });

    useEffect(() => {
        if (! enabled) return undefined;
        let alive = true;
        loadAds(placement).then((result) => alive && setState({ ...result, loaded: true }));

        return () => {
            alive = false;
        };
    }, [placement, enabled]);

    return state;
}

/**
 * Counts one view of `ad` once at least half of `ref`'s element has been on
 * screen for a second while `active` (e.g. the slide currently showing).
 */
export function useAdView(ref, ad, active = true) {
    const counted = useRef(false);

    useEffect(() => {
        const el = ref.current;
        if (! el || ! ad || ! active || counted.current || typeof IntersectionObserver === 'undefined') return undefined;

        let timer = null;
        const observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting && entry.intersectionRatio >= 0.5 && document.visibilityState === 'visible') {
                timer = timer ?? setTimeout(() => {
                    counted.current = true;
                    track(ad, 'view');
                    observer.disconnect();
                }, 1000);
            } else if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        }, { threshold: [0, 0.5, 1] });

        observer.observe(el);

        return () => {
            observer.disconnect();
            if (timer) clearTimeout(timer);
        };
    }, [ref, ad, active]);
}
