import { $, $$, api, esc, plain, store, version, versionInfo, versionOptions, versions, icon, toast } from '../core.js';

const THEMES = {
  nuit: { bg: 'radial-gradient(1200px 600px at 70% 0%, #2f4176, #121829 70%)', fg: '#ffffff', accent: '#e6c37a' },
  aube: { bg: 'linear-gradient(160deg, #3b1d4a, #b0524a 55%, #f0b35b)', fg: '#ffffff', accent: '#ffe7b0' },
  foret: { bg: 'linear-gradient(160deg, #0f2a22, #1f4d3a 60%, #3f7a54)', fg: '#ffffff', accent: '#d9f0c0' },
  parchemin: { bg: 'linear-gradient(160deg, #f8f1e1, #ecdcb8)', fg: '#2a2116', accent: '#8a5a1c' },
  noir: { bg: '#000000', fg: '#ffffff', accent: '#e6c37a' },
};

/** Découpe des paroles en diapositives (sections séparées par une ligne vide ou un [titre]). */
function songSlides(text) {
  const slides = [];
  let cur = { title: '', lines: [] };
  const push = () => { if (cur.lines.length) slides.push(cur); };
  for (const line of String(text).split('\n')) {
    const t = line.trim();
    const m = t.match(/^\[(.+)\]$/);
    if (m) { push(); cur = { title: m[1], lines: [] }; } else if (!t) { push(); cur = { title: cur.title, lines: [] }; } else cur.lines.push(t);
  }
  push();
  return slides.map(s => ({ html: s.lines.map(esc).join('<br>'), label: s.title }));
}

