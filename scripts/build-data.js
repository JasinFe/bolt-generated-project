#!/usr/bin/env node
/**
 * Mister Preacher — construction des données bibliques.
 *
 * Sources (uniquement des textes du domaine public ou sous licence libre) :
 *   - scrollmapper/bible_databases  (JSON OSIS, ~140 traductions)
 *   - Beblia/Holy-Bible-XML-Format  (XML ; on n'y prend QUE des éditions libres vérifiées)
 *   - BibleNLP/ebible               (corpus eBible.org, source du « Free Use Bible API »)
 *   - seven1m/open-bibles           (OSIS, source de bible-api.com)
 *   - OpenBible.info (références croisées), Open Scriptures (dictionnaires Strong)
 *
 * Usage :
 *   node scripts/build-data.js                  # toutes les versions du catalogue
 *   node scripts/build-data.js LSG JND          # seulement certaines versions
 *   MP_CACHE=/tmp/cache node scripts/build-data.js   # dossier de cache des téléchargements
 *
 * Ajouter une version : une entrée dans VERSIONS (kind = scrollmapper | beblia | ebible | osis).
 * Vérifiez TOUJOURS la licence : beaucoup de dépôts contiennent aussi des versions protégées.
 *
 * Format d'un verset :
 *   "{mot|H7225} texte [mot ajouté]"  ->  {…|Strong}  et  […] = mots en italique
 * Les fichiers sont écrits compressés, un par livre : data/bibles/<ID>/<Livre>.json.gz
 */
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = path.join(__dirname, '..');
const DATA = path.join(ROOT, 'data');
const CACHE = process.env.MP_CACHE || path.join(ROOT, '.cache', 'sources');
const RAW = {
  scrollmapper: 'https://raw.githubusercontent.com/scrollmapper/bible_databases/master/',
  beblia: 'https://raw.githubusercontent.com/Beblia/Holy-Bible-XML-Format/master/',
  ebible: 'https://raw.githubusercontent.com/BibleNLP/ebible/main/',
  osis: 'https://raw.githubusercontent.com/seven1m/open-bibles/master/',
  strong: 'https://raw.githubusercontent.com/openscriptures/strongs/master/',
};
const REPO = {
  scrollmapper: 'https://github.com/scrollmapper/bible_databases',
  beblia: 'https://github.com/Beblia/Holy-Bible-XML-Format',
  ebible: 'https://github.com/BibleNLP/ebible',
  osis: 'https://github.com/seven1m/open-bibles',
};

