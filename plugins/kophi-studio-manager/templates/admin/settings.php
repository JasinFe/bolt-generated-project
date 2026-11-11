<?php if (!defined('ABSPATH')) exit; ?>
<?php
$assist_error   = '';
$assist_success = '';
$settings_saved = false;

// ── Traitement sauvegarde des réglages ──────────────────────────
// BUGFIX MAJEUR : ce formulaire ("💾 SAUVEGARDER LES PARAMÈTRES") n'avait jusqu'ici
// AUCUN code de traitement — le clic sur le bouton rechargeait la page sans rien
// enregistrer. Toutes les valeurs ci-dessous (WhatsApp, tarifs, CinetPay, horaires...)
// étaient donc impossibles à modifier depuis l'administration.
if (isset($_POST['kophi_save_settings']) && check_admin_referer('kophi_settings_save')) {
    $text_fields = [
        'kophi_adresse', 'kophi_beatmaker_nom', 'kophi_burida_numero',
        'kophi_cinetpay_site_id', 'kophi_cinetpay_api_key', 'kophi_cinetpay_secret_key',
        'kophi_daw_api_key', 'kophi_daw_poll_interval',
        'kophi_msg_confirmation',
        'kophi_tarif_essentielle_min', 'kophi_tarif_essentielle_max', 'kophi_tarif_essentielle_prix',
        'kophi_tarif_pro_min', 'kophi_tarif_pro_max', 'kophi_tarif_pro_prix',
        'kophi_tarif_premium_min', 'kophi_tarif_premium_max', 'kophi_tarif_premium_prix',
        'kophi_tarif_signature_min', 'kophi_tarif_signature_max', 'kophi_tarif_signature_prix',
        'kophi_tarif_custom_max', 'kophi_tarif_custom_min', 'kophi_tarif_faceb',
        'kophi_twilio_from', 'kophi_twilio_sid', 'kophi_twilio_token',
        'kophi_ultramsg_instance', 'kophi_ultramsg_instance2',
        'kophi_ultramsg_token', 'kophi_ultramsg_token2',
        'kophi_wa_provider', 'kophi_whatsapp',
        'kophi_wave_merchant_link', 'kophi_om_merchant_link',
        'kophi_mtn_merchant_link', 'kophi_moov_merchant_link',
    ];

    foreach ($text_fields as $field) {
        if (isset($_POST[$field])) {
            update_option($field, sanitize_text_field(wp_unslash($_POST[$field])));
        }
    }

    if (isset($_POST['kophi_email'])) {
        update_option('kophi_email', sanitize_email(wp_unslash($_POST['kophi_email'])));
    }

    // Case à cocher : n'est présente dans $_POST que si cochée.
    update_option('kophi_wa_notify_client', isset($_POST['kophi_wa_notify_client']) ? '1' : '');

    // Horaires d'ouverture (voir le correctif de la section "Horaires" plus bas dans
    // ce fichier — stockage simple en option, jours 1=Lundi à 7=Dimanche).
    $horaires = [];
    for ($dow = 1; $dow <= 7; $dow++) {
        $ouv  = sanitize_text_field(wp_unslash($_POST["h_ouv_{$dow}"] ?? '09:00'));
        $ferm = sanitize_text_field(wp_unslash($_POST["h_ferm_{$dow}"] ?? '22:00'));
        // Validation basique du format HH:MM pour éviter d'enregistrer n'importe quoi.
        $horaires[$dow] = [
            'actif'           => isset($_POST["h_actif_{$dow}"]) ? 1 : 0,
            'heure_ouverture' => preg_match('/^\d{2}:\d{2}$/', $ouv) ? $ouv : '09:00',
            'heure_fermeture' => preg_match('/^\d{2}:\d{2}$/', $ferm) ? $ferm : '22:00',
        ];
    }
    update_option('kophi_horaires', $horaires);

    $settings_saved = true;
}

// ── Traitement modification mot de passe assistant ──────────────
if (isset($_POST['kophi_change_pass']) && check_admin_referer('kophi_change_pass_nonce')) {
    $uid      = (int)($_POST['assistant_uid'] ?? 0);
    $new_pass = trim($_POST['new_password'] ?? '');
    $u        = get_user_by('id', $uid);
    if ($uid && $new_pass && $u && in_array('kophi_assistant', (array)$u->roles)) {
        wp_set_password($new_pass, $uid);
        $pass_display = esc_html($new_pass);
        $assist_success = "Mot de passe modifie pour <strong>{$u->display_name}</strong> : <code>{$pass_display}</code>";
        wp_mail(
            $u->user_email,
            "Votre nouveau mot de passe - KOPHI MUSIC STUDIO",
            "Bonjour {$u->display_name},\n\nNouveau mot de passe : {$new_pass}\nURL : " . admin_url() . "\n\nKOPHI MUSIC STUDIO",
            ['Content-Type: text/plain; charset=UTF-8']
        );
    } else {
        $assist_error = 'Impossible de modifier le mot de passe.';
    }
}

// ── Traitement suppression compte assistant ─────────────────────
if (isset($_GET['delete_assistant']) && check_admin_referer('kophi_delete_assistant_' . (int)$_GET['delete_assistant'])) {
    $uid = (int)$_GET['delete_assistant'];
    $u   = get_user_by('id', $uid);
    if ($u && in_array('kophi_assistant', (array)$u->roles)) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (is_multisite()) {
            // Retirer du sous-site ET supprimer le compte réseau
            remove_user_from_blog($uid, get_current_blog_id());
            // Supprimer aussi le compte réseau (plus propre)
            if (function_exists('wpmu_delete_user')) {
                wpmu_delete_user($uid);
            }
        } else {
            wp_delete_user($uid);
        }
        // Vider le cache WordPress pour forcer le rechargement
        wp_cache_flush();
        clean_user_cache($uid);
        // Rediriger pour éviter la re-soumission et rafraîchir la liste
        wp_redirect(admin_url('admin.php?page=kophi-settings&deleted=1'));
        exit;
    }
}

// Message apres redirection
if (isset($_GET['deleted'])) {
    $assist_success = 'Compte supprime avec succes.';
}

