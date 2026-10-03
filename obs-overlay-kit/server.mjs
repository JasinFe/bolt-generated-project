// OBS Overlay Kit — propriété de KOPHI'S GROUP SAS. Tous droits réservés.
// Serveur local des overlays OBS — aucune dépendance, Node.js 18+ suffit.
//   node server.mjs            -> http://localhost:3333 (accessible depuis ce PC uniquement)
//   node server.mjs --lan      -> accessible aussi depuis un téléphone/tablette du même réseau
//   PORT=4000 node server.mjs  -> autre port
import {createServer} from 'node:http';
import {readFile, writeFile, mkdir, stat} from 'node:fs/promises';
import {existsSync, readFileSync} from 'node:fs';
import {extname, join, normalize, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {networkInterfaces} from 'node:os';
import {createRequire} from 'node:module';
import {YouTubeChat, FacebookChat} from './chat.mjs';
import {chargerBibles, listeVersions, lireReference, passage, rechercher} from './bible.mjs';
import {Licence} from './licence.mjs';
import {PROPRIETAIRE, MENTION} from './lib/licence.mjs';

const ROOT = dirname(fileURLToPath(import.meta.url));
// Générateur de QR code (Kazuhiko Arase, licence MIT), embarqué pour fonctionner hors ligne
const qrcode = createRequire(import.meta.url)('./lib/qrcode.cjs');
const PUBLIC = join(ROOT, 'public');
const DEFAULT_FILE = join(ROOT, 'data', 'etat-par-defaut.json');
const STATE_FILE = join(ROOT, 'data', 'etat.json');
const PORT = Number(process.env.PORT) || 3333;
const LAN = process.argv.includes('--lan');
const HOST = LAN ? '0.0.0.0' : '127.0.0.1';
const VERSION = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')).version;

// --- Licence ---------------------------------------------------------------------
// Fonctions verrouillées sans licence (après l'essai) : voir licence.mjs
const licence = new Licence({
	fichier: join(ROOT, 'data', 'licence.json'),
	clePublique: join(ROOT, 'licence', 'cle-publique.pem'),
	vendeur: join(ROOT, 'licence', 'vendeur.json'),
	version: VERSION,
	onChange: () => {
		// une fonction qui n'est plus couverte se retire de l'antenne
		const patch = {};
		if (!licence.a('bible') && state.bible?.visible) patch.bible = {visible: false};
		if (!licence.a('score') && state.score?.visible) patch.score = {visible: false};
		if (!licence.a('chat')) {
			for (const c of Object.values(connecteurs)) c.arreter();
			if (state.chat?.auto) patch.chat = {auto: false};
		}
		Object.keys(patch).length ? apply(patch) : broadcast();
	},
});
const LIBELLE_VERROU = {
	bible: 'Bible',
	score: 'Score',
	chat: 'Chat en direct',
	emissions: 'Émissions multiples',
	api: 'Raccourcis Stream Deck / API',
	telephone: 'Pilotage depuis un téléphone',
};
const verrou = (f) => `${LIBELLE_VERROU[f]} : fonction non incluse dans votre licence (bouton « Licence » de la régie).`;
// Retire d'une modification venant de la régie ce que la licence ne couvre pas
const filtrerPatch = (patch) => {
	const refus = new Set();
	if (!licence.a('bible') && patch.bible?.visible) delete patch.bible.visible, refus.add('bible');
	if (!licence.a('score') && patch.score?.visible) delete patch.score.visible, refus.add('score');
	if (!licence.a('chat') && patch.chat?.auto) delete patch.chat.auto, refus.add('chat');
	if (!licence.a('emissions')) {
		if (patch.emissionActive && patch.emissionActive !== state.emissionActive) delete patch.emissionActive, refus.add('emissions');
		for (const k of Object.keys(patch.emissions || {})) if (!state.emissions[k] || patch.emissions[k] === null) delete patch.emissions[k], refus.add('emissions');
	}
	return [...refus].map(verrou);
};
const etatPublic = () => ({...state, licence: licence.public()});

// Éléments masquables, avec leur éventuel masquage automatique (champ autoMasquer, en secondes)
const ELEMENTS = ['logo', 'bandeauNom', 'sujet', 'defilant', 'titreEnCours', 'citation', 'bible', 'message', 'score'];

// --- Bibles (data/bibles/*.json) ---------------------------------------------
const BIBLES = chargerBibles(join(ROOT, 'data', 'bibles'));

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.jpeg': 'image/jpeg',
	'.gif': 'image/gif',
	'.webp': 'image/webp',
	'.svg': 'image/svg+xml',
	'.woff2': 'font/woff2',
	'.ico': 'image/x-icon',
};