const PD = 'Domaine public';
const BIBLICA = 'CC BY-SA 4.0 — Biblica, Inc. (texte non modifié)';
const VERSIONS = [
  // ---------- Français ----------
  { id: 'JND', kind: 'scrollmapper', src: 'sources/fr/FreJND/FreJND-osis.json', name: 'Bible J.N. Darby (1885)', short: 'Darby', lang: 'fr', year: 1885,
    license: PD, strong: true, description: 'Traduction littérale très prisée pour l\'étude, avec numéros Strong et notes de Darby.' },
  { id: 'LSG', kind: 'beblia', src: 'FrenchBible.xml', name: 'Louis Segond (1910)', short: 'Segond 1910', lang: 'fr', year: 1910,
    license: PD, description: 'La traduction protestante de référence dans le monde francophone.' },
  { id: 'OST', kind: 'osis', src: 'fra-ostervald.osis.xml', name: 'Bible Ostervald (révision 1996)', short: 'Ostervald', lang: 'fr', year: 1996,
    license: 'Domaine public selon open-bibles / bible-api.com', description: 'Révision de la Bible d\'Ostervald (1744), elle-même issue d\'Olivétan (1535).' },
  { id: 'MAR', kind: 'scrollmapper', src: 'sources/fr/FreBDM1744/FreBDM1744-osis.json', name: 'Bible David Martin (1744)', short: 'Martin', lang: 'fr', year: 1744,
    license: PD, description: 'Révision protestante de la Bible de Genève, avec titres de sections.' },
  { id: 'PGR', kind: 'scrollmapper', src: 'sources/fr/FrePGR/FrePGR-osis.json', name: 'Bible Perret-Gentil et Rilliet (1847)', short: 'Perret-Gentil', lang: 'fr', year: 1847,
    license: PD, description: 'Traduction genevoise du XIXᵉ siècle, AT par Perret-Gentil, NT par Rilliet.' },
  { id: 'CRA', kind: 'scrollmapper', src: 'sources/fr/FreCrampon/FreCrampon.json', name: 'Bible Augustin Crampon (1923)', short: 'Crampon', lang: 'fr', year: 1923,
    license: PD, description: 'Traduction catholique incluant les livres deutérocanoniques.' },
  { id: 'NCL', kind: 'ebible', src: 'fra-francl', name: 'Sainte Bible néo-Crampon Libre', short: 'Néo-Crampon', lang: 'fr', year: 2022,
    license: 'CC BY-SA 4.0 — Fraternité de Tibériade', description: 'Modernisation libre de la Crampon, en français actuel, avec deutérocanoniques.' },
  { id: 'VIG', kind: 'beblia', src: 'FrenchVigourouxBible.xml', name: 'Bible Vigouroux (1902)', short: 'Vigouroux', lang: 'fr', year: 1902,
    license: PD, description: 'Traduction catholique de Fulcran Vigouroux, d\'après la Vulgate.' },
  { id: 'LXX', kind: 'scrollmapper', src: 'sources/fr/FreLXXGiguet/FreLXXGiguet.json', name: 'Septante — trad. Giguet (1872)', short: 'Septante', lang: 'fr', year: 1872,
    license: PD, description: 'L\'Ancien Testament grec (LXX) traduit en français : apocryphes, Hénoch, 3–4 Maccabées, Psaumes de Salomon.' },
  { id: 'SYN', kind: 'scrollmapper', src: 'sources/fr/FreSynodale1921/FreSynodale1921-osis.json', name: 'Version Synodale (1921) — NT et Psaumes', short: 'Synodale', lang: 'fr', year: 1921,
    license: PD, description: 'Nouveau Testament et Psaumes de la Société biblique de France.' },
  { id: 'OLT', kind: 'scrollmapper', src: 'sources/fr/FreOltramare1874/FreOltramare1874-osis.json', name: 'Nouveau Testament Oltramare (1874)', short: 'Oltramare', lang: 'fr', year: 1874,
    license: PD, description: 'Traduction du NT par Hugues Oltramare (Genève).' },
  { id: 'STA', kind: 'scrollmapper', src: 'sources/fr/FreStapfer1889/FreStapfer1889-osis.json', name: 'Nouveau Testament Stapfer (1889)', short: 'Stapfer', lang: 'fr', year: 1889,
    license: PD, description: 'Traduction du NT par Edmond Stapfer, au style vivant.' },
  { id: 'GEN', kind: 'scrollmapper', src: 'sources/fr/FreGeneve1669/FreGeneve1669-osis.json', name: 'Nouveau Testament de Genève (1669)', short: 'Genève 1669', lang: 'fr', year: 1669,
    license: PD, description: 'Le NT de la Bible de Genève, en français classique.' },
  // ---------- Langues d'Afrique et des Caraïbes ----------
  { id: 'LIN', kind: 'ebible', src: 'lin-lin', name: 'Biblia na Lingála (Mokanda na Bomoi, 2020)', short: 'Lingala', lang: 'ln', year: 2020,
    license: BIBLICA, description: 'Bible complète en lingala contemporain (RDC, Congo).' },
  { id: 'HAT', kind: 'beblia', src: 'HaitianBible.xml', name: 'Bib la an Kreyòl ayisyen (1985)', short: 'Kreyòl', lang: 'ht', year: 1985,
    license: 'Domaine public selon eBible.org', description: 'Bible complète en créole haïtien.' },
  { id: 'EWE', kind: 'beblia', src: 'Ewe2020Bible.xml', name: 'Biblia Kɔkɔe — Agbenya La (Éwé, 2020)', short: 'Éwé', lang: 'ee', year: 2020,
    license: BIBLICA, description: 'Bible complète en éwé (Togo, Ghana, Bénin).' },
  { id: 'TWI', kind: 'beblia', src: 'TwiAkuapemBible.xml', name: 'Akuapem Twi Nkwa Asɛm (2020)', short: 'Twi', lang: 'tw', year: 2020,
    license: BIBLICA, description: 'Bible complète en twi akuapem (Ghana).' },
  { id: 'HAU', kind: 'beblia', src: 'Hausa2013Bible.xml', name: 'Littafi Mai Tsarki — Sabon Rai Don Kowa (Haoussa, 2020)', short: 'Haoussa', lang: 'ha', year: 2020,
    license: BIBLICA, description: 'Bible complète en haoussa (Niger, Nigeria, Ghana, Cameroun, Tchad).' },
  { id: 'IBO', kind: 'beblia', src: 'Igbo2020Bible.xml', name: 'Baịbụlụ Nsọ — Igbo Contemporary Bible (2020)', short: 'Igbo', lang: 'ig', year: 2020,
    license: BIBLICA, description: 'Bible complète en igbo (Nigeria).' },
  // ---------- Autres langues ----------
  { id: 'KJVA', kind: 'scrollmapper', src: 'sources/en/KJVA/KJVA-osis.json', name: 'King James Version + Apocrypha (1769)', short: 'KJV', lang: 'en', year: 1769,
    license: 'Domaine public (texte) — balisage CrossWire GPL', strong: true, description: 'La KJV avec numéros Strong et les apocryphes.' },
  { id: 'BSB', kind: 'scrollmapper', src: 'sources/en/BSB/BSB-osis.json', name: 'Berean Standard Bible', short: 'BSB', lang: 'en', year: 2022,
    license: 'Domaine public (CC0)', strong: true, description: 'Traduction anglaise moderne, précise et lisible, avec numéros Strong.' },
  { id: 'DRC', kind: 'scrollmapper', src: 'sources/en/DRC/DRC-osis.json', name: 'Douay-Rheims (Challoner)', short: 'Douay-Rheims', lang: 'en', year: 1752,
    license: PD, description: 'Bible catholique anglaise, avec deutérocanoniques.' },
  { id: 'RVA', kind: 'scrollmapper', src: 'sources/es/SpaRV/SpaRV-osis.json', name: 'Reina-Valera (1909)', short: 'Reina-Valera', lang: 'es', year: 1909,
    license: PD, description: 'La Bible protestante classique en espagnol.' },
  { id: 'LUT', kind: 'osis', src: 'deu-luther1912.osis.xml', name: 'Lutherbibel (1912)', short: 'Luther 1912', lang: 'de', year: 1912,
    license: PD, description: 'La Bible de Luther, révision de 1912.' },
  { id: 'RIV', kind: 'osis', src: 'ita-riveduta.osis.xml', name: 'Bibbia Riveduta (1927)', short: 'Riveduta', lang: 'it', year: 1927,
    license: PD, description: 'Traduction italienne de Giovanni Luzzi.' },
  { id: 'RUS', kind: 'scrollmapper', src: 'sources/ru/RusSynodal/RusSynodal-osis.json', name: 'Синодальный перевод (1876)', short: 'Synodale russe', lang: 'ru', year: 1876,
    license: PD, description: 'La Bible synodale russe.' },
  { id: 'SVD', kind: 'beblia', src: 'ArabicSVDBible.xml', name: 'الكتاب المقدس — Van Dyck (1865)', short: 'Arabe', lang: 'ar', dir: 'rtl', year: 1865,
    license: PD, description: 'La traduction arabe de référence (Smith & Van Dyck).' },
  // ---------- Langues originales et anciennes ----------
  { id: 'WLC', kind: 'scrollmapper', src: 'sources/hbo/WLC/WLC-osis.json', name: 'Codex de Leningrad (Westminster)', short: 'Hébreu', lang: 'he', dir: 'rtl',
    license: PD, description: 'Texte hébreu massorétique de l\'Ancien Testament.' },
  { id: 'SRGNT', kind: 'scrollmapper', src: 'sources/grc/StatResGNT/StatResGNT-osis.json', name: 'Nouveau Testament grec (Statistical Restoration)', short: 'Grec', lang: 'grc', year: 2022,
    license: 'CC BY 4.0 — Alan Bunning, Center for New Testament Restoration', strong: true, description: 'Texte grec du Nouveau Testament avec numéros Strong.' },
  { id: 'TR', kind: 'ebible', src: 'grc-grctr', name: 'Textus Receptus (1550/1894)', short: 'Textus Receptus', lang: 'grc', year: 1894,
    license: PD, description: 'Le texte grec qui a servi de base à la KJV, Martin, Ostervald et la Segond.' },
  { id: 'VUL', kind: 'scrollmapper', src: 'sources/la/VulgClementine/VulgClementine.json', name: 'Vulgate Clémentine', short: 'Vulgate', lang: 'la', year: 1592,
    license: PD, description: 'La Bible latine de l\'Église, avec deutérocanoniques.' },
];

