/** A small fetch wrapper for the JSON auth API — consistent error shape
 *  (Laravel's 422 validation-error body) across every auth page. */
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
