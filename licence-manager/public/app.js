// Gestionnaire de licences — application d'administration (une seule page, sans dépendance).
import {colonnes, courbe, anneau, barres, chaleur, sparkline, nombre, cacherBulle} from './graphiques.js';

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

// --- Gabarits HTML avec échappement automatique --------------------------------------------
const ESC = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ESC[c]);
class Brut {
	constructor(s) {
		this.s = s;
	}
	toString() {
		return this.s;
	}
}
const brut = (s) => new Brut(s);
const html = (parts, ...vals) =>
	brut(parts.reduce((acc, p, i) => {
		const v = vals[i - 1];
		const s = v instanceof Brut ? v.s : Array.isArray(v) ? v.map((x) => (x instanceof Brut ? x.s : esc(x))).join('') : esc(v);
		return acc + s + p;
	}));

// --- Préférences locales (thème, période…) --------------------------------------------------
const pref = {
	lire: (k, d) => {
		try {
			return localStorage.getItem(`olm.${k}`) ?? d;
		} catch {
			return d;
		}
	},
	ecrire: (k, v) => {
		try {
			localStorage.setItem(`olm.${k}`, v);
		} catch {}
	},
};
const appliquerTheme = () => {
	const t = pref.lire('theme', '');
	if (t) document.documentElement.dataset.theme = t;
	else delete document.documentElement.dataset.theme;
};
appliquerTheme();
$('#btn-theme').addEventListener('click', () => {
	const sombre = document.documentElement.dataset.theme ? document.documentElement.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
	pref.ecrire('theme', sombre ? 'light' : 'dark');
	appliquerTheme();
	routeur();
});

// --- API -------------------------------------------------------------------------------------
const api = async (chemin, {methode = 'GET', corps} = {}) => {
	const r = await fetch(`/api/${chemin}`, {method: methode, headers: {'Content-Type': 'application/json', 'X-OLM': '1'}, body: corps ? JSON.stringify(corps) : undefined});
	const d = await r.json().catch(() => ({}));
	if (r.status === 401 && !chemin.startsWith('connexion')) {
		D = null;
		ecranConnexion();
		throw new Error(d.erreur || 'Session expirée');
	}
	if (!r.ok) throw new Error(d.erreur || `Erreur ${r.status}`);
	return d;
};

let toastT;
const toast = (txt, type = '') => {
	const t = $('#toast');
	t.textContent = txt;
	t.className = `on ${type}`;
	clearTimeout(toastT);
	toastT = setTimeout(() => (t.className = ''), 4000);
};
const copier = async (txt, quoi = 'Copié') => {
	try {
		await navigator.clipboard.writeText(txt);
		toast(`${quoi} dans le presse-papiers ✓`, 'ok');
	} catch {
		toast('Copie impossible : sélectionnez le texte manuellement.', 'ko');
	}
};
const telecharger = (url) => {
	const a = document.createElement('a');
	a.href = url;
	a.download = '';
	document.body.append(a);
	a.click();
	a.remove();
};

// --- Données ---------------------------------------------------------------------------------
let D = null;
const charger = async () => {
	D = await api('donnees');
	$('#nav-produit').textContent = D.config.produit;
	document.title = `Licences · ${D.config.produit}`;
	return D;
};
const majLicence = (l) => {
	const i = D.licences.findIndex((x) => x.id === l.id);
	if (i >= 0) D.licences[i] = l;
	else D.licences.push(l);
};

const STATUTS = {
	active: {lib: 'Active', icone: '✓', cls: 'good'},
	'expire-bientot': {lib: 'Expire bientôt', icone: '⏳', cls: 'warning'},
	expiree: {lib: 'Expirée', icone: '⌛', cls: 'serious'},
	suspendue: {lib: 'Suspendue', icone: '⏸', cls: 'neutre'},
	revoquee: {lib: 'Révoquée', icone: '⛔', cls: 'critical'},
};
const badge = (st) => html`<span class="badge ${STATUTS[st]?.cls}">${STATUTS[st]?.icone} ${STATUTS[st]?.lib || st}</span>`;
const PAIEMENTS = {paye: 'Payé', 'en-attente': 'En attente', offert: 'Offert'};
const date = (iso) => (iso ? iso.split('-').reverse().join('/') : '');
const dateT = (t) => (t ? new Date(t).toLocaleDateString('fr-FR') : '');
const dateHeure = (t) => (t ? new Date(t).toLocaleString('fr-FR', {dateStyle: 'short', timeStyle: 'short'}) : '');
const argent = (v, devise = D?.config.devise || 'EUR') => {
	try {
		return new Intl.NumberFormat('fr-FR', {style: 'currency', currency: devise, maximumFractionDigits: v % 1 ? 2 : 0}).format(v || 0);
	} catch {
		return `${nombre(v || 0)} ${devise}`;
	}
};
const dureeTxt = (j) => (!j ? 'Perpétuelle' : j % 365 === 0 ? `${j / 365} an${j > 365 ? 's' : ''}` : j % 30 === 0 ? `${j / 30} mois` : `${j} jours`);
const couleurOffre = (code) => {
	const i = D.offres.findIndex((o) => o.code === code);
	return i >= 0 && i < 8 ? `var(--s${i + 1})` : 'var(--muted)';
};
const nomOffre = (code) => D.offres.find((o) => o.code === code)?.nom || code;
const JOUR = 86400000;
const aujourdhui = () => new Date().toISOString().slice(0, 10);

// --- Boîte de dialogue générique -------------------------------------------------------------
const dlg = $('#dlg');
const ouvrirDialogue = (contenu, {large} = {}) => {
	const f = $('#dlg-corps');
	f.innerHTML = String(contenu);
	dlg.classList.toggle('large', !!large);
	$$('[data-fermer]', f).forEach((b) => b.addEventListener('click', (e) => (e.preventDefault(), dlg.close())));
	dlg.showModal();
	return f;
};
dlg.addEventListener('click', (e) => e.target === dlg && dlg.close());
const confirmer = (titre, texte, {bouton = 'Confirmer', danger = false, saisie} = {}) =>
	new Promise((ok) => {
		const f = ouvrirDialogue(html`<h2>${titre}</h2><p>${texte}</p>${saisie ? html`<label>${saisie}<input name="saisie" autocomplete="off"></label>` : ''}
			<div class="actions"><button type="button" class="sec" data-fermer>Annuler</button><button type="button" class="${danger ? 'danger' : ''}" id="dlg-ok">${bouton}</button></div>`);
		const fin = (v) => {
			dlg.removeEventListener('close', annul);
			dlg.close();
			ok(v);
		};
		const annul = () => ok(false);
		dlg.addEventListener('close', annul, {once: true});
		$('#dlg-ok', f).addEventListener('click', () => fin(saisie ? $('[name=saisie]', f).value || false : true));
	});

// --- Connexion / première installation ----------------------------------------------------------
const ecranConnexion = async () => {
	$('#nav').hidden = true;
	const s = await (await fetch('/api/session')).json();
	const v = $('#vue');
	v.className = 'vue centre';
	v.innerHTML = String(
		s.configure
			? html`<form class="carte connexion" id="f-auth">
				<img src="icone.svg" alt="" width="48" height="48">
				<h1>Gestionnaire de licences</h1>
				<label>Mot de passe administrateur<input type="password" name="motDePasse" autocomplete="current-password" required autofocus></label>
				<button>Se connecter</button>
				<p class="erreur" id="auth-err"></p>
			</form>`
			: html`<form class="carte connexion" id="f-auth">
				<img src="icone.svg" alt="" width="48" height="48">
				<h1>Bienvenue</h1>
				<p class="muted">Première utilisation : créez le mot de passe qui protégera vos licences et indiquez le nom qui apparaîtra sur les licences et les e-mails.</p>
				<label>Votre nom ou celui de votre société<input name="nom" required placeholder="Ex. : Studio Lumière"></label>
				<label>E-mail de contact<input type="email" name="email" placeholder="contact@…"></label>
				<label>Devise<select name="devise">${['EUR', 'USD', 'XOF', 'XAF', 'CDF', 'CAD', 'CHF', 'GBP', 'MAD'].map((d) => html`<option>${d}</option>`)}</select></label>
				<label>Mot de passe (8 caractères minimum)<input type="password" name="motDePasse" minlength="8" autocomplete="new-password" required></label>
				<label>Confirmez le mot de passe<input type="password" name="confirmation" minlength="8" autocomplete="new-password" required></label>
				<button>Créer mon espace</button>
				<p class="erreur" id="auth-err"></p>
			</form>`,
	);
	$('#f-auth').addEventListener('submit', async (e) => {
		e.preventDefault();
		const f = Object.fromEntries(new FormData(e.target));
		try {
			if (s.configure) await api('connexion', {methode: 'POST', corps: {motDePasse: f.motDePasse}});
			else {
				if (f.motDePasse !== f.confirmation) throw new Error('Les deux mots de passe ne correspondent pas.');
				await api('installation', {methode: 'POST', corps: {motDePasse: f.motDePasse, vendeur: {nom: f.nom, email: f.email}, devise: f.devise}});
			}
			await demarrer();
		} catch (err) {
			$('#auth-err').textContent = err.message;
		}
	});
};
$('#btn-deconnexion').addEventListener('click', async () => {
	await api('deconnexion', {methode: 'POST'}).catch(() => {});
	D = null;
	ecranConnexion();
});

// --- Routeur ------------------------------------------------------------------------------------
const PAGES = {};
const routeur = () => {
	if (!D) return;
	cacherBulle();
	const [chemin, requete] = location.hash.slice(2).split('?');
	const [page, param] = chemin.split('/');
	const p = PAGES[page] ? page : 'tableau';
	$$('#nav a').forEach((a) => a.classList.toggle('actif', a.dataset.page === (p === 'licence' ? 'licences' : p)));
	const v = $('#vue');
	v.className = `vue page-${p}`;
	v.innerHTML = '';
	PAGES[p](v, decodeURIComponent(param || ''), new URLSearchParams(requete || ''));
	v.focus?.();
	window.scrollTo(0, 0);
};
window.addEventListener('hashchange', routeur);
let resizeT;
window.addEventListener('resize', () => {
	clearTimeout(resizeT);
	resizeT = setTimeout(() => location.hash.startsWith('#/tableau') || location.hash === '' ? routeur() : null, 250);
});

const entete = (titre, sous, actions = '') => html`<header class="entete"><div><h1>${titre}</h1>${sous ? html`<p class="muted">${sous}</p>` : ''}</div><div class="entete-actions">${actions}</div></header>`;

// =================================================================================================
// TABLEAU DE BORD
// =================================================================================================
const PERIODES = {30: '30 derniers jours', 90: '90 derniers jours', 365: '12 derniers mois', tout: 'Depuis le début'};