/** Codes de livres USFM (eBible) -> OSIS */
const USFM = {
  GEN: 'Gen', EXO: 'Exod', LEV: 'Lev', NUM: 'Num', DEU: 'Deut', JOS: 'Josh', JDG: 'Judg', RUT: 'Ruth', '1SA': '1Sam', '2SA': '2Sam',
  '1KI': '1Kgs', '2KI': '2Kgs', '1CH': '1Chr', '2CH': '2Chr', EZR: 'Ezra', NEH: 'Neh', EST: 'Esth', JOB: 'Job', PSA: 'Ps', PRO: 'Prov',
  ECC: 'Eccl', SNG: 'Song', ISA: 'Isa', JER: 'Jer', LAM: 'Lam', EZK: 'Ezek', DAN: 'Dan', HOS: 'Hos', JOL: 'Joel', AMO: 'Amos',
  OBA: 'Obad', JON: 'Jonah', MIC: 'Mic', NAM: 'Nah', HAB: 'Hab', ZEP: 'Zeph', HAG: 'Hag', ZEC: 'Zech', MAL: 'Mal',
  MAT: 'Matt', MRK: 'Mark', LUK: 'Luke', JHN: 'John', ACT: 'Acts', ROM: 'Rom', '1CO': '1Cor', '2CO': '2Cor', GAL: 'Gal', EPH: 'Eph',
  PHP: 'Phil', COL: 'Col', '1TH': '1Thess', '2TH': '2Thess', '1TI': '1Tim', '2TI': '2Tim', TIT: 'Titus', PHM: 'Phlm', HEB: 'Heb',
  JAS: 'Jas', '1PE': '1Pet', '2PE': '2Pet', '1JN': '1John', '2JN': '2John', '3JN': '3John', JUD: 'Jude', REV: 'Rev',
  TOB: 'Tob', JDT: 'Jdt', WIS: 'Wis', SIR: 'Sir', BAR: 'Bar', LJE: 'EpJer', S3Y: 'PrAzar', SUS: 'Sus', BEL: 'Bel',
  '1MA': '1Macc', '2MA': '2Macc', '3MA': '3Macc', '4MA': '4Macc', '1ES': '1Esd', '2ES': '2Esd', MAN: 'PrMan', PS2: 'AddPs', ODA: 'Odes', PSS: 'PssSol', ENO: '1En',
};

