// Base de données du gestionnaire de licences : un simple fichier JSON (data/base.json),
// écrit de façon atomique (fichier temporaire puis renommage) et sauvegardé chaque jour.
import {existsSync, readFileSync, writeFileSync, renameSync, mkdirSync, readdirSync, unlinkSync} from 'node:fs';
import {join} from 'node:path';
import {aujourdhui, joursRestants} from './licence.mjs';

export const OFFRES_PAR_DEFAUT = [
	{code: 'essentiel', nom: 'Essentiel', prix: 29, duree: 365, postes: 1, fonctions: ['emissions', 'score'], description: 'Pour démarrer : habillage complet, émissions multiples, score.', actif: true},
	{code: 'createur', nom: 'Créateur', prix: 59, duree: 365, postes: 2, fonctions: ['emissions', 'score', 'chat', 'api', 'telephone'], description: 'Streamers et créateurs : chat en direct, Stream Deck, pilotage mobile.', actif: true},
	{code: 'eglise', nom: 'Église & Ministère', prix: 79, duree: 365, postes: 3, fonctions: ['emissions', 'score', 'chat', 'api', 'telephone', 'bible'], description: 'Toutes les fonctions, dont la Bible intégrée.', actif: true},
	{code: 'studio', nom: 'Studio (à vie)', prix: 199, duree: 0, postes: 5, fonctions: ['emissions', 'score', 'chat', 'api', 'telephone', 'bible'], description: 'Licence perpétuelle, 5 postes, toutes fonctions.', actif: true},
	{code: 'partenaire', nom: 'Partenaire / offerte', prix: 0, duree: 30, postes: 1, fonctions: ['emissions', 'score', 'chat', 'api', 'telephone', 'bible'], description: 'Licence offerte (presse, partenaires, tests).', actif: true},
];

const BASE_VIDE = () => ({
	version: 1,
	config: {
		produit: 'OBS Overlay Kit',
		devise: 'EUR',
		vendeur: {nom: '', email: '', site: '', adresse: ''},
		serveurPublic: '',
		activationEnLigneParDefaut: false,
		alerteJours: 30,
		modeleEmail:
			'Bonjour {client},\n\nMerci pour votre achat de {produit} ({offre}).\n\nVotre clé de licence :\n\n{cle}\n\nPour l’activer : ouvrez la régie, bouton « Licence », collez la clé puis « Activer ».\nN° de licence : {id} — valable {validite}.\n\nCordialement,\n{vendeur}',
		motDePasse: null,
	},
	offres: OFFRES_PAR_DEFAUT,
	licences: [],
	journal: [],
});

export class Base {
	constructor(dossier) {
		this.dossier = dossier;
		this.fichier = join(dossier, 'base.json');
		mkdirSync(join(dossier, 'sauvegardes'), {recursive: true});
		this.d = existsSync(this.fichier) ? {...BASE_VIDE(), ...JSON.parse(readFileSync(this.fichier, 'utf8'))} : BASE_VIDE();
		this.d.config = {...BASE_VIDE().config, ...this.d.config, vendeur: {...BASE_VIDE().config.vendeur, ...this.d.config?.vendeur}};
		this.ecrire();
		this.sauvegardeDuJour();
		setInterval(() => this.sauvegardeDuJour(), 6 * 3600 * 1000).unref();
	}

	ecrire() {
		const tmp = `${this.fichier}.tmp`;
		writeFileSync(tmp, JSON.stringify(this.d, null, 1));
		renameSync(tmp, this.fichier);
	}

	// Une copie par jour, 30 jours conservés
	sauvegardeDuJour() {
		const dos = join(this.dossier, 'sauvegardes');
		const f = join(dos, `base-${aujourdhui()}.json`);
		if (!existsSync(f)) writeFileSync(f, JSON.stringify(this.d));
		const toutes = readdirSync(dos).filter((n) => /^base-\d{4}-\d\d-\d\d\.json$/.test(n)).sort();
		for (const n of toutes.slice(0, -30)) unlinkSync(join(dos, n));
	}

	journaliser(type, message, licence = '') {
		this.d.journal.push({t: Date.now(), type, licence, message});
		if (this.d.journal.length > 5000) this.d.journal.splice(0, this.d.journal.length - 5000);
	}

	licence(id) {
		return this.d.licences.find((l) => l.id === id);
	}
}

// Statut calculé (le statut enregistré ne contient que les décisions manuelles)
export const statutEffectif = (l, alerteJours = 30, t = Date.now()) => {
	if (l.statut === 'revoquee') return 'revoquee';
	if (l.statut === 'suspendue') return 'suspendue';
	const j = joursRestants(l.expire, t);
	if (j !== null && j < 0) return 'expiree';
	if (j !== null && j <= alerteJours) return 'expire-bientot';
	return 'active';
};
