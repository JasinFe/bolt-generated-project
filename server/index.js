'use strict';
/**
 * Mister Preacher — serveur HTTP (aucune dépendance externe).
 * Sert l'interface (public/) et l'API JSON (/api/...).
 */
require('./lib/env').loadEnv();
const http = require('http');
const fs = require('fs');
const path = require('path');
const B = require('./lib/bible');
const AB = require('./lib/apibible');
const ASSISTANT = require('./lib/assistant');
const { BOOKS, parseRefs, formatRef } = require('./lib/refs');

const PUBLIC = path.join(__dirname, '..', 'public');
const DATA = path.join(__dirname, '..', 'data');
const THEMES = JSON.parse(fs.readFileSync(path.join(DATA, 'themes.json'), 'utf8'));
const PARALLELS = JSON.parse(fs.readFileSync(path.join(DATA, 'parallels.json'), 'utf8'));
const EVANG = JSON.parse(fs.readFileSync(path.join(DATA, 'evangelisation.json'), 'utf8'));
const LIVRES = JSON.parse(fs.readFileSync(path.join(DATA, 'livres.json'), 'utf8'));
const PERSONNAGES = JSON.parse(fs.readFileSync(path.join(DATA, 'personnages.json'), 'utf8'));

const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json',
};

// Chargement des versions API.Bible (lancé au démarrage) : les routes l'attendent quelques secondes au plus.
let abReady = Promise.resolve();
const waitAB = () => Promise.race([abReady, new Promise(r => setTimeout(r, 8000))]);

const versionParam = q => {
  const v = q.get('v') || B.DEFAULT_VERSION;
  if (!B.versionMeta(v) && !AB.isRemote(v)) throw B.httpError(404, `Version inconnue : ${v}`);
  return v;
};

/** Passage(s) pour une version locale ou distante (API.Bible). */
async function passageAny(v, ref) {
  if (!AB.isRemote(v)) return B.passage(v, ref);
  const refs = parseRefs(ref);
  if (!refs.length) throw B.httpError(400, `Référence non reconnue : « ${ref} »`);
  const passages = [];
  for (const r of refs) passages.push({ ref: formatRef(r), book: r.book, chapter: r.chapter, verses: await AB.rangeVerses(v, r) });
  return { version: v, passages, copyright: AB.meta(v).license };
}

/** Résout une liste de références en textes, pour les thèmes, plans, etc. */
function resolveRefs(refs, v, maxVerses = 12) {
  return refs.map(ref => {
    const r = parseRefs(ref)[0];
    const verses = r ? B.rangeVerses(v, r, maxVerses) : [];
    return { ref: r ? formatRef(r) : ref, query: ref, verses };
  });
}

function verseOfTheDay(v, date = new Date()) {
  const day = Math.floor(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()) / 86400000);
  const ref = EVANG.votd[day % EVANG.votd.length];
  return resolveRefs([ref], v)[0];
}

