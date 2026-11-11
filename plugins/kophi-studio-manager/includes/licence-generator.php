<?php
/**
 * KOPHI'S MUSIC STUDIO — Générateur de Licence PDF
 * =================================================
 * Génère une licence BURIDA en HTML/CSS convertible en PDF
 * via le navigateur (window.print) ou lien de téléchargement.
 *
 * Usage :
 *   kophi_generate_licence_pdf($commande_id) → retourne URL de téléchargement
 *   kophi_get_licence_html($data)           → retourne HTML de la licence
 */
if (!defined('ABSPATH')) exit;

// ── Générer un numéro de licence unique ────────────────────────
function kophi_generate_licence_number(): string {
    $year   = date('Y');
    $unique = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    return "KM STUDIO-{$year}-{$unique}";
}

// ── Sauvegarder et récupérer une licence ──────────────────────
function kophi_save_licence(int $commande_id, string $num, string $type): void {
    update_post_meta($commande_id, '_kophi_licence_numero',  $num);
    update_post_meta($commande_id, '_kophi_licence_type',    $type);
    update_post_meta($commande_id, '_kophi_licence_date',    date('Y-m-d'));
    update_post_meta($commande_id, '_kophi_licence_issued',  '1');
}

// ── URL de téléchargement (endpoint REST) ─────────────────────
function kophi_get_licence_url(int $commande_id): string {
    $token = get_post_meta($commande_id, '_kophi_licence_token', true);
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        update_post_meta($commande_id, '_kophi_licence_token', $token);
    }
    return rest_url("kophi/v1/licence/{$commande_id}?token={$token}");
}

