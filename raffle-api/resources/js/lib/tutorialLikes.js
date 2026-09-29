// Hearts on Learning Hub tutorials. Each person counts once: signed-in
// customers by account, everyone else by a random id this browser keeps
// (the server's TutorialController::voter). The hearts are also
// remembered here so they stay red on this device straight away.
const DEVICE_KEY = 'rk_device';
const LIKED_KEY = 'rk_liked_tutorials';

export function deviceId() {
    try {
        let id = localStorage.getItem(DEVICE_KEY);
        if (! id) {
            id = window.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
            localStorage.setItem(DEVICE_KEY, id);
        }
        return id;
    } catch {
        return null;
    }
}

function likedSet() {
    try {
        return new Set(JSON.parse(localStorage.getItem(LIKED_KEY) || '[]'));
    } catch {
        return new Set();
    }
}

/** True when this device (or the server, for a signed-in customer) says it's hearted. */
export function isLiked(tutorial) {
    return Boolean(tutorial?.liked) || likedSet().has(tutorial?.id);
}

function remember(id, liked) {
    try {
        const set = likedSet();
        liked ? set.add(id) : set.delete(id);
        localStorage.setItem(LIKED_KEY, JSON.stringify([...set]));
    } catch {
        // Private mode etc.: the heart still counts on the server.
    }
}

/** Heart or un-heart; resolves to the server's new count (or null if the request failed). */
export async function setLiked(id, liked) {
    remember(id, liked);

    try {
        const res = await fetch(`/api/tutorials/${id}/helpful`, {
            method: liked ? 'POST' : 'DELETE',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ device: deviceId() }),
        });
        if (! res.ok) return null;
        const data = await res.json();
        return data.new_count ?? null;
    } catch {
        return null;
    }
}
