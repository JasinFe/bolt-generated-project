import { $, api, esc, strongHTML, showError } from '../core.js';
import { go } from '../app.js';

/** Mots originaux : #/lexique/G26 ou #/lexique?w=amour */
export async function render(el, { args, params }) {
  const num = (args[0] || '').toUpperCase();
  const word = params.get('w') || '';
  el.innerHTML = `<div class="page">
    <h1>Mots originaux (hébreu &amp; grec)</h1>
    <form class="toolbar" id="lForm">
      <input type="search" id="lQ" class="grow" value="${esc(num || word)}" placeholder="Mot français (amour, grâce, foi), mot original (ἀγάπη), translittération (agapē) ou numéro (G26, H2617)">
      <button class="btn primary">Chercher</button>
    </form>
    <div id="lOut"></div></div>`;
  $('#lForm', el).onsubmit = e => {
    e.preventDefault();
    const q = $('#lQ', el).value.trim();
    if (/^[HG]\d+[a-z]?$/i.test(q)) go(`#/lexique/${q.toUpperCase()}`);
    else if (q) go(`#/lexique?w=${encodeURIComponent(q)}`);
  };
  const out = $('#lOut', el);
  if (num) {
    try {
      const e = await api('strong', { n: num });
      out.innerHTML = `<div class="card">${strongHTML(e)}</div>
        <p class="muted small">Définitions : Strong’s Exhaustive Concordance (1890, en anglais). Traductions : décomptées automatiquement dans la Darby française et la KJV.</p>`;
    } catch (e) { showError(out, e); }
    return;
  }
  if (word) {
    try {
      const list = await api('strong/lookup', { w: word, v: 'JND' });
      out.innerHTML = list.length ? `<p>Mots originaux traduits par « <b>${esc(word)}</b> » :</p>` + list.map(x => `
        <a class="card tile" href="#/lexique/${x.num}" style="display:block">
          <div class="row"><span class="lemma" style="font-size:1.6rem">${esc(x.lemma)}</span><b>${esc(x.translit)}</b><span class="tag">${x.num}</span>
          <span class="grow"></span><span class="muted small">${x.score >= 10000 ? 'mot original' : x.score > 1 ? `traduit « ${esc(x.via)} » ${x.score}×` : esc(x.via)}</span></div>
          <div class="muted small">${esc(x.def)}</div></a>`).join('')
        : `<div class="empty">Aucun mot original trouvé pour « ${esc(word)} ». Essayez la forme exacte utilisée dans la Darby (ex. : « aimé », « grâce »).</div>`;
    } catch (e) { showError(out, e); }
    return;
  }
  out.innerHTML = `<div class="card"><h3>Explorer le sens des mots</h3>
    <p>Chaque mot de la Bible Darby, de la KJV et du Nouveau Testament grec est relié à son mot original (numérotation Strong).
    Dans le lecteur, activez <b>Strong</b> puis cliquez sur un mot pour voir son origine, sa définition et toutes ses traductions.</p>
    <div class="row">${['amour', 'grâce', 'foi', 'paix', 'gloire', 'esprit', 'saint', 'repentance', 'onction', 'louange'].map(w => `<a class="chip" href="#/lexique?w=${w}">${w}</a>`).join('')}</div>
    <h3 style="margin-top:16px">Mots célèbres</h3>
    <div class="row">${[['G26', 'agapē — amour'], ['G5485', 'charis — grâce'], ['G4102', 'pistis — foi'], ['H2617', 'hésed — bonté'], ['H7965', 'shalom — paix'], ['G1515', 'eirēnē — paix'],
      ['H3068', 'YHWH — l’Éternel'], ['G3056', 'logos — parole'], ['G4487', 'rhēma — parole'], ['H7307', 'rouah — esprit'], ['G4151', 'pneuma — esprit'], ['G3341', 'metanoia — repentance']]
      .map(([n, l]) => `<a class="chip" href="#/lexique/${n}">${l}</a>`).join('')}</div></div>`;

}
