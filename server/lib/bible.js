'use strict';
/** Accès aux Bibles : passages, recherche, Strong, références croisées. */
const fs = require('fs');
const path = require('path');
const { normalize, normalizeWithMap, plain, strongTokens, compileQuery, matchQuery } = require('./text');
const { BOOKS, BY_ID, parseRefs, formatRef, formatKey, findBook } = require('./refs');

const DATA = path.join(__dirname, '../../data');
const readJson = f => JSON.parse(fs.readFileSync(path.join(DATA, f), 'utf8'));

const VERSIONS = readJson('versions.json');
const DEFAULT_VERSION = 'JND';
const cache = new Map();

function versionMeta(id) {
  return VERSIONS.find(v => v.id === id) || null;
}

/** Charge une version (à la demande) et prépare son index de recherche. */
function load(id) {
  if (cache.has(id)) return cache.get(id);
  const meta = versionMeta(id);
  if (!meta) throw httpError(404, `Version inconnue : ${id}`);
  const data = readJson(path.join('bibles', id + '.json'));
  const verses = []; // { key, book, c, v, raw }
  for (const b of BOOKS) {
    const chapters = data.books[b.id];
    if (!chapters) continue;
    chapters.forEach((ch, ci) => ch.forEach((raw, vi) => {
      if (raw) verses.push({ key: `${b.id}.${ci + 1}.${vi + 1}`, book: b.id, c: ci + 1, v: vi + 1, raw });
    }));
  }
  const v = { meta, data, verses, norm: null, strong: null };
  cache.set(id, v);
  return v;
}

function normIndex(v) {
  if (!v.norm) v.norm = v.verses.map(x => normalize(plain(x.raw)));
  return v.norm;
}

function httpError(status, message) {
  const e = new Error(message);
  e.status = status;
  return e;
}

function getVerse(id, key) {
  const v = load(id);
  const [b, c, n] = key.split('.');
  const ch = v.data.books[b] && v.data.books[b][c - 1];
  return ch ? ch[n - 1] || null : null;
}

function chapterCount(id, book) {
  const v = load(id);
  return (v.data.books[book] || []).length;
}

/** Livres disponibles pour une version, avec le nombre de chapitres. */
function books(id = DEFAULT_VERSION) {
  const v = load(id);
  return BOOKS.filter(b => v.data.books[b.id]).map(b => ({
    id: b.id, fr: b.fr, en: b.en, cat: b.cat, chapters: v.data.books[b.id].length,
  }));
}

/** Un chapitre complet avec titres de section et notes. */
function chapter(id, book, c) {
  const v = load(id);
  const chs = v.data.books[book];
  if (!chs) throw httpError(404, `Le livre ${book} n'existe pas dans ${id}`);
  const ch = chs[c - 1];
  if (!ch) throw httpError(404, `Chapitre ${c} introuvable`);
  return {
    version: id, book, chapter: +c, name: BY_ID.get(book).fr, chapters: chs.length,
    verses: ch.map((raw, i) => {
      const key = `${book}.${c}.${i + 1}`;
      return { v: i + 1, key, text: raw, title: v.data.titles[key] || null, notes: v.data.notes[key] || null };
    }).filter(x => x.text),
  };
}

/** Versets d'une plage de référence. */
function rangeVerses(id, r, max = 400) {
  const v = load(id);
  const chs = v.data.books[r.book];
  if (!chs) return [];
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
  const v = load(id);
  if (!v.meta.strong) throw httpError(400, `La version ${id} n'a pas de numéros Strong (essayez JND ou KJVA).`);
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
  const list = ids && ids.length ? ids : VERSIONS.map(v => v.id);
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
  VERSIONS, DEFAULT_VERSION, versionMeta, books, chapter, passage, rangeVerses, search, strongSearch,
  strongEntry, strongLookup, crossRefs, compare, rhymes, wordPalette, getVerse, chapterCount, httpError, load,
};