// ── Traitement creation compte assistant ────────────────────────
if (isset($_POST['kophi_create_assistant']) && check_admin_referer('kophi_create_assistant_nonce')) {
    $login  = sanitize_user(trim($_POST['assistant_login']  ?? ''));
    $email  = sanitize_email($_POST['assistant_email']       ?? '');
    $prenom = sanitize_text_field($_POST['assistant_prenom'] ?? '');
    $nom    = sanitize_text_field($_POST['assistant_nom']    ?? '');
    $pass   = !empty(trim($_POST['assistant_password'] ?? ''))
              ? trim($_POST['assistant_password'])
              : wp_generate_password(12, false, false);

    $errors = [];
    if (!$login)                     $errors[] = 'Identifiant obligatoire.';
    if (!$email || !is_email($email)) $errors[] = 'Email invalide.';
    if (username_exists($login))     $errors[] = 'Identifiant deja utilise.';
    if (email_exists($email))        $errors[] = 'Email deja utilise.';

    if (empty($errors)) {
        if (is_multisite()) {
            $user_id = wpmu_create_user($login, $pass, $email);
            if (!$user_id) {
                $assist_error = 'Erreur creation compte reseau. Verifiez identifiant et email.';
            } else {
                $blog_id = get_current_blog_id();
                // S'assurer que le rôle existe avant de l'assigner
                if (!get_role('kophi_assistant')) {
                    kophi_create_roles();
                }
                $added = add_user_to_blog($blog_id, $user_id, 'kophi_assistant');
                if (is_wp_error($added)) {
                    $assist_error = 'Compte cree mais erreur affectation au site.';
                } else {
                    // Forcer le rôle via WP_User dans le contexte du bon blog
                    switch_to_blog($blog_id);
                    $wp_user = new WP_User($user_id);
                    $wp_user->set_role('kophi_assistant');
                    // Forcer aussi via user_meta directement (Multisite fiable)
                    $cap_key = $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities';
                    update_user_meta($user_id, $cap_key, ['kophi_assistant' => true]);
                    restore_current_blog();
                    wp_update_user([
                        'ID'           => $user_id,
                        'first_name'   => $prenom,
                        'last_name'    => $nom,
                        'display_name' => trim("$prenom $nom") ?: $login,
                    ]);
                    $login_url    = wp_login_url();
                    $pass_display = esc_html($pass);
                    $assist_success = "Compte cree ! Identifiant : <strong>{$login}</strong> · Mot de passe : <code style='background:rgba(0,200,122,.1);padding:2px 8px;border-radius:4px'>{$pass_display}</code><br><small>URL connexion : <a href='{$login_url}' style='color:#00C87A'>{$login_url}</a></small>";
                    wp_mail(
                        $email,
                        "Votre acces KOPHI MUSIC STUDIO",
                        "Bonjour {$prenom},\n\nIdentifiants de connexion :\n\nURL : {$login_url}\nIdentifiant : {$login}\nMot de passe : {$pass}\n\nKOPHI MUSIC STUDIO",
                        ['Content-Type: text/plain; charset=UTF-8']
                    );
                }
            }
        } else {
            $user_id = wp_insert_user([
                'user_login'   => $login,
                'user_email'   => $email,
                'first_name'   => $prenom,
                'last_name'    => $nom,
                'user_pass'    => $pass,
                'role'         => 'kophi_assistant',
                'display_name' => trim("$prenom $nom") ?: $login,
            ]);
            if (is_wp_error($user_id)) {
                $assist_error = $user_id->get_error_message();
            } else {
                $pass_display = esc_html($pass);
                $assist_success = "Compte cree ! Identifiant : <strong>{$login}</strong> · Mot de passe : <code>{$pass_display}</code>";
            }
        }
    } else {
        $assist_error = implode(' ', $errors);
    }
}

// ── Liste assistants du sous-site (Multisite-safe) ──────────────
global $wpdb;
$cap_key    = $wpdb->get_blog_prefix() . 'capabilities';
$assistants = get_users([
    'meta_key'   => $cap_key,
    'meta_value' => '"kophi_assistant"',
    'meta_compare'=> 'LIKE',
    'blog_id'    => get_current_blog_id(),
    'number'     => 50,
]);
?>

?>

<form method="POST">
<?php wp_nonce_field('kophi_settings_save'); ?>
<input type="hidden" name="kophi_save_settings" value="1">

<?php if ($settings_saved): ?>
<div style="padding:10px 14px;background:rgba(0,200,122,.1);border:1px solid rgba(0,200,122,.3);border-radius:8px;color:#00C87A;font-size:12px;margin-bottom:14px">
  ✅ Paramètres enregistrés avec succès.
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<!-- Contact & Info -->
<div class="kophi-card">
  <div class="kophi-card-title">📞 Contact Studio</div>
  <div class="kophi-fg"><label>Numéro WhatsApp Business</label><input name="kophi_whatsapp" value="<?= esc_attr(get_option('kophi_whatsapp','+2250503404389')) ?>" placeholder="+2250503404389"></div>
  <div class="kophi-fg"><label>Email de contact</label><input type="email" name="kophi_email" value="<?= esc_attr(get_option('kophi_email','contact@kophismusic.com')) ?>"></div>
  <div class="kophi-fg"><label>Adresse studio</label><input name="kophi_adresse" value="<?= esc_attr(get_option('kophi_adresse','Abidjan, Côte d\'Ivoire')) ?>"></div>
  <div class="kophi-fg"><label>Nom du beatmaker</label><input name="kophi_beatmaker_nom" value="<?= esc_attr(get_option('kophi_beatmaker_nom','Beatmaker - KOPHI\'S MUSIC STUDIO')) ?>"></div>
  <div class="kophi-fg"><label>Numéro BURIDA beatmaker</label><input name="kophi_burida_numero" value="<?= esc_attr(get_option('kophi_burida_numero','')) ?>" placeholder="Numéro d'adhérent BURIDA"></div>
  <div class="kophi-fg"><label>Message de confirmation envoyé aux clients</label><textarea name="kophi_msg_confirmation" rows="3"><?= esc_textarea(get_option('kophi_msg_confirmation','Votre demande a été reçue. Notre équipe vous contacte dans les 24h.')) ?></textarea></div>
