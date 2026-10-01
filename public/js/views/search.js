import { $, $$, api, esc, highlight, version, versions, store, uid, toast, showError, books, versionOptions, icon } from '../core.js';
import { go } from '../app.js';

const MODES = [
  ['all', 'Tous les mots'], ['any', 'Au moins un mot'], ['phrase', 'Expression exacte'], ['partial', 'Mots partiels'], ['regex', 'Expression régulière'],
];
const SCOPES = [['', 'Toute la Bible'], ['AT', 'Ancien Testament'], ['NT', 'Nouveau Testament'], ['CANON', 'Canon (66 livres)'], ['DC', 'Deutérocanoniques & apocryphes']];

export async function render(el, { params, isCurrent }) {
  const q = params.get('q') || '';
  const v = params.get('v') || version();
  const mode = params.get('mode') || 'all';
  const scope = params.get('scope') || '';
  const book = params.get('books') || '';
  const list = await versions();
  await books();

  el.innerHTML = `<div class="page wide">
    <div class="page-head"><div class="grow"><div class="eyebrow">Concordance</div><h1>Rechercher</h1><p>Dans ${list.length} versions, avec ou sans accents, par expression, préfixe ou numéro Strong.</p></div></div>
    <form class="toolbar" id="sForm">
      <input type="search" id="sQ" class="grow" value="${esc(q)}" placeholder="Mots, « expression », préfixe*, -exclure, ou numéro Strong (G26, H430)">
      <select id="sV">${versionOptions(v, list)}</select>
      <select id="sMode">${MODES.map(([k, l]) => `<option value="${k}" ${k === mode ? 'selected' : ''}>${l}</option>`).join('')}</select>
      <select id="sScope">${SCOPES.map(([k, l]) => `<option value="${k}" ${k === scope ? 'selected' : ''}>${l}</option>`).join('')}</select>
      <button class="btn primary">${icon('search')} Chercher</button>
    </form>
    <div id="sOut">${q ? '<div class="loading">Recherche…</div>' : help()}</div>
  </div>`;
  $('#sForm', el).onsubmit = e => {
    e.preventDefault();
    const p = new URLSearchParams({ q: $('#sQ', el).value.trim(), v: $('#sV', el).value, mode: $('#sMode', el).value });
    if ($('#sScope', el).value) p.set('scope', $('#sScope', el).value);
    go(`#/recherche?${p}`);
  };
  if (!q) return;

  const out = $('#sOut', el);
  let offset = 0;
  let data;
  try {
    data = await api('search', { q, v, mode, scope, books: book, limit: 100 });
  } catch (e) { return showError(out, e); }
  if (!isCurrent()) return;
  const max = Math.max(1, ...data.byBook.map(b => b.count));
  out.innerHTML = `<div class="two-col">
    <div>
      <div class="row"><p class="grow"><b>${data.total.toLocaleString('fr-FR')}</b> verset(s) pour « ${esc(q)} »${book ? ` dans ${esc(data.byBook[0] ? data.byBook[0].name : book)}` : ''}
        ${data.strong ? ` · <a href="#/lexique/${data.strong}">fiche du mot ${data.strong}</a>${data.version !== v ? ` · <span class="muted">numéros Strong lus dans la ${esc(data.version === 'JND' ? 'Darby' : 'KJV')}</span>` : ''}` : ''}</p>
        ${data.total ? '<button class="btn sm" id="sSave">+ Enregistrer comme étude</button>' : ''}</div>
      <div id="sResults"></div>
      <div class="row" style="margin-top:12px"><button class="btn hidden" id="sMore">Plus de résultats</button></div>
    </div>
    <aside class="card"><h4>Répartition par livre</h4>
      ${book ? `<p><a href="#/recherche?${new URLSearchParams({ q, v, mode, scope })}">← Tous les livres</a></p>` : ''}
      <div class="bars">${data.byBook.map(b => `
        <div class="bar ${b.book === book ? 'on' : ''}" data-book="${b.book}" title="${b.count} verset(s)">
          <span>${esc(b.name)}</span><span class="track"><span class="fill" style="width:${b.count / max * 100}%"></span></span><span>${b.count}</span></div>`).join('') || '<span class="muted">—</span>'}</div>
    </aside></div>`;

  const results = $('#sResults', out);
  const append = d => {
    results.insertAdjacentHTML('beforeend', d.results.map(r => {
      const [b, c, n] = r.key.split('.');
      return `<div class="result"><a class="ref" href="#/lire/${b}/${c}?v=${n}">${esc(r.ref)}</a>
        <div class="text">${highlight(r.text, r.hl)}</div></div>`;
    }).join(''));
    offset += d.results.length;
    $('#sMore', out).classList.toggle('hidden', offset >= d.total);
  };
  append(data);
  if (!data.total) results.innerHTML = `<div class="empty">Aucun résultat. Essayez « Mots partiels », une autre version, ou un préfixe comme <b>${esc(q.split(' ')[0])}*</b>.</div>`;
  $('#sMore', out).onclick = async () => append(await api('search', { q, v, mode, scope, books: book, limit: 100, offset }));
  $$('.bar', out).forEach(bar => bar.onclick = () => go(`#/recherche?${new URLSearchParams({ q, v, mode, scope, books: bar.dataset.book })}`));
  const save = $('#sSave', out);
  if (save) save.onclick = async () => {
    const all = await api('search', { q, v, mode, scope, books: book, limit: 500 });
    const s = store.get();
    const st = { id: uid(), title: `Recherche : ${q}`, description: `${all.total} verset(s) — ${v}`, items: all.results.map(r => ({ id: uid(), ref: r.ref, note: '' })), created: Date.now() };
    s.studies.unshift(st);
    store.save();
    toast(`Étude créée (${st.items.length} versets)`);
    go(`#/etude/${st.id}`);
  };
}

function help() {
  return `<div class="card"><h3>Comment chercher</h3>
    <ul>
      <li><b>amour Dieu</b> — versets contenant tous les mots (accents facultatifs : <i>eternel</i> = <i>Éternel</i>)</li>
      <li><b>"vie éternelle"</b> — l’expression exacte</li>
      <li><b>berger*</b> — tous les mots qui commencent par « berger » (bergers, bergerie…)</li>
      <li><b>foi -oeuvres</b> — avec « foi » mais sans « œuvres »</li>
      <li><b>G26</b> ou <b>H2617</b> — tous les versets où apparaît ce mot grec / hébreu (Darby, KJV, Grec)</li>
      <li>Une référence (<b>Jean 3:16</b>, <b>1 Co 13</b>, <b>Rom 8:28; 12:1-2</b>) ouvre directement le passage depuis la barre du haut.</li>
    </ul></div>`;
}
