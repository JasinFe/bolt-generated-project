// Mister Preacher — utilitaires partagés : API, stockage, rendu, panneau.

const cache = new Map();

export async function api(path, params = {}, opts = {}) {
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
  const url = `/api/${path}${qs.toString() ? '?' + qs : ''}`;
  if (!opts.method && cache.has(url)) return cache.get(url);
  const p = fetch(url, opts.method ? { method: opts.method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(opts.body) } : undefined)
    .then(async r => {
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || `Erreur ${r.status}`);
      return data;
    });
  if (!opts.method) {
    cache.set(url, p);
    p.catch(() => cache.delete(url));
  }
  return p;
}

// ---------- Stockage local (études, sermons, chants, surlignages) ----------
const KEY = 'mp.v1';
const blank = () => ({ highlights: {}, notes: {}, studies: [], sermons: [], songs: [], history: [], settings: {} });
let state = blank();
try { state = { ...blank(), ...JSON.parse(localStorage.getItem(KEY) || '{}') }; } catch { /* stockage indisponible */ }

export const store = {
  get: () => state,
  save() { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch { toast('Impossible d’enregistrer (stockage plein ou bloqué)'); } },
  setting(k, v) { if (v === undefined) return state.settings[k]; state.settings[k] = v; this.save(); return v; },
  export() { return JSON.stringify(state, null, 2); },
  import(json) { state = { ...blank(), ...JSON.parse(json) }; this.save(); },
};

export const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 7);

// ---------- Utilitaires ----------
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
export const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const plain = t => String(t || '').replace(/\{([^|}]*)\|[^}]*\}/g, '$1').replace(/[[\]]/g, '');

export function toast(msg) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => t.classList.remove('show'), 2200);
}

export function version() {
  const v = store.setting('version');
  return v && (!VERSIONS.length || VERSIONS.some(x => x.id === v)) ? v : 'LSG';
}

let VERSIONS = [];
export async function versions() {
  if (!VERSIONS.length) VERSIONS = await api('versions');
  return VERSIONS;
}
export const versionInfo = id => VERSIONS.find(v => v.id === id) || { id, short: id, name: id, lang: 'fr', dir: 'ltr', books: {} };

/** Langues, dans l'ordre d'affichage. */
export const LANGS = {
  fr: 'Français', ln: 'Lingala', ht: 'Créole haïtien', ee: 'Éwé', tw: 'Twi', ha: 'Haoussa', ig: 'Igbo',
  en: 'Anglais', es: 'Espagnol', de: 'Allemand', it: 'Italien', ru: 'Russe', ar: 'Arabe',
  he: 'Hébreu', grc: 'Grec', la: 'Latin',
};
export const langName = code => LANGS[code] || code;

/** Versions regroupées par langue : [[code, [versions]], …] */
export function versionsByLang(list = VERSIONS) {
  const order = Object.keys(LANGS);
  const groups = new Map();
  for (const v of list) {
    if (!groups.has(v.lang)) groups.set(v.lang, []);
    groups.get(v.lang).push(v);
  }
  return [...groups.entries()].sort((a, b) => (order.indexOf(a[0]) + 1 || 99) - (order.indexOf(b[0]) + 1 || 99));
}

/** <option> groupées par langue, pour un <select>. */
export function versionOptions(selected, list = VERSIONS) {
  return versionsByLang(list).map(([lang, vs]) => `<optgroup label="${esc(langName(lang))}">${
    vs.map(v => `<option value="${v.id}" ${v.id === selected ? 'selected' : ''}>${esc(v.short)}${v.remote ? ' ☁' : ''}</option>`).join('')}</optgroup>`).join('');
}

/** Versions qui contiennent un livre. */
export const versionsWithBook = book => VERSIONS.filter(v => v.books && v.books[book]).map(v => v.id);

let BOOKS = null;
/** Tous les livres présents dans au moins une version, avec le nombre maximal de chapitres. */
export async function books() {
  if (!BOOKS) {
    const [canon, list] = await Promise.all([api('canon'), versions()]);
    const order = ['AT', 'DC', 'AP', 'NT'];
    BOOKS = canon.map(b => ({ ...b, chapters: Math.max(0, ...list.map(v => (v.books && v.books[b.id]) || 0)) }))
      .filter(b => b.chapters > 0)
      .map((b, i) => ({ ...b, i }))
      .sort((a, b) => order.indexOf(a.cat) - order.indexOf(b.cat) || a.i - b.i);
  }
  return BOOKS;
}
export const CAT_LABEL = { AT: 'Ancien Testament', NT: 'Nouveau Testament', DC: 'Deutérocanoniques', AP: 'Apocryphes' };

/** Icône du sprite SVG. */
export const icon = (name, cls = '') => `<svg class="ic ${cls}"><use href="#i-${name}"/></svg>`;

/** Fenêtre modale (élément <dialog>). */
export const modal = {
  open(title, html) {
    const d = $('#modal');
    d.innerHTML = `<div class="m-head"><h3>${esc(title)}</h3><button class="icon-btn" data-close aria-label="Fermer">${icon('close')}</button></div><div class="m-body">${html}</div>`;
    d.querySelector('[data-close]').onclick = () => d.close();
    d.onclick = e => { if (e.target === d) d.close(); };
    if (!d.open) d.showModal();
    return d.querySelector('.m-body');
  },
  close() { const d = $('#modal'); if (d.open) d.close(); },
};

