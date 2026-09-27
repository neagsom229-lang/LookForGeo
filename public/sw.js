/* =========================================================================
 * TraceGeo Service Worker
 * - Dev-safe: auto-unregisters on localhost / 127.0.0.1
 * - Network-first for navigations (with preload + offline fallback)
 * - Stale-while-revalidate for static assets (faster + always fresh-ish)
 * - Never caches API, storage, uploads, Cloudinary
 * - Handles fetch failures gracefully (no Uncaught promise errors)
 * ========================================================================= */

const CACHE_NAME = 'tracegeo-v2';        // ⬅️ bump this on every deploy
const OFFLINE_URL = '/offline.html';
const NETWORK_TIMEOUT_MS = 8000;

const NEVER_CACHE_PATH_PREFIXES = ['/api/', '/storage/', '/uploads/'];
const NEVER_CACHE_HOSTS = ['res.cloudinary.com'];
const CACHEABLE_DESTINATIONS = [
  'script', 'style', 'image', 'font', 'manifest',
];

/* -------------------------------------------------------------------------
 * DEV GUARD — unregister on localhost / 127.0.0.1 so Laravel dev server
 * never gets its requests intercepted by a stale SW.
 * ----------------------------------------------------------------------- */
const IS_DEV =
  self.location.hostname === 'localhost' ||
  self.location.hostname === '127.0.0.1' ||
  self.location.hostname === '0.0.0.0';

if (IS_DEV) {
  self.addEventListener('install', () => self.skipWaiting());
  self.addEventListener('activate', (event) => {
    event.waitUntil(
      (async () => {
        const keys = await caches.keys();
        await Promise.all(keys.map((k) => caches.delete(k)));
        await self.registration.unregister();
        const clients = await self.clients.matchAll({ type: 'window' });
        clients.forEach((client) => client.navigate(client.url));
      })()
    );
  });
  // No fetch handler in dev → no interception → no more fetch errors.
} else {
  /* -----------------------------------------------------------------------
   * PRODUCTION HANDLERS
   * --------------------------------------------------------------------- */

  /* ------------------------------- install ----------------------------- */
  self.addEventListener('install', (event) => {
    event.waitUntil(
      (async () => {
        const cache = await caches.open(CACHE_NAME);
        // Precache the offline shell. Failures here are non-fatal.
        try {
          await cache.add(new Request(OFFLINE_URL, { cache: 'reload' }));
        } catch (err) {
          console.warn('[SW] Failed to precache offline page:', err);
        }
        await self.skipWaiting();
      })()
    );
  });

  /* ------------------------------ activate ----------------------------- */
  self.addEventListener('activate', (event) => {
    event.waitUntil(
      (async () => {
        // Enable navigation preload (fixes the "preload not enabled" warning)
        if (self.registration.navigationPreload) {
          try {
            await self.registration.navigationPreload.enable();
          } catch (err) {
            console.warn('[SW] navigationPreload.enable failed:', err);
          }
        }

        // Drop old caches
        const names = await caches.keys();
        await Promise.all(
          names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n))
        );

        await self.clients.claim();
      })()
    );
  });

  /* ------------------------------- helpers ----------------------------- */
  const isNeverCache = (url) =>
    NEVER_CACHE_PATH_PREFIXES.some((p) => url.pathname.startsWith(p)) ||
    NEVER_CACHE_HOSTS.some((h) => url.hostname.includes(h));

  const timeoutFetch = (request, ms) =>
    new Promise((resolve, reject) => {
      const timer = setTimeout(
        () => reject(new Error('Network timeout')),
        ms
      );
      fetch(request).then(
        (res) => { clearTimeout(timer); resolve(res); },
        (err) => { clearTimeout(timer); reject(err); }
      );
    });

  const isCacheable = (response) =>
    response &&
    response.status === 200 &&
    response.type !== 'opaque' &&
    response.type !== 'opaqueredirect';

  /* -------------------------------- fetch ------------------------------ */
  self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only http(s)
    if (!request.url.startsWith('http')) return;

    // Only GET
    if (request.method !== 'GET') return;

    // Range requests (video/audio) → let the network handle them
    if (request.headers.has('range')) return;

    const url = new URL(request.url);

    // Never cache these
    if (isNeverCache(url)) return;

    // ---- 1. Navigations: network-first, fallback to cache, then offline ---
    if (request.mode === 'navigate') {
      event.respondWith(
        (async () => {
          try {
            // Preloaded response (only if enabled + supported)
            const preload = await event.preloadResponse;
            if (preload && preload.ok) {
              // Refresh the cached copy of this URL in the background
              const copy = preload.clone();
              caches.open(CACHE_NAME).then((c) => c.put(request, copy)).catch(() => {});
              return preload;
            }

            const fresh = await timeoutFetch(request, NETWORK_TIMEOUT_MS);
            if (isCacheable(fresh)) {
              const copy = fresh.clone();
              caches.open(CACHE_NAME).then((c) => c.put(request, copy)).catch(() => {});
            }
            return fresh;
          } catch (err) {
            // Offline / timeout — try cached page, then offline shell
            const cached = await caches.match(request);
            if (cached) return cached;

            const offline = await caches.match(OFFLINE_URL);
            if (offline) return offline;

            return new Response('You are offline.', {
              status: 503,
              statusText: 'Offline',
              headers: { 'Content-Type': 'text/plain; charset=utf-8' },
            });
          }
        })()
      );
      return;
    }

    // ---- 2. Only handle static asset destinations -----------------------
    if (!CACHEABLE_DESTINATIONS.includes(request.destination)) return;

    // ---- 3. Static assets: stale-while-revalidate ----------------------
    event.respondWith(
      (async () => {
        const cache = await caches.open(CACHE_NAME);
        const cached = await cache.match(request);

        const networkPromise = timeoutFetch(request, NETWORK_TIMEOUT_MS)
          .then((response) => {
            if (isCacheable(response)) {
              cache.put(request, response.clone()).catch(() => {});
            }
            return response;
          })
          .catch((err) => {
            // Swallow — we still have `cached` if it exists
            console.warn('[SW] Asset fetch failed:', request.url, err.message);
            return null;
          });

        // Serve cached immediately if we have it; refresh in background
        if (cached) {
          event.waitUntil(networkPromise);
          return cached;
        }

        // No cache → wait for network
        const fresh = await networkPromise;
        if (fresh) return fresh;

        // Total failure — return a real Response, never reject
        return new Response('', {
          status: 504,
          statusText: 'Gateway Timeout',
        });
      })()
    );
  });

  /* --------------------------- message channel ------------------------- */
  // Allow the page to force-activate a new SW: `sw.postMessage({type:'SKIP_WAITING'})`
  self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
      self.skipWaiting();
    }
  });
}                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            