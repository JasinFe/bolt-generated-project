<?php
/**
 * KOPHI'S MUSIC STUDIO — Smart Payment Center
 * ============================================
 * Adapté du système "KM FAMILY" (label KOPHI'S MUSIC) : une page de paiement unique,
 * accessible via un lien/QR à référence secrète (/paiement/{ref}/), qui centralise
 * TOUS les moyens de paiement — CinetPay automatique (si configuré) ET Wave/Orange
 * Money/MTN/Moov manuels (QR + bouton, lien marchand direct), plus une déclaration
 * "J'ai déjà payé" pour que le studio confirme manuellement.
 *
 * S'applique à N'IMPORTE QUELLE commande "kophi_reservation" ayant un montant
 * (_kophi_forfait numérique) et en attente de paiement — achat de beat (montant
 * connu immédiatement) ou tout autre service (acompte session, mix & mastering,
 * beat custom...) une fois que l'équipe a fixé et confirmé le prix final.
 */
if (!defined('ABSPATH')) exit;

// ── Rewrite : /paiement/{ref}/ ──────────────────────────────────
add_action('init', 'kophi_payment_center_rewrite');
function kophi_payment_center_rewrite() {
    add_rewrite_rule('^paiement/([A-Za-z0-9_-]+)/?$', 'index.php?kophi_order_ref=$matches[1]', 'top');

    // Flush automatique à chaque mise à jour de version — une règle de réécriture
    // ajoutée après la première activation du plugin n'est jamais relue par WordPress
    // tant que les règles ne sont pas régénérées (ça ne se fait normalement qu'à
    // l'activation, pas à une simple mise à jour de fichiers).
    if (get_option('kophi_payment_center_rw_version') !== KOPHI_VERSION) {
        flush_rewrite_rules();
        update_option('kophi_payment_center_rw_version', KOPHI_VERSION);
    }
}

add_filter('query_vars', function($vars) { $vars[] = 'kophi_order_ref'; return $vars; });

// ── Affichage de la page de paiement ────────────────────────────
add_action('template_redirect', 'kophi_payment_center_render');
function kophi_payment_center_render() {
    $ref = get_query_var('kophi_order_ref');
    if (!$ref) return;

    $ref     = sanitize_text_field($ref);
    $order_id = kophi_get_order_by_ref($ref);

    nocache_headers();

    if (!$order_id) {
        // Référence inconnue : 404 naturel plutôt qu'une page blanche.
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        get_header();
        echo '<div style="max-width:600px;margin:80px auto;text-align:center;color:#fff;font-family:Montserrat,sans-serif">
                <h1 style="font-family:\'Bebas Neue\',sans-serif;font-size:32px">Lien de paiement introuvable</h1>
                <p>Ce lien de paiement n\'existe pas ou a expiré. Contactez-nous sur WhatsApp si besoin.</p>
              </div>';
        get_footer();
        exit;
    }

    wp_enqueue_style('kophi-fonts');
    wp_enqueue_style('kophi-front');
    wp_enqueue_style('kophi-payment-center', KOPHI_URL . 'assets/css/payment-center.css', [], KOPHI_VERSION);
    wp_enqueue_script('kophi-qrcode-vendor', KOPHI_URL . 'assets/js/vendor/qrcode.min.js', [], '1.0.0', true);
    wp_enqueue_script('kophi-payment-center', KOPHI_URL . 'assets/js/payment-center.js', ['kophi-qrcode-vendor'], KOPHI_VERSION, true);

    wp_localize_script('kophi-payment-center', 'KophiPayment', [
        'rest'      => rest_url('kophi/v1/'),
        'nonce'     => wp_create_nonce('wp_rest'),
        'order_ref' => $ref,
        'is_mobile' => wp_is_mobile(),
        'wa_link'   => 'https://wa.me/' . preg_replace('/[^0-9]/', '', get_option('kophi_whatsapp', '2250503404389')),
    ]);

    get_header();
    include KOPHI_DIR . 'templates/front/payment-center.php';
    get_footer();
    exit;
}

