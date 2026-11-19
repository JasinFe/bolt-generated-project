<?php
/**
 * Plugin Name: KM Blog
 * Description: Blog personnalisé pour KOPHI'S MUSIC : des articles de fond, distincts des actualités du label et des artistes.
 * Version: 1.2.1
 * Author: KOPHI'S GROUP SAS
 * Text Domain: km-blog
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Garde-fou : si une autre copie de KM Blog (ancienne version restée dans un
 * autre dossier) ou une fonction du même nom (thème, snippet, autre extension)
 * est déjà chargée, on ne charge rien et on affiche un message explicite au
 * lieu de provoquer une « erreur fatale ».
 */
if ( ! function_exists( 'kmblog_conflicts' ) ) {
	function kmblog_conflicts() {
		$found = array();
		if ( defined( 'KMB_VERSION' ) || defined( 'KMB_DIR' ) ) {
			$where   = defined( 'KMB_FILE' ) ? KMB_FILE : ( defined( 'KMB_DIR' ) ? KMB_DIR : '?' );
			$found[] = 'une autre copie de KM Blog est déjà active (' . ( defined( 'KMB_VERSION' ) ? 'version ' . KMB_VERSION . ', ' : '' ) . $where . ')';
			return $found;
		}
		$names = array(
			'kmb_default_settings', 'kmb_get_settings', 'kmb_opt', 'kmb_reading_time', 'kmb_is_blog_page',
			'kmb_locate', 'kmb_get_template', 'kmb_related_posts', 'kmb_link', 'kmb_featured_id',
			'kmb_register_content_types', 'kmb_subtitle_box', 'kmb_sanitize_settings', 'kmb_settings_page',
			'kmb_load_assets', 'kmblog_mode', 'kmblog_count_words', 'kmblog_has_shortcode', 'kmblog_plain_title',
			'kmblog_primary_term', 'kmblog_seo_plugin_active', 'kmblog_on_color', 'kmblog_icon', 'kmblog_meta_tag',
			'kmblog_head_single', 'kmblog_head_archive', 'kmblog_breadcrumb_schema', 'kmblog_print_jsonld',
		);
		foreach ( $names as $fn ) {
			if ( function_exists( $fn ) ) {
				$where = '';
				try {
					$ref   = new ReflectionFunction( $fn );
					$where = ' (déjà définie dans ' . $ref->getFileName() . ', ligne ' . $ref->getStartLine() . ')';
				} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				}
				$found[] = 'la fonction ' . $fn . '()' . $where;
			}
		}
		return $found;
	}
}

$kmblog_conflicts = kmblog_conflicts();
if ( $kmblog_conflicts ) {
	$kmblog_notice = function () use ( $kmblog_conflicts ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>KM Blog ' . esc_html( '1.2.1' ) . ' n’a pas été chargé pour éviter une erreur fatale :</strong></p><ul style="list-style:disc;padding-left:20px">';
		foreach ( $kmblog_conflicts as $c ) {
			echo '<li>' . esc_html( $c ) . '</li>';
		}
		echo '</ul><p>Désactivez puis supprimez l’ancienne copie de KM Blog (ou le code en conflit), puis activez de nouveau cette version. Vos articles ne sont pas perdus.</p></div>';
	};
	add_action( 'admin_notices', $kmblog_notice );
	add_action( 'network_admin_notices', $kmblog_notice );
	return;
}

define( 'KMB_VERSION', '1.2.1' );
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
