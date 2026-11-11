<?php if (!defined('ABSPATH')) exit; ?>
<div class="kophi-wrap">
<div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">📋</div>
    <h1>RÉSERVATIONS</h1>
  </div>
  <div class="kophi-btn-group">
    <select id="filter-statut" onchange="filterTable()" style="font-size:11px;padding:7px 12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);width:auto">
      <option value="">Toutes</option>
      <option value="pending">⏳ En attente</option>
      <option value="confirmed">✅ Confirmées</option>
      <option value="cancelled">❌ Annulées</option>
    </select>
    <a href="<?= home_url('/reserver') ?>" target="_blank" class="kophi-btn kophi-btn-outline">🔗 Page publique</a>
  </div>
</div>

<?php
$all_res = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC']);
$svc_labels = ['voix'=>'🎙 Voix/Mix/Master','faceB'=>'🎵 Face B','beats'=>'🎹 Beat Custom','consultation'=>'💬 RDV','achat_beat'=>'💿 Achat Beat','mixmaster'=>'🎚 Mix & Master','mastering'=>'✨ Mastering'];
$forf_labels = ['50000'=>'50 000 F - 4h','65000'=>'65 000 F - 6h','90000'=>'90 000 F - 8h','150000'=>'150 000 F - 12h','10000'=>'10 000 F - Face B',''=>'À discuter'];

$nb_pending   = count(array_filter($all_res, function($r){ return get_post_meta($r->ID,'_kophi_statut',true)==='pending'; }));
$nb_confirmed = count(array_filter($all_res, function($r){ return get_post_meta($r->ID,'_kophi_statut',true)==='confirmed'; }));
$nb_cancelled = count(array_filter($all_res, function($r){ return get_post_meta($r->ID,'_kophi_statut',true)==='cancelled'; }));
?>

<div class="kophi-stats">
  <div class="kophi-stat orange"><div class="kophi-stat-label">En attente</div><div class="kophi-stat-val"><?= $nb_pending ?></div></div>
  <div class="kophi-stat green"><div class="kophi-stat-label">Confirmées</div><div class="kophi-stat-val"><?= $nb_confirmed ?></div></div>
  <div class="kophi-stat red"><div class="kophi-stat-label">Annulées</div><div class="kophi-stat-val"><?= $nb_cancelled ?></div></div>
  <div class="kophi-stat violet"><div class="kophi-stat-label">Total</div><div class="kophi-stat-val"><?= count($all_res) ?></div></div>
</div>

<div class="kophi-card" style="padding:0;overflow:hidden">
<?php if (empty($all_res)): ?>
  <div class="kophi-empty" style="padding:40px"><div class="kophi-empty-icon">📋</div><p>Aucune réservation pour le moment</p></div>
