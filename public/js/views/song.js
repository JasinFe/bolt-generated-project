import { $, $$, api, esc, store, uid, refText, refPreviewHTML, version, download, toast } from '../core.js';
import { go } from '../app.js';

const SECTIONS = ['Couplet 1', 'Refrain', 'Couplet 2', 'Pont'];

/** Chants & textes : #/chant, #/chant/:id, #/chant?theme=salut */
export async function render(el, { args, params }) {
  const s = store.get();
  if (!args[0] && params.get('theme')) {
    const t = await api('theme', { id: params.get('theme'), v: version() });
    const song = { id: uid(), title: t.title, theme: t.title, refs: t.passages.map(p => p.ref).join(' ; '), lyrics: SECTIONS.map(x => `[${x}]\n`).join('\n'), created: Date.now() };
    s.songs.unshift(song);
    store.save();
    return go(`#/chant/${song.id}`);
  }
  if (args[0]) return renderEditor(el, args[0]);
  const themes = await api('themes');
  el.innerHTML = `<div class="page"><div class="row"><h1 class="grow">Chants &amp; textes inspirés</h1><button class="btn primary" id="newSong">+ Nouveau texte</button></div>
    <p class="muted">Pour les auteurs, compositeurs, slameurs et poètes : partez de l’Écriture, récoltez ses images et ses mots, trouvez des rimes bibliques.</p>
    <div class="card"><h3>Partir d’un thème</h3><div class="row">${themes.map(t => `<a class="chip" href="#/chant?theme=${t.id}">${t.icon} ${esc(t.title)}</a>`).join('')}</div></div>
    <div class="card"><h3>Mes textes</h3>${s.songs.length ? s.songs.map(x => `<div class="list-item"><div class="grow"><a href="#/chant/${x.id}"><b>${esc(x.title || 'Sans titre')}</b></a>
      <div class="muted small">${esc(x.refs || '')}</div></div><button class="btn sm ghost" data-del="${x.id}">🗑</button></div>`).join('') : '<div class="empty">Aucun texte pour l’instant.</div>'}</div></div>`;
  $('#newSong', el).onclick = () => {
    const song = { id: uid(), title: '', theme: '', refs: '', lyrics: SECTIONS.map(x => `[${x}]\n`).join('\n'), created: Date.now() };
    s.songs.unshift(song);
    store.save();
    go(`#/chant/${song.id}`);
  };
  $$('[data-del]', el).forEach(b => b.onclick = () => {
    if (!confirm('Supprimer ce texte ?')) return;
    s.songs = s.songs.filter(x => x.id !== b.dataset.del);
    store.save();
    render(el, { args: [], params });
  });
}

