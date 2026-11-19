<?php
/**
 * Plugin Name: KM Blog
 * Description: Blog personnalisé pour KOPHI'S MUSIC : des articles de fond, distincts des actualités du label et des artistes.
 * Version: 1.1.0
 * Author: KOPHI'S GROUP SAS
 * Text Domain: km-blog
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KMB_VERSION', '1.1.0' );
define( 'KMB_FILE', __FILE__ );
define( 'KMB_DIR', plugin_dir_path( __FILE__ ) );
define( 'KMB_URL', plugin_dir_url( __FILE__ ) );

require_once KMB_DIR . 'includes/helpers.php';
require_once KMB_DIR . 'includes/post-types.php';
require_once KMB_DIR . 'includes/settings.php';
require_once KMB_DIR . 'includes/templates.php';
require_once KMB_DIR . 'includes/shortcode.php';
require_once KMB_DIR . 'includes/schema.php';

register_activation_hook( __FILE__, function () {
	if ( false === get_option( 'kmb_settings' ) ) {
		add_option( 'kmb_settings', kmb_default_settings() );
	}
	kmb_register_content_types();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );
