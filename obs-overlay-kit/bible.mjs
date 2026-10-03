// Moteur biblique : lecture des références (« Jean 3:16-18 », « Ps 23 », « 1 Co 13.4 »)
// et recherche par mots-clés, sur les Bibles de data/bibles/*.json.
import {readdirSync, readFileSync, existsSync} from 'node:fs';
import {join} from 'node:path';

// 66 livres (ordre protestant) : nom affiché + abréviations acceptées
export const LIVRES = [
	['Genèse', 'gn gen ge'], ['Exode', 'ex exo'], ['Lévitique', 'lv lev le'], ['Nombres', 'nb nom no'],
	['Deutéronome', 'dt deut de'], ['Josué', 'jos js'], ['Juges', 'jg jug'], ['Ruth', 'rt ru'],
	['1 Samuel', '1s 1sa 1sam'], ['2 Samuel', '2s 2sa 2sam'], ['1 Rois', '1r 1ro 1rois'], ['2 Rois', '2r 2ro 2rois'],
	['1 Chroniques', '1ch 1chr 1chro'], ['2 Chroniques', '2ch 2chr 2chro'], ['Esdras', 'esd'], ['Néhémie', 'ne neh'],
	['Esther', 'est'], ['Job', 'jb'], ['Psaumes', 'ps psa psaume psm'], ['Proverbes', 'pr pro prov'],
	['Ecclésiaste', 'ec ecc eccl qo qohelet'], ['Cantique des cantiques', 'ct cant cantique'], ['Ésaïe', 'es esa is isaie'],
	['Jérémie', 'jr jer'], ['Lamentations', 'lm lam'], ['Ézéchiel', 'ez eze ezech'], ['Daniel', 'dn da dan'],
	['Osée', 'os'], ['Joël', 'jl joe'], ['Amos', 'am'], ['Abdias', 'ab abd'], ['Jonas', 'jon'], ['Michée', 'mi mic'],
	['Nahum', 'na nah'], ['Habacuc', 'ha hab'], ['Sophonie', 'so sop'], ['Aggée', 'ag agg'], ['Zacharie', 'za zac'],
	['Malachie', 'ml mal'], ['Matthieu', 'mt mat matt'], ['Marc', 'mc mr mar'], ['Luc', 'lc lu'], ['Jean', 'jn jea'],
	['Actes', 'ac act'], ['Romains', 'rm ro rom'], ['1 Corinthiens', '1co 1cor'], ['2 Corinthiens', '2co 2cor'],
	['Galates', 'ga gal'], ['Éphésiens', 'ep eph'], ['Philippiens', 'ph phil php'], ['Colossiens', 'col'],
	['1 Thessaloniciens', '1th 1thes 1thess'], ['2 Thessaloniciens', '2th 2thes 2thess'], ['1 Timothée', '1tm 1ti 1tim'],
	['2 Timothée', '2tm 2ti 2tim'], ['Tite', 'tt tit'], ['Philémon', 'phm phlm'], ['Hébreux', 'he heb'],
	['Jacques', 'jc ja jac'], ['1 Pierre', '1p 1pi 1pie'], ['2 Pierre', '2p 2pi 2pie'], ['1 Jean', '1jn 1jean'],
	['2 Jean', '2jn 2jean'], ['3 Jean', '3jn 3jean'], ['Jude', 'jd jud'], ['Apocalypse', 'ap apo apoc'],
].map(([nom, abr]) => ({nom, abr: abr.split(' ')}));

