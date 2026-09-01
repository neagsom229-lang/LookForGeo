const CACHE_NAME = 'tracegeo-v1';
const STATIC_ASSETS = ['/', '/favicon.ico']; // Add other static files if needed

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.filter((cacheName) => cacheName !== CACHE_NAME).map((cacheName) => caches.delete(cacheName))
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // 1. IMPORTANT: Ignore non-http(s) requests (e.g., chrome-extension://)
  if (!request.url.startsWith('http')) return;

  // 2. Bypass for non-GET requests (POST uploads, etc.)
  if (request.method !== 'GET') return;

  // 3. Bypass for dynamic API calls, uploads, and Cloudinary
  const url = new URL(request.url);
  if (
    url.pathname.startsWith('/api/') ||
    url.pathname.startsWith('/storage/') ||
    url.pathname.startsWith('/uploads/') ||
    url.hostname.includes('res.cloudinary.com')
  ) {
    return; // Let the browser hit the network directly. NEVER cache these!
  }

  // 4. Handle navigations (HTML pages) with Network First + Preload
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        // Wait for the preload response to settle (fixes the warning!)
        const preloadResponse = await event.preloadResponse;
        if (preloadResponse) return preloadResponse;

        // Network first, fallback to cache for offline
        const networkResponse = await fetch(request);
        return networkResponse;
      } catch (error) {
        const cache = await caches.match(request);
        return cache || new Response('Offline', { status: 503 });
      }
    })());
    return;
  }

  // 5. Static assets (JS, CSS, Fonts, Images): Cache First, falling back to network
  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request).then((response) => {
        // Cache successful responses
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
      });
    })
  );
});