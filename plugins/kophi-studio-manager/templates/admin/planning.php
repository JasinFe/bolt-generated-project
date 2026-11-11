<?php
/**
 * KOPHI'S MUSIC STUDIO — Planning & Disponibilités
 * Reconstruction complète — Architecture 100% inline PHP→JS sans dépendance externe
 */
if (!defined('ABSPATH')) exit;

// Injecter toutes les données PHP directement en JSON dans la page
// Évite tout problème AJAX/nonce/Multisite
$_today       = date('Y-m-d');
$_year        = (int)date('Y');
$_month       = (int)date('n') - 1; // 0-based pour JS
$_ajax_url    = admin_url('admin-ajax.php');
$_nonce       = wp_create_nonce('kophi_planning_nonce');
$_rest_url    = get_site_url(get_current_blog_id()) . '/wp-json/kophi/v1/';

// Pré-charger les données du mois courant directement en PHP
global $wpdb;
$mois_debut = date('Y-m-01');
$mois_fin   = date('Y-m-t');

// Blocages
$blocages_raw = [];
$tbl = $wpdb->prefix . 'kophi_blocages';
if ($wpdb->get_var("SHOW TABLES LIKE '$tbl'") === $tbl) {
    $blocages_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT date_debut, date_fin, heure_debut, heure_fin, journee_complete, titre, motif FROM $tbl WHERE date_debut <= %s AND date_fin >= %s",
        $mois_fin, $mois_debut
    )) ?: [];
}

// Sessions du mois
$sessions_raw = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[
    ['key'=>'_kophi_date_session','value'=>[$mois_debut,$mois_fin],'compare'=>'BETWEEN','type'=>'DATE'],
    ['key'=>'_kophi_statut','value'=>['scheduled','live'],'compare'=>'IN'],
]]);

// Réservations du mois
$resas_raw = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>-1,'meta_query'=>[
    ['key'=>'_kophi_date_souhaitee','value'=>[$mois_debut,$mois_fin],'compare'=>'BETWEEN','type'=>'DATE'],
    ['key'=>'_kophi_statut','value'=>['pending','confirmed'],'compare'=>'IN'],
]]);

// Construire index par date
$occ_par_date = [];
foreach ($blocages_raw as $b) {
    for ($ts = strtotime($b->date_debut); $ts <= strtotime($b->date_fin); $ts += 86400) {
        $ds = date('Y-m-d', $ts);
        $occ_par_date[$ds][] = $b->journee_complete ? 'bloque' : 'partiel';
    }
}
foreach ($sessions_raw as $s) {
    $d = get_post_meta($s->ID,'_kophi_date_session',true);
    if ($d) $occ_par_date[$d][] = 'session';
}
foreach ($resas_raw as $r) {
    $d = get_post_meta($r->ID,'_kophi_date_souhaitee',true);
    if ($d) $occ_par_date[$d][] = 'resa';
}

// Calculer statut de chaque jour du mois
$cal_data = [];
$days_in_month = (int)date('t');
for ($d = 1; $d <= $days_in_month; $d++) {
    $ds  = date('Y-m-d', mktime(0,0,0,date('n'),$d,$_year));
    if ($ds < $_today) { $cal_data[$ds] = 'passe'; continue; }
    $dow = (int)date('w', strtotime($ds));
    if ($dow === 0) { $cal_data[$ds] = 'ferme'; continue; } // Dimanche fermé
    $occ = $occ_par_date[$ds] ?? [];
    if (in_array('bloque', $occ)) { $cal_data[$ds] = 'bloque'; }
    elseif (empty($occ))          { $cal_data[$ds] = 'libre'; }
    elseif (count($occ) >= 3)     { $cal_data[$ds] = 'bloque'; }
    else                          { $cal_data[$ds] = 'partiel'; }
}

// Blocages actifs pour la liste
$blocages_liste = [];
if ($wpdb->get_var("SHOW TABLES LIKE '$tbl'") === $tbl) {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, titre, date_debut, date_fin, heure_debut, heure_fin, journee_complete, motif FROM $tbl WHERE date_fin >= %s ORDER BY date_debut ASC LIMIT 20",
        $_today
    ), ARRAY_A) ?: [];
    $blocages_liste = $rows;
}

