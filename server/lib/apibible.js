'use strict';
/**
 * Connecteur facultatif vers API.Bible (https://scripture.api.bible).
 *
 * Il permet d'afficher des traductions protégées (Segond 21, Semeur, NBS, Parole de Vie…)
 * pour lesquelles l'éditeur a donné son accord à API.Bible — sans jamais stocker leur texte.
 *
 * Configuration (variables d'environnement ou fichier .env à la racine du projet) :
 *   API_BIBLE_KEY=<votre clé>                      (obligatoire)
 *   API_BIBLE_URL=https://rest.api.bible           (point de terminaison, par défaut)
 *   API_BIBLE_LANGS=fra                            (langues découvertes automatiquement, ex. "fra,eng")
 *   API_BIBLE_VERSIONS="S21=<bibleId>|Segond 21|Bible Segond 21|fr"   (facultatif : liste explicite)
 * Sans API_BIBLE_VERSIONS, toutes les Bibles des langues choisies auxquelles la clé donne accès sont ajoutées.
 *
 * Conditions d'API.Bible : afficher le copyright de chaque passage, ne pas mettre le texte
 * en cache durablement, et intégrer leur script de suivi (FUMS) côté client.
 */
const { BOOKS } = require('./refs');

/** Point de terminaison : https://rest.api.bible -> https://rest.api.bible/v1 */
function base() {
  const url = (process.env.API_BIBLE_URL || 'https://rest.api.bible').replace(/\/+$/, '');
  return /\/v\d+$/.test(url) ? url : url + '/v1';
}
const LANG2 = { fra: 'fr', eng: 'en', spa: 'es', deu: 'de', ita: 'it', por: 'pt', rus: 'ru', arb: 'ar', ara: 'ar', lin: 'ln', hat: 'ht', ewe: 'ee', hau: 'ha', ibo: 'ig', swh: 'sw', heb: 'he', grc: 'grc', lat: 'la' };
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

const remote = new Map(); // id -> métadonnées de version (même forme que versions.json)
const cache = new Map();
const CACHE_MS = 10 * 60 * 1000;

async function call(pathname, fetchImpl = fetch) {
  const hit = cache.get(pathname);
  if (hit && hit.until > Date.now()) return hit.data;
  const res = await fetchImpl(base() + pathname, { headers: { 'api-key': process.env.API_BIBLE_KEY || '', accept: 'application/json' } });
  if (!res.ok) {
    const detail = typeof res.text === 'function' ? await res.text().catch(() => '') : '';
    const blocked = /allowlist|proxy|egress/i.test(detail);
    const why = blocked ? `accès réseau bloqué (${detail.slice(0, 120)})`
      : res.status === 401 || res.status === 403 ? 'clé refusée ou accès non autorisé à cette Bible'
        : res.status === 429 ? 'quota dépassé' : `erreur ${res.status}`;
    const e = new Error(`API.Bible : ${why}`);
    e.status = res.status === 404 ? 404 : 502;
    e.httpStatus = res.status;
    throw e;
  }
  const data = (await res.json()).data;
  cache.set(pathname, { data, until: Date.now() + CACHE_MS });
  if (cache.size > 500) cache.delete(cache.keys().next().value);
  return data;
}

/** Identifiant court et unique pour une Bible distante (ex. « S21 »). */
function makeId(abbr, taken) {
  const baseId = String(abbr || 'AB').toUpperCase().normalize('NFD').replace(/[^A-Z0-9]/g, '').slice(0, 10) || 'AB';
  let id = taken.has(baseId) ? baseId + '-AB' : baseId;
  for (let i = 2; taken.has(id); i++) id = `${baseId}-AB${i}`;
  taken.add(id);
  return id;
}

/** Liste des Bibles à charger : explicite (API_BIBLE_VERSIONS) ou découverte par langue. */
async function discover(fetchImpl, taken) {
  const explicit = parseConfig();
  if (explicit.length) return explicit.map(v => ({ ...v, id: makeId(v.id, taken), info: null }));
  const langs = (process.env.API_BIBLE_LANGS || 'fra').split(/[,\s]+/).filter(Boolean);
  const max = +process.env.API_BIBLE_MAX || 40;
  const out = [];
  for (const lang of langs) {
    const bibles = await call(`/bibles?language=${encodeURIComponent(lang)}`, fetchImpl);
    for (const b of bibles || []) {
      if (out.length >= max) break;
      const short = b.abbreviationLocal || b.abbreviation || b.id;
      out.push({
        id: makeId(short, taken), bibleId: b.id, short, name: b.nameLocal || b.name,
        lang: LANG2[(b.language && b.language.id) || lang] || lang, info: b,
      });
    }
  }
  return out;
}

/**
 * Charge les Bibles accessibles avec la clé (au démarrage).
 * reserved : identifiants déjà utilisés par les versions locales (pour éviter les doublons).
 */
async function init(fetchImpl = fetch, reserved = []) {
  remote.clear();
  if (!process.env.API_BIBLE_KEY) return [];
  const taken = new Set(reserved);
  const list = await discover(fetchImpl, taken);
  for (const v of list) {
    try {
      const [info, books] = await Promise.all([
        v.info ? v.info : call(`/bibles/${v.bibleId}`, fetchImpl),
        call(`/bibles/${v.bibleId}/books?include-chapters=true`, fetchImpl),
      ]);
      const counts = {};
      for (const b of books) {
        const osis = USFM_TO_OSIS[b.id];
        if (osis) counts[osis] = (b.chapters || []).filter(c => /^\d+$/.test(c.number)).length;
      }
      if (!Object.keys(counts).length) continue;
      remote.set(v.id, {
        id: v.id, name: v.name || info.nameLocal || info.name, short: v.short, lang: v.lang, dir: info.dir === 'rtl' ? 'rtl' : 'ltr', year: null,
        remote: true, bibleId: v.bibleId,
        license: (info.copyright || 'Texte protégé — affiché via API.Bible').replace(/<[^>]*>/g, '').trim(),
        description: `${info.descriptionLocal || info.description || info.nameLocal || info.name || ''} — servie en direct par API.Bible.`.replace(/^ — /, ''),
        strong: false, stats: { books: Object.keys(counts).length, verses: null }, source: 'https://scripture.api.bible', books: counts,
      });
    } catch (e) {
      console.warn(`API.Bible : impossible de charger ${v.short} (${e.message})`);
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

module.exports = { init, base, makeId, isRemote, meta, list, chapter, rangeVerses, search, parseConfig, versesFromContent };