const routes = {
  'GET /api/versions': async () => { await waitAB(); return [...B.VERSIONS, ...AB.list()]; },
  'GET /api/canon': () => BOOKS.map(b => ({ id: b.id, fr: b.fr, en: b.en, cat: b.cat })),
  'GET /api/books': q => {
    const v = versionParam(q);
    if (!AB.isRemote(v)) return B.books(v);
    const counts = AB.meta(v).books;
    return BOOKS.filter(b => counts[b.id]).map(b => ({ id: b.id, fr: b.fr, en: b.en, cat: b.cat, chapters: counts[b.id] }));
  },
  'GET /api/chapter': q => {
    const v = versionParam(q);
    return AB.isRemote(v) ? AB.chapter(v, q.get('b'), +q.get('c') || 1) : B.chapter(v, q.get('b'), +q.get('c') || 1);
  },
  'GET /api/passage': q => passageAny(versionParam(q), q.get('ref')),
  'GET /api/parse': q => parseRefs(q.get('ref')).map(r => ({ ...r, label: formatRef(r) })),
  'GET /api/search': q => {
    const v = versionParam(q);
    if (AB.isRemote(v)) return AB.search(v, q.get('q'), { limit: q.get('limit'), offset: q.get('offset') });
    return B.search(v, q.get('q'), {
      mode: q.get('mode') || 'all', scope: q.get('scope'), books: q.get('books'), limit: q.get('limit'), offset: q.get('offset'),
    });
  },
  'GET /api/strong': q => B.strongEntry(q.get('n')),
  'GET /api/strong/lookup': q => B.strongLookup(q.get('w') || '', q.get('v') || B.DEFAULT_VERSION),
  'GET /api/xref': q => B.crossRefs(q.get('key'), versionParam(q), Math.min(+q.get('limit') || 40, 200)),
  'GET /api/compare': async q => {
    const ids = q.get('versions') ? q.get('versions').split(',').filter(Boolean) : null;
    const local = ids ? ids.filter(id => !AB.isRemote(id)) : null;
    const refs0 = parseRefs(q.get('ref'));
    if (!refs0.length) throw B.httpError(400, `Référence non reconnue : « ${q.get('ref')} »`);
    const data = ids && !local.length ? { ref: refs0.map(r => formatRef(r)).join(' ; '), versions: [] } : B.compare(q.get('ref'), local);
    const remoteIds = (ids || AB.list().map(v => v.id)).filter(id => AB.isRemote(id));
    for (const id of remoteIds) {
      try {
        const verses = [];
        for (const r of refs0) verses.push(...await AB.rangeVerses(id, r, 60));
        const m = AB.meta(id);
        if (verses.length) data.versions.push({ id, name: m.name, short: m.short, lang: m.lang, dir: m.dir, verses, remote: true });
      } catch (e) { /* version distante indisponible : on l'ignore */ }
    }
    if (ids) data.versions.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
    return data;
  },
  'GET /api/themes': () => THEMES.map(({ refs, ...t }) => ({ ...t, count: refs.length })),
  'GET /api/theme': q => {
    const t = THEMES.find(x => x.id === q.get('id'));
    if (!t) throw B.httpError(404, 'Thème introuvable');
    return { ...t, passages: resolveRefs(t.refs, versionParam(q)) };
  },
  'GET /api/parallels': q => {
    const v = versionParam(q);
    const ref = q.get('ref');
    let list = PARALLELS;
    if (ref) {
      const target = parseRefs(ref)[0];
      list = target ? PARALLELS.filter(p => p.refs.some(r => {
        const x = parseRefs(r)[0];
        return x && x.book === target.book && target.chapter >= x.chapter && target.chapter <= x.endChapter;
      })) : [];
    }
    return list.map(p => ({ title: p.title, passages: q.get('full') ? resolveRefs(p.refs, v, 40) : p.refs.map(r => ({ ref: r })) }));
  },
  'GET /api/evangelisation': q => {
    const v = versionParam(q);
    return {
      plans: EVANG.plans.map(p => ({ ...p, steps: p.steps.map(s => ({ ...s, ...resolveRefs([s.ref], v)[0] })) })),
      questions: EVANG.questions.map(x => ({ q: x.q, passages: resolveRefs(x.refs, v) })),
    };
  },
  'GET /api/votd': q => verseOfTheDay(versionParam(q)),
  'GET /api/rhymes': q => B.rhymes(q.get('w') || '', q.get('v') || B.DEFAULT_VERSION),
  'POST /api/palette': (q, body) => {
    const v = (body && body.v) || B.DEFAULT_VERSION;
    const refs = (body && body.refs) || [];
    const texts = refs.flatMap(r => parseRefs(r).flatMap(x => B.rangeVerses(v, x, 60).map(y => y.text)));
    return B.wordPalette(texts);
  },
  'GET /api/lookup': q => {
    // Point d'entrée unique : référence -> passage, sinon recherche.
    const text = (q.get('q') || '').trim();
    const v = versionParam(q);
    const refs = /\d/.test(text) ? parseRefs(text) : [];
    if (refs.length) return passageAny(v, text).then(p => ({ type: 'passage', ...p }));
    if (AB.isRemote(v)) return AB.search(v, text, { limit: q.get('limit') }).then(r => ({ type: 'search', ...r }));
    return { type: 'search', ...B.search(v, text, { mode: q.get('mode') || 'all', scope: q.get('scope'), limit: q.get('limit') }) };
  },
  'GET /api/livres': () => BOOKS.filter(b => LIVRES[b.id]).map(b => ({ id: b.id, fr: b.fr, cat: b.cat, theme: LIVRES[b.id].theme })),
  'GET /api/livre': q => {
    const id = q.get('id');
    const intro = LIVRES[id];
    if (!intro) throw B.httpError(404, 'Introduction indisponible pour ce livre');
    const b = BOOKS.find(x => x.id === id);
    return { id, fr: b.fr, cat: b.cat, ...intro, cleText: resolveRefs([intro.cle], versionParam(q))[0] };
  },
  'GET /api/personnages': () => PERSONNAGES.map(({ refs, lecons, ...p }) => p),
  'GET /api/personnage': q => {
    const p = PERSONNAGES.find(x => x.id === q.get('id'));
    if (!p) throw B.httpError(404, 'Personnage introuvable');
    return { ...p, passages: resolveRefs(p.refs, versionParam(q), 8) };
  },
  'GET /api/assistant/status': () => ({ enabled: ASSISTANT.enabled(), model: ASSISTANT.MODEL, modes: Object.keys(ASSISTANT.MODES) }),
  'GET /api/health': () => ({ ok: true, versions: B.VERSIONS.length + AB.list().length, remote: AB.list().length, books: BOOKS.length }),
};

