<?php
/**
 * KOPHI'S MUSIC STUDIO — Système de Paiement
 * ===========================================
 * CinetPay (Orange Money CI, MTN Money, Wave, Moov)
 * + Paiement manuel WhatsApp (fallback)
 *
 * Configurer dans KOPHI Studio → Paramètres → Paiement:
 *   kophi_cinetpay_site_id
 *   kophi_cinetpay_api_key
 *   kophi_cinetpay_secret_key
 */
if (!defined('ABSPATH')) exit;

define('KOPHI_CINETPAY_URL', 'https://api-checkout.cinetpay.com/v2/payment');
define('KOPHI_CINETPAY_CHECK', 'https://api-checkout.cinetpay.com/v2/payment/check');

// ── Routes REST paiement ─────────────────────────────────────
add_action('rest_api_init', 'kophi_register_payment_routes');
function kophi_register_payment_routes() {
    // Initier un paiement CinetPay
    register_rest_route('kophi/v1', '/payment/init', [
        'methods'             => 'POST',
        'callback'            => 'kophi_api_payment_init',
        'permission_callback' => '__return_true',
    ]);
    // Notification webhook CinetPay (IPN)
    register_rest_route('kophi/v1', '/payment/notify', [
        'methods'             => ['POST','GET'],
        'callback'            => 'kophi_api_payment_notify',
        'permission_callback' => '__return_true',
    ]);
    // Retour succès (redirect client)
    register_rest_route('kophi/v1', '/payment/success', [
        'methods'             => 'GET',
        'callback'            => 'kophi_api_payment_success',
        'permission_callback' => '__return_true',
    ]);
    // Annulation
    register_rest_route('kophi/v1', '/payment/cancel', [
        'methods'             => 'GET',
        'callback'            => 'kophi_api_payment_cancel',
        'permission_callback' => '__return_true',
    ]);
    // Vérifier statut d'un paiement
    register_rest_route('kophi/v1', '/payment/status/(?P<id>[A-Z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'kophi_api_payment_status',
        'permission_callback' => '__return_true',
    ]);
}

// ── Initier un paiement (achat de beat) ─────────────────────
// BUGFIX / ÉVOLUTION : cette route ne parle plus DIRECTEMENT à CinetPay — elle crée
// (ou retrouve) la commande, puis renvoie un lien vers le Smart Payment Center
// (/paiement/{ref}/), qui propose TOUS les moyens de paiement configurés (CinetPay
// automatique + Wave/Orange Money/MTN/Moov manuels avec QR) au même endroit, comme
// sur KM Family. L'appel réel à CinetPay ne se fait que si la personne choisit cette
// option sur cette page (voir kophi_api_paiement_cinetpay_init dans payment-center.php).
function kophi_api_payment_init(WP_REST_Request $req) {
    if (kophi_rate_limit_exceeded('payment_init', 8, 15 * MINUTE_IN_SECONDS)) {
        return kophi_rate_limit_error();
    }

    $commande_id  = (int)$req->get_param('commande_id');
    $beat_id      = (int)$req->get_param('beat_id');
    $nom          = sanitize_text_field($req->get_param('nom') ?: '');
    $tel          = sanitize_text_field($req->get_param('telephone') ?: '');
    $email        = sanitize_email($req->get_param('email') ?: '');
    $licence_type = sanitize_text_field($req->get_param('licence_type') ?: 'non_exclusive');

    if (!$beat_id || !$nom || !$tel) {
        return new WP_Error('missing', 'Données manquantes', ['status'=>422]);
    }

    // Récupérer les infos du beat et du prix
    $beat      = get_post($beat_id);
    $prix_key  = $licence_type === 'exclusive' ? '_kophi_prix_exclusive' : '_kophi_prix_non_exclusive';
    $montant   = (int)get_post_meta($beat_id, $prix_key, true);

    if (!$beat || !$montant) {
        return new WP_Error('beat_error', 'Beat ou prix introuvable', ['status'=>404]);
    }

    // Si une commande existe déjà, la réutiliser
    if (!$commande_id) {
        $commande_id = wp_insert_post([
            'post_type'   => 'kophi_reservation',
            'post_title'  => 'ACHAT BEAT — ' . $beat->post_title . ' — ' . $nom,
            'post_status' => 'publish',
        ]);
        if (!is_wp_error($commande_id)) {
            update_post_meta($commande_id, '_kophi_nom_artiste',    $nom);
            update_post_meta($commande_id, '_kophi_telephone',       $tel);
            update_post_meta($commande_id, '_kophi_email',           $email);
            update_post_meta($commande_id, '_kophi_service',         'achat_beat');
            update_post_meta($commande_id, '_kophi_forfait',         (string)$montant);
            update_post_meta($commande_id, '_kophi_beat_id',         $beat_id);
            update_post_meta($commande_id, '_kophi_beat_titre',      $beat->post_title);
            update_post_meta($commande_id, '_kophi_licence_type',    $licence_type);
            update_post_meta($commande_id, '_kophi_statut',          'payment_pending');
            update_post_meta($commande_id, '_kophi_date_souhaitee',  date('Y-m-d'));
        }
    }

    if (is_wp_error($commande_id) || !$commande_id) {
        return new WP_Error('order_error', 'Impossible de créer la commande', ['status' => 500]);
    }

    return rest_ensure_response([
        'commande_id'  => $commande_id,
        'order_ref'    => kophi_ensure_order_ref($commande_id),
        'redirect_url' => kophi_payment_center_url($commande_id),
        'montant'      => $montant,
    ]);
}

