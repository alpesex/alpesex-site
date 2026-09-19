const CACHE = 'cpmp-asm-mobile-5.4.18-3';
const SHELL = ['/application/', '/application/index.html', '/application/mobile-bridge.js', '/application/sync-queue.js', '/application/auto-sync.js', '/application/mobile.css', '/application/manifest.webmanifest', '/assets/icon-192.png', '/assets/icon-512.png'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL))));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('cpmp-asm-mobile-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  // Only public application assets: never cache accounts, APIs, documents or other sites.
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.search || !SHELL.includes(url.pathname)) return;
  event.respondWith(caches.open(CACHE).then(async cache => {
    const cached = await cache.match(event.request);
    if (cached) return cached;
    return fetch(event.request);
  }));
});