// Sessions planifiées
$sessions_liste = [];
foreach (get_posts(['post_type'=>'kophi_session','posts_per_page'=>20,'meta_query'=>[
    ['key'=>'_kophi_date_session','value'=>[$_today, date('Y-m-d', strtotime('+60 days'))],'compare'=>'BETWEEN','type'=>'DATE'],
    ['key'=>'_kophi_statut','value'=>['scheduled','live'],'compare'=>'IN'],
],'orderby'=>'meta_value','meta_key'=>'_kophi_date_session','order'=>'ASC']) as $s) {
    $sessions_liste[] = [
        'id'     => $s->ID,
        'type'   => 'session',
        'titre'  => get_post_meta($s->ID,'_kophi_client_nom',true) ?: $s->post_title,
        'date'   => get_post_meta($s->ID,'_kophi_date_session',true),
        'heure'  => get_post_meta($s->ID,'_kophi_heure_debut',true),
        'duree'  => get_post_meta($s->ID,'_kophi_duree_prevue',true),
        'statut' => get_post_meta($s->ID,'_kophi_statut',true),
    ];
}

// Réservations à venir
$resas_liste = [];
foreach (get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>20,'meta_query'=>[
    ['key'=>'_kophi_date_souhaitee','value'=>[$_today, date('Y-m-d', strtotime('+60 days'))],'compare'=>'BETWEEN','type'=>'DATE'],
    ['key'=>'_kophi_statut','value'=>['pending','confirmed'],'compare'=>'IN'],
],'orderby'=>'meta_value','meta_key'=>'_kophi_date_souhaitee','order'=>'ASC']) as $r) {
    $resas_liste[] = [
        'id'      => $r->ID,
        'type'    => 'reservation',
        'titre'   => get_post_meta($r->ID,'_kophi_nom_artiste',true),
        'date'    => get_post_meta($r->ID,'_kophi_date_souhaitee',true),
        'heure'   => get_post_meta($r->ID,'_kophi_heure_souhaitee',true),
        'service' => get_post_meta($r->ID,'_kophi_service',true),
        'statut'  => get_post_meta($r->ID,'_kophi_statut',true),
        'tel'     => get_post_meta($r->ID,'_kophi_telephone',true),
    ];
}
?>
<style>
@keyframes kp-spin{to{transform:rotate(360deg)}}
</style>

<div class="kophi-wrap"><div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">📅</div>
    <h1>PLANNING & DISPONIBILITÉS</h1>
  </div>
  <div class="kophi-btn-group">
    <button class="kophi-btn kophi-btn-primary" onclick="kpOpenModal('modal-blocage')">🔒 Bloquer un créneau</button>
    <button class="kophi-btn kophi-btn-violet"  onclick="kpOpenModal('modal-session-interne')">🎵 Session Interne</button>
    <button class="kophi-btn kophi-btn-gold"    onclick="kpOpenModal('modal-resa-tel')">📞 Résa Téléphone</button>
  </div>
</div>

<!-- Légende -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;padding:12px 16px;background:var(--k-dark2);border:1px solid var(--k-border);border-radius:10px">
  <?php foreach ([
    ['#00C87A','Libre'], ['#E8B84B','Session client'], ['#C084FC','Blocage partiel'],
    ['#A855F7','Journée bloquée'], ['#FF8C42','Mixte'], ['#FF4757','Fermé'],
  ] as [$color,$label]): ?>
  <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--k-text3)">
    <span style="width:10px;height:10px;border-radius:3px;background:<?= $color ?>;display:inline-block;flex-shrink:0"></span>
    <?= esc_html($label) ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Calendrier -->
<div class="kophi-card" style="padding:0">
  <div class="kophi-cal-header">
    <button class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="kpCalNav(-1)">← Préc.</button>
    <div class="kophi-cal-title" id="kp-cal-title">— —</div>
    <button class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="kpCalNav(1)">Suiv. →</button>
  </div>
  <div class="kophi-cal-dow">
    <span>LUN</span><span>MAR</span><span>MER</span><span>JEU</span><span>VEN</span><span>SAM</span><span>DIM</span>
  </div>
  <div class="kophi-cal-grid" id="kp-cal-grid" style="padding:0 12px 12px">
    <div style="grid-column:1/-1;text-align:center;padding:20px;color:rgba(255,255,255,.4);font-size:12px">Initialisation...</div>
  </div>
</div>