// ── Référence de commande (jeton d'accès secret, 10 caractères) ─
function kophi_ensure_order_ref(int $order_id): string {
    $ref = get_post_meta($order_id, '_kophi_order_ref', true);
    if ($ref) return $ref;

    do {
        $ref = 'KPAY-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        $exists = kophi_get_order_by_ref($ref);
    } while ($exists);

    update_post_meta($order_id, '_kophi_order_ref', $ref);
    return $ref;
}

function kophi_get_order_by_ref(string $ref) {
    global $wpdb;
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kophi_order_ref' AND meta_value = %s LIMIT 1",
        $ref
    ));
    return $id ? (int) $id : 0;
}

function kophi_payment_center_url(int $order_id): string {
    return home_url('/paiement/' . kophi_ensure_order_ref($order_id) . '/');
}

/**
 * Libellé lisible du service, pour affichage sur la page de paiement.
 */
function kophi_get_service_label(int $order_id): string {
    $service = get_post_meta($order_id, '_kophi_service', true);
    $labels = [
        'achat_beat' => 'Achat du beat « ' . get_post_meta($order_id, '_kophi_beat_titre', true) . ' »',
        'voix'       => 'Session studio — Voix, Mix & Master',
        'faceb'      => 'Session studio — Face B',
        'mix_master' => 'Mix & Mastering',
        'beat_custom'=> 'Beat Custom',
    ];
    return $labels[$service] ?? ucfirst(str_replace('_', ' ', (string) $service ?: 'Service KOPHI STUDIO'));
}

// ── REST : infos commande + moyens de paiement disponibles ──────
add_action('rest_api_init', function() {
    register_rest_route('kophi/v1', '/paiement/(?P<ref>[A-Za-z0-9_-]+)', [
        'methods' => 'GET', 'callback' => 'kophi_api_get_paiement', 'permission_callback' => '__return_true',
    ]);
    register_rest_route('kophi/v1', '/paiement/(?P<ref>[A-Za-z0-9_-]+)/status', [
        'methods' => 'GET', 'callback' => 'kophi_api_paiement_status', 'permission_callback' => '__return_true',
    ]);
    register_rest_route('kophi/v1', '/paiement/(?P<ref>[A-Za-z0-9_-]+)/initiated', [
        'methods' => 'POST', 'callback' => 'kophi_api_mark_paiement_initiated', 'permission_callback' => '__return_true',
    ]);
    register_rest_route('kophi/v1', '/paiement/(?P<ref>[A-Za-z0-9_-]+)/declare', [
        'methods' => 'POST', 'callback' => 'kophi_api_declare_paiement', 'permission_callback' => '__return_true',
    ]);
    register_rest_route('kophi/v1', '/paiement/(?P<ref>[A-Za-z0-9_-]+)/cinetpay-init', [
        'methods' => 'POST', 'callback' => 'kophi_api_paiement_cinetpay_init', 'permission_callback' => '__return_true',
    ]);
    // Actions admin
    register_rest_route('kophi/v1', '/admin/paiement/(?P<ref>[A-Za-z0-9_-]+)/confirm', [
        'methods' => 'POST', 'callback' => 'kophi_api_admin_confirm_paiement', 'permission_callback' => 'kophi_check_auth',
    ]);
    register_rest_route('kophi/v1', '/admin/paiement/generer', [
        'methods' => 'POST', 'callback' => 'kophi_api_admin_generer_paiement', 'permission_callback' => 'kophi_check_auth',
    ]);
});

function kophi_api_get_paiement(WP_REST_Request $req) {
    $order_id = kophi_get_order_by_ref(sanitize_text_field($req->get_param('ref')));
    if (!$order_id) return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);

    $montant = (float) get_post_meta($order_id, '_kophi_forfait', true);
    $ref     = get_post_meta($order_id, '_kophi_order_ref', true);

    $manual = [];
    foreach (kophi_get_manual_payment_methods() as $key => $m) {
        $manual[$key] = [
            'label'         => $m['label'],
            'icon'          => $m['icon'],
            'merchant_link' => $m['merchant_link'],
            'qr_url'        => $m['merchant_link'], // Lien direct : voir note dans payment-methods.php
        ];
    }

    return rest_ensure_response([
        'order_ref'      => $ref,
        'service_label'  => kophi_get_service_label($order_id),
        'montant'        => $montant,
        'statut'         => get_post_meta($order_id, '_kophi_statut', true),
        'payment_status' => get_post_meta($order_id, '_kophi_payment_status', true) ?: 'pending',
        'nom'            => get_post_meta($order_id, '_kophi_nom_artiste', true),
        'manual_methods' => $manual,
        'cinetpay'       => kophi_is_cinetpay_configured(),
        'wa_link'        => 'https://wa.me/' . preg_replace('/[^0-9]/', '', get_option('kophi_whatsapp', '2250503404389')),
    ]);
}

