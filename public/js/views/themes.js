import { $, $$, api, esc, plain, renderText, version, versionInfo, store, uid, toast, showError } from '../core.js';
import { go } from '../app.js';

/** Thèmes : #/themes et #/themes/salut */
export async function render(el, { args }) {
  const v = version();
  if (!args[0]) {
    const list = await api('themes');
    el.innerHTML = `<div class="page"><h1>Thèmes bibliques</h1>
      <p class="muted">Les versets clés par sujet — pour un sermon, une étude, un chant ou une conversation.</p>
      <div class="grid">${list.map(t => `<a class="card tile" href="#/themes/${t.id}"><div class="big">${t.icon}</div><h3>${esc(t.title)}</h3><div class="muted small">${t.count} passages</div></a>`).join('')}</div></div>`;
    return;
  }
  const t = await api('theme', { id: args[0], v });
  el.innerHTML = `<div class="page">
    <div class="toolbar"><a class="btn sm" href="#/themes">← Thèmes</a><b class="grow">${t.icon} ${esc(t.title)}</b>
      <button class="btn sm" id="tStudy">+ Créer une étude</button>
      <a class="btn sm" href="#/chant?theme=${t.id}">🎵 Écrire un chant</a>
      <a class="btn sm" href="#/sermon?theme=${t.id}">🎤 Préparer un sermon</a></div>
    ${t.passages.map(p => `<div class="card"><div class="row"><h3 class="grow"><a href="#/passage?ref=${encodeURIComponent(p.ref)}">${esc(p.ref)}</a></h3>
      <a class="small" href="#/comparer?ref=${encodeURIComponent(p.ref)}">Comparer</a></div>
      <div class="scripture">${p.verses.length ? p.verses.map(x => `${p.verses.length > 1 ? `<span class="vn">${x.v}</span>` : ''}${renderText(x.text)}`).join(' ') : `<span class="muted">Absent de ${esc(versionInfo(v).short)}</span>`}</div></div>`).join('')}
    <p class="muted small">Les sélections thématiques sont dans <code>data/themes.json</code> — vous pouvez en ajouter.</p></div>`;
  $('#tStudy', el).onclick = () => {
    const s = store.get();
    const st = { id: uid(), title: t.title, description: `Étude thématique : ${t.title}`, items: t.passages.map(p => ({ id: uid(), ref: p.ref, note: '' })), created: Date.now() };
    s.studies.unshift(st);
    store.save();
    toast('Étude créée');
    go(`#/etude/${st.id}`);
  };
}

/** Passages parallèles : #/paralleles ou #/paralleles?ref=Matthieu 5 */
export async function renderParallels(el, { params }) {
  const ref = params.get('ref') || '';
  const open = params.get('open');
  const v = version();
  const list = await api('parallels', { v, ref });
  el.innerHTML = `<div class="page wide"><h1>Passages parallèles</h1>
    <p class="muted">Les mêmes événements racontés dans plusieurs livres : Évangiles synoptiques, Samuel/Rois/Chroniques, prophéties accomplies.
    ${ref ? `Filtré sur <b>${esc(ref)}</b> — <a href="#/paralleles">tout voir</a>` : ''}</p>
    <div id="pList">${list.length ? list.map((p, i) => `<div class="card">
      <div class="row"><h3 class="grow">${esc(p.title)}</h3><button class="btn sm" data-open="${i}">Afficher côte à côte</button></div>
      <div class="row">${p.passages.map(x => `<a class="chip" href="#/passage?ref=${encodeURIComponent(x.ref)}">${esc(x.ref)}</a>`).join('')}</div>
      <div class="pOut" id="pOut${i}"></div></div>`).join('') : `<div class="empty">Aucun parallèle répertorié pour ce passage. Consultez les références croisées d’un verset (cliquez dessus dans le lecteur).</div>`}</div></div>`;
  const show = async i => {
    const box = $(`#pOut${i}`, el);
    const full = await api('parallels', { v, full: 1, ref }).catch(e => showError(box, e));
    if (!full) return;
    const p = full[i];
    box.innerHTML = `<table class="parallel scripture" style="margin-top:10px"><thead><tr>${p.passages.map(x => `<th>${esc(x.ref)}</th>`).join('')}</tr></thead>
      <tbody><tr>${p.passages.map(x => `<td>${x.verses.map(y => `<span class="vn">${y.c}:${y.v}</span>${esc(plain(y.text))}`).join(' ')}</td>`).join('')}</tr></tbody></table>`;
  };
  $$('[data-open]', el).forEach(b => b.onclick = () => show(+b.dataset.open));
  if (open != null) show(+open);
}
