const CACHE_NAME = 'rafflekings-v3'; // Updated to v3 to force browser refresh
const ASSETS_TO_CACHE = [
  './',                // The root folder
  './index.php',       // Your main file
  './manifest.json',   // The manifest
  'https://cdn.tailwindcss.com',
  'https://unpkg.com/lucide@latest',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap'
];

// Install Event
self.addEventListener('install', (event) => {
  self.skipWaiting(); // Force this new SW to become active immediately
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // We use map + catch here so one missing file doesn't break the entire install
      return Promise.all(
        ASSETS_TO_CACHE.map(url => {
          return cache.add(url).catch(error => {
            console.error('Could not cache:', url, error);
          });
        })
      );
    })
  );
});

// Activate Event (Cleanup old caches)
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cache) => {
          if (cache !== CACHE_NAME) {
            console.log('Clearing old cache:', cache);
            return caches.delete(cache);
          }
        })
      );
    })
  );
  return self.clients.claim();
});

// Fetch Event (Network First for HTML, Cache First for Assets)
self.addEventListener('fetch', (event) => {
  // Ignore non-GET requests (POST/PUT cannot be cached)
  if (event.request.method !== 'GET') return;

  // HTML pages: Network First
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          // Check for valid response before caching
          if (!response || response.status !== 200 || response.type !== 'basic') {
            return response;
          }
          
          const responseToCache = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
          return response;
        })
        .catch(() => {
          // If offline, try to show the cached version
          return caches.match(event.request);
        })
    );
  } else {
    // Assets (Images, JS, etc): Cache First
    event.respondWith(
      caches.match(event.request).then((response) => {
        return response || fetch(event.request).catch(e => {
            // Optional: return a placeholder image here if fetch fails
            console.log('Fetch failed for:', event.request.url);
        });
      })
    );
  }
});