<?php if(!defined("ABSPATH"))exit;
// ── Date en français ──────────────────────────────────────────
function kophi_date_fr(): string {
    $jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
    $mois  = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    return $jours[date('w')] . ' ' . date('j') . ' ' . $mois[(int)date('n')] . ' ' . date('Y');
}
$today = date('Y-m-d');
$now_ts = time();

// Sessions du jour
$sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[['key'=>'_kophi_date_session','value'=>$today,'compare'=>'=']],'orderby'=>'meta_value','meta_key'=>'_kophi_heure_debut']);

// Sessions LIVE
$live_sessions = array_filter($sessions, function($s){ return get_post_meta($s->ID,'_kophi_statut',true)==='live'; });

// Stats mois
$m_debut = date('Y-m-01'); $m_fin = date('Y-m-t');
$sessions_mois = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[['key'=>'_kophi_date_session','value'=>[$m_debut,$m_fin],'compare'=>'BETWEEN','type'=>'DATE']]]);
$revenus = 0; $heures = 0;
foreach($sessions_mois as $s){$revenus+=(float)get_post_meta($s->ID,'_kophi_montant',true);$heures+=(float)get_post_meta($s->ID,'_kophi_duree_prevue',true);}

// Réservations pending
$pending = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>-1,'meta_query'=>[['key'=>'_kophi_statut','value'=>'pending']]]);

// Projets DAW actifs (via meta _kophi_daw_actif)
$daw_actifs = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[['key'=>'_kophi_daw_actif','value'=>'1']]]);

$nb_clients = wp_count_posts('kophi_client')->publish ?? 0;
$nb_beats   = wp_count_posts('kophi_beat')->publish ?? 0;
$nb_fb_new  = function_exists('ksm_listen_total_new_feedback') ? ksm_listen_total_new_feedback() : 0;

$svc_labels = ['voix'=>'Voix/Mix/Master','faceB'=>'Face B','beats'=>'Beat Custom','consultation'=>'RDV','mixmaster'=>'Mix & Master','mastering'=>'Mastering','interne'=>'Interne Label'];
$statut_labels = ['scheduled'=>'⏰ Planifiée','live'=>'🔴 EN COURS','done'=>'✅ Terminée','cancelled'=>'❌ Annulée','interne'=>'🔒 Interne'];
?>
<div class="kophi-wrap"><div class="kophi-inner">

<!-- HEADER -->
<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">🎙</div>
    <div>
      <h1>KOPHI'S MUSIC <span>STUDIO</span></h1>
      <div class="kophi-breadcrumb">Tableau de bord · <?= kophi_date_fr() ?></div>
    </div>
  </div>
  <div class="kophi-btn-group">
    <a href="<?= admin_url('post-new.php?post_type=kophi_session') ?>" class="kophi-btn kophi-btn-primary">+ Session</a>
    <a href="<?= admin_url('admin.php?page=kophi-planning') ?>" class="kophi-btn kophi-btn-gold">📅 Planning</a>
    <a href="<?= admin_url('admin.php?page=kophi-reservations') ?>" class="kophi-btn kophi-btn-outline">⏳ Réservations <span style="background:var(--k-red);color:#fff;border-radius:20px;padding:1px 6px;font-size:9px;margin-left:4px"><?= count($pending) ?></span></a>
    <a href="https://wa.me/<?= preg_replace('/[^0-9]/','',get_option('kophi_whatsapp','2250503404389')) ?>" target="_blank" class="kophi-btn kophi-btn-wa">💬 WhatsApp</a>
  </div>
</div>

