<?php
/**
 * KOPHI'S MUSIC STUDIO — Corrections & Compatibilité Thème
 * =========================================================
 * Corrige :
 *  1. Pleine largeur pour /reserver/ et /beats/ (Astra + tout thème)
 *  2. Redirection archive /beats/ → page boutique shortcode
 *  3. Anciens shortcodes [km_*] → redirigent vers les nouvelles pages
 *  4. [kophi_dashboard] front-end fonctionnel
 *  5. Body classes pour cibler nos pages en CSS
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// 1. PLEINE LARGEUR — Forcer le template "full-width" sur nos pages
//    Compatible : Astra, OceanWP, GeneratePress, Elementor, Hello
// ============================================================

add_filter('template_include', 'kophi_force_fullwidth_template', 99);
function kophi_force_fullwidth_template($template) {
    if (!kophi_is_our_page()) return $template;

    // Chercher un template full-width dans le thème actif
    $candidates = [
        'template-full-width.php',
        'templates/template-full-width.php',
        'page-fullwidth.php',
        'full-width.php',
        'page-no-sidebar.php',
    ];
    foreach ($candidates as $tpl) {
        $found = locate_template($tpl);
        if ($found) return $found;
    }
    return $template;
}

// Astra : forcer le layout full-width via son filtre natif
add_filter('astra_page_layout', 'kophi_astra_fullwidth');
function kophi_astra_fullwidth($layout) {
    if (kophi_is_our_page()) return 'no-sidebar';
    return $layout;
}

// Astra : supprimer le content-width container sur nos pages
add_filter('astra_content_width', 'kophi_astra_content_width');
function kophi_astra_content_width($width) {
    if (kophi_is_our_page()) return 100;
    return $width;
}

// Astra : forcer "full-width" via meta post (équivalent à choisir dans l'éditeur)
add_action('wp', 'kophi_set_astra_fullwidth_meta');
function kophi_set_astra_fullwidth_meta() {
    if (!kophi_is_our_page()) return;
    global $post;
    if (!$post) return;
    // Astra lit ces metas pour le layout
    add_filter('get_post_metadata', function($val, $object_id, $meta_key) use ($post) {
        if ($object_id !== $post->ID) return $val;
        if ($meta_key === 'site-sidebar-layout')   return ['no-sidebar'];
        if ($meta_key === 'site-content-layout')   return ['full-width'];
        if ($meta_key === 'ast-site-content-layout') return ['full-width'];
        if ($meta_key === 'ast-main-header-display') return ['disabled'];
        return $val;
    }, 10, 3);
}

// Body classes pour nos pages
add_filter('body_class', 'kophi_body_classes');
function kophi_body_classes($classes) {
    if (is_page() && has_shortcode(get_post()->post_content ?? '', 'kophi_reservation')) {
        $classes[] = 'kophi-page-reservation';
        $classes[] = 'kophi-fullwidth';
    }
    if (is_page() && has_shortcode(get_post()->post_content ?? '', 'kophi_beats')) {
        $classes[] = 'kophi-page-beats';
        $classes[] = 'kophi-fullwidth';
    }
    if (is_page() && has_shortcode(get_post()->post_content ?? '', 'kophi_dashboard')) {
        $classes[] = 'kophi-page-dashboard';
        $classes[] = 'kophi-fullwidth';
    }
    return $classes;
}

// Helper : détecte si on est sur une de nos pages
function kophi_is_our_page(): bool {
    if (!is_singular('page')) return false;
    global $post;
    if (!$post) return false;
    $our_shortcodes = ['kophi_reservation', 'kophi_beats', 'kophi_dashboard'];
    foreach ($our_shortcodes as $sc) {
        if (has_shortcode($post->post_content, $sc)) return true;
    }
    return false;
}

// CSS inline : supprimer paddings/marges du thème sur nos pages
add_action('wp_head', 'kophi_fullwidth_css');
function kophi_fullwidth_css() {
    if (!kophi_is_our_page()) return;
    ?>
    <style id="kophi-fullwidth-css">
        /* ── KOPHI Full Width — bypass Astra + Elementor ── */

        /* 1. Fond global de la page */
        body.kophi-fullwidth,
        body.kophi-fullwidth #page,
        body.kophi-fullwidth .site {
            background: linear-gradient(to bottom, #0D0D0D 0%, #0D0D0D 60%, #120a24 85%, #1e0938 100%) !important;
            background-attachment: fixed !important;
            min-height: 100vh !important;
        }

        /* 2. Supprimer TOUS les conteneurs Astra */
        body.kophi-fullwidth #content,
        body.kophi-fullwidth .site-content,
        body.kophi-fullwidth .content-area,
        body.kophi-fullwidth #primary,
        body.kophi-fullwidth .ast-container,
        body.kophi-fullwidth .ast-page-builder-template,
        body.kophi-fullwidth main.site-main,
        body.kophi-fullwidth .page-content,
        body.kophi-fullwidth article.page,
        body.kophi-fullwidth .entry-content,
        body.kophi-fullwidth .post-content {
            max-width: 100% !important;
            width: 100% !important;
            padding: 0 !important;
            margin: 0 auto !important;
            float: none !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }

        /* 3. Supprimer titres, breadcrumbs, marges Astra */
        body.kophi-fullwidth .entry-header,
        body.kophi-fullwidth .ast-breadcrumbs-wrap,
        body.kophi-fullwidth .page-header,
        body.kophi-fullwidth .ast-page-header,
        body.kophi-fullwidth .elementor-page-title,
        body.kophi-fullwidth h1.entry-title,
        body.kophi-fullwidth h2.entry-title {
            display: none !important;
        }

        /* 4. Masquer sidebar */
        body.kophi-fullwidth #secondary,
        body.kophi-fullwidth .widget-area,
        body.kophi-fullwidth aside.sidebar,
        body.kophi-fullwidth .ast-right-sidebar,
        body.kophi-fullwidth .ast-left-sidebar {
            display: none !important;
        }

        /* 5. Notre wrapper prend tout */
        /* Hello Elementor full-width fix */
body.kophi-fullwidth #content, body.kophi-fullwidth .entry-content, body.kophi-fullwidth main { max-width:100%!important; padding:0!important; }
body.kophi-fullwidth .entry-header { display:none!important; }

body.kophi-fullwidth .kophi-front-wrap {
            width: 100vw !important;
            max-width: 100vw !important;
            margin-left: calc(-50vw + 50%) !important;
            margin-right: calc(-50vw + 50%) !important;
            padding: 0 !important;
            border-radius: 0 !important;
            background: linear-gradient(to bottom, #0D0D0D 0%, #0D0D0D 60%, #120a24 85%, #1e0938 100%) !important;
            background-attachment: fixed !important;
            min-height: unset !important;
        }
        /* Fix espace vertical entre contenu et footer */
        body.kophi-fullwidth .site-content,
        body.kophi-fullwidth #content,
        body.kophi-fullwidth #primary,
        body.kophi-fullwidth main.site-main {
            padding-bottom: 0 !important;
            margin-bottom: 0 !important;
        }
        body.kophi-fullwidth .entry-content {
            padding-bottom: 0 !important;
            margin-bottom: 0 !important;
        }

        /* 6. Header Astra transparent sur nos pages */
        body.kophi-fullwidth .site-header,
        body.kophi-fullwidth #masthead {
            background: rgba(13,13,13,.96) !important;
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
            border-bottom: 1px solid rgba(232,184,75,.15) !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1000 !important;
        }

        /* 7. Footer */
        body.kophi-fullwidth .site-footer,
        body.kophi-fullwidth #colophon {
            background: #0D0D0D !important;
            border-top: 1px solid rgba(255,255,255,.06) !important;
            color: rgba(255,255,255,.3) !important;
        }
        body.kophi-fullwidth .site-footer a,
        body.kophi-fullwidth .site-footer .site-info {
            color: rgba(255,255,255,.3) !important;
        }
    </style>
    <?php
}

