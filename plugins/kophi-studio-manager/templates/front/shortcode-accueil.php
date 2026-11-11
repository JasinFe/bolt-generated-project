<?php
/**
 * Shortcode [kophi_accueil] — Page d'accueil complète KOPHI'S MUSIC STUDIO
 *
 * Sections :
 *   1. HERO          — Titre cinématique + CTA principal
 *   2. SERVICES      — 3 offres phares (Voix, Face B, Beat Custom)
 *   3. POURQUOI NOUS — 4 arguments + statistiques animées
 *   4. BEATS HOME    — 5 derniers beats (réutilise kophi_beats_home)
 *   5. RÉSERVATION   — Section formulaire (réutilise kophi_reservation_home)
 *   6. TÉMOIGNAGES   — 3 avis clients (configurables)
 *   7. CTA FINAL     — Bannière "Prêt à enregistrer ?"
 *
 * Usage : [kophi_accueil]
 */
if (!defined('ABSPATH')) exit;

function kophi_shortcode_accueil($atts) {
    $a = shortcode_atts([], $atts);

    $logo    = 'https://studio.kophismusic.com/wp-content/uploads/sites/8/2026/03/LOGO-KOPHIS-MUSIC-STUDIO.png';
    $wa      = KOPHI_WA_LINK;
    $wa_text = urlencode("Bonjour KOPHI'S MUSIC STUDIO ! Je souhaite réserver une session studio.");
    $reserve = home_url('/reserver');
    $beats   = home_url('/beats');

    ob_start();
?>
<div class="kah-wrap" id="kophi-accueil-home">

<!-- ══════════════════════════════════════════════════════════════
     1 ─ HERO
══════════════════════════════════════════════════════════════ -->
<section class="kah-hero">
  <div class="kah-hero-bg">
    <div class="kah-hero-glow kah-glow-v"></div>
    <div class="kah-hero-glow kah-glow-g"></div>
    <div class="kah-hero-grid"></div>
    <div class="kah-hero-noise"></div>
  </div>

  <div class="kah-hero-inner">

    <!-- Pill -->
    <div class="kah-hero-pill" data-kah-anim="fade-down">
      <span class="kah-pill-dot"></span>
      STUDIO D'ENREGISTREMENT · ABIDJAN, CÔTE D'IVOIRE
    </div>

    <!-- Titre principal -->
    <h1 class="kah-hero-title" data-kah-anim="fade-up" data-kah-delay="100">
      VOTRE SON,<br>
      <span class="kah-gold">NOTRE PASSION</span>
    </h1>

    <!-- Sous-titre -->
    <p class="kah-hero-sub" data-kah-anim="fade-up" data-kah-delay="200">
      Urban Gospel · Afrobeats · Trap · Tous genres<br>
      Enregistrement · Mix & Master · Beats · Production
    </p>

    <!-- CTA buttons -->
    <div class="kah-hero-cta" data-kah-anim="fade-up" data-kah-delay="300">
      <a href="<?= esc_url($reserve) ?>" class="kah-btn kah-btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Réserver une session
      </a>
      <a href="<?= esc_url($wa . '?text=' . $wa_text) ?>" class="kah-btn kah-btn-wa" target="_blank" rel="noopener">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a10 10 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
        WhatsApp
      </a>
      <a href="<?= esc_url($beats) ?>" class="kah-btn kah-btn-outline">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13M9 18c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-2c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/></svg>
        Boutique Beats
      </a>
    </div>

    <!-- Badges confiance -->
    <div class="kah-hero-badges" data-kah-anim="fade-up" data-kah-delay="400">
      <?php foreach ([
        ['🎙', 'Ingénieur professionnel'],
        ['📤', 'Livraison sous 24h'],
        ['🌍', 'Tous genres musicaux'],
        ['🔒', 'Licences BURIDA'],
      ] as [$ico, $txt]): ?>
      <div class="kah-badge">
        <span><?= $ico ?></span>
        <span><?= esc_html($txt) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

  </div>

  <!-- Scroll indicator -->
  <div class="kah-scroll-ind">
    <div class="kah-scroll-dot"></div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     2 ─ STATS RAPIDES
══════════════════════════════════════════════════════════════ -->
<section class="kah-stats-bar">
  <div class="kah-stats-inner">
    <?php
    $nb_sessions = wp_count_posts('kophi_session')->publish ?? 0;
    $nb_beats    = wp_count_posts('kophi_beat')->publish ?? 0;
    $nb_clients  = wp_count_posts('kophi_client')->publish ?? 0;
    $stats = [
      [$nb_sessions ?: '100+', 'Sessions réalisées'],
      [$nb_beats    ?: '50+',  'Beats disponibles'],
      [$nb_clients  ?: '80+',  'Artistes accompagnés'],
      ['7j/7',                 'Jours d\'ouverture'],
    ];
    foreach ($stats as [$val, $label]): ?>
    <div class="kah-stat-item">
      <span class="kah-stat-num"><?= esc_html($val) ?></span>
      <span class="kah-stat-lbl"><?= esc_html($label) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     3 ─ SERVICES
══════════════════════════════════════════════════════════════ -->
<section class="kah-section kah-services-section">
  <div class="kah-section-inner">

    <div class="kah-section-header" data-kah-anim="fade-up">
      <span class="kah-section-pill">🎙 NOS SERVICES</span>
      <h2 class="kah-section-title">Ce que nous faisons <span class="kah-gold">pour vous</span></h2>
      <p class="kah-section-sub">Du concept à la livraison — un accompagnement complet</p>
    </div>

    <div class="kah-services-grid">

      <?php
      $services = [
        [
          'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2a3 3 0 0 1 3 3v7a3 3 0 0 1-6 0V5a3 3 0 0 1 3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3M8 22h8"/></svg>',
          'color'   => '#E8B84B',
          'titre'   => 'ENREGISTREMENT',
          'label'   => 'Prise de Voix · Mix · Master',
          'desc'    => 'Studio acoustique professionnel, ingénieur son dédié, rendu broadcast-ready. Du 4h au 12h.',
          'prix'    => 'À partir de 50 000 F',
          'link'    => $reserve,
          'link_txt'=> 'Réserver',
          'featured'=> false,
        ],
        [
          'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18V5l12-2v13M9 18c0 1.1-1.343 2-3 2s-3-.9-3-2 1.343-2 3-2 3 .9 3 2zm12-2c0 1.1-1.343 2-3 2s-3-.9-3-2 1.343-2 3-2 3 .9 3 2z"/></svg>',
          'color'   => '#8B3FD9',
          'titre'   => 'BEATS & PRODUCTION',
          'label'   => 'Instrumentaux · Licences BURIDA',
          'desc'    => 'Catalogue de beats variés (Gospel, Afro, Trap…) + beats sur mesure créés pour votre projet.',
          'prix'    => 'À partir de 30 000 F',
          'link'    => $beats,
          'link_txt'=> 'Voir les beats',
          'featured'=> true,
        ],
        [
          'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
          'color'   => '#00C87A',
          'titre'   => 'FACE B',
          'label'   => 'Enregistrement express · 10 000 F',
          'desc'    => 'Vous venez avec votre instrumental, on enregistre et mixe rapidement. Idéal pour projets urgents.',
          'prix'    => '10 000 F CFA',
          'link'    => $reserve,
          'link_txt'=> 'Réserver',
          'featured'=> false,
        ],
      ];
      foreach ($services as $i => $svc): ?>
      <div class="kah-service-card <?= $svc['featured'] ? 'kah-featured' : '' ?>"
           style="--svc-color: <?= $svc['color'] ?>"
           data-kah-anim="fade-up" data-kah-delay="<?= $i * 100 ?>">
        <?php if ($svc['featured']): ?>
        <div class="kah-featured-badge">⭐ POPULAIRE</div>
        <?php endif; ?>
        <div class="kah-svc-icon-wrap">
          <div class="kah-svc-icon"><?= $svc['icon'] ?></div>
        </div>
        <h3 class="kah-svc-titre"><?= esc_html($svc['titre']) ?></h3>
        <span class="kah-svc-label"><?= esc_html($svc['label']) ?></span>
        <p class="kah-svc-desc"><?= esc_html($svc['desc']) ?></p>
        <div class="kah-svc-footer">
          <span class="kah-svc-prix"><?= esc_html($svc['prix']) ?></span>
          <a href="<?= esc_url($svc['link']) ?>" class="kah-svc-btn"><?= esc_html($svc['link_txt']) ?> →</a>
        </div>
      </div>
      <?php endforeach; ?>

    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     4 ─ POURQUOI NOUS
══════════════════════════════════════════════════════════════ -->
<section class="kah-section kah-why-section">
  <div class="kah-section-inner">

    <div class="kah-why-grid">
      <!-- Texte gauche -->
      <div class="kah-why-left" data-kah-anim="fade-right">
        <span class="kah-section-pill">🏆 POURQUOI KOPHI'S</span>
        <h2 class="kah-section-title" style="text-align:left;margin-top:16px">
          Un studio pensé pour<br><span class="kah-gold">les artistes africains</span>
        </h2>
        <p class="kah-why-desc">
          Fondé par des passionnés de l'Urban Gospel, KOPHI'S MUSIC STUDIO offre un environnement créatif professionnel adapté à tous les styles musicaux.
        </p>
        <div class="kah-why-points">
          <?php foreach ([
            ['🎛', 'Équipement professionnel', 'Matériel de studio haut de gamme pour un son broadcast-ready.'],
            ['👂', 'Écoute & créativité',      'Nous comprenons votre vision et l\'aidons à prendre vie.'],
            ['⚡', 'Rapidité d\'exécution',    'Sessions efficaces, fichiers livrés sous 24h garantis.'],
            ['💬', 'Suivi personnalisé',       'De la réservation à la livraison, on reste disponibles.'],
          ] as [$ico, $titre, $desc]): ?>
          <div class="kah-why-point">
            <span class="kah-why-ico"><?= $ico ?></span>
            <div>
              <div class="kah-why-ptitle"><?= esc_html($titre) ?></div>
              <div class="kah-why-pdesc"><?= esc_html($desc) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <a href="<?= esc_url($reserve) ?>" class="kah-btn kah-btn-primary" style="margin-top:28px;display:inline-flex">
          Réserver ma session →
        </a>
      </div>

      <!-- Carte visuelle droite -->
      <div class="kah-why-right" data-kah-anim="fade-left">
        <div class="kah-studio-card">
          <div class="kah-studio-badge">
            <img src="<?= esc_url($logo) ?>" alt="Logo" width="36" height="36">
            <div>
              <div class="kah-studio-name">KOPHI'S MUSIC STUDIO</div>
              <div class="kah-studio-loc">📍 Abidjan, Côte d'Ivoire</div>
            </div>
          </div>
          <!-- Waveform déco -->
          <div class="kah-wave-deco">
            <?php for ($i = 0; $i < 32; $i++):
              $h = rand(20, 100); ?>
            <div class="kah-wave-bar" style="height:<?= $h ?>%;animation-delay:<?= ($i * 0.08) ?>s"></div>
            <?php endfor; ?>
          </div>
          <div class="kah-studio-infos">
            <div class="kah-studio-info-item">
              <span class="kah-si-label">OUVERTURE</span>
              <span class="kah-si-val">Lun – Sam · 9h – 22h</span>
            </div>
            <div class="kah-studio-info-item">
              <span class="kah-si-label">CONTACT</span>
              <span class="kah-si-val">+225 05 03 40 43 89</span>
            </div>
            <div class="kah-studio-info-item">
              <span class="kah-si-label">FORFAIT MINIMUM</span>
              <span class="kah-si-val" style="color:#E8B84B">50 000 F CFA</span>
            </div>
          </div>
          <a href="<?= esc_url($wa . '?text=' . $wa_text) ?>" class="kah-studio-wa" target="_blank">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a10 10 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
            Nous écrire sur WhatsApp
          </a>
          <!-- Indicateur live -->
          <?php
          $live_count = count(get_posts(['post_type'=>'kophi_session','posts_per_page'=>1,'meta_query'=>[['key'=>'_kophi_statut','value'=>'live']]]));
          if ($live_count): ?>
          <div class="kah-live-ind">
            <span class="kah-live-dot"></span>
            Session en cours
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     5 ─ BEATS (shortcode réutilisé)
══════════════════════════════════════════════════════════════ -->
<?= do_shortcode('[kophi_beats_home count="5" title="NOS INSTRUMENTAUX" subtitle="Produits à Abidjan · Tous styles · Licences BURIDA"]') ?>

<!-- ══════════════════════════════════════════════════════════════
     6 ─ RÉSERVATION (shortcode réutilisé)
══════════════════════════════════════════════════════════════ -->
<?= do_shortcode('[kophi_reservation_home]') ?>

<!-- ══════════════════════════════════════════════════════════════
     7 ─ CTA FINAL
══════════════════════════════════════════════════════════════ -->
<section class="kah-cta-final">
  <div class="kah-cta-bg">
    <div class="kah-cta-glow"></div>
  </div>
  <div class="kah-cta-inner" data-kah-anim="fade-up">
    <img src="<?= esc_url($logo) ?>" alt="KOPHI'S MUSIC STUDIO" class="kah-cta-logo">
    <h2 class="kah-cta-title">Prêt à enregistrer<br><span class="kah-gold">votre prochain hit ?</span></h2>
    <p class="kah-cta-sub">Rejoignez les artistes qui font confiance à KOPHI'S MUSIC STUDIO</p>
    <div class="kah-cta-btns">
      <a href="<?= esc_url($reserve) ?>" class="kah-btn kah-btn-primary kah-btn-lg">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Réserver maintenant
      </a>
      <a href="<?= esc_url($wa . '?text=' . $wa_text) ?>" class="kah-btn kah-btn-wa-lg" target="_blank">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a10 10 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
        +225 05 03 40 43 89
      </a>
    </div>
    <!-- Réseaux sociaux -->
    <div class="kah-cta-socials">
      <?php foreach ([
        ['https://www.instagram.com/kophismusic/', 'Instagram', '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>'],
        ['https://www.youtube.com/@KophisMusic',   'YouTube',   '<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>'],
        ['https://www.tiktok.com/@kophis_music',   'TikTok',    '<path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.32 6.32 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.75a8.28 8.28 0 0 0 4.84 1.56V6.87a4.84 4.84 0 0 1-1.07-.18z"/>'],
        ['https://www.facebook.com/kophismusic',   'Facebook',  '<path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>'],
      ] as [$url, $label, $path]): ?>
      <a href="<?= esc_url($url) ?>" class="kah-social" target="_blank" rel="noopener" aria-label="<?= esc_attr($label) ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><?= $path ?></svg>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

</div><!-- /.kah-wrap -->

<?php
// ── CSS ──────────────────────────────────────────────────────────
?>
<style>
/* ═══════════════════════════════════════════════════════════════
   KOPHI'S MUSIC STUDIO — Page Accueil
   Design System : #0D0D0D · #1e0938 · Gold #E8B84B · Violet #8B3FD9
════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600;700;800&display=swap');

.kah-wrap {
  font-family: 'Inter', sans-serif;
  color: #F0ECF8;
  background: #0D0D0D;
  width: 100%;
  overflow-x: hidden;
}
.kah-gold { color: #E8B84B; }

/* ── HERO ─────────────────────────────────────────────────────── */
.kah-hero {
  position: relative;
  min-height: 100vh;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 120px 24px 80px;
  text-align: center;
  overflow: hidden;
}
.kah-hero-bg { position: absolute; inset: 0; pointer-events: none; }
.kah-hero-glow {
  position: absolute; border-radius: 50%; filter: blur(100px);
}
.kah-glow-v {
  width: 700px; height: 700px;
  background: radial-gradient(circle, rgba(139,63,217,.25) 0%, transparent 70%);
  top: -200px; left: 50%; transform: translateX(-50%);
  animation: kahFloat 8s ease-in-out infinite;
}
.kah-glow-g {
  width: 500px; height: 500px;
  background: radial-gradient(circle, rgba(232,184,75,.12) 0%, transparent 70%);
  bottom: 0; right: -100px;
  animation: kahFloat 10s ease-in-out infinite reverse;
}
.kah-hero-grid {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
  background-size: 80px 80px;
}
.kah-hero-noise {
  position: absolute; inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
  opacity: .4;
}
@keyframes kahFloat {
  0%,100% { transform: translateX(-50%) translateY(0); }
  50%      { transform: translateX(-50%) translateY(-30px); }
}

.kah-hero-inner { position: relative; max-width: 860px; margin: 0 auto; }

.kah-hero-pill {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 7px 18px;
  background: rgba(139,63,217,.12);
  border: 1px solid rgba(139,63,217,.3);
  border-radius: 100px;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 2.5px;
  color: #A855F7;
  text-transform: uppercase;
  margin-bottom: 28px;
}
.kah-pill-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #00C87A;
  box-shadow: 0 0 6px #00C87A;
  animation: kahBlink 2s ease-in-out infinite;
  flex-shrink: 0;
}
@keyframes kahBlink { 0%,100%{opacity:1} 50%{opacity:.3} }

