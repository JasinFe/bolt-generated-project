<?php
/**
 * Shortcode [kophi_beats_home] — Boutique Beats section pour la page accueil
 * Affiche les 4-5 derniers beats en grille avec lecteur audio + bouton achat
 * Usage : [kophi_beats_home count="5" title="NOS INSTRUMENTAUX" cta_url="/beats"]
 */
if (!defined('ABSPATH')) exit;

function kophi_shortcode_beats_home($atts) {
    $a = shortcode_atts([
        'count'   => 5,
        'title'   => 'NOS INSTRUMENTAUX',
        'subtitle'=> 'Produits à Abidjan par KOPHI\'S MUSIC STUDIO',
        'cta_url' => '/beats',
        'cta_text'=> 'Voir tous les beats',
    ], $atts);

    $count = min(8, max(2, (int)$a['count']));

    $beats = get_posts([
        'post_type'      => 'kophi_beat',
        'posts_per_page' => $count,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [[
            'key'     => '_kophi_disponible',
            'value'   => ['1',''],
            'compare' => 'IN',
        ]],
    ]);

    // Genres disponibles pour les beats affichés
    $logo = 'https://studio.kophismusic.com/wp-content/uploads/sites/8/2026/03/LOGO-KOPHIS-MUSIC-STUDIO.png';
    $wa   = KOPHI_WA_LINK;

    ob_start();
    ?>
<section class="kbh-section" id="kophi-beats-home">
  <div class="kbh-bg-glow"></div>

  <div class="kbh-inner">

    <!-- En-tête -->
    <div class="kbh-header">
      <div class="kbh-pill">🎵 BOUTIQUE BEATS · LICENCES BURIDA</div>
      <h2 class="kbh-title">
        <?= esc_html($a['title']) ?>
      </h2>
      <p class="kbh-sub"><?= esc_html($a['subtitle']) ?></p>
    </div>

    <?php if (empty($beats)): ?>
    <div class="kbh-empty">
      <div class="kbh-empty-icon">🎹</div>
      <p>Aucun beat disponible pour le moment.</p>
      <a href="<?= esc_url(KOPHI_WA_LINK) ?>" class="kbh-btn kbh-btn-wa" target="_blank">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
        Commandez sur WhatsApp
      </a>
    </div>
    <?php else: ?>

    <!-- Grille beats -->
    <div class="kbh-grid" id="kbh-grid">
      <?php foreach ($beats as $beat):
        $id         = $beat->ID;
        $titre      = $beat->post_title;
        $audio_url  = get_post_meta($id, '_kophi_fichier_audio', true);
        $prix_ne    = (int)get_post_meta($id, '_kophi_prix_non_exclusive', true);
        $prix_ex    = (int)get_post_meta($id, '_kophi_prix_exclusive', true);
        $tempo      = get_post_meta($id, '_kophi_tempo', true);
        $duree_sec  = (int)get_post_meta($id, '_kophi_duree_sec', true);
        $desc       = get_post_meta($id, '_kophi_description_courte', true);
        $ex_vendu   = get_post_meta($id, '_kophi_exclusive_vendu', true);
        $tags       = get_post_meta($id, '_kophi_tags', true);
        $thumb      = get_the_post_thumbnail_url($id, 'medium');
        $genres     = wp_get_post_terms($id, 'kophi_genre', ['fields'=>'names']);
        $genre_str  = !empty($genres) ? implode(', ', $genres) : '';
        $duree_fmt  = $duree_sec ? sprintf('%d:%02d', intdiv($duree_sec,60), $duree_sec%60) : '';
        $prix_fmt   = $prix_ne ? number_format($prix_ne,0,',',' ') . ' F' : '—';
      ?>
      <div class="kbh-card" data-beat-id="<?= $id ?>" data-audio="<?= esc_attr($audio_url) ?>">

        <!-- Thumbnail / Waveform zone -->
        <div class="kbh-card-visual">
          <?php if ($thumb): ?>
          <img src="<?= esc_url($thumb) ?>" alt="<?= esc_attr($titre) ?>" class="kbh-thumb">
          <?php else: ?>
          <div class="kbh-thumb-placeholder">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="rgba(232,184,75,.4)" stroke-width="1.5"><path d="M9 18V5l12-2v13M9 18c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-2c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/></svg>
          </div>
          <?php endif; ?>

          <!-- Overlay play -->
          <button class="kbh-play-btn" data-beat-id="<?= $id ?>" onclick="kbhTogglePlay(<?= $id ?>, this)">
            <span class="kbh-play-icon">
              <svg class="icon-play" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3l14 9-14 9V3z"/></svg>
              <svg class="icon-pause" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
            </span>
          </button>

          <!-- Durée -->
          <?php if ($duree_fmt): ?>
          <span class="kbh-duration"><?= esc_html($duree_fmt) ?></span>
          <?php endif; ?>

          <!-- Badge genre -->
          <?php if ($genre_str): ?>
          <span class="kbh-genre-badge"><?= esc_html($genre_str) ?></span>
          <?php endif; ?>
        </div>

        <!-- Progress bar audio -->
        <div class="kbh-progress-wrap">
          <div class="kbh-progress-bar" id="kbh-progress-<?= $id ?>"></div>
        </div>

        <!-- Infos -->
        <div class="kbh-card-body">
          <h3 class="kbh-beat-title"><?= esc_html($titre) ?></h3>

          <?php if ($desc): ?>
          <p class="kbh-beat-desc"><?= esc_html(mb_strimwidth($desc, 0, 70, '…')) ?></p>
          <?php endif; ?>

          <!-- Tags meta -->
          <div class="kbh-meta">
            <?php if ($tempo): ?>
            <span class="kbh-tag">🥁 <?= esc_html($tempo) ?> BPM</span>
            <?php endif; ?>
            <?php if ($tags): ?>
            <?php foreach (array_slice(explode(',', $tags), 0, 2) as $tag): ?>
            <span class="kbh-tag"><?= esc_html(trim($tag)) ?></span>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Prix + actions -->
          <div class="kbh-card-footer">
            <div class="kbh-prices">
              <div class="kbh-price-main">
                <span class="kbh-price-label">Non-Exclusive</span>
                <span class="kbh-price-val"><?= esc_html($prix_fmt) ?></span>
              </div>
              <?php if ($prix_ex && !$ex_vendu): ?>
              <div class="kbh-price-ex">
                <span class="kbh-price-label">Exclusive</span>
                <span class="kbh-price-val kbh-price-ex-val"><?= number_format($prix_ex,0,',',' ') ?> F</span>
              </div>
              <?php elseif ($ex_vendu): ?>
              <span class="kbh-sold-badge">Exclusive vendue</span>
              <?php endif; ?>
            </div>
            <a href="<?= esc_url($wa . '?text=' . urlencode("Bonjour KOPHI'S MUSIC STUDIO ! Je suis intéressé par le beat : {$titre}")) ?>"
               class="kbh-buy-btn" target="_blank">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
              Obtenir
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- CTA voir tous les beats -->
    <div class="kbh-cta-wrap">
      <a href="<?= esc_url(home_url($a['cta_url'])) ?>" class="kbh-btn kbh-btn-outline">
        <?= esc_html($a['cta_text']) ?>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </a>
    </div>

    <?php endif; ?>
  </div>
</section>

<style>
/* ── KOPHI Beats Home Shortcode ────────────────────────────── */
#kophi-beats-home {
  position: relative;
  padding: 90px 20px;
  background: linear-gradient(180deg, #0D0D0D 0%, #100822 50%, #1e0938 100%);
  overflow: hidden;
  font-family: 'Inter', sans-serif;
}
.kbh-bg-glow {
  position: absolute;
  top: -200px; left: 50%;
  transform: translateX(-50%);
  width: 700px; height: 700px;
  background: radial-gradient(circle, rgba(139,63,217,.18) 0%, transparent 65%);
  pointer-events: none;
}
.kbh-inner {
  max-width: 1280px;
  margin: 0 auto;
  position: relative;
}

/* Header */
.kbh-header { text-align: center; margin-bottom: 56px; }
.kbh-pill {
  display: inline-block;
  padding: 6px 18px;
  background: rgba(139,63,217,.18);
  border: 1px solid rgba(139,63,217,.4);
  border-radius: 100px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 2.5px;
  color: #A855F7;
  text-transform: uppercase;
  margin-bottom: 20px;
}
.kbh-title {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: clamp(38px, 6vw, 72px) !important;
  letter-spacing: 3px;
  color: #F0ECF8 !important;
  margin: 0 0 14px !important;
  line-height: 1 !important;
}
.kbh-title span { color: #E8B84B; }
.kbh-sub {
  color: rgba(240,236,248,.45);
  font-size: 13px;
  margin: 0;
}

/* Grille */
.kbh-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 20px;
  margin-bottom: 48px;
}
@media (min-width: 1200px) {
  .kbh-grid { grid-template-columns: repeat(5, 1fr); }
}
@media (max-width: 900px) {
  .kbh-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
}
@media (max-width: 540px) {
  .kbh-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
}
@media (max-width: 380px) {
  .kbh-grid { grid-template-columns: 1fr; }
}

/* Carte beat */
.kbh-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px;
  overflow: hidden;
  transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
  cursor: pointer;
}
.kbh-card:hover {
  transform: translateY(-4px);
  border-color: rgba(232,184,75,.3);
  box-shadow: 0 16px 40px rgba(0,0,0,.4), 0 0 0 1px rgba(232,184,75,.1);
}