// ── Webhook de notification CinetPay (IPN) ──────────────────
function kophi_api_payment_notify(WP_REST_Request $req) {
    $body = $req->get_body();
    $data = json_decode($body, true) ?: $req->get_params();

    $tx_id   = sanitize_text_field($data['cpm_trans_id'] ?? $req->get_param('cpm_trans_id') ?? '');

    if (!$tx_id) {
        return new WP_REST_Response(['ok'=>false,'msg'=>'tx_id manquant'], 400);
    }

    // Trouver la commande par transaction_id
    global $wpdb;
    $commande_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_kophi_payment_tx_id' AND meta_value=%s LIMIT 1",
        $tx_id
    ));

    if (!$commande_id) {
        return new WP_REST_Response(['ok'=>false,'msg'=>'Commande introuvable'], 404);
    }

    // Vérifier le paiement auprès de CinetPay directement (jamais faire confiance
    // aveuglément aux données du webhook lui-même : on interroge CinetPay pour
    // confirmer le statut réel de la transaction).
    $site_id = get_option('kophi_cinetpay_site_id', '');
    $api_key = get_option('kophi_cinetpay_api_key', '');

    $check = wp_remote_post(KOPHI_CINETPAY_CHECK, [
        'body'    => json_encode(['apikey'=>$api_key,'site_id'=>$site_id,'transaction_id'=>$tx_id]),
        'headers' => ['Content-Type'=>'application/json'],
        'timeout' => 15,
    ]);

    $check_data = is_wp_error($check) ? [] : json_decode(wp_remote_retrieve_body($check), true);
    $is_paid    = ($check_data['data']['status'] ?? '') === 'ACCEPTED';

    if ($is_paid) {
        // BUGFIX : la logique d'émission de licence + notifications était dupliquée
        // ici et nulle part ailleurs — un paiement confirmé manuellement (Wave/OM/
        // MTN/Moov via le Smart Payment Center) ne déclenchait jamais la licence.
        // Cette logique vit maintenant dans une seule fonction partagée.
        kophi_finalize_paid_order($commande_id, 'cinetpay');
    } else {
        update_post_meta($commande_id, '_kophi_payment_status', 'failed');
        update_post_meta($commande_id, '_kophi_statut', 'cancelled');
    }

    return new WP_REST_Response(['ok'=>true], 200);
}

// ── Redirect succès client ───────────────────────────────────
function kophi_api_payment_success(WP_REST_Request $req) {
    $tx = sanitize_text_field($req->get_param('tx') ?: '');
    global $wpdb;
    $commande_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_kophi_payment_tx_id' AND meta_value=%s LIMIT 1",
        $tx
    ));
    $lic_url = $commande_id ? kophi_get_licence_url($commande_id) : home_url('/beats');
    wp_redirect(home_url('/beats?payment=success&lic=' . urlencode($lic_url)));
    exit;
}

function kophi_api_payment_cancel(WP_REST_Request $req) {
    wp_redirect(home_url('/beats?payment=cancelled'));
    exit;
}

function kophi_api_payment_status(WP_REST_Request $req) {
    $tx = sanitize_text_field($req->get_param('id') ?: '');
    global $wpdb;
    $commande_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_kophi_payment_tx_id' AND meta_value=%s LIMIT 1",
        $tx
    ));
    if (!$commande_id) return new WP_Error('not_found','Transaction introuvable',['status'=>404]);
    return rest_ensure_response([
        'statut'       => get_post_meta($commande_id,'_kophi_statut',true),
        'payment'      => get_post_meta($commande_id,'_kophi_payment_status',true),
        'licence_url'  => get_post_meta($commande_id,'_kophi_licence_issued',true) ? kophi_get_licence_url($commande_id) : null,
        'licence_num'  => get_post_meta($commande_id,'_kophi_licence_numero',true),
    ]);
}
