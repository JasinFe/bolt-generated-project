<?php if (!defined('ABSPATH')) exit; ?>
<div class="kophi-wrap"><div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">🎹</div>
    <h1>BOUTIQUE BEATS</h1>
  </div>
  <div class="kophi-btn-group">
    <a href="<?= home_url('/beats') ?>" target="_blank" class="kophi-btn kophi-btn-outline">🔗 Page publique</a>
    <a href="<?= admin_url('post-new.php?post_type=kophi_beat') ?>" class="kophi-btn kophi-btn-primary">+ Nouveau beat</a>
  </div>
</div>

<?php
$beats = get_posts(['post_type'=>'kophi_beat','posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC']);
$nb_dispo=0; $rev_total=0; $nb_ventes=0;
foreach($beats as $b) {
  if(get_post_meta($b->ID,'_kophi_disponible',true)) $nb_dispo++;
  $rev_total += (int)get_post_meta($b->ID,'_kophi_nb_ventes_ne',true) * (int)get_post_meta($b->ID,'_kophi_prix_non_exclusive',true);
  $nb_ventes += (int)get_post_meta($b->ID,'_kophi_nb_ventes_ne',true);
}
?>

<div class="kophi-stats">
  <div class="kophi-stat gold"><div class="kophi-stat-label">Beats catalogue</div><div class="kophi-stat-val"><?= count($beats) ?></div></div>
  <div class="kophi-stat green"><div class="kophi-stat-label">Disponibles</div><div class="kophi-stat-val"><?= $nb_dispo ?></div></div>
  <div class="kophi-stat violet"><div class="kophi-stat-label">Ventes NE</div><div class="kophi-stat-val"><?= $nb_ventes ?></div></div>
  <div class="kophi-stat gold"><div class="kophi-stat-label">CA Beats</div><div class="kophi-stat-val"><?= number_format($rev_total/1000,0) ?>K</div><div class="kophi-stat-sub">F CFA</div></div>
</div>

<!-- Demandes d'achat en attente -->
<?php
// BUGFIX : ce filtre cherchait _kophi_statut = 'pending', alors que le code de création
// de commande (includes/payment.php) affecte toujours 'payment_pending' — cette liste
// ne pouvait donc jamais afficher aucune commande venant du parcours d'achat en ligne.
// On inclut aussi 'payment_declared' (client ayant déclaré avoir payé, à vérifier).
$achats = get_posts([
    'post_type'      => 'kophi_reservation',
    'posts_per_page' => -1,
    'meta_query'     => [
        'relation' => 'AND',
        ['key' => '_kophi_service', 'value' => 'achat_beat'],
        ['key' => '_kophi_statut', 'value' => ['payment_pending', 'payment_declared'], 'compare' => 'IN'],
    ],
]);
if(!empty($achats)):
?>
<div class="kophi-card" style="border-color:rgba(232,184,75,.4);margin-bottom:20px">
  <div class="kophi-card-title" style="color:var(--k-gold)">⏳ Demandes d'achat en attente (<?= count($achats) ?>)</div>
  <table class="kophi-table">
    <thead><tr><th>Acheteur</th><th>Beat</th><th>Licence</th><th>Prix</th><th>Contact</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($achats as $a):
      $nom     = get_post_meta($a->ID,'_kophi_nom_artiste',true);
      $tel     = get_post_meta($a->ID,'_kophi_telephone',true);
      $beat_t  = get_post_meta($a->ID,'_kophi_beat_titre',true);
      $licence = get_post_meta($a->ID,'_kophi_licence_type',true);
      $prix    = get_post_meta($a->ID,'_kophi_forfait',true);
      $statut  = get_post_meta($a->ID,'_kophi_statut',true);
      $ref     = get_post_meta($a->ID,'_kophi_order_ref',true);
      $declare_ref  = get_post_meta($a->ID,'_kophi_payment_declared_ref',true);
      $wa      = 'https://wa.me/'.preg_replace('/[^0-9]/','', $tel).'?text='.urlencode("Bonjour, concernant votre demande d'achat du beat \"$beat_t\", voici comment procéder au paiement :");
    ?>
    <tr>
      <td class="td-name"><?= esc_html($nom) ?></td>
      <td><?= esc_html($beat_t) ?></td>
      <td><span class="kophi-badge <?= $licence==='exclusive'?'live':'pending' ?>"><?= $licence==='exclusive'?'🔑 Exclusive':'🔓 Non-Excl.' ?></span></td>
      <td style="color:var(--k-gold);font-weight:700"><?= $prix?number_format((float)$prix,0,',',' ').' F':'—' ?></td>
      <td><a href="<?= $wa ?>" target="_blank" style="color:#25D366">📱 <?= esc_html($tel) ?></a></td>
      <td>
        <?php if ($statut === 'payment_declared'): ?>
          <span class="kophi-badge live" title="Réf. transaction fournie : <?= esc_attr($declare_ref) ?>">💰 Déclaré</span>
        <?php else: ?>
          <span class="kophi-badge pending">⏳ En attente</span>
        <?php endif; ?>
      </td>
      <td>
        <div class="kophi-btn-group">
          <?php if ($statut === 'payment_declared'): ?>
            <button type="button" class="kophi-btn kophi-btn-primary kophi-btn-sm" onclick="kophiConfirmerPaiement('<?= esc_js($ref) ?>', this)">✅ Confirmer paiement</button>
          <?php endif; ?>
          <?php if ($ref): ?>
            <button type="button" class="kophi-btn kophi-btn-outline kophi-btn-sm" onclick="navigator.clipboard.writeText('<?= esc_js(home_url('/paiement/'.$ref.'/')) ?>');this.textContent='✅ Copié'">🔗 Lien paiement</button>
          <?php endif; ?>
          <a href="<?= $wa ?>" target="_blank" class="kophi-btn kophi-btn-wa kophi-btn-sm">💬 Contacter</a>
          <a href="<?= admin_url("post.php?post={$a->ID}&action=edit") ?>" class="kophi-btn kophi-btn-outline kophi-btn-sm">✏</a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Catalogue beats -->
