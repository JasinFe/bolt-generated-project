'use strict';
/** Accès aux Bibles : passages, recherche, Strong, références croisées. */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { normalize, normalizeWithMap, plain, strongTokens, compileQuery, matchQuery } = require('./text');
const { BOOKS, BY_ID, parseRefs, formatRef, formatKey, findBook } = require('./refs');

const DATA = path.join(__dirname, '../../data');
const readJson = f => JSON.parse(fs.readFileSync(path.join(DATA, f), 'utf8'));

const VERSIONS = readJson('versions.json');
const DEFAULT_VERSION = 'LSG';

/**
 * Stockage : data/bibles/<VERSION>/<Livre>.json.gz = { chapters, titles, notes }.
 * Lire un passage ne charge que le livre concerné ; la recherche charge la version entière.
 */
function lru(max) {
  const m = new Map();
  return {
    get(k) { if (!m.has(k)) return undefined; const v = m.get(k); m.delete(k); m.set(k, v); return v; },
    set(k, v) { m.set(k, v); while (m.size > max) m.delete(m.keys().next().value); return v; },
    clear() { m.clear(); },
  };
}
const bookCache = lru(+process.env.MP_MAX_BOOKS || 600);
const versionCache = lru(Math.max(2, +process.env.MP_MAX_VERSIONS || 8));

function versionMeta(id) {
  return VERSIONS.find(v => v.id === id) || null;
}

function httpError(status, message) {
  const e = new Error(message);
  e.status = status;
  return e;
}

/** Données d'un livre dans une version, ou null si le livre n'y figure pas. */
function bookData(id, book) {
  const meta = versionMeta(id);
  if (!meta) throw httpError(404, `Version inconnue : ${id}`);
  if (!meta.books || !meta.books[book]) return null;
  const k = `${id}/${book}`;
  const hit = bookCache.get(k);
  if (hit) return hit;
  const file = path.join(DATA, 'bibles', id, book + '.json.gz');
  return bookCache.set(k, JSON.parse(zlib.gunzipSync(fs.readFileSync(file)).toString('utf8')));
}

/** Charge une version entière (pour la recherche et les index). */
function load(id) {
  const hit = versionCache.get(id);
  if (hit) return hit;
  const meta = versionMeta(id);
  if (!meta) throw httpError(404, `Version inconnue : ${id}`);
  const verses = []; // { key, book, c, v, raw }
  for (const b of BOOKS) {
    const d = bookData(id, b.id);
    if (!d) continue;
    d.chapters.forEach((ch, ci) => ch.forEach((raw, vi) => {
      if (raw) verses.push({ key: `${b.id}.${ci + 1}.${vi + 1}`, book: b.id, c: ci + 1, v: vi + 1, raw });
    }));
  }
  return versionCache.set(id, { meta, verses, norm: null, strong: null });
}

function normIndex(v) {
  if (!v.norm) v.norm = v.verses.map(x => normalize(plain(x.raw)));
  return v.norm;
}

function getVerse(id, key) {
  const [b, c, n] = key.split('.');
  const d = bookData(id, b);
  const ch = d && d.chapters[c - 1];
  return ch ? ch[n - 1] || null : null;
}

/** Livres disponibles pour une version, avec le nombre de chapitres. */
function books(id = DEFAULT_VERSION) {
  const meta = versionMeta(id);
  if (!meta) throw httpError(404, `Version inconnue : ${id}`);
  const counts = meta.books || {};
  return BOOKS.filter(b => counts[b.id]).map(b => ({ id: b.id, fr: b.fr, en: b.en, cat: b.cat, chapters: counts[b.id] }));
}

/** Les versions qui contiennent un livre donné. */
function versionsWithBook(book) {
  return VERSIONS.filter(v => v.books && v.books[book]).map(v => v.id);
}

