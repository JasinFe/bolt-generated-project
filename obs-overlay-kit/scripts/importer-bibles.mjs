// Télécharge des Bibles du domaine public et les convertit pour la régie (data/bibles/*.json).
// Les 3 Bibles par défaut sont déjà fournies avec le pack : ce script ne sert qu'à en ajouter
// ou à les réinstaller.
//
//   node scripts/importer-bibles.mjs                    -> lsg, darby, martin
//   node scripts/importer-bibles.mjs crampon kjv        -> ajoute d'autres versions
//   node scripts/importer-bibles.mjs --liste            -> versions disponibles
import {mkdir, writeFile} from 'node:fs/promises';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', 'data', 'bibles');
const BEBLIA = 'https://raw.githubusercontent.com/Beblia/Holy-Bible-XML-Format/master/';
const SCROLLMAPPER = 'https://raw.githubusercontent.com/scrollmapper/bible_databases/master/formats/json/';

const SOURCES = {
	lsg: {nom: 'Louis Segond 1910', abrev: 'LSG', langue: 'fr', type: 'beblia', url: `${BEBLIA}FrenchBible.xml`},
	darby: {nom: 'Darby 1885', abrev: 'DBY', langue: 'fr', type: 'scrollmapper', url: `${SCROLLMAPPER}FreJND.json`},
	martin: {nom: 'Martin 1744', abrev: 'MAR', langue: 'fr', type: 'beblia', url: `${BEBLIA}FrenchMartinBible.xml`},
	crampon: {nom: 'Crampon 1923', abrev: 'CRA', langue: 'fr', type: 'beblia', url: `${BEBLIA}FrenchCramponBible.xml`},
	kjv: {nom: 'King James (anglais)', abrev: 'KJV', langue: 'en', type: 'scrollmapper', url: `${SCROLLMAPPER}KJV.json`},
};

const decode = (s) =>
	s
		.replace(/<[^>]+>/g, '')
		.replace(/&quot;/g, '"')
		.replace(/&apos;/g, "'")
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n)))
		.replace(/&amp;/g, '&')
		.replace(/\s+/g, ' ')
		.trim();

// Résultat : livres[0..65][chapitre-1][verset-1] = texte
const fromBeblia = (xml) => {
	const livres = Array.from({length: 66}, () => []);
	const bookRe = /<book number="(\d+)"[^>]*>([\s\S]*?)<\/book>/g;
	let b;
	while ((b = bookRe.exec(xml))) {
		const nb = Number(b[1]);
		if (nb < 1 || nb > 66) continue;
		const chapRe = /<chapter number="(\d+)"[^>]*>([\s\S]*?)<\/chapter>/g;
		let c;
		while ((c = chapRe.exec(b[2]))) {
			const verses = [];
			const vRe = /<verse number="(\d+)"[^>]*>([\s\S]*?)<\/verse>/g;
			let v;
			while ((v = vRe.exec(c[2]))) verses[Number(v[1]) - 1] = decode(v[2]);
			livres[nb - 1][Number(c[1]) - 1] = Array.from(verses, (x) => x ?? '');
		}
	}
	return livres;
};

const fromScrollmapper = (data) =>
	data.books.slice(0, 66).map((book) =>
		book.chapters.map((ch) => {
			const verses = [];
			// ces textes contiennent des espaces parasites après les apostrophes (« L’ Éternel »)
			for (const v of ch.verses) verses[v.verse - 1] = decode(v.text).replace(/([’'])\s+(?=\p{L})/gu, '$1');
			return Array.from(verses, (x) => x ?? '');
		}),
	);

const args = process.argv.slice(2);
if (args.includes('--liste')) {
	for (const [id, s] of Object.entries(SOURCES)) console.log(`${id.padEnd(10)} ${s.nom}`);
	process.exit(0);
}
const ids = args.length ? args : ['lsg', 'darby', 'martin'];
await mkdir(OUT, {recursive: true});

for (const id of ids) {
	const src = SOURCES[id];
	if (!src) {
		console.error(`✖ Version inconnue : ${id} (voir --liste)`);
		continue;
	}
	process.stdout.write(`↓ ${src.nom}… `);
	const res = await fetch(src.url);
	if (!res.ok) {
		console.error(`échec (${res.status})`);
		continue;
	}
	const livres = src.type === 'beblia' ? fromBeblia(await res.text()) : fromScrollmapper(await res.json());
	const versets = livres.flat(2).filter(Boolean).length;
	const manquants = livres.filter((l) => !l.length).length;
	await writeFile(join(OUT, `${id}.json`), JSON.stringify({id, nom: src.nom, abrev: src.abrev, langue: src.langue, livres}));
	console.log(`${versets} versets${manquants ? `, ${manquants} livres absents de cette version` : ''} ✔`);
}