// Découpe une période en intervalles (jours / semaines / mois) selon sa longueur
const decouper = (periode) => {
	const now = new Date();
	const intervalles = [];
	if (periode === '30') {
		for (let i = 29; i >= 0; i--) {
			const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - i);
			intervalles.push({debut: +d, fin: +d + JOUR, lib: d.toLocaleDateString('fr-FR', {day: 'numeric', month: 'short'})});
		}
	} else if (periode === '90') {
		const lundi = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7));
		for (let i = 12; i >= 0; i--) {
			const d = new Date(lundi.getFullYear(), lundi.getMonth(), lundi.getDate() - i * 7);
			intervalles.push({debut: +d, fin: +d + 7 * JOUR, lib: d.toLocaleDateString('fr-FR', {day: 'numeric', month: 'short'}), titre: `Semaine du ${d.toLocaleDateString('fr-FR', {day: 'numeric', month: 'long'})}`});
		}
	} else {
		let n = 12;
		if (periode === 'tout' && D.licences.length) {
			const premier = new Date(Math.min(...D.licences.map((l) => l.creeLe)));
			n = Math.max(6, (now.getFullYear() - premier.getFullYear()) * 12 + now.getMonth() - premier.getMonth() + 1);
		}
		for (let i = n - 1; i >= 0; i--) {
			const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
			const f = new Date(now.getFullYear(), now.getMonth() - i + 1, 1);
			intervalles.push({debut: +d, fin: +f, lib: d.toLocaleDateString('fr-FR', {month: 'short', ...(n > 12 || d.getMonth() === 0 ? {year: '2-digit'} : {})}), titre: d.toLocaleDateString('fr-FR', {month: 'long', year: 'numeric'})});
		}
	}
	return intervalles;
};
const indexIntervalle = (ints, t) => ints.findIndex((b) => t >= b.debut && t < b.fin);

// Tous les encaissements (achat initial + renouvellements), dans la devise principale
const encaissements = (licences) => {
	const e = [];
	for (const l of licences) {
		if (l.paiement?.statut !== 'offert' && l.prix > 0) e.push({t: l.creeLe, montant: l.prix, devise: l.devise, l, type: 'achat'});
		for (const r of l.renouvellements || []) if (r.prix > 0) e.push({t: r.t, montant: r.prix, devise: r.devise || l.devise, l, type: 'renouvellement'});
	}
	return e;
};

const carteGraphique = (id, titre, sous = '', {large = false, hauteur = 240} = {}) =>
	html`<section class="carte graphique ${large ? 'large' : ''}">
		<header><div><h2>${titre}</h2>${sous ? html`<p class="muted">${sous}</p>` : ''}</div>
		<button type="button" class="lien petit" data-vue-tableau="${id}" aria-pressed="false">Tableau</button></header>
		<div class="g-boite" id="${id}" data-hauteur="${hauteur}"></div>
	</section>`;

