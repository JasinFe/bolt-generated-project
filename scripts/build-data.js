#!/usr/bin/env node
/**
 * Mister Preacher — construction des données bibliques.
 *
 * Télécharge (ou lit localement) les textes libres de droits du projet
 * scrollmapper/bible_databases, les références croisées d'OpenBible.info et
 * les dictionnaires Strong d'Open Scriptures, puis les convertit dans le
 * format compact utilisé par le serveur (dossier data/).
 *
 * Usage :
 *   node scripts/build-data.js                  # toutes les versions du catalogue
 *   node scripts/build-data.js JND KJVA         # seulement certaines versions
 *   MP_SOURCES=/chemin/vers/bible_databases node scripts/build-data.js
 *
 * Pour ajouter une version : ajoutez une entrée dans VERSIONS ci-dessous
 * (n'importe laquelle des ~140 traductions de scrollmapper fonctionne).
 *
 * Format d'un verset :
 *   "{mot|H7225} texte [mot ajouté]"  ->  {…|Strong}  et  […] = mots en italique
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const DATA = path.join(ROOT, 'data');
const RAW = 'https://raw.githubusercontent.com/scrollmapper/bible_databases/master/';
const STRONG_RAW = 'https://raw.githubusercontent.com/openscriptures/strongs/master/';

const VERSIONS = [
  { id: 'JND', src: 'sources/fr/FreJND/FreJND-osis.json', name: 'Bible J.N. Darby (1885)', short: 'Darby', lang: 'fr', year: 1885,
    license: 'Domaine public', strong: true, description: 'Traduction littérale très prisée pour l\'étude, avec numéros Strong et notes de Darby.' },
  { id: 'MAR', src: 'sources/fr/FreBDM1744/FreBDM1744-osis.json', name: 'Bible David Martin (1744)', short: 'Martin', lang: 'fr', year: 1744,
    license: 'Domaine public', description: 'Révision protestante de la Bible de Genève, avec titres de sections.' },
  { id: 'CRA', src: 'sources/fr/FreCrampon/FreCrampon.json', name: 'Bible Augustin Crampon (1923)', short: 'Crampon', lang: 'fr', year: 1923,
    license: 'Domaine public', description: 'Traduction catholique incluant les livres deutérocanoniques.' },
  { id: 'LXX', src: 'sources/fr/FreLXXGiguet/FreLXXGiguet.json', name: 'Septante — trad. Giguet (1872)', short: 'Septante', lang: 'fr', year: 1872,
    license: 'Domaine public', description: 'L\'Ancien Testament grec (LXX) traduit en français : apocryphes, Hénoch, 3–4 Maccabées, Psaumes de Salomon.' },
  { id: 'KJVA', src: 'sources/en/KJVA/KJVA-osis.json', name: 'King James Version + Apocrypha (1769)', short: 'KJV', lang: 'en', year: 1769,
    license: 'Domaine public (texte) — balisage CrossWire GPL', strong: true, description: 'La KJV avec numéros Strong et les apocryphes.' },
  { id: 'WLC', src: 'sources/hbo/WLC/WLC-osis.json', name: 'Codex de Leningrad (Westminster)', short: 'Hébreu', lang: 'he', dir: 'rtl',
    license: 'Domaine public', description: 'Texte hébreu massorétique de l\'Ancien Testament.' },
  { id: 'SRGNT', src: 'sources/grc/StatResGNT/StatResGNT-osis.json', name: 'Nouveau Testament grec (Statistical Restoration)', short: 'Grec', lang: 'grc', year: 2022,
    license: 'CC BY 4.0 — Alan Bunning, Center for New Testament Restoration', strong: true, description: 'Texte grec du Nouveau Testament avec numéros Strong.' },
  { id: 'VUL', src: 'sources/la/VulgClementine/VulgClementine.json', name: 'Vulgate Clémentine', short: 'Vulgate', lang: 'la', year: 1592,
    license: 'Domaine public', description: 'La Bible latine de l\'Église, avec deutérocanoniques.' },
];

const TITLE_TYPES = new Set(['x-s', 'psalm', 'section', 'acrostic', 'sub']);

async function fetchText(rel, base = RAW) {
  if (process.env.MP_SOURCES && base === RAW) {
    return fs.readFileSync(path.join(process.env.MP_SOURCES, rel), 'utf8');
  }
  const url = base + rel;
  for (let attempt = 1; ; attempt++) {
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`${res.status} ${url}`);
      return await res.text();
    } catch (e) {
      if (attempt >= 4) throw e;
      await new Promise(r => setTimeout(r, 2000 * attempt));
    }
  }
}

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
const decode = s => s.replace(/&(amp|lt|gt|quot|apos);/g, (_, e) => ENTITIES[e]);
const stripTags = s => s.replace(/<[^>]*>/g, '');
const clean = s => decode(s).replace(/[¶˚]/g, '').replace(/\s+/g, ' ').replace(/\s+([,.;:!?»)])/g, (m, p) => (/[;:!?»]/.test(p) ? m : p)).trim();
const normStrong = s => s.replace(/^([HG])0*(\d+)([a-z]?)$/i, (_, l, n, x) => l.toUpperCase() + n + x);

/** Convertit un verset OSIS en format compact. */
function convertVerse(raw) {
  const notes = [];
  const titles = [];
  // Les caractères de balisage compact sont neutralisés dans le texte source.
  let t = raw.replace(/[{}|]/g, m => ({ '{': '(', '}': ')', '|': '/' }[m])).replace(/\[/g, '(').replace(/\]/g, ')');
  t = t.replace(/<note\b[^>]*>([\s\S]*?)<\/note>/g, (_, inner) => {
    const n = clean(stripTags(inner));
    if (n) notes.push(n);
    return '';
  });
  t = t.replace(/<title\b([^>]*)>([\s\S]*?)<\/title>/g, (_, attrs, inner) => {
    const type = (attrs.match(/type="([^"]+)"/) || [])[1] || '';
    const txt = clean(stripTags(inner));
    if (txt && TITLE_TYPES.has(type)) titles.push(txt);
    return '';
  });
  t = t.replace(/<transChange\b[^>]*>([\s\S]*?)<\/transChange>/g, (_, inner) => `[${stripTags(inner)}]`);
  t = t.replace(/<w\b([^>]*)>([\s\S]*?)<\/w>/g, (_, attrs, inner) => {
    const lemma = (attrs.match(/lemma="([^"]+)"/) || [])[1] || '';
    const nums = [...lemma.matchAll(/strong:([HG]\d+[a-z]?)/gi)].map(m => normStrong(m[1]));
    const word = stripTags(inner).replace(/[[\]]/g, '');
    if (!nums.length || !word.trim()) return word;
    // La ponctuation reste hors du mot balisé : {κόσμον|G2889},
    const [, pre, core, post] = word.match(/^([\s«“"'(·]*)([\s\S]*?)([\s,.;:·!?»”"')]*)$/);
    if (!core) return word;
    return `${pre}{${core}|${[...new Set(nums)].join(',')}}${post}`;
  });
  t = clean(stripTags(t));
  return { text: t, notes, titles };
}

function plainLength(t) {
  return t.replace(/\{([^|}]*)\|[^}]*\}/g, '$1').replace(/[[\]]/g, '').length;
}

async function buildVersion(v, books) {
  process.stdout.write(`• ${v.id} (${v.src}) … `);
  const src = JSON.parse(await fetchText(v.src));
  const bySm = new Map(books.map(b => [b.sm, b]));
  const out = {
    id: v.id, name: v.name, short: v.short, lang: v.lang, dir: v.dir || 'ltr', year: v.year || null,
    license: v.license, strong: !!v.strong, description: v.description,
    source: 'https://github.com/scrollmapper/bible_databases/tree/master/' + path.dirname(v.src),
    books: {}, titles: {}, notes: {},
  };
  let verses = 0;
  for (const b of src.books) {
    const meta = bySm.get(b.name);
    if (!meta) { console.warn(`\n  livre inconnu ignoré : ${b.name}`); continue; }
    const chapters = [];
    for (const ch of b.chapters) {
      const arr = [];
      for (const vv of ch.verses) {
        const { text, notes, titles } = convertVerse(vv.text || '');
        const n = vv.verse;
        if (n === 0) { if (text) out.titles[`${meta.id}.${ch.chapter}.1`] = stripTags(text); continue; }
        while (arr.length < n - 1) arr.push('');
        arr[n - 1] = arr[n - 1] ? arr[n - 1] + ' ' + text : text;
        const key = `${meta.id}.${ch.chapter}.${n}`;
        if (notes.length) out.notes[key] = notes;
        if (titles.length) out.titles[key] = titles.join(' — ');
      }
      while (chapters.length < ch.chapter - 1) chapters.push([]);
      chapters[ch.chapter - 1] = arr;
    }
    // Retire les livres vides (certaines sources listent des livres sans texte).
    while (chapters.length && !chapters[chapters.length - 1].some(t => plainLength(t))) chapters.pop();
    if (!chapters.some(c => c.some(t => plainLength(t)))) continue;
    out.books[meta.id] = chapters;
    verses += chapters.reduce((s, c) => s + c.filter(Boolean).length, 0);
  }
  out.stats = { books: Object.keys(out.books).length, verses };
  fs.writeFileSync(path.join(DATA, 'bibles', v.id + '.json'), JSON.stringify(out));
  console.log(`${out.stats.books} livres, ${verses} versets`);
  return { ...v, stats: out.stats, source: out.source, dir: out.dir };
}

async function buildCrossRefs() {
  process.stdout.write('• Références croisées (OpenBible.info) … ');
  const txt = await fetchText('sources/extras/cross_references.txt');
  const map = {};
  let n = 0;
  for (const line of txt.split('\n').slice(1)) {
    const [from, to, votes] = line.trim().split('\t');
    if (!from || !to) continue;
    const v = parseInt(votes, 10);
    if (!(v > 0)) continue;
    (map[from] ||= []).push([to, v]);
    n++;
  }
  const out = {};
  for (const [k, list] of Object.entries(map)) {
    out[k] = list.sort((a, b) => b[1] - a[1]).map(([t, v]) => `${t}:${v}`).join(';');
  }
  fs.writeFileSync(path.join(DATA, 'crossrefs.json'), JSON.stringify(out));
  console.log(`${n} liens`);
}

async function buildStrongs() {
  for (const [lang, file] of [['hebrew', 'hebrew/strongs-hebrew-dictionary.js'], ['greek', 'greek/strongs-greek-dictionary.js']]) {
    process.stdout.write(`• Dictionnaire Strong ${lang} … `);
    const js = await fetchText(file, STRONG_RAW);
    const start = js.indexOf('{');
    const end = js.lastIndexOf('}');
    const dict = JSON.parse(js.slice(start, end + 1));
    const out = {};
    for (const [k, e] of Object.entries(dict)) {
      out[k] = {
        lemma: e.lemma || '', translit: e.xlit || e.translit || '', pron: e.pron || '',
        def: (e.strongs_def || '').trim(), deriv: (e.derivation || '').trim(), kjv: (e.kjv_def || '').trim(),
      };
    }
    fs.writeFileSync(path.join(DATA, `strongs-${lang}.json`), JSON.stringify(out));
    console.log(`${Object.keys(out).length} entrées`);
  }
}

async function main() {
  fs.mkdirSync(path.join(DATA, 'bibles'), { recursive: true });
  const books = JSON.parse(fs.readFileSync(path.join(DATA, 'books.json'), 'utf8'));
  const only = process.argv.slice(2);
  const targets = only.length ? VERSIONS.filter(v => only.includes(v.id)) : VERSIONS;
  const catalogPath = path.join(DATA, 'versions.json');
  const catalog = fs.existsSync(catalogPath) ? JSON.parse(fs.readFileSync(catalogPath, 'utf8')) : [];
  for (const v of targets) {
    const entry = await buildVersion(v, books);
    const { src, ...meta } = entry;
    const i = catalog.findIndex(c => c.id === v.id);
    if (i >= 0) catalog[i] = meta; else catalog.push(meta);
  }
  catalog.sort((a, b) => VERSIONS.findIndex(v => v.id === a.id) - VERSIONS.findIndex(v => v.id === b.id));
  fs.writeFileSync(catalogPath, JSON.stringify(catalog, null, 2));
  if (!only.length) {
    await buildCrossRefs();
    await buildStrongs();
  }
  console.log('Terminé.');
}

if (require.main === module) main().catch(e => { console.error(e); process.exit(1); });
module.exports = { convertVerse };