<!-- LIVE SESSIONS BANNER -->
<?php if (!empty($live_sessions)): ?>
<div class="kophi-live-banner">
  <div class="kophi-live-dot"></div>
  <div style="font-family:'Bebas Neue',sans-serif;font-size:14px;letter-spacing:3px;color:var(--k-red);flex-shrink:0">LIVE</div>
  <div class="kophi-live-sessions">
    <?php foreach($live_sessions as $s):
      $heure  = get_post_meta($s->ID,'_kophi_heure_debut',true);
      $duree  = (float)get_post_meta($s->ID,'_kophi_duree_prevue',true);
      $client = get_post_meta($s->ID,'_kophi_client_nom',true);
      $type   = get_post_meta($s->ID,'_kophi_type_session',true);
      $start_key = 'kophi_live_start_'.$s->ID;
      $live_start = get_post_meta($s->ID,'_kophi_live_start_ts',true);
      if (!$live_start) { $live_start = $now_ts; update_post_meta($s->ID,'_kophi_live_start_ts',$live_start); }
      $duree_sec = (int)($duree*3600);
    ?>
    <div class="kophi-live-session"
         data-timer-id="<?= $s->ID ?>"
         data-timer-start="<?= $live_start ?>"
         data-timer-duree="<?= $duree ?>">
      <div class="kophi-live-name">🎤 <?= esc_html($client) ?: 'Session #'.$s->ID ?></div>
      <div class="kophi-live-service"><?= esc_html($svc_labels[$type]??$type) ?> · <?= $duree ?>h prévues</div>
      <div class="kophi-timer-display ok">00:00:00</div>
      <div class="kophi-timer-bar-wrap">
        <div class="kophi-timer-bar" style="width:0%"></div>
      </div>
      <div style="margin-top:8px">
        <a href="<?= wp_nonce_url(admin_url("admin-post.php?action=kophi_set_done&id={$s->ID}"),'kophi_done_'.$s->ID) ?>" class="kophi-btn kophi-btn-green kophi-btn-sm">✓ Terminer</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="kophi-stats">
  <div class="kophi-stat gold">
    <div class="kophi-stat-icon">💰</div>
    <div class="kophi-stat-label">Revenus ce mois</div>
    <div class="kophi-stat-val"><?= number_format($revenus/1000,0) ?>K</div>
    <div class="kophi-stat-sub">F CFA · <?= count($sessions_mois) ?> sessions</div>
  </div>
  <div class="kophi-stat violet">
    <div class="kophi-stat-icon">⏱</div>
    <div class="kophi-stat-label">Heures studio</div>
    <div class="kophi-stat-val"><?= round($heures,0) ?>h</div>
    <div class="kophi-stat-sub">ce mois</div>
  </div>
  <div class="kophi-stat red">
    <div class="kophi-stat-icon">🔴</div>
    <div class="kophi-stat-label">En cours</div>
    <div class="kophi-stat-val"><?= count($live_sessions) ?></div>
    <div class="kophi-stat-sub">session(s) active(s)</div>
  </div>
  <div class="kophi-stat orange">
    <div class="kophi-stat-icon">📋</div>
    <div class="kophi-stat-label">À confirmer</div>
    <div class="kophi-stat-val"><?= count($pending) ?></div>
    <div class="kophi-stat-sub">réservation(s)</div>
  </div>
  <div class="kophi-stat green">
    <div class="kophi-stat-icon">👤</div>
    <div class="kophi-stat-label">Clients</div>
    <div class="kophi-stat-val"><?= $nb_clients ?></div>
    <div class="kophi-stat-sub">enregistrés</div>
  </div>
  <div class="kophi-stat gold">
    <div class="kophi-stat-icon">🎹</div>
    <div class="kophi-stat-label">Beats</div>
    <div class="kophi-stat-val"><?= $nb_beats ?></div>
    <div class="kophi-stat-sub">en vente</div>
  </div>
  <a href="<?= admin_url('admin.php?page=kophi-ecoute') ?>" class="kophi-stat <?= $nb_fb_new ? 'red' : 'violet' ?>" style="text-decoration:none;display:block">
    <div class="kophi-stat-icon">💬</div>
    <div class="kophi-stat-label">Commentaires clients</div>
    <div class="kophi-stat-val"><?= $nb_fb_new ?></div>
    <div class="kophi-stat-sub"><?= $nb_fb_new ? 'à traiter' : 'à jour ✅' ?></div>
  </a>
</div>

