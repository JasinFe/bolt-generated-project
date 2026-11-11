<?php
/**
 * Template Name: Boutique Beats KOPHI
 * Template Post Type: page
 *
 * Page template complet pour la boutique beats.
 * Sélectionnez "Boutique Beats KOPHI" dans Attributs de page → Template.
 */

// Variables globales
$wa      = get_option('kophi_whatsapp', '+2250503404389');
$wa_num  = preg_replace('/[^0-9]/', '', $wa);
$wa_link = 'https://wa.me/' . $wa_num;
$logo    = 'https://studio.kophismusic.com/wp-content/uploads/sites/8/2026/03/LOGO-KOPHIS-MUSIC-STUDIO.png';
$rest    = rest_url('kophi/v1/');
$nonce   = wp_create_nonce('wp_rest');

// Récupérer tous les beats publiés
$beats_args = [
    'post_type'      => 'kophi_beat',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'menu_order date',
    'order'          => 'ASC',
];
$beats = get_posts($beats_args);

// Récupérer les genres disponibles
$genres = get_terms(['taxonomy' => 'kophi_genre', 'hide_empty' => true]);

// Labels services
$svc_labels = [
    'non_exclusive' => 'Non-Exclusive',
    'exclusive'     => 'Exclusive',
];

// Émettre un HTML complet — pas de header() WordPress
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Boutique Beats KOPHI'S MUSIC STUDIO — Instrumentaux Urban Gospel, Afrobeats, Trap · Abidjan, Côte d'Ivoire">
<title>Boutique Beats — KM STUDIO</title>
<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<!-- WaveSurfer -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/wavesurfer.js/7.8.2/wavesurfer.min.js" defer></script>
<?php wp_head(); ?>
<style>
/* ══════════════════════════════════════════════
   BOUTIQUE BEATS — KM STUDIO
   Reset complet + design propre
══════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --gold: #E8B84B; --gold-d: rgba(232,184,75,.12); --gold-b: rgba(232,184,75,.3);
  --violet: #8B3FD9; --violet2: #A855F7; --violet-d: rgba(139,63,217,.15);
  --green: #00C87A; --green-d: rgba(0,200,122,.12);
  --red: #FF4757; --red-d: rgba(255,71,87,.12);
  --bg: #0D0D0D; --bg2: #1e0938;
  --glass: rgba(255,255,255,.05); --border: rgba(255,255,255,.09);
  --text: #F0ECF8; --text2: #C8C0DC; --text3: #9E98B8;
}
html, body { height: 100%; }
body {
  font-family: 'Inter', -apple-system, sans-serif;
  background: linear-gradient(to bottom, var(--bg) 0%, var(--bg) 55%, #120a24 80%, var(--bg2) 100%);
  background-attachment: fixed;
  color: var(--text);
  min-height: 100vh;
}

/* ── HEADER ───────────────────────────────────────────────── */
.kb-header {
  position: sticky; top: 0; z-index: 999;
  background: rgba(6,4,16,.97);
  backdrop-filter: blur(24px);
  border-bottom: 1px solid rgba(232,184,75,.1);
  box-shadow: 0 2px 20px rgba(0,0,0,.5);
}
.kb-header-inner {
  max-width: 1400px; margin: 0 auto;
  padding: 0 clamp(14px,4vw,48px);
  height: 64px; display: flex; align-items: center; gap: 20px;
}
.kb-logo { display: flex; align-items: center; text-decoration: none; flex-shrink: 0; }
.kb-logo img { height: 38px; width: auto; transition: opacity .2s; }
.kb-logo:hover img { opacity: .8; }
.kb-nav { display: flex; align-items: center; gap: 2px; flex: 1; }
.kb-nav-link {
  font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
  color: rgba(255,255,255,.55); text-decoration: none;
  padding: 8px 14px; border-radius: 8px;
  transition: all .25s; white-space: nowrap; position: relative;
}
.kb-nav-link::after {
  content: ''; position: absolute; bottom: 4px; left: 14px; right: 14px;
  height: 1.5px; background: var(--gold); border-radius: 2px;
  transform: scaleX(0); transform-origin: left; transition: transform .25s ease;
}
.kb-nav-link:hover, .kb-nav-link.active { color: var(--gold); background: var(--gold-d); }
.kb-nav-link:hover::after, .kb-nav-link.active::after { transform: scaleX(1); }
.kb-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; flex-shrink: 0; }
.kb-wa {
  width: 38px; height: 38px; border-radius: 50%;
  background: rgba(37,211,102,.1); border: 1.5px solid rgba(37,211,102,.25);
  color: #25D366; display: flex; align-items: center; justify-content: center;
  text-decoration: none; transition: all .25s;
}
.kb-wa:hover { background: #25D366; color: #fff; transform: scale(1.1); }
.kb-cta {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;
  background: linear-gradient(135deg, var(--violet), var(--gold));
  color: #fff; padding: 9px 20px; border-radius: 22px; text-decoration: none;
  transition: all .3s; box-shadow: 0 4px 14px rgba(139,63,217,.3);
}
.kb-cta:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(139,63,217,.45); color: #fff; }
.kb-burger {
  display: none; flex-direction: column; justify-content: center; gap: 5px;
  width: 38px; height: 38px; background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.1); border-radius: 8px; cursor: pointer;
}
.kb-burger span { display: block; height: 2px; background: rgba(255,255,255,.8); border-radius: 2px; transition: all .3s; }
.kb-burger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.kb-burger.open span:nth-child(2) { opacity: 0; }
.kb-burger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
.kb-mob-nav {
  display: none; flex-direction: column;
  background: rgba(6,4,16,.99); border-top: 1px solid rgba(255,255,255,.05);
  max-height: 0; overflow: hidden; transition: max-height .35s ease;
}
.kb-mob-nav.open { max-height: 400px; }
.kb-mob-link {
  font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
  color: rgba(255,255,255,.65); text-decoration: none;
  padding: 14px clamp(14px,4vw,48px); border-bottom: 1px solid rgba(255,255,255,.04); transition: all .2s;
}
.kb-mob-link:hover { color: var(--gold); background: var(--gold-d); }
.kb-mob-wa {
  display: block; margin: 12px 16px; padding: 12px 20px; background: #25D366;
  color: #fff; text-decoration: none; border-radius: 10px; font-size: 13px; font-weight: 700; text-align: center;
}

