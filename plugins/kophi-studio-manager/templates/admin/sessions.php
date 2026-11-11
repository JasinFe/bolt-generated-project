<?php if (!defined('ABSPATH')) exit; ?>
<div class="kophi-wrap"><div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">🎙</div>
    <h1>SESSIONS STUDIO</h1>
  </div>
  <div class="kophi-btn-group">
    <select id="filter-statut" onchange="filterSessions()" style="font-size:11px;padding:7px 12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text)">
      <option value="">Toutes les sessions</option>
      <option value="scheduled">⏰ Planifiées</option>
      <option value="live">🔴 En cours</option>
      <option value="done">✅ Terminées</option>
      <option value="cancelled">❌ Annulées</option>
      <option value="interne">🔒 Internes</option>
    </select>
    <input type="month" id="filter-mois" value="<?= date('Y-m') ?>" onchange="filterSessions()" style="font-size:11px;padding:7px 12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text)">
    <a href="<?= admin_url('post-new.php?post_type=kophi_session') ?>" class="kophi-btn kophi-btn-primary">+ Nouvelle session</a>
    <button onclick="kophiResetSessions()" class="kophi-btn kophi-btn-red kophi-btn-sm" 
    style="margin-left:8px" title="Réinitialiser toutes les sessions EN COURS vers Terminée">
    🔄 Réinitialiser
  </button>
</div>
</div>

<?php
$sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'orderby'=>'meta_value','meta_key'=>'_kophi_date_session','order'=>'DESC']);
$s_labels = ['scheduled'=>'⏰ Planifiée','live'=>'🔴 EN COURS','done'=>'✅ Terminée','cancelled'=>'❌ Annulée','interne'=>'🔒 Interne'];
$svc_labels = ['voix'=>'Voix/Mix/Master','faceB'=>'Face B','beats'=>'Beat Custom','consultation'=>'RDV','mixmaster'=>'Mix & Master','mastering'=>'Mastering','interne'=>'Interne Label'];

$by_statut = [];
foreach($sessions as $s) {
  $st = get_post_meta($s->ID,'_kophi_statut',true) ?: 'scheduled';
  $by_statut[$st] = ($by_statut[$st]??0)+1;
}
$revenus = 0; $heures = 0;
foreach($sessions as $s) { $revenus += (float)get_post_meta($s->ID,'_kophi_montant',true); $heures += (float)get_post_meta($s->ID,'_kophi_duree_prevue',true); }
?>

<div class="kophi-stats">
  <div class="kophi-stat gold"><div class="kophi-stat-label">Total sessions</div><div class="kophi-stat-val"><?= count($sessions) ?></div></div>
  <div class="kophi-stat red"><div class="kophi-stat-label">En cours</div><div class="kophi-stat-val"><?= $by_statut['live']??0 ?></div></div>
  <div class="kophi-stat violet"><div class="kophi-stat-label">Planifiées</div><div class="kophi-stat-val"><?= $by_statut['scheduled']??0 ?></div></div>
  <div class="kophi-stat green"><div class="kophi-stat-label">Terminées</div><div class="kophi-stat-val"><?= $by_statut['done']??0 ?></div></div>
  <div class="kophi-stat gold"><div class="kophi-stat-label">Heures totales</div><div class="kophi-stat-val"><?= round($heures,0) ?>h</div></div>
  <div class="kophi-stat green"><div class="kophi-stat-label">Revenus totaux</div><div class="kophi-stat-val"><?= number_format($revenus/1000,0)?>K</div><div class="kophi-stat-sub">F CFA</div></div>
</div>

<div class="kophi-card" style="padding:0;overflow:hidden">
<?php if (empty($sessions)): ?>
  <div class="kophi-empty"><div class="kophi-empty-icon">🎙</div><p>Aucune session enregistrée.<br><a href="<?= admin_url('post-new.php?post_type=kophi_session') ?>" style="color:var(--k-gold)">Créer la première →</a></p></div>