<!-- GRILLE PRINCIPALE -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<!-- Sessions du jour -->
<div class="kophi-card">
  <div class="kophi-card-title">📅 Sessions aujourd'hui — <?= date('d/m') ?></div>
  <?php if (empty($sessions)): ?>
  <div class="kophi-empty"><div class="kophi-empty-icon">📅</div><p>Aucune session aujourd'hui<br><a href="<?= admin_url('post-new.php?post_type=kophi_session') ?>">+ Créer une session →</a></p></div>
  <?php else: ?>
  <div class="kophi-table-wrap"><table class="kophi-table">
    <thead><tr><th>Heure</th><th>Client</th><th>Service</th><th>Durée</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach($sessions as $s):
      $heure  = get_post_meta($s->ID,'_kophi_heure_debut',true);
      $client = get_post_meta($s->ID,'_kophi_client_nom',true);
      $type   = get_post_meta($s->ID,'_kophi_type_session',true);
      $duree  = get_post_meta($s->ID,'_kophi_duree_prevue',true);
      $statut = get_post_meta($s->ID,'_kophi_statut',true) ?: 'scheduled';
      $tel    = get_post_meta($s->ID,'_kophi_telephone',true) ?? get_post_meta($s->ID,'_kophi_notes',true);
    ?>
    <tr>
      <td class="td-gold"><?= esc_html(substr($heure,0,5)) ?: '—' ?></td>
      <td class="td-name"><?= esc_html($client) ?: '—' ?></td>
      <td style="font-size:11px"><?= esc_html($svc_labels[$type]??$type) ?></td>
      <td><?= $duree ? $duree.'h' : '—' ?></td>
      <td><span class="kophi-badge <?= esc_attr($statut) ?>"><?= $statut_labels[$statut]??$statut ?></span></td>
      <td>
        <?php if($statut==='scheduled'): ?>
        <a href="<?= wp_nonce_url(admin_url("admin-post.php?action=kophi_set_live&id={$s->ID}"),'kophi_live_'.$s->ID) ?>" class="kophi-btn kophi-btn-red kophi-btn-sm">▶ LIVE</a>
        <?php elseif($statut==='live'): ?>
        <a href="<?= wp_nonce_url(admin_url("admin-post.php?action=kophi_set_done&id={$s->ID}"),'kophi_done_'.$s->ID) ?>" class="kophi-btn kophi-btn-green kophi-btn-sm">✓ FIN</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- Réservations en attente -->
<div class="kophi-card kophi-card-gold">
  <div class="kophi-card-title">⏳ Réservations en attente <span style="background:var(--k-red);color:#fff;border-radius:20px;padding:1px 8px;font-size:9px"><?= count($pending) ?></span></div>
  <?php if (empty($pending)): ?>
  <div class="kophi-empty"><div class="kophi-empty-icon">✅</div><p>Tout est traité !</p></div>
  <?php else: ?>
  <?php foreach(array_slice($pending,0,5) as $r):
    $nom    = get_post_meta($r->ID,'_kophi_nom_artiste',true);
    $tel    = get_post_meta($r->ID,'_kophi_telephone',true);
    $svc    = get_post_meta($r->ID,'_kophi_service',true);
    $date   = get_post_meta($r->ID,'_kophi_date_souhaitee',true);
    $heure  = get_post_meta($r->ID,'_kophi_heure_souhaitee',true);
    $wa     = 'https://wa.me/'.preg_replace('/[^0-9]/','', $tel);
    $cnf    = wp_nonce_url(admin_url("admin-post.php?action=kophi_confirm_res&id={$r->ID}"),'kophi_confirm_res_'.$r->ID);
    $init   = strtoupper(substr($nom,0,2));
  ?>
  <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--k-border2)">
    <div class="kophi-avatar" style="font-size:11px"><?= $init ?></div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;font-size:12px;color:var(--k-text)"><?= esc_html($nom) ?></div>
      <div style="font-size:10px;color:var(--k-text3)"><?= esc_html($svc_labels[$svc]??$svc) ?> · <?= $date?date('d/m',strtotime($date)):'' ?><?= $heure?" $heure":'' ?></div>
    </div>
    <div class="kophi-btn-group">
      <a href="<?= $cnf ?>" class="kophi-btn kophi-btn-green kophi-btn-sm">✓</a>
      <a href="<?= $wa ?>" target="_blank" class="kophi-btn kophi-btn-wa kophi-btn-sm">💬</a>
    </div>
  </div>
  <?php endforeach;
  if(count($pending)>5): ?><div style="font-size:11px;color:var(--k-text4);margin-top:10px">+ <?= count($pending)-5 ?> autres — <a href="<?= admin_url('admin.php?page=kophi-reservations') ?>" style="color:var(--k-gold)">Tout voir →</a></div><?php endif; ?>
  <?php endif; ?>
