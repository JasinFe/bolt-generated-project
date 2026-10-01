import { api, esc, plain, version, store, versions } from '../core.js';
import { openLookup } from '../app.js';

const TOOLS = [
  ['#/lire', '📖', 'Lire la Bible', '8 versions, en parallèle, avec titres, notes et numéros Strong.'],
  ['#/recherche', '🔎', 'Rechercher', 'Mots, expressions exactes, préfixes, exclusions, par livre ou testament.'],
  ['#/lexique', 'א', 'Mots originaux', 'Hébreu et grec : sens, origine et traductions de chaque mot.'],
  ['#/themes', '✦', 'Thèmes', 'Salut, foi, guérison, louange… les versets clés par sujet.'],
  ['#/paralleles', '∥', 'Passages parallèles', 'Évangiles synoptiques, Rois/Chroniques, citations de l’AT.'],
  ['#/etude', '✎', 'Mes études', 'Rassemblez des versets, annotez, surlignez, exportez.'],
  ['#/sermon', '🎤', 'Préparer un sermon', 'Plan guidé : texte, points, illustrations, appel.'],
  ['#/chant', '🎵', 'Chants & textes', 'Versets d’inspiration, palette de mots, rimes bibliques.'],
  ['#/evangelisation', '🌍', 'Évangélisation', 'Plans de présentation de l’Évangile et réponses aux objections.'],
];

export async function render(el) {
  const s = store.get();
  el.innerHTML = `<div class="page">
    <div class="hero">
      <h1>Mister Preacher</h1>
      <p class="muted">L’étude biblique pour prédicateurs, pasteurs, prophètes, lecteurs et artistes.</p>
      <form class="global-big" id="homeSearch">
        <input type="search" id="homeQ" placeholder="Ex. : Jean 3:16 · Ps 23 · « vie éternelle » · grâce* · G26" autofocus>
        <button class="btn primary">Chercher</button>
      </form>
    </div>
    <div class="card votd" id="votd"><div class="loading">Verset du jour…</div></div>
    ${s.history.length ? `<h2 style="margin-top:24px">Reprendre la lecture</h2><div class="row">${s.history.slice(0, 8).map(h => `<a class="chip" href="${esc(h.href)}">${esc(h.label)}</a>`).join('')}</div>` : ''}
    <h2 style="margin-top:24px">Outils</h2>
    <div class="grid">${TOOLS.map(([href, ico, title, desc]) => `
      <a class="card tile" href="${href}"><div class="big">${ico}</div><h3>${title}</h3><div class="muted small">${desc}</div></a>`).join('')}</div>
    <p class="muted small" style="margin-top:24px" id="stats"></p>
  </div>`;
  el.querySelector('#homeSearch').onsubmit = e => { e.preventDefault(); const q = el.querySelector('#homeQ').value.trim(); if (q) openLookup(q); };
  api('votd', { v: version() }).then(d => {
    el.querySelector('#votd').innerHTML = `<div class="muted small">Verset du jour</div>
      <div>«&nbsp;${esc(d.verses.map(v => plain(v.text)).join(' '))}&nbsp;»</div>
      <div class="ref"><a href="#/passage?ref=${encodeURIComponent(d.ref)}">${esc(d.ref)}</a></div>`;
  }).catch(() => { el.querySelector('#votd').remove(); });
  const list = await versions();
  el.querySelector('#stats').textContent = `${list.length} versions disponibles : ${list.map(v => v.short).join(', ')} · ` +
    `${list.reduce((n, v) => n + v.stats.verses, 0).toLocaleString('fr-FR')} versets · 340 000 références croisées · 14 000 mots originaux.`;
}