// ============================================================
// 2. ARCHIVE /beats/ — Désactiver l'archive CPT pour éviter
//    le conflit avec la page WordPress au même slug /beats/
//    La page WP avec [kophi_beats] prend le dessus naturellement
// ============================================================

// Désactiver has_archive sur le CPT beats après son enregistrement
add_action('init', 'kophi_disable_beats_archive', 20);
function kophi_disable_beats_archive() {
    global $wp_post_types;
    if (isset($wp_post_types['kophi_beat'])) {
        $wp_post_types['kophi_beat']->has_archive = false;
        // Mettre le slug CPT différent de la page pour éviter tout conflit
        $wp_post_types['kophi_beat']->rewrite = [
            'slug'       => 'beat',   // singular /beat/xxx — pas /beats/
            'with_front' => false,
        ];
    }
}

// Flush des rewrite rules une seule fois après ce changement
add_action('init', function() {
    if (get_option('kophi_beats_slug_fixed') !== '2') {
        update_option('kophi_flush_needed', true);
        update_option('kophi_beats_slug_fixed', '2');
    }
}, 25);

// ============================================================
// 3. ANCIENS SHORTCODES [km_*] — Compatibilité / Redirections
//    Ces shortcodes viennent d'un ancien plugin désactivé.
//    On les réenregistre pour éviter qu'ils s'affichent en texte brut.
// ============================================================