/* ── HERO ────────────────────────────────────────────────── */
.kb-hero {
  text-align: center; padding: 60px 20px 40px; position: relative;
}
.kb-hero::before {
  content: ''; position: absolute; inset: 0; pointer-events: none;
  background: radial-gradient(ellipse 60% 80% at 50% 50%, rgba(139,63,217,.12) 0%, transparent 70%);
}
.kb-hero-badge {
  display: inline-block; font-size: 9px; font-weight: 700; letter-spacing: 3px;
  text-transform: uppercase; color: var(--gold);
  background: var(--gold-d); border: 1px solid var(--gold-b);
  padding: 5px 16px; border-radius: 20px; margin-bottom: 16px;
}
.kb-hero h1 {
  font-family: 'Bebas Neue', sans-serif;
  font-size: clamp(40px, 7vw, 80px); letter-spacing: 3px; color: #fff;
  line-height: 1; margin-bottom: 12px;
}
.kb-hero h1 span { color: var(--gold); }
.kb-hero p { font-size: 13px; color: var(--text3); max-width: 480px; margin: 0 auto; line-height: 1.8; }

/* ── FILTRES ─────────────────────────────────────────────── */
.kb-filters {
  display: flex; gap: 8px; flex-wrap: wrap; justify-content: center;
  padding: 0 clamp(14px,4vw,48px) 28px;
}
.kb-filter-btn {
  font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
  background: var(--glass); border: 1px solid var(--border);
  color: var(--text3); padding: 7px 16px; border-radius: 20px;
  cursor: pointer; transition: all .2s; white-space: nowrap;
}
.kb-filter-btn:hover, .kb-filter-btn.active {
  background: var(--gold-d); border-color: var(--gold-b); color: var(--gold);
}

/* ── GRILLE BEATS ───────────────────────────────────────── */
.kb-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
  padding: 0 clamp(14px,4vw,48px) 60px;
  max-width: 1400px; margin: 0 auto;
}

/* ── CARTE BEAT ─────────────────────────────────────────── */
.kb-card {
  background: var(--glass);
  border: 1px solid var(--border);
  border-radius: 16px; overflow: hidden;
  transition: all .25s; position: relative;
}
.kb-card:hover { border-color: var(--gold-b); transform: translateY(-4px); box-shadow: 0 20px 50px rgba(0,0,0,.4); }

/* Cover */
.kb-cover {
  position: relative; height: 180px; cursor: pointer;
  background: linear-gradient(135deg, rgba(139,63,217,.4), rgba(13,13,13,.9));
  overflow: hidden;
}
.kb-cover-img {
  position: absolute; inset: 0; width: 100%; height: 100%;
  object-fit: cover; opacity: .55; transition: opacity .3s;
}
.kb-card:hover .kb-cover-img { opacity: .65; }
.kb-play {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -58%);
  width: 48px; height: 48px; border-radius: 50%;
  background: rgba(232,184,75,.92); border: none; color: #000;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; z-index: 2;
  transition: all .2s; box-shadow: 0 4px 20px rgba(0,0,0,.4);
}
.kb-play:hover { background: var(--gold); transform: translate(-50%, -58%) scale(1.1); }
.kb-waveform {
  position: absolute; bottom: 0; left: 0; right: 0;
  height: 50px; padding: 0 8px; z-index: 1;
}
.kb-waveform wave { overflow: hidden !important; }

/* Infos */
.kb-info { padding: 16px; }
.kb-title {
  font-family: 'Bebas Neue', sans-serif; font-size: 20px;
  color: #fff; letter-spacing: .5px; margin-bottom: 8px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kb-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; font-size: 10px; }
.kb-genre {
  background: var(--violet-d); color: var(--violet2);
  border: 1px solid rgba(139,63,217,.3);
  padding: 2px 10px; border-radius: 20px; font-weight: 700; font-size: 9px; letter-spacing: .5px;
}
.kb-bpm { color: var(--text3); display: flex; align-items: center; gap: 4px; }
.kb-dur { color: var(--text3); display: flex; align-items: center; gap: 4px; }

/* Prix */
.kb-prices {
  display: grid; grid-template-columns: 1fr 1fr;
  background: rgba(0,0,0,.25); border: 1px solid var(--border);
  border-radius: 10px; overflow: hidden; margin-bottom: 12px;
}
.kb-price-col { padding: 10px 12px; text-align: center; }
.kb-price-col:first-child { border-right: 1px solid var(--border); }
.kb-price-label { font-size: 8px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--text3); margin-bottom: 4px; }
.kb-price-val { font-family: 'Bebas Neue', sans-serif; font-size: 18px; color: var(--gold); }
.kb-price-sold { color: var(--red); font-size: 13px; text-decoration: line-through; }