<?php else: ?>
<table class="kophi-table" id="sessions-table">
  <thead><tr><th>Date</th><th>Heure</th><th>Artiste / Client</th><th>Service</th><th>Durée</th><th>Montant</th><th>Ingénieur</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($sessions as $s):
    $date    = get_post_meta($s->ID,'_kophi_date_session',true);
    $heure   = get_post_meta($s->ID,'_kophi_heure_debut',true);
    $client  = get_post_meta($s->ID,'_kophi_client_nom',true);
    $type    = get_post_meta($s->ID,'_kophi_type_session',true);
    $duree   = get_post_meta($s->ID,'_kophi_duree_prevue',true);
    $montant = get_post_meta($s->ID,'_kophi_montant',true);
    $ing     = get_post_meta($s->ID,'_kophi_ingenieur',true);
    $statut  = get_post_meta($s->ID,'_kophi_statut',true) ?: 'scheduled';
    $notes   = get_post_meta($s->ID,'_kophi_notes',true);
  ?>
  <tr data-statut="<?= esc_attr($statut) ?>" data-mois="<?= $date?substr($date,0,7):'' ?>">
    <td style="font-weight:700;white-space:nowrap"><?= $date?date('d/m/Y',strtotime($date)):'—' ?></td>
    <td style="color:var(--k-gold);font-weight:700"><?= esc_html(substr($heure,0,5)) ?: '—' ?></td>
    <td>
      <div class="td-name"><?= esc_html($client) ?: '—' ?></div>
      <?php if($notes): ?><div style="font-size:10px;color:var(--k-text4);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= esc_attr($notes) ?>"><?= esc_html(substr($notes,0,60)) ?></div><?php endif; ?>
    </td>
    <td style="font-size:11px"><?= esc_html($svc_labels[$type]??$type) ?></td>
    <td><?= $duree?$duree.'h':'—' ?></td>
    <td><?= $montant?'<strong style="color:var(--k-gold)">'.number_format((float)$montant,0,',',' ').' F</strong>':'—' ?></td>
    <td style="font-size:11px;color:var(--k-text3)"><?= esc_html($ing) ?: '—' ?></td>
    <td><span class="kophi-badge <?= esc_attr($statut) ?>"><?= $s_labels[$statut]??$statut ?></span></td>
    <td>
      <div class="kophi-btn-group">
        <a href="<?= admin_url("post.php?post={$s->ID}&action=edit") ?>" class="kophi-btn kophi-btn-outline kophi-btn-sm" title="Modifier">✏</a>
        <?php if($statut==='scheduled'): ?>
        <a href="<?= wp_nonce_url(admin_url("admin-post.php?action=kophi_set_live&id={$s->ID}"),'kophi_live_'.$s->ID) ?>" class="kophi-btn kophi-btn-red kophi-btn-sm" title="Démarrer" onclick="return confirm('Démarrer cette session ?')">▶ LIVE</a>
        <?php elseif($statut==='live'): ?>
        <a href="<?= wp_nonce_url(admin_url("admin-post.php?action=kophi_set_done&id={$s->ID}"),'kophi_done_'.$s->ID) ?>" class="kophi-btn kophi-btn-green kophi-btn-sm" title="Terminer">✓ FIN</a>
        <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

</div></div>

<script>
function filterSessions() {
  const st = document.getElementById('filter-statut').value;
  const mo = document.getElementById('filter-mois').value;
  document.querySelectorAll('#sessions-table tbody tr').forEach(row => {
    const matchSt = !st || row.dataset.statut === st;
    const matchMo = !mo || row.dataset.mois === mo;
    row.style.display = (matchSt && matchMo) ? '' : 'none';
  });
}
</script>
<script>
function kophiResetSessions() {
  if (!confirm('Réinitialiser toutes les sessions EN COURS → Terminée ?\n\nCette action ferme toutes les sessions actives.')) return;
  fetch(ajaxurl, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=kophi_reset_sessions&nonce=' + encodeURIComponent('<?= wp_create_nonce("kophi_ajax_nonce") ?>')
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      alert('✅ ' + (d.data?.count || 0) + ' session(s) réinitialisée(s).');
      location.reload();
    } else {
      alert('Erreur: ' + (d.data?.message || 'Inconnue'));
    }
  });
}
</script>