/** Projection : #/projection?ref=Psaumes 23 ou #/projection?song=<id> */
export async function render(el, { params }) {
  await versions();
  const s = store.get();
  const opt = { theme: 'nuit', size: 5, per: 1, v: version(), ...(store.setting('projection') || {}) };
  el.innerHTML = `<div class="page">
    <div class="page-head"><div class="grow"><div class="eyebrow">Culte · Réunion · Évangélisation</div><h1>Projection</h1>
      <p>Affichez des versets ou les paroles d’un chant en plein écran sur un vidéoprojecteur ou une télévision.</p></div></div>
    <div class="card">
      <div class="seg" id="srcSeg"><button data-src="ref" class="on">Passage biblique</button><button data-src="song">Chant</button><button data-src="free">Texte libre</button></div>
      <div id="srcRef" class="editor-section" style="margin-top:14px"><label>Passages (séparés par « ; »)</label>
        <input type="text" id="pRef" style="width:100%" value="${esc(params.get('ref') || '')}" placeholder="Ex. : Psaumes 23 ; Jean 3:16 ; Romains 8:28-39"></div>
      <div id="srcSong" class="editor-section hidden" style="margin-top:14px"><label>Chant</label>
        <select id="pSong" style="width:100%">${s.songs.length ? s.songs.map(x => `<option value="${x.id}" ${x.id === params.get('song') ? 'selected' : ''}>${esc(x.title || 'Sans titre')}</option>`).join('') : '<option value="">Aucun chant enregistré (voir « Chants & textes »)</option>'}</select></div>
      <div id="srcFree" class="editor-section hidden" style="margin-top:14px"><label>Texte (une ligne vide = nouvelle diapositive)</label>
        <textarea id="pFree" style="min-height:140px" placeholder="Annonces, paroles, points du sermon…"></textarea></div>
      <div class="row" style="margin-top:6px">
        <label class="small">Version <select id="pV">${versionOptions(opt.v)}</select></label>
        <label class="small">Versets par écran <select id="pPer">${[1, 2, 3, 4].map(n => `<option ${n === opt.per ? 'selected' : ''}>${n}</option>`).join('')}</select></label>
        <label class="small">Taille <input type="range" id="pSize" min="3" max="9" step="0.5" value="${opt.size}"></label>
      </div>
      <div class="row" style="margin-top:10px">${Object.entries(THEMES).map(([k, t]) => `<button class="chip ${k === opt.theme ? 'on' : ''}" data-theme="${k}" style="background:${t.bg};color:${t.fg};border-color:transparent">${k}</button>`).join('')}</div>
      <div class="row" style="margin-top:16px"><button class="btn primary" id="pGo">${icon('columns')} Lancer la projection</button>
        <span class="muted small">Pendant la projection : ← → ou clic pour avancer, <b>N</b> écran noir, <b>Échap</b> pour quitter.</span></div>
    </div>
    <div id="stage" class="stage hidden" tabindex="0"><div class="slide"><div class="slide-text"></div><div class="slide-ref"></div></div><div class="slide-count"></div></div>
  </div>`;

  let src = params.get('song') ? 'song' : 'ref';
  const setSrc = v => {
    src = v;
    $$('#srcSeg button', el).forEach(b => b.classList.toggle('on', b.dataset.src === v));
    $('#srcRef', el).classList.toggle('hidden', v !== 'ref');
    $('#srcSong', el).classList.toggle('hidden', v !== 'song');
    $('#srcFree', el).classList.toggle('hidden', v !== 'free');
  };
  setSrc(src);
  $$('#srcSeg button', el).forEach(b => b.onclick = () => setSrc(b.dataset.src));
  $$('[data-theme]', el).forEach(b => b.onclick = () => { opt.theme = b.dataset.theme; $$('[data-theme]', el).forEach(x => x.classList.toggle('on', x === b)); });

  async function slides() {
    if (src === 'song') {
      const song = s.songs.find(x => x.id === $('#pSong', el).value);
      if (!song) throw new Error('Choisissez un chant');
      return [{ html: `<span style="font-family:var(--display);font-size:1.3em">${esc(song.title || '')}</span>`, label: '' }, ...songSlides(song.lyrics)];
    }
    if (src === 'free') return songSlides($('#pFree', el).value);
    const ref = $('#pRef', el).value.trim();
    if (!ref) throw new Error('Indiquez un passage');
    const data = await api('passage', { v: opt.v, ref });
    const out = [];
    for (const p of data.passages) {
      for (let i = 0; i < p.verses.length; i += opt.per) {
        const group = p.verses.slice(i, i + opt.per);
        const first = group[0];
        const last = group[group.length - 1];
        out.push({
          html: group.map(v => `${group.length > 1 ? `<sup>${v.v}</sup> ` : ''}${esc(plain(v.text))}`).join(' '),
          label: `${p.ref.replace(/\s[\d:–-]+$/, '')} ${first.c}:${first.v}${last.v !== first.v ? '-' + last.v : ''} · ${versionInfo(opt.v).short}`,
        });
      }
    }
    if (!out.length) throw new Error('Passage introuvable dans cette version');
    return out;
  }

  $('#pGo', el).onclick = async () => {
    opt.v = $('#pV', el).value;
    opt.per = +$('#pPer', el).value;
    opt.size = +$('#pSize', el).value;
    store.setting('projection', opt);
    let list;
    try { list = await slides(); } catch (e) { return toast(e.message); }
    const stage = $('#stage', el);
    const th = THEMES[opt.theme];
    stage.style.background = th.bg;
    stage.style.color = th.fg;
    stage.style.setProperty('--accent', th.accent);
    stage.style.setProperty('--size', opt.size + 'vmin');
    stage.classList.remove('hidden');
    let i = 0;
    let black = false;
    const show = () => {
      const sl = list[i];
      const txt = $('.slide-text', stage);
      txt.innerHTML = sl.html;
      // Ajuste la taille si le texte est long
      const len = txt.textContent.length;
      txt.style.fontSize = `calc(var(--size) * ${len > 420 ? 0.55 : len > 260 ? 0.7 : len > 160 ? 0.85 : 1})`;
      $('.slide-ref', stage).textContent = sl.label || '';
      $('.slide-count', stage).textContent = `${i + 1} / ${list.length}`;
      $('.slide', stage).style.opacity = black ? 0 : 1;
    };
    const exit = () => { stage.classList.add('hidden'); document.removeEventListener('keydown', key); if (document.fullscreenElement) document.exitFullscreen(); };
    const key = e => {
      if (['ArrowRight', 'PageDown', ' ', 'Enter'].includes(e.key)) { i = Math.min(list.length - 1, i + 1); black = false; show(); e.preventDefault(); }
      if (['ArrowLeft', 'PageUp', 'Backspace'].includes(e.key)) { i = Math.max(0, i - 1); black = false; show(); e.preventDefault(); }
      if (e.key.toLowerCase() === 'n' || e.key.toLowerCase() === 'b') { black = !black; show(); }
      if (e.key === 'Escape') exit();
    };
    document.addEventListener('keydown', key);
    stage.onclick = e => { i = e.clientX < innerWidth / 3 ? Math.max(0, i - 1) : Math.min(list.length - 1, i + 1); black = false; show(); };
    document.onfullscreenchange = () => { if (!document.fullscreenElement && !stage.classList.contains('hidden')) exit(); };
    show();
    stage.requestFullscreen?.().catch(() => {});
    stage.focus();
  };
}
