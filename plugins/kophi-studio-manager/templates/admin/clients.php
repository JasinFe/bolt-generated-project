<?php if (!defined('ABSPATH')) exit; ?>
<div class="kophi-wrap"><div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">👤</div>
    <h1>CLIENTS</h1>
  </div>
  <div class="kophi-btn-group">
    <input type="search" id="search-client" onkeyup="searchClients()" placeholder="Rechercher un client..." style="font-size:11px;padding:7px 12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);width:200px">
    <select id="filter-statut-c" onchange="searchClients()" style="font-size:11px;padding:7px 12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text)">
      <option value="">Tous</option><option value="actif">Actifs</option><option value="vip">VIP</option><option value="inactif">Inactifs</option>
    </select>
    <a href="<?= admin_url('post-new.php?post_type=kophi_client') ?>" class="kophi-btn kophi-btn-primary">+ Nouveau client</a>
  </div>
</div>

<?php
$clients = get_posts(['post_type'=>'kophi_client','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
$nb_vip = 0; $nb_actifs = 0; $total_depenses = 0;
foreach($clients as $c) {
  $st = get_post_meta($c->ID,'_kophi_statut_client',true) ?: 'actif';
  if($st==='vip') $nb_vip++;
  if($st!=='inactif') $nb_actifs++;
  $total_depenses += (float)get_post_meta($c->ID,'_kophi_total_depense',true);
}
?>

<div class="kophi-stats">
  <div class="kophi-stat violet"><div class="kophi-stat-label">Total clients</div><div class="kophi-stat-val"><?= count($clients) ?></div></div>
  <div class="kophi-stat green"><div class="kophi-stat-label">Clients actifs</div><div class="kophi-stat-val"><?= $nb_actifs ?></div></div>
  <div class="kophi-stat gold"><div class="kophi-stat-label">Clients VIP ⭐</div><div class="kophi-stat-val"><?= $nb_vip ?></div></div>
  <div class="kophi-stat green"><div class="kophi-stat-label">CA total clients</div><div class="kophi-stat-val"><?= number_format($total_depenses/1000,0) ?>K</div><div class="kophi-stat-sub">F CFA</div></div>
</div>

<div class="kophi-card" style="padding:0;overflow:hidden">
<?php if(empty($clients)): ?>
<div class="kophi-empty"><div class="kophi-empty-icon">👤</div><p>Aucun client enregistré.<br><a href="<?= admin_url('post-new.php?post_type=kophi_client') ?>" style="color:var(--k-gold)">Ajouter le premier client →</a></p></div>
<?php else: ?>
<table class="kophi-table" id="clients-table">
  <thead><tr><th>Artiste</th><th>Téléphone</th><th>Genre</th><th>Ville</th><th>Sessions</th><th>Dépenses</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php
  $genre_labels = ['gospel'=>'Gospel','afro_gospel'=>'Afro Gospel','trap_gospel'=>'Trap Gospel','worship'=>'Worship','afrobeats'=>'Afrobeats','coupe_decale'=>'Coupé-Décalé','hip_hop'=>'Hip-Hop','rnb'=>'R&B','autre'=>'Autre'];
  $statut_labels = ['actif'=>'Actif','vip'=>'⭐ VIP','inactif'=>'Inactif'];
  $statut_colors = ['actif'=>'confirmed','vip'=>'live','inactif'=>'cancelled'];
  foreach($clients as $c):
    $nom      = get_post_meta($c->ID,'_kophi_nom_artiste',true) ?: $c->post_title;
    $nom_reel = get_post_meta($c->ID,'_kophi_nom_reel',true);
    $tel      = get_post_meta($c->ID,'_kophi_telephone',true);
    $email    = get_post_meta($c->ID,'_kophi_email',true);
    $genre    = get_post_meta($c->ID,'_kophi_genre_musical',true);
    $ville    = get_post_meta($c->ID,'_kophi_ville',true);
    $nb_sess  = get_post_meta($c->ID,'_kophi_nb_sessions',true);
    $depense  = (float)get_post_meta($c->ID,'_kophi_total_depense',true);
    $statut   = get_post_meta($c->ID,'_kophi_statut_client',true) ?: 'actif';
    $wa       = 'https://wa.me/'.preg_replace('/[^0-9]/','', $tel);
    $initials = strtoupper(substr($nom,0,2));
    $genres = is_array($genre) ? array_map(function($g) use ($genre_labels) { return isset($genre_labels[$g]) ? $genre_labels[$g] : $g; }, $genre) : ($genre ? [$genre_labels[$genre] ?? $genre] : []);
  ?>
  <tr data-statut="<?= esc_attr($statut) ?>" data-name="<?= esc_attr(strtolower($nom.' '.$nom_reel.' '.$tel)) ?>">
    <td>
      <div style="display:flex;align-items:center;gap:10px">
        <?php if(has_post_thumbnail($c->ID)): ?>
        <div style="width:34px;height:34px;border-radius:50%;overflow:hidden;flex-shrink:0"><?= get_the_post_thumbnail($c->ID,[34,34]) ?></div>
        <?php else: ?>
        <div class="kophi-avatar"><?= esc_html($initials) ?></div>
        <?php endif; ?>
        <div>
          <div class="td-name"><?= esc_html($nom) ?></div>
          <?php if($nom_reel): ?><div style="font-size:10px;color:var(--k-text4)"><?= esc_html($nom_reel) ?></div><?php endif; ?>
        </div>
      </div>
    </td>
    <td>
      <a href="<?= $wa ?>" target="_blank" style="color:#25D366;font-size:12px;text-decoration:none">📱 <?= esc_html($tel) ?></a>
      <?php if($email): ?><div style="font-size:10px;color:var(--k-text4)"><?= esc_html($email) ?></div><?php endif; ?>
    </td>
    <td style="font-size:11px"><?= esc_html(implode(', ',$genres)) ?: '—' ?></td>
    <td style="font-size:11px"><?= esc_html($ville) ?: 'Abidjan' ?></td>
    <td><strong style="color:var(--k-violet-text, #C084FC)"><?= $nb_sess ?: 0 ?></strong></td>
    <td><?= $depense>0?'<strong style="color:var(--k-green)">'.number_format($depense,0,',',' ').' F</strong>':'—' ?></td>
    <td><span class="kophi-badge <?= $statut_colors[$statut]??'pending' ?>"><?= $statut_labels[$statut]??$statut ?></span></td>
    <td>
      <div class="kophi-btn-group">
        <a href="<?= admin_url("post.php?post={$c->ID}&action=edit") ?>" class="kophi-btn kophi-btn-outline kophi-btn-sm">✏</a>
        <a href="<?= $wa ?>" target="_blank" class="kophi-btn kophi-btn-wa kophi-btn-sm">💬</a>
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
function searchClients() {
  const q = document.getElementById('search-client').value.toLowerCase();
  const st = document.getElementById('filter-statut-c').value;
  document.querySelectorAll('#clients-table tbody tr').forEach(row => {
    const matchQ = !q || row.dataset.name.includes(q);
    const matchSt = !st || row.dataset.statut === st;
    row.style.display = (matchQ && matchSt) ? '' : 'none';
  });
}
</script>