function kophi_api_paiement_status(WP_REST_Request $req) {
    $order_id = kophi_get_order_by_ref(sanitize_text_field($req->get_param('ref')));
    if (!$order_id) return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);

    return rest_ensure_response([
        'statut'         => get_post_meta($order_id, '_kophi_statut', true),
        'payment_status' => get_post_meta($order_id, '_kophi_payment_status', true) ?: 'pending',
        'licence_url'    => get_post_meta($order_id, '_kophi_licence_issued', true) ? kophi_get_licence_url($order_id) : null,
    ]);
}

function kophi_api_mark_paiement_initiated(WP_REST_Request $req) {
    $order_id = kophi_get_order_by_ref(sanitize_text_field($req->get_param('ref')));
    if (!$order_id) return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);

    $gateway = sanitize_key($req->get_param('gateway') ?: '');
    update_post_meta($order_id, '_kophi_payment_gateway_choisi', $gateway);
    update_post_meta($order_id, '_kophi_payment_initiated_at', current_time('mysql'));
    return rest_ensure_response(['ok' => true]);
}

/**
 * Le client déclare avoir payé manuellement (Wave/OM/MTN/Moov) : on note la référence
 * de transaction qu'il donne, on notifie l'équipe (email + WhatsApp) pour vérification
 * et confirmation manuelle — le statut ne passe "payé" qu'après action de l'équipe
 * (kophi_api_admin_confirm_paiement), pour éviter de délivrer une licence sur une
 * simple déclaration non vérifiée.
 */
function kophi_api_declare_paiement(WP_REST_Request $req) {
    if (kophi_rate_limit_exceeded('declare_paiement', 10, 15 * MINUTE_IN_SECONDS)) {
        return kophi_rate_limit_error();
    }

    $order_id = kophi_get_order_by_ref(sanitize_text_field($req->get_param('ref')));
    if (!$order_id) return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);

    $reference = sanitize_text_field($req->get_param('reference') ?: '');
    $note      = sanitize_textarea_field($req->get_param('note') ?: '');
    $gateway   = sanitize_key($req->get_param('gateway') ?: get_post_meta($order_id, '_kophi_payment_gateway_choisi', true));

    if (!$reference) {
        return new WP_Error('missing', 'Merci d\'indiquer la référence de ta transaction.', ['status' => 422]);
    }

    update_post_meta($order_id, '_kophi_payment_status', 'declared');
    update_post_meta($order_id, '_kophi_statut', 'payment_declared');
    update_post_meta($order_id, '_kophi_payment_declared_ref', $reference);
    update_post_meta($order_id, '_kophi_payment_declared_note', $note);
    update_post_meta($order_id, '_kophi_payment_gateway', $gateway ?: 'manuel');
    update_post_meta($order_id, '_kophi_payment_declared_at', current_time('mysql'));

    $nom     = get_post_meta($order_id, '_kophi_nom_artiste', true);
    $tel     = get_post_meta($order_id, '_kophi_telephone', true);
    $montant = get_post_meta($order_id, '_kophi_forfait', true);

    kophi_notify_all([
        'type'    => 'paiement_declare',
        'nom'     => $nom,
        'tel'     => $tel,
        'service' => kophi_get_service_label($order_id) . ' — paiement déclaré, à vérifier',
        'prix'    => $montant,
        'date'    => date('d/m/Y'),
        'notes'   => "Moyen : {$gateway}\nRéférence transaction fournie : {$reference}\nNote client : {$note}",
        'post_id' => $order_id,
    ]);

    return rest_ensure_response(['ok' => true]);
}