// [km_login_form] → formulaire de connexion WP natif
add_shortcode('km_login_form', function($atts) {
    if (is_user_logged_in()) {
        $dash = get_page_by_path('dashboard') ?? get_page_by_path('mon-tableau-de-bord');
        $url  = $dash ? get_permalink($dash) : admin_url();
        return '<div style="text-align:center;padding:30px;font-family:sans-serif">
            <p>Vous êtes déjà connecté.</p>
            <a href="' . esc_url($url) . '" style="background:#E8B84B;color:#000;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700">Mon tableau de bord →</a>
        </div>';
    }
    return wp_login_form(['echo' => false, 'redirect' => admin_url('admin.php?page=kophi-dashboard')]);
});

// [km_certification_page] → message d'information
add_shortcode('km_certification_page', function($atts) {
    return '<div style="padding:40px 20px;text-align:center;font-family:sans-serif;color:#333">
        <h2>Certification Artiste</h2>
        <p>Cette fonctionnalité sera disponible prochainement.</p>
        <p>Pour toute demande, contactez-nous sur WhatsApp :</p>
        <a href="https://wa.me/2250503404389" target="_blank"
           style="display:inline-block;background:#25D366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:10px">
           💬 +225 05 03 40 43 89
        </a>
    </div>';
});

// [km_label_dashboard] → redirection vers admin KOPHI
add_shortcode('km_label_dashboard', function($atts) {
    if (!is_user_logged_in()) {
        return '<div style="text-align:center;padding:30px;font-family:sans-serif">
            <p>Accès réservé à l\'équipe KOPHI\'S MUSIC STUDIO.</p>
            <a href="' . esc_url(wp_login_url(get_permalink())) . '"
               style="background:#E8B84B;color:#000;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700">
               Se connecter →
            </a>
        </div>';
    }
    // Rediriger vers notre dashboard admin
    return '<script>window.location.href="' . admin_url('admin.php?page=kophi-dashboard') . '";</script>
    <div style="text-align:center;padding:30px;font-family:sans-serif">
        <p>Redirection vers le tableau de bord...</p>
        <a href="' . admin_url('admin.php?page=kophi-dashboard') . '"
           style="background:#E8B84B;color:#000;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700">
           Tableau de bord KOPHI →
        </a>
    </div>';
});

