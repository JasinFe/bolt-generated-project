import { $, $$, api, esc, plain, store, version, versionInfo, icon, toast } from '../core.js';
import { go } from '../app.js';

const LEVELS = [0, .3, .55, .8, 1];
const SUGGESTIONS = ['Jean 3:16', 'Psaumes 23:1', 'Philippiens 4:13', 'Romains 8:28', 'Josué 1:9', 'Ésaïe 41:10', 'Proverbes 3:5-6', 'Matthieu 6:33', 'Galates 2:20', '2 Timothée 1:7'];

/** Masque une partie des mots (toujours les mêmes pour un niveau donné). */
function masked(text, level) {
  const words = text.split(/(\s+)/);
  let n = 0;
  return words.map(w => {
    if (/^\s+$/.test(w) || !/\p{L}/u.test(w)) return esc(w);
    n++;
    const hide = LEVELS[level] >= 1 || ((n * 37) % 100) / 100 < LEVELS[level];
    if (!hide) return esc(w);
    const [, pre, core, post] = w.match(/^([^\p{L}]*)(.*?)([^\p{L}]*)$/u);
    return `${esc(pre)}<span class="hide-word" title="Cliquer pour révéler">${esc(core[0])}${'_'.repeat(Math.max(1, core.length - 1))}</span><span class="real-word hidden">${esc(core)}</span>${esc(post)}`;
  }).join('');
}

/** Mémoriser des versets : #/memoriser et #/memoriser/<index> */
export async function render(el, { args, params }) {
  const s = store.get();
  s.memo = s.memo || [];
  const add = ref => {
    if (!ref || s.memo.some(m => m.ref.toLowerCase() === ref.toLowerCase())) return;
    s.memo.push({ ref, added: Date.now(), level: 0, mastered: false });
    store.save();
  };
  if (params.get('add')) { add(params.get('add')); toast('Verset ajouté à votre liste'); }
  if (args[0] == null) {
    el.innerHTML = `<div class="page">
      <div class="page-head"><div class="grow"><div class="eyebrow">Discipline</div><h1>Mémoriser des versets</h1>
        <p>« J’ai serré ta parole dans mon cœur » (Psaumes 119:11). Les mots disparaissent peu à peu jusqu’à ce que vous récitiez le verset par cœur.</p></div></div>
      <form class="toolbar" id="mAdd"><input type="text" id="mRef" class="grow" placeholder="Ajouter un verset : Psaumes 119:11"><button class="btn primary">${icon('plus')} Ajouter</button></form>
      <div class="card">${s.memo.length ? s.memo.map((m, i) => `<div class="list-item"><div class="grow"><a href="#/memoriser/${i}"><b>${esc(m.ref)}</b></a>
          <div class="small muted">${m.mastered ? '✔ Maîtrisé' : `Niveau ${m.level + 1} / ${LEVELS.length}`}</div></div>
          <a class="btn sm" href="#/memoriser/${i}">S’entraîner</a><button class="btn sm ghost" data-rm="${i}" title="Retirer">✕</button></div>`).join('')
        : `<div class="empty">Votre liste est vide. Quelques idées :<div class="row" style="justify-content:center;margin-top:10px">${SUGGESTIONS.map(r => `<a class="chip" href="#/memoriser?add=${encodeURIComponent(r)}">${r}</a>`).join('')}</div></div>`}</div></div>`;
    $('#mAdd', el).onsubmit = async e => {
      e.preventDefault();
      const ref = $('#mRef', el).value.trim();
      const parsed = await api('parse', { ref }).catch(() => []);
      if (!parsed.length) return toast('Référence non reconnue');
      add(parsed.map(p => p.label).join(' ; '));
      render(el, { args: [], params: new URLSearchParams() });
    };
    $$('[data-rm]', el).forEach(b => b.onclick = () => { s.memo.splice(+b.dataset.rm, 1); store.save(); render(el, { args: [], params: new URLSearchParams() }); });
    return;
  }
  const i = +args[0];
  const m = s.memo[i];
  if (!m) return go('#/memoriser');
  const d = await api('passage', { v: version(), ref: m.ref });
  const text = d.passages.flatMap(p => p.verses.map(v => plain(v.text))).join(' ');
  const draw = () => {
    el.innerHTML = `<div class="page reading">
      <div class="page-head"><div class="grow"><div class="eyebrow"><a href="#/memoriser">Mémoriser</a> · ${esc(versionInfo(version()).short)}</div><h1>${esc(m.ref)}</h1></div></div>
      <div class="card"><div class="seg">${LEVELS.map((_, l) => `<button data-level="${l}" class="${l === m.level ? 'on' : ''}">Niveau ${l + 1}</button>`).join('')}</div>
        <div class="scripture" id="mText" style="font-size:1.35rem;margin:22px 0;line-height:2">${masked(text, m.level)}</div>
        <div class="row"><button class="btn" id="mReveal">Tout révéler</button><button class="btn" id="mSpeak">${icon('mic')} Écouter</button>
          <span class="grow"></span>${m.level < LEVELS.length - 1 ? `<button class="btn primary" id="mNext">Niveau suivant ${icon('right')}</button>` : `<button class="btn gold" id="mDone">✔ Je le connais par cœur</button>`}</div></div>
      <p class="muted small">Astuce : récitez à voix haute, cliquez sur un mot caché pour le vérifier, puis passez au niveau suivant. Au niveau 5, tout est masqué.</p></div>`;
    $$('.hide-word', el).forEach(h => h.onclick = () => { h.classList.add('hidden'); h.nextElementSibling.classList.remove('hidden'); });
    $$('[data-level]', el).forEach(b => b.onclick = () => { m.level = +b.dataset.level; store.save(); draw(); });
    $('#mReveal', el).onclick = () => { $$('.hide-word', el).forEach(h => h.classList.add('hidden')); $$('.real-word', el).forEach(h => h.classList.remove('hidden')); };
    $('#mSpeak', el).onclick = () => {
      const u = new SpeechSynthesisUtterance(`${m.ref}. ${text}`);
      u.lang = { fr: 'fr-FR', en: 'en-US', es: 'es-ES', de: 'de-DE', it: 'it-IT' }[versionInfo(version()).lang] || 'fr-FR';
      speechSynthesis.cancel(); speechSynthesis.speak(u);
    };
    const next = $('#mNext', el);
    if (next) next.onclick = () => { m.level++; store.save(); draw(); };
    const done = $('#mDone', el);
    if (done) done.onclick = () => { m.mastered = true; store.save(); toast('Bravo ! Verset maîtrisé'); go('#/memoriser'); };
  };
  draw();
}