// --- État -----------------------------------------------------------------
const defaults = JSON.parse(readFileSync(DEFAULT_FILE, 'utf8'));
let state = existsSync(STATE_FILE)
	? merge(structuredClone(defaults), JSON.parse(readFileSync(STATE_FILE, 'utf8')))
	: structuredClone(defaults);

// Fusion profonde : les objets sont fusionnés, les tableaux et valeurs simples remplacés.
// Une valeur null supprime la clé (utile pour supprimer une émission).
function merge(target, patch) {
	for (const [k, v] of Object.entries(patch)) {
		if (k === '__proto__' || k === 'constructor' || k === 'prototype') continue;
		if (v === null) delete target[k];
		else if (typeof v === 'object' && !Array.isArray(v) && typeof target[k] === 'object' && target[k] && !Array.isArray(target[k])) {
			merge(target[k], v);
		} else target[k] = v;
	}
	return target;
}

let saveTimer = null;
const save = () => {
	clearTimeout(saveTimer);
	saveTimer = setTimeout(() => writeFile(STATE_FILE, JSON.stringify(state, null, 2)).catch(console.error), 300);
};

// --- Diffusion en direct (Server-Sent Events) --------------------------------
const clients = new Set();
// la régie reçoit en plus les commentaires du chat (l'overlay n'en a pas besoin)
const chatClients = new Set();
const sendChat = (event, data) => {
	const payload = `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;
	for (const res of chatClients) res.write(payload);
};
const broadcast = () => {
	const payload = `event: etat\ndata: ${JSON.stringify(etatPublic())}\n\n`;
	for (const res of clients) res.write(payload);
};
setInterval(() => {
	for (const res of clients) res.write(': ping\n\n');
	for (const res of chatClients) res.write(': ping\n\n');
}, 15000);

// --- Masquage automatique ------------------------------------------------------
const hideTimers = {};
const scheduleAutoHide = (before) => {
	for (const key of ELEMENTS) {
		const el = state[key];
		if (!el || typeof el !== 'object') continue;
		const wasVisible = before[key]?.visible;
		const changed = JSON.stringify({...before[key], liste: 0}) !== JSON.stringify({...el, liste: 0});
		if (!el.visible) {
			clearTimeout(hideTimers[key]);
			continue;
		}
		if ((!wasVisible || changed) && Number(el.autoMasquer) > 0) {
			clearTimeout(hideTimers[key]);
			hideTimers[key] = setTimeout(() => apply({[key]: {visible: false}}), Number(el.autoMasquer) * 1000);
		}
	}
};

// Remplit le texte du passage choisi dans la version principale (et la 2e version éventuelle)
const resoudreBible = () => {
	const b = state.bible;
	if (!b) return;
	const sel = {livre: b.livre, chapitre: b.chapitre, debut: b.debut, fin: b.fin};
	const v1 = BIBLES.get(b.version) || BIBLES.values().next().value;
	const p1 = v1 && passage(v1, sel);
	const v2 = b.version2 && b.version2 !== v1?.id ? BIBLES.get(b.version2) : null;
	const p2 = v2 && passage(v2, sel);
	Object.assign(b, {
		reference: p1?.reference || '',
		versets: p1?.versets || [],
		nomVersion: v1?.abrev || '',
		versets2: p2?.versets || [],
		nomVersion2: p2 ? v2.abrev : '',
	});
	if (p1) Object.assign(b, {debut: p1.debut, fin: p1.fin});
};

const BIBLE_KEYS = ['version', 'version2', 'livre', 'chapitre', 'debut', 'fin'];
resoudreBible();

const apply = (patch) => {
	const before = structuredClone(state);
	merge(state, patch);
	if (state.bible && BIBLE_KEYS.some((k) => before.bible?.[k] !== state.bible[k])) resoudreBible();
	if (JSON.stringify(before.chat) !== JSON.stringify(state.chat)) {
		for (const m of chatMessages) m.filtre = filtrer(m);
		if (!state.chat?.auto) fileAuto.length = 0;
	}
	scheduleAutoHide(before);
	save();
	broadcast();
};

// --- Chat en direct (YouTube / Facebook) ------------------------------------------
const CONNEXIONS_FILE = join(ROOT, 'data', 'connexions.json');
let connexions = {youtube: {cle: '', video: ''}, facebook: {jeton: '', video: ''}};
try {
	if (existsSync(CONNEXIONS_FILE)) connexions = merge(connexions, JSON.parse(readFileSync(CONNEXIONS_FILE, 'utf8')));
} catch {}
const saveConnexions = () => writeFile(CONNEXIONS_FILE, JSON.stringify(connexions, null, 2)).catch(console.error);

const chatMessages = [];
const chatStatuts = {youtube: {etat: 'arrete', detail: '', titre: ''}, facebook: {etat: 'arrete', detail: '', titre: ''}};

const normTxt = (t) => (t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
// Filtre du mode automatique : mots interdits, liens
const filtrer = (m) => {
	const c = state.chat || {};
	const txt = normTxt(`${m.auteur} ${m.texte}`);
	if ((c.motsInterdits || []).some((w) => w.trim() && txt.includes(normTxt(w.trim())))) return 'mot interdit';
	if (c.masquerLiens && /(https?:\/\/|www\.|\b[\w-]+\.(com|net|org|fr|io|ly|gg|tv|me)\b)/i.test(m.texte)) return 'lien';
	return '';
};

// File d'attente du mode automatique : chaque nouveau commentaire passe à l'antenne à tour de rôle
const fileAuto = [];
let autoTimer = null;
const afficherMessage = (m, duree) =>
	apply({message: {visible: true, auteur: m.auteur, texte: m.texte, plateforme: m.plateforme, avatar: m.avatar || '', montant: m.montant || '', autoMasquer: duree ?? state.message.autoMasquer}});
const suivantAuto = () => {
	autoTimer = null;
	if (!state.chat?.auto || !fileAuto.length) return;
	const m = fileAuto.shift();
	const duree = Math.max(3, Number(state.chat.duree) || 10);
	afficherMessage(m, duree);
	autoTimer = setTimeout(suivantAuto, (duree + 1) * 1000);
};

const recevoirMessage = (m) => {
	if (chatMessages.some((x) => x.id === m.id)) return;
	m.filtre = filtrer(m);
	chatMessages.push(m);
	if (chatMessages.length > 300) chatMessages.splice(0, chatMessages.length - 300);
	sendChat('chat', m);
	if (state.chat?.auto && !m.filtre) {
		fileAuto.push(m);
		if (fileAuto.length > 20) fileAuto.shift();
		if (!autoTimer) suivantAuto();
	}
};
const majStatut = (plateforme, statut) => {
	chatStatuts[plateforme] = statut;
	sendChat('chat-statut', chatStatuts);
};
const connecteurs = {
	youtube: new YouTubeChat(recevoirMessage, majStatut),
	facebook: new FacebookChat(recevoirMessage, majStatut),
};

// --- Accès depuis un téléphone -----------------------------------------------------
// Adresses de ce PC sur le réseau local, la plus probable en premier
// (Wi-Fi / box : 192.168.x, puis 10.x ; les 172.x sont souvent des cartes virtuelles)
const adressesLocales = () => {
	const ips = [];
	for (const [nom, nets] of Object.entries(networkInterfaces())) {
		for (const n of nets || []) {
			if (n.family !== 'IPv4' || n.internal || n.address.startsWith('169.254.')) continue;
			const virtuelle = /virtual|vethernet|vmware|vbox|hyper-v|wsl|docker|tailscale|zerotier|hamachi/i.test(nom);
			const rang = (n.address.startsWith('192.168.') ? 0 : n.address.startsWith('10.') ? 1 : 2) + (virtuelle ? 10 : 0);
			ips.push({ip: n.address, interface: nom, rang});
		}
	}
	return ips.sort((a, b) => a.rang - b.rang).map(({ip, interface: i}) => ({ip, interface: i, url: `http://${ip}:${PORT}/controle.html`}));
};

