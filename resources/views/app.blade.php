<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Suivi énergie CIE</title>
<style>
:root {
  --fond: #f4f6f8; --carte: #fff; --texte: #17212b; --doux: #5d6b7a; --trait: #dde3ea;
  --accent: #0b6e4f; --accent-clair: #e3f3ed; --alerte: #b42318; --alerte-clair: #fdecea;
  --attention: #9a5b00; --attention-clair: #fff3dc; --barre: #2f8f6f; --barre-partielle: #b7d9cc;
}
@media (prefers-color-scheme: dark) {
  :root {
    --fond: #10161c; --carte: #19222b; --texte: #e8edf2; --doux: #9aa8b6; --trait: #2b3743;
    --accent: #4cc39b; --accent-clair: #17352c; --alerte: #ff8a80; --alerte-clair: #3a1c1a;
    --attention: #f0b455; --attention-clair: #3a2c12; --barre: #4cc39b; --barre-partielle: #2c5a4b;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--fond); color: var(--texte); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 860px; margin: 0 auto; padding: 16px; }
h1 { font-size: 1.25rem; margin: 0; }
h2 { font-size: 1.05rem; margin: 0 0 12px; }
.entete { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.entete .droite { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.carte { background: var(--carte); border: 1px solid var(--trait); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.grille { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
.grille .carte { margin: 0; }
.libelle { color: var(--doux); font-size: .85rem; }
.valeur { font-size: 1.7rem; font-weight: 650; font-variant-numeric: tabular-nums; }
.valeur small { font-size: .9rem; font-weight: 500; color: var(--doux); }
.note { color: var(--doux); font-size: .85rem; margin-top: 2px; }
.faible .valeur { color: var(--alerte); }
button, .bouton { font: inherit; border: 1px solid var(--trait); background: var(--carte); color: var(--texte); border-radius: 8px; padding: 8px 12px; cursor: pointer; }
button.principal { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
@media (prefers-color-scheme: dark) { button.principal { color: #06241a; } }
button.petit { padding: 4px 9px; font-size: .85rem; }
button.lien { border: 0; background: none; color: var(--accent); padding: 0; text-decoration: underline; }
button:disabled { opacity: .55; cursor: default; }
input, select, textarea { font: inherit; width: 100%; padding: 9px 10px; border: 1px solid var(--trait); border-radius: 8px; background: var(--carte); color: var(--texte); }
label { display: block; font-size: .9rem; color: var(--doux); margin: 10px 0 4px; }
.ligne { display: flex; gap: 10px; } .ligne > * { flex: 1; min-width: 0; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.erreur { background: var(--alerte-clair); color: var(--alerte); border-radius: 8px; padding: 8px 10px; margin-top: 12px; font-size: .9rem; }
.cache { display: none !important; }
.badge { display: inline-block; border-radius: 999px; padding: 1px 9px; font-size: .78rem; font-weight: 600; white-space: nowrap; }
.badge.en_attente { background: var(--attention-clair); color: var(--attention); }
.badge.saisi { background: var(--trait); color: var(--texte); }
.badge.confirme { background: var(--accent-clair); color: var(--accent); }
.badge.rejete, .badge.critique { background: var(--alerte-clair); color: var(--alerte); }
.badge.attention { background: var(--attention-clair); color: var(--attention); }
.badge.info { background: var(--trait); color: var(--texte); }
.element { border-top: 1px solid var(--trait); padding: 12px 0; }
.element:first-of-type { border-top: 0; padding-top: 0; }
.element .haut { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.code { font-family: ui-monospace, Menlo, Consolas, monospace; letter-spacing: .04em; }
.graphique { display: flex; align-items: flex-end; gap: 3px; height: 150px; border-bottom: 1px solid var(--trait); }
.graphique .barre { flex: 1; min-width: 4px; background: var(--barre); border-radius: 3px 3px 0 0; }
.graphique .barre.partielle { background: var(--barre-partielle); }
.axe { display: flex; justify-content: space-between; color: var(--doux); font-size: .78rem; margin-top: 4px; }
.legende { color: var(--doux); font-size: .8rem; margin-top: 8px; }
.legende i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin: 0 4px 0 10px; }
dialog { border: 1px solid var(--trait); border-radius: 14px; padding: 20px; width: min(460px, calc(100vw - 32px)); background: var(--carte); color: var(--texte); }
dialog::backdrop { background: rgba(0,0,0,.45); }
.groupes { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin: 16px 0; }
.groupes span { font: 650 1.5rem ui-monospace, Menlo, Consolas, monospace; background: var(--accent-clair); color: var(--accent); border-radius: 8px; padding: 6px 10px; }
h3 { font-size: .95rem; margin: 22px 0 8px; padding-top: 16px; border-top: 1px solid var(--trait); }
button.danger { color: var(--alerte); border-color: var(--alerte); }
dialog { max-height: calc(100vh - 32px); overflow-y: auto; }
.vide { color: var(--doux); text-align: center; padding: 12px 0; }
.connexion { max-width: 400px; margin: 8vh auto 0; }
.onglets { display: flex; gap: 6px; margin-bottom: 8px; }
.onglets button.actif { background: var(--accent-clair); border-color: var(--accent); color: var(--accent); font-weight: 600; }
</style>
</head>
<body>
<main>

<!-- ===================== Connexion ===================== -->
<section id="ecran-connexion" class="connexion cache">
  <h1 style="margin-bottom:6px">Suivi énergie CIE</h1>
  <p class="note" style="margin:0 0 16px">Recharges, relevés et autonomie de votre compteur prépayé.</p>
  <div class="carte">
    <div class="onglets">
      <button type="button" id="onglet-connexion" class="actif">Connexion</button>
      <button type="button" id="onglet-inscription">Créer un compte</button>
    </div>
    <form id="form-auth">
      <div id="champ-nom" class="cache"><label for="auth-nom">Nom</label><input id="auth-nom" autocomplete="name"></div>
      <label for="auth-email">Adresse e-mail</label><input id="auth-email" type="email" required autocomplete="email">
      <label for="auth-mdp">Mot de passe</label><input id="auth-mdp" type="password" required minlength="8" autocomplete="current-password">
      <div class="erreur cache" id="auth-erreur"></div>
      <div class="actions"><button class="principal" id="auth-envoyer">Se connecter</button></div>
    </form>
  </div>
</section>

<!-- ===================== Application ===================== -->
<section id="ecran-app" class="cache">
  <div class="entete">
    <h1>Suivi énergie CIE</h1>
    <div class="droite">
      <select id="choix-compteur" aria-label="Compteur" style="width:auto;max-width:230px"></select>
      <button type="button" id="btn-reglages" class="cache">Réglages</button>
      <button type="button" id="btn-nouveau-compteur">+ Compteur</button>
      <button type="button" id="btn-deconnexion">Déconnexion</button>
    </div>
  </div>

  <div id="aucun-compteur" class="carte cache">
    <h2>Bienvenue</h2>
    <p>Ajoutez votre premier compteur pour commencer. Le numéro à 11 ou 13 chiffres est celui demandé lors de l'achat de crédit.</p>
    <button type="button" class="principal" id="btn-premier-compteur">Ajouter un compteur</button>
  </div>

  <div id="tableau" class="cache">
    <div class="grille">
      <div class="carte" id="carte-credit"><div class="libelle">Crédit estimé</div><div class="valeur" id="v-credit">—</div><div class="note" id="n-credit"></div></div>
      <div class="carte"><div class="libelle">Autonomie</div><div class="valeur" id="v-autonomie">—</div><div class="note" id="n-autonomie"></div></div>
      <div class="carte"><div class="libelle">Consommation moyenne</div><div class="valeur" id="v-moyenne">—</div><div class="note" id="n-moyenne"></div></div>
      <div class="carte"><div class="libelle">Recharges ce mois</div><div class="valeur" id="v-mois">—</div><div class="note" id="n-mois"></div></div>
    </div>

    <div class="carte">
      <div class="actions" style="margin-top:0">
        <button type="button" class="principal" id="btn-recharge">Enregistrer une recharge</button>
        <button type="button" id="btn-releve">Ajouter un relevé</button>
      </div>
    </div>

    <div class="carte">
      <h2>Consommation des 30 derniers jours</h2>
      <div id="graphique-vide" class="vide cache">Ajoutez au moins deux relevés, à des jours différents, pour voir votre consommation.</div>
      <div id="graphique-bloc">
        <div class="graphique" id="graphique" role="img" aria-label="Consommation par jour en kWh"></div>
        <div class="axe"><span id="axe-debut"></span><span id="axe-max"></span><span id="axe-fin"></span></div>
        <div class="legende"><i style="background:var(--barre);margin-left:0"></i>jour complet<i style="background:var(--barre-partielle)"></i>jour partiel</div>
      </div>
    </div>

    <div class="carte">
      <h2>Alertes</h2>
      <div id="liste-alertes"></div>
    </div>

    <div class="carte">
      <h2>Recharges</h2>
      <div id="liste-recharges"></div>
    </div>

    <div class="carte">
      <h2>Derniers relevés</h2>
      <div id="liste-releves"></div>
    </div>
  </div>
</section>

<!-- ===================== Fenêtres ===================== -->
<dialog id="dlg-compteur">
  <form id="form-compteur">
    <h2>Ajouter un compteur</h2>
    <label for="c-site">Lieu (maison, immeuble, bureau…)</label><input id="c-site" required maxlength="120" placeholder="Maison Cocody">
    <label for="c-libelle">Nom du compteur</label><input id="c-libelle" required maxlength="80" placeholder="Compteur salon">
    <label for="c-numero">Numéro du compteur (11 ou 13 chiffres)</label><input id="c-numero" required inputmode="numeric" placeholder="42071094462">
    <label for="c-seuil">M'alerter sous (kWh)</label><input id="c-seuil" type="number" min="0" step="1" value="20">
    <div class="erreur cache"></div>
    <div class="actions"><button class="principal">Ajouter</button><button type="button" data-fermer>Annuler</button></div>
  </form>
</dialog>

<dialog id="dlg-reglages">
  <h2>Réglages du compteur</h2>
  <p class="note" id="g-numero" style="margin-top:-6px"></p>

  <form id="form-reglages">
    <label for="g-libelle">Nom du compteur</label><input id="g-libelle" required maxlength="80">
    <label for="g-seuil">M'alerter sous (kWh)</label><input id="g-seuil" type="number" min="0" step="1" required>
    <div class="erreur cache"></div>
    <div class="actions"><button class="principal">Enregistrer</button></div>
  </form>

  <h3>Partage</h3>
  <div id="g-partages"></div>
  <form id="form-partage">
    <label for="g-email">Adresse e-mail d'une personne déjà inscrite</label><input id="g-email" type="email" required>
    <label for="g-role">Rôle</label>
    <select id="g-role">
      <option value="occupant">Occupant — consulte, ajoute des relevés, ne voit pas les codes</option>
      <option value="gestionnaire">Gestionnaire — enregistre les recharges et voit les codes</option>
    </select>
    <div class="erreur cache"></div>
    <div class="actions"><button class="principal">Partager</button></div>
  </form>

  <h3>Capteur de consommation</h3>
  <div id="g-capteur" class="note"></div>
  <div id="g-jeton" class="cache">
    <p class="note">Jeton du capteur — copiez-le maintenant, il ne sera plus affiché :</p>
    <input id="g-jeton-valeur" class="code" readonly>
  </div>
  <form id="form-capteur">
    <label for="g-identifiant">Identifiant du capteur (ex. ESP32-A1B2)</label><input id="g-identifiant" required maxlength="40" pattern="[A-Za-z0-9:_\-]+">
    <div class="erreur cache"></div>
    <div class="actions">
      <button class="principal" id="g-associer">Associer</button>
      <button type="button" id="g-dissocier" class="cache">Retirer le capteur</button>
    </div>
  </form>

  <h3>Supprimer</h3>
  <p class="note">Le compteur disparaît de l'application ; l'historique des recharges est conservé en base.</p>
  <div class="actions" style="margin-top:8px">
    <button type="button" id="g-supprimer" class="danger">Supprimer ce compteur</button>
    <button type="button" data-fermer>Fermer</button>
  </div>
</dialog>

<dialog id="dlg-recharge">
  <form id="form-recharge">
    <h2>Enregistrer une recharge</h2>
    <label for="r-code">Code de 20 chiffres (ou collez le SMS reçu)</label>
    <textarea id="r-code" rows="2" required placeholder="1234 5678 9012 3456 7890"></textarea>
    <div class="ligne">
      <div><label for="r-montant">Montant (FCFA)</label><input id="r-montant" type="number" min="100" step="1" required></div>
      <div><label for="r-kwh">Énergie (kWh)</label><input id="r-kwh" type="number" min="0" step="0.01" placeholder="facultatif"></div>
    </div>
    <label for="r-mode">Payé par</label>
    <select id="r-mode">
      <option value="orange_money">Orange Money</option><option value="mtn_money">MTN Money</option>
      <option value="moov_money">Moov Money</option><option value="wave">Wave</option>
      <option value="agence">Agence CIE</option><option value="autre">Autre</option>
    </select>
    <p class="note">L'application ne crédite pas le compteur : vous devrez taper le code sur son clavier.</p>
    <div class="erreur cache"></div>
    <div class="actions"><button class="principal">Enregistrer</button><button type="button" data-fermer>Annuler</button></div>
  </form>
</dialog>

<dialog id="dlg-releve">
  <form id="form-releve">
    <h2>Ajouter un relevé</h2>
    <label for="l-type">Valeur lue sur le compteur</label>
    <select id="l-type"><option value="credit">Crédit restant (kWh)</option><option value="index">Index de consommation (kWh)</option></select>
    <label for="l-valeur">Valeur (kWh)</label><input id="l-valeur" type="number" min="0" step="0.01" required>
    <label for="l-date">Date et heure du relevé</label><input id="l-date" type="datetime-local">
    <div class="erreur cache"></div>
    <div class="actions">
      <button class="principal">Enregistrer</button>
      <button type="button" id="l-forcer" class="cache">Enregistrer quand même</button>
      <button type="button" data-fermer>Annuler</button>
    </div>
  </form>
</dialog>

<dialog id="dlg-code">
  <h2>Code à taper sur le compteur</h2>
  <div class="groupes" id="code-groupes"></div>
  <p class="note">Tapez les 20 chiffres puis validez avec la touche Entrée (↵) du compteur. Cet affichage est enregistré dans le journal.</p>
  <div class="actions"><button type="button" class="principal" id="code-saisi">J'ai tapé le code</button><button type="button" data-fermer>Fermer</button></div>
</dialog>

<dialog id="dlg-valeur">
  <form id="form-valeur">
    <h2 id="val-titre"></h2>
    <label for="val-champ" id="val-libelle"></label><input id="val-champ">
    <div class="erreur cache"></div>
    <div class="actions"><button class="principal">Valider</button><button type="button" data-fermer>Annuler</button></div>
  </form>
</dialog>

</main>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const etat = { token: null, compteurs: [], compteurId: null, mode: 'connexion' };
  try { etat.token = localStorage.getItem('energie_token'); etat.compteurId = Number(localStorage.getItem('energie_compteur')) || null; } catch (e) {}

  const nombre = (v, d = 1) => Number(v).toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d });
  const date = (s) => new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  const STATUTS = { en_attente: 'À saisir', saisi: 'Saisi', confirme: 'Confirmé', rejete: 'Anomalie' };
  const MODES = { orange_money: 'Orange Money', mtn_money: 'MTN Money', moov_money: 'Moov Money', wave: 'Wave', agence: 'Agence CIE', autre: 'Autre' };

  /** Crée un élément ; le texte passe toujours par textContent (jamais innerHTML). */
  function el(balise, attributs = {}, ...enfants) {
    const e = document.createElement(balise);
    for (const [k, v] of Object.entries(attributs)) {
      if (k === 'class') e.className = v; else if (k === 'onclick') e.addEventListener('click', v); else e.setAttribute(k, v);
    }
    for (const c of enfants) if (c !== null && c !== undefined) e.append(c);
    return e;
  }

  async function api(methode, chemin, corps) {
    const reponse = await fetch('/api' + chemin, {
      method: methode,
      headers: Object.assign({ Accept: 'application/json' },
        corps ? { 'Content-Type': 'application/json' } : {},
        etat.token ? { Authorization: 'Bearer ' + etat.token } : {}),
      body: corps ? JSON.stringify(corps) : undefined,
    });
    const donnees = reponse.status === 204 ? null : await reponse.json().catch(() => null);
    if (reponse.status === 401 && etat.token) { deconnecter(); throw new Error('Session expirée, reconnectez-vous.'); }
    if (!reponse.ok) {
      const premier = donnees && donnees.errors ? Object.values(donnees.errors)[0][0] : null;
      const erreur = new Error(premier || (donnees && donnees.message) || 'Erreur ' + reponse.status);
      erreur.donnees = donnees; erreur.statut = reponse.status;
      throw erreur;
    }
    return donnees;
  }

  function montrerErreur(form, message) {
    const zone = form.querySelector('.erreur');
    zone.textContent = message || '';
    zone.classList.toggle('cache', !message);
  }

  /** Branche un formulaire : désactive le bouton pendant l'envoi, affiche l'erreur éventuelle. */
  function brancher(form, action) {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const bouton = form.querySelector('button.principal');
      bouton.disabled = true; montrerErreur(form, '');
      try { await action(); } catch (e) { montrerErreur(form, e.message); if (form.surErreur) form.surErreur(e); }
      bouton.disabled = false;
    });
  }

  document.querySelectorAll('[data-fermer]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  function ouvrir(id) { const d = $(id); const f = d.querySelector('form'); if (f) { f.reset(); montrerErreur(f, ''); } d.showModal(); }

  // ------------------------------------------------------------ connexion
  function basculer(mode) {
    etat.mode = mode;
    $('onglet-connexion').classList.toggle('actif', mode === 'connexion');
    $('onglet-inscription').classList.toggle('actif', mode === 'inscription');
    $('champ-nom').classList.toggle('cache', mode === 'connexion');
    $('auth-nom').required = mode === 'inscription';
    $('auth-envoyer').textContent = mode === 'connexion' ? 'Se connecter' : 'Créer mon compte';
  }
  $('onglet-connexion').addEventListener('click', () => basculer('connexion'));
  $('onglet-inscription').addEventListener('click', () => basculer('inscription'));

  brancher($('form-auth'), async () => {
    const corps = { email: $('auth-email').value, password: $('auth-mdp').value };
    if (etat.mode === 'inscription') { corps.name = $('auth-nom').value; corps.password_confirmation = corps.password; }
    const r = await api('POST', etat.mode === 'connexion' ? '/connexion' : '/inscription', corps);
    etat.token = r.token;
    try { localStorage.setItem('energie_token', r.token); } catch (e) {}
    $('form-auth').reset();
    await demarrer();
  });

  function deconnecter() {
    etat.token = null; etat.compteurs = []; etat.compteurId = null;
    try { localStorage.removeItem('energie_token'); localStorage.removeItem('energie_compteur'); } catch (e) {}
    // Rien de l'ancien compte ne reste dans la page.
    ['liste-recharges', 'liste-releves', 'liste-alertes', 'graphique', 'choix-compteur'].forEach((id) => $(id).replaceChildren());
    $('tableau').classList.add('cache'); $('btn-reglages').classList.add('cache');
    $('ecran-app').classList.add('cache'); $('ecran-connexion').classList.remove('cache');
  }
  $('btn-deconnexion').addEventListener('click', async () => { try { await api('POST', '/deconnexion'); } catch (e) {} deconnecter(); });

  // ------------------------------------------------------------ compteurs
  async function demarrer() {
    if (!etat.token) { $('ecran-connexion').classList.remove('cache'); return; }
    try { etat.compteurs = await api('GET', '/compteurs'); } catch (e) { return; }
    $('ecran-connexion').classList.add('cache'); $('ecran-app').classList.remove('cache');

    const choix = $('choix-compteur');
    choix.replaceChildren(...etat.compteurs.map((c) => el('option', { value: c.id }, c.libelle + (c.site ? ' — ' + c.site.nom : ''))));
    choix.classList.toggle('cache', etat.compteurs.length === 0);
    $('aucun-compteur').classList.toggle('cache', etat.compteurs.length > 0);
    $('tableau').classList.toggle('cache', etat.compteurs.length === 0);
    if (!etat.compteurs.length) { $('btn-reglages').classList.add('cache'); return; }

    if (!etat.compteurs.some((c) => c.id === etat.compteurId)) etat.compteurId = etat.compteurs[0].id;
    choix.value = etat.compteurId;
    await charger();
  }
  $('choix-compteur').addEventListener('change', (ev) => {
    etat.compteurId = Number(ev.target.value);
    try { localStorage.setItem('energie_compteur', etat.compteurId); } catch (e) {}
    charger();
  });
  $('btn-nouveau-compteur').addEventListener('click', () => ouvrir('dlg-compteur'));
  $('btn-premier-compteur').addEventListener('click', () => ouvrir('dlg-compteur'));

  brancher($('form-compteur'), async () => {
    // Le site est créé seulement si le compteur est valide : on réutilise un site du même nom.
    const nom = $('c-site').value.trim();
    const sites = await api('GET', '/sites');
    let site = sites.find((s) => s.nom.toLowerCase() === nom.toLowerCase());
    const nouveau = !site;
    if (nouveau) site = await api('POST', '/sites', { nom });
    try {
      const c = await api('POST', '/sites/' + site.id + '/compteurs', {
        libelle: $('c-libelle').value, numero_sts: $('c-numero').value, seuil_credit_faible_kwh: $('c-seuil').value || 20,
      });
      etat.compteurId = c.id;
    } catch (e) {
      if (nouveau) await api('DELETE', '/sites/' + site.id).catch(() => {});
      throw e;
    }
    $('dlg-compteur').close();
    await demarrer();
  });

  // ------------------------------------------------------------ réglages (propriétaire)
  const ROLES = { proprietaire: 'Propriétaire', gestionnaire: 'Gestionnaire', occupant: 'Occupant' };

  $('btn-reglages').addEventListener('click', async () => {
    document.querySelectorAll('#dlg-reglages form').forEach((f) => { f.reset(); montrerErreur(f, ''); });
    $('g-jeton').classList.add('cache'); $('g-jeton-valeur').value = '';
    try { await chargerReglages(); $('dlg-reglages').showModal(); } catch (e) { alert(e.message); }
  });

  async function chargerReglages() {
    const [c, partages] = await Promise.all([
      api('GET', '/compteurs/' + etat.compteurId),
      api('GET', '/compteurs/' + etat.compteurId + '/partages'),
    ]);
    $('g-numero').textContent = 'N° ' + c.numero_sts + (c.site ? ' · ' + c.site.nom : '');
    $('g-libelle').value = c.libelle;
    $('g-seuil').value = Number(c.seuil_credit_faible_kwh);

    $('g-partages').replaceChildren(...partages.map((u) => el('div', { class: 'element' },
      el('div', { class: 'haut' },
        el('span', {}, el('strong', {}, u.name), ' · ' + (ROLES[u.role] || u.role)),
        u.role !== 'proprietaire' ? el('button', { class: 'lien', type: 'button', onclick: async () => {
          if (!confirm('Retirer l\'accès de ' + u.name + ' ?')) return;
          try { await api('DELETE', '/compteurs/' + etat.compteurId + '/partages/' + u.user_id); await chargerReglages(); } catch (e) { alert(e.message); }
        } }, 'Retirer') : null),
      el('div', { class: 'note' }, u.email))));

    const k = c.capteur;
    $('g-capteur').textContent = k
      ? 'Capteur ' + k.identifiant + (k.derniere_activite ? ' — dernière mesure le ' + date(k.derniere_activite) : ' — aucune mesure reçue pour l\'instant')
      : 'Aucun capteur associé. Un capteur (ESP32 sur le voyant du compteur) mesure la consommation en continu.';
    $('g-identifiant').value = k ? k.identifiant : '';
    $('g-associer').textContent = k ? 'Générer un nouveau jeton' : 'Associer';
    $('g-dissocier').classList.toggle('cache', !k);
  }

  brancher($('form-reglages'), async () => {
    await api('PUT', '/compteurs/' + etat.compteurId, { libelle: $('g-libelle').value, seuil_credit_faible_kwh: $('g-seuil').value });
    await demarrer();
  });

  brancher($('form-partage'), async () => {
    await api('POST', '/compteurs/' + etat.compteurId + '/partages', { email: $('g-email').value, role: $('g-role').value });
    $('g-email').value = '';
    await chargerReglages();
  });

  brancher($('form-capteur'), async () => {
    const r = await api('POST', '/compteurs/' + etat.compteurId + '/capteur', { identifiant: $('g-identifiant').value });
    await chargerReglages();
    $('g-jeton-valeur').value = r.jeton; $('g-jeton').classList.remove('cache');
  });
  $('g-dissocier').addEventListener('click', async () => {
    if (!confirm('Retirer le capteur ? Ses mesures seront supprimées.')) return;
    try { await api('DELETE', '/compteurs/' + etat.compteurId + '/capteur'); $('g-jeton').classList.add('cache'); await chargerReglages(); } catch (e) { alert(e.message); }
  });
  $('dlg-reglages').addEventListener('close', () => { $('g-jeton-valeur').value = ''; });   // le jeton ne reste pas dans la page

  $('g-supprimer').addEventListener('click', async () => {
    if (!confirm('Supprimer définitivement ce compteur de l\'application ?')) return;
    try {
      await api('DELETE', '/compteurs/' + etat.compteurId);
      $('dlg-reglages').close(); etat.compteurId = null;
      await demarrer();
    } catch (e) { alert(e.message); }
  });

  // ------------------------------------------------------------ tableau de bord
  async function charger() {
    const id = etat.compteurId;
    const [t, recharges, releves, alertes] = await Promise.all([
      api('GET', '/compteurs/' + id + '/tableau-de-bord'),
      api('GET', '/compteurs/' + id + '/recharges'),
      api('GET', '/compteurs/' + id + '/releves'),
      api('GET', '/compteurs/' + id + '/alertes'),
    ]);
    if (id !== etat.compteurId) return;
    etat.role = t.role;
    afficherChiffres(t); afficherGraphique(t.consommation.jours);
    afficherAlertes(alertes.data); afficherRecharges(recharges.data); afficherReleves(releves.data);
    $('btn-recharge').classList.toggle('cache', t.role === 'occupant');
    $('btn-reglages').classList.toggle('cache', t.role !== 'proprietaire');
  }

  function afficherChiffres(t) {
    const c = t.credit, a = t.autonomie;
    $('carte-credit').classList.toggle('faible', !!(c && c.faible));
    $('v-credit').replaceChildren(c ? nombre(c.estime_kwh) : '—', c ? el('small', {}, ' kWh') : '');
    $('n-credit').textContent = c
      ? 'Relevé : ' + nombre(c.dernier_releve_kwh) + ' kWh le ' + date(c.dernier_releve_le) + (c.faible ? ' — pensez à recharger' : '')
      : 'Ajoutez un relevé de crédit.';
    $('v-autonomie').replaceChildren(a ? String(a.jours_estimes) : '—', a ? el('small', {}, a.jours_estimes > 1 ? ' jours' : ' jour') : '');
    $('n-autonomie').textContent = a
      ? 'Entre ' + a.jours_min + ' et ' + a.jours_max + ' jours' + (a.fiable ? '' : ' — estimation provisoire')
      : 'Disponible après un jour complet de relevés.';
    const m = t.consommation.moyenne_kwh_jour;
    $('v-moyenne').replaceChildren(m !== null ? nombre(m) : '—', m !== null ? el('small', {}, ' kWh/jour') : '');
    $('n-moyenne').textContent = nombre(t.consommation.total_30j_kwh) + ' kWh sur 30 jours';
    $('v-mois').replaceChildren(nombre(t.recharges.mois_fcfa, 0), el('small', {}, ' FCFA'));
    $('n-mois').textContent = nombre(t.recharges.mois_kwh) + ' kWh' + (t.recharges.en_attente ? ' — ' + t.recharges.en_attente + ' code(s) à saisir' : '');
  }

  function afficherGraphique(jours) {
    $('graphique-vide').classList.toggle('cache', jours.length > 0);
    $('graphique-bloc').classList.toggle('cache', jours.length === 0);
    if (!jours.length) return;
    const max = Math.max(...jours.map((j) => j.kwh), 0.1);
    const libelle = (s) => new Date(s + 'T00:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
    $('graphique').replaceChildren(...jours.map((j) => el('div', {
      class: 'barre' + (j.complet ? '' : ' partielle'),
      style: 'height:' + Math.max(2, (j.kwh / max) * 100) + '%',
      title: libelle(j.jour) + ' : ' + nombre(j.kwh, 2) + ' kWh' + (j.complet ? '' : ' (jour partiel)') + (j.source === 'iot' ? ' — capteur' : ''),
    })));
    $('axe-debut').textContent = libelle(jours[0].jour);
    $('axe-max').textContent = 'max. ' + nombre(max) + ' kWh/jour';
    $('axe-fin').textContent = libelle(jours[jours.length - 1].jour);
  }

  function afficherAlertes(alertes) {
    const nonLues = alertes.filter((a) => !a.lue_le);
    $('liste-alertes').replaceChildren(...(nonLues.length ? nonLues.map((a) => el('div', { class: 'element' },
      el('div', { class: 'haut' },
        el('span', {}, el('span', { class: 'badge ' + a.niveau }, a.niveau === 'critique' ? 'Urgent' : a.niveau === 'attention' ? 'Attention' : 'Info'), ' ', a.message),
        el('button', { class: 'petit', type: 'button', onclick: async () => { await api('POST', '/alertes/' + a.id + '/lue'); charger(); } }, 'Vu')),
      el('div', { class: 'note' }, date(a.created_at)),
    )) : [el('div', { class: 'vide' }, 'Aucune alerte.')]));
  }

  function afficherRecharges(recharges) {
    $('liste-recharges').replaceChildren(...(recharges.length ? recharges.map((r) => {
      const actions = el('div', { class: 'actions', style: 'margin-top:8px' });
      const gere = etat.role !== 'occupant';
      if ((r.statut === 'en_attente' || r.statut === 'rejete') && gere) actions.append(el('button', { class: 'petit principal', type: 'button', onclick: () => reveler(r) }, 'Afficher le code'));
      if ((r.statut === 'en_attente' || r.statut === 'rejete') && !gere) actions.append(el('button', { class: 'petit', type: 'button', onclick: () => marquerSaisi(r) }, 'J\'ai tapé le code'));
      if (r.statut === 'saisi') {
        actions.append(el('button', { class: 'petit principal', type: 'button', onclick: () => confirmer(r) }, 'Confirmer le crédit'));
        actions.append(el('button', { class: 'petit', type: 'button', onclick: () => rejeter(r) }, 'Code refusé'));
      }
      if (r.statut === 'en_attente') actions.append(el('button', { class: 'petit', type: 'button', onclick: () => rejeter(r) }, 'Signaler une anomalie'));
      return el('div', { class: 'element' },
        el('div', { class: 'haut' },
          el('strong', {}, nombre(r.montant_fcfa, 0) + ' FCFA' + (r.credit_kwh !== null ? ' · ' + nombre(r.credit_kwh) + ' kWh' : '')),
          el('span', { class: 'badge ' + r.statut }, STATUTS[r.statut] || r.statut)),
        el('div', { class: 'note' }, el('span', { class: 'code' }, r.code_masque), ' · ' + (r.numero || '') + ' · ' + date(r.date_achat) + ' · ' + (MODES[r.mode_paiement] || r.mode_paiement)),
        r.message_compteur ? el('div', { class: 'note' }, 'Message du compteur : ' + r.message_compteur) : null,
        actions.childElementCount ? actions : null);
    }) : [el('div', { class: 'vide' }, 'Aucune recharge enregistrée.')]));
  }

  function afficherReleves(releves) {
    const SOURCES = { manuel: 'saisi à la main', confirmation: 'après recharge', iot: 'capteur' };
    $('liste-releves').replaceChildren(...(releves.length ? releves.slice(0, 8).map((l) => el('div', { class: 'element' },
      el('div', { class: 'haut' },
        el('span', {}, el('strong', {}, nombre(l.valeur_kwh) + ' kWh'), ' ' + (l.type === 'credit' ? 'de crédit' : 'd\'index')),
        l.source === 'manuel' ? el('button', { class: 'lien', type: 'button', onclick: async () => {
          if (!confirm('Supprimer ce relevé ?')) return;
          try { await api('DELETE', '/releves/' + l.id); charger(); } catch (e) { alert(e.message); }
        } }, 'Supprimer') : null),
      el('div', { class: 'note' }, date(l.releve_le) + ' · ' + (SOURCES[l.source] || l.source)),
    )) : [el('div', { class: 'vide' }, 'Aucun relevé.')]));
  }

  // ------------------------------------------------------------ recharges
  $('btn-recharge').addEventListener('click', () => ouvrir('dlg-recharge'));

  // SMS collé : on préremplit le montant et les kWh quand ils sont reconnaissables.
  $('r-code').addEventListener('input', (ev) => {
    const texte = ev.target.value;
    const kwh = texte.match(/(\d+(?:[.,]\d+)?)\s*kwh/i);
    const montant = texte.match(/(\d[\d ]{2,8})\s*(?:f\b|fcfa|xof)/i);
    if (kwh && !$('r-kwh').value) $('r-kwh').value = kwh[1].replace(',', '.');
    if (montant && !$('r-montant').value) $('r-montant').value = montant[1].replace(/\s/g, '');
  });

  brancher($('form-recharge'), async () => {
    await api('POST', '/compteurs/' + etat.compteurId + '/recharges', {
      code: $('r-code').value, montant_fcfa: $('r-montant').value, credit_kwh: $('r-kwh').value || null,
      date_achat: new Date().toISOString(), mode_paiement: $('r-mode').value,
    });
    $('dlg-recharge').close();
    await charger();
  });

  let rechargeAffichee = null;
  async function reveler(r) {
    try {
      const { groupes } = await api('POST', '/recharges/' + r.id + '/reveler');
      rechargeAffichee = r;
      $('code-groupes').replaceChildren(...groupes.map((g) => el('span', {}, g)));
      $('dlg-code').showModal();
    } catch (e) { alert(e.message); }
  }
  $('dlg-code').addEventListener('close', () => $('code-groupes').replaceChildren());   // le code ne reste pas dans la page
  $('code-saisi').addEventListener('click', () => { $('dlg-code').close(); marquerSaisi(rechargeAffichee); });

  /** Petite fenêtre « une valeur » réutilisée pour saisi / confirmer / rejeter. */
  function demander({ titre, libelle, type, requis, action }) {
    const form = $('form-valeur'), champ = $('val-champ');
    $('val-titre').textContent = titre; $('val-libelle').textContent = libelle;
    form.reset(); montrerErreur(form, '');
    champ.type = type; champ.required = requis;
    if (type === 'number') { champ.min = '0'; champ.step = '0.01'; } else { champ.removeAttribute('min'); champ.removeAttribute('step'); champ.maxLength = 120; }
    form.action_ = action;
    $('dlg-valeur').showModal();
  }
  brancher($('form-valeur'), async () => { await $('form-valeur').action_($('val-champ').value); $('dlg-valeur').close(); await charger(); });

  const marquerSaisi = (r) => demander({
    titre: 'Code tapé sur le compteur', libelle: 'Crédit affiché AVANT la saisie, en kWh (facultatif)', type: 'number', requis: false,
    action: (v) => api('POST', '/recharges/' + r.id + '/saisi', { credit_avant_kwh: v === '' ? null : v }),
  });
  const confirmer = (r) => demander({
    titre: 'Confirmer la recharge', libelle: 'Crédit affiché par le compteur maintenant (kWh)', type: 'number', requis: true,
    action: async (v) => {
      const rep = await api('POST', '/recharges/' + r.id + '/confirmer', { credit_apres_kwh: v });
      if (rep.ecart_credit_kwh !== null && Math.abs(rep.ecart_credit_kwh) >= 1) {
        alert('Écart constaté : ' + nombre(Math.abs(rep.ecart_credit_kwh)) + ' kWh de ' + (rep.ecart_credit_kwh > 0 ? 'moins' : 'plus') + ' que prévu.');
      }
    },
  });
  const rejeter = (r) => demander({
    titre: 'Signaler une anomalie', libelle: 'Message affiché par le compteur', type: 'text', requis: true,
    action: (v) => api('POST', '/recharges/' + r.id + '/rejeter', { message_compteur: v }),
  });

  // ------------------------------------------------------------ relevés
  $('btn-releve').addEventListener('click', () => { ouvrir('dlg-releve'); $('l-forcer').classList.add('cache'); });
  async function envoyerReleve(forcer) {
    const d = $('l-date').value;
    await api('POST', '/compteurs/' + etat.compteurId + '/releves', {
      type: $('l-type').value, valeur_kwh: $('l-valeur').value,
      releve_le: d ? new Date(d).toISOString() : null, forcer,
    });
    $('dlg-releve').close();
    await charger();
  }
  const formReleve = $('form-releve');
  formReleve.surErreur = (e) => $('l-forcer').classList.toggle('cache', !(e.donnees && e.donnees.peut_forcer));
  brancher(formReleve, () => envoyerReleve(false));
  $('l-forcer').addEventListener('click', async () => {
    try { await envoyerReleve(true); } catch (e) { montrerErreur(formReleve, e.message); }
  });

  demarrer();
})();
</script>
</body>
</html>
