<?php
/**
 * KOPHI'S MUSIC STUDIO — Smart Payment Center (template)
 * @package KophiStudioManager
 */
if (!defined('ABSPATH')) exit;
?>
<div class="kspc-wrap" id="kspc-app" data-ref="<?= esc_attr(get_query_var('kophi_order_ref')) ?>">

  <div class="kspc-card">
    <div class="kspc-eyebrow">KOPHI'S MUSIC STUDIO</div>
    <h1 class="kspc-title" id="kspc-service">Chargement…</h1>
    <div class="kspc-montant" id="kspc-montant"></div>
    <div class="kspc-ref">Réf. <span id="kspc-ref-val"><?= esc_html(get_query_var('kophi_order_ref')) ?></span></div>
  </div>

  <div class="kspc-card" id="kspc-methods-card" style="display:none">
    <div class="kspc-methods-tabs" id="kspc-tabs"></div>

    <div id="kspc-panel">
      <p class="kspc-panel-instructions">Scanne le QR ou clique sur le bouton, entre le montant exact indiqué, puis confirme le paiement dans l'app.</p>

      <div class="kspc-qr-wrap" id="kspc-qr-wrap">
        <div class="kspc-qr-box" id="kspc-qr-box"></div>
        <p class="kspc-qr-hint">Scanne avec l'app ou l'appareil photo</p>
      </div>

      <button class="kspc-btn-pay" id="kspc-btn-pay">Payer maintenant</button>
      <button class="kspc-btn-copy" id="kspc-btn-copy">📋 Copier le lien</button>

      <div class="kspc-divider"></div>

      <button class="kspc-declare-toggle" id="kspc-declare-toggle">▶ J'ai déjà payé — confirmer ma transaction</button>
      <div class="kspc-declare-form" id="kspc-declare-form" style="display:none">
        <input type="text" id="kspc-declare-ref" placeholder="Référence de la transaction (ex. reçu Wave/OM)">
        <textarea id="kspc-declare-note" placeholder="Note additionnelle (optionnel)" rows="2"></textarea>
        <button class="kspc-btn-pay" id="kspc-btn-declare">Envoyer ma confirmation</button>
        <p class="kspc-declare-msg" id="kspc-declare-msg" style="display:none"></p>
      </div>
    </div>
  </div>

  <div class="kspc-card kspc-wa-card">
    <p>Besoin d'aide ou préférence pour un règlement direct ?</p>
    <a href="#" id="kspc-wa-link" target="_blank" class="kspc-btn-wa">💬 Contacter sur WhatsApp</a>
  </div>

  <div class="kspc-card kspc-status" id="kspc-status-card" style="display:none">
    <div class="kspc-status-icon" id="kspc-status-icon">⏳</div>
    <div class="kspc-status-text" id="kspc-status-text"></div>
    <a href="#" id="kspc-licence-link" style="display:none" target="_blank" class="kspc-btn-pay">📄 Télécharger ma licence</a>
  </div>

</div>
