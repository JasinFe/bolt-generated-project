<?php
/**
 * Plugin Name:       KM SALLE D'ÉCOUTE
 * Plugin URI:        https://votre-label.com
 * Description:       Système d'écoute sécurisé de démos et productions musicales pour partenaires et invités du Label.
 * Version:           1.8.0
 * Author:            KM Label
 * Text Domain:       km-salle-ecoute
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constantes globales
define( 'KM_SALLE_ECOUTE_VERSION',    '1.8.0' );
define( 'KM_SALLE_ECOUTE_FILE',       __FILE__ );
define( 'KM_SALLE_ECOUTE_DIR',        plugin_dir_path( __FILE__ ) );
define( 'KM_SALLE_ECOUTE_URL',        plugin_dir_url( __FILE__ ) );
define( 'KM_SALLE_ECOUTE_TEMPLATES',  KM_SALLE_ECOUTE_DIR . 'templates/' );

// Chargement des modules
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-icons.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-cpt.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-acf-fields.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-acf-helper.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-access-control.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-player.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-pages.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-templates.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-shortcodes.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-wpcode-snippets.php';
require_once KM_SALLE_ECOUTE_DIR . 'includes/class-admin.php';

/**
 * Classe principale du plugin
 */
final class KM_Salle_Ecoute {

    private static ?KM_Salle_Ecoute $instance = null;

    public static function get_instance(): KM_Salle_Ecoute {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        register_activation_hook( __FILE__,   [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__,  [ $this, 'deactivate' ] );
    }

    public function init(): void {
        load_plugin_textdomain( 'km-salle-ecoute', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Le CPT et l'admin fonctionnent sans ACF (pour pouvoir afficher l'avertissement
        // et garder l'accès aux sessions). Tout ce qui lit des champs ACF côté public est
        // désactivé si ACF est absent : plus d'erreur fatale « get_field() undefined ».
        KM_Salle_Ecoute_CPT::init();
        KM_Salle_Ecoute_Admin::init();

        if ( ! function_exists( 'get_field' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_acf_missing' ] );
            return;
        }

        KM_Salle_Ecoute_ACF_Fields::init();
        KM_Salle_Ecoute_Access_Control::init();
        KM_Salle_Ecoute_Player::init();
        KM_Salle_Ecoute_Templates::init();
        KM_Salle_Ecoute_Shortcodes::init();
        KM_Salle_Ecoute_WPCode_Snippets::init();

        add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );
    }

    public function notice_acf_missing(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) return;
        echo '<div class="notice notice-error"><p><strong>KM SALLE D\'ÉCOUTE</strong> — '
            . esc_html__( 'Advanced Custom Fields (ACF) est requis. Activez ACF (gratuit ou Pro) pour afficher les sessions d\'écoute.', 'km-salle-ecoute' )
            . '</p></div>';
    }

    /**
     * Migrations légères entre versions (exécutées une seule fois en admin).
     */
    public function maybe_upgrade(): void {
        $installed = (string) get_option( 'kse_version', '0' );
        if ( version_compare( $installed, KM_SALLE_ECOUTE_VERSION, '>=' ) ) return;

        // 1.7.2 : titre de la page catalogue créé avec un antislash (« d\'Écoute »)
        KM_Salle_Ecoute_Pages::fix_titles();
        // Les règles de réécriture peuvent avoir changé (sitemaps, etc.)
        flush_rewrite_rules( false );

        update_option( 'kse_version', KM_SALLE_ECOUTE_VERSION );
    }

    public function activate(): void {
        KM_Salle_Ecoute_CPT::register();
        KM_Salle_Ecoute_Pages::create_pages();
        flush_rewrite_rules();

        // Option de version pour futures migrations
        update_option( 'kse_version', KM_SALLE_ECOUTE_VERSION );
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }
}

KM_Salle_Ecoute::get_instance();