PAGES.tableau = (v) => {
	const periode = pref.lire('periode', '365');
	const filtreOffre = pref.lire('offreTdb', '');
	const devise = D.config.devise;
	const licences = D.licences.filter((l) => !filtreOffre || l.offre === filtreOffre);
	const ints = decouper(periode);
	const debut = ints[0].debut;
	const fin = ints[ints.length - 1].fin;
	const duree = fin - debut;
	const enc = encaissements(licences);
	const encDev = enc.filter((e) => e.devise === devise);
	const autresDevises = [...new Set(enc.filter((e) => e.devise !== devise).map((e) => e.devise))];
	const somme = (a, b) => encDev.filter((e) => e.t >= a && e.t < b).reduce((s, e) => s + e.montant, 0);
	const compte = (a, b) => licences.filter((l) => l.creeLe >= a && l.creeLe < b).length;
	const revenus = somme(debut, fin);
	const revenusPrec = somme(debut - duree, debut);
	const nouvelles = compte(debut, fin);
	const nouvellesPrec = compte(debut - duree, debut);
	const actives = licences.filter((l) => ['active', 'expire-bientot'].includes(l.statutEffectif));
	const bientot = licences.filter((l) => l.statutEffectif === 'expire-bientot');
	const postesActifs = licences.flatMap((l) => l.activations || []).filter((a) => Date.now() - a.derniere < 30 * JOUR);
	const payees = licences.filter((l) => l.creeLe >= debut && l.creeLe < fin && l.prix > 0 && l.paiement?.statut !== 'offert' && l.devise === devise);
	const attente = licences.filter((l) => l.paiement?.statut === 'en-attente');
	const parIntervalle = (f) => ints.map((b) => f(b.debut, b.fin));

	const delta = (a, b, argentF) => {
		if (periode === 'tout') return '';
		if (!b) return a ? html`<span class="delta neutre">nouveau</span>` : '';
		const pc = Math.round(((a - b) / b) * 100);
		return html`<span class="delta ${pc > 0 ? 'hausse' : pc < 0 ? 'baisse' : 'neutre'}">${pc > 0 ? '▲' : pc < 0 ? '▼' : '■'} ${Math.abs(pc)} %</span><span class="muted"> vs période préc.${argentF ? ` (${argentF(b)})` : ''}</span>`;
	};

	v.innerHTML = String(html`
		${entete('Tableau de bord', `Suivi des ventes, des licences et des installations de ${D.config.produit}`, html`<a class="bouton" href="#/nouvelle">＋ Nouvelle licence</a>`)}
		<div class="filtres">
			<div class="segments" role="group" aria-label="Période">${Object.entries(PERIODES).map(([k, lib]) => html`<button type="button" data-periode="${k}" class="${k === periode ? 'actif' : ''}">${k === periode ? '✓ ' : ''}${lib}</button>`)}</div>
			<select id="tdb-offre" aria-label="Offre"><option value="">Toutes les offres</option>${D.offres.map((o) => html`<option value="${o.code}" ${o.code === filtreOffre ? 'selected' : ''}>${o.nom}</option>`)}</select>
		</div>
		${D.licences.length ? '' : html`<div class="carte bienvenue"><h2>Votre tableau de bord est prêt</h2><p>Créez votre première licence : les graphiques se rempliront au fil des ventes et des activations.</p><a class="bouton" href="#/nouvelle">Créer une licence</a> <a class="bouton sec" href="#/parametres">Configurer le kit (clé publique)</a></div>`}
		<div class="tuiles">
			<div class="tuile heros"><span class="tuile-lib">Chiffre d'affaires · ${PERIODES[periode].toLowerCase()}</span><b class="tuile-val">${argent(revenus)}</b><div class="tuile-delta">${delta(revenus, revenusPrec, argent)}</div><div class="tuile-spark" id="sp-rev"></div>${autresDevises.length ? html`<small class="muted">Hors ventes en ${autresDevises.join(', ')}</small>` : ''}</div>
			<div class="tuile"><span class="tuile-lib">Nouvelles licences</span><b class="tuile-val">${nombre(nouvelles)}</b><div class="tuile-delta">${delta(nouvelles, nouvellesPrec)}</div><div class="tuile-spark" id="sp-new"></div></div>
			<div class="tuile"><span class="tuile-lib">Licences en cours de validité</span><b class="tuile-val">${nombre(actives.length)}</b><div class="tuile-delta muted">sur ${nombre(licences.length)} émises</div></div>
			<div class="tuile"><span class="tuile-lib">Postes actifs (30 j)</span><b class="tuile-val">${nombre(postesActifs.length)}</b><div class="tuile-delta muted">${nombre(licences.reduce((a, l) => a + (l.activations?.length || 0), 0))} activations au total</div></div>
			<a class="tuile lien-tuile" href="#/licences?statut=expire-bientot"><span class="tuile-lib">Expirent sous ${D.config.alerteJours} jours</span><b class="tuile-val">${nombre(bientot.length)}</b><div class="tuile-delta muted">${bientot.length ? 'À relancer pour renouvellement →' : 'Rien à relancer'}</div></a>
			<div class="tuile"><span class="tuile-lib">Panier moyen</span><b class="tuile-val">${argent(payees.length ? payees.reduce((a, l) => a + l.prix, 0) / payees.length : 0)}</b><div class="tuile-delta muted">${attente.length ? html`<a href="#/licences?paiement=en-attente">${attente.length} paiement(s) en attente →</a>` : 'Aucun paiement en attente'}</div></div>
		</div>
		<div class="grille-graphiques">
			${carteGraphique('g-ventes', 'Nouvelles licences', 'Histogramme par offre', {large: true, hauteur: 260})}
			${carteGraphique('g-revenus', `Chiffre d'affaires (${devise})`, 'Ventes et renouvellements', {large: true, hauteur: 220})}
			${carteGraphique('g-offres', 'Répartition par offre', 'Licences en cours de validité')}
			${carteGraphique('g-statuts', 'Statut des licences', 'Toutes les licences émises')}
			${carteGraphique('g-echeances', 'Échéances à venir', 'Licences arrivant à expiration, 12 prochains mois', {hauteur: 200})}
			${carteGraphique('g-clients', 'Meilleurs clients', `Total encaissé (${devise})`)}
			${carteGraphique('g-os', 'Systèmes des postes', 'Postes vus ces 30 derniers jours')}
			${carteGraphique('g-versions', 'Versions du kit installées', 'Postes vus ces 30 derniers jours')}
			${carteGraphique('g-pays', 'Clients par pays', 'Licences émises')}
			${carteGraphique('g-fonctions', 'Fonctions les plus vendues', 'Licences en cours de validité')}
			${carteGraphique('g-activite', 'Activité', 'Créations, renouvellements et activations par jour (26 semaines)', {large: true})}
		</div>
		<section class="carte"><header><h2>Dernières activités</h2><a href="#/journal" class="lien petit">Tout le journal →</a></header><ul class="journal" id="tdb-journal"></ul></section>
	`);

	$$('[data-periode]', v).forEach((b) => b.addEventListener('click', () => (pref.ecrire('periode', b.dataset.periode), routeur())));
	$('#tdb-offre', v).addEventListener('change', (e) => (pref.ecrire('offreTdb', e.target.value), routeur()));

	const serieRev = parIntervalle((a, b) => somme(a, b));
	const serieNew = parIntervalle((a, b) => compte(a, b));
	sparkline($('#sp-rev'), serieRev);
	sparkline($('#sp-new'), serieNew);

	const vues = {};
	const dessiner = {
		'g-ventes': (t) =>
			colonnes($('#g-ventes'), {
				vueTableau: t,
				categories: ints.map((b) => b.lib),
				titres: ints.map((b) => b.titre || b.lib),
				series: D.offres
					.filter((o) => !filtreOffre || o.code === filtreOffre)
					.map((o) => ({nom: o.nom, couleur: couleurOffre(o.code), valeurs: parIntervalle((a, b) => licences.filter((l) => l.offre === o.code && l.creeLe >= a && l.creeLe < b).length)}))
					.concat(licences.some((l) => !D.offres.some((o) => o.code === l.offre)) ? [{nom: 'Autres', couleur: 'var(--muted)', valeurs: parIntervalle((a, b) => licences.filter((l) => !D.offres.some((o) => o.code === l.offre) && l.creeLe >= a && l.creeLe < b).length)}] : [])
					.filter((s) => s.valeurs.some((x) => x)),
			}),
		'g-revenus': (t) =>
			courbe($('#g-revenus'), {
				vueTableau: t,
				categories: ints.map((b) => b.lib),
				titres: ints.map((b) => b.titre || b.lib),
				format: (x) => argent(x),
				series: [{nom: 'Chiffre d’affaires', couleur: 'var(--s1)', valeurs: serieRev}],
			}),
		'g-offres': (t) =>
			anneau($('#g-offres'), {
				vueTableau: t,
				parts: D.offres.map((o) => ({nom: o.nom, couleur: couleurOffre(o.code), valeur: actives.filter((l) => l.offre === o.code).length})).filter((p) => p.valeur || !filtreOffre),
				centre: {valeur: nombre(actives.length), libelle: 'en cours'},
			}),
		'g-statuts': (t) =>
			barres($('#g-statuts'), {
				vueTableau: t,
				unite: 'Licences',
				items: Object.entries(STATUTS).map(([k, s]) => ({nom: s.lib, icone: s.icone, valeur: licences.filter((l) => l.statutEffectif === k).length, couleur: `var(--st-${s.cls})`, onClick: () => (location.hash = `#/licences?statut=${k}`)})),
			}),
		'g-echeances': (t) => {
			const now = new Date();
			const mois = [...Array(12)].map((_, i) => {
				const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
				return {d, cle: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`};
			});
			colonnes($('#g-echeances'), {
				vueTableau: t,
				categories: mois.map((m) => m.d.toLocaleDateString('fr-FR', {month: 'short'})),
				titres: mois.map((m) => m.d.toLocaleDateString('fr-FR', {month: 'long', year: 'numeric'})),
				series: [{nom: 'Licences à renouveler', couleur: 'var(--s2)', valeurs: mois.map((m) => licences.filter((l) => l.expire?.startsWith(m.cle) && !['revoquee', 'expiree'].includes(l.statutEffectif)).length)}],
			});
		},
		'g-clients': (t) => {
			const parClient = new Map();
			for (const e of encDev) {
				const k = e.l.client.email || e.l.client.nom;
				parClient.set(k, {nom: e.l.client.organisation || e.l.client.nom, valeur: (parClient.get(k)?.valeur || 0) + e.montant, email: e.l.client.email});
			}
			barres($('#g-clients'), {vueTableau: t, unite: devise, format: (x) => argent(x), items: [...parClient.values()].sort((a, b) => b.valeur - a.valeur).slice(0, 8).map((c) => ({...c, couleur: 'var(--s1)', onClick: () => (location.hash = `#/licences?q=${encodeURIComponent(c.email || c.nom)}`)}))});
		},
		'g-os': (t) => {
			const m = new Map();
			for (const a of postesActifs) {
				const os = /win/i.test(a.os) ? 'Windows' : /darwin|mac/i.test(a.os) ? 'macOS' : /linux/i.test(a.os) ? 'Linux' : 'Autre';
				m.set(os, (m.get(os) || 0) + 1);
			}
			barres($('#g-os'), {vueTableau: t, unite: 'Postes', messageVide: 'Aucun poste n’a encore contacté le serveur d’activation', items: [...m].sort((a, b) => b[1] - a[1]).map(([nom, valeur]) => ({nom, valeur, couleur: 'var(--s3)'}))});
		},
		'g-versions': (t) => {
			const m = new Map();
			for (const a of postesActifs) m.set(a.version || 'inconnue', (m.get(a.version || 'inconnue') || 0) + 1);
			barres($('#g-versions'), {vueTableau: t, unite: 'Postes', messageVide: 'Aucun poste n’a encore contacté le serveur d’activation', items: [...m].sort((a, b) => (b[0] > a[0] ? 1 : -1)).map(([nom, valeur]) => ({nom: `v${nom}`, valeur, couleur: 'var(--s7)'}))});
		},
		'g-pays': (t) => {
			const m = new Map();
			for (const l of licences) {
				const p = l.client.pays || 'Non renseigné';
				m.set(p, (m.get(p) || 0) + 1);
			}
			const tri = [...m].sort((a, b) => b[1] - a[1]);
			const items = tri.slice(0, 7).map(([nom, valeur]) => ({nom, valeur, couleur: 'var(--s4)'}));
			if (tri.length > 7) items.push({nom: 'Autres pays', valeur: tri.slice(7).reduce((a, x) => a + x[1], 0), couleur: 'var(--muted)'});
			barres($('#g-pays'), {vueTableau: t, unite: 'Licences', items});
		},
		'g-fonctions': (t) =>
			barres($('#g-fonctions'), {
				vueTableau: t,
				unite: 'Licences',
				items: Object.entries(D.fonctions)
					.map(([k, nom]) => ({nom, valeur: actives.filter((l) => l.fonctions.includes(k)).length, couleur: 'var(--s5)'}))
					.sort((a, b) => b.valeur - a.valeur),
			}),
		'g-activite': (t) => {
			const jours = new Map();
			const plus = (ts) => {
				const k = new Date(ts).toISOString().slice(0, 10);
				jours.set(k, (jours.get(k) || 0) + 1);
			};
			for (const j of D.journal) if (['creation', 'activation', 'prolonger'].includes(j.type) && (!filtreOffre || licences.some((l) => l.id === j.licence))) plus(j.t);
			chaleur($('#g-activite'), {vueTableau: t, jours, semaines: Math.max(12, Math.min(52, Math.floor(($('#g-activite').clientWidth - 40) / 16) - 1)), unite: 'événements'});
		},
	};
	for (const [id, f] of Object.entries(dessiner)) f(false);
	$$('[data-vue-tableau]', v).forEach((b) =>
		b.addEventListener('click', () => {
			const id = b.dataset.vueTableau;
			vues[id] = !vues[id];
			b.setAttribute('aria-pressed', vues[id]);
			b.textContent = vues[id] ? 'Graphique' : 'Tableau';
			dessiner[id](vues[id]);
		}),
	);
	listeJournal($('#tdb-journal'), D.journal.slice(-8).reverse());
};

const TYPES_JOURNAL = {creation: '＋', activation: '⇡', desactivation: '⇣', revoquer: '⛔', suspendre: '⏸', reactiver: '↺', prolonger: '⟳', modifier: '✎', suppression: '✕', securite: '🔒', parametres: '⚙', offres: '◈', import: '⇩', systeme: '•'};
const listeJournal = (ul, entrees) => {
	ul.innerHTML = entrees.length
		? entrees.map((j) => String(html`<li><span class="j-ic">${TYPES_JOURNAL[j.type] || '•'}</span><div><div>${j.message}${j.licence ? html` · <a href="#/licence/${j.licence}">${j.licence}</a>` : ''}</div><small class="muted">${dateHeure(j.t)}</small></div></li>`)).join('')
		: '<li class="muted">Aucune activité pour l’instant.</li>';
};

// =================================================================================================
// LISTE DES LICENCES
// =================================================================================================
PAGES.licences = (v, _, q) => {
	const etat = {
		q: q.get('q') || '',
		statut: q.get('statut') || '',
		offre: q.get('offre') || '',
		paiement: q.get('paiement') || '',
		tri: pref.lire('tri', 'creeLe'),
		sens: pref.lire('sens', 'desc'),
		page: 0,
		sel: new Set(),
	};
	v.innerHTML = String(html`
		${entete('Licences', `${D.licences.length} licence(s) émise(s)`, html`<a class="bouton sec" href="/api/export/licences.csv" download>Exporter (CSV)</a><a class="bouton" href="#/nouvelle">＋ Nouvelle licence</a>`)}
		<div class="filtres">
			<input type="search" id="f-q" placeholder="Rechercher : client, e-mail, n° de licence, étiquette…" value="${etat.q}">
			<select id="f-statut"><option value="">Tous les statuts</option>${Object.entries(STATUTS).map(([k, s]) => html`<option value="${k}" ${k === etat.statut ? 'selected' : ''}>${s.lib}</option>`)}</select>
			<select id="f-offre"><option value="">Toutes les offres</option>${D.offres.map((o) => html`<option value="${o.code}" ${o.code === etat.offre ? 'selected' : ''}>${o.nom}</option>`)}</select>
			<select id="f-paiement"><option value="">Tous les paiements</option>${Object.entries(PAIEMENTS).map(([k, p]) => html`<option value="${k}" ${k === etat.paiement ? 'selected' : ''}>${p}</option>`)}</select>
		</div>
		<div class="barre-selection" id="sel" hidden><span id="sel-n"></span>
			<button type="button" class="sec petit" data-lot="prolonger">Prolonger d'un an</button>
			<button type="button" class="sec petit" data-lot="csv">Exporter la sélection</button>
			<button type="button" class="sec petit" data-lot="suspendre">Suspendre</button>
			<button type="button" class="danger petit" data-lot="revoquer">Révoquer</button>
		</div>
		<div class="carte table-carte"><table class="table" id="t-licences"></table></div>
		<div class="pagination" id="pagination"></div>
	`);
	const COLS = [
		['id', 'N° de licence'],
		['client', 'Client'],
		['offre', 'Offre'],
		['statut', 'Statut'],
		['expire', 'Expiration'],
		['postes', 'Postes'],
		['prix', 'Prix'],
		['creeLe', 'Créée le'],
	];
	const valeurTri = (l, k) => ({client: l.client.nom.toLowerCase(), statut: l.statutEffectif, postes: l.activations.length, expire: l.expire || '9999', offre: l.offreNom}[k] ?? l[k]);
	const filtrer = () => {
		const mots = etat.q.toLowerCase().split(/\s+/).filter(Boolean);
		return D.licences
			.filter((l) => (!etat.statut || l.statutEffectif === etat.statut) && (!etat.offre || l.offre === etat.offre) && (!etat.paiement || l.paiement?.statut === etat.paiement))
			.filter((l) => {
				if (!mots.length) return true;
				const txt = [l.id, l.client.nom, l.client.email, l.client.organisation, l.client.pays, l.offreNom, l.notes, ...(l.tags || []), l.paiement?.reference, l.machine].join(' ').toLowerCase();
				return mots.every((m) => txt.includes(m));
			})
			.sort((a, b) => {
				const x = valeurTri(a, etat.tri);
				const y = valeurTri(b, etat.tri);
				return (x > y ? 1 : x < y ? -1 : 0) * (etat.sens === 'asc' ? 1 : -1);
			});
	};
	const PAR_PAGE = 25;
	const rendre = () => {
		const liste = filtrer();
		const pages = Math.max(1, Math.ceil(liste.length / PAR_PAGE));
		etat.page = Math.min(etat.page, pages - 1);
		const vue = liste.slice(etat.page * PAR_PAGE, (etat.page + 1) * PAR_PAGE);
		$('#t-licences').innerHTML = String(html`<thead><tr><th class="case"><input type="checkbox" id="sel-tout" aria-label="Tout sélectionner"></th>${COLS.map(([k, lib]) => html`<th><button type="button" class="tri ${etat.tri === k ? etat.sens : ''}" data-tri="${k}">${lib}</button></th>`)}</tr></thead>
			<tbody>${
				vue.length
					? vue.map(
							(l) => html`<tr data-id="${l.id}">
						<td class="case"><input type="checkbox" data-sel="${l.id}" ${etat.sel.has(l.id) ? 'checked' : ''} aria-label="Sélectionner"></td>
						<td><a href="#/licence/${l.id}" class="mono">${l.id}</a></td>
						<td><b>${l.client.nom}</b>${l.client.organisation ? html` · ${l.client.organisation}` : ''}<br><small class="muted">${l.client.email}</small></td>
						<td><span class="pastille" style="--c:${couleurOffre(l.offre)}"></span>${l.offreNom}</td>
						<td>${badge(l.statutEffectif)}</td>
						<td>${l.expire ? date(l.expire) : html`<span class="muted">Perpétuelle</span>`}</td>
						<td class="num">${l.activations.length}${l.postes ? html`<span class="muted"> / ${l.postes}</span>` : ''}</td>
						<td class="num">${l.paiement?.statut === 'offert' ? html`<span class="muted">Offerte</span>` : argent(l.prix, l.devise)}${l.paiement?.statut === 'en-attente' ? html`<br><small class="attente">en attente</small>` : ''}</td>
						<td class="num">${dateT(l.creeLe)}</td>
					</tr>`,
						)
					: html`<tr><td colspan="9" class="vide">${D.licences.length ? 'Aucune licence ne correspond à ces critères.' : html`Aucune licence pour l'instant. <a href="#/nouvelle">Créer la première</a>`}</td></tr>`
			}</tbody>`);
		$('#pagination').innerHTML = pages > 1 ? String(html`<button type="button" class="sec petit" data-page="-1" ${etat.page ? '' : 'disabled'}>◀</button><span>Page ${etat.page + 1} / ${pages} · ${liste.length} résultat(s)</span><button type="button" class="sec petit" data-page="1" ${etat.page < pages - 1 ? '' : 'disabled'}>▶</button>`) : `<span class="muted">${liste.length} résultat(s)</span>`;
		$$('[data-tri]').forEach((b) =>
			b.addEventListener('click', () => {
				if (etat.tri === b.dataset.tri) etat.sens = etat.sens === 'asc' ? 'desc' : 'asc';
				else (etat.tri = b.dataset.tri), (etat.sens = 'asc');
				pref.ecrire('tri', etat.tri);
				pref.ecrire('sens', etat.sens);
				rendre();
			}),
		);
		$$('[data-page]').forEach((b) => b.addEventListener('click', () => ((etat.page += Number(b.dataset.page)), rendre())));
		$$('#t-licences tbody tr[data-id]').forEach((tr) => tr.addEventListener('click', (e) => !e.target.closest('a,input') && (location.hash = `#/licence/${tr.dataset.id}`)));
		$$('[data-sel]').forEach((c) => c.addEventListener('change', () => (c.checked ? etat.sel.add(c.dataset.sel) : etat.sel.delete(c.dataset.sel), majSel())));
		$('#sel-tout').addEventListener('change', (e) => {
			for (const l of vue) e.target.checked ? etat.sel.add(l.id) : etat.sel.delete(l.id);
			rendre();
		});
		majSel();
	};
	const majSel = () => {
		$('#sel').hidden = !etat.sel.size;
		$('#sel-n').textContent = `${etat.sel.size} sélectionnée(s)`;
	};
	let t;
	$('#f-q').addEventListener('input', (e) => {
		clearTimeout(t);
		t = setTimeout(() => ((etat.q = e.target.value), (etat.page = 0), rendre()), 150);
	});
	for (const k of ['statut', 'offre', 'paiement']) $(`#f-${k}`).addEventListener('change', (e) => ((etat[k] = e.target.value), (etat.page = 0), rendre()));
	$$('[data-lot]').forEach((b) =>
		b.addEventListener('click', async () => {
			const ids = [...etat.sel];
			const action = b.dataset.lot;
			if (action === 'csv') {
				const lignes = D.licences.filter((l) => etat.sel.has(l.id)).map((l) => [l.id, l.client.nom, l.client.email, l.offreNom, STATUTS[l.statutEffectif].lib, l.expire, l.cle]);
				const csv = '﻿' + [['id', 'client', 'email', 'offre', 'statut', 'expire', 'cle'], ...lignes].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
				return telecharger(URL.createObjectURL(new Blob([csv], {type: 'text/csv'})));
			}
			const libs = {prolonger: 'Prolonger d’un an', suspendre: 'Suspendre', revoquer: 'Révoquer'};
			if (!(await confirmer(`${libs[action]} ${ids.length} licence(s) ?`, action === 'revoquer' ? 'Les postes connectés au serveur d’activation seront bloqués à leur prochaine vérification. Les licences hors ligne restent valides jusqu’à leur expiration.' : '', {danger: action === 'revoquer', bouton: libs[action]}))) return;
			for (const id of ids) majLicence((await api(`licences/${id}/${action}`, {methode: 'POST', corps: action === 'prolonger' ? {jours: 365, prix: 0} : {}})).licence);
			etat.sel.clear();
			toast(`${ids.length} licence(s) mise(s) à jour`, 'ok');
			rendre();
		}),
	);
	rendre();
};

// =================================================================================================
// FICHE D'UNE LICENCE
// =================================================================================================
PAGES.licence = (v, id) => {
	const l = D.licences.find((x) => x.id === id);
	if (!l) {
		v.innerHTML = String(html`${entete('Licence introuvable')}<p><a href="#/licences">← Retour aux licences</a></p>`);
		return;
	}
	const hist = D.journal.filter((j) => j.licence === l.id).reverse();
	const st = l.statutEffectif;
	v.innerHTML = String(html`
		<p><a href="#/licences" class="lien">← Licences</a></p>
		<header class="entete"><div><h1 class="mono">${l.id}</h1><p>${badge(st)} <span class="muted">· ${l.offreNom} · ${l.client.nom}</span></p></div>
		<div class="entete-actions">
			<button type="button" data-a="copier">Copier la clé</button>
			<button type="button" class="sec" data-a="lic">Fichier .lic</button>
			<button type="button" class="sec" data-a="email">E-mail</button>
			<button type="button" class="sec" data-a="certificat">Certificat</button>
		</div></header>
		<div class="actions-ligne">
			<button type="button" class="sec petit" data-a="prolonger">⟳ Prolonger / renouveler</button>
			<button type="button" class="sec petit" data-a="modifier">✎ Modifier</button>
			<button type="button" class="sec petit" data-a="dupliquer">⧉ Dupliquer</button>
			${l.statut === 'active' ? html`<button type="button" class="sec petit" data-a="suspendre">⏸ Suspendre</button>` : html`<button type="button" class="sec petit" data-a="reactiver">↺ Réactiver</button>`}
			${l.statut !== 'revoquee' ? html`<button type="button" class="danger petit" data-a="revoquer">⛔ Révoquer</button>` : ''}
			<button type="button" class="danger lien petit" data-a="supprimer">Supprimer</button>
		</div>
		<div class="grille-fiche">
			<section class="carte"><h2>Client</h2><dl class="def">
				<dt>Nom</dt><dd>${l.client.nom}</dd>
				<dt>Organisation</dt><dd>${l.client.organisation || '—'}</dd>
				<dt>E-mail</dt><dd>${l.client.email ? html`<a href="mailto:${l.client.email}">${l.client.email}</a>` : '—'}</dd>
				<dt>Téléphone</dt><dd>${l.client.telephone || '—'}</dd>
				<dt>Pays</dt><dd>${l.client.pays || '—'}</dd>
			</dl><p><a class="lien petit" href="#/licences?q=${encodeURIComponent(l.client.email || l.client.nom)}">Toutes ses licences →</a></p></section>
			<section class="carte"><h2>Droits</h2><dl class="def">
				<dt>Offre</dt><dd><span class="pastille" style="--c:${couleurOffre(l.offre)}"></span>${l.offreNom}</dd>
				<dt>Émise le</dt><dd>${date(l.emise)}</dd>
				<dt>Expiration</dt><dd>${l.expire ? html`${date(l.expire)} <span class="muted">(${joursAvant(l.expire)})</span>` : 'Perpétuelle'}</dd>
				<dt>Postes</dt><dd>${l.machine ? html`Lié au poste <code>${l.machine}</code>` : l.postes ? `${l.postes} maximum` : 'Illimité'}</dd>
				<dt>Activation</dt><dd>${l.enLigne ? html`En ligne (${D.config.serveurPublic || 'serveur non configuré'})` : 'Hors ligne (vérification par signature)'}</dd>
				<dt>Fonctions</dt><dd><ul class="puces">${Object.entries(D.fonctions).map(([k, n]) => html`<li class="${l.fonctions.includes(k) ? 'on' : 'off'}">${l.fonctions.includes(k) ? '✓' : '—'} ${n}</li>`)}</ul></dd>
			</dl></section>
			<section class="carte"><h2>Paiement</h2><dl class="def">
				<dt>Montant</dt><dd>${argent(l.prix, l.devise)}</dd>
				<dt>Statut</dt><dd>${PAIEMENTS[l.paiement?.statut] || '—'}</dd>
				<dt>Moyen</dt><dd>${l.paiement?.mode || '—'}</dd>
				<dt>Référence</dt><dd>${l.paiement?.reference || '—'}</dd>
				<dt>Étiquettes</dt><dd>${l.tags?.length ? l.tags.map((t) => html`<span class="etiquette">${t}</span>`) : '—'}</dd>
			</dl>
			${l.renouvellements?.length ? html`<h3>Renouvellements</h3><ul class="journal">${l.renouvellements.map((r) => html`<li><span class="j-ic">⟳</span><div>${r.a ? `Jusqu'au ${date(r.a)}` : 'Perpétuelle'} · ${argent(r.prix, r.devise || l.devise)}<br><small class="muted">${dateHeure(r.t)}</small></div></li>`)}</ul>` : ''}
			</section>
		</div>
		<section class="carte"><header><h2>Postes activés</h2><span class="muted">${l.activations.length}${l.postes ? ` / ${l.postes}` : ''}</span></header>
			${
				l.activations.length
					? html`<div class="table-carte"><table class="table"><thead><tr><th>Poste</th><th>Code machine</th><th>Système</th><th>Version</th><th>Première activation</th><th>Dernier contact</th><th></th></tr></thead><tbody>${l.activations.map(
							(a) => html`<tr><td><b>${a.nom || '—'}</b></td><td class="mono">${a.machine}</td><td>${a.os}</td><td>${a.version}</td><td>${dateHeure(a.premiere)}</td><td>${dateHeure(a.derniere)} ${Date.now() - a.derniere > 30 * JOUR ? html`<span class="badge neutre">inactif</span>` : ''}</td><td><button type="button" class="sec petit" data-liberer="${a.machine}">Libérer</button></td></tr>`,
						)}</tbody></table></div>`
					: html`<p class="muted">${l.enLigne ? 'Aucun poste ne s’est encore activé.' : 'Licence hors ligne : les postes ne se déclarent pas. Activez l’activation en ligne (Modifier) pour suivre les installations et pouvoir révoquer à distance.'}</p>`
			}
		</section>
		<section class="carte"><header><h2>Clé de licence</h2><button type="button" class="lien petit" data-a="copier">Copier</button></header><textarea class="cle" readonly rows="4">${l.cle}</textarea></section>
		${l.notes ? html`<section class="carte"><h2>Notes</h2><p class="notes">${l.notes}</p></section>` : ''}
		<section class="carte"><h2>Historique</h2><ul class="journal" id="hist"></ul></section>
	`);
	listeJournal($('#hist'), hist);
	const agir = async (action, corps = {}) => {
		const r = await api(`licences/${l.id}/${action}`, {methode: 'POST', corps});
		majLicence(r.licence);
		await charger();
		toast('Licence mise à jour ✓', 'ok');
		if (action === 'dupliquer') location.hash = `#/licence/${r.licence.id}`;
		else routeur();
	};
	$$('[data-a]', v).forEach((b) =>
		b.addEventListener('click', async () => {
			try {
				const a = b.dataset.a;
				if (a === 'copier') return copier(l.cle, 'Clé copiée');
				if (a === 'lic') return telecharger(`/api/licences/${l.id}/lic`);
				if (a === 'email') return dialogueEmail(l);
				if (a === 'certificat') return certificat(l);
				if (a === 'prolonger') return dialogueProlonger(l, agir);
				if (a === 'modifier') return dialogueModifier(l, agir);
				if (a === 'dupliquer') return (await confirmer('Dupliquer cette licence ?', 'Une nouvelle licence sera créée pour le même client, avec la même offre et une nouvelle date de début.', {bouton: 'Dupliquer'})) && agir('dupliquer');
				if (a === 'suspendre') return (await confirmer('Suspendre la licence ?', 'Les postes en activation en ligne passeront en mode limité à leur prochaine vérification. Vous pourrez la réactiver à tout moment.', {bouton: 'Suspendre'})) && agir('suspendre');
				if (a === 'reactiver') return agir('reactiver');
				if (a === 'revoquer') {
					const motif = await confirmer('Révoquer définitivement ?', 'Utilisez cette action en cas de fraude, de remboursement ou de clé diffusée. Les postes en activation en ligne seront bloqués. Une licence hors ligne reste techniquement valide jusqu’à son expiration.', {bouton: 'Révoquer', danger: true, saisie: 'Motif (facultatif)'});
					if (motif !== false) agir('revoquer', {motif: motif === true ? '' : motif});
					return;
				}
				if (a === 'supprimer') {
					if (!(await confirmer('Supprimer la licence ?', 'Elle disparaîtra des statistiques. Pour bloquer une clé, préférez « Révoquer ».', {bouton: 'Supprimer', danger: true}))) return;
					await api(`licences/${l.id}`, {methode: 'DELETE'});
					await charger();
					toast('Licence supprimée');
					location.hash = '#/licences';
				}
			} catch (err) {
				toast(err.message, 'ko');
			}
		}),
	);
	$$('[data-liberer]', v).forEach((b) =>
		b.addEventListener('click', async () => {
			if (!(await confirmer('Libérer ce poste ?', 'La place est rendue au client, qui pourra activer la licence sur un autre PC.', {bouton: 'Libérer'}))) return;
			majLicence((await api(`licences/${l.id}/activations/${b.dataset.liberer}`, {methode: 'DELETE'})).licence);
			routeur();
		}),
	);
};
const joursAvant = (iso) => {
	const j = Math.ceil((Date.parse(`${iso}T23:59:59Z`) - Date.now()) / JOUR);
	return j < 0 ? `expirée depuis ${-j} j` : j === 0 ? 'expire aujourd’hui' : `dans ${j} j`;
};

const dialogueEmail = async (l) => {
	const m = await api(`licences/${l.id}/email`);
	const f = ouvrirDialogue(
		html`<h2>E-mail au client</h2>
		<label>Destinataire<input readonly value="${l.client.email}"></label>
		<label>Objet<input id="em-sujet" value="${m.sujet}"></label>
		<label>Message<textarea id="em-corps" rows="12">${m.corps}</textarea></label>
		<p class="muted petit">Modèle modifiable dans Paramètres. Joignez le fichier .lic si vous le souhaitez.</p>
		<div class="actions"><button type="button" class="sec" data-fermer>Fermer</button><button type="button" class="sec" id="em-copier">Copier le message</button><button type="button" id="em-ouvrir">Ouvrir dans ma messagerie</button></div>`,
		{large: true},
	);
	$('#em-copier', f).addEventListener('click', () => copier($('#em-corps', f).value, 'Message copié'));
	$('#em-ouvrir', f).addEventListener('click', () => {
		location.href = `mailto:${encodeURIComponent(l.client.email)}?subject=${encodeURIComponent($('#em-sujet', f).value)}&body=${encodeURIComponent($('#em-corps', f).value)}`;
	});
};

const dialogueProlonger = (l, agir) => {
	const offre = D.offres.find((o) => o.code === l.offre);
	const f = ouvrirDialogue(html`<h2>Prolonger / renouveler</h2>
		<p class="muted">Expiration actuelle : ${l.expire ? date(l.expire) : 'perpétuelle'}. La durée s'ajoute à la date d'expiration (ou à aujourd'hui si elle est dépassée). Une nouvelle clé est émise : envoyez-la au client.</p>
		<div class="segments choix" role="radiogroup">${[
			[30, '+1 mois'],
			[90, '+3 mois'],
			[182, '+6 mois'],
			[365, '+1 an'],
			[730, '+2 ans'],
			[0, 'Perpétuelle'],
		].map(([j, lib]) => html`<label><input type="radio" name="jours" value="${j}" ${j === (offre?.duree || 365) ? 'checked' : ''}> ${lib}</label>`)}</div>
		<label>Montant encaissé pour ce renouvellement (${l.devise})<input type="number" name="prix" min="0" step="0.01" value="${offre?.prix ?? l.prix}"></label>
		<div class="actions"><button type="button" class="sec" data-fermer>Annuler</button><button type="button" id="pr-ok">Prolonger</button></div>`);
	$('#pr-ok', f).addEventListener('click', () => {
		const d = Object.fromEntries(new FormData(f));
		dlg.close();
		agir('prolonger', {jours: Number(d.jours), prix: Number(d.prix)}).catch((e) => toast(e.message, 'ko'));
	});
};

const champsLicence = (l = {}, {nouvelle} = {}) => {
	const c = l.client || {};
	const clients = clientsUniques();
	return html`
	<fieldset><legend>Client</legend><div class="champs c2">
		<label>Nom *<input name="client.nom" required value="${c.nom || ''}" list="dl-clients" autocomplete="off"></label>
		<label>E-mail<input type="email" name="client.email" value="${c.email || ''}"></label>
		<label>Organisation / église / société<input name="client.organisation" value="${c.organisation || ''}"></label>
		<label>Pays<input name="client.pays" value="${c.pays || ''}" list="dl-pays"></label>
		<label>Téléphone<input name="client.telephone" value="${c.telephone || ''}"></label>
	</div>
	<datalist id="dl-clients">${clients.map((x) => html`<option value="${x.nom}">${x.email}</option>`)}</datalist>
	<datalist id="dl-pays">${['France', 'Belgique', 'Suisse', 'Canada', 'RD Congo', 'Congo', "Côte d'Ivoire", 'Cameroun', 'Sénégal', 'Gabon', 'Bénin', 'Togo', 'Burkina Faso', 'Mali', 'Guinée', 'Madagascar', 'Maroc', 'Tunisie', 'Algérie', 'Haïti', 'Rwanda', 'Burundi', 'États-Unis'].map((p) => html`<option value="${p}">`)}</datalist>
	</fieldset>
	<fieldset><legend>Droits</legend><div class="champs c3">
		${nouvelle ? '' : html`<label>Offre<select name="offre">${D.offres.map((o) => html`<option value="${o.code}" ${o.code === l.offre ? 'selected' : ''}>${o.nom}</option>`)}</select></label>`}
		<label>Expiration (vide = perpétuelle)<input type="date" name="expire" value="${l.expire || ''}"></label>
		<label>Postes max (0 = illimité)<input type="number" min="0" name="postes" value="${l.postes ?? 1}"></label>
		<label>Lier à un poste (code machine, facultatif)<input name="machine" value="${l.machine || ''}" placeholder="XXXX-XXXX-XXXX-XXXX" pattern="[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}"></label>
	</div>
	<div class="fonctions">${Object.entries(D.fonctions).map(([k, n]) => html`<label class="case-lib"><input type="checkbox" name="fonctions" value="${k}" ${(l.fonctions || []).includes(k) ? 'checked' : ''}> ${n}</label>`)}</div>
	<label class="case-lib ${D.config.serveurPublic ? '' : 'desactive'}"><input type="checkbox" name="enLigne" ${l.enLigne ? 'checked' : ''} ${D.config.serveurPublic ? '' : 'disabled'}> Activation en ligne : suivi des postes, révocation à distance${D.config.serveurPublic ? '' : html` <small class="muted">(configurez d'abord l'adresse du serveur dans <a href="#/parametres">Paramètres</a>)</small>`}</label>
	</fieldset>
	<fieldset><legend>Paiement</legend><div class="champs c3">
		<label>Montant<input type="number" min="0" step="0.01" name="prix" value="${l.prix ?? 0}"></label>
		<label>Devise<input name="devise" value="${l.devise || D.config.devise}" maxlength="5"></label>
		<label>Statut<select name="paiement.statut">${Object.entries(PAIEMENTS).map(([k, p]) => html`<option value="${k}" ${k === (l.paiement?.statut || 'paye') ? 'selected' : ''}>${p}</option>`)}</select></label>
		<label>Moyen de paiement<input name="paiement.mode" value="${l.paiement?.mode || ''}" list="dl-modes" placeholder="Mobile Money, virement, PayPal…"></label>
		<label>Référence / n° de facture<input name="paiement.reference" value="${l.paiement?.reference || ''}"></label>
		<label>Étiquettes (virgules)<input name="tags" value="${(l.tags || []).join(', ')}" placeholder="salon2026, revendeur…"></label>
	</div>
	<datalist id="dl-modes">${['Mobile Money', 'M-Pesa', 'Orange Money', 'Airtel Money', 'Virement', 'Carte bancaire', 'PayPal', 'Espèces', 'Chèque', 'Stripe'].map((p) => html`<option value="${p}">`)}</datalist>
	<label>Notes internes<textarea name="notes" rows="2">${l.notes || ''}</textarea></label>
	</fieldset>`;
};
// Lit un formulaire « à points » (client.nom, paiement.mode…) en objet
const lireFormulaire = (f) => {
	const o = {fonctions: []};
	for (const [k, v] of new FormData(f)) {
		if (k === 'fonctions') o.fonctions.push(v);
		else if (k.includes('.')) {
			const [a, b] = k.split('.');
			(o[a] ||= {})[b] = v;
		} else o[k] = v;
	}
	o.enLigne = !!$('[name=enLigne]', f)?.checked;
	o.postes = Number(o.postes || 0);
	o.prix = Number(o.prix || 0);
	return o;
};
const clientsUniques = () => {
	const m = new Map();
	for (const l of D.licences) {
		const k = (l.client.email || l.client.nom).toLowerCase();
		if (!m.has(k) || m.get(k).creeLe < l.creeLe) m.set(k, {...l.client, creeLe: l.creeLe});
	}
	return [...m.values()];
};
const autoClient = (f) => {
	$('[name="client.nom"]', f).addEventListener('change', (e) => {
		const c = clientsUniques().find((x) => x.nom === e.target.value);
		if (!c) return;
		for (const k of ['email', 'organisation', 'pays', 'telephone']) {
			const i = $(`[name="client.${k}"]`, f);
			if (!i.value) i.value = c[k] || '';
		}
	});
};

const dialogueModifier = (l, agir) => {
	const f = ouvrirDialogue(html`<h2>Modifier la licence</h2><p class="muted">Toute modification des droits émet une <b>nouvelle clé</b> : envoyez-la au client. Avec l'activation en ligne, l'ancienne clé est refusée.</p>${champsLicence(l)}
		<div class="actions"><button type="button" class="sec" data-fermer>Annuler</button><button id="md-ok">Enregistrer</button></div>`, {large: true});
	autoClient(f);
	f.addEventListener('submit', (e) => {
		e.preventDefault();
		const corps = lireFormulaire(f);
		dlg.close();
		agir('modifier', corps).catch((er) => toast(er.message, 'ko'));
	});
};

// --- Certificat imprimable ---------------------------------------------------------------------
const certificat = (l) => {
	const v = D.config.vendeur;
	const page = String(html`<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Certificat de licence ${l.id}</title><style>
		body{font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#0b0b0b;margin:0;background:#f9f9f7}
		.page{max-width:760px;margin:24px auto;background:#fff;padding:48px;border:1px solid #e1e0d9;border-radius:12px}
		h1{font-size:26px;margin:0 0 4px}.muted{color:#52514e}table{width:100%;border-collapse:collapse;margin:24px 0}
		td{padding:8px 0;border-bottom:1px solid #e1e0d9;vertical-align:top}td:first-child{color:#52514e;width:38%}
		.cle{font:12px/1.4 ui-monospace,Consolas,monospace;word-break:break-all;background:#f0efec;padding:12px;border-radius:8px}
		.bas{margin-top:32px;font-size:13px}@media print{body{background:#fff}.page{border:0;margin:0}.np{display:none}}
		button{font:inherit;padding:8px 14px;border-radius:8px;border:1px solid #c3c2b7;background:#fff;cursor:pointer}</style></head><body>
		<div class="page"><p class="np"><button onclick="print()">Imprimer / enregistrer en PDF</button></p>
		<p class="muted">${v.nom || D.config.produit}</p><h1>Certificat de licence</h1><p class="muted">${D.config.produit}</p>
		<table><tr><td>N° de licence</td><td><b>${l.id}</b></td></tr><tr><td>Titulaire</td><td>${l.client.nom}${l.client.organisation ? ` — ${l.client.organisation}` : ''}</td></tr>
		<tr><td>Offre</td><td>${l.offreNom}</td></tr><tr><td>Date d'émission</td><td>${date(l.emise)}</td></tr><tr><td>Validité</td><td>${l.expire ? `jusqu'au ${date(l.expire)}` : 'sans limite de durée'}</td></tr>
		<tr><td>Postes</td><td>${l.machine ? `poste ${l.machine}` : l.postes ? `${l.postes} maximum` : 'illimité'}</td></tr>
		<tr><td>Fonctions incluses</td><td>${l.fonctions.map((f) => D.fonctions[f]).join(', ') || 'habillages de base'}</td></tr></table>
		<p><b>Clé d'activation</b></p><div class="cle">${l.cle}</div>
		<p class="bas muted">Pour activer : ouvrez la régie du kit, bouton « Licence », collez la clé puis « Activer ».<br>${v.nom}${v.email ? ` · ${v.email}` : ''}${v.site ? ` · ${v.site}` : ''}${v.adresse ? `<br>${esc(v.adresse)}` : ''}</p></div></body></html>`);
	const w = window.open(URL.createObjectURL(new Blob([page], {type: 'text/html'})), '_blank');
	if (!w) toast('Autorisez les fenêtres surgissantes pour afficher le certificat.', 'ko');
};

// =================================================================================================
// NOUVELLE LICENCE
// =================================================================================================
PAGES.nouvelle = (v, _, q) => {
	const offres = D.offres.filter((o) => o.actif !== false);
	let offre = offres.find((o) => o.code === q.get('offre')) || offres[0];
	const pre = {client: {nom: q.get('nom') || '', email: q.get('email') || '', organisation: q.get('organisation') || '', pays: q.get('pays') || ''}};
	v.innerHTML = String(html`
		${entete('Nouvelle licence', 'Choisissez une offre, renseignez le client : la clé signée est générée instantanément.')}
		<form id="f-nouvelle" class="formulaire">
			<fieldset><legend>Offre</legend><div class="offres-choix">${offres.map(
				(o) => html`<label class="offre-carte" style="--c:${couleurOffre(o.code)}"><input type="radio" name="offreChoix" value="${o.code}" ${o === offre ? 'checked' : ''}>
				<b>${o.nom}</b><span class="prix">${o.prix ? argent(o.prix) : 'Gratuite'}</span><small>${dureeTxt(o.duree)} · ${o.postes ? `${o.postes} poste${o.postes > 1 ? 's' : ''}` : 'postes illimités'}</small><small class="muted">${o.description}</small></label>`,
			)}</div></fieldset>
			<div id="champs"></div>
			<fieldset><legend>Génération</legend><div class="champs c3">
				<label>Date de début<input type="date" name="emise" value="${aujourdhui()}"></label>
				<label>Nombre de clés identiques (revendeur, lot)<input type="number" name="quantite" min="1" max="500" value="1"></label>
			</div></fieldset>
			<div class="actions"><button>Générer la licence</button></div>
		</form>`);
	const f = $('#f-nouvelle');
	const remplir = () => {
		const debut = $('[name=emise]', f)?.value || aujourdhui();
		const exp = offre.duree ? new Date(Date.parse(`${debut}T12:00:00Z`) + offre.duree * JOUR).toISOString().slice(0, 10) : '';
		const garde = $('#champs').childElementCount ? lireFormulaire(f) : pre;
		$('#champs').innerHTML = String(champsLicence({...garde, client: garde.client, offre: offre.code, expire: exp, postes: offre.postes, fonctions: offre.fonctions, prix: offre.prix, devise: D.config.devise, enLigne: D.config.activationEnLigneParDefaut, paiement: {statut: offre.prix ? garde.paiement?.statut || 'paye' : 'offert', mode: garde.paiement?.mode, reference: garde.paiement?.reference}, tags: garde.tags ? String(garde.tags).split(',') : [], notes: garde.notes}, {nouvelle: true}));
		autoClient(f);
	};
	remplir();
	$$('[name=offreChoix]', f).forEach((r) => r.addEventListener('change', () => ((offre = offres.find((o) => o.code === r.value)), remplir())));
	$('[name=emise]', f).addEventListener('change', remplir);
	f.addEventListener('submit', async (e) => {
		e.preventDefault();
		const corps = {...lireFormulaire(f), offre: offre.code, quantite: Number($('[name=quantite]', f).value) || 1};
		delete corps.offreChoix;
		try {
			const r = await api('licences', {methode: 'POST', corps});
			await charger();
			resultatCreation(r.licences);
		} catch (err) {
			toast(err.message, 'ko');
		}
	});
};
const resultatCreation = (ls) => {
	const l = ls[0];
	const f = ouvrirDialogue(
		html`<h2>✓ ${ls.length > 1 ? `${ls.length} licences générées` : 'Licence générée'}</h2>
		<p>${l.offreNom} pour <b>${l.client.nom}</b> · ${l.expire ? `valable jusqu'au ${date(l.expire)}` : 'perpétuelle'}</p>
		${ls.length > 1 ? html`<textarea class="cle" readonly rows="8">${ls.map((x) => `${x.id}\t${x.cle}`).join('\n')}</textarea>` : html`<textarea class="cle" readonly rows="5">${l.cle}</textarea>`}
		<div class="actions">
			<button type="button" class="sec" id="r-copier">Copier ${ls.length > 1 ? 'tout' : 'la clé'}</button>
			${ls.length > 1 ? '' : html`<button type="button" class="sec" id="r-lic">Fichier .lic</button><button type="button" class="sec" id="r-email">E-mail</button>`}
			<button type="button" id="r-voir">${ls.length > 1 ? 'Voir les licences' : 'Ouvrir la fiche'}</button>
		</div>`,
		{large: true},
	);
	$('#r-copier', f).addEventListener('click', () => copier(ls.length > 1 ? ls.map((x) => `${x.id}\t${x.cle}`).join('\n') : l.cle, 'Clé copiée'));
	$('#r-lic', f)?.addEventListener('click', () => telecharger(`/api/licences/${l.id}/lic`));
	$('#r-email', f)?.addEventListener('click', () => dialogueEmail(l));
	$('#r-voir', f).addEventListener('click', () => {
		dlg.close();
		location.hash = ls.length > 1 ? `#/licences?q=${encodeURIComponent(l.client.email || l.client.nom)}` : `#/licence/${l.id}`;
	});
};

// =================================================================================================
// CLIENTS
// =================================================================================================
PAGES.clients = (v) => {
	const m = new Map();
	for (const l of D.licences) {
		const k = (l.client.email || l.client.nom).toLowerCase();
		const c = m.get(k) || {...l.client, licences: [], total: 0, dernier: 0, premier: Infinity};
		c.licences.push(l);
		if (l.paiement?.statut !== 'offert' && l.devise === D.config.devise) c.total += l.prix + (l.renouvellements || []).reduce((a, r) => a + (r.prix || 0), 0);
		c.dernier = Math.max(c.dernier, l.creeLe);
		c.premier = Math.min(c.premier, l.creeLe);
		if (l.creeLe >= c.dernier) Object.assign(c, {nom: l.client.nom, organisation: l.client.organisation || c.organisation, pays: l.client.pays || c.pays, telephone: l.client.telephone || c.telephone});
		m.set(k, c);
	}
	const clients = [...m.values()].sort((a, b) => b.total - a.total || b.dernier - a.dernier);
	v.innerHTML = String(html`${entete('Clients', `${clients.length} client(s) · ${argent(clients.reduce((a, c) => a + c.total, 0))} encaissés au total`)}
		<div class="filtres"><input type="search" id="c-q" placeholder="Rechercher un client…"></div>
		<div class="carte table-carte"><table class="table"><thead><tr><th>Client</th><th>Pays</th><th class="num">Licences</th><th class="num">En cours</th><th class="num">Total encaissé</th><th class="num">Client depuis</th><th class="num">Dernier achat</th><th></th></tr></thead><tbody id="c-corps"></tbody></table></div>`);
	const rendre = (q = '') => {
		const liste = clients.filter((c) => !q || [c.nom, c.email, c.organisation, c.pays].join(' ').toLowerCase().includes(q.toLowerCase()));
		$('#c-corps').innerHTML = liste.length
			? liste
					.map((c) => String(html`<tr><td><a href="#/licences?q=${encodeURIComponent(c.email || c.nom)}"><b>${c.nom}</b></a>${c.organisation ? html` · ${c.organisation}` : ''}<br><small class="muted">${c.email}${c.telephone ? ` · ${c.telephone}` : ''}</small></td><td>${c.pays || '—'}</td><td class="num">${c.licences.length}</td><td class="num">${c.licences.filter((l) => ['active', 'expire-bientot'].includes(l.statutEffectif)).length}</td><td class="num">${argent(c.total)}</td><td class="num">${dateT(c.premier)}</td><td class="num">${dateT(c.dernier)}</td>
					<td><a class="bouton sec petit" href="#/nouvelle?nom=${encodeURIComponent(c.nom)}&email=${encodeURIComponent(c.email || '')}&organisation=${encodeURIComponent(c.organisation || '')}&pays=${encodeURIComponent(c.pays || '')}">＋ Licence</a></td></tr>`))
					.join('')
			: `<tr><td colspan="8" class="vide">${clients.length ? 'Aucun client trouvé.' : 'Les clients apparaîtront ici dès la première licence.'}</td></tr>`;
	};
	$('#c-q').addEventListener('input', (e) => rendre(e.target.value));
	rendre();
};

// =================================================================================================
// OFFRES ET TARIFS
// =================================================================================================
const DUREES = [[30, '1 mois'], [90, '3 mois'], [182, '6 mois'], [365, '1 an'], [730, '2 ans'], [1095, '3 ans'], [0, 'Perpétuelle']];
const optionsDuree = (duree) => {
	const liste = DUREES.some(([j]) => j === duree) ? DUREES : [...DUREES, [duree, `${duree} jours`]];
	return liste.map(([j, lib]) => html`<option value="${j}" ${j === duree ? 'selected' : ''}>${lib}</option>`);
};
PAGES.offres = (v) => {
	let offres = structuredClone(D.offres);
	const stats = (code) => {
		const ls = D.licences.filter((l) => l.offre === code);
		return {n: ls.length, actives: ls.filter((l) => ['active', 'expire-bientot'].includes(l.statutEffectif)).length, ca: encaissements(ls).filter((e) => e.devise === D.config.devise).reduce((a, e) => a + e.montant, 0)};
	};
	const rendre = () => {
		v.innerHTML = String(html`${entete('Offres et tarifs', 'Les offres pré-remplissent les nouvelles licences. Modifier une offre ne change pas les licences déjà émises.', html`<button type="button" class="sec" id="o-ajout">＋ Ajouter une offre</button><button type="button" id="o-enreg">Enregistrer</button>`)}
		<div class="grille-offres">${offres.map((o, i) => {
			const s = stats(o.code);
			return html`<section class="carte offre-edit" data-i="${i}" style="--c:${i < 8 ? `var(--s${i + 1})` : 'var(--muted)'}">
				<header><h2><span class="pastille"></span>${o.nom || 'Nouvelle offre'}</h2><label class="case-lib"><input type="checkbox" data-k="actif" ${o.actif !== false ? 'checked' : ''}> Proposée</label></header>
				<p class="muted petit">${s.n} licence(s) · ${s.actives} en cours · ${argent(s.ca)}</p>
				<div class="champs c2">
					<label>Nom<input data-k="nom" value="${o.nom}"></label>
					<label>Code (dans la clé)<input data-k="code" value="${o.code}" ${s.n ? 'readonly title="Déjà utilisé par des licences"' : ''}></label>
					<label>Prix (${D.config.devise})<input type="number" min="0" step="0.01" data-k="prix" value="${o.prix}"></label>
					<label>Durée<select data-k="duree">${optionsDuree(o.duree)}</select></label>
					<label>Postes (0 = illimité)<input type="number" min="0" data-k="postes" value="${o.postes}"></label>
				</div>
				<label>Description<input data-k="description" value="${o.description || ''}"></label>
				<div class="fonctions">${Object.entries(D.fonctions).map(([k, n]) => html`<label class="case-lib"><input type="checkbox" data-f="${k}" ${o.fonctions.includes(k) ? 'checked' : ''}> ${n}</label>`)}</div>
				<div class="actions-ligne"><button type="button" class="sec petit" data-monter>↑</button><button type="button" class="sec petit" data-descendre>↓</button><button type="button" class="danger lien petit" data-supprimer ${s.n ? 'disabled title="Utilisée par des licences : décochez « Proposée » pour la retirer"' : ''}>Supprimer</button></div>
			</section>`;
		})}</div>`);
		$$('.offre-edit', v).forEach((c) => {
			const i = Number(c.dataset.i);
			$$('[data-k]', c).forEach((inp) =>
				inp.addEventListener('input', () => {
					const k = inp.dataset.k;
					offres[i][k] = inp.type === 'checkbox' ? inp.checked : ['prix', 'duree', 'postes'].includes(k) ? Number(inp.value) : inp.value;
				}),
			);
			$$('[data-f]', c).forEach((inp) => inp.addEventListener('change', () => (offres[i].fonctions = $$('[data-f]:checked', c).map((x) => x.dataset.f))));
			$('[data-supprimer]', c).addEventListener('click', () => (offres.splice(i, 1), rendre()));
			$('[data-monter]', c).addEventListener('click', () => i > 0 && (([offres[i - 1], offres[i]] = [offres[i], offres[i - 1]]), rendre()));
			$('[data-descendre]', c).addEventListener('click', () => i < offres.length - 1 && (([offres[i + 1], offres[i]] = [offres[i], offres[i + 1]]), rendre()));
		});
		$('#o-ajout').addEventListener('click', () => {
			offres.push({code: `offre-${offres.length + 1}`, nom: 'Nouvelle offre', prix: 0, duree: 365, postes: 1, fonctions: [], description: '', actif: true});
			rendre();
		});
		$('#o-enreg').addEventListener('click', async () => {
			try {
				D.offres = (await api('offres', {methode: 'PUT', corps: {offres}})).offres;
				offres = structuredClone(D.offres);
				toast('Offres enregistrées ✓', 'ok');
				rendre();
			} catch (e) {
				toast(e.message, 'ko');
			}
		});
	};
	rendre();
};

// =================================================================================================
// VÉRIFIER UNE CLÉ
// =================================================================================================
PAGES.verifier = (v) => {
	v.innerHTML = String(html`${entete('Vérifier une clé', 'Collez une clé envoyée par un client pour contrôler son authenticité et son contenu.')}
		<section class="carte"><textarea id="v-cle" rows="5" class="cle" placeholder="OOK1-…" spellcheck="false"></textarea>
		<div class="actions"><button type="button" id="v-ok">Vérifier</button></div><div id="v-res"></div></section>`);
	$('#v-ok').addEventListener('click', async () => {
		const r = await api('verifier', {methode: 'POST', corps: {cle: $('#v-cle').value}});
		const c = r.contenu || {};
		$('#v-res').innerHTML = String(html`
			<div class="verdict ${r.valide ? 'ok' : 'ko'}">${r.valide ? '✓ Signature authentique : cette clé a bien été émise avec votre clé privée.' : `✕ ${r.erreur}`}</div>
			${r.contenu ? html`<dl class="def">
				<dt>N° de licence</dt><dd class="mono">${c.id}</dd><dt>Titulaire</dt><dd>${c.n} ${c.o ? `(${c.o})` : ''} ${c.e ? `· ${c.e}` : ''}</dd>
				<dt>Offre</dt><dd>${c.pn || c.p}</dd><dt>Émise le</dt><dd>${date(c.d)}</dd><dt>Expiration</dt><dd>${c.x ? date(c.x) : 'Perpétuelle'}</dd>
				<dt>Postes</dt><dd>${c.m ? `lié à ${c.m}` : c.a || 'illimité'}</dd><dt>Fonctions</dt><dd>${(c.f || []).map((f) => D.fonctions[f] || f).join(', ') || '—'}</dd>
				<dt>Activation en ligne</dt><dd>${c.s || 'non'}</dd>
			</dl>` : ''}
			${r.enBase ? html`<p>Dans votre base : ${badge(r.enBase.statutEffectif)} · <a href="#/licence/${r.enBase.id}">ouvrir la fiche</a>${r.aJour === false ? html` <span class="badge warning">⚠ ancienne version de la clé (remplacée depuis)</span>` : ''}</p>` : r.valide ? html`<p class="muted">Cette licence n'existe pas dans la base (supprimée, ou émise depuis une autre installation).</p>` : ''}
		`);
	});
};

// =================================================================================================
// JOURNAL
// =================================================================================================
PAGES.journal = (v) => {
	v.innerHTML = String(html`${entete('Journal', 'Toutes les opérations : créations, activations, révocations, connexions…')}
		<div class="filtres"><input type="search" id="j-q" placeholder="Rechercher…"><select id="j-type"><option value="">Tous les événements</option>${Object.keys(TYPES_JOURNAL).map((t) => html`<option>${t}</option>`)}</select></div>
		<section class="carte"><ul class="journal" id="j-liste"></ul></section>`);
	const rendre = () => {
		const q = $('#j-q').value.toLowerCase();
		const t = $('#j-type').value;
		listeJournal($('#j-liste'), D.journal.filter((j) => (!t || j.type === t) && (!q || `${j.message} ${j.licence}`.toLowerCase().includes(q))).slice(-500).reverse());
	};
	$('#j-q').addEventListener('input', rendre);
	$('#j-type').addEventListener('change', rendre);
	rendre();
};

// =================================================================================================
// PARAMÈTRES
// =================================================================================================
PAGES.parametres = (v) => {
	const c = D.config;
	v.innerHTML = String(html`${entete('Paramètres')}
	<form class="carte formulaire" id="p-general"><h2>Votre activité</h2><div class="champs c2">
		<label>Nom affiché (vendeur)<input name="vendeur.nom" value="${c.vendeur.nom}"></label>
		<label>E-mail de contact<input type="email" name="vendeur.email" value="${c.vendeur.email}"></label>
		<label>Site / page de vente<input name="vendeur.site" value="${c.vendeur.site}" placeholder="https://…"></label>
		<label>Adresse (certificats)<input name="vendeur.adresse" value="${c.vendeur.adresse}"></label>
		<label>Nom du produit<input name="produit" value="${c.produit}"></label>
		<label>Devise principale<input name="devise" value="${c.devise}" maxlength="5"></label>
		<label>Alerte « expire bientôt » (jours)<input type="number" name="alerteJours" min="1" max="365" value="${c.alerteJours}"></label>
	</div>
	<label>Modèle d'e-mail <small class="muted">— variables : {client} {produit} {offre} {cle} {id} {validite} {postes} {vendeur}</small><textarea name="modeleEmail" rows="9">${c.modeleEmail}</textarea></label>
	<div class="actions"><button>Enregistrer</button></div></form>

	<form class="carte formulaire" id="p-enligne"><h2>Activation en ligne <small class="muted">facultatif</small></h2>
		<p class="muted">Par défaut, les licences se vérifient <b>hors ligne</b> grâce à la signature : rien à héberger. Pour suivre les postes installés, limiter le nombre de PC et <b>révoquer une clé à distance</b>, rendez ce gestionnaire accessible sur Internet (ex. VPS + HTTPS) et indiquez son adresse : elle sera inscrite dans les nouvelles licences « en ligne ».</p>
		<div class="champs c2">
			<label>Adresse publique du serveur<input name="serveurPublic" value="${c.serveurPublic}" placeholder="https://licences.mondomaine.com"></label>
			<label class="case-lib"><input type="checkbox" name="activationEnLigneParDefaut" ${c.activationEnLigneParDefaut ? 'checked' : ''}> Cocher l'activation en ligne par défaut</label>
		</div>
		<p class="muted petit">Lancement : <code>node server.mjs --public</code> derrière un reverse proxy HTTPS (Caddy, Nginx). Mettez <code>TRUST_PROXY=1</code> pour que le proxy transmette l'adresse des clients. Seules les routes <code>/api/public/*</code> sont accessibles sans mot de passe. État actuel : ${D.publique ? 'serveur ouvert sur le réseau' : 'serveur limité à ce PC'}.</p>
		<div class="actions"><button>Enregistrer</button></div></form>

	<section class="carte"><h2>Clé de signature</h2>
		<p>Empreinte : <code class="mono">${D.cle.empreinte}</code></p>
		<p class="muted">Le kit vérifie les licences avec la <b>clé publique</b> ci-dessous. Elle doit se trouver dans <code>obs-overlay-kit/licence/cle-publique.pem</code> de la version que vous distribuez (avec <code>vendeur.json</code> pour afficher vos coordonnées dans la régie). La <b>clé privée</b> (<code>data/cle-privee.pem</code>) ne doit jamais être partagée : sauvegardez-la en lieu sûr, sans elle vous ne pourrez plus émettre de licences.</p>
		<textarea class="cle" readonly rows="4">${D.cle.publique}</textarea>
		<div class="actions"><a class="bouton sec" href="/api/export/cle-publique.pem" download>Télécharger cle-publique.pem</a><a class="bouton sec" href="/api/export/vendeur.json" download>Télécharger vendeur.json</a><button type="button" class="danger" id="p-regen">Régénérer les clés…</button></div>
	</section>

	<section class="carte"><h2>Données</h2>
		<p class="muted">Une copie de la base est conservée automatiquement chaque jour (30 jours) dans <code>data/sauvegardes/</code>.</p>
		<div class="actions"><a class="bouton sec" href="/api/export/licences.csv" download>Exporter les licences (CSV / Excel)</a><a class="bouton sec" href="/api/export/sauvegarde.json" download>Télécharger une sauvegarde complète</a>
		<label class="bouton sec">Importer une sauvegarde…<input type="file" id="p-import" accept=".json,application/json" hidden></label></div>
	</section>

	<section class="carte"><h2>Automatisation (jeton d'API)</h2>
		<p class="muted">Créez des licences automatiquement après un paiement (boutique en ligne, Zapier/Make, script) : <code>POST /api/licences</code> avec l'en-tête <code>Authorization: Bearer &lt;jeton&gt;</code>. Exemple en ligne de commande : <code>node scripts/creer-licence.mjs --offre createur --nom "Église Bethel" --email contact@bethel.org</code>. Le jeton ne permet que de créer et lire des licences.</p>
		<p>État : ${c.jetonActif ? html`<span class="badge good">✓ jeton actif</span>` : html`<span class="badge neutre">aucun jeton</span>`}</p>
		<div class="actions"><button type="button" class="sec" id="p-jeton">${c.jetonActif ? 'Remplacer le jeton' : 'Générer un jeton'}</button>${c.jetonActif ? html`<button type="button" class="danger" id="p-jeton-suppr">Supprimer le jeton</button>` : ''}</div>
	</section>

	<form class="carte formulaire" id="p-mdp"><h2>Sécurité</h2><div class="champs c2">
		<label>Mot de passe actuel<input type="password" name="ancien" autocomplete="current-password" required></label>
		<label>Nouveau mot de passe (8 caractères min.)<input type="password" name="nouveau" minlength="8" autocomplete="new-password" required></label>
	</div><div class="actions"><button>Changer le mot de passe</button></div></form>
	<p class="muted petit">Gestionnaire de licences v${D.version}</p>`);

	const enregistrer = (id) =>
		$(id).addEventListener('submit', async (e) => {
			e.preventDefault();
			const o = {};
			for (const [k, val] of new FormData(e.target)) {
				if (k.includes('.')) {
					const [a, b] = k.split('.');
					(o[a] ||= {})[b] = val;
				} else o[k] = val;
			}
			const cb = $('[name=activationEnLigneParDefaut]', e.target);
			if (cb) o.activationEnLigneParDefaut = cb.checked;
			try {
				await api('config', {methode: 'PUT', corps: o});
				await charger();
				toast('Paramètres enregistrés ✓', 'ok');
			} catch (err) {
				toast(err.message, 'ko');
			}
		});
	enregistrer('#p-general');
	enregistrer('#p-enligne');
	$('#p-mdp').addEventListener('submit', async (e) => {
		e.preventDefault();
		try {
			await api('mot-de-passe', {methode: 'POST', corps: Object.fromEntries(new FormData(e.target))});
			e.target.reset();
			toast('Mot de passe modifié ✓', 'ok');
		} catch (err) {
			toast(err.message, 'ko');
		}
	});
	$('#p-regen').addEventListener('click', async () => {
		const saisie = await confirmer(
			'Régénérer la paire de clés ?',
			'Toutes les clés déjà distribuées deviendront INVALIDES dans les kits qui recevront la nouvelle clé publique. À réserver au cas où la clé privée aurait fuité. Les anciennes clés sont archivées dans data/sauvegardes/. Tapez REGENERER pour confirmer.',
			{danger: true, bouton: 'Régénérer', saisie: 'Confirmation'},
		);
		if (saisie !== 'REGENERER') return saisie && toast('Confirmation incorrecte.', 'ko');
		const resigner = await confirmer('Signer à nouveau les licences existantes ?', 'Recommandé : chaque licence reçoit une nouvelle clé compatible avec la nouvelle clé publique (à renvoyer aux clients).', {bouton: 'Oui, signer à nouveau'});
		await api('cles/regenerer', {methode: 'POST', corps: {confirmation: 'REGENERER', resigner: !!resigner}});
		await charger();
		toast('Nouvelle paire de clés générée. Distribuez la nouvelle clé publique avec le kit.', 'ok');
		routeur();
	});
	$('#p-jeton').addEventListener('click', async () => {
		if (c.jetonActif && !(await confirmer('Remplacer le jeton ?', 'L’ancien jeton cessera immédiatement de fonctionner.', {bouton: 'Remplacer'}))) return;
		const r = await api('jeton', {methode: 'POST', corps: {}});
		await charger();
		const f = ouvrirDialogue(html`<h2>Nouveau jeton d'API</h2><p>Copiez-le maintenant : il ne sera <b>plus jamais affiché</b>.</p><textarea class="cle" readonly rows="2">${r.jeton}</textarea>
			<div class="actions"><button type="button" class="sec" id="j-copier">Copier</button><button type="button" data-fermer>J'ai copié le jeton</button></div>`);
		$('#j-copier', f).addEventListener('click', () => copier(r.jeton, 'Jeton copié'));
		dlg.addEventListener('close', routeur, {once: true});
	});
	$('#p-jeton-suppr')?.addEventListener('click', async () => {
		if (!(await confirmer('Supprimer le jeton ?', 'Les automatisations qui l’utilisent cesseront de fonctionner.', {bouton: 'Supprimer', danger: true}))) return;
		await api('jeton', {methode: 'POST', corps: {supprimer: true}});
		await charger();
		routeur();
	});
	$('#p-import').addEventListener('change', async (e) => {
		const fi = e.target.files[0];
		if (!fi) return;
		try {
			const d = JSON.parse(await fi.text());
			const r = await api('import', {methode: 'POST', corps: d});
			await charger();
			toast(`${r.importees} licence(s) importée(s) ✓`, 'ok');
		} catch (err) {
			toast(err.message, 'ko');
		}
		e.target.value = '';
	});
};

// --- Démarrage -----------------------------------------------------------------------------------
const demarrer = async () => {
	try {
		await charger();
	} catch {
		return;
	}
	$('#nav').hidden = false;
	if (!location.hash) location.hash = '#/tableau';
	routeur();
};
(async () => {
	const s = await (await fetch('/api/session')).json();
	if (s.connecte) demarrer();
	else ecranConnexion();
})();