export function keyLabel(key) {
  const [b, c, v] = key.split('.');
  const book = BOOKS && BOOKS.find(x => x.id === b);
  return `${book ? book.fr : b} ${c}${v ? ':' + v : ''}`;
}

/** Rendu d'un verset au format compact -> HTML. */
export function renderText(raw, { strong = false } = {}) {
  let h = esc(raw);
  h = h.replace(/\{([^|}]*)\|([^}]*)\}/g, (_, w, n) => (strong ? `<span class="w" data-s="${n}">${w}</span>` : w));
  h = h.replace(/\[([^\]]*)\]/g, '<em class="add">$1</em>');
  return h;
}

/** Texte avec plages surlignées [[début, fin], …]. */
export function highlight(text, ranges = []) {
  let out = '';
  let last = 0;
  for (const [a, b] of ranges) {
    if (a < last) continue;
    out += esc(text.slice(last, a)) + '<mark>' + esc(text.slice(a, b)) + '</mark>';
    last = b;
  }
  return out + esc(text.slice(last));
}

export function loading(el, msg = 'Chargement…') { el.innerHTML = `<div class="loading">${esc(msg)}</div>`; }
export function showError(el, e) { el.innerHTML = `<div class="error">${esc(e.message || e)}</div>`; }

export function download(name, content, type = 'text/markdown') {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([content], { type: type + ';charset=utf-8' }));
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

export async function copy(text) {
  try { await navigator.clipboard.writeText(text); toast('Copié dans le presse-papiers'); }
  catch { toast('Copie impossible'); }
}

/** Texte d'une référence (pour aperçus, exports). */
export async function refText(ref, v = version()) {
  const data = await api('passage', { v, ref });
  return data.passages.map(p => ({ ref: p.ref, text: p.verses.map(x => (p.verses.length > 1 ? `(${x.v}) ` : '') + plain(x.text)).join(' ') }));
}

export function refPreviewHTML(list) {
  return list.map(p => `<div class="ref-preview"><b>${esc(p.ref)}</b><br>${esc(p.text) || '<span class="muted">Passage absent de cette version</span>'}</div>`).join('');
}

// ---------- Panneau latéral ----------
export const panel = {
  open(title, html) {
    $('#panelTitle').textContent = title;
    $('#panelBody').innerHTML = html;
    $('#panel').classList.add('open');
    return $('#panelBody');
  },
  close() { $('#panel').classList.remove('open'); $$('.verse.selected').forEach(v => v.classList.remove('selected')); },
};

const COLORS = ['yellow', 'green', 'blue', 'pink', 'purple'];