async function renderEditor(el, id) {
  const s = store.get();
  const x = s.songs.find(y => y.id === id);
  if (!x) { el.innerHTML = '<div class="empty">Texte introuvable. <a href="#/chant">Retour</a></div>'; return; }
  el.innerHTML = `<div class="page wide"><div class="toolbar"><a class="btn sm" href="#/chant">← Mes textes</a><span class="grow muted small">Enregistrement automatique</span>
      <button class="btn sm" id="exp">Exporter</button><button class="btn sm" onclick="print()">Imprimer</button></div>
    <div class="two-col">
      <div>
        <div class="card">
          <input type="text" id="title" value="${esc(x.title)}" placeholder="Titre du chant" style="width:100%;font-size:1.4rem;font-family:var(--serif);border:none;background:none;padding:0">
          <textarea id="lyrics" style="min-height:420px;margin-top:10px;font-family:var(--serif);font-size:1.05rem" placeholder="[Couplet 1]&#10;…">${esc(x.lyrics)}</textarea>
          <p class="muted small" id="stats"></p>
        </div>
        <div class="card"><h3>Versets d’inspiration</h3>
          <input type="text" id="refs" value="${esc(x.refs)}" style="width:100%" placeholder="Psaumes 23 ; Ésaïe 40:31 ; Apocalypse 21:4">
          <div id="refsOut"></div></div>
      </div>
      <aside>
        <div class="card"><h3>Palette de mots</h3><p class="muted small">Les mots les plus présents dans vos versets — leurs images et leur vocabulaire.</p><div class="cloud" id="palette"><span class="muted">Ajoutez des versets.</span></div></div>
        <div class="card"><h3>Rimes bibliques</h3>
          <form class="row" id="rForm"><input type="text" id="rWord" class="grow" placeholder="Mot à faire rimer (ex. : gloire)"><button class="btn sm">Chercher</button></form>
          <div class="cloud" id="rhymes" style="margin-top:8px"></div>
          <p class="muted small">Rimes approximatives tirées du vocabulaire de la Bible Darby. Cliquez pour insérer.</p></div>
        <div class="card"><h3>Chercher une image</h3>
          <form class="row" id="fForm"><input type="text" id="fWord" class="grow" placeholder="ex. : aigle, rocher, lumière"><button class="btn sm">Versets</button></form>
          <div id="found" class="small"></div></div>
      </aside>
    </div></div>`;
  const save = () => store.save();
  const lyrics = $('#lyrics', el);
  const stats = () => {
    const lines = lyrics.value.split('\n').filter(l => l.trim() && !/^\[.*\]$/.test(l.trim()));
    $('#stats', el).textContent = `${lines.length} vers · ${lyrics.value.split(/\s+/).filter(Boolean).length} mots`;
  };
  stats();
  $('#title', el).oninput = e => { x.title = e.target.value; save(); };
  lyrics.oninput = () => { x.lyrics = lyrics.value; save(); stats(); };
  const insert = word => {
    const { selectionStart: a, selectionEnd: b, value } = lyrics;
    lyrics.value = value.slice(0, a) + word + value.slice(b);
    lyrics.focus();
    lyrics.selectionStart = lyrics.selectionEnd = a + word.length;
    lyrics.oninput();
  };

  let timer;
  const loadRefs = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      if (!x.refs.trim()) { $('#refsOut', el).innerHTML = ''; return; }
      try {
        $('#refsOut', el).innerHTML = refPreviewHTML(await refText(x.refs));
        const pal = await api('palette', {}, { method: 'POST', body: { refs: x.refs.split(/\s*;\s*/), v: version() } });
        const max = Math.max(1, ...pal.map(p => p.count));
        $('#palette', el).innerHTML = pal.map(p => `<span><a href="#" data-ins="${esc(p.word)}" style="font-size:${(.8 + p.count / max * .8).toFixed(2)}rem">${esc(p.word)}</a></span>`).join(' ');
        $$('[data-ins]', $('#palette', el)).forEach(a => a.onclick = e => { e.preventDefault(); insert(a.dataset.ins); });
      } catch { $('#refsOut', el).innerHTML = '<p class="muted small">Référence non reconnue.</p>'; }
    }, 500);
  };
  $('#refs', el).oninput = e => { x.refs = e.target.value; save(); loadRefs(); };
  loadRefs();

  $('#rForm', el).onsubmit = async e => {
    e.preventDefault();
    const w = $('#rWord', el).value.trim();
    if (!w) return;
    const list = await api('rhymes', { w });
    $('#rhymes', el).innerHTML = list.length ? list.map(r => `<span><a href="#" data-ins="${esc(r.word)}" style="${r.rich ? 'font-weight:700' : ''}">${esc(r.word)}</a></span>`).join(' ') : '<span class="muted">Aucune rime trouvée.</span>';
    $$('[data-ins]', $('#rhymes', el)).forEach(a => a.onclick = ev => { ev.preventDefault(); insert(a.dataset.ins); });
  };
  $('#fForm', el).onsubmit = async e => {
    e.preventDefault();
    const w = $('#fWord', el).value.trim();
    if (!w) return;
    const r = await api('search', { q: w, v: version(), limit: 12 });
    $('#found', el).innerHTML = `<p class="muted">${r.total} verset(s)</p>` + r.results.map(v => `<div class="xref"><a href="#" data-addref="${esc(v.ref)}" title="Ajouter aux versets d’inspiration">＋</a> <b>${esc(v.ref)}</b> ${esc(v.text)}</div>`).join('');
    $$('[data-addref]', $('#found', el)).forEach(a => a.onclick = ev => {
      ev.preventDefault();
      x.refs = [x.refs, a.dataset.addref].filter(Boolean).join(' ; ');
      $('#refs', el).value = x.refs;
      save(); loadRefs(); toast('Verset ajouté');
    });
  };
  $('#exp', el).onclick = async () => {
    const refs = x.refs ? (await refText(x.refs).catch(() => [])).map(p => `- **${p.ref}** — ${p.text}`).join('\n') : '';
    download(`${(x.title || 'chant').replace(/[^\p{L}\p{N}]+/gu, '-')}.md`, `# ${x.title}\n\n${x.lyrics}\n\n## Versets d’inspiration\n\n${refs}\n`);
  };
}
