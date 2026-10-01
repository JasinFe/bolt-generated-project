import { $, $$, api, esc, store, uid, refText, refPreviewHTML, version, versionInfo, download } from '../core.js';
import { go } from '../app.js';

const TEMPLATES = {
  expositif: {
    label: 'Expositif (un texte, verset par verset)',
    help: 'Lisez le texte dans son contexte, dégagez ce qu’il dit (observation), ce qu’il veut dire (interprétation), puis ce qu’il change pour nous (application).',
    points: ['Le contexte du passage', 'Ce que dit le texte', 'Ce que cela signifie', 'Ce que cela change pour nous'],
  },
  thematique: {
    label: 'Thématique (un sujet à travers la Bible)',
    help: 'Choisissez une idée centrale et appuyez chaque point sur deux ou trois passages complémentaires (voir Thèmes et Références croisées).',
    points: ['Premier aspect du thème', 'Deuxième aspect', 'Troisième aspect'],
  },
  narratif: {
    label: 'Narratif (raconter une histoire biblique)',
    help: 'Situation → tension → intervention de Dieu → résolution → leçon. Faites vivre les personnages.',
    points: ['La situation', 'La crise', 'L’intervention de Dieu', 'Le dénouement et la leçon'],
  },
  evangelisation: {
    label: 'Évangélisation (message d’appel)',
    help: 'Partez d’un besoin humain réel, présentez Jésus et son œuvre, appelez à une réponse claire.',
    points: ['Le besoin de l’homme', 'L’amour de Dieu en Jésus', 'La croix et la résurrection', 'La réponse : se repentir et croire'],
  },
  exhortation: {
    label: 'Exhortation / prophétique (parole pour l’Église)',
    help: 'Une parole claire pour aujourd’hui, fondée sur l’Écriture : constat, rappel de la Parole, appel, promesse.',
    points: ['Le constat', 'Ce que Dieu a dit', 'L’appel', 'La promesse'],
  },
};

function newSermon(template = 'expositif', extra = {}) {
  return {
    id: uid(), title: '', text: '', template, bigIdea: '', intro: '',
    points: TEMPLATES[template].points.map(t => ({ title: t, refs: '', body: '' })),
    illustration: '', application: '', conclusion: '', prayer: '', created: Date.now(), ...extra,
  };
}

export async function render(el, { args, params }) {
  const s = store.get();
  if (params.get('theme') && !args[0]) {
    const t = await api('theme', { id: params.get('theme'), v: version() });
    const sermon = newSermon('thematique', {
      title: t.title, text: t.passages[0].ref,
      points: [0, 1, 2].map(i => ({ title: '', refs: t.passages.slice(1 + i * 2, 3 + i * 2).map(p => p.ref).join(' ; '), body: '' })),
    });
    s.sermons.unshift(sermon);
    store.save();
    return go(`#/sermon/${sermon.id}`);
  }
  if (args[0]) return renderEditor(el, args[0], params.get('apercu') === '1');

  el.innerHTML = `<div class="page">
    <div class="page-head"><div class="grow"><div class="eyebrow">Atelier</div><h1>Sermons &amp; prédications</h1><p>Choisissez un modèle : les versets s’insèrent automatiquement, vous gardez la main sur le message.</p></div></div>
    <div class="card"><h3>Nouveau sermon</h3><div class="grid">${Object.entries(TEMPLATES).map(([k, t]) => `
      <button class="card tile" data-new="${k}" style="text-align:left;cursor:pointer"><b>${esc(t.label)}</b><div class="muted small">${esc(t.help)}</div></button>`).join('')}</div></div>
    <div class="card"><h3>Mes sermons</h3>${s.sermons.length ? s.sermons.map(x => `
      <div class="list-item"><div class="grow"><a href="#/sermon/${x.id}"><b>${esc(x.title || 'Sans titre')}</b></a>
      <div class="muted small">${esc(x.text || '')} · ${esc(TEMPLATES[x.template] ? TEMPLATES[x.template].label : '')} · ${new Date(x.created).toLocaleDateString('fr-FR')}</div></div>
      <button class="btn sm ghost" data-del="${x.id}" title="Supprimer">🗑</button></div>`).join('') : '<div class="empty">Aucun sermon enregistré.</div>'}</div></div>`;
  $$('[data-new]', el).forEach(b => b.onclick = () => {
    const x = newSermon(b.dataset.new);
    s.sermons.unshift(x);
    store.save();
    go(`#/sermon/${x.id}`);
  });
  $$('[data-del]', el).forEach(b => b.onclick = () => {
    if (!confirm('Supprimer ce sermon ?')) return;
    s.sermons = s.sermons.filter(x => x.id !== b.dataset.del);
    store.save();
    render(el, { args: [], params });
  });
}

