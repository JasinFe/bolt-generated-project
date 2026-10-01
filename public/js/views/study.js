import { $, $$, esc, store, uid, toast, refText, version, versionInfo, download, keyLabel, books, plain, api } from '../core.js';
import { go } from '../app.js';

/** Mes études : #/etude, #/etude/:id, #/etude/surlignages, #/etude/notes */
export async function render(el, { args }) {
  await books();
  const s = store.get();
  const id = args[0];
  if (id === 'surlignages' || id === 'notes') return renderMarks(el, id);
  if (id) return renderStudy(el, id);

  el.innerHTML = `<div class="page">
    <div class="row"><h1 class="grow">Mes études</h1>
      <button class="btn primary" id="newStudy">+ Nouvelle étude</button></div>
    <div class="row" style="margin-bottom:14px">
      <a class="chip" href="#/etude/surlignages">🖍 Mes surlignages (${Object.keys(s.highlights).length})</a>
      <a class="chip" href="#/etude/notes">✎ Mes notes (${Object.keys(s.notes).length})</a>
      <span class="grow"></span>
      <button class="btn sm" id="backup">Sauvegarder tout (.json)</button>
      <label class="btn sm">Restaurer<input type="file" id="restore" accept=".json" hidden></label>
    </div>
    <div class="card">${s.studies.length ? s.studies.map(st => `
      <div class="list-item"><div class="grow"><a href="#/etude/${st.id}"><b>${esc(st.title)}</b></a>
        <div class="muted small">${st.items.length} passage(s) · ${new Date(st.created).toLocaleDateString('fr-FR')}${st.description ? ' · ' + esc(st.description) : ''}</div></div>
        <button class="btn sm ghost" data-del="${st.id}" title="Supprimer">🗑</button></div>`).join('')
      : `<div class="empty">Aucune étude pour l’instant.<br>Cliquez sur un verset dans le lecteur, puis « Ajouter à une étude », ou créez-en une ici.</div>`}</div>
    <p class="muted small">Vos études, notes et surlignages sont enregistrés dans ce navigateur. Utilisez « Sauvegarder tout » pour les conserver ou les transférer.</p>
  </div>`;
  $('#newStudy', el).onclick = () => {
    const title = prompt('Titre de l’étude :');
    if (!title) return;
    const st = { id: uid(), title, description: '', items: [], created: Date.now() };
    s.studies.unshift(st);
    store.save();
    go(`#/etude/${st.id}`);
  };
  $$('[data-del]', el).forEach(b => b.onclick = () => {
    if (!confirm('Supprimer cette étude ?')) return;
    s.studies = s.studies.filter(x => x.id !== b.dataset.del);
    store.save();
    render(el, { args: [] });
  });
  $('#backup', el).onclick = () => download(`mister-preacher-sauvegarde-${new Date().toISOString().slice(0, 10)}.json`, store.export(), 'application/json');
  $('#restore', el).onchange = async e => {
    const f = e.target.files[0];
    if (!f || !confirm('Remplacer toutes vos données par cette sauvegarde ?')) return;
    try { store.import(await f.text()); toast('Sauvegarde restaurée'); render(el, { args: [] }); } catch { toast('Fichier invalide'); }
  };
}

