<?php if (!defined('ABSPATH')) exit; ?>
<?php
$kophi_tarifs_saved = false;

// ── Sauvegarde des tarifs directement depuis cette page ──────────────
// Mêmes options que la page Paramètres (kophi_tarif_*) : les deux pages
// restent donc toujours synchronisées, quel que soit l'endroit où l'on modifie.
if (isset($_POST['kophi_save_tarifs']) && check_admin_referer('kophi_tarifs_save')) {
    $tarif_fields = [
        'kophi_tarif_essentielle_min', 'kophi_tarif_essentielle_max', 'kophi_tarif_essentielle_prix',
        'kophi_tarif_pro_min', 'kophi_tarif_pro_max', 'kophi_tarif_pro_prix',
        'kophi_tarif_premium_min', 'kophi_tarif_premium_max', 'kophi_tarif_premium_prix',
        'kophi_tarif_signature_min', 'kophi_tarif_signature_max', 'kophi_tarif_signature_prix',
        'kophi_tarif_faceb',
    ];
    foreach ($tarif_fields as $field) {
        if (isset($_POST[$field])) {
            update_option($field, sanitize_text_field(wp_unslash($_POST[$field])));
        }
    }
    $kophi_tarifs_saved = true;
}

// Formatage "70000" -> "70 000" (espace comme séparateur de milliers, style FCFA).
if (!function_exists('kophi_fmt_fcfa')) {
    function kophi_fmt_fcfa($n) {
        return number_format((float) $n, 0, ',', ' ');
    }
}
?>
<div class="kophi-wrap"><div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">💰</div>
    <h1>TARIFS & WORKFLOW</h1>
  </div>
  <div class="kophi-btn-group">
    <a href="<?= home_url('/reserver') ?>" target="_blank" class="kophi-btn kophi-btn-outline">🔗 Page réservation</a>
  </div>
</div>

<?php if ($kophi_tarifs_saved): ?>
<div style="padding:10px 14px;background:rgba(0,200,122,.1);border:1px solid rgba(0,200,122,.3);border-radius:8px;color:#00C87A;font-size:12px;margin-bottom:16px">
  ✅ Tarifs enregistrés avec succès.
</div>
<?php endif; ?>

<form method="POST">
<?php wp_nonce_field('kophi_tarifs_save'); ?>
<input type="hidden" name="kophi_save_tarifs" value="1">

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<!-- Forfaits Studio -->
<div class="kophi-card">
  <div class="kophi-card-title">🎙 Forfaits Enregistrement</div>
  <?php
  $tarifs_data = [
    [
      'titre' => 'SESSION ESSENTIELLE', 'ico' => '🎙', 'duree' => '4h',
      'seance' => '1 ou 2 séances de 2h', 'note' => 'Idéal pour 1 titre bien travaillé',
      'min' => get_option('kophi_tarif_essentielle_min', '50000'),
      'max' => get_option('kophi_tarif_essentielle_max', '70000'),
      'prix' => get_option('kophi_tarif_essentielle_prix', '70000'),
      'field' => 'kophi_tarif_essentielle_prix',
    ],
    [
      'titre' => 'SESSION PRO', 'ico' => '🎙', 'duree' => '6h',
      'seance' => '1 ou 2 séances de 3h', 'note' => 'Produit étendu avec finitions avancées',
      'min' => get_option('kophi_tarif_pro_min', '80000'),
      'max' => get_option('kophi_tarif_pro_max', '100000'),
      'prix' => get_option('kophi_tarif_pro_prix', '100000'),
      'field' => 'kophi_tarif_pro_prix',
    ],
    [
      'titre' => 'SESSION PREMIUM', 'ico' => '🎚', 'duree' => '8h',
      'seance' => '2 séances de 4h', 'note' => 'Deux sessions pour un rendu premium',
      'min' => get_option('kophi_tarif_premium_min', '120000'),
      'max' => get_option('kophi_tarif_premium_max', '150000'),
      'prix' => get_option('kophi_tarif_premium_prix', '150000'),
      'field' => 'kophi_tarif_premium_prix',
    ],
    [
      'titre' => 'SESSION SIGNATURE', 'ico' => '✨', 'duree' => '12h',
      'seance' => '3 séances de 4h', 'note' => 'Projet complet haut de gamme',
      'min' => get_option('kophi_tarif_signature_min', '200000'),
      'max' => get_option('kophi_tarif_signature_max', '250000'),
      'prix' => get_option('kophi_tarif_signature_prix', '250000'),
      'field' => 'kophi_tarif_signature_prix',
    ],
  ];
  foreach ($tarifs_data as $t): ?>
  <div style="padding:12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:10px;margin-bottom:8px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
      <div style="display:flex;align-items:center;gap:12px">
        <span style="font-size:20px"><?= $t['ico'] ?></span>
        <div>
          <div style="font-size:11px;font-weight:700;color:var(--k-text);letter-spacing:.5px"><?= esc_html($t['titre']) ?> — <?= esc_html($t['duree']) ?></div>
          <div style="font-size:10px;color:var(--k-text4)"><?= esc_html($t['seance']) ?></div>
          <div style="font-size:9px;color:var(--k-text4);font-style:italic"><?= esc_html($t['note']) ?></div>
        </div>
      </div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:18px;color:var(--k-gold);text-align:right;white-space:nowrap">
        <?= kophi_fmt_fcfa($t['min']) ?> – <?= kophi_fmt_fcfa($t['max']) ?><span style="font-size:11px;color:var(--k-text4)"> F CFA</span>
      </div>
    </div>
    <div style="margin-top:10px;display:flex;align-items:center;gap:8px">
      <label style="font-size:10px;color:var(--k-text3);white-space:nowrap">Prix affiché à la réservation</label>
      <input type="number" name="<?= esc_attr($t['field']) ?>" value="<?= esc_attr($t['prix']) ?>" style="max-width:140px">
      <span style="font-size:10px;color:var(--k-text4)">F CFA</span>
    </div>
  </div>
  <?php endforeach; ?>
  <!-- Face B -->
  <div style="padding:12px;background:var(--k-dark3);border:1px solid var(--k-violet-b);border-radius:10px;margin-top:6px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
      <div style="display:flex;align-items:center;gap:12px">
        <span style="font-size:20px">🎵</span>
        <div>
          <div style="font-size:11px;font-weight:700;color:#C084FC;letter-spacing:.5px">SESSION FACE B — 1h30</div>
          <div style="font-size:10px;color:var(--k-text4)">Client vient avec son beat · Voix, Mix & Master</div>
        </div>
      </div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:22px;color:#C084FC">
        <?= kophi_fmt_fcfa(get_option('kophi_tarif_faceb', '10000')) ?><span style="font-size:11px;color:var(--k-text4)"> F CFA</span>
      </div>
    </div>
    <div style="margin-top:10px;display:flex;align-items:center;gap:8px">
      <label style="font-size:10px;color:var(--k-text3);white-space:nowrap">Prix affiché à la réservation</label>
      <input type="number" name="kophi_tarif_faceb" value="<?= esc_attr(get_option('kophi_tarif_faceb', '10000')) ?>" style="max-width:140px">
      <span style="font-size:10px;color:var(--k-text4)">F CFA</span>
    </div>
  </div>
