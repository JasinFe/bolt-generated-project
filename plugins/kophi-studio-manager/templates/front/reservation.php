<?php
if (!defined('ABSPATH')) exit;
$wa_num  = preg_replace('/[^0-9]/', '', get_option('kophi_whatsapp', '2250503404389'));
$wa_link = 'https://wa.me/' . $wa_num;
$rest    = rest_url('kophi/v1/');
$nonce   = wp_create_nonce('wp_rest');
?>
<div id="kres" class="kophi-front-wrap">

<!-- ── STEPPER ──────────────────────────────────────────────── -->
<div class="kres-stepper">
  <div class="kres-step active" id="kres-s1"><span class="kres-snum">1</span><span class="kres-slbl">Service</span></div>
  <div class="kres-line" id="kres-l1"></div>
  <div class="kres-step" id="kres-s2"><span class="kres-snum">2</span><span class="kres-slbl">Date & Heure</span></div>
  <div class="kres-line" id="kres-l2"></div>
  <div class="kres-step" id="kres-s3"><span class="kres-snum">3</span><span class="kres-slbl">Coordonnées</span></div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     PANEL 1 — SERVICE
══════════════════════════════════════════════════════════════ -->
<div class="kres-panel active" id="kres-p1">
  <div class="kres-inner">
    <div class="kres-ph">🎙 Choisissez votre service</div>

    <!-- Grille 3 colonnes -->
    <div class="kres-grid">
      <?php
      // Fourchette + prix de référence lus depuis les mêmes options que la page
      // Tarifs & Workflow (kophi_tarif_*) — restent synchronisés partout.
      // NB : le 2e élément de chaque ligne ('forf') est un identifiant interne
      // utilisé uniquement pour calculer la durée du créneau (kophi_get_duree) —
      // il n'est jamais affiché et ne doit pas être modifié ici.
      $ess_min = get_option('kophi_tarif_essentielle_min','50000');
      $ess_max = get_option('kophi_tarif_essentielle_max','70000');
      $pro_min = get_option('kophi_tarif_pro_min','80000');
      $pro_max = get_option('kophi_tarif_pro_max','100000');
      $prm_min = get_option('kophi_tarif_premium_min','120000');
      $prm_max = get_option('kophi_tarif_premium_max','150000');
      $sig_min = get_option('kophi_tarif_signature_min','200000');
      $sig_max = get_option('kophi_tarif_signature_max','250000');
      $faceb   = get_option('kophi_tarif_faceb','10000');

      // Schéma commun à toutes les lignes : [svc, forf, dur, ico, titre, prix, time, desc]
      $services = [
        ['voix','50000',4,   '🎙','SESSION ESSENTIELLE', kophi_fmt_fcfa($ess_min).' – '.kophi_fmt_fcfa($ess_max).' F','4h · 1 ou 2 séances de 2h',  'Prise de voix, Mix & Master'],
        ['voix','65000',6,   '🎙','SESSION PRO',          kophi_fmt_fcfa($pro_min).' – '.kophi_fmt_fcfa($pro_max).' F','6h · 1 ou 2 séances de 3h', 'Produit étendu, finitions avancées'],
        ['voix','90000',8,   '🎚','SESSION PREMIUM',      kophi_fmt_fcfa($prm_min).' – '.kophi_fmt_fcfa($prm_max).' F','8h · 2 séances de 4h',     'Deux sessions, rendu premium'],
        ['voix','150000',12, '✨','SESSION SIGNATURE',    kophi_fmt_fcfa($sig_min).' – '.kophi_fmt_fcfa($sig_max).' F','12h · 3 séances de 4h',    'Projet complet haut de gamme'],
        ['faceB','10000',1.5,'🎵','SESSION FACE B',       kophi_fmt_fcfa($faceb).' F CFA','1h30 · Face B',                                        'Vous venez avec votre beat'],
        ['beats','',4,       '🎹','BEAT CUSTOM',          '30 000 – 100 000 F', '',                       'Décrivez votre style & références'],
        ['consultation','',1,'💬','PRIX À DISCUTER',      'RDV · Consultation', '',                       'Venez au studio pour un devis'],
        ['achat_beat','',0,  '🛒','ACHETER UN BEAT',      '30 000 – 100 000 F', '',                       'Écoutez & achetez nos instrus'],
      ];

      foreach ($services as [$svc,$forf,$dur,$ico,$titre,$prix,$time,$desc]):
        $is_beat_link = $svc === 'achat_beat';
        $onclick = $is_beat_link
          ? "onclick=\"window.location.href='".esc_js(home_url('/beats'))."'\""
          : "onclick=\"kresSelect(this,'$svc','$forf',$dur)\"";
      ?>
      <div class="kres-card <?= $is_beat_link ? 'kres-card-gold' : '' ?>" <?= $onclick ?>>
        <div class="kres-card-check">✓</div>
        <div class="kres-card-ico"><?= $ico ?></div>
        <div class="kres-card-title"><?= esc_html($titre) ?></div>
        <div class="kres-card-prix"><?= esc_html($prix) ?></div>
        <?php if ($time): ?><div class="kres-card-time"><?= esc_html($time) ?></div><?php endif; ?>
        <div class="kres-card-desc"><?= esc_html($desc) ?></div>
        <?php if ($is_beat_link): ?>
        <div class="kres-card-arrow">Voir les beats →</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Lien Label -->
    <div class="kres-label-link">
      <a href="https://kophismusic.com/" target="_blank">🎵 Visiter le Label KOPHI'S MUSIC STUDIO →</a>
    </div>

    <div class="kres-actions kres-actions-right">
      <button class="kres-btn-next" id="kres-btn-next1" disabled onclick="kresGo(2)">
        CHOISIR UNE DATE <span>→</span>
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     PANEL 2 — CALENDRIER & CRÉNEAUX
══════════════════════════════════════════════════════════════ -->
<div class="kres-panel" id="kres-p2">
  <div class="kres-inner">
    <div class="kres-ph">📅 Choisissez une date disponible</div>

    <!-- Légende -->
    <div class="kres-legend">
      <span><span class="kres-dot" style="background:#00C87A"></span>Disponible</span>
      <span><span class="kres-dot" style="background:#FF8C42"></span>Partiel</span>
      <span><span class="kres-dot" style="background:#8B3FD9"></span>Réservé</span>
      <span><span class="kres-dot" style="background:#FF4757"></span>Complet / Fermé</span>
    </div>

    <!-- Calendrier -->
    <div class="kres-cal">
      <div class="kres-cal-nav">
        <button onclick="kresCalPrev()" class="kres-icon-btn">← Préc.</button>
        <span class="kres-cal-title" id="kres-cal-title">Chargement...</span>
        <button onclick="kresCalNext()" class="kres-icon-btn">Suiv. →</button>
      </div>
      <div class="kres-cal-dow">
        <b>Lun</b><b>Mar</b><b>Mer</b><b>Jeu</b><b>Ven</b><b>Sam</b><b>Dim</b>
      </div>
      <div class="kres-cal-grid" id="kres-cal-grid">
        <div class="kres-loading">Chargement du calendrier...</div>
      </div>
    </div>

    <!-- Créneaux -->
    <div id="kres-creneaux-wrap" style="display:none">
      <div class="kres-ph2" id="kres-creneaux-title">Créneaux disponibles</div>
      <div class="kres-creneaux-grid" id="kres-creneaux-grid"></div>
    </div>

    <!-- Msg consultation -->
    <div id="kres-no-hour" style="display:none" class="kres-info-box">
      ℹ️ Pour ce service, l'heure sera confirmée avec vous après validation de votre demande.
    </div>

    <div class="kres-actions">
      <button class="kres-btn-back" onclick="kresGo(1)">← Retour</button>
      <button class="kres-btn-next" id="kres-btn-next2" disabled onclick="kresGo(3)">
        Mes coordonnées →
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     PANEL 3 — COORDONNÉES
══════════════════════════════════════════════════════════════ -->
<div class="kres-panel" id="kres-p3">
  <div class="kres-inner">
    <div class="kres-ph">✍️ Vos coordonnées</div>

    <!-- Récapitulatif -->
    <div class="kres-recap">
      <div class="kres-recap-title">Récapitulatif de votre réservation</div>
      <div class="kres-recap-row"><span>Service</span><span id="rs-svc">—</span></div>
      <div class="kres-recap-row"><span>Date</span><span id="rs-date" class="kres-gold">—</span></div>
      <div class="kres-recap-row"><span>Heure</span><span id="rs-heure">—</span></div>
      <div class="kres-recap-row"><span>Durée</span><span id="rs-duree">—</span></div>
      <div class="kres-recap-row kres-recap-dispo">
        <span>Disponibilité</span>
        <span id="rs-dispo" class="kres-checking">⏳ Vérification...</span>
      </div>
    </div>

    <!-- Alerte créneau occupé -->
    <div id="kres-slot-err" class="kres-alert-err" style="display:none">
      ⚠️ Ce créneau vient d'être réservé. <button onclick="kresGo(2)">← Choisir un autre horaire</button>
    </div>

    <!-- Erreur formulaire -->
    <div id="kres-form-err" class="kres-alert-err" style="display:none"></div>

    <!-- Formulaire -->
    <div class="kres-form">
      <div class="kres-form-row">
        <div class="kres-fg">
          <label>Nom / Nom d'artiste <span class="kres-req">*</span></label>
          <input id="kres-nom" type="text" placeholder="Votre nom ou nom de scène">
        </div>
        <div class="kres-fg">
          <label>Téléphone <span class="kres-req">*</span></label>
          <input id="kres-tel" type="tel" placeholder="+225 07 00 00 00 00">
        </div>
      </div>
      <div class="kres-fg">
        <label>Email <span style="color:rgba(255,255,255,.35);font-size:9px">(optionnel)</span></label>
        <input id="kres-email" type="email" placeholder="votre@email.com">
      </div>
      <div class="kres-fg" id="kres-beat-fg" style="display:none">
        <label>Brief beat <span class="kres-req">*</span></label>
        <textarea id="kres-beat" placeholder="Genre (Gospel, Afrobeats...), tempo, ambiance, références artistiques..."></textarea>
      </div>
      <div class="kres-fg">
        <label>Message / Détails</label>
        <textarea id="kres-msg" placeholder="Genre musical, questions, détails supplémentaires..."></textarea>
      </div>
    </div>

    <div class="kres-actions">
      <button class="kres-btn-back" onclick="kresGo(2)">← Modifier la date</button>
      <button class="kres-btn-submit" id="kres-btn-submit" onclick="kresSubmit()">
        ✉️ ENVOYER MA DEMANDE
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SUCCÈS
══════════════════════════════════════════════════════════════ -->
<div class="kres-success" id="kres-success" style="display:none">
  <div class="kres-success-ico">🎙</div>
  <h2>DEMANDE ENVOYÉE !</h2>
  <p>Notre équipe vous contacte dans les <strong>24 heures</strong> pour confirmer votre créneau.</p>
  <p style="font-size:12px;opacity:.6;margin-top:8px">WhatsApp : +<?= esc_html($wa_num) ?></p>
  <a href="<?= esc_url($wa_link) ?>?text=<?= urlencode("Bonjour, j'ai soumis une demande de réservation KOPHI'S MUSIC STUDIO.") ?>"
     target="_blank" class="kres-wa-btn">💬 Nous contacter sur WhatsApp</a>
