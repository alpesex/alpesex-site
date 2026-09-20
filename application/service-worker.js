const CACHE = 'cpmp-asm-web-6.0.0-session-2';
const ASSETS = ['/application/mobile-bridge.js', '/application/sync-queue.js', '/application/auto-sync.js', '/application/install.js', '/application/mobile.css', '/application/manifest.webmanifest', '/assets/apple-touch-icon.png', '/assets/icon-192.png', '/assets/icon-512.png'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS)).then(() => self.skipWaiting())));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => (key.startsWith('cpmp-asm-mobile-') || key.startsWith('cpmp-asm-web-')) && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
  // Always recheck the customer session on the server, including explicit HTML requests.
  const entry = ['/application', '/application/', '/application/index.html', '/application/index.php'].includes(url.pathname);
  if (entry || event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request, {cache:'no-store'}).catch(() => new Response(
      '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion requise — CPMP-ASM</title><body><h1>Connexion Internet requise</h1><p>Reconnectez-vous à Internet pour vérifier votre session et ouvrir CPMP-ASM.</p><a href="/mon-compte/">Retour à mon espace client</a></body></html>',
      {status:503, headers:{'Content-Type':'text/html; charset=utf-8', 'Cache-Control':'no-store'}}
    )));
    return;
  }
  // Cache only public static assets; never cache the application entry page.
  if (url.search || !ASSETS.includes(url.pathname)) return;
  event.respondWith(caches.open(CACHE).then(async cache => {
    const cached = await cache.match(event.request);
    return cached || fetch(event.request);
  }));
});