<?php else: ?>
  <table class="kophi-table" id="res-table">
    <thead>
      <tr>
        <th>Artiste / Client</th>
        <th>Contact</th>
        <th>Service</th>
        <th>Date souhaitée</th>
        <th>Forfait</th>
        <th>Statut</th>
        <th>Reçu le</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($all_res as $r):
      $nom     = get_post_meta($r->ID,'_kophi_nom_artiste',true);
      $tel     = get_post_meta($r->ID,'_kophi_telephone',true);
      $email   = get_post_meta($r->ID,'_kophi_email',true);
      $service = get_post_meta($r->ID,'_kophi_service',true);
      $forfait = get_post_meta($r->ID,'_kophi_forfait',true);
      $date    = get_post_meta($r->ID,'_kophi_date_souhaitee',true);
      $heure   = get_post_meta($r->ID,'_kophi_heure_souhaitee',true);
      $statut  = get_post_meta($r->ID,'_kophi_statut',true) ?: 'pending';
      $message = get_post_meta($r->ID,'_kophi_message',true);
      $wa      = 'https://wa.me/'.preg_replace('/[^0-9]/','', $tel);
      $confirm_url = wp_nonce_url(admin_url("admin-post.php?action=kophi_confirm_res&id={$r->ID}"),'kophi_confirm_res_'.$r->ID);
      $cancel_url  = wp_nonce_url(admin_url("admin-post.php?action=kophi_cancel_res&id={$r->ID}"),'kophi_cancel_res_'.$r->ID);
      $initiales   = strtoupper(substr($nom,0,2));
      $pay_ref     = get_post_meta($r->ID,'_kophi_order_ref',true);
      $pay_status  = get_post_meta($r->ID,'_kophi_payment_status',true);
    ?>
    <tr data-statut="<?= esc_attr($statut) ?>">
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div class="kophi-avatar" style="font-size:12px"><?= esc_html($initiales) ?></div>
          <div>
            <div class="td-name"><?= esc_html($nom) ?></div>
            <?php if ($message): ?><div style="font-size:10px;color:var(--k-text4);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= esc_attr($message) ?>"><?= esc_html(substr($message,0,50)) ?>...</div><?php endif; ?>
          </div>
        </div>
      </td>
      <td>
        <div style="font-size:12px"><?= esc_html($tel) ?></div>
        <?php if ($email): ?><div style="font-size:10px;color:var(--k-text4)"><?= esc_html($email) ?></div><?php endif; ?>
      </td>
      <td style="font-size:11px"><?= esc_html($svc_labels[$service]??$service) ?></td>
      <td>
        <div style="font-size:12px;font-weight:600;color:var(--k-gold)"><?= $date?date('d/m/Y',strtotime($date)):'—' ?></div>
        <?php if ($heure): ?><div style="font-size:10px;color:var(--k-text3)">à <?= esc_html(substr($heure,0,5)) ?></div><?php endif; ?>
      </td>
      <td style="font-size:11px;color:var(--k-gold)"><?= esc_html($forf_labels[$forfait]??'—') ?></td>
      <td><span class="kophi-badge <?= esc_attr($statut) ?>"><?= esc_html(['pending'=>'⏳ En attente','confirmed'=>'✅ Confirmée','cancelled'=>'❌ Annulée'][$statut]??$statut) ?></span></td>
      <td style="font-size:11px;color:var(--k-text4)"><?= date('d/m/Y',strtotime($r->post_date)) ?></td>
      <td>
        <div class="kophi-btn-group">
          <?php if ($statut==='pending'): ?>
            <a href="<?= $confirm_url ?>" class="kophi-btn kophi-btn-green kophi-btn-sm" title="Confirmer">✓</a>
            <a href="<?= $cancel_url ?>" class="kophi-btn kophi-btn-red kophi-btn-sm" title="Annuler" onclick="return confirm('Annuler cette réservation ?')">✕</a>
          <?php endif; ?>
          <?php if ($service !== 'achat_beat'): ?>
            <?php if ($pay_status === 'declared'): ?>
              <button type="button" class="kophi-btn kophi-btn-primary kophi-btn-sm" onclick="kophiConfirmerPaiement('<?= esc_js($pay_ref) ?>', this)" title="Confirmer le paiement déclaré">✅ Payé</button>
            <?php elseif ($pay_ref): ?>
              <button type="button" class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="navigator.clipboard.writeText('<?= esc_js(home_url('/paiement/'.$pay_ref.'/')) ?>');this.textContent='✅ Copié'" title="Copier le lien de paiement">🔗</button>
            <?php else: ?>
              <button type="button" class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="kophiGenererLienPaiement(<?= (int)$r->ID ?>)" title="Générer un lien de paiement en ligne">💳 Facturer</button>
            <?php endif; ?>
          <?php endif; ?>
          <a href="<?= $wa ?>" target="_blank" class="kophi-btn kophi-btn-wa kophi-btn-sm" title="WhatsApp">💬</a>
          <a href="<?= admin_url("post.php?post={$r->ID}&action=edit") ?>" class="kophi-btn kophi-btn-outline kophi-btn-sm" title="Modifier">✏</a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<!-- Lien public -->
<div class="kophi-card" style="border-color:var(--k-gold-b);margin-top:20px">
  <div class="kophi-card-title">🔗 Lien de réservation à partager</div>
  <div style="font-size:12px;color:var(--k-text3);margin-bottom:12px">Partagez ce lien à vos clients sur WhatsApp ou Instagram :</div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <code style="background:var(--k-dark4);padding:8px 14px;border-radius:8px;font-size:12px;color:var(--k-gold);border:1px solid var(--k-border)"><?= home_url('/reserver') ?></code>
    <a href="<?= home_url('/reserver') ?>" target="_blank" class="kophi-btn kophi-btn-primary kophi-btn-sm">Ouvrir →</a>
    <a href="https://wa.me/?text=<?= urlencode('Réservez votre session studio KOPHI\'S MUSIC STUDIO : '.home_url('/reserver')) ?>" target="_blank" class="kophi-btn kophi-btn-wa kophi-btn-sm">Partager WhatsApp</a>
  </div>
</div>

</div></div>

<script>
function filterTable() {
  const val = document.getElementById('filter-statut').value;
  document.querySelectorAll('#res-table tbody tr').forEach(row => {
    row.style.display = (!val || row.dataset.statut === val) ? '' : 'none';
  });
}
</script>

<?php
// Action annuler réservation
add_action('admin_post_kophi_cancel_res', function() {
  $id = (int)($_GET['id']??0);
  check_admin_referer('kophi_cancel_res_'.$id);
  update_post_meta($id,'_kophi_statut','cancelled');
  wp_redirect(admin_url('admin.php?page=kophi-reservations&kophi_msg=cancelled'));
  exit;
});
?>
