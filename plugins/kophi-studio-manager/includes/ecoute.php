<?php
/**
 * KOPHI'S MUSIC STUDIO — Module ÉCOUTE CLIENT
 * ================================================================
 * Permet de faire écouter au client la version finale d'un titre
 * AVANT le solde du paiement, sans lui envoyer le fichier WAV/MP3.
 *
 * Principe :
 *  - On attache un ou plusieurs titres (audio) à une Session ou un Projet.
 *  - Un lien d'écoute privé (jeton) est généré, à envoyer au client (WhatsApp).
 *  - Le client ouvre une page avec un LECTEUR HTML5 (pas de téléchargement).
 *  - L'audio est diffusé en streaming via un endpoint PHP protégé par jeton :
 *    le vrai chemin du fichier n'est jamais exposé.
 *  - Le bouton de TÉLÉCHARGEMENT ne se débloque que si le solde = 0.
 *
 * Inclus par kophi-studio-manager.php
 *
 * NOTE SÉCURITÉ : aucun lecteur web n'empêche à 100% un utilisateur
 * déterminé de capturer un flux audio. Ce module supprime cependant
 * tout accès « facile » (clic droit, bouton télécharger, URL directe
 * du fichier), ce qui couvre le cas d'usage réel du studio.
 */

if (!defined('ABSPATH')) exit;

// Garde-fou : empêche tout double-chargement (anti « cannot redeclare »)
if (defined('KSM_LISTEN_LOADED')) return;
define('KSM_LISTEN_LOADED', true);

// Types de contenu qui peuvent porter une écoute client
if (!defined('KSM_LISTEN_TYPES')) {
    define('KSM_LISTEN_TYPES', ['kophi_session', 'kophi_projet']);
}

// ================================================================
// 1. HELPERS — jeton & recherche
// ================================================================

/**
 * Récupère (ou crée) le jeton d'écoute d'un post.
 */
function ksm_listen_get_token(int $post_id): string {
    $token = get_post_meta($post_id, '_kophi_ecoute_token', true);
    if (!$token) {
        // 24 caractères alphanumériques, sans ambiguïté
        $token = wp_generate_password(24, false, false);
        update_post_meta($post_id, '_kophi_ecoute_token', $token);
    }
    return $token;
}

/**
 * Retrouve un post à partir de son jeton d'écoute.
 */
function ksm_listen_find_by_token(string $token) {
    $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
    if (strlen($token) < 8) return null;

    $posts = get_posts([
        'post_type'      => KSM_LISTEN_TYPES,
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'meta_key'       => '_kophi_ecoute_token',
        'meta_value'     => $token,
        'no_found_rows'  => true,
    ]);
    return !empty($posts) ? $posts[0] : null;
}

/**
 * Retourne la liste normalisée des titres d'un post : [ ['title'=>, 'id'=>], ... ]
 */
function ksm_listen_get_tracks(int $post_id): array {
    $raw = get_post_meta($post_id, '_kophi_ecoute_tracks', true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $t) {
        $aid = isset($t['id']) ? (int)$t['id'] : 0;
        if ($aid && wp_get_attachment_url($aid)) {
            $out[] = [
                'title' => isset($t['title']) ? (string)$t['title'] : get_the_title($aid),
                'id'    => $aid,
            ];
        }
    }
    return $out;
}

/**
 * Retourne tous les commentaires clients d'un post, triés du plus récent au plus ancien.
 */
function ksm_listen_get_feedback(int $post_id): array {
    $raw = get_post_meta($post_id, '_kophi_ecoute_feedback', true);
    if (!is_array($raw)) return [];
    usort($raw, function($a, $b) { return ($b['date'] ?? 0) <=> ($a['date'] ?? 0); });
    return $raw;
}

/**
 * Commentaires pour un titre précis (par index dans la liste des titres).
 */
function ksm_listen_get_feedback_for_track(int $post_id, int $track_index): array {
    return array_values(array_filter(
        ksm_listen_get_feedback($post_id),
        function($f) use ($track_index) { return isset($f['track']) && (int)$f['track'] === $track_index; }
    ));
}

/**
 * Nombre de commentaires pas encore marqués « traité » par le studio.
 */
function ksm_listen_count_new_feedback(int $post_id): int {
    return count(array_filter(
        ksm_listen_get_feedback($post_id),
        function($f) { return ($f['status'] ?? 'nouveau') === 'nouveau'; }
    ));
}

/**
 * Ajoute un commentaire client pour un titre donné. Retourne l'entrée créée, ou false si vide.
 */
function ksm_listen_add_feedback(int $post_id, int $track_index, string $text) {
    $text = trim(sanitize_textarea_field($text));
    if ($text === '') return false;
    if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);

    $entry = [
        'id'     => wp_generate_password(12, false, false),
        'track'  => $track_index,
        'text'   => $text,
        'date'   => current_time('timestamp'),
        'status' => 'nouveau', // 'nouveau' | 'traite'
    ];
    $all = get_post_meta($post_id, '_kophi_ecoute_feedback', true);
    if (!is_array($all)) $all = [];
    $all[] = $entry;
    update_post_meta($post_id, '_kophi_ecoute_feedback', $all);
    return $entry;
}

/**
 * Change le statut d'un commentaire (nouveau ⇄ traité).
 */
function ksm_listen_set_feedback_status(int $post_id, string $entry_id, string $status): bool {
    if (!in_array($status, ['nouveau', 'traite'], true)) return false;
    $all = get_post_meta($post_id, '_kophi_ecoute_feedback', true);
    if (!is_array($all)) return false;
    $found = false;
    foreach ($all as &$f) {
        if (($f['id'] ?? '') === $entry_id) { $f['status'] = $status; $found = true; break; }
    }
    unset($f);
    if ($found) update_post_meta($post_id, '_kophi_ecoute_feedback', $all);
    return $found;
}

/**
 * Calcule le solde restant dû : montant total − acompte versé.
 */
function ksm_listen_get_solde(int $post_id): float {
    $total   = (float) get_post_meta($post_id, '_kophi_montant', true);
    $acompte = (float) get_post_meta($post_id, '_kophi_acompte', true);
    $solde   = $total - $acompte;
    return $solde > 0 ? $solde : 0.0;
}

/**
 * Mode de téléchargement choisi par le studio.
 *   'jamais'   → écoute seule, jamais de téléchargement
 *   'solde'    → téléchargement débloqué une fois le solde réglé (défaut)
 *   'toujours' → téléchargement toujours autorisé
 */
function ksm_listen_dl_mode(int $post_id): string {
    $m = get_post_meta($post_id, '_kophi_ecoute_dl_mode', true);
    return in_array($m, ['jamais', 'solde', 'toujours'], true) ? $m : 'solde';
}

/**
 * Le téléchargement est-il autorisé pour ce post (selon le mode + le solde) ?
 */
function ksm_listen_dl_allowed(int $post_id): bool {
    switch (ksm_listen_dl_mode($post_id)) {
        case 'toujours': return true;
        case 'jamais':   return false;
        default:         return ksm_listen_get_solde($post_id) <= 0; // 'solde'
    }
}

/**
 * Date d'expiration du lien d'écoute (format Y-m-d), ou '' si pas d'expiration.
 */
function ksm_listen_expire_date(int $post_id): string {
    $d = get_post_meta($post_id, '_kophi_ecoute_expire', true);
    return is_string($d) ? $d : '';
}

/**
 * Le lien d'écoute a-t-il expiré ?
 * Expire à 23:59:59 (heure du site) le jour choisi.
 */
function ksm_listen_is_expired(int $post_id): bool {
    $d = ksm_listen_expire_date($post_id);
    if (!$d) return false; // pas de date = jamais d'expiration
    $ts = strtotime($d . ' 23:59:59', current_time('timestamp'));
    if (!$ts) return false; // date invalide → ne bloque pas par sécurité
    return current_time('timestamp') > $ts;
}

/**
 * Le lien d'écoute public à partager avec le client.
 */
function ksm_listen_link(int $post_id): string {
    $token = ksm_listen_get_token($post_id);
    return add_query_arg('ksm_listen', $token, home_url('/'));
}

/**
 * Lien de streaming d'un titre (index) — protégé par jeton.
 */
function ksm_listen_stream_link(string $token, int $index): string {
    return add_query_arg([
        'ksm_stream' => $token,
        'i'            => $index,
    ], home_url('/'));
}

/**
 * Nombre total de commentaires « nouveaux » (non traités), toutes sessions/projets confondus.
 * Utilisé pour le badge du menu admin et le tableau de bord.
 */
function ksm_listen_total_new_feedback(): int {
    $posts = get_posts([
        'post_type'      => KSM_LISTEN_TYPES,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'meta_query'     => [['key' => '_kophi_ecoute_feedback', 'compare' => 'EXISTS']],
    ]);
    $total = 0;
    foreach ($posts as $pid) {
        $total += ksm_listen_count_new_feedback((int) $pid);
    }
    return $total;
}

/**
 * Envoie un email au studio pour prévenir d'un nouveau commentaire client.
 */
function ksm_listen_notify_new_feedback(int $post_id, int $track_index, string $text): void {
    $primary = get_option('kophi_email') ?: (defined('KOPHI_EMAIL') ? KOPHI_EMAIL : '');
    $recipients = array_unique(array_filter([
        $primary,
        'gadagadagnahoua@gmail.com', // Adresse secondaire — reçoit aussi les commentaires/recommandations
    ], 'is_email'));
    if (empty($recipients)) return;

    $tracks    = ksm_listen_get_tracks($post_id);
    $track_ttl = isset($tracks[$track_index]) ? $tracks[$track_index]['title'] : ('Titre #' . ($track_index + 1));
    $client    = get_post_meta($post_id, '_kophi_client_nom', true);
    $proj_ttl  = get_the_title($post_id);
    $edit_url  = admin_url('post.php?post=' . $post_id . '&action=edit');
    $ecoute_url= admin_url('admin.php?page=kophi-ecoute');

    $subject = "💬 Nouveau commentaire client — {$track_ttl}";
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: ' . (defined('KOPHI_EMAIL') ? KOPHI_EMAIL : $primary),
    ];

    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#0D0D0D;font-family:Inter,Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#0D0D0D;padding:24px 0'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#130826;border:1px solid rgba(139,63,217,.3);border-radius:16px;overflow:hidden'>
  <tr><td style='background:linear-gradient(135deg,#1e0938,#2d0a5e);padding:26px 32px;text-align:center'>
    <p style='color:#9E98B8;font-size:10px;letter-spacing:3px;text-transform:uppercase;margin:0 0 6px'>KOPHI'S MUSIC STUDIO</p>
    <h1 style='color:#F0ECF8;font-size:19px;margin:0'>💬 Nouveau commentaire client</h1>
  </td></tr>
  <tr><td style='padding:24px 32px'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border-radius:10px;overflow:hidden;border:1px solid rgba(255,255,255,.07)'>
      <tr><td style='padding:10px 16px;background:#1a0a30;color:#9E98B8;font-size:12px;width:130px'>Client</td><td style='padding:10px 16px;color:#F0ECF8;font-size:13px'>" . esc_html($client ?: '—') . "</td></tr>
      <tr><td style='padding:10px 16px;color:#9E98B8;font-size:12px'>Session / Projet</td><td style='padding:10px 16px;color:#F0ECF8;font-size:13px'>" . esc_html($proj_ttl) . "</td></tr>
      <tr><td style='padding:10px 16px;background:#1a0a30;color:#9E98B8;font-size:12px'>Titre concerné</td><td style='padding:10px 16px;color:#E8B84B;font-size:13px;font-weight:700;background:#1a0a30'>🎵 " . esc_html($track_ttl) . "</td></tr>
    </table>
    <div style='margin-top:16px;padding:14px 16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;color:#E5E0F2;font-size:13px;line-height:1.6;white-space:pre-wrap'>" . nl2br(esc_html($text)) . "</div>
  </td></tr>
  <tr><td style='padding:0 32px 28px;text-align:center'>
    <a href='" . esc_url($ecoute_url) . "' style='display:inline-block;padding:13px 26px;background:linear-gradient(135deg,#8B3FD9,#E8B84B);color:#fff;text-decoration:none;border-radius:10px;font-size:13px;font-weight:700'>Voir dans Écoute Client →</a>
    <br><a href='" . esc_url($edit_url) . "' style='display:inline-block;margin-top:10px;color:#9E98B8;text-decoration:none;font-size:11px'>Ouvrir la fiche →</a>
  </td></tr>
  <tr><td style='background:#0D0D0D;padding:14px 32px;text-align:center;border-top:1px solid rgba(255,255,255,.06)'>
    <p style='color:#555;font-size:10px;margin:0'>KOPHI'S MUSIC STUDIO · Abidjan, Côte d'Ivoire</p>
  </td></tr>
