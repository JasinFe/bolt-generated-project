'use strict';
/** Livres de la Bible et analyse des références (« Jean 3:16-18 ; 1 Co 13 »). */
const path = require('path');
const fs = require('fs');
const { normalize } = require('./text');

const BOOKS = JSON.parse(fs.readFileSync(path.join(__dirname, '../../data/books.json'), 'utf8'));
const BY_ID = new Map(BOOKS.map((b, i) => [b.id, { ...b, order: i }]));

const key = s => normalize(s).replace(/[\s.'’-]/g, '').replace(/^iii/, '3').replace(/^ii/, '2').replace(/^i(?=[^ns])/, '1');

const EXACT = new Map();
for (const b of BOOKS) {
  for (const alias of [b.id, b.fr, b.en, b.sm, ...b.abbr]) {
    const k = key(alias);
    if (!EXACT.has(k)) EXACT.set(k, b.id);
  }
  // « 1 Rois » -> aussi « 1er rois », « premier »
  const m = b.fr.match(/^([1-4]) (.+)$/);
  if (m) {
    const words = { 1: ['premier', 'premiere', '1er', '1re'], 2: ['deuxieme', 'second', 'seconde', '2e'], 3: ['troisieme', '3e'], 4: ['quatrieme', '4e'] }[m[1]];
    for (const w of words) { const k = key(w + m[2]); if (!EXACT.has(k)) EXACT.set(k, b.id); }
  }
}
const PREFIX = BOOKS.flatMap(b => [[key(b.fr), b.id], [key(b.en), b.id]]);

/** Trouve l'identifiant OSIS d'un nom de livre (français, anglais, abréviation). */
function findBook(name) {
  const k = key(name);
  if (!k) return null;
  if (EXACT.has(k)) return EXACT.get(k);
  if (k.length < 3) return null;
  for (const [full, id] of PREFIX) if (full.startsWith(k)) return id;
  return null;
}

/**
 * Analyse une ou plusieurs références.
 * Renvoie [{ book, chapter, verse, endChapter, endVerse }] (verse null = chapitre entier).
 */
function parseRefs(input) {
  const out = [];
  if (!input) return out;
  let lastBook = null;
  for (let part of String(input).split(/\s*;\s*|\n/)) {
    part = part.trim().replace(/\s+/g, ' ');
    if (!part) continue;
    // Format OSIS : John.3.16 ou John.3.16-John.3.18
    const osis = part.match(/^([1-4]?[A-Za-z]+)\.(\d+)(?:\.(\d+))?(?:-(?:([1-4]?[A-Za-z]+)\.)?(\d+)(?:\.(\d+))?)?$/);
    if (osis && BY_ID.has(osis[1])) {
      const [, b, c, v, , c2, v2] = osis;
      const r = { book: b, chapter: +c, verse: v ? +v : null, endChapter: +c, endVerse: v ? +v : null };
      if (c2 && v2) { r.endChapter = +c2; r.endVerse = +v2; } else if (c2) { if (v) r.endVerse = +c2; else r.endChapter = +c2; }
      out.push(r); lastBook = b; continue;
    }
    const m = part.match(/^((?:[1-4]|i{1,3}|iv|1er|1re|premier|premi[eè]re|deuxi[eè]me|second|troisi[eè]me)?\s*[^\d\s][^\d]*?)?\s*(\d+)(?:\s*(?:[:.,]|v\.?)\s*(\d+))?\s*(.*)$/i);
    if (!m) continue;
    let book = lastBook;
    if (m[1] && m[1].trim()) {
      book = findBook(m[1]);
      if (!book) continue;
    } else if (!lastBook) continue;
    const chapter = +m[2];
    const verse = m[3] ? +m[3] : null;
    const rest = m[4] || '';
    const base = { book, chapter, verse, endChapter: chapter, endVerse: verse };
    const range = rest.match(/^[-–]\s*(\d+)(?:\s*[:.]\s*(\d+))?/);
    let tail = rest;
    if (range) {
      if (range[2]) { base.endChapter = +range[1]; base.endVerse = +range[2]; }
      else if (verse != null) base.endVerse = +range[1];
      else base.endChapter = +range[1];
      tail = rest.slice(range[0].length);
    }
    out.push(base);
    // Versets supplémentaires : « Jean 3:16, 18-20 »
    for (const extra of tail.matchAll(/,\s*(\d+)(?:\s*[-–]\s*(\d+))?/g)) {
      const a = +extra[1];
      const b2 = extra[2] ? +extra[2] : a;
      out.push({ book, chapter: base.endChapter, verse: a, endChapter: base.endChapter, endVerse: b2 });
    }
    lastBook = book;
  }
  return out;
}

function bookName(id, lang = 'fr') {
  const b = BY_ID.get(id);
  return b ? b[lang] || b.fr : id;
}

/** Libellé lisible d'une référence. */
function formatRef(r, lang = 'fr') {
  let s = `${bookName(r.book, lang)} ${r.chapter}`;
  if (r.verse != null) s += `:${r.verse}`;
  if (r.endChapter !== r.chapter) s += `-${r.endChapter}${r.endVerse != null ? ':' + r.endVerse : ''}`;
  else if (r.endVerse != null && r.endVerse !== r.verse) s += `-${r.endVerse}`;
  return s;
}

/** « John.3.16 » -> libellé français */
function formatKey(k, lang = 'fr') {
  const [b, c, v] = k.split('.');
  return `${bookName(b, lang)} ${c}${v ? ':' + v : ''}`;
}

module.exports = { BOOKS, BY_ID, findBook, parseRefs, formatRef, formatKey, bookName };