// minuscules, sans accents ni ponctuation superflue
export const norm = (s) =>
	s
		.toLowerCase()
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/[’']/g, ' ')
		.replace(/\s+/g, ' ')
		.trim();

const bookKey = (s) => norm(s).replace(/[^a-z0-9]/g, '');
const EXACT = new Map();
LIVRES.forEach((l, i) => {
	EXACT.set(bookKey(l.nom), i);
	for (const a of l.abr) EXACT.set(a, i);
});

const findBook = (raw) => {
	let key = bookKey(raw).replace(/^(i{1,3})(?=[a-z])/, (m) => String(m.length));
	if (EXACT.has(key)) return EXACT.get(key);
	// préfixe unique d'un nom complet (« matth », « philip », « apoca »)
	const hits = LIVRES.map((l, i) => [bookKey(l.nom), i]).filter(([k]) => k.startsWith(key));
	if (key.length >= 2 && hits.length >= 1) return hits[0][1];
	return -1;
};

// --- Chargement des versions ------------------------------------------------------
export const chargerBibles = (dir) => {
	const versions = new Map();
	if (!existsSync(dir)) return versions;
	for (const f of readdirSync(dir).filter((x) => x.endsWith('.json')).sort()) {
		try {
			const b = JSON.parse(readFileSync(join(dir, f), 'utf8'));
			versions.set(b.id, b);
		} catch (e) {
			console.error(`Bible illisible : ${f}`, e.message);
		}
	}
	return versions;
};

const ORDRE = ['lsg', 'darby', 'martin'];
export const listeVersions = (versions) =>
	[...versions.values()]
		.sort((a, b) => ((ORDRE.indexOf(a.id) + 1 || 99) - (ORDRE.indexOf(b.id) + 1 || 99)))
		.map(({id, nom, abrev, langue}) => ({id, nom, abrev, langue}));

// --- Références ---------------------------------------------------------------------
// « Jean 3:16-18 », « jn 3.16 », « 1 Co 13 », « Psaume 23:1-3 », « Romains 8:28, »
const REF_RE = /^\s*((?:[1-3]|i{1,3})?\s*[a-zà-ÿ][a-zà-ÿ .]*?)\s*(\d+)(?:(?:\s*[:.,v]\s*|\s+)(\d+)(?:\s*[-–]\s*(\d+))?)?\s*$/i;

export const lireReference = (q) => {
	const m = REF_RE.exec(q.replace(/ /g, ' '));
	if (!m) return null;
	const livre = findBook(m[1]);
	if (livre < 0) return null;
	const chapitre = Number(m[2]);
	const debut = m[3] ? Number(m[3]) : 1;
	const fin = m[4] ? Number(m[4]) : m[3] ? debut : 0; // 0 = tout le chapitre
	return {livre, chapitre, debut, fin};
};

export const nomReference = (livre, chapitre, debut, fin) =>
	`${LIVRES[livre].nom} ${chapitre}:${debut}${fin > debut ? `-${fin}` : ''}`;

export const passage = (bible, {livre, chapitre, debut, fin}) => {
	const chap = bible.livres[livre]?.[chapitre - 1];
	if (!chap || !chap.length) return null;
	const last = fin > 0 ? Math.min(fin, chap.length) : chap.length;
	const first = Math.max(1, Math.min(debut, chap.length));
	const versets = [];
	for (let n = first; n <= last; n++) if (chap[n - 1]) versets.push({n, texte: chap[n - 1]});
	return {
		livre,
		chapitre,
		debut: first,
		fin: last,
		reference: nomReference(livre, chapitre, first, last),
		nbVersetsChapitre: chap.length,
		nbChapitres: bible.livres[livre].length,
		versets,
	};
};

// --- Recherche par mots --------------------------------------------------------------
const indexCache = new WeakMap();
const index = (bible) => {
	if (!indexCache.has(bible)) {
		const rows = [];
		bible.livres.forEach((chs, l) =>
			chs.forEach((vs, c) =>
				vs.forEach((t, v) => {
					if (t) rows.push([l, c + 1, v + 1, ` ${norm(t).replace(/[^a-z0-9 ]/g, ' ')} `]);
				}),
			),
		);
		indexCache.set(bible, rows);
	}
	return indexCache.get(bible);
};

export const rechercher = (bible, q, limite = 60) => {
	const mots = norm(q).replace(/[^a-z0-9 ]/g, ' ').split(' ').filter((m) => m.length > 1);
	if (!mots.length) return {total: 0, resultats: []};
	const expr = norm(q).replace(/[^a-z0-9 ]/g, ' ').trim();
	const resultats = [];
	let total = 0;
	for (const [l, c, v, t] of index(bible)) {
		if (mots.every((m) => t.includes(m))) {
			total++;
			// l'expression exacte d'abord
			const score = t.includes(` ${expr} `) ? 0 : 1;
			if (resultats.length < 2000) resultats.push({score, livre: l, chapitre: c, verset: v});
		}
	}
	resultats.sort((a, b) => a.score - b.score);
	return {
		total,
		resultats: resultats.slice(0, limite).map(({livre, chapitre, verset}) => ({
			livre,
			chapitre,
			verset,
			reference: nomReference(livre, chapitre, verset, verset),
			texte: bible.livres[livre][chapitre - 1][verset - 1],
		})),
	};
};