/* Boutons achat */
.kb-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.kb-btn-ne, .kb-btn-ex {
  font-family: 'Inter', sans-serif; font-size: 10px; font-weight: 700;
  letter-spacing: .5px; padding: 10px; border-radius: 8px;
  border: none; cursor: pointer; transition: all .2s; text-transform: uppercase;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
}
.kb-btn-ne { background: var(--gold-d); border: 1px solid var(--gold-b); color: var(--gold); }
.kb-btn-ne:hover { background: var(--gold); color: #000; }
.kb-btn-ex { background: var(--violet-d); border: 1px solid rgba(139,63,217,.3); color: var(--violet2); }
.kb-btn-ex:hover { background: var(--violet); color: #fff; }
.kb-btn-ex:disabled { opacity: .3; cursor: not-allowed; }
.kb-btn-price { font-size: 9px; opacity: .7; font-weight: 400; }

/* Empty state */
.kb-empty {
  text-align: center; padding: 80px 20px; grid-column: 1/-1; color: var(--text3);
}
.kb-empty-icon { font-size: 48px; margin-bottom: 16px; opacity: .4; }

/* ── MODAL ACHAT ────────────────────────────────────────── */
.kb-overlay {
  display: none; position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,.88); backdrop-filter: blur(12px);
  align-items: center; justify-content: center; padding: 20px;
}
.kb-overlay.open { display: flex; }
.kb-modal {
  background: linear-gradient(135deg, #16142A, #1e1c38);
  border: 1px solid var(--gold-b); border-radius: 20px;
  padding: 28px; width: 100%; max-width: 460px;
  box-shadow: 0 0 60px rgba(232,184,75,.15), 0 30px 80px rgba(0,0,0,.6);
  animation: kbModalIn .3s ease; position: relative; overflow: hidden;
}
.kb-modal::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--violet),var(--gold)); }
@keyframes kbModalIn { from{opacity:0;transform:scale(.93) translateY(20px)} to{opacity:1;transform:none} }
.kb-modal-title {
  font-family: 'Bebas Neue', sans-serif; font-size: 22px; letter-spacing: 2px;
  color: #fff; margin-bottom: 16px;
  display: flex; justify-content: space-between; align-items: center;
}
.kb-modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text3); transition: color .2s; }
.kb-modal-close:hover { color: var(--red); }
.kb-modal-info { margin-bottom: 18px; padding: 14px; background: rgba(255,255,255,.04); border-radius: 10px; }
.kb-fg { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.kb-fg label { font-size: 9px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--text3); }
.kb-fg input, .kb-fg select, .kb-fg textarea {
  font-family: 'Inter', sans-serif; font-size: 13px; padding: 11px 14px;
  background: rgba(0,0,0,.35); border: 1.5px solid var(--border);
  border-radius: 10px; color: #fff; outline: none; width: 100%;
  transition: border .2s, box-shadow .2s;
}
.kb-fg input:focus, .kb-fg select:focus { border-color: var(--gold-b); box-shadow: 0 0 0 3px rgba(232,184,75,.1); }
.kb-fg input::placeholder { color: rgba(255,255,255,.25); }
.kb-fg select option { background: #1A1830; }
.kb-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.kb-modal-err {
  display: none; margin-bottom: 12px; padding: 10px 14px;
  background: var(--red-d); border: 1px solid rgba(255,71,87,.3);
  border-radius: 8px; font-size: 12px; color: #FFB3B8;
}
.kb-btn-pay {
  width: 100%; padding: 14px; font-family: 'Bebas Neue', sans-serif;
  font-size: 18px; letter-spacing: 2px; border: none; border-radius: 12px;
  background: linear-gradient(135deg, var(--violet), var(--gold));
  color: #fff; cursor: pointer; transition: all .3s;
  box-shadow: 0 6px 20px rgba(139,63,217,.3); margin-top: 6px;
}
.kb-btn-pay:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(139,63,217,.45); }
.kb-btn-pay:disabled { opacity: .3; cursor: not-allowed; transform: none; }
.kb-divider { text-align: center; font-size: 10px; color: var(--text3); margin: 10px 0; letter-spacing: 1px; }
.kb-btn-wa {
  width: 100%; padding: 12px; font-family: 'Inter', sans-serif;
  font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
  background: rgba(37,211,102,.12); border: 1px solid rgba(37,211,102,.3);
  color: #25D366; border-radius: 12px; cursor: pointer; transition: all .2s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.kb-btn-wa:hover { background: #25D366; color: #fff; }

/* ── SECTION CUSTOM + FAQ ───────────────────────────────── */
.kb-section { max-width: 1400px; margin: 0 auto; padding: 0 clamp(14px,4vw,48px) 60px; }
.kb-section-title {
  font-family: 'Bebas Neue', sans-serif; font-size: 32px; letter-spacing: 2px;
  color: #fff; margin-bottom: 6px;
}
.kb-section-title span { color: var(--gold); }
.kb-section-sub { font-size: 12px; color: var(--text3); margin-bottom: 28px; line-height: 1.7; }

/* Custom beat form */
.kb-custom-form {
  background: var(--glass); border: 1px solid var(--border);
  border-radius: 16px; padding: 28px; position: relative; overflow: hidden;
}
.kb-custom-form::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--violet),var(--gold)); }

/* Table licences */
.kb-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.kb-table th { font-size: 9px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--text3); padding: 10px 14px; border-bottom: 1px solid var(--border); text-align: left; background: rgba(0,0,0,.2); }
.kb-table td { padding: 11px 14px; border-bottom: 1px solid rgba(255,255,255,.04); color: var(--text2); }
.kb-table tr:last-child td { border-bottom: none; }
.kb-table .td-gold { color: var(--gold); font-weight: 700; }
.kb-table .td-violet { color: var(--violet2); font-weight: 700; }
.kb-check { color: var(--green); font-weight: 700; }