/** Un chapitre complet avec titres de section et notes. */
function chapter(id, book, c) {
  const d = bookData(id, book);
  if (!d) throw httpError(404, `Le livre ${book} n'existe pas dans ${id}`);
  const ch = d.chapters[c - 1];
  if (!ch) throw httpError(404, `Chapitre ${c} introuvable`);
  return {
    version: id, book, chapter: +c, name: BY_ID.get(book).fr, chapters: d.chapters.length,
    verses: ch.map((raw, i) => {
      const key = `${book}.${c}.${i + 1}`;
      return { v: i + 1, key, text: raw, title: d.titles[key] || null, notes: d.notes[key] || null };
    }).filter(x => x.text),
  };
}

/**
 * Correspondance des numérotations. Les références de Mister Preacher (thèmes, saisie, références croisées)
 * suivent la numérotation usuelle (Segond, KJV). Certaines versions suivent la numérotation hébraïque :
 *   Joël 2:28-32 -> Joël 3:1-5, Joël 3:n -> Joël 4:n ; Malachie 4:n -> Malachie 3:(18+n).
 */
function mapPoint(meta, book, c, v) {
  const counts = meta.books || {};
  if (book === 'Joel' && counts.Joel === 4) {
    if (c === 2 && v != null && v >= 28) return [3, v - 27];
    if (c === 3) return [4, v];
  }
  if (book === 'Mal' && counts.Mal === 3 && c === 4) return [3, v == null ? null : v + 18];
  return [c, v];
}

function mapRef(id, r) {
  const meta = versionMeta(id);
  if (!meta || (r.book !== 'Joel' && r.book !== 'Mal')) return r;
  let [chapter, verse] = mapPoint(meta, r.book, r.chapter, r.verse);
  let [endChapter, endVerse] = mapPoint(meta, r.book, r.endChapter || r.chapter, r.endVerse);
  // « Malachie 4 » entier -> Malachie 3:19-24
  if (r.book === 'Mal' && r.chapter === 4 && r.verse == null && chapter === 3) { verse = 19; endVerse = null; }
  if (r.book === 'Mal' && (r.endChapter || r.chapter) === 4 && r.endVerse == null && endChapter === 3) endVerse = null;
  return { ...r, chapter, verse, endChapter, endVerse };
}

/** Inverse de mapPoint : clé d'une version à numérotation hébraïque -> numérotation usuelle. */
function toStandardKey(id, key) {
  const meta = versionMeta(id);
  const [b, c, v] = key.split('.').map((x, i) => (i ? +x : x));
  if (!meta || !meta.books) return key;
  if (b === 'Joel' && meta.books.Joel === 4) {
    if (c === 3) return `Joel.2.${v + 27}`;
    if (c === 4) return `Joel.3.${v}`;
  }
  if (b === 'Mal' && meta.books.Mal === 3 && c === 3 && v >= 19) return `Mal.4.${v - 18}`;
  return key;
}

/** Versets d'une plage de référence. */
function rangeVerses(id, r, max = 400) {
  r = mapRef(id, r);
  const d = bookData(id, r.book);
  if (!d) return [];
  const chs = d.chapters;
  const out = [];
  const endC = Math.min(r.endChapter || r.chapter, chs.length);
  for (let c = r.chapter; c <= endC; c++) {
    const ch = chs[c - 1] || [];
    const from = c === r.chapter && r.verse != null ? r.verse : 1;
    const to = c === endC && r.endVerse != null ? r.endVerse : ch.length;
    for (let n = from; n <= Math.min(to, ch.length); n++) {
      if (!ch[n - 1]) continue;
      out.push({ key: `${r.book}.${c}.${n}`, c, v: n, text: ch[n - 1] });
      if (out.length >= max) return out;
    }
  }
  return out;
}

/** Passage(s) à partir d'une référence texte. */
function passage(id, ref) {
  const refs = parseRefs(ref);
  if (!refs.length) throw httpError(400, `Référence non reconnue : « ${ref} »`);
  return {
    version: id,
    passages: refs.map(r => ({ ref: formatRef(r), book: r.book, chapter: r.chapter, verses: rangeVerses(id, r) })),
  };
}