async function renderEditor(el, id, preview) {
  const s = store.get();
  const x = s.sermons.find(y => y.id === id);
  if (!x) { el.innerHTML = '<div class="empty">Sermon introuvable. <a href="#/sermon">Retour</a></div>'; return; }
  if (preview) return renderPreview(el, x);
  const tpl = TEMPLATES[x.template] || TEMPLATES.expositif;
  const field = (k, label, ph, rows = 3) => `<div class="editor-section"><label for="f_${k}">${label}</label>
    <textarea id="f_${k}" data-k="${k}" rows="${rows}" placeholder="${esc(ph)}">${esc(x[k] || '')}</textarea></div>`;

  el.innerHTML = `<div class="page">
    <div class="toolbar"><a class="btn sm" href="#/sermon">← Sermons</a><span class="grow muted small">${esc(tpl.label)} · enregistrement automatique</span>
      <a class="btn sm" href="#/sermon/${x.id}?apercu=1">Aperçu / imprimer</a><button class="btn sm" id="mdExport">Exporter (Markdown)</button></div>
    <p class="muted small">${esc(tpl.help)}</p>
    <div class="card">
      <div class="editor-section"><label>Titre</label><input type="text" data-k="title" value="${esc(x.title)}" style="width:100%" placeholder="Ex. : Le berger qui ne lâche pas"></div>
      <div class="editor-section"><label>Texte de base</label><input type="text" data-k="text" data-ref="1" value="${esc(x.text)}" style="width:100%" placeholder="Ex. : Psaumes 23">
        <div class="preview" data-for="text"></div></div>
      ${field('bigIdea', 'Idée principale (en une phrase)', 'Ce que l’auditeur doit retenir s’il ne retient qu’une chose.', 2)}
      ${field('intro', 'Introduction', 'Accroche, question, situation de vie…')}
    </div>
    <h2 style="margin-top:20px">Plan</h2>
    <div id="points"></div>
    <button class="btn" id="addPoint">+ Ajouter un point</button>
    <div class="card" style="margin-top:16px">
      ${field('illustration', 'Illustrations', 'Histoire, image, témoignage, actualité…')}
      ${field('application', 'Application', 'Concrètement, cette semaine…')}
      ${field('conclusion', 'Conclusion & appel', 'Résumé, appel à la décision, invitation…')}
      ${field('prayer', 'Prière', 'Prière finale', 2)}
    </div>
    <p class="muted small" style="margin-top:12px">Astuce : cliquez sur un verset dans le lecteur pour trouver ses références croisées, puis collez-les dans un point (séparées par « ; »).</p>
  </div>`;

  const save = () => store.save();
  const previews = new Map();
  const updatePreview = async (box, ref) => {
    if (!box) return;
    if (!ref.trim()) { box.innerHTML = ''; return; }
    clearTimeout(previews.get(box));
    previews.set(box, setTimeout(async () => {
      try { box.innerHTML = refPreviewHTML(await refText(ref)); } catch { box.innerHTML = '<div class="muted small">Référence non reconnue</div>'; }
    }, 400));
  };
  $$('[data-k]', el).forEach(inp => inp.oninput = () => {
    x[inp.dataset.k] = inp.value;
    save();
    if (inp.dataset.ref) updatePreview($(`.preview[data-for="${inp.dataset.k}"]`, el), inp.value);
  });
  updatePreview($('.preview[data-for="text"]', el), x.text);

  const drawPoints = () => {
    const box = $('#points', el);
    box.innerHTML = x.points.map((p, i) => `<div class="point">
      <div class="row"><b>${i + 1}.</b><input type="text" class="grow" data-p="${i}" data-f="title" value="${esc(p.title)}" placeholder="Titre du point">
        <button class="btn sm ghost" data-up="${i}" title="Monter">↑</button><button class="btn sm ghost" data-rm="${i}" title="Supprimer">✕</button></div>
      <input type="text" data-p="${i}" data-f="refs" value="${esc(p.refs)}" style="width:100%;margin-top:6px" placeholder="Références : Jean 10:11 ; Ézéchiel 34:11-16">
      <div class="ppreview" data-pp="${i}"></div>
      <textarea data-p="${i}" data-f="body" style="margin-top:6px" placeholder="Développement, explication, mots originaux…">${esc(p.body)}</textarea></div>`).join('');
    $$('[data-p]', box).forEach(inp => inp.oninput = () => {
      x.points[+inp.dataset.p][inp.dataset.f] = inp.value;
      save();
      if (inp.dataset.f === 'refs') updatePreview($(`[data-pp="${inp.dataset.p}"]`, box), inp.value);
    });
    x.points.forEach((p, i) => updatePreview($(`[data-pp="${i}"]`, box), p.refs));
    $$('[data-rm]', box).forEach(b => b.onclick = () => { x.points.splice(+b.dataset.rm, 1); save(); drawPoints(); });
    $$('[data-up]', box).forEach(b => b.onclick = () => {
      const i = +b.dataset.up;
      if (i > 0) { [x.points[i - 1], x.points[i]] = [x.points[i], x.points[i - 1]]; save(); drawPoints(); }
    });
  };
  drawPoints();
  $('#addPoint', el).onclick = () => { x.points.push({ title: '', refs: '', body: '' }); save(); drawPoints(); };
  $('#mdExport', el).onclick = async () => download(`${(x.title || 'sermon').replace(/[^\p{L}\p{N}]+/gu, '-')}.md`, await toMarkdown(x));
}