/**
 * CinetPay reste disponible comme option "automatique" DEPUIS la page de paiement —
 * cette route réutilise exactement la même logique d'appel API que l'achat de beat
 * (voir includes/payment.php), mais génériquement pour n'importe quelle commande.
 */
function kophi_api_paiement_cinetpay_init(WP_REST_Request $req) {
    if (kophi_rate_limit_exceeded('cinetpay_init', 8, 15 * MINUTE_IN_SECONDS)) {
        return kophi_rate_limit_error();
    }

    $order_id = kophi_get_order_by_ref(sanitize_text_field($req->get_param('ref')));
    if (!$order_id) return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);

    if (!kophi_is_cinetpay_configured()) {
        return new WP_Error('not_configured', 'CinetPay non configuré', ['status' => 500]);
    }

    $montant = (float) get_post_meta($order_id, '_kophi_forfait', true);
    if (!$montant) return new WP_Error('no_amount', 'Montant introuvable pour cette commande', ['status' => 422]);

    $nom   = get_post_meta($order_id, '_kophi_nom_artiste', true);
    $tel   = get_post_meta($order_id, '_kophi_telephone', true);
    $email = get_post_meta($order_id, '_kophi_email', true);
    $ref   = get_post_meta($order_id, '_kophi_order_ref', true);

    $transaction_id = 'KOPHI_' . date('Ymd') . '_' . strtoupper(substr(md5(uniqid()), 0, 8));
    update_post_meta($order_id, '_kophi_payment_tx_id', $transaction_id);
    update_post_meta($order_id, '_kophi_payment_mode', 'cinetpay');

    $body = [
        'apikey'                => get_option('kophi_cinetpay_api_key', ''),
        'site_id'               => get_option('kophi_cinetpay_site_id', ''),
        'transaction_id'        => $transaction_id,
        'amount'                => $montant,
        'currency'              => 'XOF',
        'description'           => kophi_get_service_label($order_id),
        'customer_name'         => $nom ?: 'Client',
        'customer_phone_number' => $tel ?: '0000000000',
        'customer_email'        => $email ?: 'noreply@kophismusic.com',
        'notify_url'            => rest_url('kophi/v1/payment/notify'),
        'return_url'            => home_url('/paiement/' . $ref . '/?cinetpay=retour'),
        'channels'              => 'ALL',
        'metadata'              => json_encode(['order_id' => $order_id, 'order_ref' => $ref]),
        'lang'                  => 'fr',
    ];

    $response = wp_remote_post(KOPHI_CINETPAY_URL, [
        'body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 20,
    ]);

    if (is_wp_error($response)) return new WP_Error('cinetpay_error', 'Erreur réseau CinetPay', ['status' => 503]);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $code = (int) wp_remote_retrieve_response_code($response);

    if ($code === 201 && !empty($data['data']['payment_url'])) {
        return rest_ensure_response(['payment_url' => $data['data']['payment_url']]);
    }

    return new WP_Error('cinetpay_failed', $data['message'] ?? 'CinetPay indisponible pour le moment', ['status' => 502]);
}

/**
 * L'équipe confirme manuellement un paiement (Wave/OM/MTN/Moov déclaré, ou vu passer
 * en direct sur le compte marchand) : émet la licence si achat de beat, notifie le client.
 */
function kophi_api_admin_confirm_paiement(WP_REST_Request $req) {
    $order_id = kophi_get_order_by_ref(sanitize_text_field($req->get_param('ref')));
    if (!$order_id) return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);

    kophi_finalize_paid_order($order_id, get_post_meta($order_id, '_kophi_payment_gateway', true) ?: 'manuel');

    return rest_ensure_response(['ok' => true]);
}

/**
 * Génère (ou renvoie) le lien de paiement d'une commande existante — pour les
 * paiements "autres" que l'achat de beat : une fois que l'équipe a fixé le prix
 * final d'une session, d'un mix & mastering ou d'un beat custom, ce lien unique
 * peut être envoyé au client par WhatsApp/email pour qu'il règle en ligne.
 */
