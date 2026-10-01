import { api, esc, plain, version, versionInfo, store, versions, icon, versionsByLang } from '../core.js';
import { openLookup } from '../app.js';

const TOOLS = [
  ['#/lire', 'book', 'Lire la Bible', '31 versions en 16 langues, jusqu’à 4 en parallèle, avec titres, notes et mots originaux.'],
  ['#/recherche', 'search', 'Rechercher', 'Mots, expressions exactes, préfixes, exclusions — par livre ou par testament.'],
  ['#/lexique', 'alef', 'Mots originaux', 'Hébreu et grec : sens, origine et toutes les traductions de chaque mot.'],
  ['#/comparer', 'compare', 'Comparer', 'Un passage dans toutes les versions et toutes les langues, côte à côte.'],
  ['#/themes', 'star', 'Thèmes', 'Salut, foi, guérison, louange… les versets clés classés par sujet.'],
  ['#/paralleles', 'columns', 'Passages parallèles', 'Évangiles synoptiques, Rois et Chroniques, prophéties accomplies.'],
  ['#/sermon', 'mic', 'Préparer un sermon', 'Cinq modèles de plan, versets insérés automatiquement, export et impression.'],
  ['#/chant', 'music', 'Chants & textes', 'Versets d’inspiration, palette de mots bibliques et dictionnaire de rimes.'],
  ['#/evangelisation', 'globe', 'Évangélisation', 'Parcours prêts à l’emploi et réponses bibliques aux objections.'],
];

const QUICK = ['Jean 3:16', 'Psaumes 23', 'Romains 8', '1 Corinthiens 13', 'Ésaïe 53', '« Je suis »', 'grâce*'];

export async function render(el) {
  const s = store.get();
  const list = await versions();
  const langs = versionsByLang(list).length;
  el.innerHTML = `<div class="page">
    <section class="hero">
      <div class="eyebrow">Étude biblique · Prédication · Création</div>
      <h1>Mister Preacher</h1>
      <p class="lead">Sondez les Écritures, reliez les passages, remontez aux mots originaux — puis transformez votre étude en sermon, en chant ou en message d’évangélisation.</p>
      <form class="hero-search" id="homeSearch">
        <input type="search" id="homeQ" placeholder="Une référence (Jean 3:16) ou des mots (« vie éternelle »)" aria-label="Rechercher" autofocus>
        <button class="btn gold">${icon('search')} Chercher</button>
      </form>
      <div class="quick">${QUICK.map(q => `<a href="#" data-q="${esc(q.replace(/[«»]/g, '"'))}">${esc(q)}</a>`).join('')}</div>
    </section>

    <div class="stats">
      <div class="stat"><b>${list.length}</b><span>versions de la Bible</span></div>
      <div class="stat"><b>${langs}</b><span>langues, dont l’hébreu et le grec</span></div>
      <div class="stat"><b>341 385</b><span>références croisées</span></div>
      <div class="stat"><b>14 197</b><span>mots originaux expliqués</span></div>
    </div>

    <div class="card votd" id="votd"><div class="loading">Verset du jour…</div></div>

    ${s.history.length ? `<div class="section-head"><h2>Reprendre la lecture</h2></div>
      <div class="row">${s.history.slice(0, 8).map(h => `<a class="chip" href="${esc(h.href)}">${icon('book')} ${esc(h.label)}</a>`).join('')}</div>` : ''}

    <div class="section-head"><h2>Vos outils</h2><span class="muted small">pour prédicateurs, pasteurs, prophètes, lecteurs et artistes</span></div>
    <div class="grid">${TOOLS.map(([href, ic, title, desc]) => `
      <a class="card tile" href="${href}"><span class="tile-ic">${icon(ic)}</span><h3>${title}</h3><p>${desc}</p></a>`).join('')}</div>
  </div>`;
  const search = q => { if (q) openLookup(q); };
  el.querySelector('#homeSearch').onsubmit = e => { e.preventDefault(); search(el.querySelector('#homeQ').value.trim()); };
  el.querySelectorAll('[data-q]').forEach(a => a.onclick = e => { e.preventDefault(); search(a.dataset.q); });
  api('votd', { v: version() }).then(d => {
    el.querySelector('#votd').innerHTML = `<div class="eyebrow">Verset du jour</div>
      <div class="text">${esc(d.verses.map(v => plain(v.text)).join(' '))}</div>
      <div class="ref"><a href="#/passage?ref=${encodeURIComponent(d.ref)}">${esc(d.ref)}</a> <span class="muted small">· ${esc(versionInfo(version()).short)}</span></div>`;
  }).catch(() => { el.querySelector('#votd').remove(); });
}
