// Gestion de la licence du kit : période d'essai, clé signée, code machine, activation en ligne.
//
//   - Sans clé : 14 jours d'essai complet (filigrane discret), puis « mode gratuit » :
//     les habillages de base restent utilisables, avec un filigrane, et les fonctions avancées
//     (chat, Bible, téléphone, API, émissions multiples, score) sont verrouillées.
//   - Avec une clé valide : les fonctions de l'offre achetée, sans filigrane.
//   - La vérification se fait hors ligne (signature Ed25519). Si la clé désigne un serveur
//     d'activation, le kit s'y déclare puis le reconsulte chaque jour ; sans réponse, il continue
//     de fonctionner pendant 30 jours (délai de grâce).
import {readFileSync, existsSync} from 'node:fs';
import {writeFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {execSync} from 'node:child_process';
import {hostname, platform, arch, cpus, totalmem, release} from 'node:os';
import {FONCTIONS, PROPRIETAIRE, lireLicence, joursRestants, aujourdhui} from './lib/licence.mjs';

const JOUR = 86400000;
const ESSAI_JOURS = 14;
const GRACE_JOURS = 30;
const VERIF_INTERVALLE = JOUR;

// Identifiant stable du poste (ne contient aucune donnée personnelle : seulement une empreinte)
const idSysteme = () => {
	try {
		if (platform() === 'win32') {
			const out = execSync('reg query HKLM\\SOFTWARE\\Microsoft\\Cryptography /v MachineGuid', {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 3000});
			return out.match(/MachineGuid\s+REG_SZ\s+(\S+)/)?.[1] || '';
		}
		if (platform() === 'darwin') {
			const out = execSync('ioreg -rd1 -c IOPlatformExpertDevice', {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 3000});
			return out.match(/"IOPlatformUUID" = "([^"]+)"/)?.[1] || '';
		}
		for (const f of ['/etc/machine-id', '/var/lib/dbus/machine-id']) if (existsSync(f)) return readFileSync(f, 'utf8').trim();
	} catch {}
	return '';
};
export const codeMachine = () => {
	const base = [idSysteme() || hostname(), platform(), arch(), cpus()[0]?.model || ''].join('|');
	const h = createHash('sha256').update(`ook-poste|${base}`).digest();
	const ALPHA = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
	let s = '';
	for (let i = 0; i < 16; i++) s += ALPHA[h[i] % 32];
	return s.match(/.{4}/g).join('-');
};

export class Licence {
	constructor({fichier, clePublique, vendeur, version, onChange}) {
		this.fichier = fichier;
		this.clePublique = existsSync(clePublique) ? readFileSync(clePublique, 'utf8') : '';
		// coordonnées du vendeur affichées dans la régie (licence/vendeur.json, facultatif)
		try {
			this.vendeur = vendeur && existsSync(vendeur) ? JSON.parse(readFileSync(vendeur, 'utf8')) : null;
		} catch {
			this.vendeur = null;
		}
		this.version = version;
		this.onChange = onChange || (() => {});
		this.machine = codeMachine();
		this.data = {installeLe: Date.now(), cle: '', enLigne: null};
		try {
			if (existsSync(fichier)) Object.assign(this.data, JSON.parse(readFileSync(fichier, 'utf8')));
		} catch {}
		if (!existsSync(fichier)) this.sauver();
		this.calculer();
		setInterval(() => this.verifierEnLigne().catch(() => {}), 60 * 60 * 1000).unref();
		setTimeout(() => this.verifierEnLigne().catch(() => {}), 2000).unref();
		// recalcul quotidien (fin d'essai, expiration) même sans action
		setInterval(() => this.calculer(true), 10 * 60 * 1000).unref();
	}

	sauver() {
		return writeFile(this.fichier, JSON.stringify(this.data, null, 2)).catch(console.error);
	}

