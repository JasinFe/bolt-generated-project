'use strict';
/**
 * Connecteur facultatif vers API.Bible (https://scripture.api.bible).
 *
 * Il permet d'afficher des traductions protégées (Segond 21, Semeur, NBS, Parole de Vie…)
 * pour lesquelles l'éditeur a donné son accord à API.Bible — sans jamais stocker leur texte.
 *
 * Configuration (variables d'environnement) :
 *   API_BIBLE_KEY=<votre clé>
 *   API_BIBLE_VERSIONS="S21=<bibleId>|Segond 21|Bible Segond 21|fr; PDV=<bibleId>|Parole de Vie|Parole de Vie 2017|fr"
 * Les identifiants <bibleId> se trouvent dans votre tableau de bord API.Bible.
 *
 * Conditions d'API.Bible : afficher le copyright de chaque passage, ne pas mettre le texte
 * en cache durablement, et intégrer leur script de suivi (FUMS) côté client.
 */
const { BOOKS } = require('./refs');

const BASE = process.env.API_BIBLE_URL || 'https://api.scripture.api.bible/v1';
const OSIS_TO_USFM = {
  Gen: 'GEN', Exod: 'EXO', Lev: 'LEV', Num: 'NUM', Deut: 'DEU', Josh: 'JOS', Judg: 'JDG', Ruth: 'RUT', '1Sam': '1SA', '2Sam': '2SA',
  '1Kgs': '1KI', '2Kgs': '2KI', '1Chr': '1CH', '2Chr': '2CH', Ezra: 'EZR', Neh: 'NEH', Esth: 'EST', Job: 'JOB', Ps: 'PSA', Prov: 'PRO',
  Eccl: 'ECC', Song: 'SNG', Isa: 'ISA', Jer: 'JER', Lam: 'LAM', Ezek: 'EZK', Dan: 'DAN', Hos: 'HOS', Joel: 'JOL', Amos: 'AMO',
  Obad: 'OBA', Jonah: 'JON', Mic: 'MIC', Nah: 'NAM', Hab: 'HAB', Zeph: 'ZEP', Hag: 'HAG', Zech: 'ZEC', Mal: 'MAL',
  Matt: 'MAT', Mark: 'MRK', Luke: 'LUK', John: 'JHN', Acts: 'ACT', Rom: 'ROM', '1Cor': '1CO', '2Cor': '2CO', Gal: 'GAL', Eph: 'EPH',
  Phil: 'PHP', Col: 'COL', '1Thess': '1TH', '2Thess': '2TH', '1Tim': '1TI', '2Tim': '2TI', Titus: 'TIT', Phlm: 'PHM', Heb: 'HEB',
  Jas: 'JAS', '1Pet': '1PE', '2Pet': '2PE', '1John': '1JN', '2John': '2JN', '3John': '3JN', Jude: 'JUD', Rev: 'REV',
  Tob: 'TOB', Jdt: 'JDT', Wis: 'WIS', Sir: 'SIR', Bar: 'BAR', '1Macc': '1MA', '2Macc': '2MA',
};
const USFM_TO_OSIS = Object.fromEntries(Object.entries(OSIS_TO_USFM).map(([o, u]) => [u, o]));

/** Analyse API_BIBLE_VERSIONS. */
function parseConfig(str = process.env.API_BIBLE_VERSIONS || '') {
  return str.split(';').map(s => s.trim()).filter(Boolean).map(entry => {
    const [id, rest = ''] = entry.split('=');
    const [bibleId, short, name, lang] = rest.split('|').map(x => (x || '').trim());
    return { id: id.trim().toUpperCase(), bibleId, short: short || id.trim(), name: name || short || id.trim(), lang: lang || 'fr' };
  }).filter(v => v.id && v.bibleId);
}

const configured = process.env.API_BIBLE_KEY ? parseConfig() : [];
const remote = new Map(); // id -> métadonnées de version (même forme que versions.json)
const cache = new Map();
const CACHE_MS = 10 * 60 * 1000;

async function call(pathname, fetchImpl = fetch) {
  const hit = cache.get(pathname);
  if (hit && hit.until > Date.now()) return hit.data;
  const res = await fetchImpl(BASE + pathname, { headers: { 'api-key': process.env.API_BIBLE_KEY || '' } });
  if (!res.ok) {
    const e = new Error(`API.Bible : erreur ${res.status}`);
    e.status = res.status === 404 ? 404 : 502;
    throw e;
  }
  const data = (await res.json()).data;
  cache.set(pathname, { data, until: Date.now() + CACHE_MS });
  if (cache.size > 500) cache.delete(cache.keys().next().value);
  return data;
}

