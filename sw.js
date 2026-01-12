// Simple service worker for PWA functionality (with better update behavior)
const CACHE_NAME = 'dusart-presskit-v4';
const urlsToCache = [
  '.',
  'index.html',
  'styles.css',
  'dusart-logo-blanc-transparent.svg',
  'dusart-logo-noir-transparent.svg'
  // Éviter de mettre des URLs cross-origin critiques dans addAll pour ne pas échouer l'installation
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(urlsToCache))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
    ))
  );
  clients.claim();
});

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Network-first for navigations (HTML) to get fresh versions
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req);
        const cache = await caches.open(CACHE_NAME);
        cache.put(req, fresh.clone());
        return fresh;
      } catch (err) {
        const cached = await caches.match(req);
        return cached || caches.match('index.html');
      }
    })());
    return;
  }

  // Stale-while-revalidate for same-origin GET assets (CSS/JS/images)
  if (req.method === 'GET' && new URL(req.url).origin === self.location.origin) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_NAME);
      const cached = await cache.match(req);
      const fetchPromise = fetch(req).then((networkResp) => {
        if (networkResp && networkResp.status === 200) {
          cache.put(req, networkResp.clone());
        }
        return networkResp;
      }).catch(() => cached);
      return cached || fetchPromise;
    })());
    return;
  }

  // Default: try network, fallback to cache
  event.respondWith(fetch(req).catch(() => caches.match(req)));
});

