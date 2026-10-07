/** A small fetch wrapper for the JSON auth API — consistent error shape
 *  (Laravel's 422 validation-error body) across every auth page. */
// Made once per tap and reused if the same tap is sent again, so a double
// click or a retry can never move money twice.
export function newIdempotencyKey() {
    return typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : `key-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

// The key for one top-up attempt: the same while the amount stays the same
// (a retry of the same tap), new as soon as the amount changes.
export function topUpKey(holder, amount) {
    if (! holder.key || holder.amount !== amount) {
        holder.key = newIdempotencyKey();
        holder.amount = amount;
    }

    return holder.key;
}

export async function apiPost(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (! response.ok) {
        const message = data.message || firstError(data.errors) || 'Something went wrong. Please try again.';
        throw new Error(message);
    }

    return data;
}

function firstError(errors) {
    if (! errors) {
        return null;
    }

    const first = Object.values(errors)[0];

    return Array.isArray(first) ? first[0] : first;
}