async function renderStudy(el, id) {
  const s = store.get();
  const st = s.studies.find(x => x.id === id);
  if (!st) { el.innerHTML = '<div class="empty">Étude introuvable. <a href="#/etude">Retour</a></div>'; return; }
  const v = version();
  el.innerHTML = `<div class="page">
    <div class="toolbar"><a class="btn sm" href="#/etude">← Mes études</a><span class="grow"></span>
      <button class="btn sm" id="exportMd">Exporter (Markdown)</button><button class="btn sm" onclick="print()">Imprimer</button>
      <button class="btn sm" id="toSermon">→ En faire un sermon</button></div>
    <div class="card">
      <input type="text" id="stTitle" value="${esc(st.title)}" style="width:100%;font-size:1.4rem;font-family:var(--serif);border:none;padding:0;background:none" aria-label="Titre">
      <textarea id="stDesc" placeholder="Objectif de l’étude, contexte, question de départ…" style="margin-top:8px;min-height:60px">${esc(st.description || '')}</textarea>
    </div>
    <form class="toolbar no-print" id="addRef" style="margin-top:14px;position:static">
      <input type="text" id="newRef" class="grow" placeholder="Ajouter un passage : Jean 15:1-8 ; Ps 1 ; Rom 8:28">
      <button class="btn primary">Ajouter</button></form>
    <div id="items"></div>
    <p class="muted small">Texte affiché : ${esc(versionInfo(v).name)} (changez la version en haut à droite).</p>
  </div>`;
  const save = () => store.save();
  $('#stTitle', el).onchange = e => { st.title = e.target.value.trim() || st.title; save(); };
  $('#stDesc', el).onchange = e => { st.description = e.target.value; save(); };
  $('#addRef', el).onsubmit = async e => {
    e.preventDefault();
    const q = $('#newRef', el).value.trim();
    if (!q) return;
    try {
      const refs = await api('parse', { ref: q });
      if (!refs.length) return toast('Référence non reconnue');
      refs.forEach(r => st.items.push({ id: uid(), ref: r.label, note: '' }));
      save();
      $('#newRef', el).value = '';
      drawItems();
    } catch (err) { toast(err.message); }
  };

  async function drawItems() {
    const box = $('#items', el);
    if (!st.items.length) { box.innerHTML = '<div class="empty">Ajoutez des passages ci-dessus ou depuis le lecteur.</div>'; return; }
    const texts = await Promise.all(st.items.map(it => refText(it.ref, v).catch(() => [{ ref: it.ref, text: '' }])));
    box.innerHTML = st.items.map((it, i) => `<div class="card" data-id="${it.id}">
      <div class="row"><b class="grow"><a href="#/passage?ref=${encodeURIComponent(it.ref)}">${i + 1}. ${esc(it.ref)}</a></b>
        <span class="no-print"><button class="btn sm ghost" data-up="${i}" title="Monter">↑</button><button class="btn sm ghost" data-down="${i}" title="Descendre">↓</button>
        <button class="btn sm ghost" data-rm="${i}" title="Retirer">✕</button></span></div>
      <div class="scripture">${esc(texts[i].map(t => t.text).join(' ')) || '<span class="muted">Absent de cette version</span>'}</div>
      <textarea data-note="${i}" placeholder="Observation · Interprétation · Application" style="margin-top:8px;min-height:60px">${esc(it.note || '')}</textarea></div>`).join('');
    $$('[data-note]', box).forEach(t => t.onchange = () => { st.items[+t.dataset.note].note = t.value; save(); });
    const move = (i, d) => { const j = i + d; if (j < 0 || j >= st.items.length) return; [st.items[i], st.items[j]] = [st.items[j], st.items[i]]; save(); drawItems(); };
    $$('[data-up]', box).forEach(b => b.onclick = () => move(+b.dataset.up, -1));
    $$('[data-down]', box).forEach(b => b.onclick = () => move(+b.dataset.down, 1));
    $$('[data-rm]', box).forEach(b => b.onclick = () => { st.items.splice(+b.dataset.rm, 1); save(); drawItems(); });
  }
  drawItems();

  $('#exportMd', el).onclick = async () => {
    const parts = [`# ${st.title}`, st.description ? `\n${st.description}\n` : ''];
    for (const it of st.items) {
      const t = await refText(it.ref, v).catch(() => []);
      parts.push(`\n## ${it.ref}\n\n> ${t.map(x => x.text).join(' ')} (${versionInfo(v).short})\n`);
      if (it.note) parts.push(`\n${it.note}\n`);
    }
    parts.push(`\n---\n_Préparé avec Mister Preacher_`);
    download(`${st.title.replace(/[^\p{L}\p{N}]+/gu, '-')}.md`, parts.join(''));
  };
  $('#toSermon', el).onclick = () => {
    const sermon = {
      id: uid(), title: st.title, text: st.items[0] ? st.items[0].ref : '', template: 'thematique', intro: st.description || '',
      points: st.items.slice(1, 4).map(it => ({ title: '', refs: it.ref, body: it.note || '' })), illustration: '', application: '', conclusion: '', created: Date.now(),
    };
    s.sermons.unshift(sermon);
    store.save();
    go(`#/sermon/${sermon.id}`);
  };
}

function renderMarks(el, kind) {
  const s = store.get();
  const keys = Object.keys(kind === 'notes' ? s.notes : s.highlights);
  keys.sort();
  el.innerHTML = `<div class="page"><div class="toolbar"><a class="btn sm" href="#/etude">← Mes études</a>
    <b class="grow">${kind === 'notes' ? 'Mes notes' : 'Mes surlignages'} (${keys.length})</b>
    ${kind === 'highlights' || kind === 'surlignages' ? `<span class="row">${['yellow', 'green', 'blue', 'pink', 'purple'].map(c => `<span class="color ${c}" data-f="${c}" style="width:22px;height:22px;display:inline-block;cursor:pointer"></span>`).join('')}<span class="chip" data-f="">Tous</span></span>` : ''}
    <button class="btn sm" id="mdExport">Exporter</button></div>
    <div class="card" id="marks">${keys.length ? '<div class="loading">Chargement…</div>' : '<div class="empty">Rien pour l’instant. Cliquez sur un verset dans le lecteur pour le surligner ou l’annoter.</div>'}</div></div>`;
  if (!keys.length) return;
  const v = version();
  Promise.all(keys.map(k => api('passage', { v, ref: keyLabel(k) }).then(d => [k, d.passages[0].verses[0]]).catch(() => [k, null]))).then(rows => {
    const draw = filter => {
      $('#marks', el).innerHTML = rows.filter(([k]) => !filter || s.highlights[k] === filter).map(([k, x]) => {
        const [b, c, n] = k.split('.');
        return `<div class="list-item"><div class="grow"><a href="#/lire/${b}/${c}?v=${n}"><b>${esc(keyLabel(k))}</b></a>
          <div class="scripture verse ${s.highlights[k] ? 'hl-' + s.highlights[k] : ''}">${esc(x ? plain(x.text) : '')}</div>
          ${s.notes[k] ? `<div class="ref-preview">${esc(s.notes[k])}</div>` : ''}</div></div>`;
      }).join('') || '<div class="empty">Aucun verset de cette couleur.</div>';
    };
    draw('');
    $$('[data-f]', el).forEach(f => f.onclick = () => draw(f.dataset.f));
    $('#mdExport', el).onclick = () => download(`mister-preacher-${kind}.md`, rows.map(([k, x]) =>
      `- **${keyLabel(k)}** — ${x ? plain(x.text) : ''}${s.notes[k] ? `\n  - _Note :_ ${s.notes[k]}` : ''}`).join('\n'));
  });
}