<!-- Grille info -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px">
  <div class="kophi-card">
    <div class="kophi-card-title">🔒 Blocages & Occupations (60 jours)</div>
    <div id="kp-list"></div>
  </div>
  <div class="kophi-card">
    <div class="kophi-card-title">⏰ Créneaux du jour sélectionné</div>
    <div id="kp-creneaux-label" style="font-size:11px;color:var(--k-text3);margin-bottom:10px">Cliquez sur un jour</div>
    <div id="kp-creneaux-grid"></div>
  </div>
</div>

</div></div>

<!-- ══ MODALS ══════════════════════════════════════════════════ -->
<!-- Modal Blocage -->
<div class="kophi-modal-bg" id="modal-blocage" style="display:none">
  <div class="kophi-modal" style="max-width:520px">
    <div class="kophi-modal-title">🔒 Bloquer un créneau
      <button class="kophi-modal-close" onclick="kpCloseModal('modal-blocage')">✕</button>
    </div>
    <div class="kophi-fg"><label>Titre *</label>
      <input id="bl-titre" placeholder="Ex: Séance Label, Maintenance...">
    </div>
    <div class="kophi-fg"><label>Type</label>
      <select id="bl-type" onchange="kpToggleHeures()">
        <option value="creneau">⏰ Créneau horaire</option>
        <option value="journee">📅 Journée complète</option>
      </select>
    </div>
    <div class="kophi-form-row">
      <div class="kophi-fg"><label>Date début *</label>
        <input id="bl-debut" type="date" value="<?= $_today ?>">
      </div>
      <div class="kophi-fg"><label>Date fin *</label>
        <input id="bl-fin" type="date" value="<?= $_today ?>">
      </div>
    </div>
    <div id="bl-heures-wrap" class="kophi-form-row">
      <div class="kophi-fg"><label>Heure début *</label>
        <input id="bl-hdebut" type="time" value="09:00">
      </div>
      <div class="kophi-fg"><label>Heure fin *</label>
        <input id="bl-hfin" type="time" value="13:00">
      </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
      <span style="font-size:9px;color:var(--k-text3);align-self:center">Rapide:</span>
      <button class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="document.getElementById('bl-hdebut').value='09:00';document.getElementById('bl-hfin').value='13:00'">Matin 9–13h</button>
      <button class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="document.getElementById('bl-hdebut').value='14:00';document.getElementById('bl-hfin').value='18:00'">Après-midi 14–18h</button>
      <button class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="document.getElementById('bl-hdebut').value='18:00';document.getElementById('bl-hfin').value='22:00'">Soir 18–22h</button>
    </div>
    <div class="kophi-fg"><label>Motif</label>
      <select id="bl-motif">
        <option value="seance_label">🎵 Séance Label</option>
        <option value="mix_interne">🎚 Mix / Mastering Interne</option>
        <option value="maintenance">🔧 Maintenance Studio</option>
        <option value="conge">😴 Congé / Repos</option>
        <option value="autre">📌 Autre</option>
      </select>
    </div>
    <div class="kophi-fg"><label>Description</label>
      <textarea id="bl-desc" placeholder="Notes..." rows="2"></textarea>
    </div>
    <div id="bl-msg" style="display:none;padding:8px;border-radius:8px;font-size:11px;margin-bottom:8px"></div>
    <div style="display:flex;gap:10px">
      <button class="kophi-btn kophi-btn-primary" id="bl-btn" onclick="kpSaveBlocage()" style="flex:1">🔒 BLOQUER CE CRÉNEAU</button>
      <button class="kophi-btn kophi-btn-outline" onclick="kpCloseModal('modal-blocage')">Annuler</button>
    </div>
  </div>
</div>

