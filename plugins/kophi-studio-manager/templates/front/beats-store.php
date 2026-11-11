<?php
if (!defined('ABSPATH')) exit;

$wa        = get_option('kophi_whatsapp', '+2250503404389');
$wa_num    = preg_replace('/[^0-9]/', '', $wa);
$wa_link   = 'https://wa.me/' . $wa_num;
$logo_url  = 'https://studio.kophismusic.com/wp-content/uploads/sites/8/2026/03/LOGO-KOPHIS-MUSIC-STUDIO.png';
$rest_url  = rest_url('kophi/v1/');
$nonce     = wp_create_nonce('wp_rest');

// Layout géré par le shortcode dans kophi-studio-manager.php
?>
<div id="kophi-beats-app" class="kophi-front-wrap">

<!-- ══ HEADER BOUTIQUE BEATS ══════════════════════════════════ -->
<header class="kbs-page-header">
  <div class="kbs-page-header-inner">
    <!-- Logo -->
    <a href="<?= esc_url(home_url('/')) ?>" class="kbs-header-logo" aria-label="KOPHI'S MUSIC STUDIO">
      <img src="<?= esc_url($logo_url) ?>" alt="KM STUDIO" height="38">
    </a>
    <!-- Nav boutique -->
    <nav class="kbs-header-nav">
      <a href="<?= esc_url(home_url('/')) ?>" class="kbs-nav-link">ACCUEIL</a>
      <a href="<?= esc_url(home_url('/beats')) ?>" class="kbs-nav-link active">BOUTIQUE BEATS</a>
      <a href="<?= esc_url(home_url('/reserver')) ?>" class="kbs-nav-link">RÉSERVER</a>
      <a href="https://kophismusic.com/" class="kbs-nav-link" target="_blank" rel="noopener">LABEL</a>
    </nav>
    <!-- Actions -->
    <div class="kbs-header-actions">
      <a href="<?= esc_url($wa_link) ?>?text=<?= urlencode("Bonjour, j'ai une question sur les beats KOPHI'S MUSIC STUDIO !") ?>"
         target="_blank" rel="noopener" class="kbs-header-wa" aria-label="WhatsApp">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
          <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
        </svg>
      </a>
      <a href="<?= esc_url(home_url('/reserver')) ?>" class="kbs-header-cta">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
        RÉSERVER
      </a>
      <!-- Burger mobile -->
      <button class="kbs-burger" id="kbs-burger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
  <!-- Menu mobile -->
  <div class="kbs-mob-nav" id="kbs-mob-nav">
    <a href="<?= esc_url(home_url('/')) ?>" class="kbs-mob-link">ACCUEIL</a>
    <a href="<?= esc_url(home_url('/beats')) ?>" class="kbs-mob-link">BOUTIQUE BEATS</a>
    <a href="<?= esc_url(home_url('/reserver')) ?>" class="kbs-mob-link">RÉSERVER</a>
    <a href="https://kophismusic.com/" class="kbs-mob-link" target="_blank">LABEL</a>
    <a href="<?= esc_url($wa_link) ?>" class="kbs-mob-wa" target="_blank">💬 <?= esc_html($wa) ?></a>
  </div>
</header>
<script>
document.getElementById('kbs-burger')?.addEventListener('click',function(){
  const nav=document.getElementById('kbs-mob-nav');
  const open=nav.classList.toggle('open');
  this.classList.toggle('active',open);
});
</script>

<!-- HEADER BOUTIQUE -->
<div style="text-align:center;margin-bottom:30px">
  <div style="font-family:'Bebas Neue',sans-serif;font-size:13px;letter-spacing:4px;color:var(--kf-text4);margin-bottom:6px">KOPHI'S MUSIC STUDIO</div>
  <h1 style="font-family:'Bebas Neue',sans-serif;font-size:38px;color:#fff;letter-spacing:3px;margin:0">BOUTIQUE BEATS</h1>
  <p style="font-size:12px;color:var(--kf-text2);margin-top:8px">Licences BURIDA · Urban Gospel · Abidjan, Côte d'Ivoire</p>
</div>

<!-- ONGLETS -->
<div class="kbs-tabs" id="kbs-tabs">
  <button class="kbs-tab active" onclick="kbsTab('boutique',this)">🎹 Boutique</button>
  <button class="kbs-tab" onclick="kbsTab('licences',this)">📋 Licences</button>
  <button class="kbs-tab" onclick="kbsTab('custom',this)">✨ Beat Custom</button>
  <button class="kbs-tab" onclick="kbsTab('bundles',this)">🎁 Bundles</button>
  <button class="kbs-tab" onclick="kbsTab('faq',this)">❓ FAQ BURIDA</button>
</div>