const SCOPES = {
  AT: b => BY_ID.get(b).cat === 'AT',
  NT: b => BY_ID.get(b).cat === 'NT',
  DC: b => ['DC', 'AP'].includes(BY_ID.get(b).cat),
  CANON: b => ['AT', 'NT'].includes(BY_ID.get(b).cat),
};

/**
 * Recherche plein texte.
 * opts : { mode: all|any|phrase|partial|regex, scope: AT|NT|DC|CANON, books: 'Gen,Exod', limit, offset }
 */
function search(id, q, opts = {}) {
  q = String(q || '').trim();
  if (!q) throw httpError(400, 'Recherche vide');
  // Recherche par numéro Strong : « G25 » ou « H7225 »
  if (/^[HG]\d+[a-z]?$/i.test(q)) return strongSearch(id, q.toUpperCase(), opts);
  const v = load(id);
  let cq;
  try { cq = compileQuery(q, opts.mode); } catch (e) { throw httpError(400, 'Expression invalide : ' + e.message); }
  if (!cq.terms.length) throw httpError(400, 'Aucun mot à chercher');
  const scope = SCOPES[opts.scope];
  const bookSet = opts.books ? new Set(String(opts.books).split(',').map(b => findBook(b) || b)) : null;
  const limit = Math.min(+opts.limit || 100, 500);
  const offset = +opts.offset || 0;
  const norm = normIndex(v);
  const byBook = {};
  const hits = [];
  let total = 0;
  for (let i = 0; i < v.verses.length; i++) {
    const x = v.verses[i];
    if (scope && !scope(x.book)) continue;
    if (bookSet && !bookSet.has(x.book)) continue;
    if (!matchQuery(cq, norm[i])) continue;
    total++;
    byBook[x.book] = (byBook[x.book] || 0) + 1;
    if (total > offset && hits.length < limit) hits.push(x);
  }
  return {
    version: id, query: q, total, offset, limit,
    byBook: Object.entries(byBook).map(([b, n]) => ({ book: b, name: BY_ID.get(b).fr, count: n })),
    results: hits.map(x => {
      const text = plain(x.raw);
      const { norm: nt, map } = normalizeWithMap(text);
      const hl = (matchQuery(cq, nt) || []).map(([a, b]) => [map[a], map[b]]);
      return { key: x.key, ref: formatKey(x.key), text, hl };
    }),
  };
}

/** Index Strong d'une version : numéro -> versets + traductions employées. */
function strongIndex(v) {
  if (v.strong) return v.strong;
  const idx = new Map();
  for (const x of v.verses) {
    for (const t of strongTokens(x.raw)) {
      for (const n of t.nums) {
        let e = idx.get(n);
        if (!e) idx.set(n, (e = { keys: [], words: new Map() }));
        if (e.keys[e.keys.length - 1] !== x.key) e.keys.push(x.key);
        const w = t.word.trim().toLowerCase();
        e.words.set(w, (e.words.get(w) || 0) + 1);
      }
    }
  }
  v.strong = idx;
  return idx;
}

function strongSearch(id, num, opts = {}) {
  // Version sans numéros Strong : on cherche dans la Darby (français) ou la KJV (autres langues).
  const meta = versionMeta(id);
  if (!meta || !meta.strong) id = meta && meta.lang !== 'fr' ? 'KJVA' : 'JND';
  const v = load(id);
  const e = strongIndex(v).get(num) || { keys: [], words: new Map() };
  const limit = Math.min(+opts.limit || 100, 500);
  const offset = +opts.offset || 0;
  const byBook = {};
  for (const k of e.keys) { const b = k.split('.')[0]; byBook[b] = (byBook[b] || 0) + 1; }
  return {
    version: id, query: num, strong: num, total: e.keys.length, offset, limit,
    byBook: Object.entries(byBook).map(([b, n]) => ({ book: b, name: BY_ID.get(b).fr, count: n })),
    results: e.keys.slice(offset, offset + limit).map(k => {
      const raw = getVerse(id, k);
      // Surligne les mots portant ce numéro
      let text = '';
      const hl = [];
      let last = 0;
      for (const m of raw.matchAll(/\{([^|}]*)\|([^}]*)\}/g)) {
        text += raw.slice(last, m.index).replace(/[[\]]/g, '');
        if (m[2].split(',').includes(num)) hl.push([text.length, text.length + m[1].length]);
        text += m[1];
        last = m.index + m[0].length;
      }
      text += raw.slice(last).replace(/[[\]]/g, '');
      return { key: k, ref: formatKey(k), text, hl };
    }),
  };
}