/* ── FOOTER ─────────────────────────────────────────────── */
.kb-footer {
  background: #060410; border-top: 1px solid rgba(255,255,255,.06);
  font-family: 'Inter', sans-serif;
}
.kb-footer-inner {
  max-width: 1400px; margin: 0 auto;
  padding: 28px clamp(14px,4vw,48px);
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
}
.kb-footer-links { display: flex; gap: 20px; flex-wrap: wrap; }
.kb-footer-links a { font-size: 11px; color: rgba(255,255,255,.4); text-decoration: none; transition: color .2s; }
.kb-footer-links a:hover { color: var(--gold); }
.kb-footer-copy { font-size: 11px; color: rgba(255,255,255,.25); }

/* ── RESPONSIVE ─────────────────────────────────────────── */
@media(max-width:900px) {
  .kb-nav { display: none; }
  .kb-burger { display: flex; }
  .kb-mob-nav { display: flex; }
}
@media(max-width:600px) {
  .kb-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
  .kb-cta span { display: none; }
  .kb-form-row { grid-template-columns: 1fr; }
  .kb-footer-inner { flex-direction: column; align-items: center; text-align: center; }
}
@media(max-width:380px) {
  .kb-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ══ HEADER ═══════════════════════════════════════════════ -->
<header class="kb-header">
  <div class="kb-header-inner">
    <a href="<?= esc_url(home_url('/')) ?>" class="kb-logo">
      <img src="<?= esc_url($logo) ?>" alt="KOPHI'S MUSIC STUDIO">
    </a>
    <nav class="kb-nav">
      <a href="<?= esc_url(home_url('/')) ?>" class="kb-nav-link">ACCUEIL</a>
      <a href="<?= esc_url(get_permalink()) ?>" class="kb-nav-link active">BOUTIQUE BEATS</a>
      <a href="<?= esc_url(home_url('/reserver')) ?>" class="kb-nav-link">RÉSERVER</a>
      <a href="https://kophismusic.com/" class="kb-nav-link" target="_blank" rel="noopener">LABEL</a>
      <?php if (current_user_can('administrator') || current_user_can('kophi_manage_sessions')): ?>
      <a href="<?= esc_url(admin_url('admin.php?page=kophi-beats')) ?>" class="kb-nav-link" style="color:var(--violet2)">⚙ ADMIN BEATS</a>
      <?php endif; ?>
    </nav>
    <div class="kb-actions">
      <a href="<?= esc_url($wa_link) ?>?text=<?= urlencode("Bonjour KOPHI'S MUSIC STUDIO ! Question sur les beats.") ?>"
         target="_blank" rel="noopener" class="kb-wa" title="WhatsApp">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
      </a>
      <a href="<?= esc_url(home_url('/reserver')) ?>" class="kb-cta">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
        <span>RÉSERVER</span>
      </a>
      <button class="kb-burger" id="kb-burger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
  <div class="kb-mob-nav" id="kb-mob-nav">
    <a href="<?= esc_url(home_url('/')) ?>" class="kb-mob-link">ACCUEIL</a>
    <a href="<?= esc_url(get_permalink()) ?>" class="kb-mob-link">BOUTIQUE BEATS</a>
    <a href="<?= esc_url(home_url('/reserver')) ?>" class="kb-mob-link">RÉSERVER</a>
    <a href="https://kophismusic.com/" class="kb-mob-link" target="_blank">LABEL</a>
    <a href="<?= esc_url($wa_link) ?>" class="kb-mob-wa" target="_blank">💬 <?= esc_html($wa) ?></a>
  </div>
</header>

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<div class="kb-hero">
  <div class="kb-hero-badge">🎹 BOUTIQUE BEATS · LICENCES BURIDA</div>
  <h1>NOS <span>INSTRUMENTAUX</span></h1>
  <p>Instrumentaux professionnels · Tous genres<br>Produits à Abidjan par KOPHI'S MUSIC STUDIO</p>
</div>

<!-- ══ FILTRES GENRES ════════════════════════════════════════ -->
<div class="kb-filters">
  <button class="kb-filter-btn active" onclick="kbFilter('', this)">TOUS</button>
  <?php foreach ($genres as $g): ?>
  <button class="kb-filter-btn" onclick="kbFilter('<?= esc_js($g->slug) ?>', this)"><?= esc_html(strtoupper($g->name)) ?></button>
  <?php endforeach; ?>
</div>

<!-- ══ GRILLE BEATS ══════════════════════════════════════════ -->
<div class="kb-grid" id="kb-grid">
<?php if (empty($beats)): ?>
  <div class="kb-empty">
    <div class="kb-empty-icon">🎹</div>
    <p>Aucun beat disponible pour le moment.<br>Revenez bientôt ou contactez-nous sur WhatsApp.</p>
    <a href="<?= esc_url($wa_link) ?>" target="_blank" style="display:inline-block;margin-top:16px;background:#25D366;color:#fff;padding:10px 24px;border-radius:20px;text-decoration:none;font-size:12px;font-weight:700">💬 Nous contacter</a>
  </div>
<?php else: ?>
<?php foreach ($beats as $beat):
  $beat_id  = $beat->ID;
  $audio    = get_post_meta($beat_id, '_kophi_fichier_audio', true);
  $prix_ne  = (int)get_post_meta($beat_id, '_kophi_prix_non_exclusive', true);
  $prix_ex  = (int)get_post_meta($beat_id, '_kophi_prix_exclusive', true);
  $ex_sold  = get_post_meta($beat_id, '_kophi_exclusive_vendu', true);
  $tempo    = get_post_meta($beat_id, '_kophi_tempo', true);
  $duree    = (int)get_post_meta($beat_id, '_kophi_duree_sec', true);
  $desc     = get_post_meta($beat_id, '_kophi_description_courte', true);
  $thumb    = get_the_post_thumbnail_url($beat_id, 'medium');
  $beat_genres = wp_get_post_terms($beat_id, 'kophi_genre', ['fields' => 'all']);
  $genre_slug  = !empty($beat_genres) ? $beat_genres[0]->slug : '';
  $genre_name  = !empty($beat_genres) ? $beat_genres[0]->name : '';
  $duree_fmt   = $duree ? gmdate('i:s', $duree) : '';
?>
<div class="kb-card" data-genre="<?= esc_attr($genre_slug) ?>">
  <!-- Cover -->
  <div class="kb-cover" onclick="kbPlay(<?= $beat_id ?>, '<?= esc_js($audio) ?>')">
    <?php if ($thumb): ?>
    <img class="kb-cover-img" src="<?= esc_url($thumb) ?>" alt="<?= esc_attr($beat->post_title) ?>" loading="lazy">
    <?php endif; ?>
    <button class="kb-play" id="kb-play-<?= $beat_id ?>" aria-label="Écouter">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg>
    </button>
    <div class="kb-waveform" id="kb-wave-<?= $beat_id ?>"></div>
  </div>
  <!-- Infos -->
  <div class="kb-info">
    <div class="kb-title"><?= esc_html($beat->post_title) ?></div>
    <div class="kb-meta">
      <?php if ($genre_name): ?><span class="kb-genre"><?= esc_html($genre_name) ?></span><?php endif; ?>
      <?php if ($tempo): ?><span class="kb-bpm"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg><?= esc_html($tempo) ?> BPM</span><?php endif; ?>
      <?php if ($duree_fmt): ?><span class="kb-dur"><?= esc_html($duree_fmt) ?></span><?php endif; ?>
    </div>
    <?php if ($desc): ?><p style="font-size:11px;color:var(--text3);margin-bottom:10px;line-height:1.5"><?= esc_html($desc) ?></p><?php endif; ?>
    <!-- Prix -->
    <div class="kb-prices">
      <div class="kb-price-col">
        <div class="kb-price-label">Non-Exclusive</div>
        <div class="kb-price-val"><?= $prix_ne ? number_format($prix_ne, 0, ',', ' ') . ' F' : '—' ?></div>
      </div>
      <div class="kb-price-col">
        <div class="kb-price-label">Exclusive</div>
        <div class="kb-price-val <?= $ex_sold ? 'kb-price-sold' : '' ?>"><?= $ex_sold ? 'VENDUE' : ($prix_ex ? number_format($prix_ex, 0, ',', ' ') . ' F' : '—') ?></div>
      </div>
    </div>
    <!-- Boutons -->
    <div class="kb-btns">
      <button class="kb-btn-ne" onclick="kbAchat(<?= $beat_id ?>, '<?= esc_js($beat->post_title) ?>', 'non_exclusive', <?= $prix_ne ?>)">
        🎵 NON-EXCLUSIVE
        <?php if ($prix_ne): ?><span class="kb-btn-price"><?= number_format($prix_ne, 0, ',', ' ') ?> F</span><?php endif; ?>
      </button>
      <?php if (!$ex_sold && $prix_ex): ?>
      <button class="kb-btn-ex" onclick="kbAchat(<?= $beat_id ?>, '<?= esc_js($beat->post_title) ?>', 'exclusive', <?= $prix_ex ?>)">
        👑 EXCLUSIVE
        <span class="kb-btn-price"><?= number_format($prix_ex, 0, ',', ' ') ?> F</span>
      </button>
      <?php else: ?>
      <button class="kb-btn-ex" disabled>🔒 VENDUE</button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- ══ SECTION BEAT CUSTOM ══════════════════════════════════ -->
<div class="kb-section">
  <div class="kb-section-title">BEAT <span>CUSTOM</span></div>
  <div class="kb-section-sub">Vous ne trouvez pas ce que vous cherchez ? Commandons un beat sur mesure pour votre projet.</div>
  <div class="kb-custom-form">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div class="kb-fg"><label>Nom / Artiste *</label><input id="cus-nom" placeholder="Votre nom de scène"></div>
      <div class="kb-fg"><label>Téléphone *</label><input id="cus-tel" type="tel" placeholder="+225 07 00 00 00 00"></div>
      <div class="kb-fg"><label>Genre musical *</label>
        <select id="cus-genre">
          <option value="">— Choisir le genre —</option>
          <optgroup label="Gospel / Worship">
            <option>Gospel Urbain</option>
            <option>Afro Gospel</option>
            <option>Trap Gospel</option>
            <option>Worship / Louange</option>
            <option>Gospel Acoustique</option>
            <option>Gospel Contemporain</option>
          </optgroup>
          <optgroup label="Afro / Urban">
            <option>Afrobeats</option>
            <option>Afropop</option>
            <option>Coupé-Décalé</option>
            <option>Afro Drill</option>
            <option>Amapiano</option>
            <option>Dancehall</option>
            <option>Reggae / Reggaeton</option>
          </optgroup>
          <optgroup label="Hip-Hop / Trap">
            <option>Hip-Hop</option>
            <option>Trap</option>
            <option>Drill</option>
            <option>Boom Bap</option>
            <option>Cloud Rap</option>
            <option>Lo-Fi Hip-Hop</option>
          </optgroup>
          <optgroup label="R&amp;B / Soul">
            <option>R&amp;B</option>
            <option>R&amp;B Contemporain</option>
            <option>Neo Soul</option>
            <option>Soul</option>
          </optgroup>
          <optgroup label="Pop / Commercial">
            <option>Pop</option>
            <option>Pop Africaine</option>
            <option>Électro Pop</option>
          </optgroup>
          <optgroup label="Autres">
            <option>Jazz</option>
            <option>Funk</option>
            <option>Zouk</option>
            <option>Variété Française</option>
            <option>Cinéma / Sync</option>
            <option>Autre / Sur mesure</option>
          </optgroup>
        </select>
      </div>
      <div class="kb-fg"><label>BPM souhaité</label><input id="cus-bpm" placeholder="Ex: 90, 120... ou À discuter"></div>
      <div class="kb-fg">
        <label>Gamme / Tonalité</label>
        <select id="cus-gamme">
          <option value="">— Tonalité souhaitée —</option>
          <optgroup label="Majeures">
            <option>Do Majeur (C)</option><option>Ré Majeur (D)</option>
            <option>Mi Majeur (E)</option><option>Fa Majeur (F)</option>
            <option>Sol Majeur (G)</option><option>La Majeur (A)</option>
            <option>Si Majeur (B)</option>
          </optgroup>
          <optgroup label="Mineures">
            <option>Do Mineur (Cm)</option><option>Ré Mineur (Dm)</option>
            <option>Mi Mineur (Em)</option><option>Fa Mineur (Fm)</option>
            <option>Sol Mineur (Gm)</option><option>La Mineur (Am)</option>
            <option>Si Mineur (Bm)</option>
          </optgroup>
          <option value="libre">Pas de préférence / Libre</option>
        </select>
      </div>
    </div>
    <div class="kb-fg"><label>Références artistiques *</label><input id="cus-refs" placeholder="Artistes / chansons de référence"></div>
    <div class="kb-fg"><label>Description du projet</label><textarea id="cus-notes" placeholder="Ambiance souhaitée, paroles prévues, date de sortie..."></textarea></div>
    <div id="cus-err" style="display:none;margin-bottom:12px;padding:10px;background:var(--red-d);border:1px solid rgba(255,71,87,.3);border-radius:8px;font-size:12px;color:#FFB3B8"></div>
    <div id="cus-ok" style="display:none;margin-bottom:12px;padding:12px;background:var(--green-d);border:1px solid var(--green-b, rgba(0,200,122,.3));border-radius:8px;font-size:12px;color:var(--green)">✅ Demande envoyée ! Nous vous contactons dans les 24h.</div>
    <button onclick="kbSubmitCustom()" id="cus-btn" style="background:linear-gradient(135deg,var(--violet),var(--gold));color:#fff;border:none;border-radius:10px;padding:13px 28px;font-family:'Bebas Neue',sans-serif;font-size:17px;letter-spacing:2px;cursor:pointer;transition:all .3s;box-shadow:0 4px 16px rgba(139,63,217,.3)">
      🎹 DEMANDER UN BEAT CUSTOM
    </button>
  </div>
</div>

<!-- ══ TABLE LICENCES ═══════════════════════════════════════ -->
<div class="kb-section">
  <div class="kb-section-title">TYPES DE <span>LICENCES</span></div>
  <div class="kb-section-sub">Toutes nos licences sont conformes à la réglementation BURIDA (Bureau Ivoirien du Droit d'Auteur).</div>
  <div style="overflow-x:auto;background:var(--glass);border:1px solid var(--border);border-radius:14px">
    <table class="kb-table">
      <thead><tr><th>Conditions</th><th class="td-gold">Non-Exclusive</th><th class="td-violet">Exclusive</th></tr></thead>
      <tbody>
        <?php $rows = [
          ['Streams autorisés', "Jusqu'à 500 000", 'Illimités ∞'],
          ['Durée licence', '10 ans', 'Perpétuelle'],
          ['Distribution commerciale', 'Oui (avec crédits)', 'Oui (sans restriction)'],
          ['Beat retiré du marché', 'Non (reste dispo)', 'Oui (exclusivité)'],
          ['Fichiers stems', 'Non inclus', 'Inclus'],
          ['YouTube monétisé', 'Oui', 'Oui'],
          ['Radio / TV', 'Limité', 'Illimité'],
          ['Crédits obligatoires', 'Oui', 'Recommandé'],
          ['Prix indicatif', number_format((int)get_option('kophi_prix_licence_ne_min',30000),0,',',' ').' F CFA+', number_format((int)get_option('kophi_prix_licence_ex_min',350000),0,',',' ').' F CFA+'],
        ];
        foreach ($rows as [$label, $ne, $ex]): ?>
        <tr>
          <td><?= esc_html($label) ?></td>
          <td class="td-gold"><?= esc_html($ne) ?></td>
          <td class="td-violet"><?= esc_html($ex) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ MODAL ACHAT ══════════════════════════════════════════ -->
<div class="kb-overlay" id="kb-overlay">
  <div class="kb-modal">
    <div class="kb-modal-title">
      ACHETER CE BEAT
      <button class="kb-modal-close" onclick="kbCloseModal()">✕</button>
    </div>
    <div class="kb-modal-info" id="kb-modal-info"></div>
    <div class="kb-modal-err" id="kb-modal-err"></div>
    <div class="kb-form-row">
      <div class="kb-fg"><label>Nom / Artiste *</label><input id="kb-nom" placeholder="Votre nom de scène"></div>
      <div class="kb-fg"><label>Téléphone *</label><input id="kb-tel" type="tel" placeholder="+225 07 00 00 00 00"></div>
    </div>
    <div class="kb-fg"><label>Email <span style="font-weight:400;color:var(--text3)">(optionnel)</span></label><input id="kb-email" type="email" placeholder="votre@email.com"></div>
    <div class="kb-fg">
      <label>Mode de paiement</label>
      <select id="kb-pay-mode">
        <option value="cinetpay">💳 Mobile Money (Orange Money, MTN, Wave)</option>
        <option value="whatsapp">💬 WhatsApp (paiement manuel)</option>
      </select>
    </div>
    <button class="kb-btn-pay" id="kb-btn-pay" onclick="kbSubmitAchat()">💳 PAYER MAINTENANT</button>
    <div class="kb-divider">— ou —</div>
    <button class="kb-btn-wa" onclick="kbContactWa()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
      CONTACTER SUR WHATSAPP
    </button>
  </div>
</div>

<!-- Footer du site via wp_footer() ci-dessous -->

<script>
const KB_REST  = '<?= esc_js($rest) ?>';
const KB_NONCE = '<?= esc_js($nonce) ?>';
const KB_WA    = '<?= esc_js($wa_num) ?>';
const KB_PLAYERS = {};
let KB_playing = null;
let KB_modal   = {};

// ── Burger ──────────────────────────────────────────────────
document.getElementById('kb-burger').addEventListener('click', function() {
  const nav = document.getElementById('kb-mob-nav');
  const open = nav.classList.toggle('open');
  this.classList.toggle('open', open);
});

// ── Filtre genres ────────────────────────────────────────────
function kbFilter(genre, btn) {
  document.querySelectorAll('.kb-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#kb-grid .kb-card').forEach(c => {
    c.style.display = (!genre || c.dataset.genre === genre) ? '' : 'none';
  });
}

// ── Lecteur WaveSurfer ───────────────────────────────────────
function kbIconPlay()  { return '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg>'; }
function kbIconPause() { return '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="3" width="4" height="18"/><rect x="14" y="3" width="4" height="18"/></svg>'; }

function kbPlay(id, url) {
  const cover   = document.querySelector('[data-genre]'); // placeholder
  const btn     = document.getElementById('kb-play-' + id);
  const waveDiv = document.getElementById('kb-wave-' + id);

  if (KB_playing === id) {
    KB_PLAYERS[id]?.pause();
    if (btn) btn.innerHTML = kbIconPlay();
    KB_playing = null;
    return;
  }

  if (KB_playing && KB_PLAYERS[KB_playing]) {
    KB_PLAYERS[KB_playing].pause();
    const ob = document.getElementById('kb-play-' + KB_playing);
    if (ob) ob.innerHTML = kbIconPlay();
  }

  if (!url) { alert('Aperçu audio non disponible pour ce beat.'); return; }

  if (!KB_PLAYERS[id] && waveDiv && window.WaveSurfer) {
    const ws = WaveSurfer.create({
      container: waveDiv, waveColor: '#3A3450', progressColor: '#E8B84B',
      cursorColor: '#E8B84B', barWidth: 2, barGap: 2, barRadius: 2, height: 44,
    });
    ws.load(url);
    ws.on('finish', () => {
      if (btn) btn.innerHTML = kbIconPlay();
      KB_playing = null;
    });
    KB_PLAYERS[id] = ws;
    ws.play();
  } else if (KB_PLAYERS[id]) {
    KB_PLAYERS[id].play();
  } else {
    const audio = new Audio(url);
    audio.play().catch(() => {});
    audio.onended = () => { if (btn) btn.innerHTML = kbIconPlay(); KB_playing = null; };
    KB_PLAYERS[id] = { pause: () => audio.pause(), play: () => audio.play() };
  }

  KB_playing = id;
  if (btn) btn.innerHTML = kbIconPause();
}

// ── Modal achat ──────────────────────────────────────────────
function kbAchat(id, titre, licence, prix) {
  KB_modal = { beat_id: id, titre, licence, prix };
  const labels = { 'non_exclusive': 'Non-Exclusive (Lease)', 'exclusive': 'Exclusive (Droits totaux)' };
  const prx    = prix ? Number(prix).toLocaleString('fr-FR') + ' F CFA' : 'Prix à confirmer';
  document.getElementById('kb-modal-info').innerHTML =
    '<strong style="color:#fff;font-family:Bebas Neue,sans-serif;font-size:18px;letter-spacing:1px">' + titre + '</strong><br>' +
    '<span style="color:var(--gold);font-family:Bebas Neue,sans-serif;font-size:22px">' + prx + '</span> ' +
    '<span style="font-size:11px;color:rgba(255,255,255,.5)">' + (labels[licence] || licence) + '</span>';
  document.getElementById('kb-modal-err').style.display = 'none';
  ['kb-nom','kb-tel','kb-email'].forEach(function(i) { var e = document.getElementById(i); if(e) e.value = ''; });
  document.getElementById('kb-btn-pay').innerHTML = '💳 PAYER MAINTENANT';
  document.getElementById('kb-btn-pay').disabled = false;
  document.getElementById('kb-overlay').classList.add('open');
}
function kbCloseModal() { document.getElementById('kb-overlay').classList.remove('open'); }
document.getElementById('kb-overlay').addEventListener('click', function(e) { if(e.target===this) kbCloseModal(); });

function kbContactWa() {
  var prx = KB_modal.prix ? Number(KB_modal.prix).toLocaleString('fr-FR') + ' F CFA' : '';
  var msg = "Bonjour KOPHI'S MUSIC STUDIO !\nJe voudrais acheter le beat «" + KB_modal.titre + "» (" + (KB_modal.licence === 'exclusive' ? 'Exclusive' : 'Non-Exclusive') + ")" + (prx ? " — " + prx : "") + ".";
  window.open('https://wa.me/' + KB_WA + '?text=' + encodeURIComponent(msg), '_blank');
}

async function kbSubmitAchat() {
  var nom   = document.getElementById('kb-nom').value.trim();
  var tel   = document.getElementById('kb-tel').value.trim();
  var email = document.getElementById('kb-email').value.trim();
  var mode  = document.getElementById('kb-pay-mode').value;
  var errEl = document.getElementById('kb-modal-err');
  errEl.style.display = 'none';
  if (!nom || !tel) { errEl.textContent = 'Nom et téléphone obligatoires.'; errEl.style.display = 'block'; return; }

  var btn = document.getElementById('kb-btn-pay');
  btn.disabled = true;

  if (mode === 'whatsapp') {
    btn.innerHTML = '⏳ Ouverture WhatsApp...';
    var msg = "Bonjour KOPHI'S MUSIC STUDIO !\nBeat: «" + KB_modal.titre + "» (" + (KB_modal.licence === 'exclusive' ? 'Exclusive' : 'Non-Exclusive') + ")\nNom: " + nom + " | Tél: " + tel;
    try {
      await fetch(KB_REST + 'beats/achat', {
        method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce':KB_NONCE},
        body: JSON.stringify({ beat_id: KB_modal.beat_id, nom_acheteur: nom, telephone: tel, email: email, licence: KB_modal.licence })
      });
    } catch(e) {}
    kbCloseModal();
    window.open('https://wa.me/' + KB_WA + '?text=' + encodeURIComponent(msg), '_blank');
    return;
  }

  btn.innerHTML = '⏳ Initialisation du paiement...';
  try {
    var r = await fetch(KB_REST + 'payment/init', {
      method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce':KB_NONCE},
      body: JSON.stringify({ beat_id: KB_modal.beat_id, nom: nom, telephone: tel, email: email, licence_type: KB_modal.licence })
    });
    var d = r.ok ? await r.json() : null;
    if (d && d.mode === 'cinetpay' && d.payment_url) {
      kbCloseModal();
      window.location.href = d.payment_url;
    } else if (d && (d.mode === 'manuel' || d.mode === 'manuel_fallback')) {
      kbCloseModal();
      window.open(d.wa_link, '_blank');
    } else {
      errEl.textContent = (d && d.message) || 'Erreur. Utilisez WhatsApp.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '💳 PAYER MAINTENANT';
    }
  } catch(e) {
    errEl.textContent = 'Erreur réseau.';
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = '💳 PAYER MAINTENANT';
  }
}

// ── Beat Custom ──────────────────────────────────────────────
async function kbSubmitCustom() {
  var nom   = document.getElementById('cus-nom').value.trim();
  var tel   = document.getElementById('cus-tel').value.trim();
  var genre = document.getElementById('cus-genre').value;
  var refs  = document.getElementById('cus-refs').value.trim();
  var errEl = document.getElementById('cus-err');
  errEl.style.display = 'none';
  if (!nom || !tel || !genre || !refs) { errEl.textContent = 'Remplissez tous les champs obligatoires.'; errEl.style.display = 'block'; return; }
  var btn = document.getElementById('cus-btn');
  btn.disabled = true; btn.textContent = 'Envoi en cours...';
  var gamme = document.getElementById('cus-gamme')?.value || '';
  var msg = 'BEAT CUSTOM\nGenre: ' + genre + '\nBPM: ' + (document.getElementById('cus-bpm').value || 'À discuter') + (gamme ? '\nTonalité: ' + gamme : '') + '\nRéférences: ' + refs + '\nNotes: ' + document.getElementById('cus-notes').value;
  try {
    var r = await fetch(KB_REST + 'reservations', {
      method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce':KB_NONCE},
      body: JSON.stringify({ nom_artiste: nom, telephone: tel, service: 'beats', message: msg, date_souhaitee: new Date().toISOString().split('T')[0] })
    });
    var d = await r.json();
    if (d.success || d.id) { document.getElementById('cus-ok').style.display = 'block'; btn.style.display = 'none'; }
    else { errEl.textContent = d.message || 'Erreur.'; errEl.style.display = 'block'; btn.disabled = false; btn.textContent = '🎹 DEMANDER UN BEAT CUSTOM'; }
  } catch(e) { errEl.textContent = 'Erreur connexion.'; errEl.style.display = 'block'; btn.disabled = false; btn.textContent = '🎹 DEMANDER UN BEAT CUSTOM'; }
}

// Notification retour paiement
(function() {
  var p = new URLSearchParams(location.search);
  if (p.get('payment') === 'success') {
    var b = document.createElement('div');
    b.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);z-index:9999;background:#00C87A;color:#000;padding:14px 24px;border-radius:12px;font-size:13px;font-weight:700;box-shadow:0 4px 20px rgba(0,200,122,.4)';
    var licUrl = p.get('lic');
    b.innerHTML = '🎉 Paiement confirmé !' + (licUrl ? ' <a href="' + decodeURIComponent(licUrl) + '" target="_blank" style="color:#000;margin-left:8px;text-decoration:underline">📄 Ma licence →</a>' : '');
    document.body.appendChild(b);
    setTimeout(function() { b.remove(); }, 10000);
  }
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