<div class="kophi-card" style="padding:0;overflow:hidden">
<div style="padding:14px 16px;border-bottom:1px solid var(--k-border);font-size:11px;font-weight:700;letter-spacing:1.5px;color:var(--k-text3);text-transform:uppercase">Catalogue complet</div>
<?php if(empty($beats)): ?>
<div class="kophi-empty"><div class="kophi-empty-icon">🎹</div><p>Aucun beat dans le catalogue.<br><a href="<?= admin_url('post-new.php?post_type=kophi_beat') ?>" style="color:var(--k-gold)">Ajouter le premier beat →</a></p></div>
<?php else: ?>
<table class="kophi-table">
  <thead><tr><th>Beat</th><th>Genre</th><th>BPM</th><th>Prix NE</th><th>Prix EX</th><th>Ventes NE</th><th>Dispo</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($beats as $b):
    $tempo   = get_post_meta($b->ID,'_kophi_tempo',true);
    $prix_ne = (int)get_post_meta($b->ID,'_kophi_prix_non_exclusive',true);
    $prix_ex = (int)get_post_meta($b->ID,'_kophi_prix_exclusive',true);
    $ventes  = (int)get_post_meta($b->ID,'_kophi_nb_ventes_ne',true);
    $dispo   = get_post_meta($b->ID,'_kophi_disponible',true);
    $ex_sold = get_post_meta($b->ID,'_kophi_exclusive_vendu',true);
    $genres  = wp_get_post_terms($b->ID,'kophi_genre',['fields'=>'names']);
    $audio   = get_post_meta($b->ID,'_kophi_fichier_audio',true);
    $prod    = get_post_meta($b->ID,'_kophi_producteur',true);
  ?>
  <tr>
    <td>
      <div style="display:flex;align-items:center;gap:10px">
        <?php if(has_post_thumbnail($b->ID)): ?>
        <div style="width:34px;height:34px;border-radius:6px;overflow:hidden;flex-shrink:0"><?= get_the_post_thumbnail($b->ID,[34,34]) ?></div>
        <?php else: ?>
        <div style="width:34px;height:34px;border-radius:6px;background:linear-gradient(135deg,var(--k-violet),var(--k-gold));display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">🎹</div>
        <?php endif; ?>
        <div>
          <div class="td-name"><?= esc_html($b->post_title) ?></div>
          <div style="font-size:10px;color:var(--k-text4)"><?= esc_html($prod) ?: 'Beatmaker KOPHI' ?></div>
        </div>
      </div>
    </td>
    <td style="font-size:11px"><?= esc_html(implode(', ',$genres)) ?: '—' ?></td>
    <td><strong><?= esc_html($tempo) ?></strong><span style="font-size:9px;color:var(--k-text4)"> BPM</span></td>
    <td style="color:var(--k-gold)"><?= $prix_ne?number_format($prix_ne,0,',',' ').' F':'—' ?></td>
    <td style="color:#C084FC"><?= $ex_sold?'<span style="text-decoration:line-through;color:var(--k-text4)">'.number_format($prix_ex,0,',',' ').' F</span> <span style="font-size:10px;color:var(--k-red)">VENDUE</span>':($prix_ex?number_format($prix_ex,0,',',' ').' F':'—') ?></td>
    <td style="font-weight:700;color:var(--k-violet-text,#C084FC)"><?= $ventes ?></td>
    <td><?= $dispo?'<span style="color:var(--k-green);font-weight:700">✓ Oui</span>':'<span style="color:var(--k-red)">✗ Non</span>' ?></td>
    <td>
      <div class="kophi-btn-group">
        <a href="<?= admin_url("post.php?post={$b->ID}&action=edit") ?>" class="kophi-btn kophi-btn-outline kophi-btn-sm">✏</a>
        <?php if($audio): ?><a href="<?= esc_url($audio) ?>" target="_blank" class="kophi-btn kophi-btn-violet kophi-btn-sm">▶</a><?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

</div></div>
