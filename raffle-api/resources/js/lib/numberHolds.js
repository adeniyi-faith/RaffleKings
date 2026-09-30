// Number holds: the server keeps a player's picked numbers for them for a
// few minutes while they sign in and pay (App\Services\NumberHoldService).

const STORE = 'rk_guest_token';
const SAFE = /^[A-Za-z0-9_-]{16,64}$/;

function randomToken() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

let memoryToken = '';

/** A private random label for this browser. A guest's held numbers belong to it
 *  until they sign in, then the server hands them to their account. */
export function guestToken() {
    try {
        let token = localStorage.getItem(STORE);

        if (! token || ! SAFE.test(token)) {
            token = randomToken();
            localStorage.setItem(STORE, token);
        }

        return token;
    } catch {
        // Storage blocked (private mode): fall back to one label per page load.
        if (! memoryToken) {
            memoryToken = randomToken();
        }

        return memoryToken;
    }
}

/**
 * Ask the server to hold these numbers. Never throws; check `.status`:
 *   'held'        -> `.secondsLeft` says how long is left
 *   'unavailable' -> `.unavailable` lists numbers that are sold or held by someone else
 *   'error'       -> the server could not be reached or said no (`.message`)
 */
export async function holdNumbers(raffleId, numbers) {
    try {
        const res = await fetch(`/api/raffles/${raffleId}/holds`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ numbers, guest_token: guestToken() }),
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok) {
            return { status: 'held', secondsLeft: Number(data.seconds_left) || 0 };
        }

        if (res.status === 409 && Array.isArray(data.unavailable_numbers)) {
            return {
                status: 'unavailable',
                unavailable: data.unavailable_numbers.map(Number),
                sold: (data.sold_numbers || []).map(Number),
                held: (data.held_numbers || []).map(Number),
                message: data.message,
            };
        }

        return { status: 'error', message: data.message || '' };
    } catch {
        return { status: 'error', message: '' };
    }
}

/** Let go of numbers the player no longer wants (best effort). */
export function releaseNumbers(raffleId, numbers) {
    return fetch(`/api/raffles/${raffleId}/holds`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ numbers, guest_token: guestToken() }),
    }).catch(() => {});
}

/** "4:07" */
export function clock(seconds) {
    const s = Math.max(0, Math.floor(seconds));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}
