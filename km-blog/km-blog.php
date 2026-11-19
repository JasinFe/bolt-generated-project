<?php
/**
 * Plugin Name: KM Blog
 * Description: Blog personnalisé pour KOPHI'S MUSIC : des articles de fond, distincts des actualités du label et des artistes.
 * Version: 1.2.0
 * Author: KOPHI'S GROUP SAS
 * Text Domain: km-blog
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KMB_VERSION', '1.2.0' );
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
	update_option( 'kmb_version', KMB_VERSION, false );
} );

register_deactivation_hook( __FILE__, function () {
	// On retire d'abord le type de contenu pour que ses URL disparaissent réellement.
	unregister_post_type( 'km_blog' );
	flush_rewrite_rules();
} );

// Lien « Réglages » dans la liste des extensions.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=km_blog&page=kmb-settings' ) ) . '">Réglages</a>' );
	return $links;
} );