</div>

</div><!-- /grid -->

<!-- DAW TRACKER SECTION -->
<style>@keyframes kpulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.4)}}</style>
<div class="kophi-card kophi-card-violet" style="margin-top:20px">
  <div class="kophi-card-title">🎛 DAW Tracker — Projets FL Studio / Studio One</div>
  <div id="kophi-daw-list">
  <?php
  // Sessions DAW actives avec heartbeat récent (< 5 min) pour éviter les sessions fantômes
  $hb_seuil = time() - 300; // 5 minutes
  $daw_actives_raw = get_posts(['post_type'=>'kophi_session','posts_per_page'=>10,
    'meta_query'=>[['key'=>'_kophi_daw_actif','value'=>'1'],['key'=>'_kophi_daw_app','compare'=>'EXISTS']],
    'orderby'=>'date','order'=>'DESC']);
  // Filtrer : garder seulement les sessions avec heartbeat récent OU sans heartbeat (session interne manuelle)
  $daw_actives = array_filter($daw_actives_raw, function($s) use ($hb_seuil) {
    $hb = (int)get_post_meta($s->ID, '_kophi_daw_last_hb', true);
    // Si pas de heartbeat → c'est une session manuelle → afficher
    // Si heartbeat récent (< 5 min) → afficher
    // Si heartbeat trop vieux → session probablement terminée → masquer
    if ($hb === 0) return true; // Pas de heartbeat = session manuelle ou très récente
    return $hb >= $hb_seuil;
  });
  $daw_sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>5,
    'meta_query'=>[['key'=>'_kophi_daw_projet','compare'=>'EXISTS']],
    'orderby'=>'date','order'=>'DESC']);
  ?>
  <?php if (empty($daw_actives) && empty($daw_sessions)): ?>
  <div style="display:flex;align-items:center;gap:16px;padding:20px;background:rgba(139,63,217,.08);border:1px dashed var(--k-violet-b);border-radius:12px">
    <div style="font-size:36px">🎛</div>
    <div>
      <div style="font-weight:700;color:var(--k-text);margin-bottom:4px">Script DAW Tracker non actif</div>
      <div style="font-size:11px;color:var(--k-text3)">Installez le script Python sur l'ordinateur du studio pour tracker automatiquement FL Studio et Studio One.</div>
      <a href="<?= admin_url('admin.php?page=kophi-settings#daw-tracker') ?>" class="kophi-btn kophi-btn-violet kophi-btn-sm" style="margin-top:10px;display:inline-flex">⬇ Télécharger le script</a>
    </div>
  </div>
  <?php elseif (!empty($daw_actives)): ?>
  <?php foreach ($daw_actives as $s):
    $da_app   = get_post_meta($s->ID, '_kophi_daw_app',    true) ?: 'FL Studio';
    $da_proj  = get_post_meta($s->ID, '_kophi_daw_projet', true) ?: 'Projet en cours';
    $da_start = (int)get_post_meta($s->ID, '_kophi_live_start_ts', true);
    $da_duree = (float)get_post_meta($s->ID, '_kophi_duree_prevue', true) ?: 8;
    $da_hb    = (int)get_post_meta($s->ID, '_kophi_daw_last_hb',  true);
    $da_min   = $da_start ? round((time()-$da_start)/60) : 0;
    $da_live  = (!$da_hb || (time()-$da_hb) < 300);
    $da_ico   = (strpos(strtolower($da_app), 'studio one') !== false) ? '🎸' : '🎹';
  ?>
  <div style="display:flex;align-items:center;gap:14px;padding:14px;background:rgba(0,200,122,.07);border:1px solid rgba(0,200,122,.2);border-radius:12px;margin-bottom:10px">
    <div style="width:10px;height:10px;border-radius:50%;flex-shrink:0;background:<?= $da_live ? '#00C87A' : '#E8B84B' ?>;<?= $da_live ? 'animation:kpulse 1.5s ease-in-out infinite' : '' ?>"></div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:13px;color:var(--k-text)"><?= $da_ico ?> <?= esc_html($da_app) ?> — <span style="color:var(--k-gold)"><?= esc_html($da_proj) ?></span></div>
      <div style="font-size:10px;color:var(--k-text3);margin-top:3px">🔴 EN COURS · <?= $da_min ?> min · <?= $da_live ? 'Connecté' : 'Hors ligne' ?></div>
    </div>
    <div data-timer-id="<?= $s->ID ?>" data-timer-start="<?= $da_start ?: time() ?>" data-timer-duree="<?= $da_duree ?? 8 ?>">
      <div class="kophi-timer-display ok" style="font-family:'Bebas Neue',sans-serif;font-size:22px;color:#00C87A">00:00:00</div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!empty($daw_sessions)): ?>
  <div style="font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--k-text3);margin:10px 0 8px">Historique récent</div>
  <?php foreach ($daw_sessions as $s):
    $ds_app  = get_post_meta($s->ID, '_kophi_daw_app',    true) ?: 'FL Studio';
    $ds_proj = get_post_meta($s->ID, '_kophi_daw_projet', true) ?: 'Projet sans titre';
    $ds_cli  = get_post_meta($s->ID, '_kophi_client_nom', true);
    $ds_ico  = (strpos(strtolower($ds_app), 'studio one') !== false) ? '🎸' : '🎹';
    $ds_bdg  = (strpos(strtolower($ds_app), 'studio one') !== false) ? 'studio_one' : 'fl_studio';
  ?>
  <div class="kophi-daw-card">
    <div class="kophi-daw-icon"><?= $ds_ico ?></div>
    <div class="kophi-daw-info">
      <div class="kophi-daw-name"><?= esc_html($ds_proj) ?></div>
      <div class="kophi-daw-meta">
        <span class="kophi-badge <?= $ds_bdg ?>" style="font-size:8px"><?= esc_html($ds_app) ?></span>
        <?php if ($ds_cli): ?><span style="margin-left:6px">👤 <?= esc_html($ds_cli) ?></span><?php endif; ?>
        <?php if ($s->post_date): ?><span style="margin-left:6px">📅 <?= date('d/m/Y', strtotime($s->post_date)) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="font-size:10px;color:var(--k-text4)"><?= date('H:i', strtotime($s->post_date)) ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php else: ?>
  <?php foreach ($daw_sessions as $s):
    $ds_app  = get_post_meta($s->ID, '_kophi_daw_app',    true) ?: 'FL Studio';
    $ds_proj = get_post_meta($s->ID, '_kophi_daw_projet', true) ?: 'Projet sans titre';
    $ds_cli  = get_post_meta($s->ID, '_kophi_client_nom', true);
    $ds_ico  = (strpos(strtolower($ds_app), 'studio one') !== false) ? '🎸' : '🎹';
    $ds_bdg  = (strpos(strtolower($ds_app), 'studio one') !== false) ? 'studio_one' : 'fl_studio';
  ?>
  <div class="kophi-daw-card">
    <div class="kophi-daw-icon"><?= $ds_ico ?></div>
    <div class="kophi-daw-info">
      <div class="kophi-daw-name"><?= esc_html($ds_proj) ?></div>
      <div class="kophi-daw-meta">
        <span class="kophi-badge <?= $ds_bdg ?>" style="font-size:8px"><?= esc_html($ds_app) ?></span>
        <?php if ($ds_cli): ?><span style="margin-left:6px">👤 <?= esc_html($ds_cli) ?></span><?php endif; ?>
        <?php if ($s->post_date): ?><span style="margin-left:6px">📅 <?= date('d/m/Y', strtotime($s->post_date)) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="font-size:10px;color:var(--k-text4)"><?= date('H:i', strtotime($s->post_date)) ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  </div>
