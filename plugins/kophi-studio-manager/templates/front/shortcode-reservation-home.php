<?php
/**
 * Shortcode [kophi_reservation_home] — Section réservation pour la page accueil
 * Design mode : sombre, premium, avec cards forfaits + formulaire express
 * Usage : [kophi_reservation_home]
 */
if (!defined('ABSPATH')) exit;

function kophi_shortcode_reservation_home($atts) {
    $a = shortcode_atts([
        'title'   => 'RÉSERVEZ VOTRE SESSION',
        'subtitle'=> 'Studio disponible 7j/7 · Abidjan, Côte d\'Ivoire',
    ], $atts);

    $wa = KOPHI_WA_LINK;

    // Tarifs depuis les options (modifiables dans Paramètres / Tarifs & Workflow) —
    // mêmes options que la page /reserver/ et l'admin, donc toujours synchronisés.
    // NB : le 2e élément ('forf') est un identifiant interne utilisé uniquement pour
    // le calcul de durée des créneaux — il n'est jamais affiché et reste inchangé.
    $tarifs = [
        ['voix',   '50000',  4,  'starter',  '🎙', kophi_fmt_fcfa(get_option('kophi_tarif_essentielle_min','50000')).' – '.kophi_fmt_fcfa(get_option('kophi_tarif_essentielle_max','70000')).' F', 'SESSION ESSENTIELLE', '4h · Prise de voix, Mix & Master basique. Idéal pour 1 titre.',  false],
        ['voix',   '65000',  6,  'pro',      '🎚', kophi_fmt_fcfa(get_option('kophi_tarif_pro_min','80000')).' – '.kophi_fmt_fcfa(get_option('kophi_tarif_pro_max','100000')).' F', 'SESSION PRO', '6h · Finition avancée. Produit étendu, 1 ou 2 séances.',         false],
        ['voix',   '90000',  8,  'studio',   '✨', kophi_fmt_fcfa(get_option('kophi_tarif_premium_min','120000')).' – '.kophi_fmt_fcfa(get_option('kophi_tarif_premium_max','150000')).' F', 'SESSION PREMIUM', '8h · Deux sessions, rendu professionnel haut de gamme.',         true],
        ['voix',   '150000', 12, 'premium',  '🏆', kophi_fmt_fcfa(get_option('kophi_tarif_signature_min','200000')).' – '.kophi_fmt_fcfa(get_option('kophi_tarif_signature_max','250000')).' F', 'SESSION SIGNATURE', '12h · Projet complet. 3 séances, finalisation totale.',           false],
        ['faceB',  '10000',  2,  'faceb',    '🎵', kophi_fmt_fcfa(get_option('kophi_tarif_faceb','10000')).' F', 'SESSION FACE B', '1h30 · Vous venez avec votre instrumental, on enregistre.',        false],
    ];

    ob_start();
    ?>
<section class="krh-section" id="kophi-resa-home">

  <!-- Fond animé -->
  <div class="krh-bg">
    <div class="krh-bg-glow krh-glow-1"></div>
    <div class="krh-bg-glow krh-glow-2"></div>
    <div class="krh-bg-grid"></div>
  </div>

  <div class="krh-inner">

    <!-- CÔTÉ GAUCHE : Info + Forfaits -->
    <div class="krh-left">

      <!-- Badge + titre -->
      <div class="krh-left-header">
        <span class="krh-pill">🎙 STUDIO SESSION</span>
        <h2 class="krh-title"><?= esc_html($a['title']) ?></h2>
        <p class="krh-sub"><?= esc_html($a['subtitle']) ?></p>
      </div>

      <!-- Grille forfaits -->
      <div class="krh-forfaits">
        <?php foreach ($tarifs as [$svc,$forf,$dur,$cls,$ico,$prix,$label,$desc,$popular]): ?>
        <div class="krh-forfait <?= $popular ? 'krh-popular' : '' ?>"
             onclick="krhSelectForfait('<?= $svc ?>','<?= $forf ?>','<?= esc_js($label . ' — ' . $prix) ?>', this)">
          <?php if ($popular): ?>
          <span class="krh-popular-badge">⭐ POPULAIRE</span>
          <?php endif; ?>
          <div class="krh-forfait-top">
            <span class="krh-forfait-ico"><?= $ico ?></span>
            <div class="krh-forfait-info">
              <span class="krh-forfait-label"><?= esc_html($label) ?></span>
              <span class="krh-forfait-desc"><?= esc_html(mb_strimwidth($desc, 0, 55, '…')) ?></span>
            </div>
          </div>
          <span class="krh-forfait-price"><?= esc_html($prix) ?></span>
          <span class="krh-check-icon">✓</span>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Avantages -->
      <div class="krh-avantages">
        <?php foreach ([
          ['🎛', 'Ingénieur son professionnel'],
          ['🎧', 'Écoute finale immédiate'],
          ['📤', 'Fichiers livrés en 24h'],
          ['🌍', 'Urban Gospel · Tous genres'],
        ] as [$ico, $txt]): ?>
        <div class="krh-av-item">
          <span class="krh-av-ico"><?= $ico ?></span>
          <span class="krh-av-txt"><?= esc_html($txt) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- CÔTÉ DROIT : Formulaire express -->
    <div class="krh-right">
      <div class="krh-form-card">

        <!-- Header form -->
        <div class="krh-form-header">
          <h3 class="krh-form-title">Réserver en 2 minutes</h3>
          <p class="krh-form-sub">Confirmation par WhatsApp sous 24h</p>
        </div>

        <!-- Forfait sélectionné -->
        <div class="krh-selected-forfait" id="krh-selected-forfait" style="display:none">
          <div class="krh-selected-label">Forfait sélectionné</div>
          <div class="krh-selected-val" id="krh-selected-val">—</div>
        </div>

        <!-- Formulaire -->
        <form class="krh-form" id="krh-form" onsubmit="krhSubmit(event)">

          <div class="krh-form-row">
            <div class="krh-fg">
              <label>Nom / Artiste *</label>
              <input type="text" id="krh-nom" placeholder="Votre nom ou nom de scène" required>
            </div>
            <div class="krh-fg">
              <label>Téléphone *</label>
              <input type="tel" id="krh-tel" placeholder="+225 07 00 00 00 00" required>
            </div>
          </div>

          <div class="krh-fg">
            <label>Service</label>
            <select id="krh-service">
              <option value="voix">🎙 Voix / Mix / Master</option>
              <option value="faceB">🎵 Face B — 10 000 F</option>
              <option value="beats">🎹 Beat Custom</option>
              <option value="consultation">💬 Consultation / RDV</option>
            </select>
          </div>

          <div class="krh-form-row">
            <div class="krh-fg">
              <label>Date souhaitée *</label>
              <input type="date" id="krh-date" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="krh-fg">
              <label>Heure préférée</label>
              <select id="krh-heure">
                <option value="">— Flexible —</option>
                <?php for ($h=9; $h<=20; $h++): ?>
                <option value="<?= sprintf('%02d:00', $h) ?>"><?= sprintf('%dh00', $h) ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <div class="krh-fg">
            <label>Message (optionnel)</label>
            <textarea id="krh-message" placeholder="Genre musical, titre, références, notes…" rows="2"></textarea>
          </div>

          <!-- Erreur -->
          <div id="krh-error" class="krh-error" style="display:none"></div>
          <div id="krh-success" class="krh-success" style="display:none"></div>

          <!-- Boutons submit -->
          <div class="krh-submit-group">
            <button type="submit" class="krh-btn-submit" id="krh-btn-submit">
              <span id="krh-btn-txt">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                Envoyer ma demande
              </span>
            </button>
            <a href="<?= esc_url($wa) ?>" class="krh-btn-wa" target="_blank">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
              WhatsApp direct
            </a>
          </div>

          <p class="krh-form-note">
            🔒 Vos données sont confidentielles · Réponse sous 24h garantie
          </p>

        </form>
      </div>

      <!-- Badge disponibilité -->
      <div class="krh-dispo-badge">
        <span class="krh-dispo-dot"></span>
        Studio disponible aujourd'hui
      </div>

    </div>
  </div>
</section>

<style>
/* ── KOPHI Reservation Home Shortcode ──────────────────────── */
#kophi-resa-home {
  position: relative;
  padding: 100px 24px;
  background: #0D0D0D;
  overflow: hidden;
  font-family: 'Inter', sans-serif;
  box-sizing: border-box;
  width: 100%;
  max-width: 100%;
}
#kophi-resa-home *,
#kophi-resa-home *::before,
#kophi-resa-home *::after {
  box-sizing: border-box;
}