</div>

<!-- Services additionnels -->
<div class="kophi-card">
  <div class="kophi-card-title">🎹 Beats & Services</div>
  <?php $services = [
    ['Beat Custom','30 000 – 100 000 F','🎹','Composition sur mesure','Délai : 2-7 jours selon complexité'],
    ['Licence Non-Exclusive','30 000 F+','🔓','Jusqu\'à 500K streams · 10 ans','Crédits obligatoires · BURIDA inclus'],
    ['Licence Exclusive','350 000 – 800 000 F','🔑','Droits illimités et perpétuels','Stems inclus · Retrait du marché'],
    ['Mix & Master','Sur devis','🎚','Fichiers envoyés à distance','Livraison 48-72h'],
    ['Mastering seul','Sur devis','✨','Pour projets déjà mixés','Format WAV 24bit recommandé'],
  ];
  foreach($services as [$titre,$prix,$ico,$desc,$note]): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:10px;margin-bottom:8px">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-size:18px"><?= $ico ?></span>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--k-text)"><?= $titre ?></div>
        <div style="font-size:10px;color:var(--k-text4)"><?= $desc ?></div>
        <div style="font-size:9px;color:var(--k-text4);font-style:italic"><?= $note ?></div>
      </div>
    </div>
    <div style="font-family:'Bebas Neue',sans-serif;font-size:16px;color:var(--k-gold);text-align:right;white-space:nowrap"><?= $prix ?></div>
  </div>
  <?php endforeach; ?>
</div>

</div><!-- /grid -->

<div style="margin-top:16px">
  <button type="submit" class="kophi-btn kophi-btn-gold">💾 Enregistrer les tarifs</button>
</div>

</form>

<!-- Workflow de réservation -->
<div class="kophi-card" style="margin-top:20px">
  <div class="kophi-card-title">⚙ Workflow de réservation</div>
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;text-align:center">
    <?php $steps = [
      ['1','Client soumet la demande','Via studio.kophismusic.com/reserver','#E8B84B'],
      ['2','Email envoyé à l\'équipe','contact@kophismusic.com notifié','#8B3FD9'],
      ['3','Confirmation WhatsApp','L\'équipe contacte le client','#25D366'],
      ['4','Session créée dans le planning','CPT kophi_session généré auto','#FF8C42'],
      ['5','Paiement & session studio','Le jour J, session enregistrée','#00C87A'],
    ];
    foreach($steps as [$num,$titre,$desc,$color]): ?>
    <div style="background:var(--k-dark3);border:1px solid var(--k-border);border-radius:12px;padding:16px 10px;position:relative">
      <div style="width:28px;height:28px;border-radius:50%;background:<?= $color ?>;color:#000;font-family:'Bebas Neue',sans-serif;font-size:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px"><?= $num ?></div>
      <div style="font-size:10px;font-weight:700;color:#fff;margin-bottom:4px;line-height:1.3"><?= $titre ?></div>
      <div style="font-size:9px;color:var(--k-text4);line-height:1.4"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modifier les tarifs (lien Paramètres) -->
<div class="kophi-card" style="border-color:var(--k-gold-b);margin-top:16px">
  <div class="kophi-card-title">⚙ Modifier les tarifs</div>
  <p style="font-size:12px;color:var(--k-text3);margin-bottom:12px">Vous pouvez modifier les prix ci-dessus directement sur cette page, ou depuis les <strong>Paramètres Studio</strong> — les deux sont synchronisés :</p>
  <a href="<?= admin_url('admin.php?page=kophi-settings') ?>" class="kophi-btn kophi-btn-gold">⚙ Paramètres Studio →</a>
</div>

</div></div>
