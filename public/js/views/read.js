import {
  $, $$, api, esc, plain, renderText, version, versionInfo, versions, books, store, openVerseTools,
  CAT_LABEL, remember, showError, toast, uid,
} from '../core.js';
import { go } from '../app.js';

function markClasses(key) {
  const s = store.get();
  return `${s.highlights[key] ? 'hl-' + s.highlights[key] : ''} ${s.notes[key] ? 'has-note' : ''}`;
}

function refreshMarks(root) {
  $$('.verse', root).forEach(el => {
    el.className = `verse ${markClasses(el.dataset.key)} ${el.classList.contains('selected') ? 'selected' : ''}`;
  });
}

function bindVerses(root, rawByKey) {
  root.addEventListener('click', e => {
    if (e.target.closest('.strong-on .w') || e.target.closest('a')) return;
    const el = e.target.closest('.verse');
    if (!el) return;
    $$('.verse.selected', root).forEach(v => v.classList.remove('selected'));
    $$(`.verse[data-key="${el.dataset.key}"]`, root).forEach(v => v.classList.add('selected'));
    openVerseTools(el.dataset.key, rawByKey.get(el.dataset.key), () => refreshMarks(root));
  });
}

function verseSpan(v, info, strong) {
  const notes = v.notes ? `<span class="fn" title="${esc(v.notes.join(' · '))}">*</span>` : '';
  return `<span class="verse ${markClasses(v.key)}" data-key="${v.key}"><span class="vn">${v.v}</span>${renderText(v.text, { strong: strong && info.strong })}${notes}</span> `;
}

