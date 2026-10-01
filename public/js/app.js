// Mister Preacher — routeur et initialisation.
import { $, $$, api, store, versions, version, panel, books, openStrong, showError, loading } from './core.js';

const VIEWS = {
  '': () => import('./views/home.js'),
  lire: () => import('./views/read.js'),
  passage: () => import('./views/read.js').then(m => ({ render: m.renderPassage })),
  comparer: () => import('./views/read.js').then(m => ({ render: m.renderCompare })),
  recherche: () => import('./views/search.js'),
  lexique: () => import('./views/lexicon.js'),
  themes: () => import('./views/themes.js'),
  paralleles: () => import('./views/themes.js').then(m => ({ render: m.renderParallels })),
  etude: () => import('./views/study.js'),
  sermon: () => import('./views/sermon.js'),
  chant: () => import('./views/song.js'),
  evangelisation: () => import('./views/evangel.js'),
  apropos: () => import('./views/about.js'),
};

function parseHash() {
  const h = location.hash.replace(/^#\/?/, '');
  const [path, qs] = h.split('?');
  const [name = '', ...args] = path.split('/').map(decodeURIComponent);
  return { name, args, params: new URLSearchParams(qs || '') };
}

let renderId = 0;
async function route() {
  const { name, args, params } = parseHash();
  const id = ++renderId;
  const el = $('#view');
  panel.close();
  $('#sidenav').classList.remove('open');
  $$('.sidenav a').forEach(a => a.classList.toggle('active', a.dataset.nav === (name || 'home') ||
    (name === 'passage' && a.dataset.nav === 'lire')));
  const loader = VIEWS[name] || VIEWS[''];
  loading(el);
  try {
    const mod = await loader();
    if (id !== renderId) return;
    await mod.render(el, { args, params, isCurrent: () => id === renderId });
    if (!params.get('keepScroll')) window.scrollTo(0, 0);
  } catch (e) {
    console.error(e);
    if (id === renderId) showError(el, e);
  }
}

export function go(hash) {
  if (location.hash === hash) route(); else location.hash = hash;
}

async function init() {
  // Thème
  const theme = store.setting('theme');
  if (theme) document.documentElement.dataset.theme = theme;
  $('#themeBtn').onclick = () => {
    const dark = document.documentElement.dataset.theme === 'dark' ||
      (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'light' : 'dark';
    store.setting('theme', document.documentElement.dataset.theme);
  };
  const size = store.setting('fontSize');
  if (size) document.documentElement.style.setProperty('--scripture-size', size + 'rem');

  // Versions
  const list = await versions();
  const sel = $('#versionSel');
  sel.innerHTML = list.map(v => `<option value="${v.id}">${v.short} (${v.lang.toUpperCase()})</option>`).join('');
  sel.value = version();
  sel.onchange = () => { store.setting('version', sel.value); route(); };
  books(); // préchargement

  // Recherche globale : référence ou mots
  $('#globalSearch').onsubmit = async e => {
    e.preventDefault();
    const q = $('#globalQ').value.trim();
    if (!q) return;
    openLookup(q);
  };

  $('#menuBtn').onclick = () => $('#sidenav').classList.toggle('open');
  $('#panelClose').onclick = () => panel.close();
  document.addEventListener('keydown', e => { if (e.key === 'Escape') panel.close(); });

  // Clic sur un mot balisé Strong (n'importe où)
  document.addEventListener('click', e => {
    const w = e.target.closest('.strong-on .w');
    if (!w) return;
    e.stopPropagation();
    e.preventDefault();
    $$('.w.active').forEach(x => x.classList.remove('active'));
    w.classList.add('active');
    openStrong(w.dataset.s.split(',')[0]);
  }, true);

  window.addEventListener('hashchange', route);
  route();
}

/** Référence -> lecteur ; sinon -> recherche. */
export async function openLookup(q) {
  if (/^[HG]\d+$/i.test(q)) return go(`#/lexique/${q.toUpperCase()}`);
  if (/\d/.test(q)) {
    try {
      const refs = await api('parse', { ref: q });
      if (refs.length === 1 && refs[0].verse == null && refs[0].endChapter === refs[0].chapter) {
        return go(`#/lire/${refs[0].book}/${refs[0].chapter}`);
      }
      if (refs.length) return go(`#/passage?ref=${encodeURIComponent(q)}`);
    } catch { /* pas une référence */ }
  }
  go(`#/recherche?q=${encodeURIComponent(q)}`);
}

init().catch(e => showError($('#view'), e));