<!-- Modal Session Interne -->
<div class="kophi-modal-bg" id="modal-session-interne" style="display:none">
  <div class="kophi-modal" style="max-width:520px">
    <div class="kophi-modal-title">🎵 Session Interne Label
      <button class="kophi-modal-close" onclick="kpCloseModal('modal-session-interne')">✕</button>
    </div>
    <div style="font-size:11px;color:var(--k-text3);margin-bottom:14px;padding:10px;background:rgba(139,63,217,.1);border:1px solid rgba(139,63,217,.25);border-radius:8px">
      Le créneau sera automatiquement bloqué pour les clients.
    </div>
    <div class="kophi-fg"><label>Titre / Projet *</label>
      <input id="si-titre" placeholder="Ex: Mix Gospel, Enregistrement...">
    </div>
    <div class="kophi-fg"><label>Artiste / Membre</label>
      <input id="si-artiste" placeholder="Nom de l'artiste" value="Label - Interne">
    </div>
    <div class="kophi-form-row">
      <div class="kophi-fg"><label>Date *</label>
        <input id="si-date" type="date" value="<?= $_today ?>">
      </div>
      <div class="kophi-fg"><label>Heure *</label>
        <input id="si-heure" type="time" value="10:00">
      </div>
    </div>
    <div class="kophi-form-row">
      <div class="kophi-fg"><label>Durée (h)</label>
        <select id="si-duree">
          <option value="2">2h</option><option value="3">3h</option>
          <option value="4" selected>4h</option><option value="6">6h</option>
          <option value="8">8h</option><option value="12">12h</option>
        </select>
      </div>
      <div class="kophi-fg"><label>DAW</label>
        <select id="si-daw">
          <option value="">— Sélectionner —</option>
          <option>FL Studio</option><option>Fender Studio Pro</option><option>Studio One</option>
        </select>
      </div>
    </div>
    <div class="kophi-fg"><label>Notes</label>
      <textarea id="si-notes" placeholder="Objectifs, détails..." rows="2"></textarea>
    </div>
    <div id="si-msg" style="display:none;padding:8px;border-radius:8px;font-size:11px;margin-bottom:8px"></div>
    <div style="display:flex;gap:10px">
      <button class="kophi-btn kophi-btn-primary" id="si-btn" onclick="kpSaveSession()" style="flex:1">🎵 CRÉER LA SESSION</button>
      <button class="kophi-btn kophi-btn-outline" onclick="kpCloseModal('modal-session-interne')">Annuler</button>
    </div>
  </div>
</div>

<!-- Modal Résa Téléphone -->
<div class="kophi-modal-bg" id="modal-resa-tel" style="display:none">
  <div class="kophi-modal" style="max-width:520px">
    <div class="kophi-modal-title">📞 Réservation Téléphone
      <button class="kophi-modal-close" onclick="kpCloseModal('modal-resa-tel')">✕</button>
    </div>
    <div class="kophi-form-row">
      <div class="kophi-fg"><label>Nom / Artiste *</label>
        <input id="rt-nom" placeholder="Nom ou nom de scène">
      </div>
      <div class="kophi-fg"><label>Téléphone *</label>
        <input id="rt-tel" type="tel" placeholder="+225 07 00 00 00 00">
      </div>
    </div>
    <div class="kophi-fg"><label>Service</label>
      <select id="rt-service">
        <option value="voix">Voix / Mix / Master</option>
        <option value="faceB">Face B — 10 000 F</option>
        <option value="beats">Beat Custom</option>
        <option value="consultation">Consultation / RDV</option>
      </select>
    </div>
    <div class="kophi-fg"><label>Forfait</label>
      <select id="rt-forfait">
        <option value="50000">50 000 F — 4h</option>
        <option value="65000">65 000 F — 6h</option>
        <option value="90000">90 000 F — 8h</option>
        <option value="150000">150 000 F — 12h</option>
        <option value="10000">Face B — 10 000 F</option>
      </select>
    </div>
    <div class="kophi-form-row">
      <div class="kophi-fg"><label>Date *</label>
        <input id="rt-date" type="date" value="<?= $_today ?>">
      </div>
      <div class="kophi-fg"><label>Heure *</label>
        <input id="rt-heure" type="time" value="10:00">
      </div>
    </div>
    <div class="kophi-fg"><label>Statut</label>
      <select id="rt-statut">
        <option value="confirmed">✅ Confirmée (crée la session)</option>
        <option value="pending">⏳ En attente</option>
      </select>
    </div>
    <div class="kophi-fg"><label>Notes</label>
      <textarea id="rt-notes" placeholder="Détails, genre musical..." rows="2"></textarea>
    </div>
    <div id="rt-msg" style="display:none;padding:8px;border-radius:8px;font-size:11px;margin-bottom:8px"></div>
    <div style="display:flex;gap:10px">
      <button class="kophi-btn kophi-btn-gold" id="rt-btn" onclick="kpSaveResa()" style="flex:1">📞 ENREGISTRER</button>
      <button class="kophi-btn kophi-btn-outline" onclick="kpCloseModal('modal-resa-tel')">Annuler</button>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════
// KOPHI PLANNING — Données injectées directement par PHP
// AUCUNE requête AJAX pour le chargement initial
// ═══════════════════════════════════════════════════════════════