function send(res, status, body, type = 'application/json; charset=utf-8', extra = {}) {
  res.writeHead(status, { 'Content-Type': type, 'X-Content-Type-Options': 'nosniff', ...extra });
  res.end(body);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', c => { data += c; if (data.length > 1e6) { reject(B.httpError(413, 'Requête trop volumineuse')); req.destroy(); } });
    req.on('end', () => { try { resolve(data ? JSON.parse(data) : {}); } catch { reject(B.httpError(400, 'JSON invalide')); } });
  });
}

function serveStatic(req, res, pathname) {
  let decoded;
  try { decoded = decodeURIComponent(pathname); } catch { return send(res, 400, 'URL invalide', 'text/plain; charset=utf-8'); }
  let file = path.normalize(path.join(PUBLIC, decoded));
  if (file !== PUBLIC && !file.startsWith(PUBLIC + path.sep)) return send(res, 403, 'Interdit', 'text/plain; charset=utf-8');
  if (!fs.existsSync(file) || fs.statSync(file).isDirectory()) file = path.join(PUBLIC, 'index.html');
  const type = MIME[path.extname(file)] || 'application/octet-stream';
  fs.readFile(file, (err, buf) => {
    if (err) return send(res, 500, 'Erreur', 'text/plain');
    send(res, 200, buf, type, { 'Cache-Control': /\.(png|svg|ico)$/.test(file) ? 'public, max-age=86400' : 'no-cache' });
  });
}

