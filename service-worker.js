/*
 * THE LEISURE HUB - PWA SERVICE WORKER
 *
 * PWA SAFETY RULE:
 * Reservation, availability, authentication, admin, payment, refund, tracking,
 * print, and other transactional/sensitive routes are NEVER cached here.
 * They always require the network and only fall back to the branded offline page
 * when the connection is unavailable.
 */

const CACHE_VERSION = 'v1.3.12';
const CACHE_PREFIX = 'tlh-pwa-';
const STATIC_CACHE = `${CACHE_PREFIX}${CACHE_VERSION}-static`;
const PAGE_CACHE = `${CACHE_PREFIX}${CACHE_VERSION}-pages`;

const PRECACHE_ASSETS = [
  './offline.html',
  './manifest.webmanifest',
  './assets/css/style.css',
  './assets/css/tokens.css',
  './assets/css/modern.css',
  './assets/js/app.js',
  './assets/js/launch-v142.js',
  './assets/js/modern.js',
  './assets/img/tlh-logo.png',
  './assets/img/pwa-icon-192.png',
  './assets/img/pwa-icon-512.png',
  './assets/img/pwa-maskable-512.png',
  './assets/img/apple-touch-icon.png',
  './assets/img/leisure-hub-hero-aerial.webp',
  './assets/img/experience/experience-volleyball.webp',
  './assets/img/experience/experience-ready.webp',
  './assets/img/experience/experience-training.webp',
  './assets/img/experience/experience-court.webp'
];

// Public informational pages may use a last-known copy only if the network fails.
// Pages containing live availability, reservation state, payment data, or admin data
// are deliberately absent from this list.
const SAFE_NAVIGATION_PATHS = new Set([
  '/',
  '/index.php',
  '/venue.php',
  '/sports.php',
  '/events.php',
  '/stores.php',
  '/gallery.php',
  '/terms.php'
]);

const TRANSACTIONAL_PATH_PARTS = [
  '/admin/',
  '/reserve.php',
  '/availability.php',
  '/reservation-availability-check.php',
  '/reservation-availability-grid.php',
  '/booking-success.php',
  '/track.php',
  '/reservation-print.php',
  '/contact.php',
  '/leasing.php'
];

const scopeUrl = new URL(self.registration.scope);
const scopePath = scopeUrl.pathname.endsWith('/') ? scopeUrl.pathname.slice(0, -1) : scopeUrl.pathname;

function pathWithinApp(url) {
  let pathname = url.pathname;
  if (scopePath && pathname.startsWith(scopePath)) {
    pathname = pathname.slice(scopePath.length) || '/';
  }
  return pathname.startsWith('/') ? pathname : `/${pathname}`;
}

function isTransactionalPath(pathname) {
  return TRANSACTIONAL_PATH_PARTS.some((part) => pathname === part || pathname.startsWith(part));
}

function isStaticAsset(pathname) {
  return pathname.startsWith('/assets/css/') ||
    pathname.startsWith('/assets/js/') ||
    pathname.startsWith('/assets/img/pwa-') ||
    pathname === '/assets/img/tlh-logo.png' ||
    pathname.startsWith('/assets/img/experience/') ||
    pathname === '/assets/img/leisure-hub-hero-aerial.webp' ||
    pathname === '/manifest.webmanifest';
}

async function offlineResponse() {
  const cache = await caches.open(STATIC_CACHE);
  return (await cache.match('./offline.html', { ignoreSearch: true })) ||
    new Response('You are offline. Please reconnect and try again.', {
      status: 503,
      headers: { 'Content-Type': 'text/plain; charset=UTF-8' }
    });
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_ASSETS))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => {
            const isTlhCache = key.startsWith(CACHE_PREFIX) || /^v\d+\.\d+\.\d+-(static|pages)$/.test(key);
            return isTlhCache && ![STATIC_CACHE, PAGE_CACHE].includes(key);
          })
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const pathname = pathWithinApp(url);

  // Transactional/sensitive pages are network-only. The response is never put
  // into Cache Storage, so offline mode cannot display stale booking/payment data.
  if (isTransactionalPath(pathname)) {
    if (request.mode === 'navigate') {
      event.respondWith(fetch(request).catch(offlineResponse));
    }
    return;
  }

  // Static app-shell assets use cache-first with a background refresh.
  if (isStaticAsset(pathname)) {
    event.respondWith((async () => {
      const cache = await caches.open(STATIC_CACHE);
      const cached = await cache.match(request, { ignoreSearch: true });
      const refresh = fetch(request).then(async (response) => {
        if (response.ok) {
          await cache.put(request, response.clone());
        }
        return response;
      }).catch(() => null);

      if (cached) {
        event.waitUntil(refresh);
        return cached;
      }

      return (await refresh) || offlineResponse();
    })());
    return;
  }

  if (request.mode === 'navigate' && SAFE_NAVIGATION_PATHS.has(pathname)) {
    // Informational pages are network-first so fresh server content wins. A cached
    // copy is only used when offline, and is updated after every successful visit.
    event.respondWith((async () => {
      try {
        const response = await fetch(request);
        if (response.ok) {
          const cache = await caches.open(PAGE_CACHE);
          await cache.put(request, response.clone());
        }
        return response;
      } catch (error) {
        const cache = await caches.open(PAGE_CACHE);
        return (await cache.match(request, { ignoreSearch: true })) || offlineResponse();
      }
    })());
    return;
  }

  // Everything else remains normal network behavior. Navigations still receive a
  // friendly offline screen, but unknown/API responses are never silently cached.
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(offlineResponse));
  }
});

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
