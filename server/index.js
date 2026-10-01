'use strict';
/**
 * Mister Preacher — serveur HTTP (aucune dépendance externe).
 * Sert l'interface (public/) et l'API JSON (/api/...).
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const B = require('./lib/bible');
const { BOOKS, parseRefs, formatRef } = require('./lib/refs');

const PUBLIC = path.join(__dirname, '..', 'public');
const DATA = path.join(__dirname, '..', 'data');
const THEMES = JSON.parse(fs.readFileSync(path.join(DATA, 'themes.json'), 'utf8'));
const PARALLELS = JSON.parse(fs.readFileSync(path.join(DATA, 'parallels.json'), 'utf8'));
const EVANG = JSON.parse(fs.readFileSync(path.join(DATA, 'evangelisation.json'), 'utf8'));

const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json',
};

const versionParam = q => {
  const v = q.get('v') || B.DEFAULT_VERSION;
  if (!B.versionMeta(v)) throw B.httpError(404, `Version inconnue : ${v}`);
  return v;
};

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
  'GET /api/versions': () => B.VERSIONS,
  'GET /api/books': q => B.books(versionParam(q)),
  'GET /api/chapter': q => B.chapter(versionParam(q), q.get('b'), +q.get('c') || 1),
  'GET /api/passage': q => B.passage(versionParam(q), q.get('ref')),
  'GET /api/parse': q => parseRefs(q.get('ref')).map(r => ({ ...r, label: formatRef(r) })),
  'GET /api/search': q => B.search(versionParam(q), q.get('q'), {
    mode: q.get('mode') || 'all', scope: q.get('scope'), books: q.get('books'), limit: q.get('limit'), offset: q.get('offset'),
  }),
  'GET /api/strong': q => B.strongEntry(q.get('n')),
  'GET /api/strong/lookup': q => B.strongLookup(q.get('w') || '', q.get('v') || B.DEFAULT_VERSION),
  'GET /api/xref': q => B.crossRefs(q.get('key'), versionParam(q), Math.min(+q.get('limit') || 40, 200)),
  'GET /api/compare': q => B.compare(q.get('ref'), q.get('versions') ? q.get('versions').split(',') : null),
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
    if (refs.length) return { type: 'passage', ...B.passage(v, text) };
    return { type: 'search', ...B.search(v, text, { mode: q.get('mode') || 'all', scope: q.get('scope'), limit: q.get('limit') }) };
  },
  'GET /api/health': () => ({ ok: true, versions: B.VERSIONS.length, books: BOOKS.length }),
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
    send(res, 200, buf, type, { 'Cache-Control': type.startsWith('text/html') ? 'no-cache' : 'public, max-age=3600' });
  });
}

function createServer() {
  return http.createServer(async (req, res) => {
    let url;
    try { url = new URL(req.url, 'http://localhost'); } catch { return send(res, 400, JSON.stringify({ error: 'URL invalide' })); }
    if (!url.pathname.startsWith('/api/')) return serveStatic(req, res, url.pathname);
    const handler = routes[`${req.method} ${url.pathname}`];
    if (!handler) return send(res, 404, JSON.stringify({ error: 'Route inconnue' }));
    try {
      const body = req.method === 'POST' ? await readBody(req) : null;
      const result = handler(url.searchParams, body);
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
  createServer().listen(port, () => {
    console.log(`Mister Preacher prêt sur http://localhost:${port}`);
    // Préchargement de la version par défaut pour des premières réponses rapides
    setImmediate(() => { try { B.search(B.DEFAULT_VERSION, 'Dieu'); B.strongEntry('G26'); } catch (e) { console.error(e); } });
  });
}

module.exports = { createServer, routes };
