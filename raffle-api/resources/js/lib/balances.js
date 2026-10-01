import { useSyncExternalStore } from 'react';
import { usePage } from '@inertiajs/react';

// One shared copy of the signed-in customer's balances for every page.
// It starts from the numbers the server sends with each page
// (auth.user.balances), so nothing flashes ₦0 first; pages that change a
// balance (a purchase, a transfer, a spin) call setBalances() and every
// other place on screen updates too. The "hide balance" eye works the
// same way and is remembered on this device.

let balances = null;
let hidden = readHidden();
const adopted = new WeakSet(); // page loads whose numbers were already taken
let newestPage = 0; // when the newest page's numbers were read (server time)
const listeners = new Set();

function emit() {
    listeners.forEach((l) => l());
}

function subscribe(listener) {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

function readHidden() {
    try {
        return localStorage.getItem('rk_hide_balance') === '1';
    } catch {
        return false;
    }
}

/** Merge new numbers in, e.g. setBalances({ wallet: 1200 }) or the /api/wallet reply. */
export function setBalances(next) {
    if (! next) return;

    const mapped = {
        ...(next.wallet !== undefined ? { wallet: Number(next.wallet) } : {}),
        ...(next.wallet_balance !== undefined ? { wallet: Number(next.wallet_balance) } : {}),
        ...(next.earnings !== undefined ? { earnings: Number(next.earnings) } : {}),
        ...(next.earnings_balance !== undefined ? { earnings: Number(next.earnings_balance) } : {}),
        ...(next.points !== undefined ? { points: Number(next.points) } : {}),
    };

    balances = { ...(balances ?? { wallet: 0, earnings: 0, points: 0 }), ...mapped };
    emit();
}

let lastRefresh = 0;

/** Ask the server again (at most every few seconds). */
export function refreshBalances(force = false) {
    if (! force && Date.now() - lastRefresh < 4000) return Promise.resolve();
    lastRefresh = Date.now();

    return fetch('/api/wallet', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((res) => (res.ok ? res.json() : null))
        .then((data) => data && setBalances(data))
        .catch(() => {});
}

/**
 * { wallet, earnings, points } for the signed-in customer, or null for a
 * guest. The server's numbers from this page load win over anything older.
 */
export function useBalances() {
    const { auth } = usePage().props;
    const fromServer = auth?.user?.balances ?? null;

    if (fromServer && ! adopted.has(fromServer)) {
        // Adopt the page's own numbers once per page load. Going Back shows
        // an older copy of a page (e.g. from before a purchase): its numbers
        // must not win, so ask the server for the real ones instead.
        adopted.add(fromServer);
        const { as_of: asOf = 0, ...numbers } = fromServer;

        if (asOf >= newestPage) {
            newestPage = asOf;
            balances = { ...(balances ?? {}), ...numbers };
        } else {
            refreshBalances(true);
        }
    }

    const current = useSyncExternalStore(subscribe, () => balances, () => balances);

    return auth?.user ? current ?? { wallet: 0, earnings: 0, points: 0 } : null;
}

export function useBalanceHidden() {
    const value = useSyncExternalStore(subscribe, () => hidden, () => hidden);

    function toggle() {
        hidden = ! hidden;
        try {
            localStorage.setItem('rk_hide_balance', hidden ? '1' : '0');
        } catch {
            // private mode: just not remembered
        }
        emit();
    }

    return [value, toggle];
}
