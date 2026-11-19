<?php
/**
 * Plugin Name: KM Blog
 * Description: Blog personnalisé pour KOPHI'S MUSIC : des articles de fond, distincts des actualités du label et des artistes.
 * Version: 1.3.0
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
		if ( defined( 'KMBLOG_VERSION' ) || defined( 'KMBLOG_DIR' ) ) {
			$where   = defined( 'KMBLOG_FILE' ) ? KMBLOG_FILE : ( defined( 'KMBLOG_DIR' ) ? KMBLOG_DIR : '?' );
			$found[] = 'une autre copie de KM Blog est déjà active (' . ( defined( 'KMBLOG_VERSION' ) ? 'version ' . KMBLOG_VERSION . ', ' : '' ) . $where . ')';
			return $found;
		}
		// Ancienne version 1.0/1.1 de KM Blog encore active dans un autre dossier.
		if ( defined( 'KMB_FILE' ) && 'km-blog.php' === basename( (string) KMB_FILE ) ) {
			$found[] = 'une ancienne copie de KM Blog est encore active (' . ( defined( 'KMB_VERSION' ) ? 'version ' . KMB_VERSION . ', ' : '' ) . KMB_FILE . ')';
			return $found;
		}
		$names = array(
			'kmblog_default_settings', 'kmblog_get_settings', 'kmblog_opt', 'kmblog_reading_time', 'kmblog_is_blog_page',
			'kmblog_locate', 'kmblog_get_template', 'kmblog_related_posts', 'kmblog_link', 'kmblog_featured_id',
			'kmblog_register_content_types', 'kmblog_subtitle_box', 'kmblog_sanitize_settings', 'kmblog_settings_page',
			'kmblog_load_assets', 'kmblog_mode', 'kmblog_count_words', 'kmblog_has_shortcode', 'kmblog_plain_title',
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
		echo '<div class="notice notice-error"><p><strong>KM Blog ' . esc_html( '1.3.0' ) . ' n’a pas été chargé pour éviter une erreur fatale :</strong></p><ul style="list-style:disc;padding-left:20px">';
		foreach ( $kmblog_conflicts as $c ) {
			echo '<li>' . esc_html( $c ) . '</li>';
		}
		echo '</ul><p>Désactivez puis supprimez l’ancienne copie de KM Blog (ou le code en conflit), puis activez de nouveau cette version. Vos articles ne sont pas perdus.</p></div>';
	};
	add_action( 'admin_notices', $kmblog_notice );
	add_action( 'network_admin_notices', $kmblog_notice );
	return;
}

define( 'KMBLOG_VERSION', '1.3.0' );
define( 'KMBLOG_FILE', __FILE__ );
define( 'KMBLOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'KMBLOG_URL', plugin_dir_url( __FILE__ ) );

require_once KMBLOG_DIR . 'includes/helpers.php';
require_once KMBLOG_DIR . 'includes/post-types.php';
require_once KMBLOG_DIR . 'includes/settings.php';
require_once KMBLOG_DIR . 'includes/templates.php';
require_once KMBLOG_DIR . 'includes/shortcode.php';
require_once KMBLOG_DIR . 'includes/schema.php';

register_activation_hook( __FILE__, function () {
	if ( false === get_option( 'kmblog_settings' ) ) {
		add_option( 'kmblog_settings', kmblog_default_settings() );
	}
	kmblog_register_content_types();
	flush_rewrite_rules();
	update_option( 'kmblog_version', KMBLOG_VERSION, false );
} );

register_deactivation_hook( __FILE__, function () {
	// On retire d'abord le type de contenu pour que ses URL disparaissent réellement.
	unregister_post_type( 'km_blog' );
	flush_rewrite_rules();
} );

// Lien « Réglages » dans la liste des extensions.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=km_blog&page=kmblog-settings' ) ) . '">Réglages</a>' );
	return $links;
} );