	// Calcule l'état courant à partir de la clé enregistrée
	calculer(notifier = false) {
		const avant = JSON.stringify(this.etat || null);
		const toutes = Object.keys(FONCTIONS);
		const finEssai = this.data.installeLe + ESSAI_JOURS * JOUR;
		const jEssai = Math.max(0, Math.ceil((finEssai - Date.now()) / JOUR));
		const base = {machine: this.machine, essaiJours: ESSAI_JOURS, toutesFonctions: FONCTIONS, verifiable: !!this.clePublique};
		const gratuit = (statut, message, extra = {}) => ({
			...base,
			statut,
			message,
			fonctions: jEssai > 0 && statut === 'essai' ? toutes : [],
			filigrane: statut === 'essai' ? `Version d'essai · ${jEssai} j` : `Version non activée · ${PROPRIETAIRE.site.replace('https://', '')}`,
			...extra,
		});
		let e;
		if (!this.data.cle) {
			e = jEssai > 0 ? gratuit('essai', `Essai gratuit : ${jEssai} jour(s) restant(s), toutes fonctions incluses.`, {joursRestants: jEssai}) : gratuit('gratuit', 'Période d’essai terminée. Les fonctions avancées sont verrouillées.');
		} else {
			const r = lireLicence(this.data.cle, this.clePublique);
			const c = r.contenu || {};
			const info = {id: c.id, plan: c.pn || c.p, client: c.n, organisation: c.o, email: c.e, expire: c.x || '', serveur: c.s || '', postes: c.a || 0};
			const jr = joursRestants(c.x);
			const enLigne = this.data.enLigne;
			if (!r.valide) e = gratuit('invalide', r.erreur, info);
			else if (c.m && c.m !== this.machine) e = gratuit('invalide', `Cette licence est liée à un autre poste (${c.m}). Code de ce poste : ${this.machine}.`, info);
			else if (jr !== null && jr < 0) e = gratuit('expiree', `Licence expirée le ${c.x}. Renouvelez-la pour retrouver toutes les fonctions.`, info);
			else if (c.s && enLigne?.statut && enLigne.statut !== 'active') e = gratuit(enLigne.statut === 'revoquee' ? 'revoquee' : 'refusee', enLigne.message || 'Licence refusée par le serveur d’activation.', info);
			else if (c.s && Date.now() - (enLigne?.derniere || this.data.activeLe || Date.now()) > GRACE_JOURS * JOUR)
				e = gratuit('hors-ligne', `Impossible de joindre le serveur d’activation depuis plus de ${GRACE_JOURS} jours. Connectez ce PC à Internet.`, info);
			else
				e = {
					...base,
					...info,
					statut: 'active',
					joursRestants: jr,
					fonctions: (c.f || []).filter((f) => f in FONCTIONS),
					filigrane: '',
					message: jr === null ? 'Licence active, sans limite de durée.' : jr <= 30 ? `Licence active, expire dans ${jr} jour(s) (${c.x}).` : `Licence active jusqu'au ${c.x}.`,
					enLigne: c.s ? {derniere: enLigne?.derniere || 0, message: enLigne?.message || ''} : null,
				};
		}
		this.etat = e;
		if (notifier && avant !== JSON.stringify(e)) this.onChange(e);
		return e;
	}

	a(fonction) {
		return this.etat.fonctions.includes(fonction);
	}

	// Ce que voient la régie et l'overlay (pas d'e-mail)
	public() {
		const {email, ...reste} = this.etat;
		return {...reste, vendeur: this.vendeur || PROPRIETAIRE, proprietaire: PROPRIETAIRE};
	}

	async activer(texte) {
		const r = lireLicence(texte, this.clePublique);
		if (!r.valide) return {ok: false, erreur: r.erreur};
		if (r.contenu.m && r.contenu.m !== this.machine) return {ok: false, erreur: `Cette licence est liée au poste ${r.contenu.m}. Le code de ce poste est ${this.machine}.`};
		const jr = joursRestants(r.contenu.x);
		if (jr !== null && jr < 0) return {ok: false, erreur: `Cette licence a expiré le ${r.contenu.x}.`};
		if (r.contenu.s) {
			const rep = await this.appel(r.contenu.s, 'activer', r.cle).catch((err) => ({hs: true, message: err.message}));
			if (!rep.hs && !rep.ok) return {ok: false, erreur: rep.message || 'Activation refusée par le serveur.'};
			this.data.enLigne = rep.hs ? null : {statut: 'active', derniere: Date.now(), message: rep.message || ''};
		} else this.data.enLigne = null;
		this.data.cle = r.cle;
		this.data.activeLe = Date.now();
		await this.sauver();
		this.calculer(true);
		return {ok: true, etat: this.public()};
	}

	async desactiver() {
		const c = lireLicence(this.data.cle, this.clePublique).contenu;
		if (c?.s) await this.appel(c.s, 'desactiver', this.data.cle).catch(() => {});
		this.data.cle = '';
		this.data.enLigne = null;
		await this.sauver();
		this.calculer(true);
		return {ok: true, etat: this.public()};
	}

	async appel(serveur, action, cle) {
		const ctrl = AbortSignal.timeout(8000);
		const res = await fetch(`${serveur.replace(/\/$/, '')}/api/public/${action}`, {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({cle, machine: this.machine, poste: {nom: hostname(), os: `${platform()} ${release()}`, arch: arch(), version: this.version, memoire: Math.round(totalmem() / 2 ** 30)}}),
			signal: ctrl,
		});
		const data = await res.json().catch(() => ({}));
		if (res.status >= 500) throw new Error(data.message || `Serveur indisponible (${res.status})`);
		return data;
	}

	// Signal de vie quotidien : permet au vendeur de suivre les postes actifs et de révoquer une clé
	async verifierEnLigne(force = false) {
		const c = this.data.cle && lireLicence(this.data.cle, this.clePublique);
		if (!c?.valide || !c.contenu.s) return;
		if (!force && Date.now() - (this.data.enLigne?.derniere || 0) < VERIF_INTERVALLE) return;
		const rep = await this.appel(c.contenu.s, 'verifier', this.data.cle).catch(() => null);
		if (!rep) return this.calculer(true); // pas de réseau : délai de grâce
		this.data.enLigne = {statut: rep.ok ? 'active' : rep.statut || 'refusee', derniere: Date.now(), message: rep.message || ''};
		await this.sauver();
		this.calculer(true);
	}
}

export {aujourdhui};
