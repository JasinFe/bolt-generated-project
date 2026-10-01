import { $$, api, esc, plain, version, versionInfo } from '../core.js';

/** Évangélisation : plans de présentation, questions fréquentes. */
export async function render(el, { args }) {
  const data = await api('evangelisation', { v: version() });
  const active = args[0] || data.plans[0].id;
  const plan = data.plans.find(p => p.id === active);
  const text = p => p.verses.map(v => plain(v.text)).join(' ') || `<span class="muted">Absent de ${esc(versionInfo(version()).short)}</span>`;
  el.innerHTML = `<div class="page">
    <div class="page-head"><div class="grow"><div class="eyebrow">Atelier</div><h1>Évangélisation</h1><p>Des parcours prêts à l’emploi pour partager l’Évangile, avec le texte des versets dans la version choisie.</p></div><button class="btn" onclick="print()">Imprimer la fiche</button></div>
    <div class="row no-print" style="margin-bottom:14px">${data.plans.map(p => `<a class="chip ${p.id === active ? 'on' : ''}" href="#/evangelisation/${p.id}">${esc(p.title)}</a>`).join('')}</div>
    ${plan ? `<div class="card"><h2>${esc(plan.title)}</h2><p class="muted">${esc(plan.intro)}</p>
      <div class="steps">${plan.steps.map(s => `<div class="step"><h3>${esc(s.title)}</h3>
        <div class="ref-preview"><b><a href="#/passage?ref=${encodeURIComponent(s.ref)}">${esc(s.ref)}</a></b><br>${text(s)}</div>
        <p class="small">${esc(s.point)}</p></div>`).join('')}</div></div>` : ''}
    <h2 style="margin-top:24px">Questions fréquentes &amp; objections</h2>
    ${data.questions.map((q, i) => `<div class="card"><details ${i === 0 ? 'open' : ''}><summary><b>${esc(q.q)}</b></summary>
      ${q.passages.map(p => `<div class="ref-preview"><b><a href="#/passage?ref=${encodeURIComponent(p.ref)}">${esc(p.ref)}</a></b><br>${text(p)}</div>`).join('')}</details></div>`).join('')}
    <p class="muted small">Les plans sont définis dans <code>data/evangelisation.json</code> : votre équipe peut y ajouter ses propres parcours.</p>
  </div>`;
  // Imprimer : ouvrir tous les volets
  window.onbeforeprint = () => $$('details', el).forEach(d => { d.open = true; });
}
