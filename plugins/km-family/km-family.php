<?php
/**
 * Plugin Name:       KM Family
 * Plugin URI:        https://kophismusic.com/
 * Description:       Plateforme de soutien pour les artistes du label Urban Gospel Kophis Music. Paliers standardisés (Bronze/Argent/Or/Platine/Diamant), badges membres, paiement Mobile Money (CinetPay/Paystack) et protection totale des médias.
 * Version:           3.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kophis Music
 * Author URI:        https://kophismusic.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       km-family
 * Domain Path:       /languages
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KMFAMILY_VERSION', '3.4.1' );
define( 'KMFAMILY_FILE', __FILE__ );
define( 'KMFAMILY_PATH', plugin_dir_path( __FILE__ ) );
define( 'KMFAMILY_URL', plugin_dir_url( __FILE__ ) );
define( 'KMFAMILY_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Logger d'urgence pour capturer toute fatal error de ce plugin.
 * Le log est écrit dans /wp-content/kmfamily-fatal.log si WP_CONTENT_DIR existe.
 */
if ( ! function_exists( 'kmfamily_write_fatal_log' ) ) {
    function kmfamily_write_fatal_log( $message ) {
        $dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : __DIR__ . '/../../';
        $file = rtrim( $dir, '/' ) . '/kmfamily-fatal.log';
        @file_put_contents(
            $file,
            '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . "\n",
            FILE_APPEND
        );
    }
}

// Capture de toute erreur fatale qui impliquerait un fichier du plugin
register_shutdown_function( function() {
    $err = error_get_last();
    if ( ! $err ) return;
    if ( ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) return;

    // On log toute erreur fatale pendant les requêtes wp-admin/options.php ou contenant 'kmfamily'
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $matches_kmfamily = (
        ( isset( $err['file'] ) && strpos( $err['file'], 'km-family' ) !== false )
        || strpos( $uri, 'options.php' ) !== false
        || strpos( $uri, 'kmfamily' ) !== false
        || strpos( $uri, 'km-family' ) !== false
    );

    if ( $matches_kmfamily ) {
        kmfamily_write_fatal_log(
            'FATAL: ' . $err['message']
            . ' | FILE: ' . ( $err['file'] ?? '?' ) . ':' . ( $err['line'] ?? '?' )
            . ' | URL: ' . $uri
            . ' | METHOD: ' . ( $_SERVER['REQUEST_METHOD'] ?? '?' )
            . ' | POST keys: ' . ( ! empty( $_POST ) ? implode( ',', array_keys( $_POST ) ) : '(empty)' )
        );
    }
} );

register_activation_hook( __FILE__, 'kmfamily_activate' );
function kmfamily_activate() {
    if ( ! class_exists( 'ACF' ) && ! function_exists( 'get_field' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'KM Family nécessite le plugin Advanced Custom Fields (ACF). Veuillez l\'installer et l\'activer avant de continuer.', 'km-family' ),
            esc_html__( 'Dépendance manquante', 'km-family' ),
            array( 'back_link' => true )
        );
    }

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-roles.php';
    KMFamily_Roles::create_roles();

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-install.php';
    KMFamily_Install::create_pages();

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-media-protector.php';
    KMFamily_Media_Protector::setup_secure_storage();

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-orders.php';
    KMFamily_Orders::create_table();
    update_option( 'kmfamily_orders_db_version', KMFamily_Orders::DB_VERSION );

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-media-session.php';
    KMFamily_Media_Session::create_table();
    update_option( 'kmfamily_media_sessions_db_version', KMFamily_Media_Session::DB_VERSION );

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-rewards.php';
    KMFamily_Rewards::create_table();
    update_option( 'kmfamily_rewards_db_version', KMFamily_Rewards::DB_VERSION );

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-playlists.php';
    KMFamily_Playlists::create_table();
    update_option( 'kmfamily_playlists_db_version', KMFamily_Playlists::DB_VERSION );

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-playback.php';
    KMFamily_Playback::create_table();
    update_option( 'kmfamily_playback_db_version', KMFamily_Playback::DB_VERSION );

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-memberships.php';
    KMFamily_Memberships::create_table();
    update_option( 'kmfamily_memberships_db_version', KMFamily_Memberships::DB_VERSION );

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-revenue.php';
    KMFamily_Revenue::create_table();
    update_option( 'kmfamily_revenue_db_version', KMFamily_Revenue::DB_VERSION );
    KMFamily_Revenue::maybe_backfill();

    // La règle de réécriture du flux média doit être déclarée AVANT le flush :
    // à l'activation, le hook 'init' n'a pas encore été joué, donc la règle
    // n'existerait pas encore dans les permaliens régénérés et l'URL de
    // lecture renverrait un 404 jusqu'au prochain enregistrement des
    // permaliens.
    KMFamily_Media_Session::register_rewrite();

    flush_rewrite_rules();
    set_transient( 'kmfamily_activated', true, 60 );
}

