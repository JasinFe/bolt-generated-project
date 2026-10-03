// Gestionnaire de licences OBS Overlay Kit — aucune dépendance, Node.js 18+ suffit.
//   node server.mjs             -> http://localhost:4444 (ce PC uniquement)
//   node server.mjs --public    -> écoute aussi le réseau : nécessaire pour l'activation en ligne
//                                  (placez-le derrière un reverse proxy HTTPS : Caddy, Nginx…)
//   PORT=5000 node server.mjs   -> autre port
import {createServer} from 'node:http';
import {readFile, stat} from 'node:fs/promises';
import {existsSync, readFileSync, writeFileSync, mkdirSync, copyFileSync} from 'node:fs';
import {extname, join, normalize, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {randomBytes, scryptSync, timingSafeEqual, createHash} from 'node:crypto';
import {Base, statutEffectif} from './lib/base.mjs';
import {FONCTIONS, signerLicence, lireLicence, genererPaire, empreinteCle, nouvelId, aujourdhui, ajouterDuree, joursRestants, fichierLic} from './lib/licence.mjs';

const ROOT = dirname(fileURLToPath(import.meta.url));
const PUBLIC = join(ROOT, 'public');
const DATA = join(ROOT, 'data');
const PORT = Number(process.env.PORT) || 4444;
const PUBLIQUE = process.argv.includes('--public');
const HOST = PUBLIQUE ? '0.0.0.0' : '127.0.0.1';
const VERSION = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')).version;

mkdirSync(DATA, {recursive: true});
const base = new Base(DATA);

// --- Paire de clés de signature ------------------------------------------------------
// La clé privée ne quitte jamais ce dossier. La clé publique est copiée dans le kit
// (obs-overlay-kit/licence/cle-publique.pem) pour qu'il puisse vérifier les licences.
const PRIV = join(DATA, 'cle-privee.pem');
const PUB = join(DATA, 'cle-publique.pem');
if (!existsSync(PRIV)) {
	const k = genererPaire();
	writeFileSync(PRIV, k.privee, {mode: 0o600});
	writeFileSync(PUB, k.publique);
	base.journaliser('systeme', 'Nouvelle paire de clés de signature générée');
	base.ecrire();
}
let clePrivee = readFileSync(PRIV, 'utf8');
let clePublique = readFileSync(PUB, 'utf8');

// --- Sessions administrateur -----------------------------------------------------------
const sessions = new Map(); // jeton -> expiration
const SESSION_MS = 12 * 3600 * 1000;
const hacher = (mdp, sel = randomBytes(16).toString('hex')) => ({sel, hash: scryptSync(mdp, sel, 64).toString('hex')});
const mdpOk = (mdp) => {
	const m = base.d.config.motDePasse;
	if (!m) return false;
	const h = scryptSync(String(mdp), m.sel, 64);
	return timingSafeEqual(h, Buffer.from(m.hash, 'hex'));
};
const cookie = (req) => Object.fromEntries((req.headers.cookie || '').split(';').map((c) => c.trim().split('=')).filter((x) => x[0]));
const connecte = (req) => {
	const j = cookie(req).olm_session;
	const exp = j && sessions.get(j);
	if (!exp || exp < Date.now()) return false;
	sessions.set(j, Date.now() + SESSION_MS);
	return true;
};

// Jeton d'API (automatisation : boutique en ligne, script) — seule son empreinte est conservée
const empreinteJeton = (j) => createHash('sha256').update(String(j)).digest('hex');
const jetonOk = (req) => {
	const j = /^Bearer\s+(\S+)$/.exec(req.headers.authorization || '')?.[1];
	const h = base.d.config.jetonApi;
	return !!(j && h && timingSafeEqual(Buffer.from(empreinteJeton(j)), Buffer.from(h)));
};

// Limiteur simple (connexion, API publique) : n requêtes par fenêtre et par adresse IP
const compteurs = new Map();
const limite = (cle, max, fenetreMs) => {
	const now = Date.now();
	const c = compteurs.get(cle) || {n: 0, debut: now};
	if (now - c.debut > fenetreMs) Object.assign(c, {n: 0, debut: now});
	c.n++;
	compteurs.set(cle, c);
	return c.n > max;
};
setInterval(() => {
	const now = Date.now();
	for (const [k, c] of compteurs) if (now - c.debut > 3600000) compteurs.delete(k);
	for (const [k, exp] of sessions) if (exp < now) sessions.delete(k);
}, 600000).unref();

// --- Licences ------------------------------------------------------------------------------
const nettoyer = (v, max = 200) => String(v ?? '').trim().slice(0, max);
const contenuSigne = (l) => ({
	id: l.id,
	p: l.offre,
	pn: l.offreNom,
	n: l.client.nom,
	e: l.client.email,
	o: l.client.organisation,
	f: l.fonctions,
	m: l.machine,
	a: l.postes,
	d: l.emise,
	x: l.expire,
	s: l.enLigne ? base.d.config.serveurPublic : '',
});
const signer = (l) => {
	l.cle = signerLicence(contenuSigne(l), clePrivee);
	l.majLe = Date.now();
	return l;
};
const versPublic = (l) => ({...l, statutEffectif: statutEffectif(l, base.d.config.alerteJours)});

const creerLicence = (b) => {
	const offre = base.d.offres.find((o) => o.code === b.offre) || {code: 'perso', nom: 'Personnalisée', prix: 0, duree: 365, postes: 1, fonctions: []};
	const emise = /^\d{4}-\d\d-\d\d$/.test(b.emise || '') ? b.emise : aujourdhui();
	const duree = b.duree !== undefined && b.duree !== '' ? Number(b.duree) : offre.duree;
	const expire = b.expire !== undefined ? (/^\d{4}-\d\d-\d\d$/.test(b.expire) ? b.expire : '') : ajouterDuree(emise, duree);
	const l = {
		id: nouvelId(),
		offre: offre.code,
		offreNom: nettoyer(b.offreNom || offre.nom, 60),
		client: {
			nom: nettoyer(b.client?.nom, 120) || 'Client',
			email: nettoyer(b.client?.email, 160).toLowerCase(),
			organisation: nettoyer(b.client?.organisation, 120),
			pays: nettoyer(b.client?.pays, 60),
			telephone: nettoyer(b.client?.telephone, 40),
		},
		fonctions: (Array.isArray(b.fonctions) ? b.fonctions : offre.fonctions).filter((f) => f in FONCTIONS),
		machine: nettoyer(b.machine, 19).toUpperCase(),
		postes: Math.max(0, Math.min(9999, Number(b.postes ?? offre.postes) || 0)),
		emise,
		expire,
		enLigne: !!b.enLigne && !!base.d.config.serveurPublic,
		prix: Math.max(0, Number(b.prix ?? offre.prix) || 0),
		devise: nettoyer(b.devise || base.d.config.devise, 5).toUpperCase(),
		paiement: {mode: nettoyer(b.paiement?.mode, 40), reference: nettoyer(b.paiement?.reference, 80), statut: ['paye', 'en-attente', 'offert'].includes(b.paiement?.statut) ? b.paiement.statut : 'paye'},
		statut: 'active',
		notes: nettoyer(b.notes, 2000),
		tags: (Array.isArray(b.tags) ? b.tags : String(b.tags || '').split(',')).map((t) => nettoyer(t, 30)).filter(Boolean),
		creeLe: Date.now(),
		activations: [],
		renouvellements: [],
	};
	return signer(l);
};

// Champs modifiables d'une licence existante (le contenu signé change -> nouvelle clé)
const modifierLicence = (l, b) => {
	if (b.client) for (const k of ['nom', 'email', 'organisation', 'pays', 'telephone']) if (b.client[k] !== undefined) l.client[k] = nettoyer(b.client[k], 160);
	if (b.offre && base.d.offres.some((o) => o.code === b.offre)) {
		l.offre = b.offre;
		l.offreNom = base.d.offres.find((o) => o.code === b.offre).nom;
	}
	if (b.offreNom !== undefined) l.offreNom = nettoyer(b.offreNom, 60);
	if (Array.isArray(b.fonctions)) l.fonctions = b.fonctions.filter((f) => f in FONCTIONS);
	if (b.machine !== undefined) l.machine = nettoyer(b.machine, 19).toUpperCase();
	if (b.postes !== undefined) l.postes = Math.max(0, Number(b.postes) || 0);
	if (b.expire !== undefined) l.expire = /^\d{4}-\d\d-\d\d$/.test(b.expire) ? b.expire : '';
	if (b.enLigne !== undefined) l.enLigne = !!b.enLigne && !!base.d.config.serveurPublic;
	if (b.prix !== undefined) l.prix = Math.max(0, Number(b.prix) || 0);
	if (b.devise) l.devise = nettoyer(b.devise, 5).toUpperCase();
	if (b.paiement) l.paiement = {...l.paiement, ...b.paiement};
	if (b.notes !== undefined) l.notes = nettoyer(b.notes, 2000);
	if (b.tags !== undefined) l.tags = (Array.isArray(b.tags) ? b.tags : String(b.tags).split(',')).map((t) => nettoyer(t, 30)).filter(Boolean);
	return signer(l);
};

// Ajoute une durée à partir de l'expiration actuelle (ou d'aujourd'hui si déjà expirée)
const prolonger = (l, jours, prix) => {
	const depart = l.expire && joursRestants(l.expire) >= 0 ? l.expire : aujourdhui();
	const avant = l.expire;
	l.expire = jours > 0 ? ajouterDuree(depart, jours) : '';
	l.renouvellements.push({t: Date.now(), de: avant, a: l.expire, prix: Number(prix) || 0, devise: l.devise});
	if (l.statut === 'suspendue') l.statut = 'active';
	return signer(l);
};

// --- Exports ---------------------------------------------------------------------------------
const csvCellule = (v) => {
	let s = String(v ?? '');
	if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`; // évite l'injection de formules dans Excel
	return /[";\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};
const exportCsv = () => {
	const cols = ['id', 'statut', 'offre', 'client', 'email', 'organisation', 'pays', 'emise', 'expire', 'postes', 'activations', 'prix', 'devise', 'paiement', 'reference', 'tags', 'cle'];
	const lignes = base.d.licences.map((l) => [l.id, statutEffectif(l), l.offreNom, l.client.nom, l.client.email, l.client.organisation, l.client.pays, l.emise, l.expire || 'perpétuelle', l.postes || 'illimité', l.activations.length, String(l.prix).replace('.', ','), l.devise, l.paiement.statut, l.paiement.reference, l.tags.join(' '), l.cle]);
	return '﻿' + [cols, ...lignes].map((r) => r.map(csvCellule).join(';')).join('\r\n');
};

const remplacerVariables = (modele, l) =>
	modele.replace(/\{(\w+)\}/g, (m, k) => ({
		client: l.client.nom,
		produit: base.d.config.produit,
		offre: l.offreNom,
		cle: l.cle,
		id: l.id,
		validite: l.expire ? `jusqu'au ${l.expire.split('-').reverse().join('/')}` : 'sans limite de durée',
		vendeur: base.d.config.vendeur.nom || base.d.config.produit,
		postes: l.postes || 'illimité',
	})[k] ?? m);