<!-- ONGLET BOUTIQUE -->
<div class="kbs-panel active" id="kbs-boutique">
  <!-- Filtres genres -->
  <div class="kbs-filters" id="kbs-genre-filters">
    <button class="kbs-filter active" onclick="kbsFilter('',this)">Tous</button>
    <?php
    $genres = get_terms(['taxonomy'=>'kophi_genre','hide_empty'=>true,'orderby'=>'count','order'=>'DESC']);
    foreach($genres as $g): ?>
    <button class="kbs-filter" onclick="kbsFilter('<?= esc_js($g->name) ?>',this)"><?= esc_html($g->name) ?></button>
    <?php endforeach; ?>
  </div>

  <!-- Grille beats -->
  <div class="kbs-grid" id="kbs-grid">
  <?php
  $beats = get_posts(['post_type'=>'kophi_beat','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'date','order'=>'DESC','meta_query'=>[['key'=>'_kophi_disponible','value'=>'1','compare'=>'=']]]);
  if(empty($beats)): ?>
    <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--kf-text4)">
      <p>Catalogue en cours de mise en ligne. Contactez-nous sur WhatsApp !</p>
      <a href="<?= esc_url($wa_link) ?>" target="_blank" class="kf-btn-wa" style="margin-top:12px;display:inline-block">💬 <?= esc_html($wa) ?></a>
    </div>
  <?php else:
    foreach($beats as $b):
      $tempo   = get_post_meta($b->ID,'_kophi_tempo',true);
      $prix_ne = (int)get_post_meta($b->ID,'_kophi_prix_non_exclusive',true);
      $prix_ex = (int)get_post_meta($b->ID,'_kophi_prix_exclusive',true);
      $ex_sold = get_post_meta($b->ID,'_kophi_exclusive_vendu',true);
      $audio   = get_post_meta($b->ID,'_kophi_fichier_audio',true);
      $tags    = get_post_meta($b->ID,'_kophi_tags',true);
      $duree   = (int)get_post_meta($b->ID,'_kophi_duree_sec',true);
      $genres  = wp_get_post_terms($b->ID,'kophi_genre',['fields'=>'names']);
      $genre   = !empty($genres) ? $genres[0] : '';
      $thumb   = get_the_post_thumbnail_url($b->ID,'medium');
      $duree_fmt = $duree ? gmdate('i:s',$duree) : '—';
      $desc_short = get_post_meta($b->ID,'_kophi_description_courte',true) ?: $b->post_excerpt;
  ?>
  <div class="kbs-beat" data-genre="<?= esc_attr($genre) ?>" data-id="<?= (int) $b->ID ?>"
       data-kp-id="<?= (int) $b->ID ?>" data-kp-url="<?= esc_url($audio) ?>" data-kp-title="<?= esc_attr($b->post_title) ?>"
       data-kp-sub="<?= esc_attr(trim(($genre ? $genre . ' · ' : '') . ($tempo ? $tempo . ' BPM' : ''), ' ·')) ?>"
       data-kp-cover="<?= esc_url($thumb) ?>" data-kp-duration="<?= (int) $duree ?>" data-kp-fallback="<?= esc_url($wa_link) ?>">
    <!-- Pochette (carrée, jamais déformée) — lecture via le lecteur unifié -->
    <div class="kbs-cover" data-kp-play role="button" tabindex="0" aria-label="Écouter <?= esc_attr($b->post_title) ?>">
      <?php if($thumb): ?>
      <img src="<?= esc_url($thumb) ?>" alt="" loading="lazy" decoding="async">
      <?php else: ?>
      <div class="kbs-cover-ph" aria-hidden="true"><svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>
      <?php endif; ?>
      <span class="kbs-play-btn" id="play-<?= (int) $b->ID ?>" aria-hidden="true"><svg class="kp-ico-play" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><svg class="kp-ico-pause" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg></span>
      <span class="kbs-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="kbs-progress" aria-hidden="true"></span>
    </div>
    <!-- Infos -->
    <div class="kbs-info">
      <div class="kbs-title"><?= esc_html($b->post_title) ?></div>
      <div class="kbs-meta">
        <?php if($genre): ?><span class="kbs-genre"><?= esc_html($genre) ?></span><?php endif; ?>
        <?php if($tempo): ?><span>🥁 <?= esc_html($tempo) ?> BPM</span><?php endif; ?>
        <span>⏱ <?= $duree_fmt ?></span>
      </div>
      <?php if($desc_short): ?><div class="kbs-desc"><?= esc_html($desc_short) ?></div><?php endif; ?>
      <?php if($tags): ?>
      <div class="kbs-tags">
        <?php foreach(explode(',',esc_html($tags)) as $tag): ?><span class="kbs-tag"><?= trim($tag) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="kbs-prices">
        <div class="kbs-price-col">
          <div class="kbs-price-label">Non-Exclusive</div>
          <div class="kbs-price-val"><?= $prix_ne?number_format($prix_ne,0,',',' ').' F':'—' ?></div>
        </div>
        <div class="kbs-price-divider"></div>
        <div class="kbs-price-col">
          <div class="kbs-price-label">Exclusive</div>
          <div class="kbs-price-val <?= $ex_sold?'kbs-sold':'' ?>"><?= $ex_sold?'VENDUE':($prix_ex?number_format($prix_ex,0,',',' ').' F':'—') ?></div>
        </div>
      </div>
      <div class="kbs-actions">
        <button class="kbs-btn-ne" onclick="kbsAchat(<?= $b->ID ?>,'<?= esc_js($b->post_title) ?>','non_exclusive',<?= $prix_ne ?>)">🛒 Acheter NE</button>
        <?php if(!$ex_sold): ?>
        <button class="kbs-btn-ex" onclick="kbsAchat(<?= $b->ID ?>,'<?= esc_js($b->post_title) ?>','exclusive',<?= $prix_ex ?>)">🔑 Exclusive</button>
        <?php else: ?>
        <button class="kbs-btn-ex" disabled style="opacity:.4;cursor:not-allowed">🔒 Vendue</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
  </div>
</div>

<!-- ONGLET LICENCES -->
<div class="kbs-panel" id="kbs-licences">
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead>
      <tr>
        <th style="padding:12px 16px;text-align:left;font-size:9px;letter-spacing:2px;color:var(--kf-text4);text-transform:uppercase;border-bottom:1px solid var(--kf-border)">Fonctionnalité</th>
        <th style="padding:12px 16px;text-align:center;background:rgba(255,255,255,.03);border-bottom:1px solid var(--kf-border)"><div style="font-size:11px;font-weight:700;color:var(--kf-gold)">NON-EXCLUSIVE</div><div style="font-size:9px;color:var(--kf-text4)">Lease</div></th>
        <th style="padding:12px 16px;text-align:center;background:rgba(139,63,217,.08);border-bottom:1px solid var(--kf-violet-b)"><div style="font-size:11px;font-weight:700;color:#C084FC">EXCLUSIVE</div><div style="font-size:9px;color:var(--kf-text4)">Transfert de droits</div></th>
      </tr>
    </thead>
    <tbody>
    <?php $rows=[
      ['Streams autorisés','Jusqu\'à 500 000','Illimités ∞'],
      ['Durée de la licence','10 ans','Perpétuelle'],
      ['Distribution commerciale','Oui (avec crédits)','Oui (sans restriction)'],
      ['Beat retiré du marché','Non (reste disponible)','Oui (exclusivité totale)'],
      ['Stems / fichiers séparés','Non','Oui (inclus)'],
      ['Usage YouTube monétisé','Oui','Oui'],
      ['Usage radio / TV','Limité','Illimité'],
      ['Nombre d\'acheteurs','Plusieurs possibles','Unique acheteur'],
      ['Crédits BURIDA obligatoires','Oui','Selon accord'],
      ['Prix indicatif','30 000 F CFA+','350 000 – 800 000 F CFA'],
    ];
    foreach($rows as [$f,$ne,$ex]): ?>
    <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
      <td style="padding:10px 16px;color:var(--kf-text3);font-size:11px"><?= $f ?></td>
      <td style="padding:10px 16px;text-align:center;font-size:11px;color:var(--kf-text2)"><?= $ne ?></td>
      <td style="padding:10px 16px;text-align:center;font-size:11px;color:#C084FC;background:rgba(139,63,217,.04)"><?= $ex ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
    <a href="<?= esc_url($wa_link) ?>?text=<?= urlencode("Bonjour KOPHI'S MUSIC STUDIO, je voudrais en savoir plus sur les licences beats.") ?>" target="_blank" class="kf-btn-wa">💬 Question sur les licences</a>
  </div>
</div>

<!-- ONGLET CUSTOM -->
<div class="kbs-panel" id="kbs-custom">
  <div style="max-width:600px;margin:0 auto">
    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:#fff;text-align:center;letter-spacing:2px;margin-bottom:6px">BEAT SUR MESURE</h2>
    <p style="text-align:center;font-size:12px;color:var(--kf-text2);margin-bottom:24px">Décrivez votre vision, nous créons votre beat unique.<br><strong style="color:var(--kf-gold)">30 000 – 100 000 F CFA</strong> · Délai 2-7 jours</p>

    <div id="kbs-custom-err" style="display:none;background:rgba(255,71,87,.1);border:1px solid rgba(255,71,87,.3);border-radius:10px;padding:12px;font-size:12px;color:#FFB3B8;margin-bottom:14px"></div>

    <div class="kf-form-wrap">
      <div class="kf-form-row">
        <div class="kf-fg"><label>Nom / Artiste *</label><input id="cus-nom" placeholder="Votre nom d'artiste"></div>
        <div class="kf-fg"><label>Téléphone *</label><input id="cus-tel" type="tel" placeholder="+225 07 00 00 00 00"></div>
      </div>
      <div class="kf-fg"><label>Genre musical *</label>
        <select id="cus-genre">
          <option value="">Choisissez un genre</option>
          <option>Gospel Urbain</option><option>Afro Gospel</option><option>Trap Gospel</option><option>Worship / Louange</option>
          <option>Afrobeats</option><option>Coupé-Décalé</option><option>R&B / Zouk</option><option>Hip-Hop / Trap</option><option>Autre</option>
        </select>
      </div>
      <div class="kf-form-row">
        <div class="kf-fg"><label>Tempo souhaité (BPM)</label><input id="cus-bpm" type="number" placeholder="Ex: 90" min="40" max="200"></div>
        <div class="kf-fg"><label>Budget (F CFA)</label>
          <select id="cus-budget">
            <option value="">À discuter</option>
            <option value="30000">30 000 F (basique)</option>
            <option value="50000">50 000 F (intermédiaire)</option>
            <option value="75000">75 000 F (premium)</option>
            <option value="100000">100 000 F+ (sur mesure avancé)</option>
          </select>
        </div>
      </div>
      <div class="kf-fg"><label>Références musicales *</label><textarea id="cus-refs" placeholder="Exemples d'artistes ou sons que vous aimez, ambiance recherchée..."></textarea></div>
      <div class="kf-fg"><label>Détails du projet</label><textarea id="cus-details" placeholder="Titre prévu, thème, objectifs du projet, deadline..."></textarea></div>
    </div>
    <button class="kf-btn-submit" id="kbs-custom-btn" onclick="kbsSubmitCustom()">ENVOYER MON BRIEF →</button>
    <div id="kbs-custom-ok" style="display:none;text-align:center;padding:30px;background:rgba(0,200,122,.08);border:1px solid rgba(0,200,122,.25);border-radius:14px;margin-top:16px">
      <div style="font-size:36px;margin-bottom:12px">🎹</div>
      <h3 style="font-family:'Bebas Neue',sans-serif;font-size:24px;color:var(--kf-green);letter-spacing:2px">BRIEF REÇU !</h3>
      <p style="font-size:12px;color:var(--kf-text2)">Notre beatmaker vous contactera dans <strong style="color:var(--kf-gold)">48 heures</strong>.</p>
      <a href="<?= esc_url($wa_link) ?>" target="_blank" class="kf-btn-wa" style="display:inline-block;margin-top:16px">💬 Contacter sur WhatsApp</a>
    </div>
  </div>
</div>

<!-- ONGLET BUNDLES -->
<div class="kbs-panel" id="kbs-bundles">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
    <?php $bundles = [
      ['STARTER PACK','90 000 F CFA','3 licences Non-Exclusive','🎹',['3 beats de votre choix','Licence NE chacun','Usage commercial inclus','Crédits BURIDA fournis'],'non_exclusive'],
      ['PRO PACK','155 000 F CFA','5 licences Non-Exclusive','🎚',['5 beats de votre choix','Licence NE chacun','Priorité sur nouvelles sorties','Conseils beatmaker inclus'],'non_exclusive'],
      ['PREMIUM CUSTOM','350 000 F CFA+','Exclusive + 1 Beat Custom','✨',['1 beat sur mesure exclusif','Stems / fichiers séparés','Contrat BURIDA complet','Suivi projet personnalisé'],'exclusive'],
    ];
    foreach($bundles as [$nom,$prix,$sous_titre,$ico,$features,$type]): ?>
    <div style="background:var(--kf-dark2);border:1px solid var(--kf-border);border-radius:14px;padding:24px;text-align:center;position:relative;overflow:hidden;transition:transform .2s" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform='translateY(0)'">
      <?php if($nom==='PRO PACK'): ?><div style="position:absolute;top:0;right:0;background:var(--kf-gold);color:#000;font-size:9px;font-weight:700;padding:4px 10px;border-radius:0 0 0 8px">POPULAIRE</div><?php endif; ?>
      <div style="font-size:36px;margin-bottom:10px"><?= $ico ?></div>
      <h3 style="font-family:'Bebas Neue',sans-serif;font-size:22px;color:#fff;letter-spacing:2px;margin:0 0 4px"><?= $nom ?></h3>
      <p style="font-size:11px;color:var(--kf-text4);margin-bottom:14px"><?= $sous_titre ?></p>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:30px;color:var(--kf-gold);line-height:1;margin-bottom:16px"><?= $prix ?></div>
      <ul style="list-style:none;padding:0;margin:0 0 20px;text-align:left">
        <?php foreach($features as $f): ?>
        <li style="font-size:11px;color:var(--kf-text2);padding:4px 0;display:flex;gap:8px">✓ <?= $f ?></li>
        <?php endforeach; ?>
      </ul>
      <a href="<?= esc_url($wa_link) ?>?text=<?= urlencode("Bonjour, je suis intéressé par le $nom KOPHI'S MUSIC STUDIO.") ?>" target="_blank" class="kf-btn-wa" style="display:block;text-align:center;padding:11px">💬 Commander sur WhatsApp</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ONGLET FAQ BURIDA -->
<div class="kbs-panel" id="kbs-faq">
  <div style="max-width:700px;margin:0 auto">
    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:26px;color:#fff;text-align:center;letter-spacing:2px;margin-bottom:20px">FAQ — LICENCES & BURIDA</h2>
    <?php $faqs = [
      ["Qu'est-ce que le BURIDA ?",'Le BURIDA (Bureau Ivoirien du Droit d\'Auteur) est l\'organisme officiel de gestion des droits d\'auteur en Côte d\'Ivoire. Tous nos beats sont enregistrés au BURIDA et bénéficient d\'une protection légale.'],
      ["Quelle est la différence entre NE et Exclusive ?",'La licence Non-Exclusive permet d\'utiliser le beat commercialement jusqu\'à 500 000 streams sur 10 ans, mais le beat reste disponible pour d\'autres acheteurs. L\'Exclusive vous donne les droits exclusifs et perpétuels — personne d\'autre ne peut acheter ce beat.'],
      ["Puis-je signer chez un label avec une licence NE ?",'Oui, avec précaution. La majorité des labels acceptent les licences NE pour les mixtapes et projets indépendants. Pour un contrat majeur, nous recommandons l\'Exclusive.'],
      ["Les crédits sont-ils obligatoires ?",'Oui. Pour toute licence Non-Exclusive, vous devez créditer : "Prod. by <?= esc_html($beatmaker) ?>". Le non-respect peut entraîner la révocation de la licence.'],
      ["Comment se passe le paiement ?",'Le paiement s\'effectue via Mobile Money (Orange Money, MTN, Wave, Moov) ou virement bancaire. Nous vous envoyons les instructions via WhatsApp après confirmation.'],
      ["Recevrai-je un contrat ?",'Oui. Après paiement, nous vous envoyons un contrat de licence signé (PDF) conforme à la loi ivoirienne n°96-564 sur la propriété intellectuelle et aux règles BURIDA.'],
      ["Puis-je acheter un beat si je suis en dehors de la Côte d'Ivoire ?",'Absolument. Nos beats sont accessibles dans toute l\'Afrique francophone et la diaspora. Le paiement par virement ou PayPal est disponible pour l\'international.'],
      ["Que contient une licence Exclusive ?",'L\'Exclusive inclut : droits illimités perpétuels, retrait du beat du catalogue, fichiers WAV séparés (stems), contrat BURIDA complet, possibilité de modifier le titre.'],
    ];
    foreach($faqs as $i=>[$q,$r]): ?>
    <div style="background:var(--kf-dark2);border:1px solid var(--kf-border);border-radius:12px;margin-bottom:8px;overflow:hidden">
      <button onclick="kbsFaqToggle(<?= $i ?>)" style="width:100%;background:none;border:none;padding:14px 16px;text-align:left;cursor:pointer;display:flex;justify-content:space-between;align-items:center;color:var(--kf-text);font-family:'Montserrat',sans-serif;font-size:12px;font-weight:700">
        <?= $q ?>
        <span id="faq-arrow-<?= $i ?>" style="font-size:16px;transition:transform .3s;color:var(--kf-gold);flex-shrink:0;margin-left:12px">+</span>
      </button>
      <div id="faq-body-<?= $i ?>" style="display:none;padding:0 16px 14px;font-size:11px;color:var(--kf-text2);line-height:1.7"><?= $r ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- MODAL ACHAT -->
<div style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(8px)" id="kbs-modal" onclick="if(event.target===this)kbsCloseModal()">
  <div style="background:var(--kf-dark3);border:1px solid var(--kf-gold-b);border-radius:16px;padding:26px;width:100%;max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:20px;color:#fff;letter-spacing:2px" id="kbs-modal-title">COMMANDER CE BEAT</div>
      <button onclick="kbsCloseModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--kf-text3)">✕</button>
    </div>
    <div id="kbs-modal-info" style="background:var(--kf-dark4);border:1px solid var(--kf-border);border-radius:10px;padding:12px;margin-bottom:16px;font-size:12px;color:var(--kf-text2)"></div>
    <div id="kbs-modal-err" style="display:none;background:rgba(255,71,87,.1);border:1px solid rgba(255,71,87,.3);border-radius:8px;padding:10px;font-size:11px;color:#FFB3B8;margin-bottom:12px"></div>
    <div class="kf-fg"><label>Votre nom / Artiste *</label><input id="kbs-nom" placeholder="Nom ou nom de scène"></div>
    <div class="kf-fg"><label>Téléphone *</label><input id="kbs-tel" type="tel" placeholder="+225 07 00 00 00 00"></div>
    <div class="kf-fg"><label>Email (optionnel)</label><input id="kbs-email" type="email" placeholder="votre@email.com"></div>
    <div class="kf-fg"><label>Message (optionnel)</label><textarea id="kbs-msg" placeholder="Titre du projet, questions..." rows="2"></textarea></div>
        <p style="font-size:11px;color:var(--kf-text3);margin:0 0 14px">Après validation, tu choisiras ton moyen de paiement (Wave, Orange Money, MTN, Moov, Mobile Money automatique...) sur la page suivante.</p>
        <button id="kbs-buy-btn" onclick="kbsSubmitAchat()" style="width:100%;padding:14px;font-size:16px;font-family:'Bebas Neue',sans-serif;letter-spacing:2px;border-radius:10px;background:linear-gradient(135deg,#8B3FD9,#E8B84B);border:none;color:#fff;cursor:pointer;box-shadow:0 6px 20px rgba(139,63,217,.3);transition:all .3s">💳 CONTINUER VERS LE PAIEMENT</button>
  </div>
</div>

</div><!-- #kophi-beats-app -->

<style>
#kophi-beats-app .kbs-tabs{display:flex;gap:6px;overflow-x:auto;margin-bottom:24px;padding-bottom:2px;}
#kophi-beats-app .kbs-tab{background:var(--kf-dark2);border:1px solid var(--kf-border);border-radius:8px;color:var(--kf-text3);font-family:'Montserrat',sans-serif;font-size:10px;font-weight:700;letter-spacing:.5px;padding:8px 14px;cursor:pointer;white-space:nowrap;transition:all .2s;}
#kophi-beats-app .kbs-tab:hover{border-color:var(--kf-gold-b);color:var(--kf-gold);}
#kophi-beats-app .kbs-tab.active{background:var(--kf-gold-d);border-color:var(--kf-gold);color:var(--kf-gold);}
#kophi-beats-app .kbs-panel{display:none;}#kophi-beats-app .kbs-panel.active{display:block;}
#kophi-beats-app .kbs-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;}
#kophi-beats-app .kbs-filter{background:var(--kf-dark3);border:1px solid var(--kf-border);border-radius:20px;color:var(--kf-text3);font-size:10px;font-weight:700;padding:5px 12px;cursor:pointer;transition:all .2s;}
#kophi-beats-app .kbs-filter:hover,#kophi-beats-app .kbs-filter.active{background:var(--kf-gold-d);border-color:var(--kf-gold-b);color:var(--kf-gold);}
#kophi-beats-app .kbs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;}
#kophi-beats-app .kbs-beat{background:var(--kf-dark2);border:1px solid var(--kf-border);border-radius:12px;overflow:hidden;transition:all .22s;}
#kophi-beats-app .kbs-beat:hover{border-color:var(--kf-gold-b);transform:translateY(-3px);}
#kophi-beats-app .kbs-cover{position:relative;width:100%;height:auto;aspect-ratio:1/1;overflow:hidden;cursor:pointer;background:linear-gradient(135deg,rgba(139,63,217,.35),rgba(232,184,75,.18));}
#kophi-beats-app .kbs-cover img{position:absolute;inset:0;width:100%!important;height:100%!important;max-width:none!important;object-fit:cover;object-position:center;display:block;margin:0;border-radius:0;transition:transform .5s ease;}
#kophi-beats-app .kbs-beat:hover .kbs-cover img{transform:scale(1.05);}
#kophi-beats-app .kbs-cover-ph{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.75);background:linear-gradient(135deg,var(--kf-violet),var(--kf-gold));}
#kophi-beats-app .kbs-cover::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,transparent 55%,rgba(0,0,0,.55));opacity:0;transition:opacity .2s;pointer-events:none;}
#kophi-beats-app .kbs-cover:hover::after,#kophi-beats-app .kbs-beat.kp-current .kbs-cover::after{opacity:1;}
#kophi-beats-app .kbs-play-btn{position:absolute;right:12px;bottom:12px;inset:auto 12px 12px auto;width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#F5CC6A,#E8B84B);color:#141005;display:flex;align-items:center;justify-content:center;z-index:3;font-size:0;
  box-shadow:0 8px 22px rgba(0,0,0,.45);opacity:0;transform:translateY(8px);transition:opacity .2s,transform .2s;}