</div>


<!-- LIENS RAPIDES -->
<div class="kophi-card" style="margin-top:20px">
  <div class="kophi-card-title">🔗 Accès rapides</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <a href="<?= home_url('/reserver') ?>" target="_blank" class="kophi-btn kophi-btn-primary kophi-btn-lg">🎙 Réservation</a>
    <a href="<?= home_url('/beats') ?>" target="_blank" class="kophi-btn kophi-btn-gold kophi-btn-lg">🎹 Boutique Beats</a>
    <a href="<?= admin_url('admin.php?page=kophi-sessions') ?>" class="kophi-btn kophi-btn-violet">📋 Sessions</a>
    <a href="<?= admin_url('admin.php?page=kophi-statistiques') ?>" class="kophi-btn kophi-btn-outline">📊 Statistiques</a>
    <a href="<?= admin_url('admin.php?page=kophi-clients') ?>" class="kophi-btn kophi-btn-outline">👥 Clients</a>
    <a href="<?= admin_url('admin.php?page=kophi-beats') ?>" class="kophi-btn kophi-btn-outline">🎹 Beats</a>
  </div>
</div>


<!-- ── Timer Inline (secours — indépendant de admin.js) ── -->
<script>
(function() {
  var _timers = {};
  
  function fmtTime(sec) {
    sec = Math.abs(Math.floor(sec));
    var h = Math.floor(sec/3600);
    var m = Math.floor((sec%3600)/60);
    var s = sec%60;
    return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
  }
  
  function startInlineTimer(el) {
    var id       = el.dataset.timerId;
    var startTs  = parseInt(el.dataset.timerStart || 0);
    var dureeSec = parseFloat(el.dataset.timerDuree || 0) * 3600;
    var display  = el.querySelector('.kophi-timer-display') || el;
    var bar      = el.querySelector('.kophi-timer-bar');
    
    if (!id || !startTs || isNaN(startTs)) return;
    if (_timers[id]) clearInterval(_timers[id]);
    
    var tick = function() {
      var elapsed = Math.max(0, Math.floor(Date.now()/1000 - startTs));
      // CHRONOMÈTRE : toujours afficher le temps écoulé
      display.textContent = fmtTime(elapsed);
      if (dureeSec > 0 && elapsed > dureeSec) {
        display.className = 'kophi-timer-display warning';
      } else {
        display.className = 'kophi-timer-display ok';
      }
      if (bar && dureeSec > 0) {
        var pct = Math.min(100, elapsed / dureeSec * 100);
        bar.style.width = pct + '%';
        bar.style.background = pct > 100 ? '#FF4757' : pct > 80 ? '#E8B84B' : '#00C87A';
      }
    };
    tick();
    _timers[id] = setInterval(tick, 1000);
  }
  
  function initInlineTimers() {
    document.querySelectorAll('[data-timer-id]').forEach(startInlineTimer);
  }
  
  // Lancer immédiatement + après DOM chargé + périodiquement
  initInlineTimers();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initInlineTimers);
  }
  // Vérifier toutes les 5s si de nouveaux timers sont apparus
  setInterval(initInlineTimers, 5000);

  // Polling sessions live toutes les 20s — arrête les timers des sessions terminées
  setInterval(function() {
    var ajaxUrl = (window.KOPHI && KOPHI.ajaxurl) ? KOPHI.ajaxurl : ajaxurl;
    var nonce   = (window.KOPHI && KOPHI.nonce)   ? KOPHI.nonce   : '';
    if (!ajaxUrl) return;

    var params = new URLSearchParams();
    params.append('action', 'kophi_check_live_sessions');
    params.append('nonce', nonce);

    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.success) return;
      var activeSessions = data.data || [];

      // Arrêter les timers des sessions qui ne sont plus live
      document.querySelectorAll('[data-timer-id]').forEach(function(el) {
        var id = el.dataset.timerId;
        if (id && !activeSessions.includes(String(id))) {
          // Session terminée — arrêter le timer et griser
          if (_timers[id]) {
            clearInterval(_timers[id]);
            delete _timers[id];
          }
          var display = el.querySelector('.kophi-timer-display') || el;
          display.className = 'kophi-timer-display';
          display.style.opacity = '0.4';
        }
      });

      // Si plus aucune session live → masquer la bannière live
      if (activeSessions.length === 0) {
        var banner = document.querySelector('.kophi-live-banner');
        if (banner) {
          banner.style.transition = 'opacity 0.5s';
          banner.style.opacity = '0';
          setTimeout(function() { banner.style.display = 'none'; }, 500);
        }
      }
    })
    .catch(function() {});
  }, 20000);
  
  // Exposer pour que admin.js puisse s'en servir
  window._kophiInlineTimers = _timers;
  window._kophiInitTimers   = initInlineTimers;
})();
</script>
</div></div>