/* Visual */
.kbh-card-visual {
  position: relative;
  aspect-ratio: 1;
  background: linear-gradient(135deg, #1a0a30, #0f0520);
  overflow: hidden;
}
.kbh-thumb {
  width: 100%; height: 100%;
  object-fit: cover;
  opacity: .85;
  transition: opacity .3s;
}
.kbh-card:hover .kbh-thumb { opacity: .7; }
.kbh-thumb-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, rgba(139,63,217,.15), rgba(232,184,75,.08));
}

/* Play btn */
.kbh-play-btn {
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%) scale(.85);
  width: 48px; height: 48px;
  border-radius: 50%;
  background: rgba(232,184,75,.95);
  border: none;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: #0D0D0D;
  opacity: 0;
  transition: opacity .2s, transform .2s;
  z-index: 2;
}
.kbh-card:hover .kbh-play-btn,
.kbh-card.playing .kbh-play-btn { opacity: 1; transform: translate(-50%,-50%) scale(1); }
.kbh-card.playing .kbh-play-btn { background: rgba(139,63,217,.95); color: #fff; }

.kbh-duration {
  position: absolute;
  bottom: 7px; right: 8px;
  background: rgba(0,0,0,.65);
  color: #E8B84B;
  font-size: 9px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 4px;
}
.kbh-genre-badge {
  position: absolute;
  top: 8px; left: 8px;
  background: rgba(139,63,217,.75);
  backdrop-filter: blur(4px);
  color: #fff;
  font-size: 8px;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 4px;
}

/* Progress */
.kbh-progress-wrap {
  height: 2px;
  background: rgba(255,255,255,.07);
}
.kbh-progress-bar {
  height: 2px;
  background: linear-gradient(90deg, #8B3FD9, #E8B84B);
  width: 0%;
  transition: width .1s linear;
}

/* Card body */
.kbh-card-body { padding: 14px; }
.kbh-beat-title {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: 16px !important;
  letter-spacing: 1.5px;
  color: #F0ECF8 !important;
  margin: 0 0 5px !important;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.2 !important;
}
.kbh-beat-desc {
  font-size: 10px;
  color: rgba(240,236,248,.45);
  margin: 0 0 10px;
  line-height: 1.4;
}
.kbh-meta {
  display: flex; flex-wrap: wrap; gap: 4px;
  margin-bottom: 12px;
}
.kbh-tag {
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.1);
  color: rgba(240,236,248,.6);
  font-size: 9px;
  padding: 2px 7px;
  border-radius: 4px;
}

/* Footer card */
.kbh-card-footer {
  display: flex; align-items: flex-end; justify-content: space-between; gap: 8px;
}
.kbh-prices {}
.kbh-price-main, .kbh-price-ex {
  display: flex; flex-direction: column;
}
.kbh-price-label {
  font-size: 8px;
  color: rgba(240,236,248,.35);
  letter-spacing: 1px;
  text-transform: uppercase;
}
.kbh-price-val {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 18px;
  color: #E8B84B;
  line-height: 1;
}
.kbh-price-ex { margin-top: 2px; }
.kbh-price-ex-val { font-size: 13px; color: rgba(240,236,248,.5); }
.kbh-sold-badge {
  font-size: 8px;
  color: #FF4757;
  background: rgba(255,71,87,.1);
  border: 1px solid rgba(255,71,87,.25);
  padding: 2px 7px;
  border-radius: 4px;
  margin-top: 4px;
  display: inline-block;
}

/* Bouton obtenir */
.kbh-buy-btn {
  display: inline-flex; align-items: center; gap: 5px;
  background: linear-gradient(135deg, #8B3FD9, #E8B84B);
  color: #fff !important;
  text-decoration: none !important;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .5px;
  padding: 7px 12px;
  border-radius: 8px;
  transition: opacity .15s, transform .15s;
  white-space: nowrap;
  flex-shrink: 0;
}
.kbh-buy-btn:hover { opacity: .88; transform: scale(1.03); }

/* CTA général */
.kbh-cta-wrap { text-align: center; }
.kbh-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 13px 32px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  text-decoration: none !important;
  transition: all .2s;
  cursor: pointer;
  border: none;
}
.kbh-btn-outline {
  color: #E8B84B !important;
  border: 1.5px solid rgba(232,184,75,.45) !important;
  background: rgba(232,184,75,.07);
}
.kbh-btn-outline:hover {
  background: rgba(232,184,75,.15) !important;
  border-color: #E8B84B !important;
  transform: translateY(-2px);
}
.kbh-btn-wa {
  background: #25D366;
  color: #fff !important;
}
.kbh-btn-wa:hover { background: #1ebe5c; transform: translateY(-2px); }

/* Empty */
.kbh-empty { text-align: center; padding: 60px 0; color: rgba(240,236,248,.4); }
.kbh-empty-icon { font-size: 48px; margin-bottom: 16px; }

/* Animation entrée */
.kbh-card {
  animation: kbhFadeUp .5s ease both;
}
<?php foreach (range(1, $count) as $n): ?>
.kbh-card:nth-child(<?= $n ?>) { animation-delay: <?= ($n-1)*.07 ?>s; }
<?php endforeach; ?>
@keyframes kbhFadeUp {
  from { opacity: 0; transform: translateY(24px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>

<script>
(function() {
  var _kbhAudio   = null;
  var _kbhCurrent = null;

  window.kbhTogglePlay = function(beatId, btn) {
    var card     = btn.closest('.kbh-card');
    var audioUrl = card.dataset.audio;
    var progress = document.getElementById('kbh-progress-' + beatId);
    var playIcon  = btn.querySelector('.icon-play');
    var pauseIcon = btn.querySelector('.icon-pause');

    // Stopper l'audio en cours (autre beat)
    if (_kbhCurrent && _kbhCurrent !== beatId) {
      if (_kbhAudio) { _kbhAudio.pause(); _kbhAudio = null; }
      var prevCard = document.querySelector('.kbh-card[data-beat-id="' + _kbhCurrent + '"]');
      if (prevCard) {
        prevCard.classList.remove('playing');
        var pp = prevCard.querySelector('.icon-play');
        var pi = prevCard.querySelector('.icon-pause');
        var pg = document.getElementById('kbh-progress-' + _kbhCurrent);
        if (pp) pp.style.display = ''; if (pi) pi.style.display = 'none';
        if (pg) pg.style.width = '0%';
      }
    }

    // Toggle play/pause sur ce beat
    if (!_kbhAudio || _kbhCurrent !== beatId) {
      if (!audioUrl) {
        // Pas d'audio → ouvrir WhatsApp
        window.open('<?= esc_js(KOPHI_WA_LINK) ?>', '_blank');
        return;
      }
      _kbhAudio   = new Audio(audioUrl);
      _kbhCurrent = beatId;
      card.classList.add('playing');
      if (playIcon) playIcon.style.display = 'none';
      if (pauseIcon) pauseIcon.style.display = '';

      _kbhAudio.addEventListener('timeupdate', function() {
        if (_kbhAudio.duration && progress) {
          progress.style.width = (_kbhAudio.currentTime / _kbhAudio.duration * 100) + '%';
        }
      });
      _kbhAudio.addEventListener('ended', function() {
        card.classList.remove('playing');
        if (playIcon) playIcon.style.display = '';
        if (pauseIcon) pauseIcon.style.display = 'none';
        if (progress) progress.style.width = '0%';
        _kbhAudio = null; _kbhCurrent = null;
      });
      _kbhAudio.play().catch(function() {});

    } else if (_kbhAudio.paused) {
      _kbhAudio.play();
      card.classList.add('playing');
      if (playIcon) playIcon.style.display = 'none';
      if (pauseIcon) pauseIcon.style.display = '';
    } else {
      _kbhAudio.pause();
      card.classList.remove('playing');
      if (playIcon) playIcon.style.display = '';
      if (pauseIcon) pauseIcon.style.display = 'none';
    }
  };
})();
</script>
<?php
    return ob_get_clean();
}
add_shortcode('kophi_beats_home', 'kophi_shortcode_beats_home');