// Données du mois courant chargées par PHP (pas d'AJAX)
var KP_DATA_INIT = <?= json_encode($cal_data) ?>;
var KP_LIST_INIT = {
  blocages:     <?= json_encode($blocages_liste) ?>,
  sessions:     <?= json_encode($sessions_liste) ?>,
  reservations: <?= json_encode($resas_liste) ?>
};

// Config AJAX pour les ACTIONS (save, delete)
var KP_AJAX  = '<?= esc_js($_ajax_url) ?>';
var KP_NONCE = '<?= esc_js($_nonce) ?>';

// Mois/année courants
var KP_STATE = {
  year:  <?= $_year ?>,
  month: <?= $_month ?>,  // 0-based
  data:  KP_DATA_INIT,
  selectedDate: null,
};
var KP_MOIS = ['Janvier','Février','Mars','Avril','Mai','Juin',
               'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// ── AJAX pour les navigations et actions ────────────────────────
function kpPost(action, data, cb) {
  var p = new URLSearchParams();
  p.append('action', action);
  p.append('nonce', KP_NONCE);
  for (var k in data) p.append(k, data[k]);
  fetch(KP_AJAX, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: p.toString()
  })
  .then(function(r) {
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  })
  .then(function(d) { cb(null, d); })
  .catch(function(e) { cb(e, null); });
}

// ── Charge les données d'un mois via AJAX ───────────────────────
function kpLoadMonth() {
  var moisStr = KP_STATE.year + '-' + String(KP_STATE.month + 1).padStart(2, '0');
  var grid = document.getElementById('kp-cal-grid');
  if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;color:rgba(255,255,255,.4);font-size:12px"><span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.2);border-top-color:#E8B84B;border-radius:50%;animation:kp-spin .7s linear infinite;vertical-align:middle;margin-right:6px"></span>Chargement...</div>';

  kpPost('kophi_planning_dispo_mois', {mois: moisStr}, function(err, resp) {
    if (err || !resp || !resp.success) {
      // Fallback : générer un calendrier vide si AJAX échoue
      console.warn('[KOPHI] AJAX mois échoué, calendrier vide:', err);
      KP_STATE.data = {};
      kpRenderCal();
      return;
    }
    KP_STATE.data = resp.data || {};
    kpRenderCal();
  });
}

// ── Navigation ──────────────────────────────────────────────────
function kpCalNav(dir) {
  KP_STATE.month += dir;
  if (KP_STATE.month > 11) { KP_STATE.month = 0; KP_STATE.year++; }
  if (KP_STATE.month < 0)  { KP_STATE.month = 11; KP_STATE.year--; }
  kpLoadMonth();
}

