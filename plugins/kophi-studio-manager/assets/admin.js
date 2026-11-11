
/* KOPHI'S MUSIC STUDIO v4.0 — Admin JS */
'use strict';
const KOPHI = window.KOPHI || {};
const REST  = KOPHI.rest_url || '/wp-json/kophi/v1/';
const NONCE = KOPHI.nonce  || '';
const MOIS  = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// ── API Helpers ─────────────────────────────────────────────────
async function kGet(ep, p={}) {
  const qs = new URLSearchParams(p).toString();
  const r = await fetch(REST+ep+(qs?'?'+qs:''), {headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'}});
  return r.ok ? r.json() : null;
}
async function kPost(ep, d={}) {
  const r = await fetch(REST+ep, {method:'POST', headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'}, body:JSON.stringify(d)});
  return r.ok ? r.json() : null;
}
async function kPut(ep, d={}) {
  const r = await fetch(REST+ep, {method:'PUT', headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'}, body:JSON.stringify(d)});
  return r.ok ? r.json() : null;
}
async function kDelete(ep) {
  const r = await fetch(REST+ep, {method:'DELETE', headers:{'X-WP-Nonce':NONCE}});
  return r.ok ? r.json() : null;
}

// ── Toast ───────────────────────────────────────────────────────
function toast(msg, type='info', dur=3500) {
  let el = document.getElementById('kophi-toast');
  if (!el) { el=document.createElement('div'); el.id='kophi-toast'; el.className='kophi-toast'; document.body.appendChild(el); }
  el.textContent = msg;
  el.className = `kophi-toast ${type} show`;
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.remove('show'), dur);
}

// ── Modal helpers ────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

// ── Formatters ───────────────────────────────────────────────────
function fDate(ds) { if(!ds) return '—'; const [y,m,d]=ds.split('-'); return `${d}/${m}/${y}`; }
function fMontant(m) { return m ? parseInt(m).toLocaleString('fr-FR')+' F' : '—'; }
function fDuree(h) { return h ? h+'h' : '—'; }
function escHtml(s) { const d=document.createElement('div'); d.appendChild(document.createTextNode(s||'')); return d.innerHTML; }

// ── Smart Payment Center — actions admin ─────────────────────────
// Confirme un paiement déclaré par le client (Wave/Orange Money/MTN/Moov) : émet la
// licence si c'est un achat de beat, notifie le client. Adapté du même mécanisme que
// KM Family (label KOPHI'S MUSIC).
async function kophiConfirmerPaiement(ref, btn) {
  if (!ref) return;
  if (!confirm('Confirmer ce paiement ? La licence sera émise et le client notifié.')) return;
  if (btn) { btn.disabled = true; btn.textContent = '⏳...'; }
  const res = await kPost(`admin/paiement/${ref}/confirm`, {});
  if (res && res.ok) {
    toast('Paiement confirmé.', 'success');
    setTimeout(() => window.location.reload(), 800);
  } else {
    toast('Erreur lors de la confirmation.', 'error');
    if (btn) { btn.disabled = false; btn.textContent = '✅ Confirmer paiement'; }
  }
}

// Génère (ou récupère) le lien de paiement d'une commande existante — pour tout
// paiement "autre" qu'un achat de beat (acompte session, mix & mastering, beat
// custom...) une fois le prix final fixé par l'équipe.
async function kophiGenererLienPaiement(commandeId, montant) {
  const m = montant || prompt('Montant à facturer (FCFA) :');
  if (!m || isNaN(parseFloat(m))) return;
  const res = await kPost('admin/paiement/generer', { commande_id: commandeId, montant: parseFloat(m) });
  if (res && res.payment_url) {
    navigator.clipboard.writeText(res.payment_url);
    toast('Lien de paiement copié : ' + res.payment_url, 'success', 6000);
  } else {
    toast('Erreur lors de la génération du lien.', 'error');
  }
}

// ── CHRONOMÈTRE LIVE ────────────────────────────────────────────
const Timers = {};  // { session_id: { start_ts, duree_sec, el_display, el_bar } }

function startTimer(id, startTs, dureeSec, elDisplay, elBar) {
  if (Timers[id]) clearInterval(Timers[id].interval);
  
  function fmt(sec) {
    sec = Math.abs(Math.floor(sec));
    const h = Math.floor(sec/3600);
    const m = Math.floor((sec%3600)/60);
    const s = sec%60;
    return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
  }
  
  const tick = () => {
    const nowSec  = Date.now() / 1000;
    const elapsed = Math.max(0, Math.floor(nowSec - startTs));
    
    // CHRONOMÈTRE : toujours afficher le temps écoulé depuis le début
    if (elDisplay) {
      elDisplay.textContent = fmt(elapsed);
      // Couleur : vert jusqu'à durée prévue, orange si dépassé
      if (dureeSec > 0 && elapsed > dureeSec) {
        elDisplay.className = 'kophi-timer-display warning';
      } else {
        elDisplay.className = 'kophi-timer-display ok';
      }
    }
    
    // Barre de progression : % de la durée prévue écoulé
    if (elBar && dureeSec > 0) {
      const pct = Math.max(0, Math.min(100, (elapsed / dureeSec) * 100));
      elBar.style.width = pct + '%';
      elBar.style.background = pct > 100 ? 'linear-gradient(90deg,#FF4757,#FF8C42)'
                              : pct > 80  ? 'linear-gradient(90deg,#FF8C42,#E8B84B)'
                              : 'linear-gradient(90deg,#00C87A,#8B3FD9)';
    }
  };
  
  tick(); // Premier tick immédiat
  Timers[id] = { interval: setInterval(tick, 1000), startTs, dureeSec };
}

function stopTimer(id) {
  if (Timers[id]) { clearInterval(Timers[id].interval); delete Timers[id]; }
}

// Initialiser tous les timers présents dans la page
function initAllTimers() {
  document.querySelectorAll('[data-timer-id]').forEach(el => {
    const id       = el.dataset.timerId;
    const startTs  = parseInt(el.dataset.timerStart || 0);
    const dureeSec = parseInt(el.dataset.timerDuree || 0) * 3600;
    const display  = el.querySelector('.kophi-timer-display');
    const bar      = el.querySelector('.kophi-timer-bar');
    if (startTs) startTimer(id, startTs, dureeSec, display, bar);
  });
}

// ── Calendrier Planning ──────────────────────────────────────────
let calState = { year: new Date().getFullYear(), month: new Date().getMonth(), data: {} };

async function loadCalendar(service='voix', forfait='50000') {
  const moisStr = calState.year+'-'+String(calState.month+1).padStart(2,'0');
  const titleEl = document.getElementById('kophi-cal-title');
  if (titleEl) titleEl.textContent = MOIS[calState.month]+' '+calState.year;
  try {
    const data = await kGet('disponibilite/mois', {mois:moisStr, service, forfait});
    calState.data = data || {};
  } catch { calState.data = {}; }
  renderCalendar();
}

function renderCalendar() {
  const grid = document.getElementById('kophi-cal-grid');
  if (!grid) return;
  grid.innerHTML = '';
  const today = new Date().toISOString().split('T')[0];
  const hier  = new Date(Date.now()-86400000).toISOString().split('T')[0];
  const fd    = new Date(calState.year, calState.month, 1).getDay();
  const off   = fd===0?6:fd-1;
  const days  = new Date(calState.year, calState.month+1, 0).getDate();
  const labels = {libre:'LIBRE',partiel:'◑',bloque:'🔒',ferme:'',passe:''};
  for (let i=0;i<off;i++) grid.insertAdjacentHTML('beforeend','<div></div>');
  for (let d=1;d<=days;d++) {
    const ds = `${calState.year}-${String(calState.month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const isPast = ds<=hier;
    const st  = isPast?'passe':(calState.data[ds]||'libre');
    const sel = window._selectedDate===ds?'style="outline:2px solid #E8B84B;outline-offset:2px"':'';
    const click = !['bloque','ferme','passe'].includes(st)?`onclick="dayClick('${ds}')"` : '';
    grid.insertAdjacentHTML('beforeend',`
      <div class="kophi-cal-day ${st} ${ds===today?'today':''}" ${click} title="${ds}" ${sel}>
        <span class="dn">${d}</span>
        <span class="ds">${labels[st]||''}</span>
      </div>`);
  }
}

function calPrev() { calState.month--; if(calState.month<0){calState.month=11;calState.year--;} loadCalendar(); }
function calNext() { calState.month++; if(calState.month>11){calState.month=0;calState.year++;} loadCalendar(); }

function dayClick(ds) {
  window._selectedDate = ds;
  renderCalendar();
  // Remplir les inputs date si présents
  ['b-debut','b-fin','s-date'].forEach(id => { const el=document.getElementById(id); if(el) el.value=ds; });
  const panel = document.getElementById('kophi-creneaux-wrap');
  if (panel) { panel.style.display='block'; loadCreneaux(ds); }
  const label = document.getElementById('creneaux-date-label');
  if (label) label.textContent = 'Créneaux du '+ds.split('-').reverse().join('/');
}

// ── Créneaux ────────────────────────────────────────────────────
async function loadCreneaux(date, service='voix', forfait='50000') {
  const grid = document.getElementById('kophi-creneaux-grid');
  if (!grid) return;
  grid.innerHTML = '<div style="color:var(--k-text3);font-size:12px;padding:10px">Chargement...</div>';
  const data = await kGet('disponibilite', {date, service, forfait});
  if (!data?.ouvert) { grid.innerHTML='<div style="color:var(--k-text4);font-size:12px;padding:10px">Studio fermé ce jour</div>'; return; }
  window._creneaux = data.creneaux||[];
  renderCreneaux();
}

function renderCreneaux() {
  const grid = document.getElementById('kophi-creneaux-grid');
  if (!grid||!window._creneaux?.length) return;
  const style = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(72px,1fr));gap:6px;margin-top:12px';
  grid.innerHTML = `<div style="${style}">`+window._creneaux.map(c => {
    const sel = window._selectedCreneau===c.heure?'style="outline:2px solid #fff"':'';
    const cls = c.disponible?'kophi-btn kophi-btn-green kophi-btn-sm':'kophi-btn kophi-btn-sm" style="opacity:.4;cursor:not-allowed';
    const action = c.disponible?`onclick="pickCreneau('${c.heure}','${c.heure_fin}')"`:c.conflicts?.[0]?`onclick="alert('${c.conflicts[0].message}')"` :'';
    return `<button class="${cls}" ${action} ${sel}><span style="font-size:13px;font-family:Bebas Neue,sans-serif;letter-spacing:1px">${c.heure}</span></button>`;
  }).join('')+'</div>';
}

function pickCreneau(h, hf) {
  window._selectedCreneau = h;
  const inputs = ['s-heure','b-heure-debut'];
  inputs.forEach(id => { const el=document.getElementById(id); if(el) el.value=h; });
  renderCreneaux();
  toast(`Créneau ${h} → ${hf} sélectionné`, 'success');
}

// ── Blocages ─────────────────────────────────────────────────────
async function saveBlocage() {
  const titre = document.getElementById('b-titre')?.value?.trim();
  const debut = document.getElementById('b-debut')?.value;
  const fin   = document.getElementById('b-fin')?.value;
  if (!titre||!debut||!fin) { toast('Titre et dates obligatoires','error'); return; }
  const isJour = document.getElementById('b-type')?.value==='journee';
  const data = {
    titre, date_debut:debut, date_fin:fin,
    journee_complete: isJour?1:0,
    heure_debut: isJour?null:document.getElementById('b-heure-debut')?.value,
    heure_fin:   isJour?null:document.getElementById('b-heure-fin')?.value,
    motif:       document.getElementById('b-motif')?.value||'autre',
    description: document.getElementById('b-desc')?.value||'',
  };
  const r = await kPost('blocages', data);
  if (r?.success||r?.id) {
    toast('Blocage créé ✓','success');
    closeModal('modal-blocage');
    loadCalendar(); loadBlocages();
    ['b-titre','b-debut','b-fin','b-desc'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
  } else { toast(r?.message||'Erreur','error'); }
}

async function deleteBlocage(id) {
  if (!confirm('Supprimer ce blocage ?')) return;
  await kDelete('blocages/'+id);
  toast('Blocage supprimé','info');
  loadCalendar(); loadBlocages();
}

async function loadBlocages() {
  const list = document.getElementById('kophi-blocages-list');
  if (!list) return;
  const today = new Date().toISOString().split('T')[0];
  const fin   = new Date(Date.now()+7776000000).toISOString().split('T')[0];
  const data  = await kGet('blocages', {from:today,to:fin});
  if (!data?.length) { list.innerHTML='<div class="kophi-empty"><div class="kophi-empty-icon">🔒</div><p>Aucun blocage actif</p></div>'; return; }
  list.innerHTML = data.map(b => `
    <div class="kophi-daw-card" style="margin-bottom:8px">
      <div class="kophi-daw-icon">${b.journee_complete?'🔒':'⏰'}</div>
      <div class="kophi-daw-info">
        <div class="kophi-daw-name">${escHtml(b.titre)}</div>
        <div class="kophi-daw-meta">📅 ${b.date_debut} → ${b.date_fin}${b.heure_debut?' · '+b.heure_debut.slice(0,5)+' – '+b.heure_fin.slice(0,5):''} ${b.journee_complete?'<span class="kophi-badge interne" style="font-size:8px">Journée complète</span>':''}</div>
      </div>
      <button class="kophi-btn kophi-btn-red kophi-btn-sm" onclick="deleteBlocage(${b.id})">✕</button>
    </div>`).join('');
}

function toggleBlocageType() {
  const t = document.getElementById('b-type')?.value;
  const hw = document.getElementById('b-heure-wrap');
  if (hw) hw.style.display = t==='creneau'?'grid':'none';
}

// ── Init ─────────────────────────────────────────────────────────
function kophiAdminInit() {
  if (document.getElementById('kophi-cal-grid')) loadCalendar();
  if (document.getElementById('kophi-blocages-list')) loadBlocages();
  initAllTimers();
  document.querySelectorAll('.kophi-modal-bg').forEach(bg => {
    bg.addEventListener('click', e => { if(e.target===bg) bg.classList.remove('open'); });
  });
  // Rafraichir les timers toutes les 30s
  setInterval(initAllTimers, 30000);
  window.kophi_toast = toast;
  window.kGet=kGet; window.kPost=kPost; window.kPut=kPut; window.kDelete=kDelete;
  window.openModal=openModal; window.closeModal=closeModal;
}

// Appel immédiat si DOM déjà prêt (script chargé en footer), sinon attendre
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', kophiAdminInit);
} else {
  kophiAdminInit();
}
