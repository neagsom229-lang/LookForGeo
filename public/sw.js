const CACHE_NAME = 'tracegeo-v1';

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // ✅ Enable navigation preload (fixes the warning)
      if (self.registration?.navigationPreload) {
        await self.registration.navigationPreload.enable();
      }

      // Clean old caches
      const cacheNames = await caches.keys();
      await Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );

      // Claim clients so the SW takes control immediately
      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // 1. Ignore non-http(s) requests (e.g., chrome-extension://)
  if (!request.url.startsWith('http')) return;

  // 2. Bypass non-GET requests
  if (request.method !== 'GET') return;

  // 3. Bypass API, storage, Cloudinary
  const url = new URL(request.url);
  if (
    url.pathname.startsWith('/api/') ||
    url.pathname.startsWith('/storage/') ||
    url.pathname.startsWith('/uploads/') ||
    url.hostname.includes('res.cloudinary.com')
  ) {
    return; // never cache these
  }

  // 4. Navigation requests: Network first with preload
  if (request.mode === 'navigate') {
    event.respondWith(
      (async () => {
        try {
          // Use the preloaded response if available
          const preloadResponse = await event.preloadResponse;
          if (preloadResponse) return preloadResponse;

          // Fallback to network
          return await fetch(request);
        } catch (error) {
          // Offline fallback
          const cached = await caches.match(request);
          return cached || new Response('Offline', { status: 503 });
        }
      })()
    );
    return;
  }

  // 5. Static assets: Cache first, fallback to network
  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
      });
    })
  );
});