</table></td></tr></table></body></html>";

    foreach ($recipients as $to) {
        wp_mail($to, $subject, $html, $headers);
    }
}

// ================================================================
// 2. METABOX ADMIN — gestion des titres + lien
// ================================================================
add_action('add_meta_boxes', 'ksm_listen_add_metabox');
function ksm_listen_add_metabox() {
    foreach (KSM_LISTEN_TYPES as $type) {
        add_meta_box(
            'ksm_listen_box',
            '🎧 Écoute Client (version finale)',
            'ksm_listen_render_metabox',
            $type,
            'normal',
            'high'
        );
    }
}

function ksm_listen_render_metabox($post) {
    wp_nonce_field('ksm_listen_save', 'ksm_listen_nonce');

    $tracks  = ksm_listen_get_tracks($post->ID);
    $active  = get_post_meta($post->ID, '_kophi_ecoute_active', true);
    $active  = ($active === '' ) ? '1' : $active; // activé par défaut
    $total   = (float) get_post_meta($post->ID, '_kophi_montant', true);
    $acompte = (float) get_post_meta($post->ID, '_kophi_acompte', true);
    $solde   = ksm_listen_get_solde($post->ID);
    $dl_mode = ksm_listen_dl_mode($post->ID);
    $expire  = ksm_listen_expire_date($post->ID);
    $expired = ksm_listen_is_expired($post->ID);

    $has_tracks = !empty($tracks);
    $link       = $has_tracks ? ksm_listen_link($post->ID) : '';

    $client   = get_post_meta($post->ID, '_kophi_client_nom', true);
    $tel      = preg_replace('/[^0-9]/', '', get_post_meta($post->ID, '_kophi_telephone', true));
    $all_fb   = ksm_listen_get_feedback($post->ID);
    $fb_nonce = wp_create_nonce('ksm_listen_ajax');
    ?>
    <style>
      .kex-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:12px 0}
      .kex-fld label{display:block;font-weight:600;font-size:12px;margin-bottom:4px}
      .kex-fld input{width:100%}
      .kex-tracks{margin:10px 0}
      .kex-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;background:#f6f7f7;padding:8px;border-radius:6px}
      .kex-row .kex-title{flex:1}
      .kex-row .kex-file{flex:1.4;font-size:12px;color:#555;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .kex-link-box{margin-top:14px;padding:12px;border:1px solid #d9b441;background:#fffdf3;border-radius:8px}
      .kex-link-box input{width:100%;font-size:12px;padding:6px}
      .kex-solde{font-size:13px;margin-top:6px}
      .kex-solde b{color:#b8860b}
      .button.kex-wa{background:#25D366;border-color:#1da851;color:#fff}
      .kex-fb-newcount{background:#d9364f;color:#fff;font-size:10.5px;font-weight:700;border-radius:999px;
        padding:2px 8px;margin-left:6px;vertical-align:middle}
      .kex-fb-items{display:flex;flex-direction:column;gap:8px}
      .kex-fb-item{background:#f6f7f7;border:1px solid #e2e2e2;border-left:3px solid #8B3FD9;border-radius:6px;padding:10px 12px}
      .kex-fb-item.kex-fb-done{border-left-color:#0a8a4a;opacity:.75}
      .kex-fb-item-head{display:flex;justify-content:space-between;gap:10px;font-size:11.5px;color:#666;margin-bottom:4px}
      .kex-fb-track{font-weight:600;color:#444}
      .kex-fb-text{font-size:13px;color:#222;line-height:1.5;margin-bottom:8px;white-space:pre-wrap}
    </style>

    <p style="color:#555;font-size:13px;margin-top:4px">
      Ajoutez ici la <strong>version finale</strong> des titres. Le client pourra les <strong>écouter</strong>
      via un lien privé. Le <strong>téléchargement</strong> dépend du mode choisi ci-dessous
      (jamais, après règlement du solde, ou toujours).
    </p>

    <div class="kex-tracks" id="kex-tracks">
      <?php
      if ($has_tracks) {
          foreach ($tracks as $t) {
              ksm_listen_row_html($t['title'], $t['id']);
          }
      } else {
          ksm_listen_row_html('', 0);
      }
      ?>
    </div>
    <button type="button" class="button" id="kex-add-row">＋ Ajouter un titre</button>

    <?php $kex_cover_id = (int) get_post_meta($post->ID, '_kophi_ecoute_cover', true);
          $kex_cover_src = $kex_cover_id ? wp_get_attachment_image_src($kex_cover_id, 'thumbnail') : false; ?>
    <div class="kex-fld" style="margin:14px 0 4px">
      <label>Pochette de la page d'écoute (facultatif)</label>
      <div style="display:flex;align-items:center;gap:10px">
        <img id="kex-cover-prev" src="<?= esc_url($kex_cover_src ? $kex_cover_src[0] : '') ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px;background:#e5e5e5;<?= $kex_cover_src ? '' : 'display:none' ?>">
        <input type="hidden" name="ksm_listen_cover" id="kex-cover-id" value="<?= esc_attr($kex_cover_id ?: '') ?>">
        <button type="button" class="button" id="kex-cover-pick">Choisir une image</button>
        <button type="button" class="button-link" id="kex-cover-clear" style="color:#b32d2e;<?= $kex_cover_id ? '' : 'display:none' ?>">Retirer</button>
      </div>
      <p class="description">Image carrée conseillée. Sans image, une pochette aux couleurs du studio est affichée.</p>
      <script>
      jQuery(function($){
        $('#kex-cover-pick').on('click', function(e){
          e.preventDefault();
          var frame = wp.media({ title:'Choisir la pochette', library:{ type:'image' }, multiple:false, button:{ text:'Utiliser cette image' } });
          frame.on('select', function(){
            var a = frame.state().get('selection').first().toJSON();
            var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
            $('#kex-cover-id').val(a.id); $('#kex-cover-prev').attr('src', url).show(); $('#kex-cover-clear').show();
          });
          frame.open();
        });
        $('#kex-cover-clear').on('click', function(e){ e.preventDefault(); $('#kex-cover-id').val(''); $('#kex-cover-prev').hide(); $(this).hide(); });
      });
      </script>
    </div>

    <div class="kex-grid">
      <div class="kex-fld">
        <label>Montant total (F CFA)</label>
        <input type="number" name="kophi_montant" value="<?= esc_attr($total) ?>" min="0" step="500">
      </div>
      <div class="kex-fld">
        <label>Acompte déjà versé (F CFA)</label>
        <input type="number" name="kophi_acompte" value="<?= esc_attr($acompte) ?>" min="0" step="500">
      </div>
      <div class="kex-fld">
        <label>Écoute activée&nbsp;?</label>
        <select name="ksm_listen_active">
          <option value="1" <?= selected($active, '1', false) ?>>✅ Oui — lien actif</option>
          <option value="0" <?= selected($active, '0', false) ?>>🚫 Non — lien désactivé</option>
        </select>
      </div>
      <div class="kex-fld">
        <label>Téléchargement du son</label>
        <select name="ksm_listen_dl_mode">
          <option value="solde"    <?= selected($dl_mode, 'solde', false) ?>>🔒 Après règlement du solde</option>
          <option value="toujours" <?= selected($dl_mode, 'toujours', false) ?>>⬇ Toujours autorisé</option>
          <option value="jamais"   <?= selected($dl_mode, 'jamais', false) ?>>🚫 Jamais (écoute seule)</option>
        </select>
      </div>
      <div class="kex-fld">
        <label>Expiration du lien <span style="color:#999;font-weight:400">(optionnel)</span></label>
        <input type="date" name="ksm_listen_expire" value="<?= esc_attr($expire) ?>">
      </div>
    </div>

    <div class="kex-solde">
      Solde restant dû : <b id="kex-solde-val"><?= number_format($solde, 0, ',', ' ') ?> F CFA</b>
      <?php if ($solde <= 0 && $total > 0): ?><span style="color:#0a8a4a">— soldé ✅ (téléchargement débloqué)</span><?php endif; ?>
    </div>
    <?php if ($expire): ?>
      <p style="margin:8px 0 0;font-size:12.5px;<?= $expired ? 'color:#c0392b;font-weight:600' : 'color:#777' ?>">
        <?= $expired ? '⛔ Lien expiré depuis le ' : '⏳ Le lien expirera le ' ?>
        <?= esc_html(date_i18n('d/m/Y', strtotime($expire))) ?>
        <?php if ($expired): ?> — le client ne peut plus y accéder.<?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($has_tracks): ?>
    <div class="kex-link-box">
      <label style="font-weight:600;display:block;margin-bottom:6px">🔗 Lien d'écoute à envoyer au client</label>
      <input type="text" id="kex-link" readonly value="<?= esc_attr($link) ?>" onclick="this.select()">
      <p style="margin:8px 0 0">
        <button type="button" class="button button-primary" onclick="kexCopy()">📋 Copier le lien</button>
        <?php
        $wa_msg = rawurlencode(
            "Bonjour " . ($client ?: '') . " 👋\n\nVoici la version finale de votre son à écouter :\n" . $link .
            ($solde > 0 ? "\n\nIl reste " . number_format($solde, 0, ',', ' ') . " F CFA à régler pour recevoir vos fichiers WAV/MP3." : '') .
            "\n\nKOPHI'S MUSIC STUDIO 🎙"
        );
        $wa_base = $tel ? "https://wa.me/{$tel}?text={$wa_msg}" : "https://wa.me/?text={$wa_msg}";
        ?>
        <a class="button kex-wa" href="<?= esc_url($wa_base) ?>" target="_blank" rel="noopener">🟢 Envoyer sur WhatsApp</a>
        <a class="button" href="<?= esc_url($link) ?>" target="_blank" rel="noopener">👁 Prévisualiser</a>
      </p>
    </div>

    <!-- Commentaires clients -->
    <div class="kex-fb-box" data-postid="<?= (int) $post->ID ?>" data-nonce="<?= esc_attr($fb_nonce) ?>">
      <label style="font-weight:600;display:block;margin:16px 0 8px">
        💬 Commentaires du client
        <?php $new_n = ksm_listen_count_new_feedback($post->ID); if ($new_n): ?>
          <span class="kex-fb-newcount"><?= $new_n ?> nouveau<?= $new_n > 1 ? 'x' : '' ?></span>
        <?php endif; ?>
      </label>
      <?php if (empty($all_fb)): ?>
        <p style="color:#777;font-style:italic;font-size:12.5px;margin:0">Aucun commentaire pour le moment.</p>
      <?php else: ?>
        <div class="kex-fb-items">
          <?php foreach ($all_fb as $f):
            $tidx = (int) ($f['track'] ?? 0);
            $ttl  = isset($tracks[$tidx]) ? $tracks[$tidx]['title'] : '—';
            $done = ($f['status'] ?? 'nouveau') === 'traite';
          ?>
            <div class="kex-fb-item<?= $done ? ' kex-fb-done' : '' ?>" data-fid="<?= esc_attr($f['id']) ?>">
              <div class="kex-fb-item-head">
                <span class="kex-fb-track">🎵 <?= esc_html($ttl) ?></span>
                <span class="kex-fb-date"><?= esc_html(date_i18n('d/m/Y H:i', $f['date'])) ?></span>
              </div>
              <div class="kex-fb-text"><?= nl2br(esc_html($f['text'])) ?></div>
              <button type="button" class="button button-small kex-fb-toggle-status">
                <?= $done ? '↺ Remettre en nouveau' : '✅ Marquer traité' ?>
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <p style="color:#777;font-style:italic;margin-top:12px">
      💡 Ajoutez au moins un titre puis enregistrez pour générer le lien d'écoute.
    </p>
    <?php endif; ?>


    <!-- Modèle de ligne (pour le JS) -->
    <template id="kex-row-tpl"><?php ksm_listen_row_html('', 0); ?></template>

    <script>
    (function(){
      var wrap = document.getElementById('kex-tracks');

      function bindRow(row){
        var btn = row.querySelector('.kex-pick');
        var rm  = row.querySelector('.kex-remove');
        if (btn) btn.addEventListener('click', function(){
          var frame = wp.media({ title:'Choisir le fichier audio final', library:{ type:'audio' }, multiple:false, button:{ text:'Utiliser ce fichier' } });
          frame.on('select', function(){
            var att = frame.state().get('selection').first().toJSON();
            row.querySelector('.kex-id').value = att.id;
            row.querySelector('.kex-file').textContent = att.filename || att.title || ('#'+att.id);
            var titleInput = row.querySelector('.kex-title');
            if (titleInput && !titleInput.value) titleInput.value = (att.title || '').replace(/\.[a-z0-9]+$/i,'');
          });
          frame.open();
        });
        if (rm) rm.addEventListener('click', function(){
          if (wrap.querySelectorAll('.kex-row').length > 1) row.remove();
          else { row.querySelector('.kex-id').value=''; row.querySelector('.kex-file').textContent='Aucun fichier'; row.querySelector('.kex-title').value=''; }
        });
      }

      wrap.querySelectorAll('.kex-row').forEach(bindRow);

      document.getElementById('kex-add-row').addEventListener('click', function(){
        var tpl = document.getElementById('kex-row-tpl');
        var div = document.createElement('div');
        div.innerHTML = tpl.innerHTML.trim();
        var row = div.firstChild;
        wrap.appendChild(row);
        bindRow(row);
      });

      // Recalcule du solde en direct
      var iT = document.querySelector('input[name="kophi_montant"]');
      var iA = document.querySelector('input[name="kophi_acompte"]');
      var out = document.getElementById('kex-solde-val');
      function recalc(){
        var s = (parseFloat(iT.value)||0) - (parseFloat(iA.value)||0);
        if (s < 0) s = 0;
        out.textContent = s.toLocaleString('fr-FR') + ' F CFA';
      }
      if (iT && iA && out){ iT.addEventListener('input', recalc); iA.addEventListener('input', recalc); }

      // Marquer un commentaire client comme traité / nouveau
      var fbBox = document.querySelector('.kex-fb-box');
      if (fbBox) {
        var postId = fbBox.dataset.postid, nonce = fbBox.dataset.nonce;
        fbBox.addEventListener('click', function(e){
          var btn = e.target.closest('.kex-fb-toggle-status');
          if (!btn) return;
          var item = btn.closest('.kex-fb-item');
          var done = item.classList.contains('kex-fb-done');
          var newStatus = done ? 'nouveau' : 'traite';
          btn.disabled = true;
          var data = new FormData();
          data.append('action', 'ksm_listen_feedback_status');
          data.append('nonce', nonce);
          data.append('post_id', postId);
          data.append('fid', item.dataset.fid);
          data.append('status', newStatus);
          fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
            .then(function(r){ return r.json(); })
            .then(function(res){
              btn.disabled = false;
              if (res && res.success) {
                item.classList.toggle('kex-fb-done', newStatus === 'traite');
                btn.textContent = newStatus === 'traite' ? '↺ Remettre en nouveau' : '✅ Marquer traité';
              }
            })
            .catch(function(){ btn.disabled = false; });
        });
      }
    })();

    function kexCopy(){
      var f = document.getElementById('kex-link');
      f.select(); f.setSelectionRange(0, 99999);
      navigator.clipboard ? navigator.clipboard.writeText(f.value) : document.execCommand('copy');
      var b = event.target; var old = b.textContent; b.textContent = '✅ Copié !';
      setTimeout(function(){ b.textContent = old; }, 1500);
    }
    </script>
    <?php
}

/**
 * HTML d'une ligne de titre (réutilisé par PHP et par le template JS).
 */
function ksm_listen_row_html($title, $att_id) {
    $att_id = (int) $att_id;
    $fname  = $att_id ? (basename(get_attached_file($att_id)) ?: ('#' . $att_id)) : 'Aucun fichier';
    ?>
    <div class="kex-row">
      <input type="text" class="kex-title" name="kophi_track_title[]" placeholder="Titre du son" value="<?= esc_attr($title) ?>">
      <input type="hidden" class="kex-id" name="kophi_track_id[]" value="<?= esc_attr($att_id) ?>">
      <span class="kex-file"><?= esc_html($fname) ?></span>
      <button type="button" class="button kex-pick">🎵 Fichier</button>
      <button type="button" class="button kex-remove" title="Retirer">✕</button>
    </div>
    <?php
}

// Charger wp.media sur les écrans d'édition concernés
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
    $screen = get_current_screen();
    if ($screen && in_array($screen->post_type, KSM_LISTEN_TYPES, true)) {
        wp_enqueue_media();
    }
});

// ================================================================
// 3. SAUVEGARDE
// ================================================================
add_action('save_post', 'ksm_listen_save_metabox', 10, 2);
function ksm_listen_save_metabox($post_id, $post) {
    if (!isset($_POST['ksm_listen_nonce']) || !wp_verify_nonce($_POST['ksm_listen_nonce'], 'ksm_listen_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!in_array($post->post_type, KSM_LISTEN_TYPES, true)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Titres
    $titles = isset($_POST['kophi_track_title']) ? (array) $_POST['kophi_track_title'] : [];
    $ids    = isset($_POST['kophi_track_id'])    ? (array) $_POST['kophi_track_id']    : [];
    $tracks = [];
    foreach ($ids as $i => $aid) {
        $aid = (int) $aid;
        if (!$aid) continue;
        $title = isset($titles[$i]) ? sanitize_text_field(wp_unslash($titles[$i])) : '';
        if ($title === '') $title = get_the_title($aid);
        $tracks[] = ['title' => $title, 'id' => $aid];
    }
    update_post_meta($post_id, '_kophi_ecoute_tracks', $tracks);

    // Montants
    if (isset($_POST['kophi_montant'])) {
        update_post_meta($post_id, '_kophi_montant', (float) $_POST['kophi_montant']);
    }
    if (isset($_POST['kophi_acompte'])) {
        update_post_meta($post_id, '_kophi_acompte', (float) $_POST['kophi_acompte']);
    }

    // Activation
    $active = isset($_POST['ksm_listen_active']) && $_POST['ksm_listen_active'] === '1' ? '1' : '0';
    update_post_meta($post_id, '_kophi_ecoute_active', $active);

    // Mode de téléchargement
    $dl = isset($_POST['ksm_listen_dl_mode']) ? sanitize_text_field($_POST['ksm_listen_dl_mode']) : 'solde';
    if (!in_array($dl, ['jamais', 'solde', 'toujours'], true)) $dl = 'solde';
    update_post_meta($post_id, '_kophi_ecoute_dl_mode', $dl);

    // Date d'expiration du lien (optionnelle — format Y-m-d)
    $expire = isset($_POST['ksm_listen_expire']) ? sanitize_text_field($_POST['ksm_listen_expire']) : '';
    if ($expire && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire)) $expire = '';
    update_post_meta($post_id, '_kophi_ecoute_expire', $expire);

    // Pochette de la page d'écoute (ID de pièce jointe image, facultatif)
    if (isset($_POST['ksm_listen_cover'])) {
        $cover_id = (int) $_POST['ksm_listen_cover'];
        if ($cover_id && wp_attachment_is_image($cover_id)) update_post_meta($post_id, '_kophi_ecoute_cover', $cover_id);
        else delete_post_meta($post_id, '_kophi_ecoute_cover');
    }

    // Générer le jeton dès qu'il y a au moins un titre
    if (!empty($tracks)) ksm_listen_get_token($post_id);
}

// ================================================================
// 4. FRONT — page d'écoute + streaming protégé
// ================================================================
add_action('template_redirect', 'ksm_listen_router');
function ksm_listen_router() {
    if (isset($_GET['ksm_stream'])) {
        ksm_listen_stream((string) $_GET['ksm_stream'], isset($_GET['i']) ? (int) $_GET['i'] : 0);
        exit;
    }
    if (isset($_GET['ksm_listen'])) {
        ksm_listen_render_page((string) $_GET['ksm_listen']);
        exit;
    }
    // Téléchargement (débloqué uniquement si soldé)
    if (isset($_GET['ksm_dl'])) {
        ksm_listen_download((string) $_GET['ksm_dl'], isset($_GET['i']) ? (int) $_GET['i'] : 0);
        exit;
    }
}

/**
 * Vérifie l'accès et retourne [$post, $tracks] ou affiche une erreur + exit.
 */
function ksm_listen_guard(string $token) {
    $post = ksm_listen_find_by_token($token);
    if (!$post) {
        status_header(404);
        wp_die('Lien d\'écoute introuvable ou expiré.', 'Écoute KOPHI', ['response' => 404]);
    }
    if (get_post_meta($post->ID, '_kophi_ecoute_active', true) === '0') {
        status_header(403);
        wp_die('Cette écoute a été désactivée par le studio.', 'Écoute KOPHI', ['response' => 403]);
    }
    if (ksm_listen_is_expired($post->ID)) {
        $d = ksm_listen_expire_date($post->ID);
        $d_fmt = $d ? date_i18n('d/m/Y', strtotime($d)) : '';
        status_header(410); // Gone
        wp_die(
            'Ce lien d\'écoute a expiré' . ($d_fmt ? " le {$d_fmt}" : '') . '.<br>Contactez le studio pour obtenir un nouveau lien.',
            'Lien expiré — KOPHI',
            ['response' => 410]
        );
    }
    $tracks = ksm_listen_get_tracks($post->ID);
    if (empty($tracks)) {
        status_header(404);
        wp_die('Aucun titre disponible pour le moment.', 'Écoute KOPHI', ['response' => 404]);
    }
    return [$post, $tracks];
}

/**
 * Streaming audio protégé, avec support des requêtes Range (lecture/seek HTML5).
 */
function ksm_listen_stream(string $token, int $index) {
    list($post, $tracks) = ksm_listen_guard($token);
    if (!isset($tracks[$index])) { status_header(404); exit; }

    $file = get_attached_file($tracks[$index]['id']);
    if (!$file || !file_exists($file)) { status_header(404); exit; }

    $type = get_post_mime_type($tracks[$index]['id']) ?: 'audio/mpeg';
    $size = filesize($file);

    // Empêche la mise en cache publique et le téléchargement « propre »
    nocache_headers();
    header('Content-Type: ' . $type);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="ecoute.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
    header('X-Content-Type-Options: nosniff');

    $start = 0;
    $end   = $size - 1;

    // Support Range pour permettre le seek dans le lecteur
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int) $m[1];
        if ($m[2] !== '') $end   = (int) $m[2];
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            status_header(416);
            exit;
        }
        status_header(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    header('Content-Length: ' . ($end - $start + 1));

    // Vider tout buffer de sortie WordPress
    while (ob_get_level()) ob_end_clean();

    $fh = fopen($file, 'rb');
    if (!$fh) { status_header(500); exit; }
    fseek($fh, $start);
    $remaining = $end - $start + 1;
    $chunk = 8192;
    while ($remaining > 0 && !feof($fh)) {
        $read = ($remaining > $chunk) ? $chunk : $remaining;
        echo fread($fh, $read);
        flush();
        $remaining -= $read;
    }
    fclose($fh);
    exit;
}

/**
 * Téléchargement — autorisé UNIQUEMENT si le solde est à 0.
 */
function ksm_listen_download(string $token, int $index) {
    list($post, $tracks) = ksm_listen_guard($token);
    if (!ksm_listen_dl_allowed($post->ID)) {
        status_header(402); // Payment Required / non disponible
        $msg = (ksm_listen_dl_mode($post->ID) === 'jamais')
            ? 'Le téléchargement n\'est pas disponible pour cette écoute.'
            : 'Le téléchargement sera débloqué après le règlement du solde.';
        wp_die($msg, 'Écoute KOPHI', ['response' => 402]);
    }
    if (!isset($tracks[$index])) { status_header(404); exit; }
    $file = get_attached_file($tracks[$index]['id']);
    if (!$file || !file_exists($file)) { status_header(404); exit; }

    $name = sanitize_file_name($tracks[$index]['title'] . '.' . pathinfo($file, PATHINFO_EXTENSION));
    nocache_headers();
    header('Content-Type: ' . (get_post_mime_type($tracks[$index]['id']) ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    while (ob_get_level()) ob_end_clean();
    readfile($file);
    exit;
}

/**
 * Pochette de l'écoute : image choisie dans la boîte « Écoute client », sinon
 * image mise en avant du post, sinon '' (une pochette générée est alors affichée).
 */
function ksm_listen_cover_url(int $post_id, string $size = 'large'): string {
    $aid = (int) get_post_meta($post_id, '_kophi_ecoute_cover', true);
    if (!$aid) $aid = (int) get_post_thumbnail_id($post_id);
    if (!$aid) return '';
    $src = wp_get_attachment_image_src($aid, $size);
    return $src ? (string) $src[0] : '';
}

/**
 * Page publique d'écoute (HTML autonome, aux couleurs du studio).
 * v4.8.0 : présentation « lecteur de streaming » — grande pochette, liste des
 * titres, barre de lecture fixe avec enchaînement automatique (lecteur unifié).
 */
function ksm_listen_render_page(string $token) {
    list($post, $tracks) = ksm_listen_guard($token);

    $solde      = ksm_listen_get_solde($post->ID);
    $total      = (float) get_post_meta($post->ID, '_kophi_montant', true);
    $paid       = ($solde <= 0 && $total > 0);
    $client     = get_post_meta($post->ID, '_kophi_client_nom', true);
    $dl_allowed = ksm_listen_dl_allowed($post->ID);
    $dl_mode    = ksm_listen_dl_mode($post->ID);
    $count      = count($tracks);
    $expire     = ksm_listen_expire_date($post->ID);
    $wa_num     = preg_replace('/[^0-9]/', '', defined('KOPHI_WA') ? KOPHI_WA : '2250503404389');
    $titre      = get_the_title($post->ID);
    $cover      = ksm_listen_cover_url($post->ID, 'large');
    $cover_sm   = ksm_listen_cover_url($post->ID, 'thumbnail');
    $ver        = defined('KOPHI_VERSION') ? KOPHI_VERSION : '1';
    $len        = function_exists('mb_strlen') ? mb_strlen($titre) : strlen($titre);
    $title_cls  = $len <= 16 ? 'xl' : ($len <= 30 ? 'lg' : ($len <= 48 ? 'md' : 'sm'));

    $wa_solde = rawurlencode(
        "Bonjour KOPHI'S MUSIC STUDIO 🎙\nJe souhaite régler le solde de " .
        number_format($solde, 0, ',', ' ') . " F CFA pour mon son : " . $titre
    );

    // La page dépend du jeton, du solde et des remarques : jamais mise en cache.
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0b0a12">
<title><?= esc_html($titre) ?> — Écoute · KOPHI'S MUSIC STUDIO</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= esc_url(KOPHI_URL . 'assets/css/kophi-player.css?ver=' . $ver) ?>">
<style>
  :root{--gold:#E8B84B;--gold2:#F5CC6A;--violet:#8B3FD9;--violet2:#A855F7;--ink:#F0ECF8;--mut:#A7A1BF;--bg:#0b0a12;--tint:#3a1c66;
    --line:rgba(255,255,255,.09);--max:1080px;--pad:40px}
  *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  html{-webkit-text-size-adjust:100%}
  body{font-family:'Inter',-apple-system,'Segoe UI',sans-serif;color:var(--ink);background:var(--bg);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}
  button{font:inherit;color:inherit;background:none;border:0;cursor:pointer}
  a{color:inherit;text-decoration:none}
  :focus-visible{outline:2px solid var(--gold);outline-offset:2px;border-radius:4px}
  .sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}

  /* ── En-tête : pochette + titre ── */
  .hero{position:relative;overflow:hidden;background:var(--tint);transition:background 1s}
  .hero-bg{position:absolute;inset:-60px;background-size:cover;background-position:center;filter:blur(60px) saturate(1.4);opacity:.45;transform:scale(1.2)}
  .hero::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.08),rgba(0,0,0,.55))}
  .hero-in{position:relative;z-index:1;max-width:var(--max);margin:0 auto;padding:64px var(--pad) 28px;display:flex;align-items:flex-end;gap:32px;animation:up .55s cubic-bezier(.2,.8,.2,1) both}
  .brand{position:absolute;z-index:2;top:18px;left:var(--pad);display:inline-flex;align-items:center;gap:9px;font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;
    padding:7px 13px;border-radius:999px;background:rgba(0,0,0,.4);backdrop-filter:blur(8px)}
  .brand i{width:8px;height:8px;border-radius:50%;background:linear-gradient(135deg,var(--violet2),var(--gold))}
  .cover{flex:0 0 auto;width:clamp(170px,22vw,248px);aspect-ratio:1/1;border-radius:10px;overflow:hidden;position:relative;box-shadow:0 10px 60px rgba(0,0,0,.6);
    background:linear-gradient(145deg,var(--violet) 0%,#5b2aa0 45%,var(--gold) 130%)}
  .cover img{width:100%;height:100%;object-fit:cover;display:block}
  .cover-gen{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;color:#fff}
  .cover-gen svg{width:34%;height:auto;filter:drop-shadow(0 6px 18px rgba(0,0,0,.35))}
  .cover-gen b{font-size:10px;letter-spacing:.3em;text-transform:uppercase;opacity:.85}
  .bars{display:flex;align-items:flex-end;gap:4px;height:26px}
  .bars span{width:4px;border-radius:2px;background:rgba(255,255,255,.8);height:40%}
  .bars span:nth-child(2){height:75%}.bars span:nth-child(3){height:100%}.bars span:nth-child(4){height:55%}.bars span:nth-child(5){height:85%}.bars span:nth-child(6){height:35%}.bars span:nth-child(7){height:65%}
  .kp-is-playing .bars span{animation:eq .8s ease-in-out infinite alternate;transform-origin:bottom}
  .kp-is-playing .bars span:nth-child(2n){animation-delay:.2s}.kp-is-playing .bars span:nth-child(3n){animation-delay:.35s}
  .htext{flex:1;min-width:0}
  .kind{display:block;font-size:14px;font-weight:700;margin-bottom:6px}
  h1{font-weight:900;letter-spacing:-.045em;line-height:1;margin-bottom:18px;overflow-wrap:anywhere;text-wrap:balance;text-shadow:0 2px 30px rgba(0,0,0,.25)}
  h1.xl{font-size:clamp(40px,8vw,96px)} h1.lg{font-size:clamp(36px,6vw,72px)} h1.md{font-size:clamp(30px,4.4vw,54px)} h1.sm{font-size:clamp(24px,3.2vw,38px);letter-spacing:-.03em;line-height:1.1}
  .by{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;font-size:14.5px;color:rgba(255,255,255,.8)}
  .by b{color:#fff;font-weight:800}.by .d{opacity:.7}

  /* ── Corps ── */
  .body{background:linear-gradient(180deg,color-mix(in srgb,var(--tint) 60%,var(--bg)) 0,var(--bg) 280px);padding-bottom:56px;transition:background 1s}
  .wrap{max-width:var(--max);margin:0 auto;padding:0 var(--pad)}
  .actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding-top:24px;padding-bottom:20px}
  .play-main{width:60px;height:60px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#141005;
    background:linear-gradient(135deg,var(--gold2),var(--gold));box-shadow:0 10px 30px rgba(232,184,75,.4);transition:transform .15s}
  .play-main:hover{transform:scale(1.07)}.play-main:active{transform:scale(.96)}
  .play-main svg{width:28px;height:28px;fill:currentColor}
  .play-main .i-pause,.kp-is-playing .play-main .i-play{display:none}.kp-is-playing .play-main .i-pause{display:block}
  .chip{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;padding:7px 13px;border-radius:999px;color:rgba(255,255,255,.82);
    background:rgba(255,255,255,.07);border:1px solid var(--line)}
  .chip.ok{color:#3ddc97;border-color:rgba(61,220,151,.3);background:rgba(61,220,151,.08)}
  .chip.warn{color:var(--gold2);border-color:rgba(232,184,75,.3);background:rgba(232,184,75,.08)}
  .chip svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .chips{display:flex;flex-wrap:wrap;gap:8px;margin-left:auto}

  /* ── Liste des titres ── */
  .thead,.row{display:grid;grid-template-columns:40px minmax(0,1fr) auto 56px;gap:0 14px;align-items:center}
  .thead{padding:0 14px 9px;border-bottom:1px solid var(--line);margin-bottom:10px;font-size:12px;font-weight:600;color:var(--mut)}
  .thead span:first-child{text-align:center}.thead span:last-child{text-align:right;padding-right:6px}
  .track{position:relative;border-radius:10px;scroll-margin:20px 0 120px}
  .track.kp-current{background:rgba(255,255,255,.07)}
  .track.kp-current::before{content:'';position:absolute;left:0;bottom:0;height:2px;width:var(--kp-progress,0%);border-radius:2px;background:linear-gradient(90deg,var(--violet2),var(--gold))}
  .row{padding:7px 14px;min-height:60px;border-radius:10px;transition:background .15s}
  .track:not(.kp-current) .row:hover{background:rgba(255,255,255,.08)}
  .row-main{display:contents;text-align:left}
  .num{position:relative;height:40px;display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--mut);font-variant-numeric:tabular-nums}
  .num svg{position:absolute;width:18px;height:18px;fill:#fff;display:none}
  .eq{position:absolute;display:none;align-items:flex-end;gap:2px;height:15px}
  .eq i{width:3px;height:100%;border-radius:1px;background:var(--gold);animation:eq .7s ease-in-out infinite alternate;transform-origin:bottom}
  .eq i:nth-child(2){animation-delay:.2s}.eq i:nth-child(3){animation-delay:.1s}.eq i:nth-child(4){animation-delay:.3s}
  @keyframes eq{from{transform:scaleY(.2)}to{transform:scaleY(1)}}
  .spin{position:absolute;display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:rot .7s linear infinite}
  @keyframes rot{to{transform:rotate(360deg)}}
  .track.kp-current .num b{color:var(--gold)}
  .num b{font-weight:500}
  @media (hover:hover){
    .row:hover .num b{display:none}.row:hover .num .i-play{display:block}
    .track.kp-playing .row:hover .eq{display:none}.track.kp-playing .row:hover .num .i-play{display:none}.track.kp-playing .row:hover .num .i-pause{display:block}
  }
  .track.kp-playing .num b{display:none}.track.kp-playing .eq{display:flex}
  .track.kp-loading .num b,.track.kp-loading .eq,.track.kp-loading .num svg{display:none!important}.track.kp-loading .spin{display:block}
  .tinfo{min-width:0;cursor:pointer}
  .ttitle{font-size:16px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .track.kp-current .ttitle{color:var(--gold2)}
  .track.kp-error .ttitle{color:#ff7a90}
  .tsub{font-size:13px;color:var(--mut);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tools{display:flex;align-items:center;gap:4px}
  .tool{position:relative;width:38px;height:38px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:var(--mut);transition:color .15s,background .15s}
  .tool:hover,.tool[aria-expanded="true"]{color:#fff;background:rgba(255,255,255,.1)}
  .tool svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .badge{position:absolute;top:1px;right:0;background:var(--gold);color:#1a1205;font-weight:800;font-size:10px;border-radius:999px;min-width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px}
  .tdur{font-size:14px;color:var(--mut);text-align:right;font-variant-numeric:tabular-nums;padding-right:6px}

  /* ── Remarques client ── */
  .fb{margin:2px 14px 12px 68px;padding:14px 16px;border-radius:12px;background:rgba(255,255,255,.05);border:1px solid var(--line)}
  .fb[hidden]{display:none}
  .fb h3{font-size:13px;font-weight:700;margin-bottom:10px}
  .fb-list{display:flex;flex-direction:column;gap:8px;margin-bottom:10px}
  .fb-item{background:rgba(255,255,255,.05);border-radius:10px;padding:9px 12px}
  .fb-text{font-size:13.5px;line-height:1.5;color:#E5E0F2;white-space:pre-wrap;overflow-wrap:anywhere}
  .fb-date{font-size:11px;color:var(--mut);margin-top:4px}
  .fb-input{width:100%;resize:vertical;min-height:72px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.14);border-radius:10px;color:var(--ink);font:inherit;font-size:16px;padding:10px 12px;line-height:1.5}
  .fb-input:focus{outline:none;border-color:var(--gold)}
  .fb-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}
  .fb-actions{display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}
  .fb-send{background:linear-gradient(135deg,var(--violet),var(--gold));color:#fff;border-radius:999px;padding:10px 20px;font-size:13.5px;font-weight:700}
  .fb-send:disabled{opacity:.6;cursor:default}
  .fb-sent{color:#3ddc97;font-size:13px;font-weight:600}.fb-err{color:#ff7a90;font-size:13px}
  .fb-count{font-size:11.5px;color:var(--mut);margin-left:auto}

  .note{display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.55;color:var(--gold2);background:rgba(232,184,75,.07);border:1px solid rgba(232,184,75,.22);border-radius:12px;padding:12px 14px;margin-top:28px}
  .note svg{flex-shrink:0;width:16px;height:16px;margin-top:2px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .solde{margin-top:18px;border-radius:16px;padding:24px;display:flex;align-items:center;gap:24px;flex-wrap:wrap;background:linear-gradient(135deg,rgba(139,63,217,.18),rgba(232,184,75,.1));border:1px solid rgba(232,184,75,.3)}
  .solde .lbl{font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.12em;font-weight:700}
  .solde .val{font-size:40px;font-weight:900;letter-spacing:-.03em;color:var(--gold);line-height:1.1;margin:4px 0}
  .solde .hint{font-size:13.5px;color:#D9D2EC}
  .pay{margin-left:auto;display:inline-flex;align-items:center;gap:10px;padding:15px 26px;border-radius:999px;font-size:15px;font-weight:800;background:#25D366;color:#06270f;box-shadow:0 10px 26px rgba(37,211,102,.3);transition:transform .15s}
  .pay:hover{transform:translateY(-2px)}
  .pay svg{width:20px;height:20px;fill:currentColor}
  .foot{text-align:center;font-size:12.5px;color:#7d7896;margin-top:40px;line-height:1.9}
  .foot a{color:var(--gold);font-weight:600}
  @keyframes up{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}

  @media (max-width:720px){
    :root{--pad:16px}
    .hero-in{flex-direction:column;align-items:flex-start;gap:20px;padding:72px var(--pad) 20px}
    .cover{width:min(62vw,250px);align-self:center}
    h1,h1.xl,h1.lg,h1.md{font-size:clamp(28px,8.6vw,42px);letter-spacing:-.03em;line-height:1.05;margin-bottom:12px}
    .by{font-size:13.5px}
    .actions{padding-top:16px}
    .play-main{order:9;margin-left:auto;width:54px;height:54px}
    .chips{margin-left:0;flex:1}
    .thead{display:none}
    .wrap.list{padding:0 6px}
    .row{grid-template-columns:28px minmax(0,1fr) auto 44px;gap:0 8px;padding:6px 8px}
    .ttitle{font-size:15px}
    .fb{margin-left:10px;margin-right:10px}
    .solde{padding:20px}.pay{margin-left:0;width:100%;justify-content:center}
  }
  @media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
</style>
</head>
<body oncontextmenu="return false">
  <header class="hero">
    <?php if ($cover): ?><div class="hero-bg" style="background-image:url('<?= esc_url($cover_sm ?: $cover) ?>')"></div><?php endif; ?>
    <div class="brand"><i></i>KOPHI'S MUSIC STUDIO</div>
    <div class="hero-in">
      <div class="cover">
        <?php if ($cover): ?>
          <img src="<?= esc_url($cover) ?>" alt="" id="ksl-cover" data-sample="<?= esc_url($cover_sm ?: $cover) ?>">
        <?php else: ?>
          <div class="cover-gen" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg>
            <div class="bars"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <b>Écoute privée</b>
          </div>
        <?php endif; ?>
      </div>
      <div class="htext">
        <span class="kind">Espace d'écoute privé</span>
        <h1 class="<?= esc_attr($title_cls) ?>"><?= esc_html($titre) ?></h1>
        <div class="by">
          <?php if ($client): ?><b><?= esc_html($client) ?></b><span class="d">•</span><?php endif; ?>
          <span><?= (int) $count ?> titre<?= $count > 1 ? 's' : '' ?><span id="ksl-total"></span></span>
          <?php if ($expire): ?><span class="d">•</span><span>Accès jusqu'au <?= esc_html(date_i18n('d/m/Y', strtotime($expire))) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <main class="body">
    <div class="wrap actions">
      <button type="button" class="play-main" id="ksl-play" aria-label="Lecture / pause">
        <svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
        <svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
      </button>
      <div class="chips">
        <?php if ($dl_allowed && $dl_mode === 'toujours'): ?>
          <span class="chip ok"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>Téléchargement disponible</span>
        <?php elseif ($dl_allowed && $paid): ?>
          <span class="chip ok"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>Solde réglé — téléchargement débloqué</span>
        <?php elseif ($dl_allowed): ?>
          <span class="chip ok"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>Téléchargement disponible</span>
        <?php elseif ($dl_mode === 'jamais'): ?>
          <span class="chip"><svg viewBox="0 0 24 24"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg>Écoute seule</span>
        <?php elseif ($solde > 0): ?>
          <span class="chip warn"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Téléchargement après règlement du solde</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="wrap list">
      <div class="thead" aria-hidden="true"><span>#</span><span>Titre</span><span></span><span>Durée</span></div>
      <?php foreach ($tracks as $i => $t):
          $src = ksm_listen_stream_link($token, $i);
          $fb  = ksm_listen_get_feedback_for_track($post->ID, $i); ?>
      <div class="track" data-kp-id="t<?= (int) $i ?>" data-kp-probe
           data-kp-url="<?= esc_url($src) ?>" data-kp-title="<?= esc_attr($t['title']) ?>"
           data-kp-sub="<?= esc_attr($client ?: "KOPHI'S MUSIC STUDIO") ?>" data-kp-cover="<?= esc_url($cover_sm) ?>">
        <div class="row">
          <button type="button" class="row-main" data-kp-play aria-label="Lire <?= esc_attr($t['title']) ?>">
            <span class="num">
              <b><?= (int) $i + 1 ?></b>
              <svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
              <svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
              <span class="eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span class="spin" aria-hidden="true"></span>
            </span>
            <span class="tinfo">
              <span class="ttitle" style="display:block"><?= esc_html($t['title']) ?></span>
              <span class="tsub" style="display:block"><?= $client ? esc_html($client) : 'Version d\'écoute' ?></span>
            </span>
          </button>
          <div class="tools">
            <button type="button" class="tool fb-toggle" aria-expanded="false" aria-controls="ksl-fb-<?= (int) $i ?>" title="Laisser une remarque sur ce titre" aria-label="Laisser une remarque sur <?= esc_attr($t['title']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              <?php if ($fb): ?><span class="badge"><?= count($fb) ?></span><?php endif; ?>
            </button>
            <?php if ($dl_allowed): ?>
            <a class="tool" href="<?= esc_url(add_query_arg(['ksm_dl' => $token, 'i' => $i], home_url('/'))) ?>" title="Télécharger" aria-label="Télécharger <?= esc_attr($t['title']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
            </a>
            <?php endif; ?>
          </div>
          <div class="tdur kp-dur">—</div>
        </div>

        <!-- Remarques / instructions de retouche pour ce titre -->
        <div class="fb" id="ksl-fb-<?= (int) $i ?>" hidden>
          <h3>Vos remarques sur « <?= esc_html($t['title']) ?> »</h3>
          <div class="fb-list">
            <?php foreach ($fb as $f): ?>
              <div class="fb-item">
                <div class="fb-text"><?= esc_html($f['text'] ?? '') ?></div>
                <div class="fb-date"><?= esc_html(date_i18n('d/m/Y H:i', (int) ($f['date'] ?? 0))) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <label class="sr" for="ksl-fb-in-<?= (int) $i ?>">Votre remarque</label>
          <textarea class="fb-input" id="ksl-fb-in-<?= (int) $i ?>" maxlength="2000" rows="3"
            placeholder="Ex : baisser un peu la voix au refrain, couper les 10 dernières secondes…"></textarea>
          <input type="text" class="fb-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <div class="fb-actions">
            <button type="button" class="fb-send" data-track="<?= (int) $i ?>">Envoyer au studio</button>
            <button type="button" class="chip fb-stamp" title="Insérer la position de lecture en cours">+ Position actuelle</button>
            <span class="fb-sent" hidden>Envoyé au studio ✓</span>
            <span class="fb-err" role="alert" hidden></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="note"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>Version d'écoute à usage privé. Merci de ne pas partager ni diffuser cet enregistrement.</span></div>

      <?php if ($solde > 0): ?>
      <div class="solde">
        <div>
          <div class="lbl">Solde restant à régler</div>
          <div class="val"><?= number_format($solde, 0, ',', ' ') ?> F</div>
          <div class="hint"><?= $dl_mode === 'solde' ? 'Réglez le solde pour débloquer le téléchargement de vos fichiers.' : 'Réglez le solde pour finaliser votre projet.' ?></div>
        </div>
        <a class="pay" href="https://wa.me/<?= esc_attr($wa_num) ?>?text=<?= $wa_solde ?>" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.300 1.200-1.800 1.200-.5.1-1 .2-3.300-.7-2.700-1.100-4.500-3.900-4.600-4.100-.1-.2-1.100-1.500-1.100-2.800 0-1.300.7-2 .9-2.200.2-.3.5-.3.700-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.2-.3.3-.1.6.2.300.7 1.200 1.600 1.900 1.100.9 2 1.200 2.300 1.400.3.1.5.1.6-.1l.8-1c.2-.2.4-.2.6-.1l1.900.9c.3.1.5.2.5.3.1.200.1.700-.2 1.100z"/></svg>
          Payer le solde
        </a>
      </div>
      <?php endif; ?>

      <div class="foot">
        KOPHI'S MUSIC STUDIO — Abidjan<br>
        <a href="https://wa.me/<?= esc_attr($wa_num) ?>" target="_blank" rel="noopener">Nous contacter sur WhatsApp</a>
      </div>
    </div>
  </main>

<script src="<?= esc_url(KOPHI_URL . 'assets/js/kophi-player.js?ver=' . $ver) ?>"></script>
<script>
(function(){
  var AJAX  = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
  var TOKEN = <?= wp_json_encode($token) ?>;
  var KP = window.KophiPlayer;

  // Bouton principal + clic sur le titre
  document.getElementById('ksl-play').addEventListener('click', function(){ if (KP) KP.toggle(); });

  // Durée totale (« 3 titres, 7 min 12 s ») dès que toutes les durées sont connues
  function total(){
    var sum = 0, ok = true;
    document.querySelectorAll('.track').forEach(function(t){ var d = parseFloat(t.getAttribute('data-kp-duration')); if (d) sum += d; else ok = false; });
    if (!ok || !sum) return;
    sum = Math.round(sum); var m = Math.floor(sum/60), s = sum%60;
    document.getElementById('ksl-total').textContent = ', ' + (m ? m + ' min ' + ('0'+s).slice(-2) + ' s' : s + ' s');
  }
  document.addEventListener('kophi-player:durations', total);

  // Teinte de l'en-tête tirée de la pochette
  (function(){
    var img = document.getElementById('ksl-cover'); if (!img) return;
    var probe = new Image();
    probe.onload = function(){
      try {
        var c = document.createElement('canvas'); c.width = c.height = 40;
        var x = c.getContext('2d'); x.drawImage(probe, 0, 0, 40, 40);
        var d = x.getImageData(0, 0, 40, 40).data, r = 0, g = 0, b = 0, n = 0;
        for (var i = 0; i < d.length; i += 4) {
          var mx = Math.max(d[i], d[i+1], d[i+2]), mn = Math.min(d[i], d[i+1], d[i+2]), w = (mx ? (mx-mn)/mx : 0) + .05;
          r += d[i]*w; g += d[i+1]*w; b += d[i+2]*w; n += w;
        }
        r /= n; g /= n; b /= n;
        var l = (Math.max(r,g,b) + Math.min(r,g,b)) / 510, k = l > 0 ? .3 / l : 1;
        var f = function(v){ return Math.round(Math.max(0, Math.min(255, v*k))); };
        document.documentElement.style.setProperty('--tint', 'rgb(' + f(r) + ',' + f(g) + ',' + f(b) + ')');
      } catch(e) {}
    };
    probe.src = img.getAttribute('data-sample') || img.src;
  })();

  // Remarques / instructions par titre
  document.querySelectorAll('.track').forEach(function(track){
    var toggle = track.querySelector('.fb-toggle'), panel = track.querySelector('.fb');
    var input = panel.querySelector('.fb-input'), hp = panel.querySelector('.fb-hp'), send = panel.querySelector('.fb-send');
    var sent = panel.querySelector('.fb-sent'), err = panel.querySelector('.fb-err'), list = panel.querySelector('.fb-list');
    var stamp = panel.querySelector('.fb-stamp');

    toggle.addEventListener('click', function(){
      var open = panel.hidden;
      panel.hidden = !open; toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) input.focus();
    });
    // Insère « [1:23] » : la position de lecture, si ce titre est en cours
    stamp.addEventListener('click', function(){
      var cur = track.style.getPropertyValue('--kp-progress'), d = parseFloat(track.getAttribute('data-kp-duration'));
      var t = (cur && d) ? parseFloat(cur) / 100 * d : 0;
      var txt = '[' + (KP ? KP.fmt(t) : '0:00') + '] ';
      input.value = input.value + (input.value && !/\s$/.test(input.value) ? ' ' : '') + txt; input.focus();
    });

    send.addEventListener('click', function(){
      var msg = (input.value || '').trim();
      err.hidden = true;
      if (!msg) { err.textContent = 'Écrivez un message avant d\'envoyer.'; err.hidden = false; input.focus(); return; }

      send.disabled = true; send.textContent = 'Envoi…';
      var data = new FormData();
      data.append('action', 'ksm_listen_add_comment');
      data.append('token', TOKEN);
      data.append('track', send.dataset.track);
      data.append('message', msg);
      data.append('hp', hp.value || '');

      fetch(AJAX, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(res){
          send.disabled = false; send.textContent = 'Envoyer au studio';
          if (res && res.success) {
            if (!(res.data && res.data.fake)) {          // piège à robots : succès factice, rien n'est ajouté
              var item = document.createElement('div'); item.className = 'fb-item';
              var a = document.createElement('div'); a.className = 'fb-text'; a.textContent = msg;
              var b = document.createElement('div'); b.className = 'fb-date'; b.textContent = (res.data && res.data.date) || '';
              item.appendChild(a); item.appendChild(b); list.insertBefore(item, list.firstChild);
              var badge = toggle.querySelector('.badge');
              if (!badge) { badge = document.createElement('span'); badge.className = 'badge'; badge.textContent = '0'; toggle.appendChild(badge); }
              badge.textContent = (parseInt(badge.textContent, 10) || 0) + 1;
            }
            input.value = '';
            sent.hidden = false; setTimeout(function(){ sent.hidden = true; }, 3000);
          } else {
            err.textContent = (res && res.data && res.data.message) || 'Erreur, réessayez.'; err.hidden = false;
          }
        })
        .catch(function(){
          send.disabled = false; send.textContent = 'Envoyer au studio';
          err.textContent = 'Erreur réseau, réessayez.'; err.hidden = false;
        });
    });
  });
})();
</script>
</body>
</html><?php
}

// ================================================================
// 4bis. COMMENTAIRES CLIENT — envoyés depuis la page d'écoute publique
// ================================================================
add_action('wp_ajax_nopriv_ksm_listen_add_comment', 'ksm_listen_ajax_add_comment');
add_action('wp_ajax_ksm_listen_add_comment', 'ksm_listen_ajax_add_comment');
function ksm_listen_ajax_add_comment() {
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $track = isset($_POST['track']) ? (int) $_POST['track'] : -1;
    $text  = isset($_POST['message']) ? (string) wp_unslash($_POST['message']) : '';
    $hp    = isset($_POST['hp']) ? trim((string) wp_unslash($_POST['hp'])) : '';

    // Piège à robots : si le champ caché est rempli, on simule un succès sans rien enregistrer
    if ($hp !== '') {
        wp_send_json_success(['fake' => true]);
    }

    $post = ksm_listen_find_by_token($token);
    if (!$post) wp_send_json_error(['message' => 'Lien invalide.']);
    if (get_post_meta($post->ID, '_kophi_ecoute_active', true) === '0') {
        wp_send_json_error(['message' => 'Cette écoute a été désactivée.']);
    }
    if (ksm_listen_is_expired($post->ID)) {
        wp_send_json_error(['message' => 'Ce lien a expiré.']);
    }
    $tracks = ksm_listen_get_tracks($post->ID);
    if ($track < 0 || !isset($tracks[$track])) {
        wp_send_json_error(['message' => 'Titre introuvable.']);
    }
    if (trim($text) === '') {
        wp_send_json_error(['message' => 'Message vide.']);
    }

    // Anti-spam simple : max 15 commentaires / 10 minutes pour ce lien
    $rl_key = 'ksm_fb_rl_' . md5($token);
    $count  = (int) get_transient($rl_key);
    if ($count >= 15) {
        wp_send_json_error(['message' => 'Trop de messages envoyés. Réessayez un peu plus tard.']);
    }
    set_transient($rl_key, $count + 1, 10 * MINUTE_IN_SECONDS);

    $entry = ksm_listen_add_feedback($post->ID, $track, $text);
    if (!$entry) wp_send_json_error(['message' => 'Message vide.']);

    // Notifier le studio par email (best-effort, ne bloque pas la réponse au client)
    ksm_listen_notify_new_feedback($post->ID, $track, $entry['text']);

    wp_send_json_success([
        'id'   => $entry['id'],
        'text' => esc_html($entry['text']),
        'date' => date_i18n('d/m/Y H:i', $entry['date']),
    ]);
}

// ================================================================
// 5. PAGE ADMIN DÉDIÉE — « 🎧 Écoute Client »
//    Tout au même endroit : upload des sons + lecteur + lien.
// ================================================================
add_action('admin_menu', 'ksm_listen_admin_menu', 20);
function ksm_listen_admin_menu() {
    $cap = current_user_can('administrator') ? 'manage_options' : 'kophi_manage_sessions';
    $new_n = ksm_listen_total_new_feedback();
    $label = '🎧 Écoute Client';
    if ($new_n) {
        $label .= " <span class='update-plugins count-{$new_n}' style='background:#d9364f'><span class='update-count'>{$new_n}</span></span>";
    }
    add_submenu_page(
        'kophi-dashboard',
        'Écoute Client',
        $label,
        $cap,
        'kophi-ecoute',
        'ksm_listen_admin_page'
    );
}

// Charger la médiathèque sur la page écoute
add_action('admin_enqueue_scripts', function ($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'kophi-ecoute') {
        wp_enqueue_media();
    }
});

// AJAX — enregistrer une écoute depuis la page dédiée
add_action('wp_ajax_ksm_listen_save', 'ksm_listen_ajax_save');
function ksm_listen_ajax_save() {
    check_ajax_referer('ksm_listen_ajax', 'nonce');
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Accès refusé']);
    }
    $titles = isset($_POST['title']) ? (array) $_POST['title'] : [];
    $ids    = isset($_POST['id'])    ? (array) $_POST['id']    : [];
    $tracks = [];
    foreach ($ids as $i => $aid) {
        $aid = (int) $aid;
        if (!$aid) continue;
        $title = isset($titles[$i]) ? sanitize_text_field(wp_unslash($titles[$i])) : '';
        if ($title === '') $title = get_the_title($aid);
        $tracks[] = ['title' => $title, 'id' => $aid];
    }
    update_post_meta($post_id, '_kophi_ecoute_tracks', $tracks);
    update_post_meta($post_id, '_kophi_montant', (float) ($_POST['montant'] ?? 0));
    update_post_meta($post_id, '_kophi_acompte', (float) ($_POST['acompte'] ?? 0));
    update_post_meta($post_id, '_kophi_ecoute_active', !empty($_POST['active']) ? '1' : '0');
    $dl = isset($_POST['dl_mode']) ? sanitize_text_field($_POST['dl_mode']) : 'solde';
    if (!in_array($dl, ['jamais', 'solde', 'toujours'], true)) $dl = 'solde';
    update_post_meta($post_id, '_kophi_ecoute_dl_mode', $dl);

    // Date d'expiration du lien (optionnelle — format Y-m-d)
    $expire = isset($_POST['expire']) ? sanitize_text_field($_POST['expire']) : '';
    if ($expire && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire)) $expire = '';
    update_post_meta($post_id, '_kophi_ecoute_expire', $expire);

    if (!empty($tracks)) ksm_listen_get_token($post_id);

    wp_send_json_success([
        'solde'   => ksm_listen_get_solde($post_id),
        'link'    => !empty($tracks) ? ksm_listen_link($post_id) : '',
        'count'   => count($tracks),
        'expire'  => $expire,
        'expired' => ksm_listen_is_expired($post_id),
    ]);
}

// AJAX — marquer un commentaire client comme traité / non traité
add_action('wp_ajax_ksm_listen_feedback_status', 'ksm_listen_ajax_feedback_status');
function ksm_listen_ajax_feedback_status() {
    check_ajax_referer('ksm_listen_ajax', 'nonce');
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $fid     = isset($_POST['fid']) ? sanitize_text_field($_POST['fid']) : '';
    $status  = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'traite';
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Accès refusé']);
    }
    $ok = ksm_listen_set_feedback_status($post_id, $fid, $status);
    if (!$ok) wp_send_json_error(['message' => 'Commentaire introuvable']);
    wp_send_json_success(['new_count' => ksm_listen_count_new_feedback($post_id)]);
}

function ksm_listen_admin_page() {
    // Candidats : sessions récentes (90 j) + tous les projets, plafonnés
    $sessions = get_posts([
        'post_type'      => 'kophi_session',
        'posts_per_page' => 40,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'date_query'     => [['after' => '90 days ago']],
    ]);
    $projets = get_posts([
        'post_type'      => 'kophi_projet',
        'posts_per_page' => 40,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    $items = array_merge($sessions, $projets);

    // S'assurer que toute session/projet avec des commentaires en attente apparaît toujours,
    // même si elle est plus ancienne que 90 jours (sinon le lien depuis le tableau de bord
    // pourrait pointer vers une carte absente de la liste).
    $with_fb = get_posts([
        'post_type'      => ['kophi_session', 'kophi_projet'],
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => '_kophi_ecoute_feedback', 'compare' => 'EXISTS']],
    ]);
    if ($with_fb) {
        $known_ids = wp_list_pluck($items, 'ID');
        foreach ($with_fb as $p) {
            if (!in_array($p->ID, $known_ids, true)) { $items[] = $p; $known_ids[] = $p->ID; }
        }
    }

    // Trier : commentaires non traités en premier, puis ceux qui ont déjà des titres
    usort($items, function ($a, $b) {
        $fa = ksm_listen_count_new_feedback($a->ID);
        $fb = ksm_listen_count_new_feedback($b->ID);
        if ($fa !== $fb) return $fb - $fa; // plus de nouveaux commentaires en premier
        $ta = (int) !empty(ksm_listen_get_tracks($a->ID));
        $tb = (int) !empty(ksm_listen_get_tracks($b->ID));
        return $tb - $ta;
    });

    $wa_num = preg_replace('/[^0-9]/', '', defined('KOPHI_WA') ? KOPHI_WA : '2250503404389');
    $nonce  = wp_create_nonce('ksm_listen_ajax');
    $type_labels = ['kophi_session' => '🎙 Session', 'kophi_projet' => '🎵 Projet'];
    ?>
    <style>
      .kex-fb-newpill{background:#d9364f;color:#fff;font-size:9.5px;font-weight:700;letter-spacing:.5px;
        border-radius:999px;padding:1px 7px;margin-left:4px;vertical-align:middle}
      .kex-fb-item.kex-fb-highlight{animation:kexFbPulse 1.6s ease-in-out 2;box-shadow:0 0 0 2px var(--k-gold-b)}
      @keyframes kexFbPulse{
        0%,100%{box-shadow:0 0 0 2px var(--k-gold-b)}
        50%{box-shadow:0 0 0 6px rgba(232,184,75,.25)}
      }
    </style>
    <div class="kophi-wrap"><div class="kophi-inner">

      <div class="kophi-page-header">
        <div class="kophi-page-title">
          <div class="kophi-page-icon">🎧</div>
          <div>
            <h1>ÉCOUTE <span>CLIENT</span></h1>
            <div class="kophi-breadcrumb">Faites écouter la version finale avant le solde</div>
          </div>
        </div>
      </div>

      <div class="kophi-card kophi-card-gold" style="margin-bottom:22px">
        <div class="kophi-card-title">💡 Comment ça marche</div>
        <ol style="margin:0;padding-left:18px;line-height:2;color:var(--k-text2);font-size:13px">
          <li>Choisissez une <b>session</b> ou un <b>projet</b> ci-dessous.</li>
          <li>Cliquez <b>🎵 Fichier</b> pour téléverser le son final (WAV/MP3) depuis la médiathèque.</li>
          <li>Renseignez le <b>montant total</b> et l'<b>acompte versé</b> → le solde se calcule seul.</li>
          <li><b>Enregistrez</b>, puis envoyez le <b>lien d'écoute</b> au client (WhatsApp).</li>
          <li>Le client écoute en ligne ; le <b>téléchargement</b> ne se débloque qu'une fois le solde à 0.</li>
        </ol>
      </div>

      <?php if (empty($items)): ?>
        <div class="kophi-card"><div class="kophi-empty"><div class="kophi-empty-icon">🎧</div>
          <p>Aucune session ou projet récent.<br>
          <a href="<?= admin_url('post-new.php?post_type=kophi_session') ?>">+ Créer une session →</a></p>
        </div></div>
      <?php else: ?>

      <?php foreach ($items as $post):
        $pid     = $post->ID;
        $tracks  = ksm_listen_get_tracks($pid);
        $has     = !empty($tracks);
        $token   = $has ? ksm_listen_get_token($pid) : '';
        $total   = (float) get_post_meta($pid, '_kophi_montant', true);
        $acompte = (float) get_post_meta($pid, '_kophi_acompte', true);
        $solde   = ksm_listen_get_solde($pid);
        $active  = get_post_meta($pid, '_kophi_ecoute_active', true);
        $active  = ($active === '') ? '1' : $active;
        $dl_mode = ksm_listen_dl_mode($pid);
        $client  = get_post_meta($pid, '_kophi_client_nom', true);
        $link    = $has ? ksm_listen_link($pid) : '';
        $expire  = ksm_listen_expire_date($pid);
        $expired = ksm_listen_is_expired($pid);
        $all_fb  = ksm_listen_get_feedback($pid);
        $new_fb  = ksm_listen_count_new_feedback($pid);
      ?>
      <div class="kophi-card kex-admin-card<?= $has ? ' kex-has' : '' ?>" data-postid="<?= $pid ?>" style="<?= $has ? 'border-color:var(--k-gold-b)' : '' ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px">
          <div>
            <div style="font-size:11px;color:var(--k-text3);letter-spacing:1px"><?= $type_labels[$post->post_type] ?? '' ?></div>
            <div style="font-size:16px;font-weight:700;color:var(--k-text)"><?= esc_html(get_the_title($pid)) ?></div>
            <?php if ($client): ?><div style="font-size:12px;color:var(--k-text3)">👤 <?= esc_html($client) ?></div><?php endif; ?>
          </div>
          <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:6px">
            <?php if ($has): ?>
              <span class="kophi-badge <?= $solde <= 0 && $total > 0 ? 'done' : 'scheduled' ?>">
                <?= $solde <= 0 && $total > 0 ? '✅ Soldé' : ('Solde : ' . number_format($solde, 0, ',', ' ') . ' F') ?>
              </span>
            <?php else: ?>
              <span class="kophi-badge cancelled">Pas encore d'écoute</span>
            <?php endif; ?>
            <?php if ($expire): ?>
              <span class="kophi-badge kex-expire-badge <?= $expired ? 'cancelled' : 'scheduled' ?>">
                <?= $expired ? '⛔ Expiré le ' : '⏳ Expire le ' ?><?= esc_html(date_i18n('d/m/Y', strtotime($expire))) ?>
              </span>
            <?php endif; ?>
            <?php if ($new_fb): ?>
              <span class="kophi-badge cancelled">💬 <?= $new_fb ?> nouveau<?= $new_fb > 1 ? 'x' : '' ?></span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Titres -->
        <div class="kex-tracks">
          <?php
          if ($has) { foreach ($tracks as $i => $t) { ksm_listen_admin_row($t['title'], $t['id'], $token, $i); } }
          else { ksm_listen_admin_row('', 0, '', 0); }
          ?>
        </div>
        <button type="button" class="kophi-btn kophi-btn-outline kophi-btn-sm kex-add">＋ Ajouter un titre</button>

        <!-- Montants -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:14px 0">
          <label style="font-size:11px;color:var(--k-text3)">Montant total (F CFA)
            <input type="number" class="kex-montant" value="<?= esc_attr($total) ?>" min="0" step="500"
              style="width:100%;margin-top:4px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);padding:9px">
          </label>
          <label style="font-size:11px;color:var(--k-text3)">Acompte versé (F CFA)
            <input type="number" class="kex-acompte" value="<?= esc_attr($acompte) ?>" min="0" step="500"
              style="width:100%;margin-top:4px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);padding:9px">
          </label>
          <label style="font-size:11px;color:var(--k-text3)">Écoute active
            <select class="kex-active" style="width:100%;margin-top:4px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);padding:9px">
              <option value="1" <?= selected($active, '1', false) ?>>✅ Oui</option>
              <option value="0" <?= selected($active, '0', false) ?>>🚫 Non</option>
            </select>
          </label>
          <label style="font-size:11px;color:var(--k-text3)">Téléchargement
            <select class="kex-dlmode" style="width:100%;margin-top:4px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);padding:9px">
              <option value="solde"    <?= selected($dl_mode, 'solde', false) ?>>🔒 Après le solde</option>
              <option value="toujours" <?= selected($dl_mode, 'toujours', false) ?>>⬇ Toujours</option>
              <option value="jamais"   <?= selected($dl_mode, 'jamais', false) ?>>🚫 Jamais</option>
            </select>
          </label>
          <label style="font-size:11px;color:var(--k-text3)">Expiration du lien <span style="color:var(--k-text4)">(optionnel)</span>
            <input type="date" class="kex-expire" value="<?= esc_attr($expire) ?>"
              style="width:100%;margin-top:4px;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);padding:9px;color-scheme:dark">
          </label>
        </div>

        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <button type="button" class="kophi-btn kophi-btn-gold kex-save">💾 Enregistrer</button>
          <span class="kex-solde-info" style="font-size:13px;color:var(--k-text2)">
            Solde : <b style="color:var(--k-gold)"><?= number_format($solde, 0, ',', ' ') ?> F CFA</b>
          </span>
          <span class="kex-saved" style="display:none;color:var(--k-green);font-size:13px">✅ Enregistré</span>
        </div>

        <!-- Lien + lecteur -->
        <div class="kex-share" style="margin-top:14px;<?= $has ? '' : 'display:none' ?>">
          <div style="background:var(--k-gold-d);border:1px solid var(--k-gold-b);border-radius:10px;padding:12px">
            <div style="font-size:11px;color:var(--k-text3);margin-bottom:6px">🔗 Lien d'écoute du client</div>
            <input type="text" class="kex-link" readonly value="<?= esc_attr($link) ?>" onclick="this.select()"
              style="width:100%;background:var(--k-dark3);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text);padding:8px;font-size:12px">
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
              <button type="button" class="kophi-btn kophi-btn-primary kophi-btn-sm kex-copy">📋 Copier</button>
              <?php
                $wa_msg = rawurlencode("Bonjour " . ($client ?: '') . " 👋\nVoici la version finale à écouter :\n" . $link .
                  ($solde > 0 ? "\nSolde restant : " . number_format($solde, 0, ',', ' ') . " F CFA." : '') . "\n\nKOPHI'S MUSIC STUDIO 🎙");
                $wa_to = preg_replace('/[^0-9]/', '', get_post_meta($pid, '_kophi_telephone', true));
                $wa_url = $wa_to ? "https://wa.me/{$wa_to}?text={$wa_msg}" : "https://wa.me/?text={$wa_msg}";
              ?>
              <a class="kophi-btn kophi-btn-green kophi-btn-sm kex-wa" href="<?= esc_url($wa_url) ?>" target="_blank" rel="noopener">🟢 WhatsApp</a>
              <a class="kophi-btn kophi-btn-outline kophi-btn-sm kex-preview" href="<?= esc_url($link) ?>" target="_blank" rel="noopener">👁 Prévisualiser</a>
            </div>
          </div>

          <!-- Lecteurs (aperçu studio) -->
          <div class="kex-players" style="margin-top:12px">
            <?php if ($has): foreach ($tracks as $i => $t): ?>
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <span style="font-size:12px;color:var(--k-text3);min-width:120px"><?= esc_html($t['title']) ?></span>
                <audio controls preload="none" controlsList="nodownload" style="flex:1;height:34px">
                  <source src="<?= esc_url(ksm_listen_stream_link($token, $i)) ?>" type="<?= esc_attr(get_post_mime_type($t['id']) ?: 'audio/mpeg') ?>">
                </audio>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <!-- Commentaires clients -->
          <?php if (!empty($all_fb)): ?>
          <div class="kex-fb-box" style="margin-top:14px">
            <div style="font-size:11px;color:var(--k-text3);letter-spacing:1px;margin-bottom:8px">💬 COMMENTAIRES CLIENT</div>
            <div class="kex-fb-items" style="display:flex;flex-direction:column;gap:8px">
              <?php foreach ($all_fb as $f):
                $tidx = (int) ($f['track'] ?? 0);
                $ttl  = isset($tracks[$tidx]) ? $tracks[$tidx]['title'] : '—';
                $done = ($f['status'] ?? 'nouveau') === 'traite';
              ?>
                <div class="kex-fb-item<?= $done ? ' kex-fb-done' : ' kex-fb-new' ?>" id="fb-<?= esc_attr($f['id']) ?>" data-fid="<?= esc_attr($f['id']) ?>"
                  style="background:var(--k-dark3);border:1px solid var(--k-border);border-left:3px solid <?= $done ? '#0a8a4a' : 'var(--k-violet)' ?>;
                  border-radius:8px;padding:10px 12px;<?= $done ? 'opacity:.7' : '' ?>">
                  <div style="display:flex;justify-content:space-between;gap:10px;font-size:11px;color:var(--k-text3);margin-bottom:4px">
                    <span style="font-weight:600;color:var(--k-text2)">🎵 <?= esc_html($ttl) ?><?php if (!$done): ?> <span class="kex-fb-newpill">NOUVEAU</span><?php endif; ?></span>
                    <span><?= esc_html(date_i18n('d/m/Y H:i', $f['date'])) ?></span>
                  </div>
                  <div style="font-size:13px;color:var(--k-text);line-height:1.5;margin-bottom:8px;white-space:pre-wrap"><?= nl2br(esc_html($f['text'])) ?></div>
                  <button type="button" class="kophi-btn kophi-btn-outline kophi-btn-sm kex-fb-toggle-status">
                    <?= $done ? '↺ Remettre en nouveau' : '✅ Marquer traité' ?>
                  </button>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

    </div></div>

    <!-- Modèle de ligne -->
    <template id="kex-admin-row-tpl"><?php ksm_listen_admin_row('', 0, '', 0); ?></template>

    <script>
    (function(){
      var NONCE = '<?= $nonce ?>';
      var AJAX  = '<?= admin_url('admin-ajax.php') ?>';
      var HOME  = '<?= esc_url(home_url('/')) ?>';

      // Aller directement au commentaire non traité (depuis le badge du menu ou du tableau de bord)
      (function(){
        var hash = window.location.hash; // ex: #fb-abc123
        var target = hash ? document.querySelector(hash) : null;
        if (!target) target = document.querySelector('.kex-fb-item.kex-fb-new');
        if (target) {
          setTimeout(function(){
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.add('kex-fb-highlight');
            setTimeout(function(){ target.classList.remove('kex-fb-highlight'); }, 3400);
          }, 350);
        }
      })();

      function bindRow(row){
        var pick = row.querySelector('.kex-pick');
        var rm   = row.querySelector('.kex-remove');
        if (pick) pick.addEventListener('click', function(){
          var frame = wp.media({ title:'Choisir le son final', library:{ type:'audio' }, multiple:false, button:{ text:'Utiliser ce fichier' } });
          frame.on('select', function(){
            var att = frame.state().get('selection').first().toJSON();
            row.querySelector('.kex-id').value = att.id;
            row.querySelector('.kex-file').textContent = att.filename || att.title || ('#'+att.id);
            var ti = row.querySelector('.kex-title');
            if (ti && !ti.value) ti.value = (att.title||'').replace(/\.[a-z0-9]+$/i,'');
          });
          frame.open();
        });
        if (rm) rm.addEventListener('click', function(){
          var wrap = row.parentNode;
          if (wrap.querySelectorAll('.kex-row').length > 1) row.remove();
          else { row.querySelector('.kex-id').value=''; row.querySelector('.kex-file').textContent='Aucun fichier'; row.querySelector('.kex-title').value=''; }
        });
      }

      document.querySelectorAll('.kex-admin-card').forEach(function(card){
        card.querySelectorAll('.kex-row').forEach(bindRow);

        card.querySelector('.kex-add').addEventListener('click', function(){
          var tpl = document.getElementById('kex-admin-row-tpl');
          var div = document.createElement('div'); div.innerHTML = tpl.innerHTML.trim();
          var row = div.firstChild;
          card.querySelector('.kex-tracks').appendChild(row);
          bindRow(row);
        });

        var iM = card.querySelector('.kex-montant'), iA = card.querySelector('.kex-acompte');
        function recalc(){
          var s = (parseFloat(iM.value)||0) - (parseFloat(iA.value)||0); if (s<0) s=0;
          card.querySelector('.kex-solde-info b').textContent = s.toLocaleString('fr-FR') + ' F CFA';
        }
        iM.addEventListener('input', recalc); iA.addEventListener('input', recalc);

        card.querySelector('.kex-save').addEventListener('click', function(){
          var btn = this; btn.textContent = '⏳ ...'; btn.disabled = true;
          var data = new FormData();
          data.append('action','ksm_listen_save');
          data.append('nonce', NONCE);
          data.append('post_id', card.dataset.postid);
          data.append('montant', iM.value||0);
          data.append('acompte', iA.value||0);
          data.append('active', card.querySelector('.kex-active').value);
          data.append('dl_mode', card.querySelector('.kex-dlmode').value);
          data.append('expire', card.querySelector('.kex-expire').value || '');
          card.querySelectorAll('.kex-row').forEach(function(r){
            var id = r.querySelector('.kex-id').value;
            if (id){ data.append('id[]', id); data.append('title[]', r.querySelector('.kex-title').value||''); }
          });
          fetch(AJAX, { method:'POST', credentials:'same-origin', body:data })
            .then(function(r){ return r.json(); })
            .then(function(res){
              btn.textContent = '💾 Enregistrer'; btn.disabled = false;
              if (res && res.success){
                var saved = card.querySelector('.kex-saved'); saved.style.display='inline';
                setTimeout(function(){ saved.style.display='none'; }, 2500);
                if (res.data.link){
                  card.querySelector('.kex-share').style.display='';
                  card.querySelector('.kex-link').value = res.data.link;
                  // recharge pour régénérer les lecteurs avec le bon jeton
                  setTimeout(function(){ location.reload(); }, 700);
                }
              } else {
                alert('Erreur : ' + ((res && res.data && res.data.message) || 'inconnue'));
              }
            })
            .catch(function(){ btn.textContent='💾 Enregistrer'; btn.disabled=false; alert('Erreur réseau'); });
        });

        var copy = card.querySelector('.kex-copy');
        if (copy) copy.addEventListener('click', function(){
          var f = card.querySelector('.kex-link'); f.select(); f.setSelectionRange(0,99999);
          navigator.clipboard ? navigator.clipboard.writeText(f.value) : document.execCommand('copy');
          var o = copy.textContent; copy.textContent='✅ Copié'; setTimeout(function(){ copy.textContent=o; },1500);
        });

        // Marquer un commentaire client comme traité / nouveau
        card.querySelectorAll('.kex-fb-toggle-status').forEach(function(btn){
          btn.addEventListener('click', function(){
            var item = btn.closest('.kex-fb-item');
            var done = item.classList.contains('kex-fb-done');
            var newStatus = done ? 'nouveau' : 'traite';
            btn.disabled = true;
            var data = new FormData();
            data.append('action', 'ksm_listen_feedback_status');
            data.append('nonce', NONCE);
            data.append('post_id', card.dataset.postid);
            data.append('fid', item.dataset.fid);
            data.append('status', newStatus);
            fetch(AJAX, { method:'POST', credentials:'same-origin', body:data })
              .then(function(r){ return r.json(); })
              .then(function(res){
                btn.disabled = false;
                if (res && res.success) {
                  item.classList.toggle('kex-fb-done', newStatus === 'traite');
                  item.classList.toggle('kex-fb-new', newStatus !== 'traite');
                  item.style.opacity = newStatus === 'traite' ? '.7' : '';
                  item.style.borderLeftColor = newStatus === 'traite' ? '#0a8a4a' : 'var(--k-violet)';
                  btn.textContent = newStatus === 'traite' ? '↺ Remettre en nouveau' : '✅ Marquer traité';
                  var pill = item.querySelector('.kex-fb-newpill');
                  if (newStatus === 'traite' && pill) pill.remove();
                  if (newStatus !== 'traite' && !pill) {
                    var track = item.querySelector('span[style*="font-weight:600"]');
                    if (track) {
                      var np = document.createElement('span');
                      np.className = 'kex-fb-newpill';
                      np.textContent = 'NOUVEAU';
                      np.style.marginLeft = '4px';
                      track.appendChild(np);
                    }
                  }
                }
              })
              .catch(function(){ btn.disabled = false; });
          });
        });
      });
    })();
    </script>
    <?php
}

/**
 * Ligne de titre pour la page admin dédiée (style sombre).
 */
function ksm_listen_admin_row($title, $att_id, $token, $index) {
    $att_id = (int) $att_id;
    $fname  = $att_id ? (basename(get_attached_file($att_id)) ?: ('#' . $att_id)) : 'Aucun fichier';
    ?>
    <div class="kex-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;background:var(--k-dark3);padding:8px;border-radius:8px;border:1px solid var(--k-border)">
      <input type="text" class="kex-title" placeholder="Titre du son" value="<?= esc_attr($title) ?>"
        style="flex:1;background:var(--k-dark4);border:1px solid var(--k-border);border-radius:6px;color:var(--k-text);padding:7px 9px">
      <input type="hidden" class="kex-id" value="<?= esc_attr($att_id) ?>">
      <span class="kex-file" style="flex:1.2;font-size:11px;color:var(--k-text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= esc_html($fname) ?></span>
      <button type="button" class="kophi-btn kophi-btn-outline kophi-btn-sm kex-pick">🎵 Fichier</button>
      <button type="button" class="kophi-btn kophi-btn-red kophi-btn-sm kex-remove">✕</button>
    </div>
    <?php
}