.kah-hero-title {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: clamp(52px, 9vw, 110px) !important;
  line-height: .95 !important;
  letter-spacing: 2px;
  color: #F0ECF8 !important;
  margin: 0 0 24px !important;
}
.kah-hero-sub {
  font-size: clamp(13px, 2vw, 16px);
  color: rgba(240,236,248,.5);
  line-height: 1.7;
  margin: 0 0 40px;
}

/* CTA */
.kah-hero-cta {
  display: flex; flex-wrap: wrap; justify-content: center; gap: 12px;
  margin-bottom: 48px;
}
.kah-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 14px 28px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: .5px;
  text-decoration: none !important;
  transition: all .2s ease;
  cursor: pointer;
  border: none;
  white-space: nowrap;
}
.kah-btn-primary {
  background: linear-gradient(135deg, #8B3FD9, #E8B84B);
  color: #fff !important;
  box-shadow: 0 8px 24px rgba(139,63,217,.35);
}
.kah-btn-primary:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(139,63,217,.5); opacity: .92; }
.kah-btn-wa {
  background: #25D366;
  color: #fff !important;
  box-shadow: 0 8px 24px rgba(37,211,102,.25);
}
.kah-btn-wa:hover { transform: translateY(-3px); background: #1ebe5c; }
.kah-btn-outline {
  color: rgba(240,236,248,.7) !important;
  border: 1.5px solid rgba(255,255,255,.15) !important;
  background: rgba(255,255,255,.04);
}
.kah-btn-outline:hover { background: rgba(255,255,255,.08) !important; border-color: rgba(255,255,255,.3) !important; transform: translateY(-2px); }
.kah-btn-lg { padding: 16px 36px; font-size: 14px; border-radius: 14px; }

/* Badges */
.kah-hero-badges {
  display: flex; flex-wrap: wrap; justify-content: center; gap: 10px;
}
.kah-badge {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 8px;
  font-size: 11px;
  color: rgba(240,236,248,.55);
}

/* Scroll indicator */
.kah-scroll-ind {
  position: absolute;
  bottom: 32px; left: 50%; transform: translateX(-50%);
  display: flex; flex-direction: column; align-items: center; gap: 6px;
}
.kah-scroll-dot {
  width: 4px; height: 28px;
  border-radius: 4px;
  background: linear-gradient(to bottom, rgba(232,184,75,.6), transparent);
  animation: kahScrollDot 2s ease-in-out infinite;
}
@keyframes kahScrollDot {
  0%   { opacity: 0; transform: translateY(-8px); }
  30%  { opacity: 1; }
  100% { opacity: 0; transform: translateY(8px); }
}

/* ── STATS BAR ────────────────────────────────────────────────── */
.kah-stats-bar {
  background: rgba(255,255,255,.03);
  border-top: 1px solid rgba(255,255,255,.06);
  border-bottom: 1px solid rgba(255,255,255,.06);
  padding: 28px 24px;
}
.kah-stats-inner {
  max-width: 900px; margin: 0 auto;
  display: flex; flex-wrap: wrap; justify-content: center; gap: 0;
}
.kah-stat-item {
  display: flex; flex-direction: column; align-items: center;
  padding: 12px 40px;
  border-right: 1px solid rgba(255,255,255,.07);
}
.kah-stat-item:last-child { border-right: none; }
.kah-stat-num {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 36px;
  letter-spacing: 2px;
  color: #E8B84B;
  line-height: 1;
}
.kah-stat-lbl {
  font-size: 10px;
  color: rgba(240,236,248,.4);
  letter-spacing: 1px;
  text-transform: uppercase;
  margin-top: 4px;
}
@media (max-width: 600px) {
  .kah-stat-item { padding: 10px 20px; border-right: none; border-bottom: 1px solid rgba(255,255,255,.06); }
  .kah-stat-item:last-child { border-bottom: none; }
  .kah-stats-inner { flex-direction: column; }
}

/* ── SECTIONS COMMUNES ────────────────────────────────────────── */
.kah-section { padding: 96px 24px; }
.kah-services-section {
  background: linear-gradient(180deg, #0D0D0D, #100820);
}
.kah-why-section {
  background: linear-gradient(180deg, #100820, #1a0932);
}
.kah-section-inner { max-width: 1200px; margin: 0 auto; }
.kah-section-header { text-align: center; margin-bottom: 56px; }
.kah-section-pill {
  display: inline-block;
  padding: 5px 16px;
  background: rgba(139,63,217,.12);
  border: 1px solid rgba(139,63,217,.3);
  border-radius: 100px;
  font-size: 9px; font-weight: 700; letter-spacing: 2.5px;
  color: #A855F7; text-transform: uppercase;
  margin-bottom: 16px;
}
.kah-section-title {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: clamp(32px, 5vw, 56px) !important;
  letter-spacing: 2px;
  color: #F0ECF8 !important;
  margin: 0 0 12px !important;
  line-height: 1 !important;
}
.kah-section-sub {
  font-size: 13px;
  color: rgba(240,236,248,.45);
  margin: 0;
}

/* ── SERVICES GRID ────────────────────────────────────────────── */
.kah-services-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
@media (max-width: 768px) { .kah-services-grid { grid-template-columns: 1fr; } }
@media (min-width: 769px) and (max-width: 1024px) { .kah-services-grid { grid-template-columns: 1fr 1fr; } }

.kah-service-card {
  position: relative;
  padding: 32px;
  background: rgba(255,255,255,.04);
  border: 1.5px solid rgba(255,255,255,.08);
  border-radius: 20px;
  transition: all .3s ease;
  overflow: hidden;
}
.kah-service-card::before {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(135deg, var(--svc-color, #E8B84B)1a 0%, transparent 60%);
  opacity: 0;
  transition: opacity .3s;
}
.kah-service-card:hover { transform: translateY(-6px); border-color: var(--svc-color, #E8B84B); }
.kah-service-card:hover::before { opacity: 1; }
.kah-service-card.kah-featured {
  border-color: rgba(139,63,217,.5);
  background: rgba(139,63,217,.08);
}

.kah-featured-badge {
  position: absolute; top: 16px; right: 16px;
  background: linear-gradient(135deg, #8B3FD9, #E8B84B);
  color: #fff;
  font-size: 8px; font-weight: 800; letter-spacing: 1px;
  padding: 3px 10px; border-radius: 6px;
}
.kah-svc-icon-wrap {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(255,255,255,.06);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 20px;
  color: var(--svc-color, #E8B84B);
}
.kah-svc-titre {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: 22px !important; letter-spacing: 2px;
  color: #F0ECF8 !important;
  margin: 0 0 4px !important;
}
.kah-svc-label {
  display: block;
  font-size: 10px; color: var(--svc-color, #E8B84B);
  letter-spacing: 1px; text-transform: uppercase; font-weight: 600;
  margin-bottom: 14px;
}
.kah-svc-desc {
  font-size: 13px;
  color: rgba(240,236,248,.5);
  line-height: 1.6;
  margin: 0 0 20px;
  flex: 1;
}
.kah-svc-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding-top: 16px;
  border-top: 1px solid rgba(255,255,255,.07);
}
.kah-svc-prix {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 16px; color: var(--svc-color, #E8B84B);
}
.kah-svc-btn {
  font-size: 11px; font-weight: 700;
  color: rgba(240,236,248,.7) !important;
  text-decoration: none !important;
  letter-spacing: .5px;
  transition: color .2s;
}
.kah-svc-btn:hover { color: var(--svc-color, #E8B84B) !important; }

/* ── POURQUOI ─────────────────────────────────────────────────── */
.kah-why-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 80px; align-items: center;
}
@media (max-width: 900px) {
  .kah-why-grid { grid-template-columns: 1fr; gap: 48px; }
  .kah-why-right { order: -1; }
}
.kah-why-desc {
  font-size: 14px;
  color: rgba(240,236,248,.5);
  line-height: 1.7;
  margin: 16px 0 28px;
}
.kah-why-points { display: flex; flex-direction: column; gap: 18px; }
.kah-why-point {
  display: flex; gap: 14px; align-items: flex-start;
  padding: 14px 16px;
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.06);
  border-radius: 12px;
  transition: border-color .2s;
}
.kah-why-point:hover { border-color: rgba(232,184,75,.25); }
.kah-why-ico { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
.kah-why-ptitle { font-size: 13px; font-weight: 700; color: #F0ECF8; margin-bottom: 3px; }
.kah-why-pdesc  { font-size: 11px; color: rgba(240,236,248,.45); line-height: 1.5; }

/* Studio card */
.kah-studio-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 24px;
  padding: 28px;
  position: relative;
  overflow: hidden;
}
.kah-studio-badge {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 24px;
}
.kah-studio-badge img { border-radius: 10px; }
.kah-studio-name { font-family: 'Bebas Neue', sans-serif; font-size: 16px; letter-spacing: 1.5px; color: #F0ECF8; }
.kah-studio-loc  { font-size: 10px; color: rgba(240,236,248,.45); margin-top: 2px; }

/* Waveform déco */
.kah-wave-deco {
  display: flex; align-items: center; gap: 2px;
  height: 60px; margin-bottom: 24px;
}
.kah-wave-bar {
  flex: 1; min-width: 2px; max-width: 6px;
  background: linear-gradient(to top, rgba(139,63,217,.6), rgba(232,184,75,.4));
  border-radius: 2px;
  animation: kahWave 2s ease-in-out infinite alternate;
}
@keyframes kahWave {
  from { transform: scaleY(.4); }
  to   { transform: scaleY(1); }
}

.kah-studio-infos { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
.kah-studio-info-item { display: flex; justify-content: space-between; align-items: center; }
.kah-si-label { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: rgba(240,236,248,.35); }
.kah-si-val   { font-size: 12px; font-weight: 600; color: rgba(240,236,248,.8); }
.kah-studio-wa {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 16px;
  background: rgba(37,211,102,.1);
  border: 1px solid rgba(37,211,102,.25);
  border-radius: 12px;
  color: #25D366 !important;
  text-decoration: none !important;
  font-size: 12px; font-weight: 600;
  transition: all .2s;
}
.kah-studio-wa:hover { background: rgba(37,211,102,.18); transform: translateY(-2px); }

.kah-live-ind {
  position: absolute; top: 16px; right: 16px;
  display: flex; align-items: center; gap: 6px;
  padding: 5px 12px;
  background: rgba(255,71,87,.12);
  border: 1px solid rgba(255,71,87,.3);
  border-radius: 100px;
  font-size: 9px; color: #FF4757; font-weight: 700; letter-spacing: 1px;
}
.kah-live-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: #FF4757; box-shadow: 0 0 6px #FF4757;
  animation: kahBlink 1.5s infinite;
}

/* ── CTA FINAL ────────────────────────────────────────────────── */
.kah-cta-final {
  position: relative;
  padding: 100px 24px;
  text-align: center;
  overflow: hidden;
  background: linear-gradient(180deg, #1a0932, #0D0D0D);
}
.kah-cta-bg { position: absolute; inset: 0; pointer-events: none; }
.kah-cta-glow {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 600px; height: 400px;
  background: radial-gradient(ellipse, rgba(139,63,217,.2) 0%, transparent 70%);
}
.kah-cta-inner { position: relative; max-width: 700px; margin: 0 auto; }
.kah-cta-logo { width: 60px; height: 60px; border-radius: 16px; margin-bottom: 28px; object-fit: contain; }
.kah-cta-title {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: clamp(36px, 6vw, 68px) !important;
  letter-spacing: 2px;
  color: #F0ECF8 !important;
  margin: 0 0 16px !important;
  line-height: 1 !important;
}
.kah-cta-sub {
  font-size: 14px;
  color: rgba(240,236,248,.45);
  margin: 0 0 40px;
}
.kah-cta-btns { display: flex; flex-wrap: wrap; justify-content: center; gap: 14px; margin-bottom: 40px; }
.kah-btn-wa-lg {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 16px 32px;
  background: #25D366;
  border-radius: 14px;
  color: #fff !important;
  text-decoration: none !important;
  font-size: 14px; font-weight: 700;
  transition: all .2s;
  box-shadow: 0 8px 24px rgba(37,211,102,.25);
}
.kah-btn-wa-lg:hover { background: #1ebe5c; transform: translateY(-3px); }

/* Réseaux sociaux */
.kah-cta-socials { display: flex; justify-content: center; gap: 12px; }
.kah-social {
  width: 42px; height: 42px;
  border-radius: 12px;
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.1);
  display: flex; align-items: center; justify-content: center;
  color: rgba(240,236,248,.5) !important;
  text-decoration: none !important;
  transition: all .2s;
}
.kah-social:hover { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.25); color: #F0ECF8 !important; transform: translateY(-3px); }

/* ── ANIMATIONS SCROLL ────────────────────────────────────────── */
[data-kah-anim] { opacity: 0; transition: opacity .6s ease, transform .6s ease; }
[data-kah-anim="fade-up"]    { transform: translateY(30px); }
[data-kah-anim="fade-down"]  { transform: translateY(-20px); }
[data-kah-anim="fade-right"] { transform: translateX(-30px); }
[data-kah-anim="fade-left"]  { transform: translateX(30px); }
[data-kah-anim].kah-visible  { opacity: 1; transform: none; }

/* ── RESPONSIVE ───────────────────────────────────────────────── */
@media (max-width: 480px) {
  .kah-hero { padding: 100px 16px 60px; }
  .kah-section { padding: 64px 16px; }
  .kah-hero-cta { flex-direction: column; align-items: center; }
  .kah-btn { width: 100%; justify-content: center; max-width: 280px; }
}
</style>

<script>
// ── Animations scroll (Intersection Observer) ─────────────────
(function() {
  if (!window.IntersectionObserver) {
    document.querySelectorAll('[data-kah-anim]').forEach(function(el) {
      el.classList.add('kah-visible');
    });
    return;
  }
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        var delay = parseInt(entry.target.dataset.kahDelay || 0);
        setTimeout(function() {
          entry.target.classList.add('kah-visible');
        }, delay);
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

  document.querySelectorAll('[data-kah-anim]').forEach(function(el) {
    obs.observe(el);
  });
})();
</script>
<?php
    return ob_get_clean();
}
add_shortcode('kophi_accueil', 'kophi_shortcode_accueil');