function kophi_api_admin_generer_paiement(WP_REST_Request $req) {
    $order_id = (int) $req->get_param('commande_id');
    $montant  = (float) $req->get_param('montant');

    if (!$order_id || !get_post($order_id)) return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);
    if ($montant <= 0) return new WP_Error('invalid_amount', 'Montant invalide', ['status' => 422]);

    update_post_meta($order_id, '_kophi_forfait', (string) $montant);
    update_post_meta($order_id, '_kophi_statut', 'payment_pending');
    $ref = kophi_ensure_order_ref($order_id);

    return rest_ensure_response([
        'order_ref'    => $ref,
        'payment_url'  => kophi_payment_center_url($order_id),
    ]);
}

/**
 * Logique commune de finalisation d'un paiement confirmé — appelée à la fois par le
 * webhook CinetPay (paiement automatique) et par la confirmation manuelle admin
 * (Wave/OM/MTN/Moov). Émet la licence si c'est un achat de beat, notifie le client
 * et l'équipe. Centraliser cette logique évite qu'un des deux chemins de paiement
 * oublie une étape (ce qui était le cas : cette logique n'existait auparavant que
 * dans le webhook CinetPay, donc invisible/inutilisable pour un paiement manuel).
 */
function kophi_finalize_paid_order(int $order_id, string $gateway_label = 'manuel'): void {
    update_post_meta($order_id, '_kophi_statut', 'confirmed');
    update_post_meta($order_id, '_kophi_payment_status', 'paid');
    update_post_meta($order_id, '_kophi_payment_date', current_time('mysql'));
    update_post_meta($order_id, '_kophi_payment_gateway', $gateway_label);

    $service    = get_post_meta($order_id, '_kophi_service', true);
    $email      = get_post_meta($order_id, '_kophi_email', true);
    $nom        = get_post_meta($order_id, '_kophi_nom_artiste', true);
    $tel        = get_post_meta($order_id, '_kophi_telephone', true);
    $montant    = get_post_meta($order_id, '_kophi_forfait', true);
    $extra_note = '';

    if ($service === 'achat_beat' && function_exists('kophi_generate_licence_number')) {
        $num        = kophi_generate_licence_number();
        $type       = get_post_meta($order_id, '_kophi_licence_type', true) ?: 'non_exclusive';
        $beat_titre = get_post_meta($order_id, '_kophi_beat_titre', true);
        kophi_save_licence($order_id, $num, $type);
        $lic_url = kophi_get_licence_url($order_id);

        if ($email) {
            wp_mail(
                $email,
                "🎹 Votre licence beat — KOPHI'S MUSIC STUDIO",
                "Bonjour {$nom},\n\nMerci pour votre achat du beat «{$beat_titre}».\n\n"
                . "Téléchargez votre licence ici :\n{$lic_url}\n\n"
                . "Numéro de licence : {$num}\n\n"
                . "Pour toute question : contact@kophismusic.com\nWhatsApp : " . get_option('kophi_whatsapp', '+2250503404389') . "\n\n"
                . "---\nKOPHI'S MUSIC STUDIO\nstudio.kophismusic.com",
                ['Content-Type: text/plain; charset=UTF-8', 'From: KOPHI Studio <noreply@kophismusic.com>']
            );
        }
        $extra_note = "N° licence : {$num}";
    } elseif ($email) {
        // Autres services (session, mix & mastering, beat custom...) : confirmation générique.
        wp_mail(
            $email,
            "✅ Paiement confirmé — KOPHI'S MUSIC STUDIO",
            "Bonjour {$nom},\n\nNous confirmons la bonne réception de votre paiement pour : " . kophi_get_service_label($order_id) . ".\n\n"
            . "Notre équipe revient vers vous très vite pour la suite.\n\n"
            . "WhatsApp : " . get_option('kophi_whatsapp', '+2250503404389') . "\n\n---\nKOPHI'S MUSIC STUDIO",
            ['Content-Type: text/plain; charset=UTF-8', 'From: KOPHI Studio <noreply@kophismusic.com>']
        );
    }

    kophi_notify_all([
        'type'    => 'paiement_confirme',
        'nom'     => $nom,
        'tel'     => $tel,
        'service' => kophi_get_service_label($order_id) . ' (PAYÉ — ' . $gateway_label . ')',
        'prix'    => $montant,
        'date'    => date('d/m/Y'),
        'notes'   => $extra_note,
        'post_id' => $order_id,
    ]);
}
