import { $, $$, esc, store, toast, versions, versionInfo, books, icon } from '../core.js';
import { go } from '../app.js';

/** Répartit n éléments sur d jours, de façon régulière. */
const split = (items, days) => Array.from({ length: days }, (_, i) => items.slice(Math.floor(i * items.length / days), Math.floor((i + 1) * items.length / days)));

/** [["Gen",1],["Gen",2]…] -> "Genèse 1-2" */
function label(chapters, names) {
  const out = [];
  for (const [b, c] of chapters) {
    const last = out[out.length - 1];
    if (last && last.b === b && last.to === c - 1) last.to = c;
    else out.push({ b, from: c, to: c });
  }
  return out.map(x => `${names[x.b]} ${x.from}${x.to > x.from ? '-' + x.to : ''}`);
}

async function buildPlans() {
  await versions();
  const all = await books();
  const counts = versionInfo('LSG').books;
  const names = Object.fromEntries(all.map(b => [b.id, b.fr]));
  const canon = all.filter(b => (b.cat === 'AT' || b.cat === 'NT') && counts[b.id]);
  const ch = filter => canon.filter(filter).flatMap(b => Array.from({ length: counts[b.id] }, (_, i) => [b.id, i + 1]));
  const at = ch(b => b.cat === 'AT');
  const nt = ch(b => b.cat === 'NT');
  const gospels = ch(b => ['Matt', 'Mark', 'Luke', 'John'].includes(b.id));
  const mk = (id, title, desc, days) => ({ id, title, desc, days: days.map(d => label(d, names)) });
  const atDays = split(at, 365);
  const ntDays = split(nt, 365);
  return [
    mk('bible-1an', 'La Bible en un an', 'Chaque jour, un passage de l’Ancien et un du Nouveau Testament.', atDays.map((d, i) => [...d, ...ntDays[i]])),
    mk('bible-ordre', 'La Bible dans l’ordre, en un an', 'De la Genèse à l’Apocalypse, environ 3 chapitres par jour.', split([...at, ...nt], 365)),
    mk('nt-90', 'Le Nouveau Testament en 90 jours', 'Environ 3 chapitres par jour.', split(nt, 90)),
    mk('evangiles-40', 'Les quatre Évangiles en 40 jours', 'Marcher avec Jésus de Matthieu à Jean.', split(gospels, 40)),
    { id: 'ps-pr-31', title: 'Psaumes et Proverbes en un mois', desc: 'Chaque jour : 5 psaumes et 1 chapitre des Proverbes.',
      days: Array.from({ length: 31 }, (_, d) => [`Proverbes ${d + 1}`, ...[0, 30, 60, 90, 120].map(k => d + 1 + k).filter(n => n <= 150).map(n => `Psaumes ${n}`)]) },
  ];
}

const today = () => new Date().toISOString().slice(0, 10);
const dayIndex = start => Math.floor((Date.parse(today()) - Date.parse(start)) / 86400000);

