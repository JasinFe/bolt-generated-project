<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'template_include', function ( $template ) {
	if ( ! kmb_opt( 'use_templates' ) || wp_is_block_theme() ) {
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
	if ( is_admin() || ! $q->is_main_query() ) {
		return;
	}
	if ( $q->is_post_type_archive( 'km_blog' ) || $q->is_tax( array( 'km_blog_cat', 'km_blog_tag' ) ) ) {
		$q->set( 'posts_per_page', (int) kmb_opt( 'per_page' ) );
		if ( $q->is_post_type_archive( 'km_blog' ) && kmb_opt( 'use_templates' ) && kmb_featured_id() ) {
			$q->set( 'post__not_in', array( kmb_featured_id() ) );
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
	$done = true;
	wp_enqueue_style( 'km-blog', KMB_URL . 'assets/css/km-blog.css', array(), KMB_VERSION );
	wp_add_inline_style( 'km-blog', ':root{--kmb-user-accent:' . esc_attr( kmb_opt( 'accent' ) ) . ';}' );
	wp_enqueue_script( 'km-blog', KMB_URL . 'assets/js/km-blog.js', array(), KMB_VERSION, true );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( kmb_is_blog_page() ) {
		kmb_load_assets();
	}
} );