// ── HTML complet de la licence ─────────────────────────────────
function kophi_get_licence_html(array $d): string {
    $logo     = 'https://studio.kophismusic.com/wp-content/uploads/sites/8/2026/03/LOGO-KOPHIS-MUSIC-STUDIO.png';
    $is_excl  = ($d['licence_type'] ?? '') === 'exclusive';
    $type_lbl = $is_excl ? 'EXCLUSIVE' : 'NON-EXCLUSIVE';
    $color    = $is_excl ? '#8B3FD9' : '#E8B84B';
    $date_fr  = date('d/m/Y', strtotime($d['date'] ?? 'now'));
    $numero   = esc_html($d['numero'] ?? '');
    $beat     = esc_html($d['beat_titre'] ?? '');
    $artiste  = esc_html($d['nom_artiste'] ?? '');
    $prix     = esc_html($d['prix'] ?? '');

    $conditions_ne = [
        "Streams autorisés : jusqu'à 500 000",
        'Durée : 10 ans renouvelables',
        'Distribution commerciale avec crédit obligatoire',
        "Beat reste disponible pour d'autres acheteurs",
        'Fichiers stems non inclus',
        'Usage YouTube monétisé autorisé',
        'Usage radio/TV limité',
        "Crédit requis : Prod. by KOPHI'S MUSIC STUDIO",
    ];
    $conditions_ex = [
        'Streams : illimités',
        'Durée : perpétuelle',
        'Distribution commerciale sans restriction',
        'Beat retiré du marché (exclusivité totale)',
        'Fichiers stems inclus (livrés séparément)',
        'Tous usages autorisés (radio, TV, sync, cinéma)',
        'Acheteur unique — aucune autre vente possible',
        "Crédit recommandé : Prod. by KOPHI'S MUSIC STUDIO",
    ];
    $conditions = $is_excl ? $conditions_ex : $conditions_ne;
    $cond_html  = '';
    foreach ($conditions as $c) {
        $cond_html .= '<li>' . esc_html($c) . '</li>';
    }

    $verify_url = home_url('/wp-json/kophi/v1/licence/verify?num=' . urlencode($d['numero'] ?? ''));
    $qr_url     = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($verify_url);

    $html  = '<!DOCTYPE html><html lang="fr"><head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<title>Licence ' . $numero . ' — KM STUDIO</title>';
    $html .= '<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">';
    $html .= '<style>';
    $html .= '*{box-sizing:border-box;margin:0;padding:0}';
    $html .= 'body{font-family:Inter,sans-serif;background:#0D0D0D;color:#F0ECF8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}';
    $html .= '.lic{width:100%;max-width:720px;background:linear-gradient(160deg,#12112A,#1e0938);border:1px solid ' . $color . ';border-radius:16px;overflow:hidden;box-shadow:0 0 60px rgba(139,63,217,.3)}';
    $html .= '.lic-head{background:linear-gradient(135deg,#1A1830,#0D0D0D);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid ' . $color . '}';
    $html .= '.lic-logo{height:52px;width:auto}';
    $html .= '.lic-type{font-family:Bebas Neue,sans-serif;font-size:28px;color:' . $color . ';letter-spacing:3px}';
    $html .= '.lic-num{font-size:11px;color:rgba(255,255,255,.5);margin-top:3px;letter-spacing:1px}';
    $html .= '.lic-body{padding:28px 32px}';
    $html .= '.lic-lbl{font-size:9px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:5px}';
    $html .= '.lic-val{font-size:15px;font-weight:600;color:#fff}';
    $html .= '.lic-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:22px}';
    $html .= '.lic-beat{font-family:Bebas Neue,sans-serif;font-size:32px;color:' . $color . ';letter-spacing:2px;margin-bottom:8px}';
    $html .= '.lic-cond{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:18px;margin-bottom:20px}';
    $html .= '.lic-cond h3{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:12px}';
    $html .= '.lic-cond ul{list-style:none;display:flex;flex-direction:column;gap:7px}';
    $html .= '.lic-cond li{font-size:12px;color:rgba(255,255,255,.7);padding-left:16px;position:relative}';
    $html .= '.lic-cond li::before{content:"✓";position:absolute;left:0;color:' . $color . ';font-weight:700}';
    $html .= '.lic-foot{background:rgba(0,0,0,.3);padding:18px 32px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid rgba(255,255,255,.06)}';
    $html .= '.lic-burida{font-size:10px;color:rgba(255,255,255,.4);line-height:1.6;max-width:480px}';
    $html .= '.lic-burida strong{color:' . $color . '}';
    $html .= '.lic-qr{display:flex;flex-direction:column;align-items:center;gap:5px}';
    $html .= '.lic-qr img{border:1px solid rgba(255,255,255,.1);border-radius:6px;background:#fff;padding:4px}';
    $html .= '.lic-qr span{font-size:9px;color:rgba(255,255,255,.3);letter-spacing:1px}';
    $html .= '.lic-seal{text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.06)}';
    $html .= '.lic-seal-line{width:60px;height:2px;background:' . $color . ';margin:0 auto 8px}';
    $html .= '.lic-seal-txt{font-size:11px;color:rgba(255,255,255,.4)}';
    $html .= '.print-btn{display:block;margin:20px auto 0;background:' . $color . ';color:#000;border:none;border-radius:8px;padding:12px 32px;font-family:Bebas Neue,sans-serif;font-size:18px;letter-spacing:2px;cursor:pointer}';
    $html .= '@media print{body{background:#fff;color:#000}.lic{border-color:#ccc;background:#fff}.lic-type,.lic-beat{color:#000}.print-btn{display:none}}';
    $html .= '</style></head><body><div>';
    $html .= '<div class="lic">';
    $html .= '<div class="lic-head">';
    $html .= '<img src="' . esc_url($logo) . '" alt="KM STUDIO" class="lic-logo">';
    $html .= '<div><div class="lic-type">LICENCE ' . $type_lbl . '</div>';
    $html .= '<div class="lic-num">' . $numero . '</div></div></div>';
    $html .= '<div class="lic-body">';
    $html .= '<div class="lic-beat">' . $beat . '</div>';
    $html .= '<div class="lic-grid">';
    $html .= '<div><div class="lic-lbl">Licencié</div><div class="lic-val">' . $artiste . '</div></div>';
    $html .= '<div><div class="lic-lbl">Date d\'émission</div><div class="lic-val">' . $date_fr . '</div></div>';
    $html .= '<div><div class="lic-lbl">Type de licence</div><div class="lic-val">' . $type_lbl . '</div></div>';
    $html .= '<div><div class="lic-lbl">Montant payé</div><div class="lic-val">' . $prix . ' F CFA</div></div>';
    $html .= '</div>';
    $html .= '<div class="lic-cond"><h3>Conditions de la licence</h3><ul>' . $cond_html . '</ul></div>';
    $html .= '<div class="lic-seal"><div class="lic-seal-line"></div>';
    $html .= '<div class="lic-seal-txt">Émis par KM STUDIO · studio.kophismusic.com · contact@kophismusic.com</div></div>';
    $html .= '</div>';
    $html .= '<div class="lic-foot">';
    $html .= '<div class="lic-burida"><strong>BURIDA — Droits d\'auteur ivoiriens</strong><br>';
    $html .= 'Licence conforme à la réglementation ivoirienne.<br>';
    $html .= 'Toute utilisation hors cadre engage la responsabilité du licencié.</div>';
    $html .= '<div class="lic-qr"><img src="' . esc_url($qr_url) . '" width="80" height="80" alt="QR">';
    $html .= '<span>VÉRIFIER</span></div></div></div>';
    $html .= '<button class="print-btn" onclick="window.print()">⬇ IMPRIMER / PDF</button>';
    $html .= '</div>';
    $html .= '<script>if(new URLSearchParams(location.search).get("print")==="1")setTimeout(()=>window.print(),800);</script>';
    $html .= '</body></html>';

    return $html;
}


