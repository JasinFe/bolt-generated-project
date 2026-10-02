import {
  $, $$, api, esc, plain, renderText, version, versionInfo, versions, books, store, openVerseTools,
  CAT_LABEL, remember, showError, toast, uid, icon, modal, versionsByLang, langName, versionsWithBook,
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

/** Fenêtre de choix du livre puis du chapitre. */
export async function pickBook(current, onPick) {
  const allBooks = await books();
  const available = new Set(Object.keys(versionInfo(version()).books || {}));
  const showBooks = () => {
    const body = modal.open('Choisir un livre', ['AT', 'NT', 'DC', 'AP'].map(cat => {
      const list = allBooks.filter(b => b.cat === cat);
      if (!list.length) return '';
      return `<div class="book-group"><h4>${CAT_LABEL[cat]}</h4><div class="book-grid">${list.map(b =>
        `<button data-b="${b.id}" class="${b.id === current ? 'on' : ''} ${available.has(b.id) ? '' : 'na'}" title="${available.has(b.id) ? '' : 'Absent de la version choisie — sera lu dans une autre version'}">${esc(b.fr)}</button>`).join('')}</div></div>`;
    }).join(''));
    $$('[data-b]', body).forEach(btn => btn.onclick = () => showChapters(allBooks.find(b => b.id === btn.dataset.b)));
  };
  const showChapters = b => {
    if (b.chapters === 1) { modal.close(); return onPick(b.id, 1); }
    const body = modal.open(b.fr, `<p><button class="btn sm" data-back>${icon('left')} Tous les livres</button></p>
      <div class="chap-grid">${Array.from({ length: b.chapters }, (_, i) => `<button data-c="${i + 1}">${i + 1}</button>`).join('')}</div>`);
    $('[data-back]', body).onclick = showBooks;
    $$('[data-c]', body).forEach(btn => btn.onclick = () => { modal.close(); onPick(b.id, +btn.dataset.c); });
  };
  showBooks();
}

/** Fenêtre de choix des versions affichées en parallèle (3 au maximum). */
async function pickParallel(main, current, onDone) {
  const list = (await versions()).filter(v => v.id !== main);
  const chosen = new Set(current);
  const body = modal.open('Versions en parallèle', `<p class="muted small">Choisissez jusqu’à 3 versions à lire à côté de <b>${esc(versionInfo(main).name)}</b>.</p>
    ${versionsByLang(list).map(([lang, vs]) => `<div class="book-group"><h4>${esc(langName(lang))}</h4><div class="ver-list">${vs.map(v => `
      <label class="ver-item ${chosen.has(v.id) ? 'on' : ''}"><input type="checkbox" value="${v.id}" ${chosen.has(v.id) ? 'checked' : ''}>
        <span><b>${esc(v.short)}</b><span>${esc(v.name)}</span></span></label>`).join('')}</div></div>`).join('')}
    <div class="row end" style="position:sticky;bottom:0;background:var(--surface);padding-top:12px"><button class="btn" data-clear>Aucune</button><button class="btn primary" data-ok>Afficher</button></div>`);
  $$('input[type=checkbox]', body).forEach(cb => cb.onchange = () => {
    if (cb.checked && chosen.size >= 3) { cb.checked = false; return toast('3 versions en parallèle au maximum'); }
    cb.checked ? chosen.add(cb.value) : chosen.delete(cb.value);
    cb.closest('.ver-item').classList.toggle('on', cb.checked);
  });
  $('[data-clear]', body).onclick = () => { modal.close(); onDone([]); };
  $('[data-ok]', body).onclick = () => { modal.close(); onDone([...chosen]); };
}

/** Lecteur de chapitre : #/lire/John/3?v=16 */
export async function render(el, { args, params }) {
  const allBooks = await books();
  await versions();
  const last = store.setting('lastRead') || ['John', 1];
  const book = args[0] || last[0];
  const chapter = +(args[1] || (args[0] ? 1 : last[1]));
  const main = version();
  const parallel = (store.setting('parallel') || []).filter(p => p !== main && versionInfo(p).books);
  const strong = store.setting('strong') !== false;
  const bookMeta = allBooks.find(b => b.id === book);
  if (!bookMeta) throw new Error(`Livre inconnu : ${book}`);

  // Si la version principale n'a pas ce livre (deutérocanoniques, apocryphes, NT seul…), on bascule sur une version qui l'a.
  const hasBook = id => !!(versionInfo(id).books || {})[book];
  let fallback = null;
  if (!hasBook(main)) {
    const sameLang = versionsWithBook(book).find(id => versionInfo(id).lang === versionInfo(main).lang);
    fallback = sameLang || versionsWithBook(book)[0] || null;
  }
  const ids = [fallback || main, ...parallel.filter(p => p !== fallback && hasBook(p))];
  const chapters = await Promise.all(ids.map(id => api('chapter', { v: id, b: book, c: chapter }).catch(() => null)));
  const primary = chapters.find(Boolean);
  const maxCh = Math.max(...ids.map(id => (versionInfo(id).books || {})[book] || 0), 1);
  store.setting('lastRead', [book, chapter]);
  remember({ href: `#/lire/${book}/${chapter}`, label: `${bookMeta.fr} ${chapter}` });

  el.innerHTML = `<div class="page ${ids.length > 1 ? 'wide' : 'reading'}">
    <div class="toolbar">
      <button class="btn" id="rPick">${icon('book')} <b>${esc(bookMeta.fr)} ${chapter}</b></button>
      <button class="btn icon" id="rPrev" title="Chapitre précédent" aria-label="Chapitre précédent">${icon('left')}</button>
      <button class="btn icon" id="rNext" title="Chapitre suivant" aria-label="Chapitre suivant">${icon('right')}</button>
      <span class="grow"></span>
      <button class="btn sm" id="rIntro" title="Introduction au livre">${icon('info')} Intro</button>
      <button class="btn sm" id="rListen" title="Écouter le chapitre">${icon('volume')} Écouter</button>
      <a class="btn sm" href="#/projection?ref=${encodeURIComponent(bookMeta.fr + ' ' + chapter)}" title="Projeter ce chapitre">${icon('screen')}</a>
      <button class="btn sm" id="rPar">${icon('columns')} Parallèle${parallel.length ? ` (${parallel.length})` : ''}</button>
      <span class="chip ${strong ? 'on' : ''}" id="rStrong" title="Cliquer sur les mots pour voir l’hébreu ou le grec (Darby, KJV, BSB, Grec)">${icon('alef')} Strong</span>
      <span class="seg" aria-label="Taille du texte"><button id="rSmaller" title="Texte plus petit">A−</button><button id="rBigger" title="Texte plus grand">A+</button></span>
    </div>
    <div class="card reading-body" id="rBody"></div>
    <div class="nav-chapter no-print">
      <a class="btn" id="rPrev2" href="#">${icon('left')} Précédent</a>
      <a class="btn" href="#/comparer?ref=${encodeURIComponent(bookMeta.fr + ' ' + chapter)}">${icon('compare')} Comparer ce chapitre</a>
      <a class="btn" id="rNext2" href="#">Suivant ${icon('right')}</a>
    </div>
  </div>`;

  const body = $('#rBody', el);
  const rawByKey = new Map();
  const head = `<div class="chapter-head"><div class="book">${esc(bookMeta.fr)}</div><div class="num">${chapter}</div>
    <div class="ver">${esc(ids.map(id => versionInfo(id).short).join(' · '))}</div></div>`;
  const notice = fallback ? `<p class="muted small" style="text-align:center">${esc(bookMeta.fr)} ne fait pas partie de ${esc(versionInfo(main).name)} — texte affiché : <b>${esc(versionInfo(fallback).name)}</b>.</p>` : '';
  if (!primary) {
    body.innerHTML = head + `<p class="muted" style="text-align:center">Ce chapitre est introuvable dans les versions choisies.</p>`;
  } else if (ids.length === 1) {
    const info = versionInfo(ids[0]);
    const ch = chapters[0];
    ch.verses.forEach(v => rawByKey.set(v.key, v.text));
    const html = ch.verses.map((v, i) => (v.title ? `<div class="section-title">${esc(v.title)}</div>` : '') +
      (i === 0 && info.dir !== 'rtl' ? dropcap(verseSpan(v, info, strong)) : verseSpan(v, info, strong))).join('');
    body.innerHTML = head + notice + `<div class="scripture ${strong ? 'strong-on' : ''} ${info.dir === 'rtl' ? 'rtl' : ''}" lang="${info.lang}">${html}</div>
      ${ch.copyright ? `<p class="copyright">${esc(ch.copyright)}</p>` : ''}`;
  } else {
    const maxV = Math.max(...chapters.filter(Boolean).map(c => Math.max(0, ...c.verses.map(v => v.v))));
    const maps = chapters.map(c => new Map((c ? c.verses : []).map(v => [v.v, v])));
    chapters.forEach(c => c && c.verses.forEach(v => { if (!rawByKey.has(v.key)) rawByKey.set(v.key, v.text); }));
    const infos = ids.map(versionInfo);
    body.innerHTML = head + notice + `<table class="parallel scripture ${strong ? 'strong-on' : ''}"><thead><tr>${infos.map(i => `<th>${esc(i.short)}</th>`).join('')}</tr></thead><tbody>${
      Array.from({ length: maxV }, (_, i) => i + 1).map(n => `<tr>${maps.map((m, j) => {
        const v = m.get(n);
        const info = infos[j];
        return `<td class="${info.dir === 'rtl' ? 'rtl' : ''}" lang="${info.lang}">${v ? (v.title ? `<div class="section-title">${esc(v.title)}</div>` : '') + verseSpan(v, info, strong) : ''}</td>`;
      }).join('')}</tr>`).join('')}</tbody></table>
      ${chapters.filter(c => c && c.copyright).map(c => `<p class="copyright">${esc(versionInfo(c.version).short)} : ${esc(c.copyright)}</p>`).join('')}`;
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
  $('#rPick', el).onclick = () => pickBook(book, (b, c) => go(`#/lire/${b}/${c}`));
  $('#rPar', el).onclick = () => pickParallel(main, parallel, list => { store.setting('parallel', list); go(location.hash); });
  $('#rIntro', el).onclick = async () => {
    try {
      const b = await api('livre', { id: book, v: version() });
      const { introHTML } = await import('./livres.js');
      modal.open(b.fr, introHTML(b, true));
    } catch (e) { toast(e.message); }
  };
  // Lecture audio (synthèse vocale du navigateur), verset par verset avec surlignage
  $('#rListen', el).onclick = () => {
    const btn = $('#rListen', el);
    if (!window.speechSynthesis) return toast('Lecture audio non disponible dans ce navigateur');
    if (speechSynthesis.speaking) { speechSynthesis.cancel(); btn.innerHTML = `${icon('volume')} Écouter`; $$('.speaking', body).forEach(x => x.classList.remove('speaking')); return; }
    const info = versionInfo(ids[0]);
    const lang = { fr: 'fr-FR', en: 'en-US', es: 'es-ES', de: 'de-DE', it: 'it-IT', ru: 'ru-RU', ar: 'ar-SA', he: 'he-IL', la: 'it-IT', ht: 'fr-HT' }[info.lang] || 'fr-FR';
    const voice = speechSynthesis.getVoices().find(v => v.lang === lang) || speechSynthesis.getVoices().find(v => v.lang.startsWith(lang.slice(0, 2)));
    const verses = (chapters[0] ? chapters[0].verses : []);
    btn.innerHTML = `${icon('stop')} Arrêter`;
    verses.forEach((v, i) => {
      const u = new SpeechSynthesisUtterance(plain(v.text));
      u.lang = lang;
      if (voice) u.voice = voice;
      u.onstart = () => {
        $$('.speaking', body).forEach(x => x.classList.remove('speaking'));
        const span = $(`.verse[data-key="${v.key}"]`, body);
        if (span) { span.classList.add('speaking'); span.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      };
      if (i === verses.length - 1) u.onend = () => { btn.innerHTML = `${icon('volume')} Écouter`; $$('.speaking', body).forEach(x => x.classList.remove('speaking')); };
      speechSynthesis.speak(u);
    });
  };
  $('#rStrong', el).onclick = () => { store.setting('strong', !strong); go(location.hash); };
  const resize = d => {
    const cur = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--scripture-size')) || 1.1;
    const size = Math.min(2, Math.max(.85, cur + d));
    document.documentElement.style.setProperty('--scripture-size', size + 'rem');
    store.setting('fontSize', size);
  };
  $('#rSmaller', el).onclick = () => resize(-.08);
  $('#rBigger', el).onclick = () => resize(.08);

  // Flèches clavier pour changer de chapitre
  document.onkeydown = e => {
    if (!location.hash.startsWith('#/lire') || /input|select|textarea/i.test(document.activeElement.tagName) || $('#modal').open) return;
    if (e.key === 'ArrowLeft') goTo(prev);
    if (e.key === 'ArrowRight') goTo(next);
  };
}

/** Lettrine : met en valeur la première lettre du chapitre. */
function dropcap(html) {
  return html.replace(/(<span class="vn">\d+<\/span>)((?:<span class="w"[^>]*>)?)([«"“(]?\p{L})/u, (_, vn, w, letter) => `${w}<span class="dropcap">${letter}</span>`);
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
  el.innerHTML = `<div class="page reading">
    <div class="toolbar">
      <b class="grow">${esc(data.passages.map(p => p.ref).join(' ; '))} <span class="muted small">· ${esc(info.short)}</span></b>
      <a class="btn sm" href="#/comparer?ref=${encodeURIComponent(ref)}">${icon('compare')} Comparer</a>
      <a class="btn sm" href="#/assistant?ref=${encodeURIComponent(ref)}&mode=expliquer&go=1">${icon('spark')} Expliquer</a>
      <a class="btn sm" href="#/projection?ref=${encodeURIComponent(ref)}">${icon('screen')} Projeter</a>
      <button class="btn sm" id="pStudy">${icon('plus')} Étude</button>
      <button class="btn sm" id="pCopy">Copier</button>
    </div>
    ${data.passages.map(p => {
      p.verses.forEach(x => rawByKey.set(x.key, x.text));
      return `<div class="card">
        <div class="row"><h2 class="grow">${esc(p.ref)}</h2><a class="btn sm" href="#/lire/${p.book}/${p.chapter}?v=${p.verses[0] ? p.verses[0].v : ''}">${icon('book')} Contexte</a></div>
        <div class="scripture ${strong ? 'strong-on' : ''} ${info.dir === 'rtl' ? 'rtl' : ''}">${p.verses.length
          ? p.verses.map(x => verseSpan({ ...x, notes: null }, info, strong)).join('')
          : `<span class="muted">Ce passage n’existe pas dans ${esc(info.name)}.</span>`}</div></div>`;
    }).join('')}
    ${data.copyright ? `<p class="copyright">${esc(data.copyright)}</p>` : ''}
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
  const list = await versions();
  const groups = versionsByLang(list);
  let langs = store.setting('compareLangs') || ['fr', 'he', 'grc'];
  el.innerHTML = `<div class="page wide">
    <div class="page-head"><div class="grow"><div class="eyebrow">${list.length} versions · ${groups.length} langues</div><h1>Comparer les versions</h1>
      <p>Un même passage lu dans plusieurs traductions et dans les langues originales.</p></div></div>
    <form class="toolbar" id="cForm"><input type="text" id="cRef" class="grow" value="${esc(ref)}" placeholder="Ex. : Jean 3:16 ou Psaumes 23:1-3"><button class="btn primary">${icon('compare')} Comparer</button></form>
    <div class="row" style="margin-bottom:18px"><span class="small muted">Langues :</span>
      ${groups.map(([l, vs]) => `<span class="chip ${langs.includes(l) ? 'on' : ''}" data-lang="${l}">${esc(langName(l))} <span class="small">${vs.length}</span></span>`).join('')}
      <span class="chip" data-lang="*">Toutes</span></div>
    <div id="cOut">${ref ? '<div class="loading">Chargement…</div>' : `<div class="empty">Saisissez une référence pour la lire dans les ${list.length} versions disponibles.</div>`}</div></div>`;
  $('#cForm', el).onsubmit = e => { e.preventDefault(); go(`#/comparer?ref=${encodeURIComponent($('#cRef', el).value)}`); };
  $$('[data-lang]', el).forEach(c => c.onclick = () => {
    const l = c.dataset.lang;
    langs = l === '*' ? groups.map(g => g[0]) : langs.includes(l) ? langs.filter(x => x !== l) : [...langs, l];
    store.setting('compareLangs', langs);
    go(location.hash.includes('keepScroll') ? location.hash : location.hash + (location.hash.includes('?') ? '&' : '?') + 'keepScroll=1');
  });
  if (!ref) return;
  const ids = list.filter(v => langs.includes(v.lang)).map(v => v.id);
  if (!ids.length) { $('#cOut', el).innerHTML = '<div class="empty">Choisissez au moins une langue.</div>'; return; }
  try {
    const data = await api('compare', { ref, versions: ids.join(',') });
    const strong = store.setting('strong') !== false;
    const byLang = versionsByLang(data.versions.map(v => ({ ...v, lang: v.lang })));
    $('#cOut', el).innerHTML = `<h2>${esc(data.ref)}</h2>` + (byLang.length ? byLang.map(([lang, vs]) => `
      <div class="lang-group"><h4>${esc(langName(lang))}</h4>${vs.map(v => `
        <div class="card"><div class="row" style="margin-bottom:6px"><b class="grow">${esc(v.short)} <span class="muted small" style="font-weight:400">— ${esc(v.name)}</span></b>
          <a class="btn sm ghost" href="#" data-read="${v.id}">Lire dans cette version</a></div>
        <div class="scripture ${strong ? 'strong-on' : ''} ${v.dir === 'rtl' ? 'rtl' : ''}" lang="${v.lang}">${
          v.verses.map(x => `${v.verses.length > 1 ? `<span class="vn">${x.v}</span>` : ''}${renderText(x.text, { strong: strong && versionInfo(v.id).strong })} `).join('')}</div></div>`).join('')}</div>`).join('')
      : '<div class="empty">Ce passage ne figure dans aucune version de ces langues.</div>');
    $$('[data-read]', el).forEach(a => a.onclick = e => {
      e.preventDefault();
      store.setting('version', a.dataset.read);
      $('#versionSel').value = a.dataset.read;
      go(`#/passage?ref=${encodeURIComponent(ref)}`);
    });
  } catch (e) { showError($('#cOut', el), e); }
}
