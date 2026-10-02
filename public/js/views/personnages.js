import { $, api, esc, plain, version, versionInfo, icon } from '../core.js';

/** Personnages bibliques : #/personnages et #/personnages/david */
export async function render(el, { args }) {
  if (!args[0]) {
    const list = await api('personnages');
    el.innerHTML = `<div class="page">
      <div class="page-head"><div class="grow"><div class="eyebrow">Bible</div><h1>Personnages bibliques</h1>
        <p>${list.length} hommes et femmes de la Bible : leur histoire, les passages clés et les leçons pour aujourd’hui.</p></div>
        <input type="search" id="pFilter" placeholder="Filtrer…" style="min-width:220px"></div>
      <div class="grid" id="pGrid">${list.map(p => `<a class="card tile" href="#/personnages/${p.id}" data-name="${esc((p.nom + ' ' + p.role).toLowerCase())}">
        <span class="tile-ic" style="font-family:var(--display);font-size:1.3rem;font-weight:700">${esc(p.nom[0])}</span>
        <h3>${esc(p.nom)}</h3><p>${esc(p.role)}<br><span class="small">${esc(p.periode)}</span></p></a>`).join('')}</div></div>`;
    $('#pFilter', el).oninput = e => {
      const q = e.target.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      el.querySelectorAll('#pGrid > a').forEach(a => { a.style.display = a.dataset.name.normalize('NFD').replace(/[̀-ͯ]/g, '').includes(q) ? '' : 'none'; });
    };
    return;
  }
  const p = await api('personnage', { id: args[0], v: version() });
  el.innerHTML = `<div class="page reading">
    <div class="page-head"><div class="grow"><div class="eyebrow"><a href="#/personnages">Personnages</a> · ${esc(p.periode)}</div><h1>${esc(p.nom)}</h1><p>${esc(p.role)}</p></div>
      <a class="btn" href="#/assistant?mode=personnage&ref=${encodeURIComponent(p.refs[0])}">${icon('star')} Approfondir avec l’assistant</a></div>
    <div class="card"><p style="font-size:1.08rem;margin:0">${esc(p.resume)}</p></div>
    <div class="card"><h3>Leçons pour aujourd’hui</h3><ul>${p.lecons.map(l => `<li>${esc(l)}</li>`).join('')}</ul></div>
    <h2 style="margin-top:22px">Passages clés</h2>
    ${p.passages.map(x => `<div class="card"><div class="row"><h3 class="grow"><a href="#/passage?ref=${encodeURIComponent(x.query)}">${esc(x.ref)}</a></h3></div>
      <div class="scripture">${x.verses.map(v => `${x.verses.length > 1 ? `<span class="vn">${v.v}</span>` : ''}${esc(plain(v.text))}`).join(' ')}${x.verses.length >= 8 ? ' <span class="muted">…</span>' : ''}</div></div>`).join('')}
    <p class="muted small">Texte : ${esc(versionInfo(version()).name)}</p></div>`;
}
