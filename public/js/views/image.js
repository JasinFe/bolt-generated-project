import { $, $$, api, esc, plain, store, version, versionInfo, versionOptions, versions, icon, toast } from '../core.js';

const BGS = {
  nuit: ['#121829', '#2f4176', '#e6c37a'],
  aube: ['#3b1d4a', '#f0b35b', '#ffe7b0'],
  ocean: ['#06283d', '#1f8a9e', '#d6f5ff'],
  foret: ['#0f2a22', '#3f7a54', '#e0f2c8'],
  braise: ['#2a0b0b', '#b4382c', '#ffd9a0'],
  parchemin: ['#f8f1e1', '#e3cfa2', '#7a4b12'],
  lavande: ['#2a2342', '#8c7bd1', '#f3eaff'],
};
const FORMATS = { carre: [1080, 1080, 'Carré (Instagram, Facebook)'], story: [1080, 1920, 'Story / statut (vertical)'], paysage: [1920, 1080, 'Paysage (YouTube, projection)'] };
const FONTS = { serif: '"Literata", Georgia, serif', classique: '"Cormorant Garamond", Georgia, serif', moderne: '"Inter", system-ui, sans-serif' };

/** Découpe le texte en lignes tenant dans la largeur donnée. */
function wrap(ctx, text, width) {
  const lines = [];
  let line = '';
  for (const word of text.split(/\s+/)) {
    const test = line ? line + ' ' + word : word;
    if (ctx.measureText(test).width > width && line) { lines.push(line); line = word; } else line = test;
  }
  if (line) lines.push(line);
  return lines;
}