/* Fond */
.krh-bg { position: absolute; inset: 0; pointer-events: none; }
.krh-bg-glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
}
.krh-glow-1 {
  width: 500px; height: 500px;
  background: rgba(139,63,217,.2);
  top: -100px; left: -100px;
}
.krh-glow-2 {
  width: 400px; height: 400px;
  background: rgba(232,184,75,.1);
  bottom: -100px; right: -100px;
}
.krh-bg-grid {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 60px 60px;
}

/* Layout */
.krh-inner {
  max-width: 1200px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 64px;
  align-items: start;
  position: relative;
}
@media (max-width: 900px) {
  .krh-inner { grid-template-columns: 1fr; gap: 48px; }
}

/* Gauche */
.krh-left-header { margin-bottom: 32px; }
.krh-pill {
  display: inline-block;
  padding: 5px 16px;
  background: rgba(139,63,217,.15);
  border: 1px solid rgba(139,63,217,.35);
  border-radius: 100px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 2px;
  color: #A855F7;
  text-transform: uppercase;
  margin-bottom: 18px;
}
.krh-title {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: clamp(32px, 5vw, 56px) !important;
  letter-spacing: 2px;
  color: #F0ECF8 !important;
  margin: 0 0 12px !important;
  line-height: 1 !important;
}
.krh-sub {
  font-size: 13px;
  color: rgba(240,236,248,.45);
  margin: 0;
}