/** Lecteur de chapitre : #/lire/John/3?v=16 */
export async function render(el, { args, params }) {
  const allBooks = await books();
  await versions();
  const last = store.setting('lastRead') || ['John', 1];
  const book = args[0] || last[0];
  const chapter = +(args[1] || (args[0] ? 1 : last[1]));
  const main = version();
  const parallel = (store.setting('parallel') || []).filter(p => p !== main);
  const strong = store.setting('strong') !== false;
  const bookMeta = allBooks.find(b => b.id === book);
  if (!bookMeta) throw new Error(`Livre inconnu : ${book}`);

  // Si la version principale n'a pas ce livre (deutérocanoniques, apocryphes), on bascule sur une version qui l'a.
  let fallback = null;
  const has = async id => (await api('books', { v: id })).some(b => b.id === book);
  if (!(await has(main))) {
    for (const v of await versions()) if (await has(v.id)) { fallback = v.id; break; }
  }
  const ids = [fallback || main, ...parallel.filter(p => p !== fallback)];
  const chapters = await Promise.all(ids.map(id => api('chapter', { v: id, b: book, c: chapter }).catch(() => null)));
  const primary = chapters.find(Boolean);
  const maxCh = Math.max(bookMeta.chapters, ...chapters.filter(Boolean).map(c => c.chapters));
  store.setting('lastRead', [book, chapter]);
  remember({ href: `#/lire/${book}/${chapter}`, label: `${bookMeta.fr} ${chapter}` });

  const all = await versions();
  const groups = ['AT', 'DC', 'AP', 'NT'].map(cat => `<optgroup label="${CAT_LABEL[cat]}">${
    allBooks.filter(b => b.cat === cat).map(b => `<option value="${b.id}" ${b.id === book ? 'selected' : ''}>${esc(b.fr)}</option>`).join('')}</optgroup>`).join('');

  el.innerHTML = `<div class="page ${parallel.length ? 'wide' : ''}">
    <div class="toolbar">
      <select id="rBook" aria-label="Livre">${groups}</select>
      <select id="rChap" aria-label="Chapitre">${Array.from({ length: maxCh }, (_, i) => `<option ${i + 1 === chapter ? 'selected' : ''}>${i + 1}</option>`).join('')}</select>
      <button class="btn sm" id="rPrev" title="Chapitre précédent">◀</button>
      <button class="btn sm" id="rNext" title="Chapitre suivant">▶</button>
      <span class="grow"></span>
      <span class="small muted">En parallèle :</span>
      ${all.filter(v => v.id !== main).map(v => `<span class="chip ${parallel.includes(v.id) ? 'on' : ''}" data-par="${v.id}" title="${esc(v.name)}">${esc(v.short)}</span>`).join('')}
      <span class="chip ${strong ? 'on' : ''}" id="rStrong" title="Cliquer sur les mots pour voir l’hébreu / le grec">Strong</span>
      <button class="btn sm" id="rSmaller" title="Texte plus petit">A−</button><button class="btn sm" id="rBigger" title="Texte plus grand">A+</button>
    </div>
    <div class="card" id="rBody"></div>
    <div class="nav-chapter no-print">
      <a class="btn" id="rPrev2" href="#">◀ Précédent</a>
      <a class="btn" href="#/comparer?ref=${encodeURIComponent(bookMeta.fr + ' ' + chapter)}">⇄ Comparer ce chapitre</a>
      <a class="btn" id="rNext2" href="#">Suivant ▶</a>
    </div>
  </div>`;

  const body = $('#rBody', el);
  const rawByKey = new Map();
  if (!primary) {
    body.innerHTML = `<p class="muted">Ce chapitre n’existe pas dans les versions choisies. Essayez une autre version (Crampon, Septante, KJV ou Vulgate pour les deutérocanoniques et apocryphes).</p>`;
  } else {
    const head = `<h1 class="chapter-title">${esc(bookMeta.fr)} ${chapter}</h1>`;
    const notice = fallback ? `<p class="muted small" style="text-align:center">${esc(bookMeta.fr)} ne fait pas partie de ${esc(versionInfo(main).name)} — texte affiché : <b>${esc(versionInfo(fallback).name)}</b>.</p>` : '';
    if (ids.length === 1) {
      const info = versionInfo(ids[0]);
      const ch = chapters[0];
      if (!ch) {
        body.innerHTML = head + `<p class="muted">${esc(bookMeta.fr)} ${chapter} n’existe pas dans ${esc(info.name)}.</p>`;
      } else {
        ch.verses.forEach(v => rawByKey.set(v.key, v.text));
        body.innerHTML = head + notice + `<div class="scripture ${strong ? 'strong-on' : ''} ${info.dir === 'rtl' ? 'rtl' : ''} lang-${info.lang}" lang="${info.lang}">${
          ch.verses.map(v => (v.title ? `<div class="section-title">${esc(v.title)}</div>` : '') + verseSpan(v, info, strong)).join('')}</div>`;
      }
    } else {
      const maxV = Math.max(...chapters.filter(Boolean).map(c => Math.max(...c.verses.map(v => v.v))));
      const maps = chapters.map(c => new Map((c ? c.verses : []).map(v => [v.v, v])));
      chapters.forEach(c => c && c.verses.forEach(v => { if (!rawByKey.has(v.key)) rawByKey.set(v.key, v.text); }));
      const infos = ids.map(versionInfo);
      body.innerHTML = head + notice + `<table class="parallel scripture ${strong ? 'strong-on' : ''}"><thead><tr>${infos.map(i => `<th>${esc(i.short)}</th>`).join('')}</tr></thead><tbody>${
        Array.from({ length: maxV }, (_, i) => i + 1).map(n => `<tr>${maps.map((m, j) => {
          const v = m.get(n);
          const info = infos[j];
          return `<td class="${info.dir === 'rtl' ? 'rtl' : ''} lang-${info.lang}" lang="${info.lang}">${v ? (v.title ? `<div class="section-title">${esc(v.title)}</div>` : '') + verseSpan(v, info, strong) : ''}</td>`;
        }).join('')}</tr>`).join('')}</tbody></table>`;
    }
  }
  bindVerses(body, rawByKey);

  const target = params.get('v');
  if (target) {
    const [a, b] = target.split('-').map(Number);
    for (let n = a; n <= (b || a); n++) $$(`.verse[data-key="${book}.${chapter}.${n}"]`, body).forEach(x => x.classList.add('selected'));
    const first = $(`.verse[data-key="${book}.${chapter}.${a}"]`, body);
    if (first) setTimeout(() => first.scrollIntoView({ block: 'center' }), 50);
  }

  const idx = allBooks.findIndex(b => b.id === book);
  const prev = chapter > 1 ? [book, chapter - 1] : idx > 0 ? [allBooks[idx - 1].id, allBooks[idx - 1].chapters] : null;
  const next = chapter < maxCh ? [book, chapter + 1] : idx < allBooks.length - 1 ? [allBooks[idx + 1].id, 1] : null;
  const goTo = p => p && go(`#/lire/${p[0]}/${p[1]}`);
  $('#rPrev', el).onclick = () => goTo(prev);
  $('#rNext', el).onclick = () => goTo(next);
  $('#rPrev2', el).onclick = e => { e.preventDefault(); goTo(prev); };
  $('#rNext2', el).onclick = e => { e.preventDefault(); goTo(next); };
  $('#rBook', el).onchange = e => go(`#/lire/${e.target.value}/1`);
  $('#rChap', el).onchange = e => go(`#/lire/${book}/${e.target.value}`);
  $$('[data-par]', el).forEach(c => c.onclick = () => {
    const set = new Set(parallel);
    if (set.has(c.dataset.par)) set.delete(c.dataset.par);
    else { if (set.size >= 3) return toast('3 versions en parallèle au maximum'); set.add(c.dataset.par); }
    store.setting('parallel', [...set]);
    go(location.hash);
  });
  $('#rStrong', el).onclick = () => { store.setting('strong', !strong); go(location.hash); };
  const resize = d => {
    const cur = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--scripture-size')) || 1.12;
    const size = Math.min(2, Math.max(.85, cur + d));
    document.documentElement.style.setProperty('--scripture-size', size + 'rem');
    store.setting('fontSize', size);
  };
  $('#rSmaller', el).onclick = () => resize(-.08);
  $('#rBigger', el).onclick = () => resize(.08);

  // Flèches clavier pour changer de chapitre
  document.onkeydown = e => {
    if (!location.hash.startsWith('#/lire') || /input|select|textarea/i.test(document.activeElement.tagName)) return;
    if (e.key === 'ArrowLeft') goTo(prev);
    if (e.key === 'ArrowRight') goTo(next);
  };
}