// [km_artist_dashboard] → tableau de bord artiste simplifié
add_shortcode('km_artist_dashboard', function($atts) {
    if (!is_user_logged_in()) {
        return '<div style="text-align:center;padding:30px;font-family:sans-serif">
            <p>Connectez-vous pour accéder à votre espace artiste.</p>
            <a href="' . esc_url(wp_login_url(get_permalink())) . '"
               style="background:#8B3FD9;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700">
               Se connecter →
            </a>
        </div>';
    }
    $user = wp_get_current_user();
    $reserver_page = get_page_by_path('reserver');
    $beats_page    = get_page_by_path('beats') ?? get_page_by_path('boutique-beats');
    return '<div style="font-family:sans-serif;padding:30px 20px;max-width:600px;margin:0 auto">
        <h2 style="color:#E8B84B">Bonjour, ' . esc_html($user->display_name) . ' 👋</h2>
        <p style="color:#666;margin-bottom:24px">Bienvenue dans votre espace artiste KOPHI\'S MUSIC STUDIO.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            ' . ($reserver_page ? '<a href="' . get_permalink($reserver_page) . '" style="background:#1E1E26;border:1px solid rgba(232,184,75,.3);border-radius:12px;padding:20px;text-decoration:none;text-align:center;color:#E8B84B;font-weight:700">🎙 Réserver une session</a>' : '') . '
            ' . ($beats_page ? '<a href="' . get_permalink($beats_page) . '" style="background:#1E1E26;border:1px solid rgba(139,63,217,.3);border-radius:12px;padding:20px;text-decoration:none;text-align:center;color:#C084FC;font-weight:700">🎹 Boutique Beats</a>' : '') . '
            <a href="https://wa.me/2250503404389" target="_blank" style="background:#1E1E26;border:1px solid rgba(37,211,102,.3);border-radius:12px;padding:20px;text-decoration:none;text-align:center;color:#25D366;font-weight:700">💬 Contacter le studio</a>
            <a href="' . wp_logout_url(home_url()) . '" style="background:#1E1E26;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px;text-decoration:none;text-align:center;color:#999;font-weight:700">🚪 Se déconnecter</a>
        </div>
    </div>';
});

// ============================================================
// 4. [kophi_dashboard] — Dashboard front-end pour l'équipe
// ============================================================

add_shortcode('kophi_dashboard', 'kophi_shortcode_dashboard_front');
function kophi_shortcode_dashboard_front($atts) {
    if (!is_user_logged_in()) {
        return '<div style="text-align:center;padding:40px;font-family:sans-serif;background:#0E0E12;border-radius:12px;color:#E0DCEB">
            <div style="font-size:40px;margin-bottom:16px">🎙</div>
            <h2 style="color:#E8B84B;font-family:\'Bebas Neue\',sans-serif;letter-spacing:2px;font-size:28px">KOPHI\'S MUSIC STUDIO</h2>
            <p style="color:#9E9AB0;margin-bottom:20px">Accès réservé à l\'équipe du studio.</p>
            <a href="' . esc_url(wp_login_url(get_permalink())) . '"
               style="background:#E8B84B;color:#000;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:13px">
               🔐 Se connecter
            </a>
        </div>';
    }
    if (!current_user_can('kophi_manage_sessions') && !current_user_can('administrator')) {
        return '<div style="text-align:center;padding:30px;font-family:sans-serif;color:#333">
            <p>Vous n\'avez pas les droits pour accéder à cette page.</p>
            <a href="' . admin_url() . '">Retour à l\'accueil →</a>
        </div>';
    }
    // Rediriger vers le vrai dashboard admin KOPHI
    return '<script>window.location.href="' . admin_url('admin.php?page=kophi-dashboard') . '";</script>
    <div style="text-align:center;padding:30px;font-family:sans-serif;background:#0E0E12;border-radius:12px;color:#E0DCEB">
        <p>Redirection vers le tableau de bord...</p>
        <a href="' . admin_url('admin.php?page=kophi-dashboard') . '"
           style="background:#E8B84B;color:#000;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700">
           Tableau de bord KOPHI →
        </a>
    </div>';
}

// ============================================================
// 5. FLUSH REWRITE RULES — Si archive beats est en 404
// ============================================================

add_action('init', function() {
    // Forcer la mise à jour des permaliens si nécessaire
    if (get_option('kophi_flush_needed')) {
        flush_rewrite_rules(false);
        delete_option('kophi_flush_needed');
    }
});