const makeQr = (texte) => {
	const qr = qrcode(0, 'M');
	qr.addData(texte);
	qr.make();
	return qr;
};

// QR code en caractères, lisible sur la fenêtre noire (modules clairs dessinés en blanc)
const qrTerminal = (texte) => {
	const qr = makeQr(texte);
	const n = qr.getModuleCount();
	const m = 2;
	const clair = (r, c) => r < 0 || c < 0 || r >= n || c >= n || !qr.isDark(r, c);
	const lignes = [];
	for (let r = -m; r < n + m; r += 2) {
		let l = '  ';
		for (let c = -m; c < n + m; c++) {
			const haut = clair(r, c);
			const bas = r + 1 < n + m ? clair(r + 1, c) : false;
			l += haut && bas ? '█' : haut ? '▀' : bas ? '▄' : ' ';
		}
		lignes.push(l);
	}
	return lignes.join('\n');
};

// --- HTTP ------------------------------------------------------------------------
const readBody = (req, limit = 15 * 1024 * 1024) =>
	new Promise((resolve, reject) => {
		const chunks = [];
		let size = 0;
		req.on('data', (c) => {
			size += c.length;
			if (size > limit) {
				reject(new Error('Fichier trop volumineux'));
				req.destroy();
			} else chunks.push(c);
		});
		req.on('end', () => resolve(Buffer.concat(chunks)));
		req.on('error', reject);
	});