</div>

</div><!-- /#kres -->

<!-- ══ CSS INLINE ══════════════════════════════════════════════ -->
<style>
#kres { font-family: 'Inter', -apple-system, sans-serif; }

/* Stepper */
.kres-stepper { display:flex; align-items:center; justify-content:center; padding:28px 20px 20px; gap:0; }
.kres-step { display:flex; align-items:center; gap:8px; }
.kres-snum { width:34px; height:34px; border-radius:50%; border:2px solid rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-family:'Bebas Neue',sans-serif; font-size:16px; color:rgba(255,255,255,.35); transition:all .35s; background:rgba(0,0,0,.3); flex-shrink:0; }
.kres-slbl { font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:rgba(255,255,255,.35); transition:color .35s; white-space:nowrap; }
.kres-line { width:50px; height:1.5px; background:rgba(255,255,255,.1); flex-shrink:0; margin:0 10px; position:relative; top:-1px; transition:background .35s; }
.kres-line.done { background:#00C87A; }
.kres-step.active .kres-snum { border-color:#E8B84B; color:#E8B84B; background:rgba(232,184,75,.12); box-shadow:0 0 16px rgba(232,184,75,.3); }
.kres-step.active .kres-slbl { color:#E8B84B; }
.kres-step.done .kres-snum { border-color:#00C87A; background:#00C87A; color:#000; }
.kres-step.done .kres-slbl { color:#00C87A; }

/* Panel */
.kres-panel { display:none; animation:kresIn .3s ease; padding-bottom:50px; }
.kres-panel.active { display:block; }
@keyframes kresIn { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:none} }
.kres-inner { max-width:960px; margin:0 auto; padding:0 clamp(14px,4vw,40px); }
.kres-ph { font-family:'Bebas Neue',sans-serif; font-size:13px; letter-spacing:3.5px; text-transform:uppercase; color:rgba(255,255,255,.55); display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid rgba(255,255,255,.08); }
.kres-ph::before { content:''; width:20px; height:1.5px; background:#E8B84B; flex-shrink:0; }
.kres-ph2 { font-size:10px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#E8B84B; margin:18px 0 10px; }

/* Cards services — grille 4 colonnes */
.kres-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
.kres-card { background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.09); border-radius:14px; padding:18px 14px; cursor:pointer; transition:all .22s; position:relative; overflow:hidden; }
.kres-card::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,transparent,rgba(232,184,75,.03)); opacity:0; transition:.3s; }
.kres-card:hover { border-color:rgba(232,184,75,.4); transform:translateY(-3px); background:rgba(232,184,75,.06); }
.kres-card:hover::before { opacity:1; }
.kres-card.selected { border-color:#E8B84B; background:rgba(232,184,75,.1); box-shadow:0 0 0 2px rgba(232,184,75,.2),0 10px 30px rgba(0,0,0,.3); }
.kres-card-check { position:absolute; top:8px; right:8px; width:18px; height:18px; border-radius:50%; background:#E8B84B; color:#000; font-size:10px; font-weight:900; display:none; align-items:center; justify-content:center; }
.kres-card.selected .kres-card-check { display:flex; }
.kres-card-gold { background:rgba(232,184,75,.07); border-color:rgba(232,184,75,.2); }
.kres-card-gold:hover { background:rgba(232,184,75,.12); }
.kres-card-ico { font-size:24px; margin-bottom:10px; }
.kres-card-title { font-size:10px; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:#fff; margin-bottom:6px; }
.kres-card-prix { font-family:'Bebas Neue',sans-serif; font-size:17px; color:#E8B84B; line-height:1; margin-bottom:4px; }
.kres-card-time { font-size:10px; font-weight:600; color:rgba(255,255,255,.65); margin-bottom:5px; }
.kres-card-desc { font-size:10px; color:rgba(255,255,255,.38); line-height:1.4; }
.kres-card-arrow { margin-top:8px; font-size:10px; font-weight:700; color:#E8B84B; opacity:.7; }

/* Lien label */
.kres-label-link { text-align:center; margin:6px 0 18px; }
.kres-label-link a { font-size:11px; color:rgba(232,184,75,.5); text-decoration:none; letter-spacing:.5px; transition:color .2s; }
.kres-label-link a:hover { color:#E8B84B; }

/* Légende calendrier */
.kres-legend { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:14px; }
.kres-legend span { display:flex; align-items:center; gap:5px; font-size:10px; font-weight:600; color:rgba(255,255,255,.55); }
.kres-dot { width:9px; height:9px; border-radius:3px; flex-shrink:0; }

/* Calendrier */
.kres-cal { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:16px; overflow:hidden; margin-bottom:16px; }
.kres-cal-nav { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; background:rgba(0,0,0,.25); border-bottom:1px solid rgba(255,255,255,.07); }
.kres-cal-title { font-family:'Bebas Neue',sans-serif; font-size:20px; letter-spacing:2px; color:#fff; }
.kres-icon-btn { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); border-radius:8px; color:rgba(255,255,255,.7); padding:7px 14px; font-size:11px; font-weight:700; cursor:pointer; transition:all .2s; font-family:'Inter',sans-serif; }
.kres-icon-btn:hover { border-color:rgba(232,184,75,.4); color:#E8B84B; background:rgba(232,184,75,.08); }
.kres-cal-dow { display:grid; grid-template-columns:repeat(7,1fr); padding:8px 14px 4px; }
.kres-cal-dow b { text-align:center; font-size:9px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:rgba(255,255,255,.35); }
.kres-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; padding:8px 14px 14px; }
.kres-day { border-radius:10px; aspect-ratio:1; display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; transition:all .2s; border:1.5px solid transparent; position:relative; }
.kres-day .kdn { font-family:'Bebas Neue',sans-serif; font-size:17px; line-height:1; }
.kres-day .kds { font-size:7px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-top:2px; }
/* États jours */
.kres-day.libre { background:rgba(0,200,122,.1); border-color:rgba(0,200,122,.3); }
.kres-day.libre .kdn { color:#00C87A; }
.kres-day.libre .kds { color:rgba(0,200,122,.7); }
.kres-day.libre:hover { background:rgba(0,200,122,.22); transform:scale(1.1); box-shadow:0 4px 16px rgba(0,200,122,.25); }
.kres-day.partiel { background:rgba(255,140,66,.1); border-color:rgba(255,140,66,.3); }
.kres-day.partiel .kdn { color:#FF8C42; }
.kres-day.partiel .kds { color:rgba(255,140,66,.7); }
.kres-day.partiel:hover { background:rgba(255,140,66,.2); transform:scale(1.08); }
.kres-day.bloque { background:rgba(139,63,217,.12); border-color:rgba(139,63,217,.3); cursor:not-allowed; }
.kres-day.bloque .kdn { color:#A855F7; }
.kres-day.ferme { background:rgba(255,71,87,.07); cursor:default; }
.kres-day.ferme .kdn { color:rgba(255,71,87,.45); }
.kres-day.passe { opacity:.22; cursor:default; }
.kres-day.today { outline:2px solid #E8B84B; outline-offset:2px; }
.kres-day.ksel { outline:2.5px solid #fff; transform:scale(1.12); z-index:1; }

/* Créneaux */
.kres-creneaux-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(82px,1fr)); gap:7px; }
.kres-slot { padding:10px 6px; border-radius:10px; text-align:center; cursor:pointer; transition:all .2s; border:1.5px solid transparent; }
.kres-slot .ksh { font-family:'Bebas Neue',sans-serif; font-size:17px; letter-spacing:1px; line-height:1; }
.kres-slot .ksf { font-size:9px; font-weight:600; margin-top:3px; }
/* Disponible */
.kres-slot.dispo { background:rgba(0,200,122,.1); border-color:rgba(0,200,122,.3); }
.kres-slot.dispo .ksh { color:#00C87A; }
.kres-slot.dispo .ksf { color:rgba(0,200,122,.7); }
.kres-slot.dispo:hover { background:#00C87A; transform:scale(1.06); box-shadow:0 4px 14px rgba(0,200,122,.3); }
.kres-slot.dispo:hover .ksh,.kres-slot.dispo:hover .ksf { color:#000; }
/* Occupé */
.kres-slot.occupe { background:rgba(255,71,87,.07); border-color:rgba(255,71,87,.2); cursor:not-allowed; }
.kres-slot.occupe .ksh { color:rgba(255,71,87,.5); }
.kres-slot.occupe .ksf { color:rgba(255,71,87,.4); }
/* Sélectionné */
.kres-slot.sel { background:rgba(232,184,75,.15); border-color:#E8B84B; box-shadow:0 0 0 2px rgba(232,184,75,.25); }
.kres-slot.sel .ksh { color:#E8B84B; }
.kres-slot.sel .ksf { color:rgba(232,184,75,.8); }

/* Récapitulatif panel 3 */
.kres-recap { background:linear-gradient(135deg,rgba(232,184,75,.07),rgba(139,63,217,.07)); border:1px solid rgba(232,184,75,.2); border-radius:14px; padding:18px 22px; margin-bottom:18px; }
.kres-recap-title { font-family:'Bebas Neue',sans-serif; font-size:13px; letter-spacing:2.5px; color:#E8B84B; margin-bottom:12px; }
.kres-recap-row { display:flex; justify-content:space-between; align-items:center; font-size:12px; padding:6px 0; border-bottom:1px solid rgba(255,255,255,.05); }
.kres-recap-row:last-child { border-bottom:none; }
.kres-recap-row span:first-child { color:rgba(255,255,255,.45); }
.kres-recap-row span:last-child { font-weight:600; color:rgba(255,255,255,.85); }
.kres-gold { color:#E8B84B !important; font-weight:700 !important; }
.kres-checking { color:#E8B84B; animation:kresChk .8s ease-in-out infinite alternate; }
@keyframes kresChk { from{opacity:.5} to{opacity:1} }
.kres-dispo-ok { color:#00C87A !important; }
.kres-dispo-err { color:#FF4757 !important; }
.kres-recap-dispo { background:rgba(255,255,255,.02); border-radius:6px; padding:8px 10px !important; margin-top:6px; }

/* Formulaire */
.kres-form { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:22px; margin-bottom:18px; position:relative; overflow:hidden; }
.kres-form::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,#8B3FD9,#E8B84B); }
.kres-form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.kres-fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
.kres-fg:last-child { margin-bottom:0; }
.kres-fg label { font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,.5); }
.kres-req { color:#E8B84B; }
.kres-fg input,.kres-fg textarea { font-family:'Inter',sans-serif; font-size:13px; padding:11px 14px; background:rgba(0,0,0,.35); border:1.5px solid rgba(255,255,255,.1); border-radius:10px; color:#fff; outline:none; transition:border .2s,box-shadow .2s; width:100%; }
.kres-fg input:focus,.kres-fg textarea:focus { border-color:rgba(232,184,75,.45); box-shadow:0 0 0 3px rgba(232,184,75,.1); }
.kres-fg input::placeholder,.kres-fg textarea::placeholder { color:rgba(255,255,255,.25); }
.kres-fg textarea { resize:vertical; min-height:80px; }

/* Boutons */
.kres-actions { display:flex; align-items:center; gap:10px; margin-top:20px; }
.kres-actions-right { justify-content:center; }
.kres-btn-next { background:linear-gradient(135deg,#8B3FD9,#E8B84B); color:#fff; border:none; border-radius:30px; padding:13px 32px; font-family:'Bebas Neue',sans-serif; font-size:16px; letter-spacing:2px; cursor:pointer; transition:all .3s; box-shadow:0 6px 20px rgba(139,63,217,.3); }
.kres-btn-next:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 10px 28px rgba(139,63,217,.45); }
.kres-btn-next:disabled { opacity:.3; cursor:not-allowed; transform:none; box-shadow:none; }
.kres-btn-back { background:transparent; border:1.5px solid rgba(255,255,255,.15); color:rgba(255,255,255,.55); border-radius:30px; padding:11px 22px; font-family:'Inter',sans-serif; font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase; cursor:pointer; transition:all .2s; white-space:nowrap; }
.kres-btn-back:hover { border-color:rgba(232,184,75,.4); color:#E8B84B; background:rgba(232,184,75,.06); }
.kres-btn-submit { background:linear-gradient(135deg,#8B3FD9,#E8B84B); color:#fff; border:none; border-radius:30px; padding:14px 34px; font-family:'Bebas Neue',sans-serif; font-size:17px; letter-spacing:2px; cursor:pointer; transition:all .3s; box-shadow:0 6px 20px rgba(139,63,217,.3); flex:1; }
.kres-btn-submit:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 10px 28px rgba(139,63,217,.45); }
.kres-btn-submit:disabled { opacity:.3; cursor:not-allowed; }

/* Alertes */
.kres-alert-err { background:rgba(255,71,87,.1); border:1px solid rgba(255,71,87,.3); border-radius:10px; padding:12px 16px; font-size:12px; font-weight:600; color:#FFB3B8; margin-bottom:14px; line-height:1.6; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.kres-alert-err button { background:rgba(255,71,87,.2); border:1px solid rgba(255,71,87,.4); border-radius:8px; color:#FF4757; font-size:11px; font-weight:700; padding:6px 12px; cursor:pointer; transition:all .2s; }
.kres-alert-err button:hover { background:#FF4757; color:#fff; }
.kres-info-box { background:rgba(139,63,217,.1); border:1px solid rgba(139,63,217,.25); border-radius:10px; padding:12px 16px; font-size:12px; color:rgba(255,255,255,.65); line-height:1.6; margin-bottom:14px; }
.kres-loading { text-align:center; padding:28px; font-size:12px; color:rgba(255,255,255,.4); grid-column:1/-1; }

/* Succès */
.kres-success { text-align:center; padding:70px 24px; }
.kres-success-ico { font-size:64px; display:block; margin-bottom:20px; filter:drop-shadow(0 0 24px rgba(0,200,122,.4)); }
.kres-success h2 { font-family:'Bebas Neue',sans-serif; font-size:44px; color:#00C87A; letter-spacing:3px; margin-bottom:14px; }
.kres-success p { font-size:13px; color:rgba(255,255,255,.65); line-height:1.9; max-width:480px; margin:0 auto 8px; }
.kres-wa-btn { display:inline-flex; align-items:center; gap:8px; background:#25D366; color:#fff; padding:13px 26px; border-radius:30px; text-decoration:none; font-size:13px; font-weight:700; margin-top:20px; transition:all .2s; }
.kres-wa-btn:hover { background:#20BD5A; transform:translateY(-2px); }

/* Responsive */
@media(max-width:900px) { .kres-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:660px) { .kres-grid { grid-template-columns:repeat(2,1fr); } .kres-form-row { grid-template-columns:1fr; } .kres-slbl { display:none; } .kres-line { width:24px; } }
@media(max-width:420px) { .kres-grid { grid-template-columns:1fr 1fr; } }
</style>

<!-- ══ JS INLINE ═══════════════════════════════════════════════ -->
<script>
const KRES = {
  rest: '<?= esc_js($rest) ?>',
  nonce: '<?= esc_js($nonce) ?>',
  svc: '', forf: '', duree: 0, label: '',
  date: '', heure: '', heureFin: '',
  calYear: new Date().getFullYear(),
  calMonth: new Date().getMonth(),
  calData: {},
  creneaux: [],
  conflicts: {},
};

const KRES_MOIS = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
// Généré depuis les mêmes options kophi_tarif_* que les cartes ci-dessus
// et que les pages Tarifs & Workflow / Paramètres — toujours synchronisé.
const KRES_SVC_LABELS = {
  'voix_50000':'Session Essentielle — <?= esc_js(kophi_fmt_fcfa($ess_min)) ?>-<?= esc_js(kophi_fmt_fcfa($ess_max)) ?> F',
  'voix_65000':'Session Pro — <?= esc_js(kophi_fmt_fcfa($pro_min)) ?>-<?= esc_js(kophi_fmt_fcfa($pro_max)) ?> F',
  'voix_90000':'Session Premium — <?= esc_js(kophi_fmt_fcfa($prm_min)) ?>-<?= esc_js(kophi_fmt_fcfa($prm_max)) ?> F',
  'voix_150000':'Session Signature — <?= esc_js(kophi_fmt_fcfa($sig_min)) ?>-<?= esc_js(kophi_fmt_fcfa($sig_max)) ?> F',
  'faceB_10000':'Session Face B — <?= esc_js(kophi_fmt_fcfa($faceb)) ?> F',
  'beats_':'Beat Custom — 30 000-100 000 F',
  'consultation_':'RDV / Consultation',
};

// ── Navigation panels ────────────────────────────────────────────
function kresGo(n) {
  document.querySelectorAll('.kres-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('kres-p'+n).classList.add('active');
  [1,2,3].forEach(i => {
    const s = document.getElementById('kres-s'+i);
    s.classList.remove('active','done');
    if (i < n) { s.classList.add('done'); document.getElementById('kres-l'+(i))?.classList.add('done'); }
    else if (i === n) s.classList.add('active');
    else document.getElementById('kres-l'+i)?.classList.remove('done');
  });
  if (n === 2) kresLoadCal();
  if (n === 3) kresUpdateRecap();
  document.getElementById('kres')?.scrollIntoView({ behavior:'smooth', block:'start' });
}

// ── Sélection service ────────────────────────────────────────────
function kresSelect(el, svc, forf, duree) {
  document.querySelectorAll('.kres-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  KRES.svc = svc; KRES.forf = forf; KRES.duree = duree;
  KRES.label = KRES_SVC_LABELS[svc+'_'+forf] || svc;
  KRES.date = ''; KRES.heure = '';
  document.getElementById('kres-btn-next1').disabled = false;
  document.getElementById('kres-beat-fg').style.display = svc === 'beats' ? 'flex' : 'none';
}

// ── Calendrier ───────────────────────────────────────────────────
async function kresLoadCal() {
  const moisStr = KRES.calYear+'-'+String(KRES.calMonth+1).padStart(2,'0');
  document.getElementById('kres-cal-title').textContent = KRES_MOIS[KRES.calMonth]+' '+KRES.calYear;
  document.getElementById('kres-cal-grid').innerHTML = '<div class="kres-loading">Chargement...</div>';
  try {
    const r = await fetch(KRES.rest+'disponibilite/mois?mois='+moisStr+'&service='+KRES.svc+'&forfait='+KRES.forf, {
      headers: {'X-WP-Nonce': KRES.nonce}
    });
    if (r.ok) { KRES.calData = await r.json(); }
    else { KRES.calData = {}; }
  } catch { KRES.calData = {}; }
  kresRenderCal();
}

function kresRenderCal() {
  const grid = document.getElementById('kres-cal-grid');
  grid.innerHTML = '';
  const today = new Date().toISOString().split('T')[0];
  const hier  = new Date(Date.now()-86400000).toISOString().split('T')[0];
  const fd    = new Date(KRES.calYear, KRES.calMonth, 1).getDay();
  const off   = fd === 0 ? 6 : fd-1;
  const days  = new Date(KRES.calYear, KRES.calMonth+1, 0).getDate();
  const labels = {libre:'LIBRE',partiel:'PARTIEL',bloque:'🔒',ferme:'FERMÉ',passe:''};

  for (let i=0; i<off; i++) grid.insertAdjacentHTML('beforeend','<div></div>');
  for (let d=1; d<=days; d++) {
    const ds = `${KRES.calYear}-${String(KRES.calMonth+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const isPast = ds <= hier;
    const st = isPast ? 'passe' : (KRES.calData[ds] || 'libre');
    const isSel = ds === KRES.date;
    const isToday = ds === today;
    const clickable = !['bloque','ferme','passe'].includes(st);
    const label = labels[st] || '';
    grid.insertAdjacentHTML('beforeend', `
      <div class="kres-day ${st} ${isSel?'ksel':''} ${isToday?'today':''}"
           ${clickable?`onclick="kresPickDate('${ds}')"`:''}
           title="${ds}">
        <span class="kdn">${d}</span>
        <span class="kds">${label}</span>
      </div>`);
  }
}

function kresCalPrev() { KRES.calMonth--; if(KRES.calMonth<0){KRES.calMonth=11;KRES.calYear--;} KRES.date='';KRES.heure='';kresLoadCal(); }
function kresCalNext() { KRES.calMonth++; if(KRES.calMonth>11){KRES.calMonth=0;KRES.calYear++;} KRES.date='';KRES.heure='';kresLoadCal(); }

async function kresPickDate(ds) {
  KRES.date = ds; KRES.heure = '';
  document.getElementById('kres-btn-next2').disabled = true;
  kresRenderCal();

  if (KRES.svc === 'consultation') {
    document.getElementById('kres-creneaux-wrap').style.display = 'none';
    document.getElementById('kres-no-hour').style.display = 'block';
    document.getElementById('kres-btn-next2').disabled = false;
    return;
  }
  document.getElementById('kres-no-hour').style.display = 'none';
  document.getElementById('kres-creneaux-wrap').style.display = 'block';
  document.getElementById('kres-creneaux-title').textContent = 'Créneaux — '+ds.split('-').reverse().join('/');
  document.getElementById('kres-creneaux-grid').innerHTML = '<div class="kres-loading" style="grid-column:1/-1">Chargement des créneaux...</div>';

  try {
    const r = await fetch(KRES.rest+'disponibilite?date='+ds+'&service='+KRES.svc+'&forfait='+KRES.forf, {
      headers: {'X-WP-Nonce': KRES.nonce}
    });
    const data = r.ok ? await r.json() : null;
    if (!data?.ouvert) {
      document.getElementById('kres-creneaux-grid').innerHTML = '<div class="kres-loading">Studio fermé ce jour. Choisissez une autre date.</div>';
      return;
    }
    KRES.creneaux = data.creneaux || [];
    // Stocker les conflits
    KRES.conflicts = {};
    KRES.creneaux.forEach(c => { KRES.conflicts[c.heure] = c.conflicts || []; });
    kresRenderCreneaux();
  } catch(e) {
    document.getElementById('kres-creneaux-grid').innerHTML = '<div class="kres-loading">Erreur chargement.</div>';
  }
}

function kresRenderCreneaux() {
  const grid = document.getElementById('kres-creneaux-grid');
  if (!KRES.creneaux.length) {
    grid.innerHTML = '<div class="kres-loading">Aucun créneau disponible ce jour.</div>';
    return;
  }
  grid.innerHTML = KRES.creneaux.map(c => {
    const isSel = KRES.heure === c.heure;
    const cls = c.disponible ? 'dispo' : 'occupe';
    const action = c.disponible
      ? `onclick="kresPickHeure('${c.heure}','${c.heure_fin}')"`
      : `onclick="alert('🚫 Créneau déjà réservé')"`;
    const label = c.disponible ? '→ '+c.heure_fin : 'Occupé';
    return `<div class="kres-slot ${cls} ${isSel?'sel':''}" ${action}>
      <div class="ksh">${c.heure}</div>
      <div class="ksf">${label}</div>
    </div>`;
  }).join('');
}

function kresPickHeure(h, hf) {
  KRES.heure = h; KRES.heureFin = hf;
  kresRenderCreneaux();
  document.getElementById('kres-btn-next2').disabled = false;
}

// ── Panel 3 — Récapitulatif + vérification ───────────────────────
async function kresUpdateRecap() {
  document.getElementById('rs-svc').textContent   = KRES.label || '—';
  document.getElementById('rs-date').textContent  = KRES.date ? KRES.date.split('-').reverse().join('/') : '—';
  document.getElementById('rs-heure').textContent = KRES.heure ? KRES.heure+(KRES.heureFin?' → '+KRES.heureFin:'') : "À confirmer avec l'équipe";
  document.getElementById('rs-duree').textContent = KRES.duree ? KRES.duree+'h' : '—';

  const dispoEl = document.getElementById('rs-dispo');
  const slotErr = document.getElementById('kres-slot-err');
  const submitBtn = document.getElementById('kres-btn-submit');
  slotErr.style.display = 'none';
  submitBtn.disabled = false;

  if (KRES.svc === 'consultation') {
    dispoEl.className = 'kres-dispo-ok';
    dispoEl.textContent = '✓ RDV — l\'équipe vous contactera';
    return;
  }
  if (!KRES.heure) {
    dispoEl.className = '';
    dispoEl.textContent = 'Aucune heure sélectionnée';
    return;
  }

  // Re-vérification en temps réel
  dispoEl.className = 'kres-checking';
  dispoEl.textContent = '⏳ Vérification en cours...';
  try {
    const r = await fetch(KRES.rest+'disponibilite?date='+KRES.date+'&service='+KRES.svc+'&forfait='+KRES.forf, {
      headers: {'X-WP-Nonce': KRES.nonce}
    });
    const data = r.ok ? await r.json() : null;
    if (!data) { dispoEl.className = ''; dispoEl.textContent = '—'; return; }

    // Chercher notre créneau spécifique dans la réponse
    const slot = (data.creneaux || []).find(c => c.heure === KRES.heure);
    if (!slot) {
      // Créneau non trouvé — studio fermé ou durée incompatible
      dispoEl.className = 'kres-dispo-err';
      dispoEl.textContent = '⚠ Créneau indisponible ce jour';
      slotErr.style.display = 'flex';
      submitBtn.disabled = true;
    } else if (slot.disponible) {
      dispoEl.className = 'kres-dispo-ok';
      dispoEl.textContent = '✓ Créneau disponible';
    } else {
      const msg = slot.conflicts?.[0]?.message || 'Créneau déjà réservé';
      dispoEl.className = 'kres-dispo-err';
      dispoEl.textContent = '⚠ ' + msg;
      slotErr.style.display = 'flex';
      submitBtn.disabled = true;
    }
  } catch {
    dispoEl.className = ''; dispoEl.textContent = '—';
  }
}

// ── Soumission ───────────────────────────────────────────────────
async function kresSubmit() {
  const nom  = document.getElementById('kres-nom').value.trim();
  const tel  = document.getElementById('kres-tel').value.trim();
  const beat = document.getElementById('kres-beat').value.trim();
  const errEl = document.getElementById('kres-form-err');
  errEl.style.display = 'none';

  if (!nom || !tel) { errEl.textContent = 'Veuillez remplir votre nom et téléphone.'; errEl.style.display = 'flex'; return; }
  if (KRES.svc === 'beats' && !beat) { errEl.textContent = 'Veuillez décrire votre beat.'; errEl.style.display = 'flex'; return; }

  const btn = document.getElementById('kres-btn-submit');
  btn.disabled = true; btn.textContent = '⏳ Envoi en cours...';

  const msg = (document.getElementById('kres-msg').value || '') + (beat ? '\n\nBrief Beat :\n' + beat : '');

  try {
    const r = await fetch(KRES.rest+'reservations', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KRES.nonce },
      body: JSON.stringify({
        nom_artiste: nom, telephone: tel,
        email: document.getElementById('kres-email').value,
        service: KRES.svc, forfait: KRES.forf,
        date_souhaitee: KRES.date,
        heure_souhaitee: KRES.heure || null,
        message: msg,
      }),
    });
    const data = r.ok ? await r.json() : null;
    if (data?.id || data?.success) {
      document.querySelectorAll('.kres-panel, .kres-stepper').forEach(el => el.style.display = 'none');
      document.getElementById('kres-success').style.display = 'block';
      document.getElementById('kres').scrollIntoView({ behavior:'smooth' });
    } else {
      const errMsg = data?.message || 'Erreur lors de l\'envoi.';
      errEl.textContent = errMsg; errEl.style.display = 'flex';
      btn.disabled = false; btn.textContent = '✉️ ENVOYER MA DEMANDE';
      if (data?.message?.includes('conflict') || data?.message?.includes('créneau')) {
        setTimeout(() => kresGo(2), 2000);
      }
    }
  } catch {
    errEl.textContent = 'Erreur de connexion. Réessayez.'; errEl.style.display = 'flex';
    btn.disabled = false; btn.textContent = '✉️ ENVOYER MA DEMANDE';
  }
}
</script>
