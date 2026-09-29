// Item 31's service worker for the new Inertia+React stack — a clean
// rebuild of the legacy sw.js's job (offline-capable app shell), not a
// port of its actual code: the legacy worker pre-cached hardcoded CDN
// URLs (Tailwind's CDN build, a Google Fonts stylesheet) this stack
// doesn't use at all — everything is bundled through Vite now, under
// content-hashed filenames that change on every deploy, so there is
// nothing safe to pre-cache at install time. Instead, built assets are
// cached opportunistically the first time they're actually requested.
//
// API responses (`/api/*`) and the broadcasting auth handshake are
// NEVER cached — this app's data is real-time and per-user; serving a
// stale cached balance or ticket count would be a correctness bug, not
// a convenience.
const CACHE_NAME = 'rafflekings-v1';
// Profile pictures live in their own cache so the app-shell cache can be
// reset without making everyone download their avatar again.
const AVATAR_CACHE = 'rafflekings-avatars-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) => Promise.all(names.filter((name) => name !== CACHE_NAME && name !== AVATAR_CACHE).map((name) => caches.delete(name))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Profile pictures: an uploaded one has a "?v=" version in its address and a
    // cartoon one is generated from the person's name, so for a given address the
    // picture never changes. Show the saved copy instantly, fetch it once if missing.
    const isAvatar = url.pathname.startsWith('/storage/avatars/') || url.hostname === 'api.dicebear.com';
    if (isAvatar && request.destination === 'image') {
        event.respondWith(
            caches.open(AVATAR_CACHE).then((cache) =>
                cache.match(request).then(
                    (cached) =>
                        cached ||
                        fetch(request).then((response) => {
                            // Cross-origin images come back "opaque" (status 0); those are fine to keep too.
                            if (response && (response.ok || response.type === 'opaque')) {
                                cache.put(request, response.clone());
                            }
                            return response;
                        }),
                ),
            ),
        );
        return;
    }

    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/broadcasting/')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response && response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached || caches.match('/'))),
        );
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname === '/manifest.json') {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response && response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                        }
                        return response;
                    }),
            ),
        );
    }
});