async function quote(ref) {
  if (!ref || !ref.trim()) return [];
  try { return await refText(ref); } catch { return [{ ref, text: '' }]; }
}

async function toMarkdown(x) {
  const v = versionInfo(version()).short;
  const q = async ref => (await quote(ref)).map(p => `> **${p.ref}** — ${p.text} (${v})`).join('\n>\n');
  const out = [`# ${x.title || 'Sermon'}`, ''];
  if (x.text) out.push(`**Texte :** ${x.text}`, '', await q(x.text), '');
  if (x.bigIdea) out.push(`**Idée principale :** ${x.bigIdea}`, '');
  if (x.intro) out.push('## Introduction', '', x.intro, '');
  for (const [i, p] of x.points.entries()) {
    out.push(`## ${i + 1}. ${p.title || ''}`, '');
    if (p.refs) out.push(await q(p.refs), '');
    if (p.body) out.push(p.body, '');
  }
  for (const [k, l] of [['illustration', 'Illustrations'], ['application', 'Application'], ['conclusion', 'Conclusion & appel'], ['prayer', 'Prière']]) {
    if (x[k]) out.push(`## ${l}`, '', x[k], '');
  }
  out.push('---', '_Préparé avec Mister Preacher_');
  return out.join('\n');
}

async function renderPreview(el, x) {
  const block = async ref => refPreviewHTML(await quote(ref));
  const para = t => esc(t).replace(/\n/g, '<br>');
  el.innerHTML = `<div class="page"><div class="toolbar"><a class="btn sm" href="#/sermon/${x.id}">← Modifier</a><span class="grow"></span><button class="btn sm primary" onclick="print()">Imprimer</button></div>
    <article class="card scripture-doc">
      <h1>${esc(x.title || 'Sermon')}</h1>
      ${x.text ? `<p><b>Texte :</b> ${esc(x.text)}</p>${await block(x.text)}` : ''}
      ${x.bigIdea ? `<p style="margin-top:12px"><b>Idée principale :</b> <i>${esc(x.bigIdea)}</i></p>` : ''}
      ${x.intro ? `<h2>Introduction</h2><p>${para(x.intro)}</p>` : ''}
      ${(await Promise.all(x.points.map(async (p, i) => `<h2>${i + 1}. ${esc(p.title)}</h2>${p.refs ? await block(p.refs) : ''}${p.body ? `<p>${para(p.body)}</p>` : ''}`))).join('')}
      ${[['illustration', 'Illustrations'], ['application', 'Application'], ['conclusion', 'Conclusion & appel'], ['prayer', 'Prière']]
        .filter(([k]) => x[k]).map(([k, l]) => `<h2>${l}</h2><p>${para(x[k])}</p>`).join('')}
      <p class="muted small">Version : ${esc(versionInfo(version()).name)}</p>
    </article></div>`;

}