// ── Rendu calendrier ────────────────────────────────────────────
function kpRenderCal() {
  var grid = document.getElementById('kp-cal-grid');
  var titleEl = document.getElementById('kp-cal-title');
  if (!grid) return;
  if (titleEl) titleEl.textContent = KP_MOIS[KP_STATE.month] + ' ' + KP_STATE.year;

  var today = new Date().toISOString().split('T')[0];
  var firstDay = new Date(KP_STATE.year, KP_STATE.month, 1).getDay();
  var offset   = firstDay === 0 ? 6 : firstDay - 1;
  var days     = new Date(KP_STATE.year, KP_STATE.month + 1, 0).getDate();

  var colors = {
    libre:   '#00C87A', partiel: '#C084FC', bloque: '#A855F7',
    ferme:   '#FF4757', passe:   'rgba(255,255,255,.1)',
    mixte:   '#FF8C42',
  };

  var html = '';
  for (var i = 0; i < offset; i++) html += '<div></div>';

  for (var d = 1; d <= days; d++) {
    var ds   = KP_STATE.year + '-' + String(KP_STATE.month + 1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    var st   = KP_STATE.data[ds] || (ds < today ? 'passe' : 'libre');
    var isSel = ds === KP_STATE.selectedDate;
    var isTod = ds === today;
    var color = colors[st] || '#00C87A';
    var clickable = !['bloque','ferme','passe'].includes(st);

    html += '<div style="position:relative;aspect-ratio:1;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:8px;border:1.5px solid ' +
      (isSel ? '#E8B84B' : isTod ? 'rgba(255,255,255,.2)' : 'transparent') +
      ';background:rgba(255,255,255,.03);cursor:' + (clickable ? 'pointer' : 'default') + ';transition:all .15s"' +
      (clickable ? ' onclick="kpDayClick(\'' + ds + '\')"' : '') +
      ' title="' + ds + '">' +
      '<span style="font-family:Bebas Neue,sans-serif;font-size:16px;color:' + (st==='passe'?'rgba(255,255,255,.25)':'#fff') + '">' + d + '</span>' +
      '<span style="width:6px;height:6px;border-radius:50%;background:' + color + ';margin-top:2px;' + (st==='passe'?'opacity:.3':'') + '"></span>' +
      '</div>';
  }
  grid.innerHTML = html;
}

// ── Clic sur un jour ────────────────────────────────────────────
function kpDayClick(ds) {
  KP_STATE.selectedDate = ds;
  kpRenderCal();

  // Pré-remplir les modals
  ['bl-debut','bl-fin','si-date','rt-date'].forEach(function(id) {
    var el = document.getElementById(id); if (el) el.value = ds;
  });

  var parts = ds.split('-');
  document.getElementById('kp-creneaux-label').textContent = 'Créneaux du ' + parts[2] + '/' + parts[1] + '/' + parts[0];
  document.getElementById('kp-creneaux-grid').innerHTML = '<div style="font-size:11px;color:rgba(255,255,255,.4)"><span style="display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,.15);border-top-color:#E8B84B;border-radius:50%;animation:kp-spin .7s linear infinite;vertical-align:middle;margin-right:6px"></span>Chargement...</div>';

  kpPost('kophi_planning_creneaux', {date: ds}, function(err, resp) {
    var el = document.getElementById('kp-creneaux-grid');
    if (!el) return;
    if (err || !resp) { el.innerHTML = '<div style="font-size:11px;color:var(--k-text3)">Erreur chargement créneaux.</div>'; return; }
    var data = resp.data || resp;
    if (!data.ouvert) { el.innerHTML = '<div style="font-size:11px;color:var(--k-text3)">Studio fermé ce jour.</div>'; return; }
    var crens = data.creneaux || [];
    if (!crens.length) { el.innerHTML = '<div style="font-size:11px;color:var(--k-text3)">Aucun créneau ce jour.</div>'; return; }
    el.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(72px,1fr));gap:5px">' +
      crens.map(function(c) {
        var bg = c.disponible ? 'rgba(0,200,122,.1)' : 'rgba(255,71,87,.1)';
        var cl = c.disponible ? '#00C87A' : '#FF4757';
        var bc = c.disponible ? 'rgba(0,200,122,.3)' : 'rgba(255,71,87,.3)';
        return '<div style="text-align:center;padding:7px 4px;border-radius:7px;background:' + bg + ';border:1px solid ' + bc + '">' +
          '<div style="font-family:Bebas Neue,sans-serif;font-size:14px;color:' + cl + '">' + c.heure + '</div>' +
          '<div style="font-size:8px;color:rgba(255,255,255,.5)">' + (c.disponible ? '→' + (c.heure_fin||'') : 'Occupé') + '</div></div>';
      }).join('') + '</div>';
  });
}

// ── Rendu liste blocages/sessions/réservations ──────────────────
function kpRenderList(data) {
  var el = document.getElementById('kp-list');
  if (!el) return;
  var all = [];

  (data.blocages || []).forEach(function(b) {
    all.push({
      color: '#A855F7', id: b.id, type: 'blocage',
      label: '🔒 ' + b.titre,
      sub: '📅 ' + b.date_debut + (b.date_fin !== b.date_debut ? ' → ' + b.date_fin : '') +
           (b.heure_debut ? ' · ' + String(b.heure_debut).slice(0,5) + '–' + String(b.heure_fin||'').slice(0,5) : ' · Journée'),
    });
  });
  (data.sessions || []).forEach(function(s) {
    all.push({
      color: '#E8B84B', id: s.id, type: 'session',
      label: '🎙 ' + s.titre,
      sub: '📅 ' + s.date + (s.heure ? ' · ' + String(s.heure).slice(0,5) : '') + (s.duree ? ' · ' + s.duree + 'h' : ''),
    });
  });
  (data.reservations || []).forEach(function(r) {
    all.push({
      color: r.statut === 'confirmed' ? '#00C87A' : '#E8B84B', id: r.id, type: 'reservation',
      label: (r.statut === 'confirmed' ? '✅' : '⏳') + ' ' + r.titre,
      sub: '📅 ' + r.date + (r.heure ? ' · ' + String(r.heure).slice(0,5) : '') + (r.tel ? ' · ' + r.tel : ''),
    });
  });

  if (!all.length) { el.innerHTML = '<div style="color:var(--k-text3);font-size:12px">Aucune occupation les 60 prochains jours.</div>'; return; }

  el.innerHTML = all.map(function(item) {
    var btnDelete = item.type === 'blocage'
      ? '<button onclick="kpDeleteBlocage(' + item.id + ')" style="background:rgba(255,71,87,.15);border:1px solid rgba(255,71,87,.3);color:#FF4757;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:10px">✕</button>'
      : item.type === 'session'
      ? '<button onclick="kpAnnulerSession(' + item.id + ')" style="background:rgba(255,71,87,.15);border:1px solid rgba(255,71,87,.3);color:#FF4757;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:10px">Annuler</button>'
      : '<button onclick="kpAnnulerResa(' + item.id + ')" style="background:rgba(255,71,87,.15);border:1px solid rgba(255,71,87,.3);color:#FF4757;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:10px">Annuler</button>';
    return '<div style="display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.04)">' +
      '<div style="width:3px;min-height:32px;border-radius:2px;background:' + item.color + ';flex-shrink:0;margin-top:2px"></div>' +
      '<div style="flex:1;min-width:0">' +
        '<div style="font-weight:600;font-size:11px;color:var(--k-text)">' + item.label + '</div>' +
        '<div style="font-size:9px;color:var(--k-text3);margin-top:2px">' + item.sub + '</div>' +
      '</div>' + btnDelete + '</div>';
  }).join('');
}