const TITLE_TYPES = new Set(['x-s', 'psalm', 'section', 'acrostic', 'sub']);

/** Télécharge un fichier (avec cache local et nouvelles tentatives). */
async function fetchText(url) {
  if (process.env.MP_SOURCES && url.startsWith(RAW.scrollmapper)) {
    return fs.readFileSync(path.join(process.env.MP_SOURCES, url.slice(RAW.scrollmapper.length)), 'utf8');
  }
  const cached = path.join(CACHE, url.replace(/^https?:\/\//, '').replace(/[^\w.-]+/g, '_'));
  if (fs.existsSync(cached)) return fs.readFileSync(cached, 'utf8');
  for (let attempt = 1; ; attempt++) {
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`${res.status} ${url}`);
      const text = await res.text();
      fs.mkdirSync(CACHE, { recursive: true });
      fs.writeFileSync(cached, text);
      return text;
    } catch (e) {
      if (attempt >= 4) throw e;
      await new Promise(r => setTimeout(r, 2000 * 2 ** (attempt - 1)));
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

function plain(t) {
  return t.replace(/\{([^|}]*)\|[^}]*\}/g, '$1').replace(/[[\]]/g, '');
}

/* ---------- Lecteurs de sources : chacun renvoie [{ book, c, v, raw }] ---------- */

async function readScrollmapper(v, books) {
  const src = JSON.parse(await fetchText(RAW.scrollmapper + v.src));
  const bySm = new Map(books.map(b => [b.sm, b.id]));
  const out = [];
  for (const b of src.books) {
    const id = bySm.get(b.name);
    if (!id) { console.warn(`\n  livre inconnu ignoré : ${b.name}`); continue; }
    for (const ch of b.chapters) for (const vv of ch.verses) out.push({ book: id, c: ch.chapter, v: vv.verse, raw: vv.text || '' });
  }
  return out;
}

async function readBeblia(v, books) {
  const xml = (await fetchText(RAW.beblia + v.src)).replace(/^\uFEFF/, '');
  const canon = books.filter(b => b.cat === 'AT' || b.cat === 'NT'); // numérotation 1–66
  const out = [];
  for (const bm of xml.matchAll(/<book number="(\d+)">([\s\S]*?)<\/book>/g)) {
    const meta = canon[+bm[1] - 1];
    if (!meta) continue;
    for (const cm of bm[2].matchAll(/<chapter number="(\d+)">([\s\S]*?)<\/chapter>/g)) {
      for (const vm of cm[2].matchAll(/<verse number="(\d+)">([\s\S]*?)<\/verse>/g)) {
        out.push({ book: meta.id, c: +cm[1], v: +vm[1], raw: vm[2] });
      }
    }
  }
  return out;
}

async function readEbible(v) {
  const vref = (await fetchText(RAW.ebible + 'metadata/vref.txt')).split('\n');
  const lines = (await fetchText(RAW.ebible + `corpus/${v.src}.txt`)).split('\n');
  const out = [];
  vref.forEach((ref, i) => {
    const m = ref.trim().match(/^(\w+) (\d+):(\d+)$/);
    const text = (lines[i] || '').trim();
    if (!m || !USFM[m[1]] || !text || text === '<range>') return;
    out.push({ book: USFM[m[1]], c: +m[2], v: +m[3], raw: text.replace(/<range>/g, '') });
  });
  return out;
}

async function readOsis(v) {
  const xml = await fetchText(RAW.osis + v.src);
  const out = [];
  for (const m of xml.matchAll(/<verse\b[^>]*osisID=['"]([^'"]+)['"][^>]*>([\s\S]*?)<\/verse>/g)) {
    const [book, c, n] = m[1].split(' ')[0].split('.');
    out.push({ book, c: +c, v: +n, raw: m[2] });
  }
  return out;
}

const READERS = { scrollmapper: readScrollmapper, beblia: readBeblia, ebible: readEbible, osis: readOsis };

async function buildVersion(v, books) {
  process.stdout.write(`• ${v.id} (${v.kind}: ${v.src}) … `);
  const known = new Set(books.map(b => b.id));
  const rows = await READERS[v.kind](v, books);
  const out = {
    id: v.id, name: v.name, short: v.short, lang: v.lang, dir: v.dir || 'ltr', year: v.year || null,
    license: v.license, strong: !!v.strong, description: v.description,
    source: v.kind === 'scrollmapper' ? `${REPO.scrollmapper}/tree/master/${path.dirname(v.src)}` : `${REPO[v.kind]}`,
    books: {}, titles: {}, notes: {},
  };
  for (const r of rows) {
    if (!known.has(r.book) || !(r.c > 0)) continue;
    const { text, notes, titles } = convertVerse(r.raw);
    const chapters = (out.books[r.book] ||= []);
    while (chapters.length < r.c) chapters.push([]);
    const arr = chapters[r.c - 1];
    if (r.v === 0) { if (text) out.titles[`${r.book}.${r.c}.1`] = plain(text); continue; }
    while (arr.length < r.v - 1) arr.push('');
    arr[r.v - 1] = arr[r.v - 1] ? arr[r.v - 1] + ' ' + text : text;
    const key = `${r.book}.${r.c}.${r.v}`;
    if (notes.length) out.notes[key] = notes;
    if (titles.length) out.titles[key] = titles.join(' — ');
  }
  let verses = 0;
  for (const [id, chapters] of Object.entries(out.books)) {
    // Retire les livres et chapitres finaux vides (certaines sources listent des livres sans texte).
    while (chapters.length && !chapters[chapters.length - 1].some(t => plain(t).trim())) chapters.pop();
    if (!chapters.some(c => c.some(t => plain(t).trim()))) { delete out.books[id]; continue; }
    verses += chapters.reduce((s, c) => s + c.filter(Boolean).length, 0);
  }
  // Ordre canonique des livres
  out.books = Object.fromEntries(books.filter(b => out.books[b.id]).map(b => [b.id, out.books[b.id]]));
  out.stats = { books: Object.keys(out.books).length, verses };
  // Un fichier compressé par livre : data/bibles/<ID>/<Livre>.json.gz
  const dir = path.join(DATA, 'bibles', v.id);
  fs.rmSync(dir, { recursive: true, force: true });
  fs.mkdirSync(dir, { recursive: true });
  for (const [book, chapters] of Object.entries(out.books)) {
    const pick = obj => Object.fromEntries(Object.entries(obj).filter(([k]) => k.startsWith(book + '.')));
    const payload = { chapters, titles: pick(out.titles), notes: pick(out.notes) };
    fs.writeFileSync(path.join(dir, book + '.json.gz'), zlib.gzipSync(JSON.stringify(payload), { level: 9 }));
  }
  for (const legacy of [v.id + '.json', v.id + '.json.gz']) fs.rmSync(path.join(DATA, 'bibles', legacy), { force: true });
  console.log(`${out.stats.books} livres, ${verses} versets`);
  const chapterCounts = Object.fromEntries(Object.entries(out.books).map(([id, c]) => [id, c.length]));
  return { ...v, stats: out.stats, source: out.source, dir: out.dir, books: chapterCounts };
}

async function buildCrossRefs() {
  process.stdout.write('• Références croisées (OpenBible.info) … ');
  const txt = await fetchText(RAW.scrollmapper + 'sources/extras/cross_references.txt');
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
    const js = await fetchText(RAW.strong + file);
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
    const { src, kind, ...meta } = entry;
    const i = catalog.findIndex(c => c.id === v.id);
    if (i >= 0) catalog[i] = meta; else catalog.push(meta);
  }
  for (let i = catalog.length - 1; i >= 0; i--) if (!VERSIONS.some(v => v.id === catalog[i].id)) catalog.splice(i, 1);
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