let LEXICON = null;
function lexicon() {
  if (!LEXICON) LEXICON = { ...readJson('strongs-hebrew.json'), ...readJson('strongs-greek.json') };
  return LEXICON;
}

/** Fiche d'un mot original (Strong) avec ses traductions en français et en anglais. */
function strongEntry(num) {
  num = String(num).toUpperCase().replace(/^([HG])0*/, '$1');
  const e = lexicon()[num];
  if (!e) throw httpError(404, `Numéro Strong inconnu : ${num}`);
  const usage = {};
  for (const id of VERSIONS.filter(x => x.strong).map(x => x.id)) {
    const s = strongIndex(load(id)).get(num);
    if (!s) continue;
    usage[id] = {
      occurrences: s.keys.length,
      words: [...s.words.entries()].sort((a, b) => b[1] - a[1]).slice(0, 30).map(([w, n]) => ({ word: w, count: n })),
    };
  }
  const related = [...new Set(`${e.deriv} ${e.def}`.match(/[HG]\d+/g) || [])]
    .filter(r => r !== num && lexicon()[r])
    .map(r => ({ num: r, lemma: lexicon()[r].lemma, translit: lexicon()[r].translit }));
  return { num, language: num[0] === 'H' ? 'hébreu / araméen' : 'grec', ...e, usage, related };
}

/** Trouve les numéros Strong traduits par un mot français / anglais / original. */
function strongLookup(word, id = DEFAULT_VERSION) {
  const w = normalize(word.trim());
  if (!w) return [];
  const out = new Map();
  const add = (num, score, via) => {
    const prev = out.get(num);
    if (!prev || prev.score < score) out.set(num, { num, score, via });
  };
  for (const [num, e] of strongIndex(load(id))) {
    for (const [tw, n] of e.words) if (normalize(tw) === w) add(num, n, tw);
  }
  const lex = lexicon();
  for (const [num, e] of Object.entries(lex)) {
    if (normalize(e.lemma) === w || normalize(e.translit) === w) add(num, 10000, e.lemma);
    else if (new RegExp(`(^|[^a-z])${w.replace(/[^a-z]/g, '')}([^a-z]|$)`).test(normalize(e.kjv)) && w.length > 2) add(num, 1, 'KJV : ' + e.kjv);
  }
  return [...out.values()].sort((a, b) => b.score - a.score).slice(0, 40)
    .map(x => ({ ...x, lemma: lex[x.num] && lex[x.num].lemma, translit: lex[x.num] && lex[x.num].translit, def: lex[x.num] && lex[x.num].def }));
}

let XREFS = null;
/** Références croisées d'un verset (OpenBible.info), triées par pertinence. */
function crossRefs(key, id = DEFAULT_VERSION, limit = 40) {
  if (!XREFS) XREFS = readJson('crossrefs.json');
  key = toStandardKey(id, key);
  const raw = XREFS[key];
  if (!raw) return { key, ref: formatKey(key), total: 0, refs: [] };
  const all = raw.split(';').map(s => { const i = s.lastIndexOf(':'); return [s.slice(0, i), +s.slice(i + 1)]; });
  return {
    key, ref: formatKey(key), total: all.length,
    refs: all.slice(0, limit).map(([target, votes]) => {
      const r = parseRefs(target)[0];
      const verses = r ? rangeVerses(id, r, 6) : [];
      return { target, ref: r ? formatRef(r) : target, votes, text: verses.map(x => plain(x.text)).join(' ') };
    }).filter(x => x.text),
  };
}

