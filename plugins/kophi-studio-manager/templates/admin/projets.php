<?php if (!defined('ABSPATH')) exit; ?>
<div class="kophi-wrap"><div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">🎵</div>
    <h1>PROJETS MUSICAUX</h1>
  </div>
  <div class="kophi-btn-group">
    <select id="filter-statut-p" onchange="filterProjets()" style="font-size:11px;padding:7px 12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text)">
      <option value="">Tous</option><option value="en_cours">En cours</option><option value="en_attente">En attente</option><option value="termine">Terminés</option>
    </select>
    <a href="<?= admin_url('post-new.php?post_type=kophi_projet') ?>" class="kophi-btn kophi-btn-primary">+ Nouveau projet</a>
  </div>
</div>

<?php
$projets = get_posts(['post_type'=>'kophi_projet','posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC']);
$nb_cours=0;$nb_done=0;$budget_total=0;
foreach($projets as $p){
  $st=get_post_meta($p->ID,'_kophi_statut',true);
  if($st==='en_cours') $nb_cours++;
  if($st==='termine') $nb_done++;
  $budget_total+=(float)get_post_meta($p->ID,'_kophi_budget',true);
}
$type_labels=['single'=>'Single','ep'=>'EP','album'=>'Album','mixtape'=>'Mixtape','clip'=>'Clip','autre'=>'Autre'];
$statut_labels=['en_cours'=>'🔵 En cours','en_attente'=>'⏳ En attente','termine'=>'✅ Terminé','abandonne'=>'❌ Abandonné'];
$statut_class=['en_cours'=>'live','en_attente'=>'pending','termine'=>'confirmed','abandonne'=>'cancelled'];
?>

<div class="kophi-stats">
  <div class="kophi-stat violet"><div class="kophi-stat-label">Total projets</div><div class="kophi-stat-val"><?= count($projets) ?></div></div>
  <div class="kophi-stat red"><div class="kophi-stat-label">En cours</div><div class="kophi-stat-val"><?= $nb_cours ?></div></div>
  <div class="kophi-stat green"><div class="kophi-stat-label">Terminés</div><div class="kophi-stat-val"><?= $nb_done ?></div></div>
  <div class="kophi-stat gold"><div class="kophi-stat-label">Budget total</div><div class="kophi-stat-val"><?= number_format($budget_total/1000,0) ?>K</div><div class="kophi-stat-sub">F CFA</div></div>
</div>

<div class="kophi-card" style="padding:0;overflow:hidden">
<?php if(empty($projets)): ?>
<div class="kophi-empty"><div class="kophi-empty-icon">🎵</div><p>Aucun projet enregistré.</p></div>
<?php else: ?>
<table class="kophi-table" id="projets-table">
  <thead><tr><th>Projet</th><th>Type</th><th>Artiste</th><th>Avancement</th><th>Budget</th><th>Livraison</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($projets as $p):
    $titre   = $p->post_title;
    $type    = get_post_meta($p->ID,'_kophi_type_projet',true);
    $av      = (int)get_post_meta($p->ID,'_kophi_avancement',true);
    $budget  = (float)get_post_meta($p->ID,'_kophi_budget',true);
    $livr    = get_post_meta($p->ID,'_kophi_date_livraison',true);
    $statut  = get_post_meta($p->ID,'_kophi_statut',true) ?: 'en_cours';
    $artiste_id = get_post_meta($p->ID,'_kophi_artiste',true);
    $artiste = $artiste_id ? get_post_meta($artiste_id,'_kophi_nom_artiste',true) ?: get_the_title($artiste_id) : get_post_meta($p->ID,'_kophi_artiste',true);
    $bar_color = $av>=100?'#00C87A':($av>=60?'#E8B84B':'#8B3FD9');
  ?>
  <tr data-statut="<?= esc_attr($statut) ?>">
    <td>
      <div class="td-name"><?= esc_html($titre) ?></div>
      <div style="font-size:10px;color:var(--k-text4)"><?= get_post_meta($p->ID,'_kophi_nb_titres',true) ?> titre(s) prévus</div>
    </td>
    <td style="font-size:11px"><?= esc_html($type_labels[$type]??$type) ?></td>
    <td style="font-size:12px"><?= esc_html($artiste) ?: '—' ?></td>
    <td style="min-width:120px">
      <div style="display:flex;align-items:center;gap:8px">
        <div style="flex:1;background:var(--k-dark4);border-radius:10px;height:6px;overflow:hidden">
          <div style="height:100%;width:<?= $av ?>%;background:<?= $bar_color ?>;transition:width .3s;border-radius:10px"></div>
        </div>
        <span style="font-size:10px;font-weight:700;color:<?= $bar_color ?>;white-space:nowrap"><?= $av ?>%</span>
      </div>
    </td>
    <td><?= $budget?number_format($budget,0,',',' ').' F':'—' ?></td>
    <td style="font-size:11px"><?= $livr?date('d/m/Y',strtotime($livr)):'—' ?></td>
    <td><span class="kophi-badge <?= $statut_class[$statut]??'pending' ?>"><?= $statut_labels[$statut]??$statut ?></span></td>
    <td><a href="<?= admin_url("post.php?post={$p->ID}&action=edit") ?>" class="kophi-btn kophi-btn-outline kophi-btn-sm">✏ Modifier</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

</div></div>
<script>
function filterProjets(){
  const st=document.getElementById('filter-statut-p').value;
  document.querySelectorAll('#projets-table tbody tr').forEach(r=>{r.style.display=(!st||r.dataset.statut===st)?'':'none';});
}
</script>