</div>

<!-- Tarifs -->
<div class="kophi-card">
  <div class="kophi-card-title">💰 Tarifs Studio</div>
  <p style="font-size:11px;color:var(--k-text4);margin:-4px 0 12px">Ces tarifs sont aussi modifiables directement depuis <a href="<?= admin_url('admin.php?page=kophi-tarifs') ?>" style="color:var(--k-gold)">Tarifs &amp; Workflow</a> — les deux pages partagent les mêmes valeurs.</p>

  <div style="font-size:11px;font-weight:700;color:var(--k-gold);margin:10px 0 6px">🎙 Session Essentielle — 4h</div>
  <div class="kophi-form-row">
    <div class="kophi-fg"><label>Prix affiché (F CFA)</label><input type="number" name="kophi_tarif_essentielle_prix" value="<?= esc_attr(get_option('kophi_tarif_essentielle_prix','70000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette min (F)</label><input type="number" name="kophi_tarif_essentielle_min" value="<?= esc_attr(get_option('kophi_tarif_essentielle_min','50000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette max (F)</label><input type="number" name="kophi_tarif_essentielle_max" value="<?= esc_attr(get_option('kophi_tarif_essentielle_max','70000')) ?>"></div>
  </div>

  <div style="font-size:11px;font-weight:700;color:var(--k-gold);margin:14px 0 6px">🎙 Session Pro — 6h</div>
  <div class="kophi-form-row">
    <div class="kophi-fg"><label>Prix affiché (F CFA)</label><input type="number" name="kophi_tarif_pro_prix" value="<?= esc_attr(get_option('kophi_tarif_pro_prix','100000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette min (F)</label><input type="number" name="kophi_tarif_pro_min" value="<?= esc_attr(get_option('kophi_tarif_pro_min','80000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette max (F)</label><input type="number" name="kophi_tarif_pro_max" value="<?= esc_attr(get_option('kophi_tarif_pro_max','100000')) ?>"></div>
  </div>

  <div style="font-size:11px;font-weight:700;color:var(--k-gold);margin:14px 0 6px">🎚 Session Premium — 8h</div>
  <div class="kophi-form-row">
    <div class="kophi-fg"><label>Prix affiché (F CFA)</label><input type="number" name="kophi_tarif_premium_prix" value="<?= esc_attr(get_option('kophi_tarif_premium_prix','150000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette min (F)</label><input type="number" name="kophi_tarif_premium_min" value="<?= esc_attr(get_option('kophi_tarif_premium_min','120000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette max (F)</label><input type="number" name="kophi_tarif_premium_max" value="<?= esc_attr(get_option('kophi_tarif_premium_max','150000')) ?>"></div>
  </div>

  <div style="font-size:11px;font-weight:700;color:var(--k-gold);margin:14px 0 6px">✨ Session Signature — 12h</div>
  <div class="kophi-form-row">
    <div class="kophi-fg"><label>Prix affiché (F CFA)</label><input type="number" name="kophi_tarif_signature_prix" value="<?= esc_attr(get_option('kophi_tarif_signature_prix','250000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette min (F)</label><input type="number" name="kophi_tarif_signature_min" value="<?= esc_attr(get_option('kophi_tarif_signature_min','200000')) ?>"></div>
    <div class="kophi-fg"><label>Fourchette max (F)</label><input type="number" name="kophi_tarif_signature_max" value="<?= esc_attr(get_option('kophi_tarif_signature_max','250000')) ?>"></div>
  </div>

  <div style="font-size:11px;font-weight:700;color:#C084FC;margin:14px 0 6px">🎵 Session Face B — 1h30</div>
  <div class="kophi-fg"><label>Prix (F CFA)</label><input type="number" name="kophi_tarif_faceb" value="<?= esc_attr(get_option('kophi_tarif_faceb','10000')) ?>"></div>

  <div style="font-size:11px;font-weight:700;color:var(--k-gold);margin:14px 0 6px">🎹 Beat Custom</div>
  <div class="kophi-form-row">
    <div class="kophi-fg"><label>Beat Custom — prix min (F)</label><input type="number" name="kophi_tarif_custom_min" value="<?= esc_attr(get_option('kophi_tarif_custom_min','30000')) ?>"></div>
    <div class="kophi-fg"><label>Beat Custom — prix max (F)</label><input type="number" name="kophi_tarif_custom_max" value="<?= esc_attr(get_option('kophi_tarif_custom_max','100000')) ?>"></div>
  </div>
</div>

</div><!-- /grid -->

<!-- Horaires -->
<?php
// CORRECTIF : $jours et $h_map n'étaient définis nulle part dans le plugin — ce bloc
// ne pouvait ni s'afficher ni fonctionner. Stockage simple via une option WordPress
// (kophi_horaires), pas besoin d'une table dédiée pour 7 lignes de données.
// Convention ISO-8601 : 1 = Lundi ... 7 = Dimanche (date('N') en PHP).
$jours = [
    1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
    5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
];
$h_map = get_option('kophi_horaires', []);
?>
<div class="kophi-card" style="margin-top:20px">
  <div class="kophi-card-title">🕐 Horaires d'ouverture</div>
  <table class="kophi-table">
    <thead><tr><th>Jour</th><th>Ouvert</th><th>Heure ouverture</th><th>Heure fermeture</th></tr></thead>
    <tbody>
    <?php foreach($jours as $dow => $jour_label):
      $h = $h_map[$dow] ?? null;
      $actif = $h ? !empty($h['actif']) : ($dow > 0 && $dow < 7);
      $ouv  = $h ? substr($h['heure_ouverture'],0,5) : '09:00';
      $ferm = $h ? substr($h['heure_fermeture'],0,5) : '22:00';
    ?>
    <tr>
      <td style="font-weight:700;color:var(--k-text)"><?= $jour_label ?></td>
      <td><label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" name="h_actif_<?= $dow ?>" <?= $actif?'checked':'' ?>> <span style="font-size:11px"><?= $actif?'Ouvert':'Fermé' ?></span></label></td>
      <td><input type="time" name="h_ouv_<?= $dow ?>" value="<?= esc_attr($ouv) ?>" style="font-family:inherit;font-size:12px;padding:6px 10px;background:var(--k-dark4);border:1px solid var(--k-border);border-radius:8px;color:#fff;width:100px"></td>
      <td><input type="time" name="h_ferm_<?= $dow ?>" value="<?= esc_attr($ferm) ?>" style="font-family:inherit;font-size:12px;padding:6px 10px;background:var(--k-dark4);border:1px solid var(--k-border);border-radius:8px;color:#fff;width:100px"></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Info système -->
<div class="kophi-card" style="border-color:var(--k-violet-b);margin-top:16px">
  <div class="kophi-card-title">ℹ Informations système</div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:11px;color:var(--k-text2)">
    <div><strong style="color:var(--k-text3)">Version plugin :</strong><br><?= KOPHI_VERSION ?></div>
    <div><strong style="color:var(--k-text3)">Site studio :</strong><br><a href="<?= home_url() ?>" target="_blank" style="color:var(--k-gold)"><?= home_url() ?></a></div>
    <div><strong style="color:var(--k-text3)">REST API :</strong><br><a href="<?= rest_url('kophi/v1/') ?>" target="_blank" style="color:var(--k-gold)">Voir les endpoints</a></div>
    <div><strong style="color:var(--k-text3)">Page réservation :</strong><br><a href="<?= home_url('/reserver') ?>" target="_blank" style="color:var(--k-gold)"><?= home_url('/reserver') ?></a></div>
    <div><strong style="color:var(--k-text3)">Boutique beats :</strong><br><a href="<?= home_url('/beats') ?>" target="_blank" style="color:var(--k-gold)"><?= home_url('/beats') ?></a></div>
    <div><strong style="color:var(--k-text3)">WhatsApp :</strong><br><a href="https://wa.me/<?= preg_replace('/[^0-9]/','',get_option('kophi_whatsapp','2250503404389')) ?>" target="_blank" style="color:#25D366"><?= get_option('kophi_whatsapp','+225 05 03 40 43 89') ?></a></div>
  </div>
</div>

<div style="margin-top:20px">
  <button type="submit" class="kophi-btn kophi-btn-primary" style="padding:12px 28px;font-size:14px">💾 SAUVEGARDER LES PARAMÈTRES</button>
</div>


<!-- SECTION PAIEMENT CINETPAY -->
<div class="kophi-card kophi-card-gold" style="margin-top:20px">
  <div class="kophi-card-title">💳 Paiement Mobile Money — CinetPay</div>
  <div style="font-size:11px;color:var(--k-text3);margin-bottom:16px;padding:10px;background:rgba(232,184,75,.06);border:1px solid rgba(232,184,75,.15);border-radius:8px">
    ℹ️ Créez votre compte sur <a href="https://cinetpay.com" target="_blank" style="color:var(--k-gold)">cinetpay.com</a> pour obtenir vos clés API.
    Acceptez Orange Money CI, MTN Mobile Money, Wave et Moov.
  </div>
  <div class="kophi-form-row">
    <div class="kophi-fg">
      <label>Site ID CinetPay</label>
      <input type="text" name="kophi_cinetpay_site_id" value="<?= esc_attr(get_option('kophi_cinetpay_site_id','')) ?>" placeholder="Votre Site ID">
    </div>

  <div class="kophi-fg" style="margin-top:12px">
    <label>Clé API CinetPay</label>
    <input type="text" name="kophi_cinetpay_api_key"
           value="<?= esc_attr(get_option('kophi_cinetpay_api_key','')) ?>"
           placeholder="Votre clé API CinetPay"
           style="font-family:monospace">
    <!-- BUGFIX : ce champ (et celui de la clé secrète juste en dessous) étaient absents du
         formulaire alors que le code de paiement les utilise (get_option('kophi_cinetpay_api_key')
         / 'kophi_cinetpay_secret_key') — CinetPay ne pouvait donc jamais être réellement
         configuré depuis l'administration. Le bloc précédent ici était en plus du HTML cassé
         (balise <input> manquante, attributs orphelins qui s'affichaient en texte brut sur
         la page publique des réglages). -->
  </div>

  <div class="kophi-fg" style="margin-top:12px">
    <label>Clé secrète CinetPay</label>
    <input type="text" name="kophi_cinetpay_secret_key"
           value="<?= esc_attr(get_option('kophi_cinetpay_secret_key','')) ?>"
           placeholder="Votre clé secrète CinetPay"
           style="font-family:monospace">
  </div>

  <div style="margin-top:14px;padding:10px 14px;background:rgba(255,255,255,.03);border-radius:8px">
    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--k-text3);margin-bottom:8px">Types de notifications envoyées</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;font-size:11px;color:var(--k-text3)">
      <div>📋 Nouvelle réservation client</div>
      <div>✅ Confirmation de réservation</div>
      <div>🎵 Session Interne Label créée</div>
      <div>📞 Résa téléphone enregistrée</div>
      <div>🎹 Demande achat beat</div>
      <div>💰 Paiement déclaré à vérifier</div>
      <div>✅ Paiement confirmé</div>
    </div>
  </div>
</div>

<!-- SECTION SMART PAYMENT CENTER — MOYENS MANUELS (adapté de KM Family) -->
<div class="kophi-card" style="margin-top:20px;border:1px solid var(--k-gold-b)">
  <div class="kophi-card-title">🌊 Smart Payment Center — Moyens manuels</div>
  <div style="font-size:11px;color:var(--k-text3);margin-bottom:16px;padding:10px;background:rgba(232,184,75,.06);border:1px solid rgba(232,184,75,.15);border-radius:8px">
    ℹ️ Ces liens marchands sont utilisés pour <strong>tous les paiements du studio</strong> (achat de beat, acompte de session, mix &amp; mastering, beat custom...) sur la page de paiement unifiée <code>/paiement/{référence}/</code>. Un moyen n'apparaît que si son lien est renseigné ici.
  </div>
  <div class="kophi-form-row">
    <div class="kophi-fg">
      <label>🌊 Lien marchand Wave</label>
      <input type="text" name="kophi_wave_merchant_link" value="<?= esc_attr(get_option('kophi_wave_merchant_link','')) ?>" placeholder="https://pay.wave.com/m/M_ci_XXXXXXXX/c/ci/">
    </div>
  </div>
  <div class="kophi-form-row" style="margin-top:12px">
    <div class="kophi-fg">
      <label>🟠 Lien marchand Orange Money</label>
      <input type="text" name="kophi_om_merchant_link" value="<?= esc_attr(get_option('kophi_om_merchant_link','')) ?>" placeholder="Lien marchand Orange Money">
    </div>
  </div>
  <div class="kophi-form-row" style="margin-top:12px">
    <div class="kophi-fg">
      <label>🟡 Lien marchand MTN Money</label>
      <input type="text" name="kophi_mtn_merchant_link" value="<?= esc_attr(get_option('kophi_mtn_merchant_link','')) ?>" placeholder="Lien marchand MTN Mobile Money">
    </div>
  </div>
  <div class="kophi-form-row" style="margin-top:12px">
    <div class="kophi-fg">
      <label>🔵 Lien marchand Moov Money</label>
      <input type="text" name="kophi_moov_merchant_link" value="<?= esc_attr(get_option('kophi_moov_merchant_link','')) ?>" placeholder="Lien marchand Moov Money">
    </div>
  </div>
</div>

<!-- ══ SECTION DAW TRACKER ══════════════════════════════════ -->
<div id="daw-tracker" class="kophi-card" style="margin-top:20px;border:1px solid var(--k-violet-b)">
  <div class="kophi-card-title">🎛 DAW Tracker — Script Python</div>
  <div style="font-size:11px;color:var(--k-text3);margin-bottom:16px">
    Le script Python surveille FL Studio, Studio One et Finder Studio Pro sur l'ordinateur du studio.
    Il envoie les données au tableau de bord en temps réel.
  </div>

  <div class="kophi-fg">
    <label>Clé API DAW Tracker</label>
    <div style="display:flex;gap:8px;align-items:center">
      <input type="text" name="kophi_daw_api_key"
             value="<?= esc_attr(get_option('kophi_daw_api_key','KOPHIS_DAW_SECRET_KEY_2025')) ?>"
             style="font-family:monospace;font-size:11px"
             placeholder="KOPHIS_DAW_SECRET_KEY_2025">
      <button type="button" onclick="this.previousElementSibling.value='KOPHI_'+Math.random().toString(36).substr(2,16).toUpperCase(); this.textContent='✓ Générée'"
              class="kophi-btn kophi-btn-violet kophi-btn-sm">🔀 Générer</button>
    </div>
    <div style="font-size:10px;color:var(--k-text3);margin-top:4px">
      Cette clé doit correspondre à <code>API_KEY</code> dans le fichier <code>kophi-daw-tracker.py</code>
    </div>
  </div>

  <div style="padding:14px;background:rgba(139,63,217,.08);border:1px solid var(--k-violet-b);border-radius:10px;margin-bottom:14px">
    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--k-violet2);margin-bottom:8px">URL de l'endpoint REST (à copier dans le script)</div>
    <code style="font-size:11px;color:var(--k-gold);background:rgba(0,0,0,.3);padding:6px 12px;border-radius:6px;display:block;word-break:break-all">
      <?= esc_html(rest_url('kophi/v1/daw-tracker')) ?>
    </code>
  </div>

  <div class="kophi-fg">
    <label>Intervalle de polling (secondes)</label>
    <input type="number" name="kophi_daw_poll_interval"
           value="<?= esc_attr(get_option('kophi_daw_poll_interval','8')) ?>"
           min="5" max="60" style="max-width:100px">
    <div style="font-size:10px;color:var(--k-text3);margin-top:4px">
      Fréquence de détection des DAWs (8 secondes recommandé)
    </div>
  </div>

  <div style="margin-top:14px">
    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--k-text3);margin-bottom:8px">Télécharger le script</div>
    <a href="<?= admin_url('admin.php?page=kophi-settings&kophi_download_daw=1') ?>"
       class="kophi-btn kophi-btn-violet">⬇ Télécharger kophi-daw-tracker.zip</a>
    <div style="font-size:10px;color:var(--k-text3);margin-top:8px">
      DAWs supportés : FL Studio · Studio One · Finder Studio Pro · Pro Tools · Ableton · Logic Pro · Cubase · Reaper
    </div>
  </div>
</div>

<!-- ══ SECTION WHATSAPP NOTIFICATIONS ══════════════════════════ -->
<div class="kophi-card" style="margin-top:20px;border:1px solid rgba(37,211,102,.3)">
  <div class="kophi-card-title">💬 Notifications WhatsApp</div>

  <!-- Provider -->
  <div class="kophi-fg" style="margin-bottom:20px">
    <label>Service d'envoi WhatsApp</label>
    <select name="kophi_wa_provider" onchange="kophiWaToggle(this.value)">
      <option value="ultramsg" <?= selected(get_option('kophi_wa_provider','ultramsg'),'ultramsg',false) ?>>
        ⚡ UltraMsg — Scan QR code (recommandé, le plus simple)
      </option>
      <option value="twilio" <?= selected(get_option('kophi_wa_provider'),'twilio',false) ?>>
        🔷 Twilio — API professionnelle
      </option>
      <option value="none" <?= selected(get_option('kophi_wa_provider'),'none',false) ?>>
        🚫 Désactivé — Email uniquement
      </option>
    </select>
  </div>

  <!-- UltraMsg -->
  <div id="kop-wa-ultramsg" style="display:<?= in_array(get_option('kophi_wa_provider','ultramsg'),['twilio','none'])?'none':'block' ?>">
    <div style="font-size:11px;color:var(--k-text3);margin-bottom:16px;padding:12px 14px;background:rgba(37,211,102,.07);border:1px solid rgba(37,211,102,.2);border-radius:8px;line-height:1.8">
      <strong style="color:#00C87A">⚡ Configuration UltraMsg — 5 minutes</strong><br>
      <strong>1.</strong> Rendez-vous sur <a href="https://ultramsg.com" target="_blank" style="color:#00C87A;font-weight:700">ultramsg.com</a> → créer un compte gratuit<br>
      <strong>2.</strong> Créez <strong>2 Instances</strong> (une pour chaque numéro) → Scanner le QR code avec chaque téléphone<br>
      <strong>3.</strong> Copiez l'<strong>Instance ID</strong> et le <strong>Token</strong> de chaque instance ci-dessous<br>
      <strong>4.</strong> Cliquez <strong>Enregistrer</strong> ✅
    </div>

    <!-- Numéro 1 -->
    <div style="margin-bottom:18px;padding:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px">
      <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#00C87A;margin-bottom:12px;display:flex;align-items:center;gap:8px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
        Numéro 1 — +225 05 03 40 43 89
      </div>
      <div class="kophi-form-row" style="grid-template-columns:1fr 1fr;gap:14px">
        <div class="kophi-fg">
          <label>Instance ID</label>
          <input type="text" name="kophi_ultramsg_instance"
                 value="<?= esc_attr(get_option('kophi_ultramsg_instance','')) ?>"
                 placeholder="instance12345" style="font-family:monospace">
        </div>
        <div class="kophi-fg">
          <label>Token</label>
          <input type="text" name="kophi_ultramsg_token"
                 value="<?= esc_attr(get_option('kophi_ultramsg_token','')) ?>"
                 placeholder="abc123xyz..." style="font-family:monospace">
        </div>
      </div>
    </div>

    <!-- Numéro 2 -->
    <div style="padding:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px">
      <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#00C87A;margin-bottom:12px;display:flex;align-items:center;gap:8px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12 0C5.373 0 0 5.373 0 12c0 2.123.553 4.116 1.524 5.847L.057 23.5l5.797-1.522A11.936 11.936 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.519-5.17-1.425l-.37-.22-3.44.903.919-3.353-.241-.386A9.933 9.933 0 0 1 2 12C2 6.478 6.478 2 12 2s10 4.478 10 10-4.478 10-10 10z"/></svg>
        Numéro 2 — +225 05 76 15 46 53
      </div>
      <div class="kophi-form-row" style="grid-template-columns:1fr 1fr;gap:14px">
        <div class="kophi-fg">
          <label>Instance ID</label>
          <input type="text" name="kophi_ultramsg_instance2"
                 value="<?= esc_attr(get_option('kophi_ultramsg_instance2','')) ?>"
                 placeholder="instance67890" style="font-family:monospace">
        </div>
        <div class="kophi-fg">
          <label>Token</label>
          <input type="text" name="kophi_ultramsg_token2"
                 value="<?= esc_attr(get_option('kophi_ultramsg_token2','')) ?>"
                 placeholder="xyz789abc..." style="font-family:monospace">
        </div>
      </div>
    </div>
  </div>

  <!-- Twilio -->
  <div id="kop-wa-twilio" style="display:<?= get_option('kophi_wa_provider')==='twilio'?'block':'none' ?>">
    <div style="font-size:11px;color:var(--k-text3);margin-bottom:14px;padding:12px 14px;background:rgba(139,63,217,.07);border:1px solid rgba(139,63,217,.2);border-radius:8px;line-height:1.7">
      <strong style="color:#A855F7">🔷 Twilio WhatsApp</strong><br>
      1. <a href="https://twilio.com" target="_blank" style="color:#A855F7">twilio.com</a> → Console → Messaging → WhatsApp<br>
      2. Copiez Account SID, Auth Token, et le numéro From (<code>whatsapp:+14155238886</code>)
    </div>
    <div class="kophi-form-row" style="grid-template-columns:1fr 1fr 1fr;gap:12px">
      <div class="kophi-fg">
        <label>Account SID</label>
        <input type="text" name="kophi_twilio_sid" value="<?= esc_attr(get_option('kophi_twilio_sid','')) ?>" placeholder="ACxxxxxxxx" style="font-family:monospace">
      </div>
      <div class="kophi-fg">
        <label>Auth Token</label>
        <input type="password" name="kophi_twilio_token" value="<?= esc_attr(get_option('kophi_twilio_token','')) ?>" style="font-family:monospace">
      </div>
      <div class="kophi-fg">
        <label>Numéro From</label>
        <input type="text" name="kophi_twilio_from" value="<?= esc_attr(get_option('kophi_twilio_from','')) ?>" placeholder="whatsapp:+14155238886" style="font-family:monospace">
      </div>
    </div>
  </div>

  <!-- Notif client -->
  <div class="kophi-fg" style="margin-top:16px">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
      <input type="checkbox" name="kophi_wa_notify_client" value="1" <?= get_option('kophi_wa_notify_client') ? 'checked' : '' ?>>
      <span>Envoyer aussi une confirmation WhatsApp au client lors de sa réservation</span>
    </label>
  </div>
</div>

<!-- BOUTON ENREGISTRER (bas de page) -->
<div style="margin-top:24px;padding:20px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div style="font-size:12px;color:var(--k-text3)">💾 Tous les paramètres sont enregistrés ensemble</div>
  <button type="submit" class="kophi-btn kophi-btn-primary" style="padding:13px 32px;font-size:14px;letter-spacing:1px">
    ✅ ENREGISTRER TOUS LES PARAMÈTRES
  </button>
</div>

<script>
function kophiTogglePassForm(uid) {
  var row = document.getElementById('pass-form-' + uid);
  if (!row) return;
  row.style.display = (row.style.display===''||row.style.display==='none') ? 'table-row' : 'none';
}
function kophiGenChars() {
  var c='abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789@#!', p='';
  for(var i=0;i<12;i++) p+=c.charAt(Math.floor(Math.random()*c.length));
  return p;
}
function kophiGenPass(uid) {
  var el=document.getElementById('pass-input-'+uid); if(el) el.value=kophiGenChars();
}
function kophiGenPassNew() {
  var el=document.getElementById('new-assistant-pass'); if(el) el.value=kophiGenChars();
}
function kophiSavePass(uid, nom) {
  var input=document.getElementById('pass-input-'+uid);
  var msg=document.getElementById('pass-msg-'+uid);
  if(!input||!input.value.trim()){if(msg){msg.textContent='Entrez un MDP';msg.style.color='#FF4757';}return;}
  if(msg){msg.textContent='Enregistrement...';msg.style.color='#E8B84B';}
  var p=new URLSearchParams();
  p.append('action','kophi_change_assistant_pass');
  p.append('uid',uid);
  p.append('new_password',input.value.trim());
  p.append('nonce','<?= wp_create_nonce("kophi_change_pass_ajax") ?>');
  fetch('<?= esc_js(admin_url("admin-ajax.php")) ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p.toString()})
  .then(function(r){return r.json();})
  .then(function(d){
    if(d&&d.success){
      if(msg){msg.textContent='MDP modifie pour '+nom;msg.style.color='#00C87A';}
      input.value='';
      setTimeout(function(){kophiTogglePassForm(uid);if(msg)msg.textContent='';},2500);
    } else {
      if(msg){msg.textContent=(d&&d.data)?d.data:'Erreur';msg.style.color='#FF4757';}
    }
  })
  .catch(function(e){if(msg){msg.textContent='Erreur: '+e.message;msg.style.color='#FF4757';}});
}
function kophiDeleteAssistant(uid, nonce) {
  var p=new URLSearchParams();
  p.append('action','kophi_delete_assistant');
  p.append('uid',uid);
  p.append('nonce',nonce);
  fetch('<?= esc_js(admin_url("admin-ajax.php")) ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p.toString()})
  .then(function(r){return r.json();})
  .then(function(d){
    if(d&&d.success){
      var row=document.querySelector('tr[data-uid="'+uid+'"]');
      var pr=document.getElementById('pass-form-'+uid);
      if(row) row.remove();
      if(pr) pr.remove();
      var m=document.createElement('div');
      m.style.cssText='position:fixed;top:80px;right:24px;background:#00C87A;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999';
      m.textContent='Compte supprime.';
      document.body.appendChild(m);
      setTimeout(function(){m.remove();},3000);
    } else {
      alert('Erreur: '+((d&&d.data)?d.data:'echec'));
    }
  })
  .catch(function(e){alert('Erreur reseau: '+e.message);});
}
function kophiWaToggle(val) {
  var u=document.getElementById('kop-wa-ultramsg');
  var t=document.getElementById('kop-wa-twilio');
  if(u) u.style.display=val==='ultramsg'?'block':'none';
  if(t) t.style.display=val==='twilio'?'block':'none';
}
</script>

</form>

<!--
  BUGFIX CRITIQUE : la section "Accès Équipe" ci-dessous (avec son propre formulaire
  de création d'assistant) était imbriquée DANS le même <form> que les réglages
  généraux. Or WordPress nomme par défaut TOUS les champs nonce "_wpnonce"
  (wp_nonce_field()) — deux champs du même nom dans un seul <form>, à la soumission,
  seule la DERNIÈRE valeur (ici celle de "Créer un assistant") est envoyée dans
  $_POST['_wpnonce'], quel que soit le bouton cliqué. Résultat : cliquer sur
  "💾 SAUVEGARDER LES PARAMÈTRES" envoyait le nonce de l'AUTRE formulaire, et
  check_admin_referer('kophi_settings_save') rejetait systématiquement la requête
  avec "Le lien suivi est expiré" — le formulaire de sauvegarde des réglages ne
  pouvait jamais fonctionner tant que les deux formulaires n'étaient pas séparés.
-->

<!-- ══ SECTION ACCÈS ÉQUIPE (ASSISTANT KOPHI) ══════════════════ -->
<div class="kophi-card" style="margin-top:20px;border:1px solid rgba(232,184,75,.3)">
  <div class="kophi-card-title">👥 Accès Équipe — Assistant / Beatmaker KOPHI Studio</div>

  <?php if (!empty($assist_error)): ?>
  <div style="padding:10px 14px;background:rgba(255,71,87,.1);border:1px solid rgba(255,71,87,.3);border-radius:8px;color:#FF4757;font-size:12px;margin-bottom:14px">
    ❌ <?= esc_html($assist_error) ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($assist_success)): ?>
  <div style="padding:10px 14px;background:rgba(0,200,122,.1);border:1px solid rgba(0,200,122,.3);border-radius:8px;color:#00C87A;font-size:12px;margin-bottom:14px">
    <?= $assist_success ?>
  </div>
  <?php endif; ?>

  <form method="POST">

  <!-- Info accès -->
  <div style="padding:12px 14px;background:rgba(232,184,75,.07);border:1px solid rgba(232,184,75,.2);border-radius:8px;margin-bottom:18px;font-size:11px;color:var(--k-text3);line-height:1.7">
    <strong style="color:#E8B84B">ℹ️ Accès Assistant / Beatmaker :</strong> Accès complet au tableau de bord KOPHI'S STUDIO.<br>
    ✅ Peut faire : Réservations, Validation, Confirmation, Planning, Sessions, Clients, Projets, Statistiques, Boutique Beats (créer/modifier/publier), Upload de sons & médias (médiathèque WordPress)<br>
    ❌ Pas d'accès à : Paramètres KOPHI, Réglages WordPress, Extensions, Thèmes, Gestion des utilisateurs
  </div>

  <!-- Liste des assistants -->
  <?php if (!empty($assistants)): ?>
  <div style="margin-bottom:18px">
    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--k-text3);margin-bottom:10px">Comptes actifs</div>
    <table style="width:100%;border-collapse:collapse">
      <tr style="font-size:9px;letter-spacing:1px;text-transform:uppercase;color:var(--k-text3)">
        <td style="padding:6px 10px">Nom</td>
        <td style="padding:6px 10px">Identifiant</td>
        <td style="padding:6px 10px">Email</td>
        <td style="padding:6px 10px">Créé le</td>
        <td style="padding:6px 10px">Action</td>
      </tr>
      <?php foreach ($assistants as $u): ?>
      <tr data-uid="<?= (int)$u->ID ?>" style="background:rgba(255,255,255,.03);border-top:1px solid rgba(255,255,255,.06)">
        <td style="padding:10px;color:var(--k-text);font-size:12px;font-weight:600">
          <?= esc_html($u->display_name) ?>
        </td>
        <td style="padding:10px;color:var(--k-text3);font-size:11px;font-family:monospace">
          <?= esc_html($u->user_login) ?>
        </td>
        <td style="padding:10px;color:var(--k-text3);font-size:11px">
          <?= esc_html($u->user_email) ?>
        </td>
        <td style="padding:10px;color:var(--k-text3);font-size:11px">
          <?= date('d/m/Y', strtotime($u->user_registered)) ?>
        </td>
        <td style="padding:10px">
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <!-- Bouton MDP -->
            <button type="button"
                    onclick="kophiTogglePassForm(<?= (int)$u->ID ?>)"
                    style="background:rgba(232,184,75,.15);border:1px solid rgba(232,184,75,.3);color:#E8B84B;border-radius:6px;padding:5px 12px;cursor:pointer;font-size:11px;font-weight:600">
              🔑 MDP
            </button>
            <!-- Bouton Supprimer : soumission via formulaire hidden pour éviter GET nonce -->
            <button type="button"
                    onclick="if(confirm('Supprimer ce compte ?')) kophiDeleteAssistant(<?= (int)$u->ID ?>, '<?= esc_js(wp_create_nonce('kophi_delete_assistant_' . $u->ID)) ?>')"
                    style="background:rgba(255,71,87,.15);border:1px solid rgba(255,71,87,.3);color:#FF4757;border-radius:6px;padding:5px 12px;cursor:pointer;font-size:11px;font-weight:600">
              ✕ Supprimer
            </button>
          </div>
        </td>
      </tr>
      <!-- Formulaire inline MDP -->
      <tr id="pass-form-<?= (int)$u->ID ?>" style="display:none;background:rgba(232,184,75,.05);border-top:1px solid rgba(232,184,75,.15)">
        <td colspan="5" style="padding:14px 18px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
            <span style="font-size:12px;color:var(--k-text3)">
              Nouveau MDP pour <strong style="color:#E8B84B"><?= esc_html($u->display_name) ?></strong> :
            </span>
            <input type="text"
                   id="pass-input-<?= (int)$u->ID ?>"
                   placeholder="Nouveau mot de passe"
                   style="flex:1;min-width:180px;background:rgba(255,255,255,.07);border:1.5px solid rgba(232,184,75,.4);border-radius:8px;padding:8px 12px;color:#F0ECF8;font-size:13px;font-family:monospace;outline:none">
            <button type="button"
                    onclick="kophiGenPass(<?= (int)$u->ID ?>)"
                    style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:#C8C0DC;border-radius:7px;padding:8px 14px;cursor:pointer;font-size:12px">
              ⚡ Générer
            </button>
            <button type="button"
                    onclick="kophiSavePass(<?= (int)$u->ID ?>, '<?= esc_js($u->display_name) ?>')"
                    style="background:linear-gradient(135deg,#8B3FD9,#E8B84B);border:none;color:#fff;border-radius:8px;padding:8px 18px;cursor:pointer;font-size:12px;font-weight:700">
              ✅ Enregistrer
            </button>
            <button type="button"
                    onclick="kophiTogglePassForm(<?= (int)$u->ID ?>)"
                    style="background:none;border:none;color:var(--k-text3);cursor:pointer;font-size:12px">
              Annuler
            </button>
            <span id="pass-msg-<?= (int)$u->ID ?>" style="font-size:11px;margin-left:4px"></span>
          </div>
          <p style="font-size:10px;color:var(--k-text3);margin:0">
            Un email avec le nouveau mot de passe sera envoyé automatiquement.
          </p>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php else: ?>
  <div style="color:var(--k-text3);font-size:12px;margin-bottom:14px">Aucun compte assistant créé.</div>
  <?php endif; ?>

  <!-- Formulaire création -->
  <div style="border-top:1px solid rgba(255,255,255,.07);padding-top:18px">
    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--k-text3);margin-bottom:14px">Créer un nouveau compte</div>
    <?php wp_nonce_field('kophi_create_assistant_nonce'); ?>
    <div class="kophi-form-row" style="grid-template-columns:1fr 1fr;gap:14px">
      <div class="kophi-fg">
        <label>Prénom</label>
        <input type="text" name="assistant_prenom" placeholder="Prénom" autocomplete="off">
      </div>
      <div class="kophi-fg">
        <label>Nom</label>
        <input type="text" name="assistant_nom" placeholder="Nom" autocomplete="off">
      </div>
    </div>
    <div class="kophi-form-row" style="grid-template-columns:1fr 1fr;gap:14px">
      <div class="kophi-fg">
        <label>Identifiant * <span style="font-size:9px;color:var(--k-text3)">(pour la connexion)</span></label>
        <input type="text" name="assistant_login" placeholder="ex: marie.dupont" autocomplete="off" style="font-family:monospace">
      </div>
      <div class="kophi-fg">
        <label>Email *</label>
        <input type="email" name="assistant_email" placeholder="email@exemple.com" autocomplete="off">
      </div>
    </div>
    <div class="kophi-fg">
      <label>Mot de passe <span style="font-size:9px;color:var(--k-text3)">(laissez vide pour auto-générer)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="text" name="assistant_password" id="new-assistant-pass"
               placeholder="Laissez vide → généré automatiquement"
               autocomplete="new-password"
               style="font-family:monospace;flex:1">
        <button type="button" onclick="kophiGenPassNew()"
                style="background:rgba(139,63,217,.2);border:1px solid rgba(139,63,217,.4);color:#A855F7;border-radius:8px;padding:10px 14px;cursor:pointer;font-size:11px;white-space:nowrap">
          ⚡ Générer
        </button>
      </div>
      <div style="font-size:10px;color:var(--k-text3);margin-top:4px">Le mot de passe est envoyé par email à l'assistant lors de la création.</div>
    </div>
    <div style="margin-top:14px;display:flex;align-items:center;gap:12px">
      <button type="submit" name="kophi_create_assistant" value="1"
              class="kophi-btn kophi-btn-gold" style="padding:11px 24px;font-size:12px">
        ➕ Créer le compte assistant
      </button>
      <span style="font-size:10px;color:var(--k-text3)">Les identifiants seront envoyés par email à la personne.</span>
    </div>
  </div>
</div>


</form>
