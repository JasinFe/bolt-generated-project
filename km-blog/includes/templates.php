<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'template_include', function ( $template ) {
	if ( ! kmblog_opt( 'use_templates' ) || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
		return $template;
	}
	if ( is_singular( 'km_blog' ) ) {
		return kmblog_locate( 'single' );
	}
	if ( is_post_type_archive( 'km_blog' ) || is_tax( array( 'km_blog_cat', 'km_blog_tag' ) ) ) {
		return kmblog_locate( 'archive' );
	}
	return $template;
}, 99 );

add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() || $q->is_feed() ) {
		return;
	}
	if ( $q->is_post_type_archive( 'km_blog' ) || $q->is_tax( array( 'km_blog_cat', 'km_blog_tag' ) ) ) {
		$q->set( 'posts_per_page', (int) kmblog_opt( 'per_page' ) );
		// L'article « À la une » est affiché à part : on l'exclut de la grille.
		if ( $q->is_post_type_archive( 'km_blog' ) && ! $q->is_search() && kmblog_opt( 'use_templates' ) && kmblog_featured_id() ) {
			$exclude   = (array) $q->get( 'post__not_in' );
			$exclude[] = kmblog_featured_id();
			$q->set( 'post__not_in', array_unique( array_map( 'intval', $exclude ) ) );
		}
	}
} );

add_filter( 'body_class', function ( $classes ) {
	if ( kmblog_is_blog_page() ) {
		$classes[] = 'kmblog-page';
	}
	return $classes;
} );

function kmblog_load_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done   = true;
	$accent = sanitize_hex_color( kmblog_opt( 'accent' ) );
	$accent = $accent ? $accent : '#d4a24c';
	$css    = KMBLOG_DIR . 'assets/css/km-blog.css';
	$js     = KMBLOG_DIR . 'assets/js/km-blog.js';
	// Version = version du plugin + date du fichier : les caches sont vidés à chaque modification.
	wp_enqueue_style( 'km-blog', KMBLOG_URL . 'assets/css/km-blog.css', array(), KMBLOG_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : 0 ) );
	wp_add_inline_style( 'km-blog', ':root{--kmblog-user-accent:' . $accent . ';--kmblog-user-on-accent:' . kmblog_on_color( $accent ) . ';}' );
	wp_enqueue_script( 'km-blog', KMBLOG_URL . 'assets/js/km-blog.js', array(), KMBLOG_VERSION . '.' . ( file_exists( $js ) ? filemtime( $js ) : 0 ), true );
	if ( function_exists( 'wp_script_add_data' ) ) {
		wp_script_add_data( 'km-blog', 'strategy', 'defer' );
	}
}

add_action( 'wp_enqueue_scripts', function () {
	// Chargé sur les pages du blog et sur toute page contenant [km_blog]
	// (évite l'apparition tardive des styles en pied de page).
	if ( kmblog_is_blog_page() || kmblog_has_shortcode() ) {
		kmblog_load_assets();
	}
} );