/** Un même passage dans toutes les versions qui le contiennent. */
function compare(ref, ids) {
  const refs = parseRefs(ref);
  if (!refs.length) throw httpError(400, `Référence non reconnue : « ${ref} »`);
  const list = (ids && ids.length ? ids : VERSIONS.map(v => v.id))
    .filter(id => { const m = versionMeta(id); return m && refs.some(r => m.books && m.books[r.book]); });
  return {
    ref: refs.map(r => formatRef(r)).join(' ; '),
    versions: list.map(id => {
      const meta = versionMeta(id);
      if (!meta) return null;
      const verses = refs.flatMap(r => rangeVerses(id, r, 60));
      return verses.length ? { id, name: meta.name, short: meta.short, lang: meta.lang, dir: meta.dir, verses } : null;
    }).filter(Boolean),
  };
}

let VOCAB = null;
/** Vocabulaire d'une version française, pour l'aide à l'écriture (rimes). */
function vocabulary(id = DEFAULT_VERSION) {
  if (VOCAB && VOCAB.id === id) return VOCAB.words;
  const counts = new Map();
  for (const x of load(id).verses) {
    for (const w of plain(x.raw).toLowerCase().match(/[\p{L}]+(?:-[\p{L}]+)*/gu) || []) {
      if (w.length < 3) continue;
      counts.set(w, (counts.get(w) || 0) + 1);
    }
  }
  VOCAB = { id, words: counts };
  return counts;
}

/** Terminaison « phonétique » simplifiée pour la rime française (approximative). */
function rhymeKey(w) {
  let s = w.toLowerCase().replace(/[sx]$/, '');
  if (s.length > 4) s = s.replace(/ent$/, 'e');
  s = s.replace(/(ée|é|ez|er|ai)$/, '#');
  s = normalize(s).replace(/[^a-z#]/g, '').replace(/e$/, '');
  s = s.replace(/eau|au/g, 'o').replace(/ai|ei/g, 'e').replace(/y/g, 'i').replace(/ph/g, 'f').replace(/qu|c(?=[aou])|k/g, 'k');
  return s.slice(-3);
}

function rhymes(word, id = DEFAULT_VERSION, limit = 80) {
  const k = rhymeKey(word);
  const k2 = k.slice(-2);
  const base = normalize(word);
  const out = [];
  for (const [w, n] of vocabulary(id)) {
    if (normalize(w) === base) continue;
    const rk = rhymeKey(w);
    if (rk === k) out.push({ word: w, count: n, rich: true });
    else if (k2.length === 2 && rk.endsWith(k2)) out.push({ word: w, count: n, rich: false });
  }
  return out.sort((a, b) => (b.rich - a.rich) || b.count - a.count).slice(0, limit);
}

/** Mots les plus fréquents d'une liste de versets (palette pour l'écriture). */
const STOP = new Set(('les des que qui pour dans par sur pas est son ses une aux mais avec vous nous ils elle elles leur leurs tout tous toute toutes ont été être fait faits dit alors après avait était aussi sera seront peut sont vers devant grand car cette ces lui moi toi mon ton votre vos notre nos même donc comme ainsi point plus ceux celui celle ' +
  'the and that for unto with his her them they shall have which not him are was were from thou thee thy this all but there their').split(' '));
function wordPalette(texts, limit = 40) {
  const counts = new Map();
  for (const t of texts) {
    for (const w of plain(t).toLowerCase().match(/[\p{L}]{4,}/gu) || []) {
      if (STOP.has(w)) continue;
      counts.set(w, (counts.get(w) || 0) + 1);
    }
  }
  return [...counts.entries()].sort((a, b) => b[1] - a[1]).slice(0, limit).map(([word, count]) => ({ word, count }));
}

module.exports = {
  VERSIONS, DEFAULT_VERSION, versionMeta, books, versionsWithBook, chapter, passage, rangeVerses, search, strongSearch,
  mapRef, strongEntry, strongLookup, crossRefs, compare, rhymes, wordPalette, getVerse, httpError, load, bookData,
};