// ── Modals ──────────────────────────────────────────────────────
function kpOpenModal(id) {
  var el = document.getElementById(id);
  if (el) { el.style.display = 'flex'; el.classList.add('open'); }
}
function kpCloseModal(id) {
  var el = document.getElementById(id);
  if (el) { el.style.display = 'none'; el.classList.remove('open'); }
}
document.addEventListener('click', function(e) {
  if (e.target && e.target.classList.contains('kophi-modal-bg')) {
    e.target.style.display = 'none';
  }
});
function kpToggleHeures() {
  var t = document.getElementById('bl-type').value;
  var w = document.getElementById('bl-heures-wrap');
  if (w) w.style.display = t === 'journee' ? 'none' : 'grid';
}
function kpMsg(elId, msg, ok) {
  var el = document.getElementById(elId);
  if (!el) return;
  el.textContent = msg;
  el.style.display = 'block';
  el.style.background = ok ? 'rgba(0,200,122,.1)' : 'rgba(255,71,87,.1)';
  el.style.border = '1px solid ' + (ok ? 'rgba(0,200,122,.3)' : 'rgba(255,71,87,.3)');
  el.style.color = ok ? '#00C87A' : '#FF4757';
}
function kpSetBtn(id, busy, txt) {
  var el = document.getElementById(id);
  if (el) { el.disabled = busy; if (txt) el.textContent = txt; }
}