/** Image de verset : #/image?ref=Jean 3:16 */
export async function render(el, { params }) {
  await versions();
  const opt = { bg: 'nuit', format: 'carre', font: 'serif', v: version(), sign: true, ...(store.setting('image') || {}) };
  el.innerHTML = `<div class="page wide">
    <div class="page-head"><div class="grow"><div class="eyebrow">Création</div><h1>Image de verset</h1>
      <p>Créez une belle image d’un verset à partager sur les réseaux sociaux, WhatsApp ou à projeter.</p></div></div>
    <div class="two-col" style="grid-template-columns:minmax(0,1fr) 340px">
      <div class="card" style="display:grid;place-items:center;background:var(--surface-2)"><canvas id="cv" style="max-width:100%;max-height:70vh;border-radius:12px;box-shadow:var(--shadow-lg)"></canvas></div>
      <aside class="card">
        <div class="editor-section"><label>Verset</label><input type="text" id="iRef" style="width:100%" value="${esc(params.get('ref') || 'Jean 3:16')}"></div>
        <div class="editor-section"><label>Version</label><select id="iV" style="width:100%">${versionOptions(opt.v)}</select></div>
        <div class="editor-section"><label>Ou votre texte</label><textarea id="iText" placeholder="Laissez vide pour utiliser le verset"></textarea></div>
        <div class="editor-section"><label>Fond</label><div class="row">${Object.entries(BGS).map(([k, c]) => `<button class="color" data-bg="${k}" title="${k}" style="background:linear-gradient(135deg,${c[0]},${c[1]});${k === opt.bg ? 'box-shadow:0 0 0 3px var(--gold)' : ''}"></button>`).join('')}</div></div>
        <div class="editor-section"><label>Format</label><select id="iFormat" style="width:100%">${Object.entries(FORMATS).map(([k, f]) => `<option value="${k}" ${k === opt.format ? 'selected' : ''}>${f[2]}</option>`).join('')}</select></div>
        <div class="editor-section"><label>Police</label><select id="iFont" style="width:100%">${Object.keys(FONTS).map(k => `<option ${k === opt.font ? 'selected' : ''}>${k}</option>`).join('')}</select></div>
        <label class="row small"><input type="checkbox" id="iSign" ${opt.sign ? 'checked' : ''}> Signature « Mister Preacher »</label>
        <div class="row" style="margin-top:14px"><button class="btn primary" id="iDl">Télécharger l’image (PNG)</button><button class="btn" id="iShare">Partager</button></div>
      </aside></div></div>`;
  const cv = $('#cv', el);
  let text = '';
  let label = '';

  async function load() {
    const custom = $('#iText', el).value.trim();
    if (custom) { text = custom; label = $('#iRef', el).value.trim(); return; }
    try {
      const d = await api('passage', { v: opt.v, ref: $('#iRef', el).value.trim() });
      const p = d.passages[0];
      text = p.verses.map(v => plain(v.text)).join(' ');
      label = `${d.passages.map(x => x.ref).join(' ; ')} · ${versionInfo(opt.v).short}`;
      if (!text) text = 'Passage absent de cette version.';
    } catch (e) { text = e.message; label = ''; }
  }

  function draw() {
    const [W, H] = FORMATS[opt.format];
    const [c1, c2, accent] = BGS[opt.bg];
    cv.width = W; cv.height = H;
    const ctx = cv.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, c1); g.addColorStop(1, c2);
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    const glow = ctx.createRadialGradient(W * .8, H * .1, 0, W * .8, H * .1, W * .8);
    glow.addColorStop(0, 'rgba(255,255,255,.18)'); glow.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = glow; ctx.fillRect(0, 0, W, H);
    const dark = opt.bg !== 'parchemin';
    const fg = dark ? '#ffffff' : '#2a2116';
    const margin = W * .11;
    const maxW = W - margin * 2;
    // Taille de police décroissante jusqu'à ce que le texte tienne
    let size = Math.round(W * .075);
    let lines;
    for (;;) {
      ctx.font = `${opt.font === 'moderne' ? 600 : 500} ${size}px ${FONTS[opt.font]}`;
      lines = wrap(ctx, `« ${text} »`, maxW);
      if (lines.length * size * 1.4 < H * .62 || size < 26) break;
      size -= 2;
    }
    const lh = size * 1.4;
    let y = H / 2 - (lines.length * lh) / 2 + size * .35;
    ctx.fillStyle = fg; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.shadowColor = dark ? 'rgba(0,0,0,.35)' : 'transparent'; ctx.shadowBlur = 18;
    for (const l of lines) { ctx.fillText(l, W / 2, y); y += lh; }
    ctx.shadowBlur = 0;
    ctx.fillStyle = accent;
    ctx.fillRect(W / 2 - W * .05, y + size * .1, W * .1, Math.max(3, W * .004));
    ctx.font = `600 ${Math.round(W * .03)}px "Inter", system-ui, sans-serif`;
    ctx.fillText(label.toUpperCase(), W / 2, y + size * .9);
    if (opt.sign) {
      ctx.globalAlpha = .6; ctx.fillStyle = fg;
      ctx.font = `600 ${Math.round(W * .022)}px "Cormorant Garamond", Georgia, serif`;
      ctx.fillText('Mister Preacher', W / 2, H - W * .05);
      ctx.globalAlpha = 1;
    }
  }

  const refresh = async () => { await load(); draw(); };
  const save = () => store.setting('image', opt);
  $('#iRef', el).onchange = refresh;
  $('#iText', el).oninput = () => { clearTimeout(refresh.t); refresh.t = setTimeout(refresh, 300); };
  $('#iV', el).onchange = e => { opt.v = e.target.value; save(); refresh(); };
  $('#iFormat', el).onchange = e => { opt.format = e.target.value; save(); draw(); };
  $('#iFont', el).onchange = e => { opt.font = e.target.value; save(); draw(); };
  $('#iSign', el).onchange = e => { opt.sign = e.target.checked; save(); draw(); };
  $$('[data-bg]', el).forEach(b => b.onclick = () => {
    opt.bg = b.dataset.bg; save();
    $$('[data-bg]', el).forEach(x => { x.style.boxShadow = x === b ? '0 0 0 3px var(--gold)' : ''; });
    draw();
  });
  const fileName = () => `verset-${(label || 'mister-preacher').split('·')[0].trim().replace(/[^\p{L}\p{N}]+/gu, '-')}.png`;
  $('#iDl', el).onclick = () => { const a = document.createElement('a'); a.href = cv.toDataURL('image/png'); a.download = fileName(); a.click(); };
  $('#iShare', el).onclick = () => cv.toBlob(async blob => {
    const file = new File([blob], fileName(), { type: 'image/png' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      try { await navigator.share({ files: [file], title: label }); } catch { /* partage annulé */ }
    } else toast('Partage direct indisponible ici : utilisez « Télécharger ».');
  });
  if (document.fonts && document.fonts.ready) await document.fonts.ready;
  refresh();
}