register_deactivation_hook( __FILE__, 'kmfamily_deactivate' );
function kmfamily_deactivate() {
    wp_clear_scheduled_hook( 'kmfamily_daily_subscription_check' );
    flush_rewrite_rules();
}

add_action( 'admin_notices', 'kmfamily_check_dependencies' );
function kmfamily_check_dependencies() {
    if ( ! function_exists( 'get_field' ) ) {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>KM Family</strong> : ';
        echo esc_html__( 'Le plugin Advanced Custom Fields (ACF) est requis. ', 'km-family' );
        echo '<a href="' . esc_url( admin_url( 'plugin-install.php?s=advanced+custom+fields&tab=search' ) ) . '">';
        echo esc_html__( 'Installer ACF', 'km-family' );
        echo '</a>.';
        echo '</p></div>';
    }
}

add_action( 'plugins_loaded', 'kmfamily_init' );
function kmfamily_init() {
    if ( ! function_exists( 'get_field' ) ) return;

    load_plugin_textdomain( 'km-family', false, dirname( KMFAMILY_BASENAME ) . '/languages' );

    require_once KMFAMILY_PATH . 'includes/class-kmfamily-roles.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-install.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-security.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-paliers.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-periodicity.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-cpt.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-acf.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-subscriptions.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-access.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-multisite.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-frontend.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-admin.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-shortcodes.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-payment-gateway.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-orders.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-payment-methods.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-security-tokens.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-event-log.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-payment-tracking.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-risk-engine.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-security-dashboard.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-payment-center.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-tickets.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-loyalty.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-notifications.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-direct-link.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-auth.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-media-protector.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-badges.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-profile.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-revenue.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-memberships.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-renewal.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-artist-publishing.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-media-drivers.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-media-session.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-playback.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-player.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-availability.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-collections.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-playlists.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-fanhub.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-cloudflare.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-loyalty-plus.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-rewards.php';
    require_once KMFAMILY_PATH . 'includes/class-kmfamily-analytics.php';

    KMFamily_Security::init();
    KMFamily_Paliers::init();
    KMFamily_Periodicity::init();
    KMFamily_CPT::init();
    KMFamily_ACF::init();
    KMFamily_Subscriptions::init();
    KMFamily_Access::init();
    KMFamily_Multisite::init();
    KMFamily_Frontend::init();
    KMFamily_Admin::init();
    KMFamily_Shortcodes::init();
    KMFamily_Orders::init();
    KMFamily_Event_Log::init();
    KMFamily_Security_Tokens::init();
    KMFamily_Payment_Tracking::init();
    KMFamily_Security_Dashboard::init();
    KMFamily_Payment_Gateway::init();
    KMFamily_Payment_Center::init();
    KMFamily_Tickets::init();
    KMFamily_Loyalty::init();
    KMFamily_Notifications::init();
    KMFamily_Direct_Link::init();
    KMFamily_Auth::init();
    KMFamily_Media_Protector::init();
    KMFamily_Badges::init();
    KMFamily_Profile::init();
    KMFamily_Revenue::init();
    KMFamily_Memberships::init();
    KMFamily_Renewal::init();
    KMFamily_Artist_Publishing::init();
    KMFamily_Media_Session::init();
    KMFamily_Playback::init();
    KMFamily_Player::init();
    KMFamily_Availability::init();
    KMFamily_Collections::init();
    KMFamily_Playlists::init();
    KMFamily_FanHub::init();
    KMFamily_Cloudflare_Stream::init();
    KMFamily_Loyalty_Plus::init();
    KMFamily_Rewards::init();
    KMFamily_Analytics::init();
}

add_filter( 'plugin_action_links_' . KMFAMILY_BASENAME, 'kmfamily_action_links' );
function kmfamily_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=km-family' ) ) . '">' . esc_html__( 'Réglages', 'km-family' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
