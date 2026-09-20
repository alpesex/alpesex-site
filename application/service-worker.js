const CACHE = 'cpmp-asm-web-6.0.0-1';
const SHELL = ['/application/', '/application/index.html', '/application/mobile-bridge.js', '/application/sync-queue.js', '/application/auto-sync.js', '/application/install.js', '/application/mobile.css', '/application/manifest.webmanifest', '/assets/apple-touch-icon.png', '/assets/icon-192.png', '/assets/icon-512.png'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('cpmp-asm-mobile-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  // Only public application assets: never cache accounts, APIs, documents or other sites.
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.search || !SHELL.includes(url.pathname)) return;
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request).then(response => {
      if (response.ok) caches.open(CACHE).then(cache => cache.put('/application/', response.clone()));
      return response;
    }).catch(() => caches.match('/application/')));
    return;
  }
  event.respondWith(caches.open(CACHE).then(async cache => {
    const cached = await cache.match(event.request);
    if (cached) return cached;
    return fetch(event.request);
  }));
});