/** Assistant IA : réponse envoyée au fil de l'eau (Server-Sent Events). */
async function assistantStream(req, res) {
  let body;
  try { body = await readBody(req); } catch (e) { return send(res, e.status || 400, JSON.stringify({ error: e.message })); }
  res.writeHead(200, { 'Content-Type': 'text/event-stream; charset=utf-8', 'Cache-Control': 'no-cache', Connection: 'keep-alive', 'X-Accel-Buffering': 'no' });
  const emit = (event, data) => res.write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`);
  let closed = false;
  req.on('close', () => { closed = true; });
  try {
    const out = await ASSISTANT.ask(body || {}, text => { if (!closed) emit('text', text); });
    emit('done', out);
  } catch (e) {
    if (!e.status) console.error('Assistant :', e.message);
    emit('error', { message: e.status ? e.message : ASSISTANT.explainError(e) });
  }
  res.end();
}

function createServer() {
  return http.createServer(async (req, res) => {
    let url;
    try { url = new URL(req.url, 'http://localhost'); } catch { return send(res, 400, JSON.stringify({ error: 'URL invalide' })); }
    if (!url.pathname.startsWith('/api/')) return serveStatic(req, res, url.pathname);
    if (req.method === 'POST' && url.pathname === '/api/assistant') return assistantStream(req, res);
    const handler = routes[`${req.method} ${url.pathname}`];
    if (!handler) return send(res, 404, JSON.stringify({ error: 'Route inconnue' }));
    try {
      const body = req.method === 'POST' ? await readBody(req) : null;
      const result = await handler(url.searchParams, body);
      send(res, 200, JSON.stringify(result), undefined, { 'Cache-Control': 'public, max-age=300' });
    } catch (e) {
      const status = e.status || 500;
      if (status === 500) console.error(e);
      send(res, status, JSON.stringify({ error: status === 500 ? 'Erreur interne' : e.message }));
    }
  });
}

if (require.main === module) {
  const port = process.env.PORT || 3000;
  if (process.env.API_BIBLE_KEY) {
    abReady = AB.init(fetch, B.VERSIONS.map(v => v.id))
      .then(list => console.log(list.length
        ? `API.Bible : ${list.length} version(s) ajoutée(s) — ${list.map(v => v.short).join(', ')}`
        : 'API.Bible : aucune Bible accessible avec cette clé pour les langues demandées (lancez « npm run apibible »).'))
      .catch(e => console.warn(`API.Bible indisponible : ${e.message}`));
  }
  const server = createServer();
  server.on('error', e => {
    if (e.code === 'EADDRINUSE') {
      console.log(`Le port ${port} est déjà utilisé : Mister Preacher tourne sans doute déjà. Ouverture de la page…`);
      openBrowser(`http://localhost:${port}`);
      setTimeout(() => process.exit(0), 1500);
    } else throw e;
  });
  server.listen(port, () => {
    const url = `http://localhost:${port}`;
    console.log(`Mister Preacher prêt sur ${url}`);
    console.log('(Ctrl + C pour arrêter le serveur)');
    openBrowser(url);
    // Préchargement de la version par défaut pour des premières réponses rapides
    setImmediate(() => { try { B.search(B.DEFAULT_VERSION, 'Dieu'); B.strongEntry('G26'); } catch (e) { console.error(e); } });
    if (!process.env.API_BIBLE_KEY) console.log('API.Bible désactivée (ajoutez API_BIBLE_KEY dans le fichier .env pour les versions sous licence).');
  });
}

/**
 * Ouvre le navigateur par défaut (Windows, macOS, Linux).
 * Désactivé par MP_NO_OPEN=1, en production (NODE_ENV=production) et dans les conteneurs.
 */
function openBrowser(url) {
  if (process.env.MP_NO_OPEN === '1' || process.env.NODE_ENV === 'production' || process.env.CI) return;
  const { spawn } = require('child_process');
  const [cmd, args] = process.platform === 'win32' ? ['cmd', ['/c', 'start', '""', url]]
    : process.platform === 'darwin' ? ['open', [url]] : ['xdg-open', [url]];
  try {
    const child = spawn(cmd, args, { stdio: 'ignore', detached: true, windowsHide: true });
    child.on('error', () => {});
    child.unref();
  } catch { /* pas de navigateur disponible : on ignore */ }
}

module.exports = { createServer, routes, openBrowser };
