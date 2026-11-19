<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'template_include', function ( $template ) {
	if ( ! kmb_opt( 'use_templates' ) || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
		return $template;
	}
	if ( is_singular( 'km_blog' ) ) {
		return kmb_locate( 'single' );
	}
	if ( is_post_type_archive( 'km_blog' ) || is_tax( array( 'km_blog_cat', 'km_blog_tag' ) ) ) {
		return kmb_locate( 'archive' );
	}
	return $template;
}, 99 );

add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() || $q->is_feed() ) {
		return;
	}
	if ( $q->is_post_type_archive( 'km_blog' ) || $q->is_tax( array( 'km_blog_cat', 'km_blog_tag' ) ) ) {
		$q->set( 'posts_per_page', (int) kmb_opt( 'per_page' ) );
		// L'article « À la une » est affiché à part : on l'exclut de la grille.
		if ( $q->is_post_type_archive( 'km_blog' ) && ! $q->is_search() && kmb_opt( 'use_templates' ) && kmb_featured_id() ) {
			$exclude   = (array) $q->get( 'post__not_in' );
			$exclude[] = kmb_featured_id();
			$q->set( 'post__not_in', array_unique( array_map( 'intval', $exclude ) ) );
		}
	}
} );

add_filter( 'body_class', function ( $classes ) {
	if ( kmb_is_blog_page() ) {
		$classes[] = 'kmb-page';
	}
	return $classes;
} );

function kmb_load_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done   = true;
	$accent = sanitize_hex_color( kmb_opt( 'accent' ) );
	$accent = $accent ? $accent : '#d4a24c';
	$css    = KMB_DIR . 'assets/css/km-blog.css';
	$js     = KMB_DIR . 'assets/js/km-blog.js';
	// Version = version du plugin + date du fichier : les caches sont vidés à chaque modification.
	wp_enqueue_style( 'km-blog', KMB_URL . 'assets/css/km-blog.css', array(), KMB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : 0 ) );
	wp_add_inline_style( 'km-blog', ':root{--kmb-user-accent:' . $accent . ';--kmb-user-on-accent:' . kmblog_on_color( $accent ) . ';}' );
	wp_enqueue_script( 'km-blog', KMB_URL . 'assets/js/km-blog.js', array(), KMB_VERSION . '.' . ( file_exists( $js ) ? filemtime( $js ) : 0 ), true );
	if ( function_exists( 'wp_script_add_data' ) ) {
		wp_script_add_data( 'km-blog', 'strategy', 'defer' );
	}
}

add_action( 'wp_enqueue_scripts', function () {
	// Chargé sur les pages du blog et sur toute page contenant [km_blog]
	// (évite l'apparition tardive des styles en pied de page).
	if ( kmb_is_blog_page() || kmblog_has_shortcode() ) {
		kmb_load_assets();
	}
} );
