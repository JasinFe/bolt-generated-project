/* Mister Preacher — service hors ligne.
   - Application (HTML, CSS, JS, icônes) : servie depuis le cache, mise à jour en arrière-plan.
   - Textes bibliques et données (/api/…) : réseau d'abord, cache en secours (chapitres déjà lus disponibles sans connexion).
   - L'assistant IA et API.Bible ne sont jamais mis en cache. */
const VERSION = 'mp-1.3.0';
const APP = ['/', '/index.html', '/css/style.css', '/js/app.js', '/js/core.js', '/img/icon.svg', '/manifest.webmanifest'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(APP)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.startsWith('/api/assistant')) return;
  if (url.pathname.startsWith('/api/')) {
    e.respondWith(fetch(e.request).then(res => {
      if (res.ok) { const copy = res.clone(); caches.open(VERSION).then(c => c.put(e.request, copy)); }
      return res;
    }).catch(() => caches.match(e.request).then(r => r || new Response(JSON.stringify({ error: 'Hors ligne : ce contenu n’a pas encore été consulté.' }), { status: 503, headers: { 'Content-Type': 'application/json' } }))));
    return;
  }
  e.respondWith(caches.match(e.request).then(hit => {
    const net = fetch(e.request).then(res => {
      if (res.ok) { const copy = res.clone(); caches.open(VERSION).then(c => c.put(e.request, copy)); }
      return res;
    }).catch(() => hit || caches.match('/index.html'));
    return hit || net;
  }));
});