#kophi-beats-app .kbs-cover:hover .kbs-play-btn,#kophi-beats-app .kbs-cover:focus-visible .kbs-play-btn,#kophi-beats-app .kbs-beat.kp-current .kbs-play-btn{opacity:1;transform:none;}
#kophi-beats-app .kbs-cover:focus-visible{outline:2px solid var(--kf-gold);outline-offset:-2px;}
#kophi-beats-app .kbs-eq{position:absolute;left:12px;bottom:14px;z-index:3;display:none;align-items:flex-end;gap:3px;height:18px;}
#kophi-beats-app .kbs-eq i{width:3px;height:100%;border-radius:2px;background:#F5CC6A;transform-origin:bottom;animation:kwave .7s ease-in-out infinite alternate;}
#kophi-beats-app .kbs-eq i:nth-child(2){animation-delay:.2s}#kophi-beats-app .kbs-eq i:nth-child(3){animation-delay:.1s}#kophi-beats-app .kbs-eq i:nth-child(4){animation-delay:.3s}
#kophi-beats-app .kbs-beat.kp-playing .kbs-eq{display:flex;}
#kophi-beats-app .kbs-progress{position:absolute;left:0;bottom:0;height:3px;width:var(--kp-progress,0%);background:linear-gradient(90deg,var(--kf-violet),var(--kf-gold));z-index:4;}
#kophi-beats-app .kbs-beat.kp-current{border-color:var(--kf-gold-b);}
@keyframes kwave{0%{transform:scaleY(.3)}100%{transform:scaleY(1)}}
@media (hover:none){#kophi-beats-app .kbs-play-btn{opacity:1;transform:none;}}
#kophi-beats-app .kbs-info{padding:14px;}
#kophi-beats-app .kbs-title{font-family:'Bebas Neue',sans-serif;font-size:17px;color:#fff;letter-spacing:.5px;margin-bottom:6px;}
#kophi-beats-app .kbs-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;}
#kophi-beats-app .kbs-meta span{font-size:11px;color:var(--kf-text4);}
#kophi-beats-app .kbs-genre{background:var(--kf-violet-d);color:#C084FC;border:1px solid var(--kf-violet-b);padding:2px 8px;border-radius:10px;font-size:9px;}
#kophi-beats-app .kbs-desc{font-size:12px;color:var(--kf-text4);margin-bottom:8px;line-height:1.4;}
#kophi-beats-app .kbs-tags{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:10px;}
#kophi-beats-app .kbs-tag{font-size:10px;background:var(--kf-dark4);border:1px solid var(--kf-border);color:var(--kf-text4);padding:2px 6px;border-radius:6px;}
#kophi-beats-app .kbs-prices{display:flex;align-items:center;background:var(--kf-dark3);border:1px solid var(--kf-border);border-radius:10px;padding:10px;margin-bottom:10px;}
#kophi-beats-app .kbs-price-col{flex:1;text-align:center;}
#kophi-beats-app .kbs-price-label{font-size:8px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--kf-text4);margin-bottom:3px;}
#kophi-beats-app .kbs-price-val{font-family:'Bebas Neue',sans-serif;font-size:16px;color:var(--kf-gold);}
#kophi-beats-app .kbs-price-val.kbs-sold{color:var(--kf-red);font-size:13px;}
#kophi-beats-app .kbs-price-divider{width:1px;height:30px;background:var(--kf-border);flex-shrink:0;margin:0 6px;}
#kophi-beats-app .kbs-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
#kophi-beats-app .kbs-btn-ne,#kophi-beats-app .kbs-btn-ex{font-family:'Montserrat',sans-serif;font-size:9px;font-weight:700;letter-spacing:.5px;padding:8px;border-radius:8px;border:none;cursor:pointer;transition:all .2s;}
#kophi-beats-app .kbs-btn-ne{background:rgba(232,184,75,.12);border:1px solid rgba(232,184,75,.3);color:var(--kf-gold);}
#kophi-beats-app .kbs-btn-ne:hover{background:var(--kf-gold);color:#000;}
#kophi-beats-app .kbs-btn-ex{background:rgba(139,63,217,.12);border:1px solid rgba(139,63,217,.3);color:#C084FC;}
#kophi-beats-app .kbs-btn-ex:hover{background:var(--kf-violet);color:#fff;}

/* ═══════════════════════════════════════════════
   HEADER BOUTIQUE BEATS (autonome)
═══════════════════════════════════════════════ */
.kbs-page-header {
  position:sticky; top:0; z-index:999;
  background:rgba(6,4,16,.97);
  backdrop-filter:blur(24px);
  border-bottom:1px solid rgba(232,184,75,.1);
  box-shadow:0 2px 20px rgba(0,0,0,.4);
  font-family:Inter,-apple-system,sans-serif;
}
.kbs-page-header-inner {
  max-width:1400px; margin:0 auto;
  padding:0 clamp(14px,4vw,48px);
  height:64px; display:flex; align-items:center; gap:20px;
}
.kbs-header-logo { display:flex; align-items:center; text-decoration:none; transition:opacity .2s; flex-shrink:0; }
.kbs-header-logo:hover { opacity:.8; }
.kbs-header-logo img { height:38px; width:auto; }
.kbs-header-nav { display:flex; align-items:center; gap:2px; flex:1; }
.kbs-nav-link {
  font-size:10px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;
  color:rgba(255,255,255,.55); text-decoration:none;
  padding:8px 14px; border-radius:8px; transition:all .25s; white-space:nowrap;
  position:relative;
}
.kbs-nav-link::after {
  content:''; position:absolute; bottom:4px; left:14px; right:14px;
  height:1.5px; background:#E8B84B; border-radius:2px;
  transform:scaleX(0); transform-origin:left; transition:transform .25s ease;
}
.kbs-nav-link:hover { color:#E8B84B; background:rgba(232,184,75,.07); }
.kbs-nav-link:hover::after,.kbs-nav-link.active::after { transform:scaleX(1); }
.kbs-nav-link.active { color:#E8B84B; }
.kbs-header-actions { display:flex; align-items:center; gap:10px; margin-left:auto; flex-shrink:0; }
.kbs-header-wa {
  width:38px; height:38px; border-radius:50%;
  background:rgba(37,211,102,.1); border:1.5px solid rgba(37,211,102,.25);
  color:#25D366; display:flex; align-items:center; justify-content:center;
  text-decoration:none; transition:all .25s; flex-shrink:0;
}
.kbs-header-wa:hover { background:#25D366; color:#fff; transform:scale(1.1) rotate(-3deg); }
.kbs-header-cta {
  display:inline-flex; align-items:center; gap:6px;
  font-size:10px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase;
  background:linear-gradient(135deg,#8B3FD9,#E8B84B); color:#fff!important;
  padding:9px 20px; border-radius:22px; text-decoration:none;
  transition:all .3s; box-shadow:0 4px 14px rgba(139,63,217,.3);
}
.kbs-header-cta:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(139,63,217,.45); }
.kbs-burger {
  display:none; flex-direction:column; justify-content:center; gap:5px;
  width:38px; height:38px; background:rgba(255,255,255,.06);
  border:1.5px solid rgba(255,255,255,.1); border-radius:8px; cursor:pointer;
}
.kbs-burger span { display:block; height:2px; background:rgba(255,255,255,.8); border-radius:2px; transition:all .3s; }
.kbs-burger.active span:nth-child(1) { transform:translateY(7px) rotate(45deg); }
.kbs-burger.active span:nth-child(2) { opacity:0; }
.kbs-burger.active span:nth-child(3) { transform:translateY(-7px) rotate(-45deg); }
.kbs-mob-nav {
  display:none; flex-direction:column;
  background:rgba(6,4,16,.99); border-top:1px solid rgba(255,255,255,.05);
  max-height:0; overflow:hidden; transition:max-height .35s ease;
}
.kbs-mob-nav.open { max-height:400px; }
.kbs-mob-link {
  font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;
  color:rgba(255,255,255,.65); text-decoration:none;
  padding:14px clamp(14px,4vw,48px); border-bottom:1px solid rgba(255,255,255,.04); transition:all .2s;
}
.kbs-mob-link:hover { color:#E8B84B; background:rgba(232,184,75,.05); }
.kbs-mob-wa {
  display:block; margin:12px 16px; padding:12px 20px; background:#25D366;
  color:#fff!important; text-decoration:none; border-radius:10px;
  font-size:13px; font-weight:700; text-align:center;
}
/* ═══════════════════════════════════════════════
   FOOTER BOUTIQUE BEATS
═══════════════════════════════════════════════ */
.kbs-page-footer {
  background:#060410; border-top:1px solid rgba(255,255,255,.06);
  font-family:Inter,-apple-system,sans-serif; margin-top:0;
}
.kbs-footer-inner {
  max-width:1400px; margin:0 auto;
  padding:28px clamp(14px,4vw,48px);
  display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;
}
.kbs-footer-links { display:flex; gap:20px; flex-wrap:wrap; }
.kbs-footer-links a { font-size:11px; color:rgba(255,255,255,.4); text-decoration:none; transition:color .2s; }
.kbs-footer-links a:hover { color:#E8B84B; }
.kbs-footer-copy { font-size:11px; color:rgba(255,255,255,.25); }
@media(max-width:768px) { .kbs-header-nav { display:none; } .kbs-burger { display:flex; } .kbs-mob-nav { display:flex; } }
@media(max-width:500px) { .kbs-header-cta { padding:8px 14px; font-size:9px; } .kbs-footer-inner { flex-direction:column; align-items:center; text-align:center; } }

</style>

<script>
const KBS_REST  = '<?= esc_js(rest_url("kophi/v1/")) ?>';
const KBS_NONCE = '<?= esc_js(wp_create_nonce("wp_rest")) ?>';
const KBS_WA    = '<?= esc_js(preg_replace("/[^0-9]/","",get_option("kophi_whatsapp","2250503404389"))) ?>';

// ── Lecteur : géré par le lecteur unifié (assets/js/kophi-player.js) ──
// kbsPlay() est conservé pour compatibilité avec d'éventuels appels externes.
let kbsModalData = {};
function kbsPlay(id) { if (window.KophiPlayer) KophiPlayer.toggle(id); }
document.addEventListener('keydown', function (e) {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.kbs-cover[data-kp-play]')) { e.preventDefault(); e.target.click(); }
});

// ── Filtres / Onglets ───────────────────────────────────────
function kbsTab(id, btn) {
  document.querySelectorAll('#kophi-beats-app .kbs-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('#kophi-beats-app .kbs-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('kbs-' + id).classList.add('active');
}
function kbsFilter(genre, btn) {
  document.querySelectorAll('#kophi-beats-app .kbs-filter').forEach(f => f.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#kbs-grid .kbs-beat').forEach(b => {
    b.style.display = (!genre || b.dataset.genre === genre) ? '' : 'none';
  });
}

// ── Modal Achat ─────────────────────────────────────────────
function kbsAchat(id, titre, licence, prix) {
  kbsModalData = { beat_id: id, titre, licence, prix };
  const mod    = document.getElementById('kbs-modal');
  const labels = { 'non_exclusive': 'Non-Exclusive (Lease)', 'exclusive': 'Exclusive (Droits totaux)' };
  const prx    = prix ? Number(prix).toLocaleString('fr-FR') + ' F CFA' : 'Prix à confirmer';
  document.getElementById('kbs-modal-info').innerHTML =
    `<strong style="color:#fff">🎹 ${titre}</strong><br>
     <span style="color:var(--kf-gold);font-family:Bebas Neue,sans-serif;font-size:20px">${prx}</span>
     <span style="font-size:11px;color:rgba(255,255,255,.5);margin-left:8px">${labels[licence]||licence}</span>`;
  document.getElementById('kbs-modal-title').textContent = licence === 'exclusive' ? 'ACHETER EN EXCLUSIVITÉ' : 'ACHETER (NON-EXCLUSIVE)';
  document.getElementById('kbs-modal-err').style.display = 'none';
  ['kbs-nom','kbs-tel','kbs-email','kbs-msg'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  document.getElementById('kbs-buy-btn').innerHTML = '💳 PAYER MAINTENANT';
  document.getElementById('kbs-buy-btn').disabled = false;

  // Afficher message succès paiement si retour CinetPay
  const params = new URLSearchParams(location.search);
  if (params.get('payment') === 'success') {
    const licUrl = params.get('lic');
    document.getElementById('kbs-modal-info').innerHTML +=
      `<div style="margin-top:12px;padding:10px;background:rgba(0,200,122,.1);border:1px solid rgba(0,200,122,.3);border-radius:8px;font-size:12px;color:#00C87A">
        ✅ Paiement confirmé !
        ${licUrl ? `<a href="${decodeURIComponent(licUrl)}" target="_blank" style="display:block;margin-top:6px;color:#E8B84B;font-weight:700">📄 Télécharger ma licence →</a>` : ''}
       </div>`;
  }

  mod.style.display = 'flex';
}
function kbsCloseModal() { document.getElementById('kbs-modal').style.display = 'none'; }

// ── Soumettre achat (CinetPay + WhatsApp) ───────────────────
async function kbsSubmitAchat() {
  const nom   = document.getElementById('kbs-nom').value.trim();
  const tel   = document.getElementById('kbs-tel').value.trim();
  const email = document.getElementById('kbs-email').value.trim();
  const msg   = document.getElementById('kbs-msg').value.trim();
  const errEl = document.getElementById('kbs-modal-err');
  errEl.style.display = 'none';

  if (!nom || !tel) { errEl.textContent = 'Nom et téléphone obligatoires.'; errEl.style.display = 'block'; return; }

  const btn = document.getElementById('kbs-buy-btn');
  btn.disabled = true;
  btn.innerHTML = '⏳ Préparation du paiement...';

  // Toutes les demandes passent par la même commande — le choix du moyen de paiement
  // (Wave, Orange Money, MTN, Moov, Mobile Money auto, ou WhatsApp en dernier recours)
  // se fait sur la page de paiement unifiée qui suit, comme sur KM Family.
  try {
    const r = await fetch(KBS_REST + 'payment/init', {
      method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce':KBS_NONCE},
      body: JSON.stringify({ beat_id: kbsModalData.beat_id, nom, telephone: tel, email, licence_type: kbsModalData.licence, message: msg })
    });
    const d = r.ok ? await r.json() : null;

    if (d?.redirect_url) {
      kbsCloseModal();
      window.location.href = d.redirect_url;
    } else {
      errEl.textContent = d?.message || "Erreur lors de la préparation du paiement. Contacte-nous sur WhatsApp.";
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '💳 CONTINUER VERS LE PAIEMENT';
    }
  } catch(e) {
    errEl.textContent = 'Erreur réseau. Réessaie ou contacte-nous sur WhatsApp.';
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = '💳 CONTINUER VERS LE PAIEMENT';
  }
}

// ── Beat Custom ─────────────────────────────────────────────
async function kbsSubmitCustom() {
  const nom   = document.getElementById('cus-nom').value.trim();
  const tel   = document.getElementById('cus-tel').value.trim();
  const genre = document.getElementById('cus-genre').value;
  const refs  = document.getElementById('cus-refs').value.trim();
  const errEl = document.getElementById('kbs-custom-err');
  errEl.style.display = 'none';
  if (!nom || !tel || !genre || !refs) { errEl.textContent = 'Remplissez tous les champs obligatoires.'; errEl.style.display = 'block'; return; }
  const btn = document.getElementById('kbs-custom-btn');
  btn.disabled = true; btn.textContent = 'Envoi en cours...';
  const msg = `BEAT CUSTOM\nGenre: ${genre}\nBPM: ${document.getElementById('cus-bpm').value || 'À discuter'}\nRéférences: ${refs}\nNotes: ${document.getElementById('cus-notes').value}`;
  try {
    const r = await fetch(KBS_REST + 'reservations', { method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce':KBS_NONCE}, body: JSON.stringify({ nom_artiste: nom, telephone: tel, email: document.getElementById('cus-email').value, service: 'beats', message: msg, date_souhaitee: new Date().toISOString().split('T')[0] }) });
    const d = await r.json();
    if (d.success || d.id) { btn.style.display = 'none'; document.getElementById('kbs-custom-ok').style.display = 'block'; }
    else { errEl.textContent = d.message || 'Erreur.'; errEl.style.display = 'block'; btn.disabled = false; btn.textContent = 'ENVOYER MA DEMANDE'; }
  } catch(e) { errEl.textContent = 'Erreur connexion.'; errEl.style.display = 'block'; btn.disabled = false; btn.textContent = 'ENVOYER MA DEMANDE'; }
}

function kbsFaqToggle(i) {
  const body = document.getElementById('faq-body-' + i);
  const arrow = document.getElementById('faq-arrow-' + i);
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  arrow.textContent = open ? '+' : '−';
}

// Vérifier retour paiement
document.addEventListener('DOMContentLoaded', () => {
  const p = new URLSearchParams(location.search);
  if (p.get('payment') === 'success') {
    const licUrl = p.get('lic');
    const banner = document.createElement('div');
    banner.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);z-index:9999;background:rgba(0,200,122,.95);color:#000;padding:14px 24px;border-radius:12px;font-size:13px;font-weight:700;box-shadow:0 4px 20px rgba(0,200,122,.4);text-align:center';
    banner.innerHTML = '🎉 Paiement confirmé !' + (licUrl ? ` <a href="${decodeURIComponent(licUrl)}" target="_blank" style="color:#000;margin-left:10px;text-decoration:underline">Télécharger ma licence →</a>` : '');
    document.body.appendChild(banner);
    setTimeout(() => banner.remove(), 10000);
  }
  if (p.get('payment') === 'cancelled') {
    const banner = document.createElement('div');
    banner.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);z-index:9999;background:rgba(255,71,87,.9);color:#fff;padding:14px 24px;border-radius:12px;font-size:13px;font-weight:700';
    banner.textContent = '❌ Paiement annulé. Réessayez ou contactez-nous sur WhatsApp.';
    document.body.appendChild(banner);
    setTimeout(() => banner.remove(), 6000);
  }
});
</script>
<!-- ══ FOOTER BOUTIQUE BEATS ═════════════════════════════════ -->
<footer class="kbs-page-footer">
  <div class="kbs-footer-inner">
    <a href="<?= esc_url(home_url('/')) ?>">
      <img src="<?= esc_url($logo_url) ?>" alt="KM STUDIO" height="32" style="opacity:.7">
    </a>
    <div class="kbs-footer-links">
      <a href="<?= esc_url(home_url('/beats')) ?>">Boutique Beats</a>
      <a href="<?= esc_url(home_url('/reserver')) ?>">Réserver</a>
      <a href="https://kophismusic.com/" target="_blank">Label</a>
      <a href="<?= esc_url($wa_link) ?>" target="_blank"><?= esc_html($wa) ?></a>
    </div>
    <div class="kbs-footer-copy">© <?= date('Y') ?> KM STUDIO · Abidjan, Côte d'Ivoire</div>
  </div>
</footer>