/** Récupère la liste des livres de chaque version configurée (au démarrage). */
async function init(fetchImpl = fetch) {
  for (const v of configured) {
    try {
      const [info, books] = await Promise.all([call(`/bibles/${v.bibleId}`, fetchImpl), call(`/bibles/${v.bibleId}/books?include-chapters=true`, fetchImpl)]);
      const counts = {};
      for (const b of books) {
        const osis = USFM_TO_OSIS[b.id];
        if (osis) counts[osis] = (b.chapters || []).filter(c => /^\d+$/.test(c.number)).length;
      }
      remote.set(v.id, {
        id: v.id, name: v.name || info.name, short: v.short, lang: v.lang, dir: 'ltr', year: null, remote: true, bibleId: v.bibleId,
        license: (info.copyright || 'Texte protégé — affiché via API.Bible').replace(/<[^>]*>/g, ''),
        description: `${info.nameLocal || info.name} — servi en direct par API.Bible (lecture et comparaison).`,
        strong: false, stats: { books: Object.keys(counts).length, verses: null }, source: 'https://scripture.api.bible', books: counts,
      });
    } catch (e) {
      console.warn(`API.Bible : impossible de charger ${v.id} (${e.message})`);
    }
  }
  return [...remote.values()];
}

const isRemote = id => remote.has(id);
const meta = id => remote.get(id) || null;
const list = () => [...remote.values()];

/** Extrait le texte verset par verset du contenu JSON d'API.Bible. */
function versesFromContent(content) {
  const out = new Map();
  const walk = items => {
    for (const it of items || []) {
      if (it.type === 'text' && it.attrs && it.attrs.verseId) {
        const k = it.attrs.verseId;
        out.set(k, ((out.get(k) || '') + it.text));
      }
      if (it.items) walk(it.items);
    }
  };
  walk(content);
  return [...out.entries()].map(([id, text]) => {
    const [, c, v] = id.split('.');
    return { c: +c, v: +v, text: text.replace(/\s+/g, ' ').trim() };
  }).filter(x => x.text);
}

async function chapter(id, book, c, fetchImpl = fetch) {
  const m = meta(id);
  const usfm = OSIS_TO_USFM[book];
  if (!m || !usfm || !m.books[book]) { const e = new Error(`Le livre ${book} n'existe pas dans ${id}`); e.status = 404; throw e; }
  const data = await call(`/bibles/${m.bibleId}/chapters/${usfm}.${c}?content-type=json&include-notes=false&include-titles=true&include-verse-numbers=false`, fetchImpl);
  const name = (BOOKS.find(b => b.id === book) || {}).fr || book;
  return {
    version: id, book, chapter: +c, name, chapters: m.books[book], copyright: data.copyright || m.license,
    verses: versesFromContent(data.content).filter(x => x.c === +c).map(x => ({ v: x.v, key: `${book}.${c}.${x.v}`, text: x.text, title: null, notes: null })),
  };
}

/** Versets d'une plage (références déjà analysées par refs.js). */
async function rangeVerses(id, r, max = 400, fetchImpl = fetch) {
  const out = [];
  const m = meta(id);
  if (!m || !m.books[r.book]) return out;
  const endC = Math.min(r.endChapter || r.chapter, m.books[r.book]);
  for (let c = r.chapter; c <= endC; c++) {
    const ch = await chapter(id, r.book, c, fetchImpl);
    for (const x of ch.verses) {
      if (c === r.chapter && r.verse != null && x.v < r.verse) continue;
      if (c === endC && r.endVerse != null && x.v > r.endVerse) continue;
      out.push({ key: x.key, c, v: x.v, text: x.text });
      if (out.length >= max) return out;
    }
  }
  return out;
}

/** Recherche plein texte déléguée à API.Bible. */
async function search(id, q, opts = {}, fetchImpl = fetch) {
  const m = meta(id);
  const limit = Math.min(+opts.limit || 50, 100);
  const offset = +opts.offset || 0;
  const data = await call(`/bibles/${m.bibleId}/search?query=${encodeURIComponent(q)}&limit=${limit}&offset=${offset}`, fetchImpl);
  const verses = (data.verses || []).map(v => {
    const [u, c, n] = String(v.id).split('.');
    const book = USFM_TO_OSIS[u];
    const name = (BOOKS.find(b => b.id === book) || {}).fr || u;
    return book ? { key: `${book}.${c}.${n}`, ref: `${name} ${c}:${n}`, text: String(v.text || '').replace(/<[^>]*>/g, ''), hl: [] } : null;
  }).filter(Boolean);
  return { version: id, query: q, total: data.total || verses.length, offset, limit, byBook: [], results: verses, remote: true };
}

module.exports = { init, isRemote, meta, list, chapter, rangeVerses, search, parseConfig, versesFromContent };
