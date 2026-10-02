import { $, api, esc, plain, version, versionInfo, icon, CAT_LABEL } from '../core.js';

/** Introductions aux livres : #/livres et #/livres/John */
export async function render(el, { args }) {
  if (!args[0]) {
    const list = await api('livres');
    el.innerHTML = `<div class="page">
      <div class="page-head"><div class="grow"><div class="eyebrow">Bible</div><h1>Les livres de la Bible</h1>
        <p>Auteur, date, thème, verset clé et plan de chacun des 66 livres — pour situer un texte avant de le prêcher.</p></div></div>
      ${['AT', 'NT'].map(cat => `<div class="lang-group"><h3>${CAT_LABEL[cat]} <span class="tag">${list.filter(b => b.cat === cat).length}</span></h3>
        <div class="grid">${list.filter(b => b.cat === cat).map(b => `<a class="card tile" href="#/livres/${b.id}"><h3>${esc(b.fr)}</h3><p>${esc(b.theme)}</p></a>`).join('')}</div></div>`).join('')}
    </div>`;
    return;
  }
  const b = await api('livre', { id: args[0], v: version() });
  el.innerHTML = `<div class="page reading">${introHTML(b, true)}</div>`;
}

/** Fiche d'introduction (réutilisée dans le lecteur). */
export function introHTML(b, full = false) {
  const key = b.cleText && b.cleText.verses.length ? b.cleText.verses.map(v => plain(v.text)).join(' ') : '';
  return `<div class="page-head"><div class="grow"><div class="eyebrow"><a href="#/livres">Les livres</a> · ${esc(CAT_LABEL[b.cat])}</div><h1>${esc(b.fr)}</h1><p>${esc(b.resume)}</p></div>
      ${full ? `<a class="btn primary" href="#/lire/${b.id}/1">${icon('book')} Lire ${esc(b.fr)}</a>` : ''}</div>
    <div class="card"><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
      <div><h4>Auteur</h4>${esc(b.auteur)}</div><div><h4>Date</h4>${esc(b.date)}</div><div><h4>Thème</h4>${esc(b.theme)}</div></div></div>
    ${key ? `<div class="card votd"><div class="eyebrow">Verset clé</div><div class="text">${esc(key)}</div>
      <div class="ref"><a href="#/passage?ref=${encodeURIComponent(b.cle)}">${esc(b.cle)}</a> <span class="muted small">· ${esc(versionInfo(version()).short)}</span></div></div>` : ''}
    <div class="card"><h3>Plan du livre</h3><div class="steps">${b.plan.map(([t, r]) => `<div class="step" style="margin-bottom:12px"><b>${esc(t)}</b><br><a href="#/passage?ref=${encodeURIComponent(r)}">${esc(r)}</a></div>`).join('')}</div></div>
    <p class="muted small">Les dates et attributions suivent la tradition ; lorsque les spécialistes en débattent, c’est indiqué.</p>`;
}
