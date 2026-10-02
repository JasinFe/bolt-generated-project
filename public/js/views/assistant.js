import { $, $$, api, esc, md, store, uid, toast, version, versionInfo, icon, download, copy } from '../core.js';
import { go } from '../app.js';

const MODES = [
  ['expliquer', 'Expliquer le passage', 'Contexte, sens, mots originaux, liens et applications'],
  ['sermon', 'Plan de prédication', 'Titre, idée principale, 3 points, illustration, appel'],
  ['etude', 'Étude de groupe', 'Questions d’observation, d’interprétation et d’application'],
  ['illustrations', 'Illustrations', '5 illustrations pour prêcher le passage'],
  ['chant', 'Écrire un chant', 'Thème, images bibliques, refrain et couplet'],
  ['evangelisation', 'Évangélisation', 'Comment annoncer l’Évangile avec ce texte'],
  ['personnage', 'Personnages', 'Qui sont-ils, que nous apprennent-ils ?'],
  ['libre', 'Question libre', 'Posez votre propre question'],
];

/** Assistant d'étude : #/assistant?ref=Jean 3:16&mode=expliquer */
export async function render(el, { params }) {
  const status = await api('assistant/status').catch(() => ({ enabled: false }));
  const ref = params.get('ref') || '';
  let mode = params.get('mode') || 'expliquer';
  const history = [];
  el.innerHTML = `<div class="page">
    <div class="page-head"><div class="grow"><div class="eyebrow">Intelligence artificielle</div><h1>Assistant d’étude</h1>
      <p>Il lit le passage dans votre version, la Darby littérale, les références croisées et les mots hébreux ou grecs — puis vous aide à comprendre, prêcher, enseigner ou écrire.</p></div></div>
    ${status.enabled ? '' : `<div class="card" style="border-color:var(--gold)"><h3>Activer l’assistant</h3>
      <p>L’assistant utilise Claude (Anthropic). Créez une clé sur <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>,
      ajoutez la ligne <code>ANTHROPIC_API_KEY=votre-cle</code> dans le fichier <code>.env</code> du dossier Mister Preacher, puis relancez l’application.
      Chaque réponse est facturée par Anthropic selon votre usage (quelques centimes).</p></div>`}
    <form class="card" id="aForm">
      <div class="editor-section"><label>Passage</label>
        <input type="text" id="aRef" value="${esc(ref)}" style="width:100%" placeholder="Ex. : Jean 3:1-21, Psaumes 23, Romains 8:28-39 (facultatif pour une question libre)"></div>
      <div class="editor-section"><label>Que voulez-vous faire ?</label>
        <div class="ver-list">${MODES.map(([k, t, d]) => `<label class="ver-item ${k === mode ? 'on' : ''}"><input type="radio" name="mode" value="${k}" ${k === mode ? 'checked' : ''}><span><b>${t}</b><span>${d}</span></span></label>`).join('')}</div></div>
      <div class="editor-section"><label>Précision ou question <span class="muted small">(facultatif)</span></label>
        <textarea id="aQ" placeholder="Ex. : pour un culte de jeunes ; pour une veillée de prière ; que signifie « naître d’en haut » ?"></textarea></div>
      <div class="row"><button class="btn primary" ${status.enabled ? '' : 'disabled'}>${icon('star')} Demander</button>
        <span class="muted small">Version utilisée : ${esc(versionInfo(version()).name)}</span></div>
    </form>
    <div id="aOut"></div>
  </div>`;
  $$('input[name=mode]', el).forEach(r => r.onchange = () => {
    mode = r.value;
    $$('.ver-item', el).forEach(i => i.classList.toggle('on', i.querySelector('input').checked));
  });
  $('#aForm', el).onsubmit = async e => {
    e.preventDefault();
    const q = $('#aQ', el).value.trim();
    const r = $('#aRef', el).value.trim();
    if (!r && !q) return toast('Indiquez un passage ou une question');
    await ask({ mode, ref: r, question: q });
  };

  async function ask(payload) {
    const box = document.createElement('div');
    box.className = 'card answer';
    box.innerHTML = `<div class="row"><h3 class="grow">${esc(MODES.find(m => m[0] === payload.mode)?.[1] || 'Réponse')}${payload.ref ? ` · ${esc(payload.ref)}` : ''}</h3></div>
      <div class="md loading">L’assistant réfléchit…</div>`;
    $('#aOut', el).prepend(box);
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const out = box.querySelector('.md');
    let text = '';
    try {
      const res = await fetch('/api/assistant', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, version: version(), history }),
      });
      const reader = res.body.getReader();
      const dec = new TextDecoder();
      let buf = '';
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += dec.decode(value, { stream: true });
        let i;
        while ((i = buf.indexOf('\n\n')) >= 0) {
          const chunk = buf.slice(0, i);
          buf = buf.slice(i + 2);
          const ev = (chunk.match(/^event: (.*)$/m) || [])[1];
          const data = JSON.parse((chunk.match(/^data: (.*)$/m) || [])[1] || 'null');
          if (ev === 'text') { text += data; out.classList.remove('loading'); out.innerHTML = md(text); }
          if (ev === 'error') throw new Error(data.message);
        }
      }
    } catch (err) {
      out.classList.remove('loading');
      out.innerHTML = `<div class="error">${esc(err.message)}</div>`;
      return;
    }
    history.push({ role: 'user', content: `${payload.mode} ${payload.ref} ${payload.question || ''}`.trim() }, { role: 'assistant', content: text });
    const actions = document.createElement('div');
    actions.className = 'row no-print';
    actions.style.marginTop = '12px';
    actions.innerHTML = `<button class="btn sm" data-a="copy">Copier</button><button class="btn sm" data-a="study">${icon('pen')} Enregistrer dans une étude</button>
      ${payload.mode === 'sermon' ? `<button class="btn sm" data-a="sermon">${icon('mic')} Ouvrir comme sermon</button>` : ''}
      <button class="btn sm" data-a="md">Exporter</button>
      <form class="row grow" data-a="follow"><input type="text" class="grow" placeholder="Poser une question de suivi…"><button class="btn sm primary">Envoyer</button></form>`;
    box.appendChild(actions);
    actions.querySelector('[data-a=copy]').onclick = () => copy(text);
    actions.querySelector('[data-a=md]').onclick = () => download(`assistant-${(payload.ref || 'question').replace(/[^\p{L}\p{N}]+/gu, '-')}.md`, text);
    actions.querySelector('[data-a=study]').onclick = () => {
      const s = store.get();
      const st = { id: uid(), title: `${payload.ref || 'Question'} — assistant`, description: text, items: payload.ref ? [{ id: uid(), ref: payload.ref, note: '' }] : [], created: Date.now() };
      s.studies.unshift(st);
      store.save();
      toast('Étude créée');
    };
    const sermonBtn = actions.querySelector('[data-a=sermon]');
    if (sermonBtn) sermonBtn.onclick = () => {
      const s = store.get();
      const title = (text.match(/^#+\s*(?:Titre\s*:?\s*)?(.+)$/m) || [])[1] || `Sermon sur ${payload.ref}`;
      const sermon = { id: uid(), title: title.replace(/[*«»]/g, '').trim(), text: payload.ref, template: 'expositif', bigIdea: '', intro: text, points: [], illustration: '', application: '', conclusion: '', prayer: '', created: Date.now() };
      s.sermons.unshift(sermon);
      store.save();
      go(`#/sermon/${sermon.id}`);
    };
    actions.querySelector('[data-a=follow]').onsubmit = ev => {
      ev.preventDefault();
      const input = ev.target.querySelector('input');
      if (!input.value.trim()) return;
      ask({ mode: 'libre', ref: payload.ref, question: input.value.trim() });
      input.value = '';
    };
  }
  if (ref && params.get('go') === '1' && status.enabled) ask({ mode, ref, question: '' });
}