/** Ouvre les outils d'un verset : surlignage, note, étude, références croisées, mots originaux. */
export async function openVerseTools(key, raw, onChange) {
  const s = store.get();
  const label = keyLabel(key);
  const studies = s.studies;
  const body = panel.open(label, `
    <section><div class="quote">${renderText(raw || '')}</div><div class="small muted" style="margin-top:6px">${esc(versionInfo(version()).name)}</div></section>
    <section><h4>Surligner</h4><div class="colors">
      ${COLORS.map(c => `<button class="color ${c}" data-color="${c}" title="${c}"></button>`).join('')}
      <button class="color none" data-color="" title="Retirer"></button></div></section>
    <section><h4>Ma note</h4><textarea id="vNote" placeholder="Pensée, révélation, illustration…">${esc(s.notes[key] || '')}</textarea></section>
    <section><h4>Ajouter à une étude</h4><div class="row">
      <select id="vStudy" class="grow"><option value="__new">+ Nouvelle étude…</option>
        ${studies.map(st => `<option value="${st.id}">${esc(st.title)}</option>`).join('')}</select>
      <button class="btn sm" id="vAdd">Ajouter</button></div></section>
    <section class="row">
      <a class="btn sm" href="#/comparer?ref=${encodeURIComponent(label)}">${icon('compare')} Comparer</a>
      <button class="btn sm" id="vCopy">Copier</button>
      <a class="btn sm" href="#/paralleles?ref=${encodeURIComponent(label)}">${icon('columns')} Parallèles</a>
    </section>
    <section id="vWords"></section>
    <section><h4>Références croisées</h4><div id="vXref" class="loading">Chargement…</div></section>`);

  if (s.studies.length && store.setting('lastStudy')) $('#vStudy', body).value = store.setting('lastStudy');
  body.querySelectorAll('.color').forEach(btn => btn.onclick = () => {
    if (btn.dataset.color) s.highlights[key] = btn.dataset.color; else delete s.highlights[key];
    store.save(); onChange && onChange(); toast(btn.dataset.color ? 'Verset surligné' : 'Surlignage retiré');
  });
  $('#vNote', body).onchange = e => {
    const t = e.target.value.trim();
    if (t) s.notes[key] = t; else delete s.notes[key];
    store.save(); onChange && onChange(); toast('Note enregistrée');
  };
  $('#vAdd', body).onclick = () => {
    let id = $('#vStudy', body).value;
    if (id === '__new') {
      const title = prompt('Titre de la nouvelle étude :', 'Étude du ' + new Date().toLocaleDateString('fr-FR'));
      if (!title) return;
      id = uid();
      s.studies.unshift({ id, title, description: '', items: [], created: Date.now() });
    }
    const st = s.studies.find(x => x.id === id);
    st.items.push({ id: uid(), ref: label, note: '' });
    store.setting('lastStudy', id);
    store.save();
    toast(`Ajouté à « ${st.title} »`);
  };
  $('#vCopy', body).onclick = () => copy(`« ${plain(raw)} » (${label}, ${versionInfo(version()).short})`);

  // Mots originaux : à partir de la version Strong (Darby ou KJV)
  const strongVersion = versionInfo(version()).strong ? version() : 'JND';
  api('passage', { v: strongVersion, ref: label }).then(d => {
    const v = d.passages[0] && d.passages[0].verses[0];
    if (!v) return;
    const words = [...v.text.matchAll(/\{([^|}]*)\|([^}]*)\}/g)];
    if (!words.length) return;
    $('#vWords', body).innerHTML = `<h4>Mots originaux (${esc(versionInfo(strongVersion).short)})</h4><div class="word-list">${
      words.map(m => `<a class="chip" href="#/lexique/${m[2].split(',')[0]}" title="${esc(m[2])}">${esc(m[1])} <span class="muted small">${esc(m[2].split(',')[0])}</span></a>`).join('')}</div>`;
  }).catch(() => {});

  try {
    const x = await api('xref', { key, v: version(), limit: 30 });
    $('#vXref', body).classList.remove('loading');
    $('#vXref', body).innerHTML = x.refs.length ? x.refs.map(r => `
      <div class="xref"><span class="votes" title="Pertinence (votes OpenBible.info)">▲ ${r.votes}</span>
        <a href="#/passage?ref=${encodeURIComponent(r.ref)}">${esc(r.ref)}</a>
        <div class="scripture small">${esc(r.text.length > 260 ? r.text.slice(0, 260) + '…' : r.text)}</div></div>`).join('')
      + (x.total > x.refs.length ? `<p class="muted small">${x.total} références au total.</p>` : '')
      : '<p class="muted">Aucune référence croisée pour ce verset.</p>';
  } catch (e) { showError($('#vXref', body), e); }
}

/** Fiche Strong dans le panneau. */
export async function openStrong(num) {
  const body = panel.open(num, '<div class="loading">Chargement…</div>');
  try {
    const e = await api('strong', { n: num });
    body.innerHTML = strongHTML(e, true);
  } catch (err) { showError(body, err); }
}

export function strongHTML(e, compact = false) {
  const usage = Object.entries(e.usage).map(([v, u]) => `
    <div class="editor-section"><b>${esc(versionInfo(v).short)}</b> <span class="muted small">— ${u.occurrences} versets</span>
      <div class="cloud">${u.words.map(w => `<span style="font-size:${Math.min(1.5, .8 + w.count / Math.max(...u.words.map(x => x.count)) * .7)}rem">${esc(w.word)} <sup class="muted">${w.count}</sup></span>`).join('')}</div>
      <a class="small" href="#/recherche?q=${e.num}&v=${v}">Voir toutes les occurrences →</a></div>`).join('');
  const link = s => esc(s).replace(/\b([HG]\d+)\b/g, '<a href="#/lexique/$1">$1</a>');
  return `
    <div class="lemma ${e.num[0] === 'H' ? 'he' : ''}">${esc(e.lemma)}</div>
    <p><b>${esc(e.translit)}</b>${e.pron ? ` <span class="muted">(${esc(e.pron)})</span>` : ''} · <span class="tag">${esc(e.language)}</span> · <span class="tag">${esc(e.num)}</span></p>
    <div class="editor-section"><label>Définition (Strong)</label>${link(e.def) || '<span class="muted">—</span>'}</div>
    ${e.deriv ? `<div class="editor-section"><label>Origine / dérivation</label>${link(e.deriv)}</div>` : ''}
    ${e.kjv ? `<div class="editor-section"><label>Rendu dans la KJV</label>${esc(e.kjv)}</div>` : ''}
    <div class="editor-section"><label>Comment ce mot est traduit</label>${usage || '<span class="muted">Aucune occurrence balisée.</span>'}</div>
    ${e.related.length ? `<div class="editor-section"><label>Mots apparentés</label><div class="word-list">${e.related.map(r => `<a class="chip" href="#/lexique/${r.num}">${esc(r.lemma)} <span class="muted small">${r.num}</span></a>`).join('')}</div></div>` : ''}
    ${compact ? `<a class="btn sm" href="#/lexique/${e.num}">Ouvrir la fiche complète</a>` : ''}`;
}

/** Ajoute l'historique de lecture. */
export function remember(entry) {
  const s = store.get();
  s.history = [entry, ...s.history.filter(h => h.href !== entry.href)].slice(0, 12);
  store.save();
}
