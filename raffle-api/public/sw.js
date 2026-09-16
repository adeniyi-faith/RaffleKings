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

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) => Promise.all(names.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

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
