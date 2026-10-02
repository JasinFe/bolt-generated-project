/* Mister Preacher — service hors ligne.
   Règle unique : le réseau d'abord (toujours la version la plus récente), le cache seulement en secours
   quand il n'y a pas de connexion. Les fichiers de l'application sont versionnés (/a/<version>/…),
   donc une ancienne version ne peut jamais être mélangée avec une nouvelle.
   L'assistant IA n'est jamais mis en cache. */
const CACHE = 'mp-cache-v2';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', e => {
  e.waitUntil((async () => {
    const keys = await caches.keys();
    const hadOld = keys.some(k => k !== CACHE);
    await Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)));
    await self.clients.claim();
    // Après une mise à jour : on recharge une fois les pages ouvertes pour quitter l'ancienne version.
    if (hadOld) for (const c of await self.clients.matchAll({ type: 'window' })) c.navigate(c.url).catch(() => {});
  })());
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.startsWith('/api/assistant') || url.pathname === '/sw.js') return;
  e.respondWith((async () => {
    try {
      const res = await fetch(e.request);
      if (res.ok && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
      }
      return res;
    } catch {
      const hit = await caches.match(e.request);
      if (hit) return hit;
      if (e.request.mode === 'navigate') {
        const home = await caches.match('/');
        if (home) return home;
      }
      if (url.pathname.startsWith('/api/')) {
        return new Response(JSON.stringify({ error: 'Hors ligne : ce contenu n’a pas encore été consulté avec une connexion.' }), { status: 503, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
      }
      return new Response('Hors ligne', { status: 503 });
    }
  })());
});