const json = (res, code, obj) => {
	res.writeHead(code, {'Content-Type': MIME['.json'], 'Cache-Control': 'no-store'});
	res.end(JSON.stringify(obj));
};

const server = createServer(async (req, res) => {
	try {
		const url = new URL(req.url, 'http://localhost');
		const p = decodeURIComponent(url.pathname);

		if (p === '/') {
			res.writeHead(302, {Location: '/controle.html'});
			return res.end();
		}

		// Accès depuis un autre appareil (téléphone) : fonction « telephone » de la licence
		const local = /^(::1|127\.|::ffff:127\.)/.test(req.socket.remoteAddress || '');
		if (!local && !licence.a('telephone')) {
			res.writeHead(403, {'Content-Type': 'text/html; charset=utf-8'});
			return res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="font:16px system-ui;background:#0f1115;color:#e8eaee;padding:24px"><h2>🔒 Pilotage à distance verrouillé</h2><p>${verrou('telephone')}</p><p>Code de ce PC : <b>${licence.machine}</b></p></body>`);
		}

		// --- Licence ---
		if (p === '/api/licence' && req.method === 'GET') return json(res, 200, licence.public());
		if (p === '/api/licence/activer' && req.method === 'POST') {
			const body = JSON.parse((await readBody(req, 64 * 1024)).toString('utf8') || '{}');
			const r = await licence.activer(body.cle);
			return json(res, r.ok ? 200 : 400, r);
		}
		if (p === '/api/licence/desactiver' && req.method === 'POST') return json(res, 200, await licence.desactiver());
		if (p === '/api/licence/verifier' && req.method === 'POST') {
			await licence.verifierEnLigne(true);
			return json(res, 200, licence.public());
		}

		if (p === '/api/events') {
			res.writeHead(200, {
				'Content-Type': 'text/event-stream',
				'Cache-Control': 'no-cache',
				Connection: 'keep-alive',
			});
			res.write(`event: etat\ndata: ${JSON.stringify(etatPublic())}\n\n`);
			const set = url.searchParams.get('chat') ? chatClients : clients;
			if (set === chatClients) {
				// la régie reçoit aussi l'état, puis le chat
				clients.add(res);
				res.write(`event: chat-statut\ndata: ${JSON.stringify(chatStatuts)}\n\n`);
			}
			set.add(res);
			req.on('close', () => {
				clients.delete(res);
				chatClients.delete(res);
			});
			return;
		}

		if (p === '/api/state' && req.method === 'GET') return json(res, 200, etatPublic());

		if (p === '/api/state' && req.method === 'POST') {
			const patch = JSON.parse((await readBody(req)).toString('utf8') || '{}');
			const refus = filtrerPatch(patch);
			apply(patch);
			if (refus.length) broadcast(); // la régie retrouve l'état réel
			return json(res, 200, {ok: true, refus});
		}

		if (p === '/api/reset' && req.method === 'POST') {
			state = structuredClone(defaults);
			resoudreBible();
			save();
			broadcast();
			return json(res, 200, {ok: true});
		}

		// Raccourcis pratiques pour Stream Deck / Touch Portal / navigateur :
		//   /api/afficher/bandeauNom   /api/masquer/defilant   /api/basculer/logo
		//   /api/ecran/debut|pause|fin|aucun   /api/emission/debat
		const m = /^\/api\/(afficher|masquer|basculer|ecran|emission)\/([\w-]+)$/.exec(p);
		if (m) {
			if (!licence.a('api')) return json(res, 402, {erreur: verrou('api')});
			const [, action, cible] = m;
			if (action === 'ecran') {
				const patch = {ecran: {mode: cible}};
				if (cible === 'debut' && state.ecran.minutes > 0) patch.ecran.cible = Date.now() + state.ecran.minutes * 60000;
				apply(patch);
			} else if (action === 'emission') {
				if (!state.emissions[cible]) return json(res, 404, {erreur: 'Émission inconnue'});
				if (!licence.a('emissions')) return json(res, 402, {erreur: verrou('emissions')});
				apply({emissionActive: cible});
			} else {
				if (!ELEMENTS.includes(cible)) return json(res, 404, {erreur: 'Élément inconnu'});
				const visible = action === 'afficher' ? true : action === 'masquer' ? false : !state[cible].visible;
				apply({[cible]: {visible}});
			}
			return json(res, 200, {ok: true});
		}

		if (p === '/api/reseau') {
			return json(res, 200, {lan: LAN, port: PORT, adresses: LAN ? adressesLocales() : []});
		}

		if (p === '/api/qr.svg') {
			const texte = (url.searchParams.get('t') || '').slice(0, 300);
			res.writeHead(200, {'Content-Type': MIME['.svg'], 'Cache-Control': 'no-store'});
			return res.end(makeQr(texte).createSvgTag({cellSize: 8, margin: 2, scalable: true}));
		}

		// --- Chat en direct ---
		if (p === '/api/chat' && req.method === 'GET') {
			return json(res, 200, {
				statuts: chatStatuts,
				messages: chatMessages.slice(-150),
				config: {
					youtube: {video: connexions.youtube.video, cleEnregistree: !!connexions.youtube.cle},
					facebook: {video: connexions.facebook.video, jetonEnregistre: !!connexions.facebook.jeton},
				},
			});
		}
		const ch = /^\/api\/chat\/(youtube|facebook)\/(connecter|deconnecter|oublier)$/.exec(p);
		if (ch && req.method === 'POST') {
			const [, pf, action] = ch;
			if (action === 'connecter' && !licence.a('chat')) return json(res, 402, {erreur: verrou('chat')});
			if (action === 'deconnecter') connecteurs[pf].arreter();
			else if (action === 'oublier') {
				connecteurs[pf].arreter();
				connexions[pf] = pf === 'youtube' ? {cle: '', video: connexions[pf].video} : {jeton: '', video: connexions[pf].video};
				await saveConnexions();
			} else {
				const body = JSON.parse((await readBody(req)).toString('utf8') || '{}');
				const secret = pf === 'youtube' ? 'cle' : 'jeton';
				if (body[secret]) connexions[pf][secret] = String(body[secret]).trim();
				if (body.video !== undefined) connexions[pf].video = String(body.video).trim();
				await saveConnexions();
				connecteurs[pf].demarrer({...connexions[pf]});
			}
			return json(res, 200, {ok: true});
		}
		const aff = /^\/api\/chat\/afficher\/([\w-]+)$/.exec(p);
		if (aff) {
			if (!licence.a('chat')) return json(res, 402, {erreur: verrou('chat')});
			const m = chatMessages.find((x) => x.id === aff[1]);
			if (!m) return json(res, 404, {erreur: 'Message introuvable'});
			afficherMessage(m);
			return json(res, 200, {ok: true});
		}
		if (p === '/api/chat/vider' && req.method === 'POST') {
			chatMessages.length = 0;
			fileAuto.length = 0;
			sendChat('chat-vide', {});
			return json(res, 200, {ok: true});
		}

		// --- Bible ---
		if (p === '/api/bible/versions') return json(res, 200, listeVersions(BIBLES));

		if (p.startsWith('/api/bible/') && p !== '/api/bible/versions' && !licence.a('bible')) return json(res, 402, {erreur: verrou('bible')});
		if (p === '/api/bible/chercher') {
			const bible = BIBLES.get(url.searchParams.get('v')) || BIBLES.get(state.bible?.version) || BIBLES.values().next().value;
			if (!bible) return json(res, 404, {erreur: 'Aucune Bible installée'});
			const q = url.searchParams.get('q') || '';
			const ref = lireReference(q);
			if (ref) {
				const pas = passage(bible, ref);
				return json(res, 200, pas ? {type: 'reference', passage: pas} : {type: 'introuvable'});
			}
			return json(res, 200, {type: 'mots', ...rechercher(bible, q)});
		}

		// précédent / suivant : décale d'un verset (ou de la taille du passage), en changeant de chapitre si besoin
		const nav = /^\/api\/bible\/(suivant|precedent)$/.exec(p);
		if (nav) {
			const b = state.bible;
			const bible = BIBLES.get(b.version) || BIBLES.values().next().value;
			if (!bible) return json(res, 404, {erreur: 'Aucune Bible installée'});
			const n = Math.max(1, (b.fin || b.debut) - b.debut + 1);
			let {livre, chapitre, debut} = b;
			const len = (l, c) => bible.livres[l]?.[c - 1]?.length || 0;
			if (nav[1] === 'suivant') {
				debut += n;
				if (debut > len(livre, chapitre)) {
					debut = 1;
					chapitre++;
					if (!len(livre, chapitre)) (livre = (livre + 1) % 66), (chapitre = 1);
				}
			} else {
				debut -= n;
				if (debut < 1) {
					chapitre--;
					if (chapitre < 1) (livre = (livre + 65) % 66), (chapitre = bible.livres[livre].length);
					debut = Math.max(1, len(livre, chapitre) - n + 1);
				}
			}
			apply({bible: {livre, chapitre, debut, fin: Math.min(debut + n - 1, len(livre, chapitre))}});
			return json(res, 200, {ok: true, reference: state.bible.reference});
		}

		if (p === '/api/upload' && (req.method === 'PUT' || req.method === 'POST')) {
			const nom = (url.searchParams.get('nom') || 'fichier').toLowerCase().replace(/[^a-z0-9._-]+/g, '-');
			if (!/\.(png|jpe?g|gif|webp|svg)$/.test(nom)) return json(res, 400, {erreur: 'Formats acceptés : png, jpg, gif, webp, svg'});
			const data = await readBody(req);
			await mkdir(join(PUBLIC, 'uploads'), {recursive: true});
			await writeFile(join(PUBLIC, 'uploads', nom), data);
			return json(res, 200, {ok: true, chemin: `uploads/${nom}`});
		}

		// Fichiers statiques
		const file = normalize(join(PUBLIC, p));
		if (!file.startsWith(PUBLIC)) {
			res.writeHead(403);
			return res.end();
		}
		const info = await stat(file).catch(() => null);
		if (!info || !info.isFile()) {
			res.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'});
			return res.end('Introuvable');
		}
		res.writeHead(200, {
			'Content-Type': MIME[extname(file).toLowerCase()] || 'application/octet-stream',
			'Cache-Control': 'no-cache',
		});
		res.end(await readFile(file));
	} catch (err) {
		console.error(err);
		json(res, 500, {erreur: String(err.message || err)});
	}
});

server.listen(PORT, HOST, () => {
	console.log(`\n  OBS Overlay Kit ${VERSION} prêt !`);
	console.log(`  ${MENTION}`);
	console.log(`  ${PROPRIETAIRE.email} · Tél. / WhatsApp ${PROPRIETAIRE.telephone} · ${PROPRIETAIRE.site}\n`);
	console.log(`  Régie (panneau de contrôle) : http://localhost:${PORT}/controle.html`);
	console.log(`  Overlay pour OBS            : http://localhost:${PORT}/overlay.html   (1920 x 1080)`);
	const adresses = LAN ? adressesLocales() : [];
	for (const a of adresses) console.log(`  Depuis un téléphone         : ${a.url}`);
	console.log(`  Bibles installées           : ${listeVersions(BIBLES).map((b) => b.nom).join(', ') || 'aucune'}`);
	const lic = licence.public();
	console.log(`  Licence                     : ${lic.statut === 'active' ? `${lic.plan} — ${lic.client}` : lic.message}`);
	console.log(`  Code de ce poste            : ${licence.machine}`);
	if (adresses.length) {
		console.log('\n  Scannez ce QR code avec l\'appareil photo du téléphone (même Wi-Fi que ce PC) :\n');
		console.log(qrTerminal(adresses[0].url));
		console.log('\n  Le téléphone n\'arrive pas à se connecter ? Double-cliquez sur AUTORISER-TELEPHONE.bat');
	} else if (!LAN) {
		console.log('  Piloter depuis un téléphone : lancez plutôt DEMARRER-avec-telephone.bat');
	}
	console.log('\n  Laissez cette fenêtre ouverte pendant le direct. Ctrl+C pour arrêter.\n');
});
