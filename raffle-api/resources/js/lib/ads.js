// On-site ads (admin: Site → Ads). Every ad spot on a page asks for its ads
// here; spots that ask at the same moment share one request. Views, taps and
// closes are sent back with sendBeacon, which still arrives when the tap
// opens another page.

const VIEWER_KEY = 'rk_ad_viewer';
const CLOSED_KEY = 'rk_ads_closed';

// A random id for this browser, so "at most N times a day" also works for
// visitors who are not logged in. It says nothing about who they are.
export function viewerId() {
    try {
        let id = window.localStorage.getItem(VIEWER_KEY);
        if (! id) {
            id = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `v-${Date.now()}-${Math.random().toString(16).slice(2)}`;
            window.localStorage.setItem(VIEWER_KEY, id);
        }
        return id;
    } catch {
        return '';
    }
}

let batch = null;

/** The ads for one spot (a list, best first). Never throws: no ads on any error. */
export function loadAds(placement) {
    if (! batch) {
        const current = { slots: new Set() };
        current.promise = new Promise((resolve) => {
            setTimeout(() => {
                batch = null;
                const params = new URLSearchParams({
                    slots: [...current.slots].join(','),
                    v: viewerId(),
                    path: window.location.pathname,
                });
                fetch(`/api/ads?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then((res) => (res.ok ? res.json() : {}))
                    .then((data) => resolve(data || {}))
                    .catch(() => resolve({}));
            }, 0);
        });
        batch = current;
    }

    batch.slots.add(placement);

    return batch.promise.then((data) => ({
        ads: (data.ads?.[placement] ?? []).filter((ad) => ! isClosed(ad)),
        popupGapHours: data.popup_gap_hours ?? 12,
    }));
}

export function track(ad, event) {
    const body = JSON.stringify({ t: ad.token, e: event, v: viewerId() });

    try {
        if (navigator.sendBeacon && navigator.sendBeacon('/api/ads/event', new Blob([body], { type: 'application/json' }))) {
            return;
        }
    } catch {
        // Fall through to fetch.
    }

    fetch('/api/ads/event', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body }).catch(() => {});
}

// Closing an ad hides it for the rest of this visit.
function adId(ad) {
    return String(ad.token).split('.')[0];
}

function isClosed(ad) {
    try {
        return JSON.parse(window.sessionStorage.getItem(CLOSED_KEY) || '[]').includes(adId(ad));
    } catch {
        return false;
    }
}

export function rememberClosed(ad) {
    try {
        const closed = JSON.parse(window.sessionStorage.getItem(CLOSED_KEY) || '[]');
        window.sessionStorage.setItem(CLOSED_KEY, JSON.stringify([...new Set([...closed, adId(ad)])].slice(-50)));
    } catch {
        // Blocked storage: it may simply show again on the next page.
    }
}