// --- API publique d'activation (appelée par le kit) -----------------------------------------
const apiPublique = (action, b, ip) => {
	const r = lireLicence(b.cle, clePublique);
	if (!r.valide) return [400, {ok: false, statut: 'invalide', message: r.erreur}];
	const l = base.licence(r.contenu.id);
	if (!l) return [404, {ok: false, statut: 'inconnue', message: 'Licence inconnue de ce serveur.'}];
	if (l.cle !== r.cle) return [409, {ok: false, statut: 'remplacee', message: 'Cette clé a été remplacée par une nouvelle version : demandez votre nouvelle clé au vendeur.'}];
	const machine = nettoyer(b.machine, 19);
	const poste = b.poste || {};
	const existe = l.activations.find((a) => a.machine === machine);
	if (action === 'desactiver') {
		if (existe) {
			l.activations = l.activations.filter((a) => a !== existe);
			base.journaliser('desactivation', `Poste ${machine} (${existe.nom}) libéré par le client`, l.id);
			base.ecrire();
		}
		return [200, {ok: true, message: 'Poste libéré.'}];
	}
	const st = statutEffectif(l);
	if (st === 'revoquee') return [403, {ok: false, statut: 'revoquee', message: 'Cette licence a été révoquée. Contactez le vendeur.'}];
	if (st === 'suspendue') return [403, {ok: false, statut: 'suspendue', message: 'Cette licence est suspendue (paiement en attente ?). Contactez le vendeur.'}];
	if (st === 'expiree') return [403, {ok: false, statut: 'expiree', message: `Licence expirée le ${l.expire}.`}];
	if (l.machine && l.machine !== machine) return [403, {ok: false, statut: 'refusee', message: 'Licence liée à un autre poste.'}];
	if (!existe) {
		if (l.postes && l.activations.length >= l.postes)
			return [403, {ok: false, statut: 'refusee', message: `Nombre maximal de postes atteint (${l.postes}). Désactivez la licence sur un ancien PC ou demandez au vendeur de libérer un poste.`}];
		l.activations.push({machine, premiere: Date.now(), derniere: Date.now(), verifs: 1, nom: nettoyer(poste.nom, 60), os: nettoyer(poste.os, 60), version: nettoyer(poste.version, 20), arch: nettoyer(poste.arch, 10), ip});
		base.journaliser('activation', `Activée sur ${nettoyer(poste.nom, 60) || machine} (${nettoyer(poste.os, 60)})`, l.id);
	} else Object.assign(existe, {derniere: Date.now(), verifs: (existe.verifs || 0) + 1, os: nettoyer(poste.os, 60) || existe.os, version: nettoyer(poste.version, 20) || existe.version, nom: nettoyer(poste.nom, 60) || existe.nom, ip});
	base.ecrire();
	return [200, {ok: true, statut: 'active', expire: l.expire, message: st === 'expire-bientot' ? `Licence valable jusqu'au ${l.expire} : pensez à la renouveler.` : ''}];
};

