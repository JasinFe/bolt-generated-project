<?php
/**
 * Plugin Name: KOPHI'S MUSIC — Artist Dashboard
 * Plugin URI:  https://kophismusic.com
 * Description: Tableau de bord premium pour artistes et label — Urban Gospel
 * Version:     5.8.0
 * Author:      KOPHI'S MUSIC
 * Text Domain: kophismusic
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'KM_VERSION' ) )    define( 'KM_VERSION',    '5.8.0' );
if ( ! defined( 'KM_PLUGIN_DIR' ) ) define( 'KM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'KM_PLUGIN_URL' ) ) define( 'KM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'KM_LOGO_URL' ) )   define( 'KM_LOGO_URL',   'https://kophismusic.com/wp-content/uploads/2025/11/LOGO-KOPHIS-MUSIC.svg' );
if ( ! defined( 'KM_EUR_TO_XOF' ) ) define( 'KM_EUR_TO_XOF', 655.957 );

// ══════════════════════════════════════════════════════════════
// CHARGEMENT DES MODULES
// ══════════════════════════════════════════════════════════════
require_once KM_PLUGIN_DIR . 'includes/icons.php';
require_once KM_PLUGIN_DIR . 'includes/cpt-register.php';
require_once KM_PLUGIN_DIR . 'includes/roles.php';
require_once KM_PLUGIN_DIR . 'includes/login.php';
require_once KM_PLUGIN_DIR . 'includes/artist-dashboard.php';
require_once KM_PLUGIN_DIR . 'includes/label-dashboard.php';
require_once KM_PLUGIN_DIR . 'includes/importer.php';
require_once KM_PLUGIN_DIR . 'includes/pdf-export.php';
require_once KM_PLUGIN_DIR . 'includes/releve.php';
require_once KM_PLUGIN_DIR . 'includes/km-family-bridge.php';
require_once KM_PLUGIN_DIR . 'includes/km-family-publish.php';

// ══════════════════════════════════════════════════════════════
// ACTIVATION
// ══════════════════════════════════════════════════════════════
register_activation_hook( __FILE__, 'km_plugin_activate' );
register_deactivation_hook( __FILE__, 'km_plugin_deactivate' );
add_action( 'admin_init', 'km_maybe_run_setup' );

function km_plugin_activate() { km_run_setup(); }

function km_plugin_deactivate() {
    flush_rewrite_rules();
    delete_option( 'km_setup_done_v' . KM_VERSION );
}

function km_maybe_run_setup() {
    if ( get_option( 'km_setup_done_v' . KM_VERSION ) ) return;
    km_run_setup();
    update_option( 'km_setup_done_v' . KM_VERSION, 1 );
}

function km_run_setup() {
    km_register_all_cpts();
    km_create_roles();
    flush_rewrite_rules( true );
    km_create_dashboard_pages();
}

// ══════════════════════════════════════════════════════════════
// CREATION DES PAGES DASHBOARD
// ══════════════════════════════════════════════════════════════
function km_create_dashboard_pages() {
    $pages = array(
        array( 'title' => 'Mon Tableau de Bord',  'slug' => 'mon-tableau-de-bord',   'shortcode' => '[km_artist_dashboard]'    ),
        array( 'title' => 'Dashboard Label',      'slug' => 'dashboard-label',       'shortcode' => '[km_label_dashboard]'     ),
        array( 'title' => 'Connexion Artiste',    'slug' => 'connexion-artiste',     'shortcode' => '[km_login_form]'          ),
        array( 'title' => 'Certification Artiste','slug' => 'certification-artiste', 'shortcode' => '[km_certification_page]'  ),
    );
    foreach ( $pages as $page ) {
        $existing = get_page_by_path( $page['slug'] );
        if ( $existing ) {
            if ( trim( $existing->post_content ) !== $page['shortcode'] || $existing->post_status !== 'publish' ) {
                wp_update_post( array(
                    'ID'           => $existing->ID,
                    'post_content' => $page['shortcode'],
                    'post_status'  => 'publish',
                    'post_name'    => $page['slug'],
                ) );
            }
            update_option( 'km_page_id_' . sanitize_key( $page['slug'] ), $existing->ID );
        } else {
            $new_id = wp_insert_post( array(
                'post_title'   => $page['title'],
                'post_name'    => $page['slug'],
                'post_content' => $page['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ) );
            if ( $new_id && ! is_wp_error( $new_id ) ) {
                update_option( 'km_page_id_' . sanitize_key( $page['slug'] ), $new_id );
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════
// URL SYSTEM — Compatible Nginx/Hostinger (utilise ?page_id=)
// ══════════════════════════════════════════════════════════════
function km_get_page_id( $slug ) {
    $opt_key = 'km_page_id_' . sanitize_key( $slug );
    $stored  = (int) get_option( $opt_key, 0 );
    if ( $stored ) return $stored;
    // Fallback UNIQUEMENT si rien en option (évite boucles infinies)
    $page = get_page_by_path( $slug );
    if ( $page ) {
        update_option( $opt_key, $page->ID );
        return $page->ID;
    }
    return 0;
}

function km_dashboard_url( $slug ) {
    $id = km_get_page_id( $slug );
    if ( ! $id ) return home_url( '/' );

    $permalink_structure = get_option( 'permalink_structure' );
    if ( $permalink_structure ) {
        $url = get_permalink( $id );
        if ( $url ) return $url;
    }
    return home_url( '/?page_id=' . $id );
}

// ══════════════════════════════════════════════════════════════
// DETECTION PAGE DASHBOARD — SAFE (pas de the_content hook)
// Utilise uniquement $post et $_GET, pas de requêtes BDD
// ══════════════════════════════════════════════════════════════
function km_is_dashboard_page() {
    global $post;

    // Méthode rapide : ID du post courant
    if ( $post && $post->ID ) {
        $slugs = array( 'mon-tableau-de-bord', 'dashboard-label', 'connexion-artiste', 'certification-artiste' );
        foreach ( $slugs as $slug ) {
            $stored = (int) get_option( 'km_page_id_' . sanitize_key( $slug ), 0 );
            if ( $stored === (int) $post->ID ) return true;
        }
    }

    // Méthode GET : ?page_id=X
    if ( isset( $_GET['page_id'] ) ) {
        $pid = (int) $_GET['page_id'];
        $slugs = array( 'mon-tableau-de-bord', 'dashboard-label', 'connexion-artiste', 'certification-artiste' );
        foreach ( $slugs as $slug ) {
            $stored = (int) get_option( 'km_page_id_' . sanitize_key( $slug ), 0 );
            if ( $stored === $pid ) return true;
        }
    }

    // Méthode pagename
    if ( isset( $_GET['pagename'] ) ) {
        $slugs = array( 'mon-tableau-de-bord', 'dashboard-label', 'connexion-artiste', 'certification-artiste' );
        if ( in_array( $_GET['pagename'], $slugs, true ) ) return true;
    }

    return false;
}

// ══════════════════════════════════════════════════════════════
// REDIRECTION ANTICIPÉE — pages dashboard protégées
// Si non connecté et tente d'accéder au dashboard → login
// Si connecté et tente d'accéder à une page reservée → dashboard
// Fonctionne AVANT tout output HTML (headers non encore envoyés)
// ══════════════════════════════════════════════════════════════
add_action( 'template_redirect', 'km_dashboard_access_control', 5 );
function km_dashboard_access_control() {
    // Pages dashboard artiste/label : non connecté → vers connexion
    $protected = array( 'mon-tableau-de-bord', 'dashboard-label', 'certification-artiste' );
    foreach ( $protected as $slug ) {
        $pid = km_get_page_id( $slug );
        if ( $pid && is_page( $pid ) && ! is_user_logged_in() ) {
            wp_safe_redirect( km_dashboard_url( 'connexion-artiste' ), 302 );
            exit;
        }
    }
}

// ══════════════════════════════════════════════════════════════
// TEMPLATE BLANK — FORCE PLEINE PAGE (safe)
// ══════════════════════════════════════════════════════════════
add_filter( 'template_include', 'km_force_blank_template', 999 );
function km_force_blank_template( $template ) {
    if ( ! km_is_dashboard_page() ) return $template;
    $blank = KM_PLUGIN_DIR . 'templates/blank.php';
    if ( file_exists( $blank ) ) return $blank;
    return $template;
}

// ══════════════════════════════════════════════════════════════
// ASTRA — DESACTIVATION CIBLEE (uniquement sur pages dashboard)
// ══════════════════════════════════════════════════════════════
add_action( 'wp', 'km_disable_astra_on_dashboard' );
function km_disable_astra_on_dashboard() {
    if ( ! km_is_dashboard_page() ) return;

    add_filter( 'show_admin_bar', '__return_false' );

    // ── Astra : header + footer + wrappers ──
    remove_all_actions( 'astra_header' );
    remove_all_actions( 'astra_footer' );
    remove_all_actions( 'astra_footer_content' );
    remove_all_actions( 'astra_masthead_top' );
    remove_all_actions( 'astra_masthead_bottom' );
    remove_all_actions( 'astra_content_before' );
    remove_all_actions( 'astra_content_top' );
    remove_all_actions( 'astra_content_after' );
    remove_all_actions( 'astra_content_bottom' );

    // ── Autres thèmes / footer builders ──
    remove_all_actions( 'generate_footer' );
    remove_all_actions( 'ocean_footer' );
    remove_all_actions( 'storefront_footer' );
    remove_all_actions( 'neve_do_footer' );
    remove_all_actions( 'hfe_footer_content' );

    // ── Elementor Header & Footer Builder (HFB) ──
    remove_all_actions( 'elementor/page_templates/canvas/after_content' );
    add_filter( 'hfe_render_footer', '__return_false' );
    add_filter( 'hfe_render_header', '__return_false' );
}

// ══════════════════════════════════════════════════════════════
// BODY CLASS
// ══════════════════════════════════════════════════════════════
add_filter( 'body_class', 'km_add_body_class' );
function km_add_body_class( $classes ) {
    if ( km_is_dashboard_page() ) $classes[] = 'km-dashboard-page';
    return $classes;
}

// ══════════════════════════════════════════════════════════════
// ENQUEUE ASSETS (uniquement sur pages dashboard)
// ══════════════════════════════════════════════════════════════
add_action( 'wp_enqueue_scripts', 'km_enqueue_assets' );
function km_enqueue_assets() {
    if ( ! km_is_dashboard_page() ) return;

    wp_enqueue_style(  'km-fonts',     'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=DM+Sans:wght@300;400;500;700&family=JetBrains+Mono:wght@400;600&display=swap', array(), null );
    wp_enqueue_style(  'km-dashboard', KM_PLUGIN_URL . 'assets/dashboard.css', array( 'km-fonts' ), KM_VERSION );
    wp_enqueue_style(  'km-releve',    KM_PLUGIN_URL . 'assets/releve.css',    array( 'km-fonts' ), KM_VERSION );
    wp_enqueue_style(  'km-login',     KM_PLUGIN_URL . 'assets/login.css',     array( 'km-fonts' ), KM_VERSION );

    wp_enqueue_script( 'chartjs',      'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',      array(), '4.4.1', true );
    wp_enqueue_script( 'jspdf',        'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',         array(), '2.5.1', true );
    wp_enqueue_script( 'html2canvas',  'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', array(), '1.4.1', true );
}

// ══════════════════════════════════════════════════════════════
// HELPER COVER — utilisé par les dashboards
// ══════════════════════════════════════════════════════════════
if ( ! function_exists( 'km_get_release_cover' ) ) {
    function km_get_release_cover( $release_title, $upc ) {
        $cover = '';
        if ( $upc ) {
            $disc = get_posts( array(
                'post_type'      => 'discographies',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'meta_query'     => array( array( 'key' => 'disc_upc', 'value' => $upc, 'compare' => '=' ) ),
            ) );
            if ( $disc ) {
                $raw = get_field( 'disc_cover', $disc[0]->ID );
                if ( is_array( $raw ) && isset( $raw['url'] ) ) $cover = $raw['url'];
                elseif ( is_string( $raw ) && $raw )            $cover = $raw;
                if ( ! $cover ) $cover = (string) get_the_post_thumbnail_url( $disc[0]->ID, 'medium' );
            }
        }
        if ( ! $cover && $release_title ) {
            $disc2 = get_posts( array(
                'post_type'      => 'discographies',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'title'          => $release_title,
            ) );
            if ( $disc2 ) {
                $raw2 = get_field( 'disc_cover', $disc2[0]->ID );
                if ( is_array( $raw2 ) && isset( $raw2['url'] ) ) $cover = $raw2['url'];
                elseif ( is_string( $raw2 ) && $raw2 )            $cover = $raw2;
                if ( ! $cover ) $cover = (string) get_the_post_thumbnail_url( $disc2[0]->ID, 'medium' );
            }
        }
        return $cover;
    }
}

// ══════════════════════════════════════════════════════════════
// ADMIN MENU
// ══════════════════════════════════════════════════════════════
add_action( 'admin_menu', 'km_register_admin_menu' );
function km_register_admin_menu() {
    add_menu_page( 'KM Dashboard', 'KM Dashboard', 'manage_options', 'km-dashboard-menu', 'km_admin_overview_page', 'dashicons-format-audio', 25 );
    add_submenu_page( 'km-dashboard-menu', 'Vue d\'ensemble', 'Vue d\'ensemble', 'manage_options', 'km-dashboard-menu',   'km_admin_overview_page' );
    add_submenu_page( 'km-dashboard-menu', 'Réglages',        'Réglages',        'manage_options', 'km-settings',         'km_admin_settings_page' );
    add_submenu_page( 'km-dashboard-menu', 'Mapping Artistes','Mapping Artistes','manage_options', 'km-mapping',          'km_admin_mapping_page' );
    add_submenu_page( 'km-dashboard-menu', 'Collaborations',  'Collaborations',  'manage_options', 'km-collabs',          'km_admin_collabs_page' );
    add_submenu_page( 'km-dashboard-menu', 'Nettoyage Collabs','🧹 Nettoyage Collabs','manage_options', 'km-collabs-cleanup', 'km_admin_collabs_cleanup_page' );
    add_submenu_page( 'km-dashboard-menu', 'Réparer Encodage', '🔧 Réparer Encodage', 'manage_options', 'km-encoding-repair',  'km_admin_encoding_repair_page' );
    add_submenu_page( 'km-dashboard-menu', 'Commissions Titres','Commissions par titre','manage_options', 'km-track-commissions', 'km_admin_track_commissions_page' );
    add_submenu_page( 'km-dashboard-menu', 'Import TuneCore', 'Import TuneCore', 'manage_options', 'km-tunecore-import',  'km_admin_import_page' );
}

function km_admin_overview_page() {
    $total_r  = wp_count_posts( 'rapport_mensuel' )->publish;
    $total_p  = wp_count_posts( 'paiement' )->publish;
    $total_a  = wp_count_posts( 'nos-artistes' )->publish;
    $tc_artists = function_exists( 'km_get_all_tunecore_artists' ) ? km_get_all_tunecore_artists() : array();
    $g_streams = 0; $g_gains = 0.0;
    $rapports = get_posts( array( 'post_type' => 'rapport_mensuel', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) );
    foreach ( $rapports as $rid ) {
        $ts = (int) get_post_meta( $rid, '_km_total_streams', true );
        $tg = (float) get_post_meta( $rid, '_km_total_gains', true );
        if ( ! $ts ) {
            foreach ( array( 'spotify','apple','youtube','deezer','tidal' ) as $p ) {
                $ts += (int) get_field( $p . '_streams', $rid );
            }
        }
        if ( ! $tg ) {
            foreach ( array( 'spotify','apple','youtube','deezer','tidal' ) as $p ) {
                $tg += (float) get_field( $p . '_gains', $rid );
            }
        }
        $g_streams += $ts; $g_gains += $tg;
    }
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
            <span style="font-size:1.4em;">🎵</span> KM Dashboard — <em style="color:#F5A623;font-style:normal;">KOPHI'S MUSIC</em>
            <span style="font-size:.7em;background:#1e3a5f;color:#7ec8e3;padding:3px 10px;border-radius:4px;font-weight:400;">v<?php echo KM_VERSION; ?></span>
        </h1>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;">
        <?php foreach ( array(
            array( 'label'=>'Total Streams','value'=>number_format( $g_streams, 0, ',', ' ' ),'color'=>'#F5A623','icon'=>'🎵' ),
            array( 'label'=>'Gains Bruts',  'value'=>number_format( $g_gains, 4, ',', '' ) . ' €','color'=>'#00D67F','icon'=>'💰' ),
            array( 'label'=>'Rapports',     'value'=>$total_r,'color'=>'#4C9FFF','icon'=>'📊' ),
            array( 'label'=>'Artistes',     'value'=>$total_a,'color'=>'#FF6B9E','icon'=>'🎤' ),
        ) as $k ) : ?>
        <div style="background:#1e2535;border:1px solid #2d3a50;border-radius:10px;padding:18px 20px;border-top:3px solid <?php echo $k['color']; ?>;">
            <div style="font-size:1.5em;margin-bottom:6px;"><?php echo $k['icon']; ?></div>
            <div style="font-size:1.6rem;font-weight:800;color:<?php echo $k['color']; ?>;font-family:system-ui;"><?php echo $k['value']; ?></div>
            <div style="font-size:.78rem;color:#7a8faa;margin-top:3px;"><?php echo $k['label']; ?></div>
        </div>
        <?php endforeach; ?>
        </div>

        <div style="background:#1e2535;border:1px solid #2d3a50;border-radius:10px;padding:20px;margin-bottom:20px;">
            <h3 style="margin:0 0 14px;color:#EEF2FF;font-size:.95rem;">🔗 Accès direct aux pages</h3>
            <?php
            $direct_pages = array(
                array( 'label' => '🎤 Mon Tableau de Bord', 'slug' => 'mon-tableau-de-bord' ),
                array( 'label' => '🏷️ Dashboard Label',     'slug' => 'dashboard-label' ),
                array( 'label' => '🔐 Connexion Artiste',   'slug' => 'connexion-artiste' ),
            );
            foreach ( $direct_pages as $dp ) :
                $did  = km_get_page_id( $dp['slug'] );
                $durl = km_dashboard_url( $dp['slug'] );
            ?>
            <div style="display:flex;align-items:center;gap:14px;padding:10px 14px;margin:6px 0;background:#0d1120;border-radius:6px;border:1px solid #2d3a50;">
                <span style="color:#EEF2FF;font-weight:600;min-width:200px;"><?php echo $dp['label']; ?></span>
                <?php if ( $did ) : ?>
                    <span style="color:#00D67F;font-size:.78rem;">✅ ID:<?php echo $did; ?></span>
                <?php else : ?>
                    <span style="color:#ff7a7a;font-size:.78rem;">❌ Introuvable</span>
                <?php endif; ?>
                <a href="<?php echo esc_url( $durl ); ?>" target="_blank"
                   style="margin-left:auto;background:#F5A623;color:#000;font-weight:700;padding:6px 16px;border-radius:5px;text-decoration:none;font-size:.82rem;">Ouvrir →</a>
            </div>
            <?php endforeach; ?>
        </div>

        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1{color:#EEF2FF;}</style>
    </div>
    <?php
}

function km_admin_settings_page() {
    if ( isset( $_POST['km_settings_nonce'] ) && wp_verify_nonce( $_POST['km_settings_nonce'], 'km_save_settings' ) ) {
        update_option( 'km_label_commission', intval( $_POST['km_label_commission'] ) );
        update_option( 'km_label_name',       sanitize_text_field( $_POST['km_label_name'] ) );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Réglages sauvegardés.</p></div>';
    }

    if ( isset( $_POST['km_repair_pages'] ) && check_admin_referer( 'km_repair_pages' ) ) {
        km_create_dashboard_pages();
        flush_rewrite_rules( true );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Pages réparées avec succès.</p></div>';
    }

    $commission = get_option( 'km_label_commission', 30 );
    $label_name = get_option( 'km_label_name', 'KOPHI\'S MUSIC' );
    ?>
    <div class="wrap">
        <h1>⚙️ Réglages — KM Dashboard</h1>
        <form method="post" style="max-width:640px;margin-top:20px;">
            <?php wp_nonce_field( 'km_save_settings', 'km_settings_nonce' ); ?>
            <table class="form-table" style="background:#1e2535;border-radius:10px;border:1px solid #2d3a50;">
                <tr><th style="color:#c8d6e8;padding:16px 20px;">Nom du Label</th>
                    <td style="padding:16px 20px;"><input type="text" name="km_label_name" value="<?php echo esc_attr( $label_name ); ?>" class="regular-text" /></td></tr>
                <tr><th style="color:#c8d6e8;padding:16px 20px;">Commission Label (%)</th>
                    <td style="padding:16px 20px;"><input type="number" name="km_label_commission" value="<?php echo intval( $commission ); ?>" min="0" max="100" style="width:80px;" />
                        <span style="color:#7a8faa;font-size:.82rem;margin-left:8px;">% retenu (artiste reçoit <?php echo 100 - $commission; ?>%)</span></td></tr>
            </table>
            <p><input type="submit" class="button button-primary button-large" value="💾 Sauvegarder" /></p>
        </form>

        <hr style="border-color:#2d3a50;margin:28px 0;">
        <form method="post">
            <?php wp_nonce_field( 'km_repair_pages' ); ?>
            <input type="hidden" name="km_repair_pages" value="1">
            <button type="submit" class="button button-primary" style="background:#F5A623;border-color:#C07A10;color:#000;font-weight:700;">🔧 Réparer les pages</button>
        </form>
        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1{color:#EEF2FF;}</style>
    </div>
    <?php
}

function km_admin_mapping_page() {
    if ( isset( $_POST['km_mapping_nonce'] ) && wp_verify_nonce( $_POST['km_mapping_nonce'], 'km_save_mapping' ) ) {
        $mapping = get_option( 'km_tunecore_mapping', array() );
        foreach ( $_POST as $key => $val ) {
            if ( strpos( $key, 'km_map_' ) === 0 ) {
                $tc_name = urldecode( substr( $key, 7 ) );
                $uid     = intval( $val ); // -1 = "c'est le label", 0 = non mappé, >0 = user_id
                if ( $tc_name ) $mapping[ $tc_name ] = $uid;
            }
        }
        update_option( 'km_tunecore_mapping', $mapping );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Mapping sauvegardé.</p></div>';
    }
    $tc_artists = function_exists( 'km_get_all_tunecore_artists' ) ? km_get_all_tunecore_artists() : array();
    $mapping    = get_option( 'km_tunecore_mapping', array() );
    $wp_users   = get_users( array( 'role__in' => array( 'artiste_label','administrator' ), 'orderby' => 'display_name' ) );

    // Confort : le nom du label lui-même ("KOPHI'S MUSIC") revient très
    // souvent en co-crédit sur les featurings. S'il n'a encore jamais été
    // classé explicitement, on pré-sélectionne "🏷️ C'est le Label" par
    // défaut dans le formulaire ci-dessous (il suffit de cliquer
    // Sauvegarder pour confirmer) — rien n'est enregistré tant que ce
    // n'est pas validé.
    $label_default_names = array( "KOPHI'S MUSIC" );
    ?>
    <div class="wrap">
        <h1>🔗 Mapping Artistes TuneCore → WordPress</h1>
        <p style="color:#8a9aab;max-width:720px;font-size:13px;">
            Si un nom TuneCore correspond au <strong>label lui-même</strong> (ex: crédité en co-artiste sur un featuring,
            comme <code>"KOPHI'S MUSIC &amp; Mdrick Oninni"</code>) et non à un artiste réel, choisis
            <strong>"🏷️ C'est le Label"</strong> plutôt que de le laisser sur "— Non mappé —". Sa part de streams/gains
            sur ces lignes ne sera alors plus répartie comme si c'était un artiste : elle restera implicitement dans la
            part du label, et les vrais artistes du combo garderont la part qui leur revient normalement.
        </p>
        <?php if ( ! $tc_artists ) : ?>
        <p>Aucun artiste TuneCore. Importez d'abord un CSV.</p>
        <?php else : ?>
        <form method="post" style="max-width:760px;margin-top:20px;">
            <?php wp_nonce_field( 'km_save_mapping', 'km_mapping_nonce' ); ?>
            <table class="widefat" style="background:#1e2535;border-color:#2d3a50;">
                <thead><tr><th>Nom TuneCore</th><th>Utilisateur WordPress</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ( $tc_artists as $tc_name ) :
                    $has_explicit_mapping = isset( $mapping[ $tc_name ] );
                    $mapped_raw = $has_explicit_mapping ? (int) $mapping[ $tc_name ] : 0;
                    if ( ! $has_explicit_mapping && in_array( $tc_name, $label_default_names, true ) ) {
                        $mapped_raw = -1; // pré-sélection de confort, non enregistrée tant que non sauvegardée
                    }
                    $is_label   = $mapped_raw === -1;
                    $mapped_uid = $mapped_raw > 0 ? $mapped_raw : 0;
                    $mapped_u   = $mapped_uid ? get_user_by( 'id', $mapped_uid ) : null;
                ?>
                <tr>
                    <td><code style="background:#0d1120;padding:2px 7px;"><?php echo esc_html( $tc_name ); ?></code></td>
                    <td>
                        <select name="km_map_<?php echo urlencode( $tc_name ); ?>" style="background:#0d1120;color:#EEF2FF;border-color:#2d3a50;min-width:220px;">
                            <option value="0" <?php selected( ! $is_label && ! $mapped_uid, true ); ?>>— Non mappé —</option>
                            <option value="-1" <?php selected( $is_label, true ); ?>>🏷️ C'est le Label (pas un artiste)</option>
                            <?php foreach ( $wp_users as $u ) : ?>
                            <option value="<?php echo $u->ID; ?>" <?php selected( $mapped_uid, $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?> (#<?php echo $u->ID; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <?php if ( $is_label ) : ?>
                            🏷️ Label
                        <?php elseif ( $mapped_u ) : ?>
                            ✅ <?php echo esc_html( $mapped_u->display_name ); ?>
                        <?php else : ?>
                            ⚠️ Non mappé
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><input type="submit" class="button button-primary" value="💾 Sauvegarder" /></p>
        </form>
        <?php endif; ?>
        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1{color:#EEF2FF;}</style>
    </div>
    <?php
}

// ══════════════════════════════════════════════════════════════
// PAGE ADMIN — COLLABORATIONS / FEATURING
// Détecte les noms d'artistes combinés détectés à l'import TuneCore
// (ex: "Artiste A & Artiste B") et permet de choisir, pour chaque
// combo, comment répartir les streams/gains entre les artistes
// concernés : équitablement (1/N chacun) ou intégralement (100%
// chacun, utile si chacun touche déjà sa propre commission ailleurs).
// ══════════════════════════════════════════════════════════════
function km_admin_collabs_page() {
    if ( isset( $_POST['km_collabs_nonce'] ) && wp_verify_nonce( $_POST['km_collabs_nonce'], 'km_save_collabs' ) ) {
        $modes         = get_option( 'km_collab_split_modes', array() );
        $custom_splits = get_option( 'km_collab_custom_splits', array() );
        $errors        = array();

        // Reconstruit la liste des combos connus pour pouvoir résoudre,
        // pour chaque combo posté en mode "custom", quels artistes le
        // composent (nécessaire pour lire les champs tcs-like postés).
        $combos_raw_save = function_exists( 'km_get_all_collab_combos' ) ? km_get_all_collab_combos() : array();

        foreach ( $_POST as $key => $val ) {
            if ( strpos( $key, 'km_collab_' ) !== 0 ) continue;
            $combo_key = urldecode( substr( $key, 10 ) );
            if ( ! $combo_key ) continue;
            $mode = in_array( $val, array( 'equal', 'full', 'custom' ), true ) ? $val : 'equal';

            if ( $mode === 'custom' ) {
                $label_pct = isset( $_POST[ 'km_collab_label_pct_' . urlencode( $combo_key ) ] )
                    ? floatval( $_POST[ 'km_collab_label_pct_' . urlencode( $combo_key ) ] ) : 0;
                $parts = isset( $combos_raw_save[ $combo_key ]['parts'] ) ? $combos_raw_save[ $combo_key ]['parts'] : array();

                $artists_pcts = array();
                $sum          = $label_pct;
                $combo_error  = '';

                foreach ( $parts as $an ) {
                    $field = 'km_collab_artist_pct_' . urlencode( $combo_key ) . '_' . urlencode( sanitize_title( $an ) );
                    $pct   = isset( $_POST[ $field ] ) ? floatval( $_POST[ $field ] ) : 0;
                    $an_uid = km_get_artist_user_id( $an );
                    if ( ! $an_uid ) {
                        $combo_error = 'Artiste "' . $an . '" non mappé à un compte WordPress — impossible de définir sa part.';
                        break;
                    }
                    $artists_pcts[ $an_uid ] = $pct;
                    $sum += $pct;
                }

                if ( ! $combo_error && abs( $sum - 100 ) > 0.05 ) {
                    $combo_error = 'Combo "' . $combo_key . '" : le total (label + artistes) doit faire 100% (actuellement ' . number_format( $sum, 1 ) . '%). Répartition personnalisée ignorée, mode conservé sur "équitable".';
                }

                if ( $combo_error ) {
                    $errors[] = $combo_error;
                    $mode = 'equal';
                    unset( $custom_splits[ $combo_key ] );
                } else {
                    $custom_splits[ $combo_key ] = array(
                        'label_pct' => $label_pct,
                        'artists'   => $artists_pcts,
                    );
                }
            } else {
                unset( $custom_splits[ $combo_key ] );
            }

            $modes[ $combo_key ] = $mode;
        }

        update_option( 'km_collab_split_modes', $modes );
        update_option( 'km_collab_custom_splits', $custom_splits );

        foreach ( $errors as $err ) {
            echo '<div class="notice notice-error"><p>⚠️ ' . esc_html( $err ) . '</p></div>';
        }
        if ( ! $errors ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Réglages de collaboration sauvegardés.</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Réglages sauvegardés (voir avertissement ci-dessus pour les combos ignorés).</p></div>';
        }
    }

    $modes  = get_option( 'km_collab_split_modes', array() );
    $custom_splits = get_option( 'km_collab_custom_splits', array() );
    $combos_raw = function_exists( 'km_get_all_collab_combos' ) ? km_get_all_collab_combos() : array();

    // $combos_raw est indexé par clé normalisée : array( key => array('raw'=>..., 'parts'=>[...]) )
    $combos = array();
    foreach ( $combos_raw as $key => $info ) {
        if ( ! empty( $info['raw'] ) && ! empty( $info['parts'] ) ) {
            $combos[ $key ] = $info;
        }
    }
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <span style="font-size:1.3em;">🤝</span> Collaborations &amp; Featuring
        </h1>
        <p style="color:#8a9aab;max-width:760px;font-size:13px;">
            Ces noms d'artistes ont été détectés comme des collaborations lors d'un import TuneCore
            (séparés par <code>&amp;</code>, <code>feat.</code>, <code>ft.</code>, <code>x</code>, une virgule, etc.).
            Chaque artiste mentionné reçoit déjà sa propre fiche et son propre rapport mensuel.
            Choisissez ici comment répartir les streams et les gains de la ligne CSV entre eux :
        </p>
        <ul style="color:#8a9aab;max-width:760px;font-size:13px;line-height:1.7;">
            <li><strong style="color:#EEF2FF;">Équitable</strong> — chaque artiste reçoit 1/N des streams et des gains (N = nombre d'artistes du combo). C'est le réglage par défaut : il ne risque jamais de faire compter les gains du label en double.</li>
            <li><strong style="color:#EEF2FF;">Intégral (100% chacun)</strong> — chaque artiste voit le total complet de la ligne. Utile si l'accord de collaboration prévoit que chacun touche sa part sur la totalité (à manier avec précaution : la somme affichée au label sera supérieure au total réel TuneCore).</li>
            <li><strong style="color:#EEF2FF;">Répartition personnalisée</strong> — fixe directement le % de chaque artiste ET le % retenu par le label sur le total brut de la ligne (le total doit faire 100%). S'applique à tous les titres de ce combo précis, tant qu'aucune répartition plus spécifique (par ISRC/Titre, depuis "Commissions par titre") n'existe pour un titre donné.</li>
        </ul>

        <?php if ( ! $combos ) : ?>
        <p style="color:#7a8faa;margin-top:24px;">Aucune collaboration détectée pour le moment. Importez un rapport CSV contenant des featurings pour les voir apparaître ici.</p>
        <?php else : ?>
        <form method="post" style="max-width:1100px;margin-top:20px;" id="km-collabs-form">
            <?php wp_nonce_field( 'km_save_collabs', 'km_collabs_nonce' ); ?>
            <table class="widefat" style="background:#1e2535;border-color:#2d3a50;">
                <thead><tr><th>Nom TuneCore (combo)</th><th>Artistes détectés</th><th>Répartition</th></tr></thead>
                <tbody>
                <?php foreach ( $combos as $combo_key => $info ) :
                    $tc_name    = $info['raw'];
                    $parts      = $info['parts'];
                    $current    = isset( $modes[ $combo_key ] ) ? $modes[ $combo_key ] : 'equal';
                    $ck_id      = md5( $combo_key ); // identifiant DOM stable et sûr
                    $existing_split = isset( $custom_splits[ $combo_key ] ) ? $custom_splits[ $combo_key ] : null;
                ?>
                <tr>
                    <td><code style="background:#0d1120;padding:2px 7px;"><?php echo esc_html( $tc_name ); ?></code></td>
                    <td style="color:#c8d6e8;"><?php echo esc_html( implode( ' • ', $parts ) ); ?> <span style="color:#7a8faa;">(<?php echo count( $parts ); ?>)</span></td>
                    <td>
                        <select name="km_collab_<?php echo urlencode( $combo_key ); ?>" class="km-collab-mode" data-target="km-collab-custom-<?php echo esc_attr( $ck_id ); ?>" style="background:#0d1120;color:#EEF2FF;border-color:#2d3a50;min-width:220px;">
                            <option value="equal"  <?php selected( $current, 'equal' ); ?>>Équitable (1/<?php echo count( $parts ); ?> chacun)</option>
                            <option value="full"   <?php selected( $current, 'full' ); ?>>Intégral (100% chacun)</option>
                            <option value="custom" <?php selected( $current, 'custom' ); ?>>Répartition personnalisée</option>
                        </select>

                        <div id="km-collab-custom-<?php echo esc_attr( $ck_id ); ?>" class="km-collab-custom-box" style="<?php echo $current === 'custom' ? '' : 'display:none;'; ?>margin-top:10px;padding:12px;background:#0d1120;border-radius:6px;border:1px solid #2d3a50;">
                            <?php foreach ( $parts as $an ) :
                                $an_uid = km_get_artist_user_id( $an );
                                $an_pct = ( $existing_split && $an_uid && isset( $existing_split['artists'][ $an_uid ] ) ) ? $existing_split['artists'][ $an_uid ] : '';
                                $field  = 'km_collab_artist_pct_' . urlencode( $combo_key ) . '_' . urlencode( sanitize_title( $an ) );
                            ?>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                    <span style="color:#c8d6e8;min-width:180px;font-size:13px;"><?php echo esc_html( $an ); ?><?php if ( ! $an_uid ) : ?> <span style="color:#FF7590;font-size:11px;">(non mappé)</span><?php endif; ?></span>
                                    <input type="number" name="<?php echo esc_attr( $field ); ?>" min="0" max="100" step="0.1" value="<?php echo esc_attr( $an_pct ); ?>" class="km-collab-pct" style="width:80px;" <?php echo $an_uid ? '' : 'disabled'; ?> />
                                    <span style="color:#7a8faa;">%</span>
                                </div>
                            <?php endforeach; ?>
                            <div style="display:flex;align-items:center;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid #2d3a50;">
                                <span style="color:#c8d6e8;min-width:180px;font-size:13px;">Label</span>
                                <input type="number" name="km_collab_label_pct_<?php echo urlencode( $combo_key ); ?>" min="0" max="100" step="0.1"
                                       value="<?php echo esc_attr( $existing_split ? $existing_split['label_pct'] : '' ); ?>" class="km-collab-pct" style="width:80px;" />
                                <span style="color:#7a8faa;">%</span>
                            </div>
                            <div class="km-collab-total" style="margin-top:8px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:4px;display:inline-block;">Total : 0%</div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><input type="submit" class="button button-primary" value="💾 Sauvegarder" /></p>
        </form>
        <script>
        (function(){
            function updateTotal(box) {
                var total = 0;
                box.querySelectorAll('.km-collab-pct').forEach(function(el){
                    var v = parseFloat(el.value);
                    if (!isNaN(v)) total += v;
                });
                var t = box.querySelector('.km-collab-total');
                total = Math.round(total * 10) / 10;
                t.textContent = 'Total : ' + total + '%';
                if (Math.abs(total - 100) < 0.05) { t.style.background='#0f3d2a'; t.style.color='#00D67F'; }
                else { t.style.background='#3a1520'; t.style.color='#FF7590'; }
            }
            document.querySelectorAll('.km-collab-mode').forEach(function(sel){
                var box = document.getElementById(sel.dataset.target);
                sel.addEventListener('change', function(){
                    box.style.display = (sel.value === 'custom') ? '' : 'none';
                    if (sel.value === 'custom') updateTotal(box);
                });
                box.addEventListener('input', function(e){
                    if (e.target.classList.contains('km-collab-pct')) updateTotal(box);
                });
                if (sel.value === 'custom') updateTotal(box);
            });
        })();
        </script>
        <?php endif; ?>
        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1{color:#EEF2FF;}</style>
    </div>
    <?php
}

// ══════════════════════════════════════════════════════════════
// PAGE ADMIN — NETTOYAGE DES ANCIENS RAPPORTS COMBINÉS
// Détecte les rapports mensuels (rapport_mensuel) dont le nom
// TuneCore enregistré est un combo de collaboration (ex: "A & B"),
// créés avant la correction du split automatique à l'import.
// Permet de les supprimer après confirmation, avant un réimport
// du CSV d'origine (qui recréera les rapports par artiste séparé).
// ══════════════════════════════════════════════════════════════
function km_admin_collabs_cleanup_page() {
    $deleted = array();
    $error   = '';

    if ( isset( $_POST['km_cleanup_nonce'] ) && wp_verify_nonce( $_POST['km_cleanup_nonce'], 'km_cleanup_collabs' ) ) {
        if ( empty( $_POST['km_cleanup_post_ids'] ) || ! is_array( $_POST['km_cleanup_post_ids'] ) ) {
            $error = 'Aucun rapport sélectionné.';
        } else {
            foreach ( $_POST['km_cleanup_post_ids'] as $pid ) {
                $pid = intval( $pid );
                if ( ! $pid ) continue;
                $post = get_post( $pid );
                if ( ! $post || $post->post_type !== 'rapport_mensuel' ) continue;
                // Double vérification de sécurité : on ne supprime que si le
                // nom TuneCore associé est bien un combo de collaboration.
                $tc_name = get_field( 'tunecore_artist_name', $pid );
                $parts   = function_exists( 'km_split_artist_names' ) ? km_split_artist_names( $tc_name ) : array( $tc_name );
                if ( count( $parts ) > 1 ) {
                    $title = get_the_title( $pid );
                    if ( wp_delete_post( $pid, true ) ) {
                        $deleted[] = $title;
                    }
                }
            }
        }
    }

    // ── Scan de tous les rapports pour repérer les combos ──
    $all_rapports = get_posts( array(
        'post_type'      => 'rapport_mensuel',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    $flagged = array();
    foreach ( $all_rapports as $r ) {
        $tc_name = get_field( 'tunecore_artist_name', $r->ID );
        if ( ! $tc_name ) continue;
        $parts = function_exists( 'km_split_artist_names' ) ? km_split_artist_names( $tc_name ) : array( $tc_name );
        if ( count( $parts ) > 1 ) {
            $periode  = get_field( 'periode', $r->ID ) ?: get_the_title( $r->ID );
            $streams  = (int) get_post_meta( $r->ID, '_km_total_streams', true );
            $gains    = (float) get_post_meta( $r->ID, '_km_total_gains', true );
            $flagged[] = array(
                'id'      => $r->ID,
                'title'   => get_the_title( $r->ID ),
                'tc_name' => $tc_name,
                'parts'   => $parts,
                'periode' => $periode,
                'streams' => $streams,
                'gains'   => $gains,
            );
        }
    }
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <span style="font-size:1.3em;">🧹</span> Nettoyage des rapports de collaboration
        </h1>
        <p style="color:#8a9aab;max-width:760px;font-size:13px;">
            Ces rapports mensuels ont été enregistrés avec un nom d'artiste combiné
            (ex: <code>Artiste A &amp; Artiste B</code>), avant la correction du split automatique à l'import.
            Ils ne sont rattachés à aucun artiste réel et faussent les statistiques du dashboard label.
        </p>
        <p style="color:#FFB020;max-width:760px;font-size:13px;background:#2a2210;border-left:3px solid #F5A623;padding:10px 14px;border-radius:0 6px 6px 0;">
            ⚠️ La suppression est <strong>définitive</strong>. Pensez à réimporter le CSV TuneCore d'origine
            <em>après</em> avoir supprimé ces rapports, pour que les artistes individuels soient recréés correctement.
        </p>

        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p>❌ <?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <?php if ( $deleted ) : ?>
            <div class="notice notice-success">
                <p>✅ <?php echo count( $deleted ); ?> rapport(s) supprimé(s) : <?php echo esc_html( implode( ', ', $deleted ) ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( ! $flagged ) : ?>
        <p style="color:#7a8faa;margin-top:24px;">✅ Aucun rapport combiné détecté. Tout est propre.</p>
        <?php else : ?>
        <form method="post" style="max-width:980px;margin-top:20px;">
            <?php wp_nonce_field( 'km_cleanup_collabs', 'km_cleanup_nonce' ); ?>
            <table class="widefat striped" style="background:#1e2535;border-color:#2d3a50;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" onclick="document.querySelectorAll('.km-cleanup-cb').forEach(c=>c.checked=this.checked)" /></th>
                        <th>Nom TuneCore (combo)</th>
                        <th>Artistes détectés</th>
                        <th>Période</th>
                        <th>Streams</th>
                        <th>Gains (€)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $flagged as $f ) : ?>
                <tr>
                    <td><input type="checkbox" class="km-cleanup-cb" name="km_cleanup_post_ids[]" value="<?php echo $f['id']; ?>" /></td>
                    <td><code style="background:#0d1120;padding:2px 7px;"><?php echo esc_html( $f['tc_name'] ); ?></code></td>
                    <td style="color:#c8d6e8;"><?php echo esc_html( implode( ' • ', $f['parts'] ) ); ?></td>
                    <td><?php echo esc_html( $f['periode'] ); ?></td>
                    <td><?php echo number_format( $f['streams'], 0, ',', ' ' ); ?></td>
                    <td><?php echo number_format( $f['gains'], 4, ',', '' ); ?> €</td>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $f['id'] ) ); ?>" target="_blank" style="font-size:.82rem;">Voir →</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:16px;">
                <input type="submit" class="button button-primary" style="background:#c0392b;border-color:#962d22;"
                    value="🗑️ Supprimer les rapports sélectionnés"
                    onclick="return confirm('Confirmer la suppression définitive des rapports sélectionnés ? Cette action est irréversible.');" />
            </p>
        </form>
        <?php endif; ?>
        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1{color:#EEF2FF;}</style>
    </div>
    <?php
}

// ══════════════════════════════════════════════════════════════
// PAGE ADMIN — RÉPARATION DES TITRES MAL ENCODÉS
// Certains rapports TuneCore déjà importés contiennent des titres où
// l'échappement Unicode a perdu son antislash (ex: "Ju00c9SUS M'APPELLE"
// au lieu de "JÉSUS M'APPELLE"). Cette page scanne tous les rapports
// mensuels, repère ceux qui sont concernés (artiste, catalogue, pistes)
// et permet de les corriger après confirmation, sans avoir à réimporter
// les CSV TuneCore d'origine.
// ══════════════════════════════════════════════════════════════
function km_admin_encoding_repair_page() {
    $repaired = array();
    $error    = '';

    if ( isset( $_POST['km_repair_nonce'] ) && wp_verify_nonce( $_POST['km_repair_nonce'], 'km_repair_encoding' ) ) {
        if ( empty( $_POST['km_repair_post_ids'] ) || ! is_array( $_POST['km_repair_post_ids'] ) ) {
            $error = 'Aucun rapport sélectionné.';
        } else {
            foreach ( $_POST['km_repair_post_ids'] as $pid ) {
                $pid = intval( $pid );
                if ( ! $pid ) continue;
                $post = get_post( $pid );
                if ( ! $post || $post->post_type !== 'rapport_mensuel' ) continue;

                $any_change = false;

                // Nom d'artiste TuneCore (champ ACF)
                $tc_name = get_field( 'tunecore_artist_name', $pid );
                if ( $tc_name ) {
                    $fixed = km_repair_lost_unicode_escapes( $tc_name );
                    if ( $fixed !== $tc_name ) { update_field( 'tunecore_artist_name', $fixed, $pid ); $any_change = true; }
                }

                // Titre du rapport (post_title) — reconstruit à partir du nom réparé + période
                $periode = get_field( 'periode', $pid );
                if ( $tc_name && $periode ) {
                    $fixed_title = km_repair_lost_unicode_escapes( $tc_name ) . ' — ' . $periode;
                    if ( $fixed_title !== $post->post_title ) {
                        wp_update_post( array( 'ID' => $pid, 'post_title' => $fixed_title ) );
                        $any_change = true;
                    }
                }

                // Catalogue (JSON) — clés (titres de sortie) + valeurs (tracks, etc.)
                $cat_raw = get_post_meta( $pid, '_km_catalog', true );
                if ( $cat_raw ) {
                    $cat_decoded = json_decode( $cat_raw, true );
                    if ( is_array( $cat_decoded ) ) {
                        $changed = false;
                        $cat_fixed = km_repair_unicode_deep( $cat_decoded, $changed );
                        if ( $changed ) { update_post_meta( $pid, '_km_catalog', km_safe_json_encode( $cat_fixed ) ); $any_change = true; }
                    }
                }

                // Pistes (JSON) — clés (titres de morceaux) + valeurs (album, etc.)
                $trk_raw = get_post_meta( $pid, '_km_tracks', true );
                if ( $trk_raw ) {
                    $trk_decoded = json_decode( $trk_raw, true );
                    if ( is_array( $trk_decoded ) ) {
                        $changed = false;
                        $trk_fixed = km_repair_unicode_deep( $trk_decoded, $changed );
                        if ( $changed ) { update_post_meta( $pid, '_km_tracks', km_safe_json_encode( $trk_fixed ) ); $any_change = true; }
                    }
                }

                if ( $any_change ) {
                    $repaired[] = get_the_title( $pid );
                }
            }
        }
    }

    // ── Scan de tous les rapports pour repérer ceux concernés ──
    // Détection : la donnée brute (avant tout decode JSON) contient
    // une séquence "u" + 4 chiffres hexadécimaux dans une plage sûre.
    $all_rapports = get_posts( array(
        'post_type'      => 'rapport_mensuel',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    $flagged = array();
    foreach ( $all_rapports as $r ) {
        $tc_name = get_field( 'tunecore_artist_name', $r->ID );
        $cat_raw = get_post_meta( $r->ID, '_km_catalog', true );
        $trk_raw = get_post_meta( $r->ID, '_km_tracks', true );

        $is_flagged = false;
        $examples   = array();

        if ( $tc_name && $tc_name !== km_repair_lost_unicode_escapes( $tc_name ) ) {
            $is_flagged = true;
            $examples[] = $tc_name . ' → ' . km_repair_lost_unicode_escapes( $tc_name );
        }
        foreach ( array( $cat_raw, $trk_raw ) as $raw ) {
            if ( ! $raw ) continue;
            $decoded = json_decode( $raw, true );
            if ( ! is_array( $decoded ) ) continue;
            $changed = false;
            $fixed   = km_repair_unicode_deep( $decoded, $changed );
            if ( $changed ) {
                $is_flagged = true;
                // Récupère 1-2 exemples concrets pour affichage (clés modifiées)
                $orig_keys  = array_keys( $decoded );
                $fixed_keys = array_keys( $fixed );
                foreach ( $orig_keys as $i => $ok ) {
                    if ( isset( $fixed_keys[ $i ] ) && $fixed_keys[ $i ] !== $ok && count( $examples ) < 3 ) {
                        $examples[] = $ok . ' → ' . $fixed_keys[ $i ];
                    }
                }
            }
        }

        if ( $is_flagged ) {
            $flagged[] = array(
                'id'       => $r->ID,
                'title'    => get_the_title( $r->ID ),
                'periode'  => get_field( 'periode', $r->ID ) ?: '—',
                'examples' => $examples,
            );
        }
    }
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <span style="font-size:1.3em;">🔧</span> Réparer les titres mal encodés
        </h1>
        <p style="color:#8a9aab;max-width:760px;font-size:13px;">
            Certains rapports TuneCore contiennent des titres où un caractère accentué
            (é, è, ô, ç…) a perdu son échappement et s'affiche en clair, par exemple
            <code style="background:#0d1120;padding:2px 6px;">Ju00c9SUS M'APPELLE</code>
            au lieu de <code style="background:#0d1120;padding:2px 6px;">JÉSUS M'APPELLE</code>.
            Cette page détecte ces rapports et corrige l'artiste, le catalogue et les pistes concernées.
        </p>

        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p>❌ <?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <?php if ( $repaired ) : ?>
            <div class="notice notice-success">
                <p>✅ <?php echo count( $repaired ); ?> rapport(s) corrigé(s) : <?php echo esc_html( implode( ', ', $repaired ) ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( ! $flagged ) : ?>
        <p style="color:#7a8faa;margin-top:24px;">✅ Aucun titre mal encodé détecté. Tout est propre.</p>
        <?php else : ?>
        <form method="post" style="max-width:980px;margin-top:20px;">
            <?php wp_nonce_field( 'km_repair_encoding', 'km_repair_nonce' ); ?>
            <table class="widefat striped" style="background:#1e2535;border-color:#2d3a50;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" onclick="document.querySelectorAll('.km-repair-cb').forEach(c=>c.checked=this.checked)" checked /></th>
                        <th>Rapport</th>
                        <th>Période</th>
                        <th>Exemples de correction</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $flagged as $f ) : ?>
                <tr>
                    <td><input type="checkbox" class="km-repair-cb" name="km_repair_post_ids[]" value="<?php echo $f['id']; ?>" checked /></td>
                    <td><?php echo esc_html( $f['title'] ); ?></td>
                    <td><?php echo esc_html( $f['periode'] ); ?></td>
                    <td style="font-size:12px;color:#c8d6e8;">
                        <?php foreach ( $f['examples'] as $ex ) : ?>
                            <div><code style="background:#0d1120;padding:2px 6px;border-radius:4px;"><?php echo esc_html( $ex ); ?></code></div>
                        <?php endforeach; ?>
                    </td>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $f['id'] ) ); ?>" target="_blank" style="font-size:.82rem;">Voir →</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:16px;">
                <input type="submit" class="button button-primary"
                    value="🔧 Corriger les rapports sélectionnés"
                    onclick="return confirm('Corriger l\'encodage des rapports sélectionnés ? Cette action réécrit les titres en base.');" />
            </p>
        </form>
        <?php endif; ?>
        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1{color:#EEF2FF;}</style>
    </div>
    <?php
}

function km_admin_import_page() {
    if ( isset( $_FILES['km_csv'] ) && check_admin_referer( 'km_import_csv' ) ) {
        $result = km_import_tunecore_csv( $_FILES['km_csv']['tmp_name'] );
        if ( is_wp_error( $result ) ) {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html( $result->get_error_message() ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Import terminé — Créés: ' . $result['created'] . ' | Mis à jour: ' . $result['updated'] . ' | Ignorés: ' . $result['skipped'] . '</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>📥 Import CSV TuneCore</h1>
        <div style="background:#1e2535;border:1px solid #2d3a50;border-radius:10px;padding:24px;max-width:600px;margin-top:20px;">
            <p style="color:#c8d6e8;">Téléversez le fichier CSV mensuel TuneCore (Sales Report).</p>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'km_import_csv' ); ?>
                <input type="file" name="km_csv" accept=".csv" style="color:#EEF2FF;margin-bottom:14px;display:block;" />
                <input type="submit" class="button button-primary button-large" value="📥 Importer" />
            </form>
        </div>
        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1{color:#EEF2FF;}</style>
    </div>
    <?php
}

// ══════════════════════════════════════════════════════════════
// PAGE ADMIN — COMMISSIONS PAR TITRE
// Permet de definir un % label specifique pour certains titres
// (ex: collaborations ou titres ou l'artiste a paye le studio)
// ══════════════════════════════════════════════════════════════
function km_admin_track_commissions_page() {
    // ── Sauvegarde ──
    if ( isset( $_POST['km_tc_nonce'] ) && wp_verify_nonce( $_POST['km_tc_nonce'], 'km_save_track_commissions' ) ) {
        $current = get_option( 'km_track_commissions', array() );
        if ( ! is_array( $current ) ) $current = array();

        // Suppression d'entree
        if ( ! empty( $_POST['km_tc_delete'] ) ) {
            $del_key = sanitize_text_field( wp_unslash( $_POST['km_tc_delete'] ) );
            unset( $current[ $del_key ] );
            update_option( 'km_track_commissions', $current );
            echo '<div class="notice notice-success is-dismissible"><p>Entree supprimee.</p></div>';
        }
        // Ajout d'une nouvelle entree
        if ( ! empty( $_POST['km_tc_add'] ) ) {
            $type    = isset( $_POST['tc_type'] ) ? sanitize_key( $_POST['tc_type'] ) : 'isrc';
            $user_id = isset( $_POST['tc_user_id'] ) ? intval( $_POST['tc_user_id'] ) : 0;
            $title   = isset( $_POST['tc_title'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_title'] ) ) : '';
            $isrc    = isset( $_POST['tc_isrc'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_isrc'] ) ) : '';
            $pct     = isset( $_POST['tc_pct'] ) ? floatval( $_POST['tc_pct'] ) : 0;

            if ( $pct < 0 || $pct > 100 ) {
                echo '<div class="notice notice-error"><p>Le pourcentage doit etre entre 0 et 100.</p></div>';
            } elseif ( $type === 'isrc' && $isrc ) {
                $current[ 'isrc:' . sanitize_key( $isrc ) ] = $pct;
                update_option( 'km_track_commissions', $current );
                echo '<div class="notice notice-success is-dismissible"><p>Commission ISRC ajoutee.</p></div>';
            } elseif ( $type === 'track' && $title && $user_id ) {
                $current[ 'track:' . $user_id . ':' . sanitize_title( $title ) ] = $pct;
                update_option( 'km_track_commissions', $current );
                echo '<div class="notice notice-success is-dismissible"><p>Commission titre ajoutee.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Merci de remplir les champs requis.</p></div>';
            }
        }
        // Mise a jour en masse des pourcentages existants
        if ( ! empty( $_POST['km_tc_update_all'] ) && isset( $_POST['km_tc_pct'] ) && is_array( $_POST['km_tc_pct'] ) ) {
            foreach ( $_POST['km_tc_pct'] as $k => $v ) {
                $k = sanitize_text_field( wp_unslash( $k ) );
                $v = floatval( $v );
                if ( isset( $current[ $k ] ) && $v >= 0 && $v <= 100 ) {
                    $current[ $k ] = $v;
                }
            }
            update_option( 'km_track_commissions', $current );
            echo '<div class="notice notice-success is-dismissible"><p>Pourcentages mis a jour.</p></div>';
        }

        // ── Suppression d'une répartition multi-artistes ──
        if ( ! empty( $_POST['km_tc_delete_split'] ) ) {
            $splits  = get_option( 'km_track_multi_splits', array() );
            $del_key = sanitize_text_field( wp_unslash( $_POST['km_tc_delete_split'] ) );
            unset( $splits[ $del_key ] );
            update_option( 'km_track_multi_splits', $splits );
            echo '<div class="notice notice-success is-dismissible"><p>Répartition multi-artistes supprimée.</p></div>';
        }

        // ── Ajout / remplacement d'une répartition multi-artistes ──
        // Permet, pour un titre donné (ISRC de préférence, ou titre en
        // repli), de fixer directement le % de CHAQUE artiste ET le %
        // retenu par le label sur le total brut — au lieu d'un partage
        // égal entre artistes suivi d'une commission appliquée séparément
        // à chacun. Le total (label + artistes) doit faire 100%.
        if ( ! empty( $_POST['km_tc_add_split'] ) ) {
            $s_isrc    = isset( $_POST['tcs_isrc'] )      ? sanitize_text_field( wp_unslash( $_POST['tcs_isrc'] ) )  : '';
            $s_title   = isset( $_POST['tcs_title'] )     ? sanitize_text_field( wp_unslash( $_POST['tcs_title'] ) ) : '';
            $label_pct = isset( $_POST['tcs_label_pct'] ) ? floatval( $_POST['tcs_label_pct'] ) : 0;
            $art_ids   = ( isset( $_POST['tcs_artist_id'] )  && is_array( $_POST['tcs_artist_id'] ) )  ? $_POST['tcs_artist_id']  : array();
            $art_pcts  = ( isset( $_POST['tcs_artist_pct'] ) && is_array( $_POST['tcs_artist_pct'] ) ) ? $_POST['tcs_artist_pct'] : array();

            $error        = '';
            $artists_pcts = array();
            $sum          = $label_pct;

            if ( ! $s_isrc && ! $s_title ) {
                $error = 'Merci de renseigner un ISRC ou, à défaut, un titre pour identifier le son.';
            } elseif ( $label_pct < 0 || $label_pct > 100 ) {
                $error = 'Le pourcentage label doit être compris entre 0 et 100.';
            } else {
                foreach ( $art_ids as $i => $aid ) {
                    $aid = intval( $aid );
                    $pct = isset( $art_pcts[ $i ] ) ? floatval( $art_pcts[ $i ] ) : 0;
                    if ( ! $aid || $pct <= 0 ) continue; // ligne vide ignorée
                    if ( isset( $artists_pcts[ $aid ] ) ) {
                        $error = 'Un même artiste ne peut apparaître deux fois dans la répartition.';
                        break;
                    }
                    $artists_pcts[ $aid ] = $pct;
                    $sum += $pct;
                }
                if ( ! $error && count( $artists_pcts ) < 2 ) {
                    $error = 'Ajoutez au moins 2 artistes (pour un seul artiste, utilisez plutôt le mode "Par ISRC" ou "Par Titre + Artiste" ci-dessus).';
                }
                if ( ! $error && abs( $sum - 100 ) > 0.05 ) {
                    $error = 'Le total (label + artistes) doit être égal à 100%. Actuellement : ' . number_format( $sum, 1 ) . '%.';
                }
            }

            if ( $error ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
            } else {
                $splits = get_option( 'km_track_multi_splits', array() );
                $key    = $s_isrc ? ( 'isrc:' . sanitize_key( $s_isrc ) ) : ( 'title:' . sanitize_title( $s_title ) );
                $splits[ $key ] = array(
                    'label_pct' => $label_pct,
                    'artists'   => $artists_pcts,
                    'raw_label' => $s_isrc ? strtoupper( $s_isrc ) : $s_title,
                );
                update_option( 'km_track_multi_splits', $splits );
                echo '<div class="notice notice-success is-dismissible"><p>Répartition multi-artistes enregistrée. Elle s\'appliquera aux prochains imports CSV concernant ce titre.</p></div>';
            }
        }
    }

    $overrides      = get_option( 'km_track_commissions', array() );
    $multi_splits   = get_option( 'km_track_multi_splits', array() );
    $default_pct    = floatval( get_option( 'km_label_commission', 30 ) );
    $artists_users  = get_users( array( 'role__in' => array( 'artiste_label', 'administrator' ), 'orderby' => 'display_name' ) );
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <span style="font-size:1.3em;">🎚️</span> Commissions par titre
            <span style="font-size:.65em;background:#1e3a5f;color:#7ec8e3;padding:3px 10px;border-radius:4px;font-weight:400;">OVERRIDE</span>
        </h1>
        <p style="color:#8a9aab;max-width:720px;font-size:13px;">
            Par defaut, chaque artiste recoit <strong style="color:#F5A623;"><?php echo 100 - $default_pct; ?>%</strong> de ses revenus streaming (commission label : <?php echo $default_pct; ?>%).
            Utilisez cette page pour definir un <strong>pourcentage specifique</strong> pour certains titres (ex: collaborations, titres ou l'artiste a finance le studio, accords particuliers).
            La priorite est : <em>ISRC &gt; Titre &gt; Commission artiste par defaut</em>.
        </p>

        <!-- ── Formulaire ajout ── -->
        <div style="background:#1e2535;border:1px solid #2d3a50;border-radius:10px;padding:24px;max-width:900px;margin-top:20px;">
            <h2 style="color:#EEF2FF;font-size:1rem;margin-top:0;margin-bottom:14px;">Ajouter une commission specifique</h2>
            <form method="post">
                <?php wp_nonce_field( 'km_save_track_commissions', 'km_tc_nonce' ); ?>
                <input type="hidden" name="km_tc_add" value="1" />
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th style="color:#c8d6e8;width:160px;">Mode</th>
                        <td>
                            <label style="margin-right:20px;">
                                <input type="radio" name="tc_type" value="isrc" checked onchange="kmTcMode(this.value)" />
                                Par ISRC <span style="color:#7a8faa;font-size:12px;">(code unique TuneCore)</span>
                            </label>
                            <label>
                                <input type="radio" name="tc_type" value="track" onchange="kmTcMode(this.value)" />
                                Par Titre + Artiste
                            </label>
                        </td>
                    </tr>
                    <tr id="km-tc-row-isrc">
                        <th style="color:#c8d6e8;">ISRC</th>
                        <td>
                            <input type="text" name="tc_isrc" class="regular-text" placeholder="FR-XXX-XX-XXXXX" style="font-family:monospace;" />
                            <p style="color:#7a8faa;font-size:12px;margin:6px 0 0;">Le code ISRC identifie de maniere unique un enregistrement. Tu le trouves dans tes rapports TuneCore.</p>
                        </td>
                    </tr>
                    <tr id="km-tc-row-track" style="display:none;">
                        <th style="color:#c8d6e8;">Artiste</th>
                        <td>
                            <select name="tc_user_id" style="min-width:280px;">
                                <option value="0">— Selectionner —</option>
                                <?php foreach ( $artists_users as $u ) : ?>
                                <option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="km-tc-row-track2" style="display:none;">
                        <th style="color:#c8d6e8;">Titre du son</th>
                        <td>
                            <input type="text" name="tc_title" class="regular-text" placeholder="ex: Amen, Blessé, Gloire à toi" />
                            <p style="color:#7a8faa;font-size:12px;margin:6px 0 0;">Le titre est normalise pour la correspondance (insensible a la casse et accents).</p>
                        </td>
                    </tr>
                    <tr>
                        <th style="color:#c8d6e8;">Commission label (%)</th>
                        <td>
                            <input type="number" name="tc_pct" min="0" max="100" step="0.1" value="<?php echo esc_attr( $default_pct ); ?>" style="width:90px;" required />
                            <span style="color:#7a8faa;font-size:13px;margin-left:10px;">% retenu par le label pour ce titre</span>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary button-large" style="background:#F5A623;border-color:#C07A10;color:#000;font-weight:700;">Ajouter</button></p>
            </form>
        </div>

        <script>
        function kmTcMode(mode) {
            document.getElementById('km-tc-row-isrc').style.display   = mode === 'isrc' ? '' : 'none';
            document.getElementById('km-tc-row-track').style.display  = mode === 'track' ? '' : 'none';
            document.getElementById('km-tc-row-track2').style.display = mode === 'track' ? '' : 'none';
        }
        </script>

        <!-- ── Formulaire ajout : répartition multi-artistes ── -->
        <div style="background:#1e2535;border:1px solid #2d3a50;border-radius:10px;padding:24px;max-width:900px;margin-top:24px;">
            <h2 style="color:#EEF2FF;font-size:1rem;margin-top:0;margin-bottom:6px;">🤝 Répartition multi-artistes (featuring / collaboration)</h2>
            <p style="color:#8a9aab;font-size:13px;margin-top:0;margin-bottom:16px;max-width:700px;">
                Pour un son avec plusieurs artistes, fixe directement le pourcentage de <strong>chaque artiste</strong> et
                celui retenu par le <strong>label</strong> — au lieu d'un partage égal entre artistes suivi d'une commission
                appliquée séparément à chacun. Le total doit faire <strong>100%</strong>. Priorité identique aux commissions
                simples : <em>ISRC &gt; Titre</em>. L'ISRC est recommandé (il identifie le son de façon unique, y compris
                si le titre est orthographié différemment d'une plateforme à l'autre).
            </p>
            <form method="post" id="km-tc-split-form">
                <?php wp_nonce_field( 'km_save_track_commissions', 'km_tc_nonce' ); ?>
                <input type="hidden" name="km_tc_add_split" value="1" />
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th style="color:#c8d6e8;width:160px;">ISRC</th>
                        <td>
                            <input type="text" name="tcs_isrc" class="regular-text" placeholder="FR-XXX-XX-XXXXX" style="font-family:monospace;" />
                            <span style="color:#7a8faa;font-size:12px;"> — recommandé</span>
                        </td>
                    </tr>
                    <tr>
                        <th style="color:#c8d6e8;">Titre du son</th>
                        <td>
                            <input type="text" name="tcs_title" class="regular-text" placeholder="ex: Amen, Blessé, Gloire à toi" />
                            <p style="color:#7a8faa;font-size:12px;margin:6px 0 0;">Utilisé seulement si l'ISRC ci-dessus est vide.</p>
                        </td>
                    </tr>
                    <tr>
                        <th style="color:#c8d6e8;">Commission label (%)</th>
                        <td>
                            <input type="number" name="tcs_label_pct" min="0" max="100" step="0.1" value="<?php echo esc_attr( $default_pct ); ?>" class="km-tcs-pct" style="width:90px;" required />
                            <span style="color:#7a8faa;font-size:13px;margin-left:10px;">% retenu par le label sur ce titre</span>
                        </td>
                    </tr>
                    <tr>
                        <th style="color:#c8d6e8;vertical-align:top;padding-top:10px;">Artistes</th>
                        <td>
                            <div id="km-tcs-rows"></div>
                            <button type="button" onclick="kmTcsAddRow()" class="button" style="margin-top:8px;">+ Ajouter un artiste</button>
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <div id="km-tcs-total" style="font-weight:700;font-size:14px;padding:8px 14px;border-radius:6px;display:inline-block;">Total : 0%</div>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary button-large" style="background:#F5A623;border-color:#C07A10;color:#000;font-weight:700;">Enregistrer la répartition</button></p>
            </form>
        </div>

        <script>
        (function(){
            var artistsOptions = <?php
                $opts = array();
                foreach ( $artists_users as $u ) { $opts[] = array( 'id' => $u->ID, 'name' => $u->display_name ); }
                echo wp_json_encode( $opts );
            ?>;

            function buildSelect() {
                var s = document.createElement('select');
                s.name = 'tcs_artist_id[]';
                s.style.minWidth = '240px';
                var opt0 = document.createElement('option');
                opt0.value = '0'; opt0.textContent = '— Selectionner —';
                s.appendChild(opt0);
                artistsOptions.forEach(function(a){
                    var o = document.createElement('option');
                    o.value = a.id; o.textContent = a.name;
                    s.appendChild(o);
                });
                return s;
            }

            window.kmTcsAddRow = function(){
                var wrap = document.createElement('div');
                wrap.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:8px;';

                var sel = buildSelect();

                var pct = document.createElement('input');
                pct.type = 'number'; pct.min = '0'; pct.max = '100'; pct.step = '0.1';
                pct.name = 'tcs_artist_pct[]'; pct.placeholder = '%';
                pct.className = 'km-tcs-pct';
                pct.style.width = '90px';

                var pctLabel = document.createElement('span');
                pctLabel.textContent = '%'; pctLabel.style.color = '#7a8faa';

                var del = document.createElement('button');
                del.type = 'button'; del.textContent = 'Retirer'; del.className = 'button';
                del.style.cssText = 'background:#3a1520;color:#FF7590;border-color:#8a2f40;font-size:11px;';
                del.onclick = function(){ wrap.remove(); kmTcsUpdateTotal(); };

                wrap.appendChild(sel); wrap.appendChild(pct); wrap.appendChild(pctLabel); wrap.appendChild(del);
                document.getElementById('km-tcs-rows').appendChild(wrap);
                sel.addEventListener('change', kmTcsUpdateTotal);
                pct.addEventListener('input', kmTcsUpdateTotal);
            };

            window.kmTcsUpdateTotal = function(){
                var total = 0;
                document.querySelectorAll('#km-tc-split-form .km-tcs-pct').forEach(function(el){
                    var v = parseFloat(el.value);
                    if (!isNaN(v)) total += v;
                });
                var box = document.getElementById('km-tcs-total');
                total = Math.round(total * 10) / 10;
                box.textContent = 'Total : ' + total + '%';
                if (Math.abs(total - 100) < 0.05) {
                    box.style.background = '#0f3d2a'; box.style.color = '#00D67F';
                } else {
                    box.style.background = '#3a1520'; box.style.color = '#FF7590';
                }
            };

            document.getElementById('km-tc-split-form').addEventListener('input', function(e){
                if (e.target.classList.contains('km-tcs-pct')) kmTcsUpdateTotal();
            });

            // Deux lignes artiste par défaut, cas le plus courant (featuring à deux)
            kmTcsAddRow(); kmTcsAddRow();
            kmTcsUpdateTotal();
        })();
        </script>

        <!-- ── Liste des overrides existants ── -->
        <div style="background:#1e2535;border:1px solid #2d3a50;border-radius:10px;padding:24px;max-width:1100px;margin-top:24px;">
            <h2 style="color:#EEF2FF;font-size:1rem;margin-top:0;margin-bottom:14px;">
                Commissions enregistrees
                <span style="color:#7a8faa;font-size:13px;font-weight:400;">(<?php echo count( $overrides ); ?>)</span>
            </h2>
            <?php if ( empty( $overrides ) ) : ?>
                <p style="color:#7a8faa;font-size:13px;padding:16px;background:#0d1120;border-radius:6px;">Aucune commission specifique definie. La commission artiste par defaut s'applique a tous les titres.</p>
            <?php else : ?>
            <form method="post">
                <?php wp_nonce_field( 'km_save_track_commissions', 'km_tc_nonce' ); ?>
                <input type="hidden" name="km_tc_update_all" value="1" />
                <table class="widefat" style="background:#0d1120;border-color:#2d3a50;">
                    <thead>
                        <tr>
                            <th style="width:90px;">Type</th>
                            <th>Cle</th>
                            <th>Artiste</th>
                            <th style="width:140px;">% Label</th>
                            <th style="width:140px;">% Artiste</th>
                            <th style="width:100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $overrides as $key => $pct ) :
                        $is_isrc = strpos( $key, 'isrc:' ) === 0;
                        $label   = '';
                        $artist  = '—';
                        if ( $is_isrc ) {
                            $label = strtoupper( substr( $key, 5 ) );
                        } else {
                            $parts = explode( ':', $key );
                            if ( count( $parts ) >= 3 ) {
                                $uid = intval( $parts[1] );
                                $u   = get_user_by( 'id', $uid );
                                if ( $u ) $artist = $u->display_name;
                                $label = str_replace( '-', ' ', ucwords( $parts[2] ) );
                            }
                        }
                        $pct_artist = 100 - floatval( $pct );
                    ?>
                        <tr>
                            <td>
                                <?php if ( $is_isrc ) : ?>
                                    <span style="background:#1e3a5f;color:#7ec8e3;padding:3px 9px;border-radius:4px;font-size:11px;font-weight:700;">ISRC</span>
                                <?php else : ?>
                                    <span style="background:#3a2556;color:#c8a1ff;padding:3px 9px;border-radius:4px;font-size:11px;font-weight:700;">TITRE</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="background:#050710;color:#F5A623;padding:3px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html( $label ); ?></code></td>
                            <td style="color:#c8d6e8;"><?php echo esc_html( $artist ); ?></td>
                            <td>
                                <input type="number" name="km_tc_pct[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $pct ); ?>" min="0" max="100" step="0.1" style="width:80px;background:#050710;color:#F5A623;border-color:#2d3a50;font-weight:700;" />
                                <span style="color:#7a8faa;">%</span>
                            </td>
                            <td style="color:#00D67F;font-weight:700;"><?php echo number_format( $pct_artist, 1 ); ?> %</td>
                            <td>
                                <button type="submit" name="km_tc_delete" value="<?php echo esc_attr( $key ); ?>"
                                        onclick="return confirm('Supprimer cette entree ?');"
                                        class="button" style="background:#3a1520;color:#FF7590;border-color:#8a2f40;font-size:11px;">Supprimer</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:14px;"><button type="submit" class="button button-primary" style="background:#00D67F;border-color:#00A85F;color:#000;font-weight:700;">Mettre a jour les pourcentages</button></p>
            </form>
            <?php endif; ?>
        </div>

        <!-- ── Liste des répartitions multi-artistes existantes ── -->
        <div style="background:#1e2535;border:1px solid #2d3a50;border-radius:10px;padding:24px;max-width:1100px;margin-top:24px;">
            <h2 style="color:#EEF2FF;font-size:1rem;margin-top:0;margin-bottom:14px;">
                Répartitions multi-artistes enregistrées
                <span style="color:#7a8faa;font-size:13px;font-weight:400;">(<?php echo count( $multi_splits ); ?>)</span>
            </h2>
            <?php if ( empty( $multi_splits ) ) : ?>
                <p style="color:#7a8faa;font-size:13px;padding:16px;background:#0d1120;border-radius:6px;">Aucune répartition multi-artistes définie pour l'instant.</p>
            <?php else : ?>
                <table class="widefat" style="background:#0d1120;border-color:#2d3a50;">
                    <thead>
                        <tr>
                            <th style="width:90px;">Type</th>
                            <th>Son</th>
                            <th>Répartition</th>
                            <th style="width:100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $multi_splits as $key => $split ) :
                        $is_isrc_split = strpos( $key, 'isrc:' ) === 0;
                        $raw_label     = isset( $split['raw_label'] ) ? $split['raw_label'] : $key;
                    ?>
                        <tr>
                            <td>
                                <?php if ( $is_isrc_split ) : ?>
                                    <span style="background:#1e3a5f;color:#7ec8e3;padding:3px 9px;border-radius:4px;font-size:11px;font-weight:700;">ISRC</span>
                                <?php else : ?>
                                    <span style="background:#3a2556;color:#c8a1ff;padding:3px 9px;border-radius:4px;font-size:11px;font-weight:700;">TITRE</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="background:#050710;color:#F5A623;padding:3px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html( $raw_label ); ?></code></td>
                            <td style="color:#c8d6e8;font-size:13px;">
                                <span style="background:#1e3a5f;color:#7ec8e3;padding:2px 8px;border-radius:4px;margin-right:6px;">Label <?php echo esc_html( number_format( floatval( $split['label_pct'] ), 1 ) ); ?>%</span>
                                <?php foreach ( (array) $split['artists'] as $art_uid => $art_pct ) :
                                    $art_u = get_user_by( 'id', $art_uid );
                                    $art_name = $art_u ? $art_u->display_name : ( 'Utilisateur #' . $art_uid );
                                ?>
                                    <span style="background:#0f3d2a;color:#00D67F;padding:2px 8px;border-radius:4px;margin-right:6px;display:inline-block;margin-top:4px;"><?php echo esc_html( $art_name ); ?> <?php echo esc_html( number_format( floatval( $art_pct ), 1 ) ); ?>%</span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Supprimer cette répartition ?');">
                                    <?php wp_nonce_field( 'km_save_track_commissions', 'km_tc_nonce' ); ?>
                                    <button type="submit" name="km_tc_delete_split" value="<?php echo esc_attr( $key ); ?>"
                                            class="button" style="background:#3a1520;color:#FF7590;border-color:#8a2f40;font-size:11px;">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#7a8faa;font-size:12px;margin-top:12px;">Pour modifier une répartition, ré-enregistre le même ISRC (ou titre) ci-dessus avec les nouveaux pourcentages : elle sera remplacée.</p>
            <?php endif; ?>
        </div>

        <style>#wpcontent{background:#0d1120 !important;}#wpbody-content .wrap h1,.wrap h2{color:#EEF2FF;}.wrap input[type=text],.wrap input[type=number],.wrap select{background:#0d1120;color:#EEF2FF;border-color:#2d3a50;}</style>
    </div>
    <?php
}