/** Un ou plusieurs passages : #/passage?ref=Rom 8:28; 12:1-2 */
export async function renderPassage(el, { params }) {
  const ref = params.get('ref') || '';
  const v = version();
  const info = versionInfo(v);
  const strong = store.setting('strong') !== false;
  await books();
  let data;
  try { data = await api('passage', { v, ref }); } catch (e) { return showError(el, e); }
  const rawByKey = new Map();
  remember({ href: location.hash, label: data.passages.map(p => p.ref).join(' ; ') });
  el.innerHTML = `<div class="page">
    <div class="toolbar">
      <b class="grow">${esc(data.passages.map(p => p.ref).join(' ; '))}</b>
      <a class="btn sm" href="#/comparer?ref=${encodeURIComponent(ref)}">⇄ Comparer</a>
      <button class="btn sm" id="pStudy">+ Ajouter à une étude</button>
      <button class="btn sm" id="pCopy">Copier</button>
    </div>
    ${data.passages.map(p => {
      p.verses.forEach(x => rawByKey.set(x.key, x.text));
      return `<div class="card">
        <div class="row"><h2 class="grow">${esc(p.ref)}</h2><a class="btn sm" href="#/lire/${p.book}/${p.chapter}?v=${p.verses[0] ? p.verses[0].v : ''}">Lire le contexte →</a></div>
        <div class="scripture ${strong ? 'strong-on' : ''} ${info.dir === 'rtl' ? 'rtl' : ''}">${p.verses.length
          ? p.verses.map(x => verseSpan({ ...x, notes: null }, info, strong)).join('')
          : `<span class="muted">Ce passage n’existe pas dans ${esc(info.name)}.</span>`}</div></div>`;
    }).join('')}
    <p class="muted small">Cliquez sur un verset pour le surligner, l’annoter, voir ses références croisées et ses mots originaux.</p>
  </div>`;
  $$('.card .scripture', el).forEach(s => bindVerses(s, rawByKey));
  $('#pCopy', el).onclick = () => navigator.clipboard.writeText(
    data.passages.map(p => `${p.ref} (${info.short})\n${p.verses.map(x => `${x.v} ${plain(x.text)}`).join('\n')}`).join('\n\n'),
  ).then(() => toast('Passage copié'));
  $('#pStudy', el).onclick = () => {
    const s = store.get();
    const titles = s.studies.map((st, i) => `${i + 1}. ${st.title}`).join('\n');
    const answer = prompt(`Numéro de l’étude, ou titre d’une nouvelle étude :\n${titles}`, s.studies.length ? '1' : 'Nouvelle étude');
    if (!answer) return;
    let st = s.studies[+answer - 1];
    if (!st) { st = { id: uid(), title: answer, description: '', items: [], created: Date.now() }; s.studies.unshift(st); }
    for (const p of data.passages) st.items.push({ id: uid(), ref: p.ref, note: '' });
    store.save();
    toast(`Ajouté à « ${st.title} »`);
  };
}

/** Comparaison d'un passage dans toutes les versions : #/comparer?ref=Jean 3:16 */
export async function renderCompare(el, { params }) {
  const ref = params.get('ref') || '';
  el.innerHTML = `<div class="page wide">
    <h1>Comparer les versions</h1>
    <form class="toolbar" id="cForm"><input type="text" id="cRef" class="grow" value="${esc(ref)}" placeholder="Ex. : Jean 3:16 ou Psaumes 23"><button class="btn primary">Comparer</button></form>
    <div id="cOut">${ref ? '<div class="loading">Chargement…</div>' : '<p class="muted">Saisissez une référence pour la lire dans les 8 versions (français, anglais, hébreu, grec, latin).</p>'}</div></div>`;
  $('#cForm', el).onsubmit = e => { e.preventDefault(); go(`#/comparer?ref=${encodeURIComponent($('#cRef', el).value)}`); };
  if (!ref) return;
  try {
    const data = await api('compare', { ref });
    const strong = store.setting('strong') !== false;
    $('#cOut', el).innerHTML = `<h2>${esc(data.ref)}</h2>` + data.versions.map(v => `
      <div class="card"><div class="row"><b class="grow">${esc(v.name)}</b><span class="tag">${esc(v.lang)}</span></div>
      <div class="scripture ${strong ? 'strong-on' : ''} ${v.dir === 'rtl' ? 'rtl' : ''}" lang="${v.lang}">${
        v.verses.map(x => `<span class="vn">${v.verses.length > 1 ? x.v : ''}</span>${renderText(x.text, { strong: strong && versionInfo(v.id).strong })} `).join('')}</div></div>`).join('');
  } catch (e) { showError($('#cOut', el), e); }
}