// ── Enregistrer les routes REST licence ───────────────────────
add_action('rest_api_init', 'kophi_register_licence_routes');
function kophi_register_licence_routes() {
    // Afficher / télécharger une licence
    register_rest_route('kophi/v1', '/licence/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'kophi_api_get_licence',
        'permission_callback' => '__return_true',
        'args'                => ['id' => ['required'=>true,'sanitize_callback'=>'absint']],
    ]);
    // Vérifier authenticité d'un numéro
    register_rest_route('kophi/v1', '/licence/verify', [
        'methods'             => 'GET',
        'callback'            => 'kophi_api_verify_licence',
        'permission_callback' => '__return_true',
    ]);
    // Émettre manuellement (admin)
    register_rest_route('kophi/v1', '/licence/emit/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'kophi_api_emit_licence',
        'permission_callback' => 'kophi_check_auth',
    ]);
}

function kophi_api_get_licence(WP_REST_Request $req) {
    $id    = (int)$req->get_param('id');
    $token = sanitize_text_field($req->get_param('token') ?: '');
    $post  = get_post($id);

    if (!$post || $post->post_type !== 'kophi_reservation') {
        wp_die('Licence introuvable.', 404);
    }

    $saved_token = get_post_meta($id, '_kophi_licence_token', true);
    if ($saved_token && $token !== $saved_token) {
        if (!current_user_can('administrator')) wp_die('Accès refusé.', 403);
    }

    // Vérifier qu'une licence a été émise
    if (!get_post_meta($id, '_kophi_licence_issued', true)) {
        wp_die('Licence non encore émise. Contactez KOPHI\'S MUSIC STUDIO.', 403);
    }

    $data = [
        'numero'       => get_post_meta($id, '_kophi_licence_numero', true),
        'beat_titre'   => get_post_meta($id, '_kophi_beat_titre', true),
        'nom_artiste'  => get_post_meta($id, '_kophi_nom_artiste', true),
        'licence_type' => get_post_meta($id, '_kophi_licence_type', true) ?: 'non_exclusive',
        'prix'         => number_format((int)get_post_meta($id, '_kophi_forfait', true), 0, ',', ' '),
        'date'         => get_post_meta($id, '_kophi_licence_date', true) ?: get_the_date('Y-m-d', $post),
    ];

    // Retourner le HTML en réponse directe
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="licence-' . $data['numero'] . '.html"');
    echo kophi_get_licence_html($data);
    exit;
}

function kophi_api_verify_licence(WP_REST_Request $req) {
    $num = sanitize_text_field($req->get_param('num') ?: '');
    if (!$num) return new WP_Error('missing', 'Numéro manquant', ['status'=>400]);

    global $wpdb;
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_kophi_licence_numero' AND meta_value=%s LIMIT 1",
        $num
    ));

    if (!$post_id) {
        return rest_ensure_response(['valid'=>false,'message'=>'Numéro de licence invalide ou introuvable.']);
    }

    return rest_ensure_response([
        'valid'       => true,
        'numero'      => $num,
        'beat'        => get_post_meta($post_id, '_kophi_beat_titre', true),
        'licencie'    => get_post_meta($post_id, '_kophi_nom_artiste', true),
        'type'        => get_post_meta($post_id, '_kophi_licence_type', true),
        'date'        => get_post_meta($post_id, '_kophi_licence_date', true),
        'verified_at' => current_time('mysql'),
    ]);
}

function kophi_api_emit_licence(WP_REST_Request $req) {
    $id   = (int)$req->get_param('id');
    $post = get_post($id);
    if (!$post) return new WP_Error('not_found','Commande introuvable',['status'=>404]);

    $num  = get_post_meta($id, '_kophi_licence_numero', true);
    if (!$num) {
        $num = kophi_generate_licence_number();
        $type = get_post_meta($id, '_kophi_licence_type', true) ?: 'non_exclusive';
        kophi_save_licence($id, $num, $type);
    }

    $url = kophi_get_licence_url($id);
    return rest_ensure_response(['success'=>true,'numero'=>$num,'url'=>$url]);
}