// ── SAVE Blocage ─────────────────────────────────────────────────
function kpSaveBlocage() {
  var titre  = (document.getElementById('bl-titre').value || '').trim();
  var debut  = document.getElementById('bl-debut').value || '';
  var fin    = document.getElementById('bl-fin').value   || debut;
  var isJour = document.getElementById('bl-type').value === 'journee';
  var hdebut = document.getElementById('bl-hdebut').value || '';
  var hfin   = document.getElementById('bl-hfin').value   || '';
  var motif  = document.getElementById('bl-motif').value  || 'autre';
  var desc   = document.getElementById('bl-desc').value   || '';

  if (!titre || !debut) { kpMsg('bl-msg','Titre et date obligatoires.',false); return; }
  if (!isJour && (!hdebut || !hfin)) { kpMsg('bl-msg','Heures obligatoires pour un creneau.',false); return; }

  kpSetBtn('bl-btn', true, 'Création...');
  kpPost('kophi_planning_save_blocage', {
    titre: titre, date_debut: debut, date_fin: fin,
    journee_complete: isJour ? '1' : '0',
    heure_debut: hdebut, heure_fin: hfin, motif: motif, description: desc
  }, function(err, resp) {
    kpSetBtn('bl-btn', false, '🔒 BLOQUER CE CRÉNEAU');
    if (!err && resp && resp.success) {
      kpMsg('bl-msg','Creneau bloque avec succes !', true);
      setTimeout(function() { kpCloseModal('modal-blocage'); kpLoadMonth(); kpReloadList(); }, 1200);
    } else {
      kpMsg('bl-msg', (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur.', false);
    }
  });
}

// ── SAVE Session Interne ─────────────────────────────────────────
function kpSaveSession() {
  var titre   = (document.getElementById('si-titre').value || '').trim();
  var artiste = document.getElementById('si-artiste').value || 'Label - Interne';
  var date    = document.getElementById('si-date').value   || '';
  var heure   = document.getElementById('si-heure').value  || '';
  var duree   = document.getElementById('si-duree').value  || '4';
  var daw     = document.getElementById('si-daw').value    || '';
  var notes   = document.getElementById('si-notes').value  || '';

  if (!titre || !date || !heure) { kpMsg('si-msg','Titre, date et heure obligatoires.',false); return; }

  kpSetBtn('si-btn', true, 'Création...');
  kpPost('kophi_planning_save_session', {
    titre: titre, artiste: artiste, date: date, heure: heure,
    duree: duree, daw: daw, notes: notes
  }, function(err, resp) {
    kpSetBtn('si-btn', false, '🎵 CRÉER LA SESSION');
    if (!err && resp && resp.success) {
      kpMsg('si-msg','Session creee et creneau bloque !', true);
      setTimeout(function() { kpCloseModal('modal-session-interne'); kpLoadMonth(); kpReloadList(); }, 1200);
    } else {
      kpMsg('si-msg', (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur.', false);
    }
  });
}

// ── SAVE Résa Téléphone ──────────────────────────────────────────
function kpSaveResa() {
  var nom     = (document.getElementById('rt-nom').value    || '').trim();
  var tel     = document.getElementById('rt-tel').value     || '';
  var service = document.getElementById('rt-service').value || 'voix';
  var forfait = document.getElementById('rt-forfait').value || '50000';
  var date    = document.getElementById('rt-date').value    || '';
  var heure   = document.getElementById('rt-heure').value   || '';
  var statut  = document.getElementById('rt-statut').value  || 'confirmed';
  var notes   = document.getElementById('rt-notes').value   || '';

  if (!nom || !date) { kpMsg('rt-msg','Nom et date obligatoires.',false); return; }

  kpSetBtn('rt-btn', true, 'Enregistrement...');
  kpPost('kophi_planning_save_resa', {
    nom: nom, tel: tel, service: service, forfait: forfait,
    date: date, heure: heure, statut: statut, notes: notes
  }, function(err, resp) {
    kpSetBtn('rt-btn', false, '📞 ENREGISTRER');
    if (!err && resp && resp.success) {
      var msg = statut === 'confirmed' ? 'Reservation confirmee !' : 'Demande enregistree en attente.';
      kpMsg('rt-msg', msg, true);
      setTimeout(function() { kpCloseModal('modal-resa-tel'); kpLoadMonth(); kpReloadList(); }, 1200);
    } else {
      kpMsg('rt-msg', (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur.', false);
    }
  });
}

// ── DELETE ───────────────────────────────────────────────────────
function kpDeleteBlocage(id) {
  if (!confirm('Supprimer ce blocage ?')) return;
  kpPost('kophi_planning_delete_blocage', {blocage_id: id}, function(err, resp) {
    if (!err && resp && resp.success) { kpLoadMonth(); kpReloadList(); }
    else alert('Erreur lors de la suppression.');
  });
}
function kpAnnulerSession(id) {
  if (!confirm('Annuler cette session ? Le creneau sera libere.')) return;
  kpPost('kophi_cancel_session', {id: id}, function(err, resp) {
    if (!err && resp && resp.success) { kpLoadMonth(); kpReloadList(); alert('Session annulee.'); }
    else alert('Erreur.');
  });
}
function kpAnnulerResa(id) {
  if (!confirm('Annuler cette reservation ? Le creneau sera libere.')) return;
  kpPost('kophi_cancel_reservation', {id: id}, function(err, resp) {
    if (!err && resp && resp.success) { kpLoadMonth(); kpReloadList(); alert('Reservation annulee.'); }
    else alert('Erreur.');
  });
}

// ── Reload liste ─────────────────────────────────────────────────
function kpReloadList() {
  kpPost('kophi_planning_blocages', {}, function(err, resp) {
    if (!err && resp && resp.success) {
      kpRenderList(resp.data || {});
    }
  });
}

// ── INIT ─────────────────────────────────────────────────────────
// Rendu immédiat avec données PHP (pas d'attente AJAX)
kpRenderCal();
kpRenderList(KP_LIST_INIT);
</script>