/* Forfaits */
.krh-forfaits {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 32px;
}
.krh-forfait {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: rgba(255,255,255,.04);
  border: 1.5px solid rgba(255,255,255,.08);
  border-radius: 14px;
  cursor: pointer;
  transition: all .2s ease;
  user-select: none;
}
.krh-forfait:hover {
  background: rgba(232,184,75,.07);
  border-color: rgba(232,184,75,.3);
  transform: translateX(4px);
}
.krh-forfait.krh-popular {
  border-color: rgba(232,184,75,.4);
  background: rgba(232,184,75,.06);
}
.krh-forfait.selected {
  border-color: #E8B84B !important;
  background: rgba(232,184,75,.12) !important;
}
.krh-popular-badge {
  position: absolute;
  top: -8px; right: 12px;
  background: linear-gradient(135deg, #E8B84B, #F5CC6A);
  color: #0D0D0D;
  font-size: 8px;
  font-weight: 800;
  letter-spacing: 1px;
  padding: 2px 8px;
  border-radius: 4px;
}
.krh-forfait-top {
  display: flex; align-items: center; gap: 10px;
  flex: 1; min-width: 0;
}
.krh-forfait-ico { font-size: 20px; flex-shrink: 0; }
.krh-forfait-info {
  display: flex; flex-direction: column; min-width: 0;
}
.krh-forfait-label {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 15px;
  letter-spacing: 1.5px;
  color: #F0ECF8;
  white-space: nowrap;
}
.krh-forfait-desc {
  font-size: 9px;
  color: rgba(240,236,248,.4);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.krh-forfait-price {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 18px;
  color: #E8B84B;
  white-space: nowrap;
  flex-shrink: 0;
}
.krh-check-icon {
  width: 20px; height: 20px;
  border-radius: 50%;
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 10px;
  color: transparent;
  flex-shrink: 0;
  transition: all .2s;
}
.krh-forfait.selected .krh-check-icon {
  background: #E8B84B;
  border-color: #E8B84B;
  color: #0D0D0D;
  font-weight: 900;
}

/* Avantages */
.krh-avantages {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.krh-av-item {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 12px;
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.06);
  border-radius: 8px;
}
.krh-av-ico { font-size: 14px; }
.krh-av-txt { font-size: 10px; color: rgba(240,236,248,.55); }

/* Droite */
.krh-right { position: sticky; top: 80px; width: 100%; min-width: 0; }
@media (max-width: 900px) { .krh-right { position: static; } }
.krh-form-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 24px;
  overflow: hidden;
  backdrop-filter: blur(12px);
}

.krh-form-header {
  padding: 28px 28px 0;
  border-bottom: 1px solid rgba(255,255,255,.07);
  padding-bottom: 20px;
  margin-bottom: 0;
  background: linear-gradient(135deg, rgba(139,63,217,.15), rgba(232,184,75,.06));
}
.krh-form-title {
  font-family: 'Bebas Neue', sans-serif !important;
  font-size: 24px !important;
  letter-spacing: 2px;
  color: #F0ECF8 !important;
  margin: 0 0 4px !important;
}
.krh-form-sub {
  font-size: 11px;
  color: rgba(240,236,248,.45);
  margin: 0;
}

/* Forfait sélectionné */
.krh-selected-forfait {
  margin: 16px 24px 0;
  padding: 10px 14px;
  background: rgba(232,184,75,.1);
  border: 1px solid rgba(232,184,75,.3);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: space-between;
}
.krh-selected-label {
  font-size: 9px;
  color: rgba(240,236,248,.5);
  letter-spacing: 1px;
  text-transform: uppercase;
}
.krh-selected-val {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 14px;
  color: #E8B84B;
  letter-spacing: 1px;
}

/* Formulaire */
.krh-form { padding: 20px 24px 24px; }
.krh-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 12px;
}
@media (max-width: 480px) { .krh-form-row { grid-template-columns: 1fr; } }
.krh-fg { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.krh-fg:last-of-type { margin-bottom: 0; }
.krh-form-row .krh-fg { margin-bottom: 0; }
.krh-fg label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: rgba(240,236,248,.5);
}
.krh-fg input,
.krh-fg select,
.krh-fg textarea {
  background: rgba(255,255,255,.06) !important;
  border: 1.5px solid rgba(255,255,255,.1) !important;
  border-radius: 10px !important;
  padding: 11px 14px !important;
  color: #F0ECF8 !important;
  font-size: 13px !important;
  font-family: 'Inter', sans-serif !important;
  outline: none !important;
  transition: border-color .2s !important;
  width: 100% !important;
  box-sizing: border-box !important;
}
.krh-fg input:focus,
.krh-fg select:focus,
.krh-fg textarea:focus {
  border-color: rgba(232,184,75,.6) !important;
  background: rgba(232,184,75,.05) !important;
}
.krh-fg input::placeholder,
.krh-fg textarea::placeholder { color: rgba(240,236,248,.25) !important; }
.krh-fg select option { background: #1a0a30; color: #F0ECF8; }
.krh-fg textarea { resize: vertical; min-height: 64px; }

/* Erreur / Succès */
.krh-error {
  padding: 10px 14px;
  background: rgba(255,71,87,.1);
  border: 1px solid rgba(255,71,87,.3);
  border-radius: 8px;
  font-size: 11px;
  color: #FF4757;
  margin-bottom: 12px;
}
.krh-success {
  padding: 10px 14px;
  background: rgba(0,200,122,.1);
  border: 1px solid rgba(0,200,122,.3);
  border-radius: 8px;
  font-size: 11px;
  color: #00C87A;
  margin-bottom: 12px;
}

/* Boutons */
.krh-submit-group {
  display: flex; gap: 10px; margin-top: 16px;
}
.krh-btn-submit {
  flex: 1;
  padding: 13px 20px;
  background: linear-gradient(135deg, #8B3FD9, #E8B84B);
  border: none;
  border-radius: 12px;
  color: #fff !important;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 1px;
  cursor: pointer;
  transition: opacity .2s, transform .2s;
  display: flex; align-items: center; justify-content: center; gap: 7px;
}
.krh-btn-submit:hover:not(:disabled) { opacity: .88; transform: translateY(-2px); }
.krh-btn-submit:disabled { opacity: .5; cursor: not-allowed; }

.krh-btn-wa {
  padding: 13px 18px;
  background: #25D366;
  border-radius: 12px;
  color: #fff !important;
  text-decoration: none !important;
  font-size: 12px;
  font-weight: 700;
  display: flex; align-items: center; gap: 6px;
  transition: background .2s, transform .2s;
  white-space: nowrap;
  flex-shrink: 0;
}
.krh-btn-wa:hover { background: #1ebe5c; transform: translateY(-2px); }

.krh-form-note {
  text-align: center;
  font-size: 9px;
  color: rgba(240,236,248,.3);
  margin: 12px 0 0;
}

/* Badge dispo */
.krh-dispo-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
  padding: 8px 16px;
  background: rgba(0,200,122,.08);
  border: 1px solid rgba(0,200,122,.2);
  border-radius: 100px;
  font-size: 11px;
  color: rgba(240,236,248,.6);
}
.krh-dispo-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: #00C87A;
  box-shadow: 0 0 6px #00C87A;
  animation: krhPulse 2s ease-in-out infinite;
}
@keyframes krhPulse {
  0%,100% { opacity: 1; } 50% { opacity: .4; }
}

/* Animations entrée */
.krh-left { animation: krhSlideLeft .6s ease both; }
.krh-right { animation: krhSlideRight .6s .15s ease both; }
@keyframes krhSlideLeft {
  from { opacity: 0; transform: translateX(-24px); }
  to   { opacity: 1; transform: translateX(0); }
}
@keyframes krhSlideRight {
  from { opacity: 0; transform: translateX(24px); }
  to   { opacity: 1; transform: translateX(0); }
}
/* ── MOBILE ──────────────────────────────────────────────── */
@media (max-width: 900px) {
  #kophi-resa-home { padding: 64px 16px; }
  .krh-inner { grid-template-columns: 1fr; gap: 40px; }
  .krh-title { font-size: clamp(28px, 7vw, 44px) !important; }
  .krh-avantages { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 600px) {
  #kophi-resa-home { padding: 48px 12px; }

  /* Centrage général */
  .krh-left-header { text-align: center; }
  .krh-pill { display: block; text-align: center; }

  /* Forfaits */
  .krh-forfait {
    padding: 12px 14px;
    gap: 10px;
  }
  .krh-forfait-label { font-size: 13px; }
  .krh-forfait-desc  { font-size: 8px; }
  .krh-forfait-price { font-size: 15px; }
  .krh-popular-badge { font-size: 7px; padding: 2px 6px; }

  /* Avantages : 1 colonne sur petit mobile */
  .krh-avantages { grid-template-columns: 1fr; gap: 8px; }
  .krh-av-item { padding: 8px 12px; }

  /* Formulaire card : pas de débordement */
  .krh-form-card {
    border-radius: 16px;
    width: 100%;
    box-sizing: border-box;
  }
  .krh-form-header { padding: 20px 16px 16px; }
  .krh-form-title  { font-size: 20px !important; }
  .krh-form        { padding: 14px 16px 20px; }

  /* Forfait sélectionné */
  .krh-selected-forfait { margin: 12px 14px 0; }

  /* Champs du formulaire */
  .krh-form-row { grid-template-columns: 1fr; gap: 10px; }
  .krh-fg input,
  .krh-fg select,
  .krh-fg textarea { padding: 10px 12px !important; font-size: 14px !important; }

  /* Boutons */
  .krh-submit-group { flex-direction: column; }
  .krh-btn-submit,
  .krh-btn-wa {
    width: 100%;
    justify-content: center;
    padding: 13px 16px;
    font-size: 13px;
  }
  .krh-btn-wa { flex-shrink: 0; }

  /* Badge dispo */
  .krh-dispo-badge { width: 100%; justify-content: center; box-sizing: border-box; }
}

@media (max-width: 380px) {
  #kophi-resa-home { padding: 40px 10px; }
  .krh-forfait-price { font-size: 13px; }
  .krh-forfait-ico   { font-size: 16px; }
}
</style>

<script>
(function() {
  var _krhForfait = null;

  window.krhSelectForfait = function(svc, forf, label, el) {
    // Déselectionner tout
    document.querySelectorAll('#kophi-resa-home .krh-forfait').forEach(function(f) {
      f.classList.remove('selected');
    });
    // Sélectionner celui-ci
    el.classList.add('selected');
    _krhForfait = { svc: svc, forf: forf };

    // Mettre à jour service + afficher forfait sélectionné
    var svcEl = document.getElementById('krh-service');
    if (svcEl) {
      for (var i = 0; i < svcEl.options.length; i++) {
        if (svcEl.options[i].value === svc) { svcEl.selectedIndex = i; break; }
      }
    }
    var selEl  = document.getElementById('krh-selected-forfait');
    var selVal = document.getElementById('krh-selected-val');
    if (selEl)  selEl.style.display = '';
    if (selVal) selVal.textContent  = label;
  };

  window.krhSubmit = function(e) {
    e.preventDefault();
    var nom     = (document.getElementById('krh-nom')?.value     || '').trim();
    var tel     = (document.getElementById('krh-tel')?.value     || '').trim();
    var service = document.getElementById('krh-service')?.value  || 'voix';
    var date    = document.getElementById('krh-date')?.value     || '';
    var heure   = document.getElementById('krh-heure')?.value    || '';
    var message = document.getElementById('krh-message')?.value  || '';
    var forfait = _krhForfait ? _krhForfait.forf : '50000';
    var errEl   = document.getElementById('krh-error');
    var sucEl   = document.getElementById('krh-success');
    var btn     = document.getElementById('krh-btn-submit');

    if (errEl) errEl.style.display = 'none';
    if (sucEl) sucEl.style.display = 'none';

    if (!nom || !tel || !date) {
      if (errEl) { errEl.textContent = 'Nom, téléphone et date sont obligatoires.'; errEl.style.display = ''; }
      return;
    }

    if (btn) { btn.disabled = true; document.getElementById('krh-btn-txt').innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:krhSpin .6s linear infinite"></span> Envoi...'; }

    var ajaxUrl = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>; // l'ancien repli « /wp-admin/… » cassait sur un site en sous-dossier

    var params = new URLSearchParams();
    params.append('action', 'kophi_resa_home_submit');
    params.append('nom', nom);
    params.append('tel', tel);
    params.append('service', service);
    params.append('forfait', forfait);
    params.append('date', date);
    params.append('heure', heure);
    params.append('message', message);
    params.append('nonce', '<?= wp_create_nonce("kophi_resa_home") ?>');

    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (btn) {
        btn.disabled = false;
        document.getElementById('krh-btn-txt').innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg> Envoyer ma demande';
      }
      if (data && data.success) {
        if (sucEl) { sucEl.textContent = '✅ Demande envoyée ! Nous vous contactons sous 24h via WhatsApp.'; sucEl.style.display = ''; }
        document.getElementById('krh-form').reset();
        document.querySelectorAll('#kophi-resa-home .krh-forfait').forEach(function(f) { f.classList.remove('selected'); });
        var selEl = document.getElementById('krh-selected-forfait');
        if (selEl) selEl.style.display = 'none';
        _krhForfait = null;
      } else {
        var msg = (data && data.data && data.data.message) ? data.data.message : 'Erreur lors de l envoi. Contactez-nous sur WhatsApp.';
        if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
      }
    })
    .catch(function() {
      if (btn) { btn.disabled = false; }
      if (errEl) { errEl.textContent = 'Erreur réseau. Utilisez le bouton WhatsApp.'; errEl.style.display = ''; }
    });
  };
})();
</script>
<style>
@keyframes krhSpin { to { transform: rotate(360deg); } }
</style>
<?php
    return ob_get_clean();
}
add_shortcode('kophi_reservation_home', 'kophi_shortcode_reservation_home');