/** Plans de lecture : #/plans et #/plans/bible-1an */
export async function render(el, { args }) {
  const plans = await buildPlans();
  const s = store.get();
  s.plans = s.plans || {};
  if (!args[0]) {
    el.innerHTML = `<div class="page">
      <div class="page-head"><div class="grow"><div class="eyebrow">Discipline</div><h1>Plans de lecture</h1>
        <p>Lisez toute la Bible avec régularité : un programme quotidien, votre progression enregistrée, une lecture en un clic.</p></div></div>
      <div class="grid">${plans.map(p => {
        const st = s.plans[p.id];
        const pct = st ? Math.round(st.done.length / p.days.length * 100) : 0;
        return `<a class="card tile" href="#/plans/${p.id}"><span class="tile-ic">${icon('book')}</span><h3>${esc(p.title)}</h3><p>${esc(p.desc)}</p>
          <p class="small"><b>${p.days.length} jours</b>${st ? ` · en cours : ${pct} %` : ''}</p>
          ${st ? `<div class="bar" style="grid-template-columns:1fr"><span class="track"><span class="fill" style="width:${pct}%"></span></span></div>` : ''}</a>`;
      }).join('')}</div></div>`;
    return;
  }
  const p = plans.find(x => x.id === args[0]);
  if (!p) return go('#/plans');
  const st = s.plans[p.id];
  if (!st) {
    el.innerHTML = `<div class="page reading"><div class="page-head"><div class="grow"><div class="eyebrow"><a href="#/plans">Plans de lecture</a></div><h1>${esc(p.title)}</h1><p>${esc(p.desc)}</p></div></div>
      <div class="card"><p>${p.days.length} jours. Premier jour : <b>${esc(p.days[0].join(' ; '))}</b></p>
        <button class="btn primary" id="startPlan">Commencer aujourd’hui</button></div>
      <div class="card"><h3>Aperçu</h3>${p.days.slice(0, 7).map((d, i) => `<div class="list-item"><b style="width:70px">Jour ${i + 1}</b><span>${esc(d.join(' ; '))}</span></div>`).join('')}</div></div>`;
    $('#startPlan', el).onclick = () => { s.plans[p.id] = { start: today(), done: [] }; store.save(); toast('Plan commencé'); render(el, { args }); };
    return;
  }
  const done = new Set(st.done);
  const current = Math.min(p.days.length - 1, Math.max(0, dayIndex(st.start)));
  const nextUndone = p.days.findIndex((_, i) => !done.has(i));
  // Jour affiché : celui d'aujourd'hui, ou un jour choisi dans le calendrier (?jour=N)
  const asked = +new URLSearchParams(location.hash.split('?')[1] || '').get('jour');
  const focus = asked > 0 && asked <= p.days.length ? asked - 1 : current;
  const late = Array.from({ length: current }, (_, i) => i).filter(i => !done.has(i)).length;
  el.innerHTML = `<div class="page">
    <div class="page-head"><div class="grow"><div class="eyebrow"><a href="#/plans">Plans de lecture</a> · commencé le ${new Date(st.start).toLocaleDateString('fr-FR')}</div><h1>${esc(p.title)}</h1>
      <p>${done.size} jour(s) lu(s) sur ${p.days.length} · ${Math.round(done.size / p.days.length * 100)} %${late > 0 ? ` · ${late} jour(s) de retard, rien de grave : reprenez simplement là où vous en êtes.` : ''}</p></div>
      <button class="btn sm ghost" id="stopPlan">Arrêter ce plan</button></div>
    <div class="card votd"><div class="eyebrow">Jour ${focus + 1}${focus === current ? ' · aujourd’hui' : ''}</div>
      <div class="row" style="margin:8px 0">${p.days[focus].map(r => `<a class="btn" href="#/passage?ref=${encodeURIComponent(r)}">${icon('book')} ${esc(r)}</a>`).join('')}</div>
      <label class="row"><input type="checkbox" id="doneToday" ${done.has(focus) ? 'checked' : ''}> Lecture du jour ${focus + 1} terminée</label>
      ${done.has(focus) && nextUndone >= 0 && nextUndone !== focus ? `<p class="small" style="margin-top:8px">Bravo ! <a href="#/plans/${p.id}?jour=${nextUndone + 1}">Lire le jour ${nextUndone + 1}</a> (en avance ou en rattrapage).</p>` : ''}</div>
    <div class="card"><h3>Calendrier</h3><div class="chap-grid" id="cal">${p.days.map((d, i) => `<button title="${esc(`Jour ${i + 1} : ${d.join(' ; ')}`)}" data-day="${i}" class="${done.has(i) ? 'on' : ''}" style="${i === current ? 'outline:2px solid var(--gold)' : ''}">${i + 1}</button>`).join('')}</div>
      <p class="muted small">Clic : ouvrir ce jour · double-clic : marquer comme lu ou non lu.</p></div></div>`;
  const save = () => { st.done = [...done].sort((a, b) => a - b); store.save(); };
  $('#doneToday', el).onchange = e => { e.target.checked ? done.add(focus) : done.delete(focus); save(); if (e.target.checked) toast('Bravo, lecture enregistrée !'); render(el, { args, params: new URLSearchParams() }); };
  $$('[data-day]', el).forEach(b => {
    b.onclick = () => go(`#/plans/${p.id}?jour=${+b.dataset.day + 1}`);
    b.ondblclick = () => { const i = +b.dataset.day; done.has(i) ? done.delete(i) : done.add(i); save(); b.classList.toggle('on'); };
  });
  $('#stopPlan', el).onclick = () => { if (confirm('Arrêter ce plan et effacer sa progression ?')) { delete s.plans[p.id]; store.save(); go('#/plans'); } };
}