// --- HTTP ---------------------------------------------------------------------------------------
const MIME = {'.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon'};
const ENTETES = {'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'no-referrer', 'X-Frame-Options': 'DENY'};
const json = (res, code, obj) => {
	res.writeHead(code, {...ENTETES, 'Content-Type': MIME['.json'], 'Cache-Control': 'no-store'});
	res.end(JSON.stringify(obj));
};
const fichier = (res, nom, type, contenu) => {
	res.writeHead(200, {...ENTETES, 'Content-Type': type, 'Content-Disposition': `attachment; filename="${nom}"`, 'Cache-Control': 'no-store'});
	res.end(contenu);
};
const lireCorps = (req, limiteOctets = 2 * 1024 * 1024) =>
	new Promise((ok, ko) => {
		const morceaux = [];
		let n = 0;
		req.on('data', (c) => {
			n += c.length;
			if (n > limiteOctets) {
				ko(new Error('Requête trop volumineuse'));
				req.destroy();
			} else morceaux.push(c);
		});
		req.on('end', () => {
			try {
				ok(JSON.parse(Buffer.concat(morceaux).toString('utf8') || '{}'));
			} catch {
				ko(new Error('JSON invalide'));
			}
		});
		req.on('error', ko);
	});

const donnees = () => {
	const {motDePasse, jetonApi, ...config} = base.d.config;
	return {
		version: VERSION,
		config: {...config, jetonActif: !!jetonApi},
		offres: base.d.offres,
		fonctions: FONCTIONS,
		licences: base.d.licences.map(versPublic),
		journal: base.d.journal.slice(-1500),
		cle: {publique: clePublique, empreinte: empreinteCle(clePublique)},
		publique: PUBLIQUE,
	};
};

const server = createServer(async (req, res) => {
	try {
		const url = new URL(req.url, 'http://localhost');
		const p = decodeURIComponent(url.pathname);
		// derrière un reverse proxy (TRUST_PROXY=1), l'adresse réelle du client est dans X-Forwarded-For
		const ip = (process.env.TRUST_PROXY === '1' && (req.headers['x-forwarded-for'] || '').split(',')[0].trim()) || req.socket.remoteAddress || '';

		// API publique : seule partie accessible sans mot de passe
		const pub = /^\/api\/public\/(activer|verifier|desactiver)$/.exec(p);
		if (pub) {
			if (req.method !== 'POST') return json(res, 405, {ok: false});
			if (limite(`pub:${ip}`, 60, 60000)) return json(res, 429, {ok: false, message: 'Trop de requêtes, réessayez plus tard.'});
			const [code, rep] = apiPublique(pub[1], await lireCorps(req, 16 * 1024), ip);
			return json(res, code, rep);
		}
		if (p === '/api/public/statut') return json(res, 200, {ok: true, produit: base.d.config.produit});

		// --- Session ---
		if (p === '/api/session') return json(res, 200, {configure: !!base.d.config.motDePasse, connecte: connecte(req)});
		const ouvrirSession = () => {
			const j = randomBytes(32).toString('hex');
			sessions.set(j, Date.now() + SESSION_MS);
			res.setHeader('Set-Cookie', `olm_session=${j}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${SESSION_MS / 1000}`);
		};
		if (p === '/api/installation' && req.method === 'POST') {
			if (base.d.config.motDePasse) return json(res, 409, {erreur: 'Déjà configuré.'});
			const b = await lireCorps(req);
			if (String(b.motDePasse || '').length < 8) return json(res, 400, {erreur: 'Le mot de passe doit contenir au moins 8 caractères.'});
			base.d.config.motDePasse = hacher(String(b.motDePasse));
			if (b.vendeur) base.d.config.vendeur = {...base.d.config.vendeur, nom: nettoyer(b.vendeur.nom, 120), email: nettoyer(b.vendeur.email, 160)};
			if (b.devise) base.d.config.devise = nettoyer(b.devise, 5).toUpperCase();
			base.journaliser('systeme', 'Compte administrateur créé');
			base.ecrire();
			ouvrirSession();
			return json(res, 200, {ok: true});
		}
		if (p === '/api/connexion' && req.method === 'POST') {
			if (limite(`login:${ip}`, 8, 5 * 60000)) return json(res, 429, {erreur: 'Trop de tentatives. Patientez 5 minutes.'});
			const b = await lireCorps(req);
			if (!mdpOk(b.motDePasse)) {
				base.journaliser('securite', `Échec de connexion depuis ${ip}`);
				base.ecrire();
				return json(res, 401, {erreur: 'Mot de passe incorrect.'});
			}
			ouvrirSession();
			return json(res, 200, {ok: true});
		}
		if (p === '/api/deconnexion' && req.method === 'POST') {
			sessions.delete(cookie(req).olm_session);
			res.setHeader('Set-Cookie', 'olm_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0');
			return json(res, 200, {ok: true});
		}

		// --- API d'administration (connexion obligatoire) ---
		if (p.startsWith('/api/')) {
			const parJeton = jetonOk(req);
			if (parJeton) {
				// le jeton ne donne accès qu'à la création / lecture des licences
				if (!(p === '/api/licences' || p === '/api/donnees' || /^\/api\/licences\/OOK-[A-Z0-9-]+\/(lic|email)$/.test(p))) return json(res, 403, {erreur: 'Action non autorisée avec un jeton d’API.'});
			} else {
				if (limite(`api:${ip}`, 600, 60000)) return json(res, 429, {erreur: 'Trop de requêtes.'});
				if (!connecte(req)) return json(res, 401, {erreur: 'Session expirée : reconnectez-vous.'});
				// protection CSRF : les écritures doivent venir de l'application (en-tête personnalisé)
				if (req.method !== 'GET' && req.headers['x-olm'] !== '1') return json(res, 403, {erreur: 'Requête refusée.'});
			}
		}

		if (p === '/api/jeton' && req.method === 'POST') {
			const b = await lireCorps(req);
			if (b.supprimer) {
				base.d.config.jetonApi = '';
				base.journaliser('securite', 'Jeton d’API supprimé');
				base.ecrire();
				return json(res, 200, {ok: true});
			}
			const j = `olm_${randomBytes(24).toString('base64url')}`;
			base.d.config.jetonApi = empreinteJeton(j);
			base.journaliser('securite', 'Nouveau jeton d’API généré');
			base.ecrire();
			return json(res, 200, {ok: true, jeton: j});
		}

		if (p === '/api/donnees') return json(res, 200, donnees());

		if (p === '/api/licences' && req.method === 'POST') {
			const b = await lireCorps(req);
			const n = Math.max(1, Math.min(500, Number(b.quantite) || 1));
			const crees = [];
			for (let i = 0; i < n; i++) crees.push(creerLicence(b));
			base.d.licences.push(...crees);
			base.journaliser('creation', n > 1 ? `${n} licences « ${crees[0].offreNom} » créées pour ${crees[0].client.nom}` : `Licence « ${crees[0].offreNom} » créée pour ${crees[0].client.nom}`, crees[0].id);
			base.ecrire();
			return json(res, 200, {ok: true, licences: crees.map(versPublic)});
		}

		const lm = /^\/api\/licences\/(OOK-[A-Z0-9-]+)(?:\/(\w+))?(?:\/([A-Z0-9-]+))?$/.exec(p);
		if (lm) {
			const l = base.licence(lm[1]);
			if (!l) return json(res, 404, {erreur: 'Licence introuvable.'});
			const [, , action, machine] = lm;
			if (!action && req.method === 'DELETE') {
				base.d.licences = base.d.licences.filter((x) => x !== l);
				base.journaliser('suppression', `Licence de ${l.client.nom} supprimée`, l.id);
				base.ecrire();
				return json(res, 200, {ok: true});
			}
			if (action === 'lic') return fichier(res, `licence-${l.id}.lic`, 'text/plain; charset=utf-8', fichierLic(l.cle, contenuSigne(l), base.d.config.produit));
			if (action === 'email') return json(res, 200, {sujet: `Votre licence ${base.d.config.produit} — ${l.offreNom}`, corps: remplacerVariables(base.d.config.modeleEmail, l)});
			if (action === 'activations' && req.method === 'DELETE') {
				l.activations = l.activations.filter((a) => a.machine !== machine);
				base.journaliser('desactivation', `Poste ${machine} libéré par l'administrateur`, l.id);
				base.ecrire();
				return json(res, 200, {ok: true, licence: versPublic(l)});
			}
			if (req.method === 'POST') {
				const b = await lireCorps(req);
				let msg;
				if (action === 'revoquer') (l.statut = 'revoquee'), (msg = `Licence révoquée${b.motif ? ` : ${nettoyer(b.motif, 200)}` : ''}`);
				else if (action === 'suspendre') (l.statut = 'suspendue'), (msg = 'Licence suspendue');
				else if (action === 'reactiver') (l.statut = 'active'), (msg = 'Licence réactivée');
				else if (action === 'prolonger') {
					prolonger(l, Number(b.jours) || 0, b.prix);
					msg = l.expire ? `Licence prolongée jusqu'au ${l.expire}` : 'Licence rendue perpétuelle';
				} else if (action === 'modifier') {
					modifierLicence(l, b);
					msg = 'Licence modifiée (nouvelle clé émise)';
				} else if (action === 'dupliquer') {
					const c = creerLicence({...l, machine: '', client: {...l.client, ...(b.client || {})}, expire: undefined, emise: aujourdhui(), duree: base.d.offres.find((o) => o.code === l.offre)?.duree ?? 365});
					base.d.licences.push(c);
					base.journaliser('creation', `Licence dupliquée depuis ${l.id}`, c.id);
					base.ecrire();
					return json(res, 200, {ok: true, licence: versPublic(c)});
				} else return json(res, 404, {erreur: 'Action inconnue.'});
				l.majLe = Date.now();
				base.journaliser(action, msg, l.id);
				base.ecrire();
				return json(res, 200, {ok: true, licence: versPublic(l)});
			}
		}

		if (p === '/api/verifier' && req.method === 'POST') {
			const b = await lireCorps(req);
			const r = lireLicence(b.cle, clePublique);
			const l = r.contenu?.id && base.licence(r.contenu.id);
			return json(res, 200, {...r, enBase: l ? versPublic(l) : null, aJour: l ? l.cle === r.cle : null});
		}

		if (p === '/api/offres' && req.method === 'PUT') {
			const b = await lireCorps(req);
			if (!Array.isArray(b.offres) || !b.offres.length) return json(res, 400, {erreur: 'Au moins une offre est nécessaire.'});
			const codes = new Set();
			base.d.offres = b.offres.map((o) => {
				let code = nettoyer(o.code, 30).toLowerCase().replace(/[^a-z0-9-]+/g, '-') || 'offre';
				while (codes.has(code)) code += '-2';
				codes.add(code);
				return {code, nom: nettoyer(o.nom, 60) || code, prix: Math.max(0, Number(o.prix) || 0), duree: Math.max(0, Number(o.duree) || 0), postes: Math.max(0, Number(o.postes) || 0), fonctions: (o.fonctions || []).filter((f) => f in FONCTIONS), description: nettoyer(o.description, 300), actif: o.actif !== false};
			});
			base.journaliser('offres', `Offres mises à jour (${base.d.offres.length})`);
			base.ecrire();
			return json(res, 200, {ok: true, offres: base.d.offres});
		}

		if (p === '/api/config' && req.method === 'PUT') {
			const b = await lireCorps(req);
			const c = base.d.config;
			if (b.produit !== undefined) c.produit = nettoyer(b.produit, 60) || 'OBS Overlay Kit';
			if (b.devise) c.devise = nettoyer(b.devise, 5).toUpperCase();
			if (b.vendeur) for (const k of ['nom', 'email', 'site', 'adresse']) if (b.vendeur[k] !== undefined) c.vendeur[k] = nettoyer(b.vendeur[k], 300);
			if (b.serveurPublic !== undefined) {
				const s = nettoyer(b.serveurPublic, 200).replace(/\/+$/, '');
				if (s && !/^https?:\/\/[^\s/]+/.test(s)) return json(res, 400, {erreur: 'Adresse du serveur invalide (ex. https://licences.mondomaine.com).'});
				c.serveurPublic = s;
			}
			if (b.activationEnLigneParDefaut !== undefined) c.activationEnLigneParDefaut = !!b.activationEnLigneParDefaut;
			if (b.alerteJours !== undefined) c.alerteJours = Math.max(1, Math.min(365, Number(b.alerteJours) || 30));
			if (b.modeleEmail !== undefined) c.modeleEmail = String(b.modeleEmail).slice(0, 5000);
			base.journaliser('parametres', 'Paramètres modifiés');
			base.ecrire();
			return json(res, 200, {ok: true});
		}

		if (p === '/api/mot-de-passe' && req.method === 'POST') {
			const b = await lireCorps(req);
			if (!mdpOk(b.ancien)) return json(res, 400, {erreur: 'Mot de passe actuel incorrect.'});
			if (String(b.nouveau || '').length < 8) return json(res, 400, {erreur: 'Au moins 8 caractères.'});
			base.d.config.motDePasse = hacher(String(b.nouveau));
			base.journaliser('securite', 'Mot de passe modifié');
			base.ecrire();
			return json(res, 200, {ok: true});
		}

		if (p === '/api/cles/regenerer' && req.method === 'POST') {
			const b = await lireCorps(req);
			if (b.confirmation !== 'REGENERER') return json(res, 400, {erreur: 'Confirmation manquante.'});
			const horo = new Date().toISOString().replace(/[:.]/g, '-');
			copyFileSync(PRIV, join(DATA, 'sauvegardes', `cle-privee-${horo}.pem`));
			copyFileSync(PUB, join(DATA, 'sauvegardes', `cle-publique-${horo}.pem`));
			const k = genererPaire();
			writeFileSync(PRIV, k.privee, {mode: 0o600});
			writeFileSync(PUB, k.publique);
			clePrivee = k.privee;
			clePublique = k.publique;
			if (b.resigner) for (const l of base.d.licences) signer(l);
			base.journaliser('securite', `Nouvelle paire de clés générée${b.resigner ? ' et toutes les licences ont été signées à nouveau' : ''}`);
			base.ecrire();
			return json(res, 200, {ok: true});
		}

		if (p === '/api/export/licences.csv') return fichier(res, `licences-${aujourdhui()}.csv`, 'text/csv; charset=utf-8', exportCsv());
		if (p === '/api/export/sauvegarde.json') {
			const {motDePasse, jetonApi, ...config} = base.d.config;
			return fichier(res, `sauvegarde-licences-${aujourdhui()}.json`, MIME['.json'], JSON.stringify({...base.d, config}, null, 1));
		}
		if (p === '/api/export/cle-publique.pem') return fichier(res, 'cle-publique.pem', 'application/x-pem-file', clePublique);
		if (p === '/api/export/vendeur.json') {
			const v = base.d.config.vendeur;
			return fichier(res, 'vendeur.json', MIME['.json'], JSON.stringify({nom: v.nom, email: v.email, site: v.site}, null, 2));
		}
		if (p === '/api/import' && req.method === 'POST') {
			const b = await lireCorps(req, 50 * 1024 * 1024);
			if (!Array.isArray(b.licences)) return json(res, 400, {erreur: 'Fichier de sauvegarde invalide.'});
			const ids = new Set(base.d.licences.map((l) => l.id));
			let n = 0;
			for (const l of b.licences) {
				if (!l?.id || !l.cle || ids.has(l.id)) continue;
				base.d.licences.push({activations: [], renouvellements: [], tags: [], paiement: {statut: 'paye'}, client: {}, ...l});
				n++;
			}
			if (b.remplacerOffres && Array.isArray(b.offres)) base.d.offres = b.offres;
			base.journaliser('import', `${n} licence(s) importée(s)`);
			base.ecrire();
			return json(res, 200, {ok: true, importees: n});
		}

		if (p.startsWith('/api/')) return json(res, 404, {erreur: 'Introuvable'});

		// Fichiers statiques
		const f = normalize(join(PUBLIC, p === '/' ? 'index.html' : p));
		if (!f.startsWith(PUBLIC)) return json(res, 403, {erreur: 'Interdit'});
		const info = await stat(f).catch(() => null);
		if (!info?.isFile()) return json(res, 404, {erreur: 'Introuvable'});
		res.writeHead(200, {...ENTETES, 'Content-Type': MIME[extname(f)] || 'application/octet-stream', 'Cache-Control': 'no-cache'});
		res.end(await readFile(f));
	} catch (err) {
		console.error(err);
		json(res, 500, {erreur: String(err.message || err)});
	}
});

server.listen(PORT, HOST, () => {
	console.log(`\n  Gestionnaire de licences ${VERSION} prêt !\n`);
	console.log(`  Tableau de bord : http://localhost:${PORT}`);
	console.log(`  Empreinte de la clé publique : ${empreinteCle(clePublique)}`);
	if (PUBLIQUE) console.log(`  API d'activation ouverte sur le réseau : POST /api/public/activer (placez un HTTPS devant)`);
	console.log('\n  Laissez cette fenêtre ouverte. Ctrl+C pour arrêter.\n');
});
