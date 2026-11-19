<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kmb_default_settings() {
	return array(
		'slug'          => 'blog',
		'title'         => 'Le Blog',
		'intro'         => 'Réflexions, coulisses et paroles de fond, loin du fil d’actualité du label.',
		'per_page'      => 9,
		'accent'        => '#d4a24c',
		'mode'          => 'dark',
		'use_templates' => 1,
		'show_author'   => 1,
		'show_reading'  => 1,
		'show_share'    => 1,
		'show_related'  => 1,
		'show_featured' => 1,
		'cta_newsletter'=> 1,
		'newsletter_url'=> '/newsletter/',
		'cta_family'    => 1,
		'family_url'    => '/rejoindre-km-family/',
	);
}

function kmb_get_settings() {
	return wp_parse_args( get_option( 'kmb_settings', array() ), kmb_default_settings() );
}

function kmb_opt( $key ) {
	$s = kmb_get_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

function kmb_reading_time( $post_id = null ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return 1;
	}
	$text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	return max( 1, (int) ceil( count( $words ) / 200 ) );
}

function kmb_is_blog_page() {
	return is_singular( 'km_blog' ) || is_post_type_archive( 'km_blog' ) || is_tax( array( 'km_blog_cat', 'km_blog_tag' ) );
}

function kmb_locate( $name ) {
	$theme = locate_template( 'km-blog/' . $name . '.php' );
	return $theme ? $theme : KMB_DIR . 'templates/' . $name . '.php';
}

function kmb_get_template( $name, $args = array() ) {
	$file = kmb_locate( $name );
	if ( ! file_exists( $file ) ) {
		return;
	}
	if ( $args ) {
		extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	}
	include $file;
}

function kmb_related_posts( $post_id, $limit = 3 ) {
	$args  = array(
		'post_type'           => 'km_blog',
		'posts_per_page'      => $limit,
		'post__not_in'        => array( $post_id ),
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);
	$terms = wp_get_post_terms( $post_id, 'km_blog_cat', array( 'fields' => 'ids' ) );
	if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'km_blog_cat',
				'field'    => 'term_id',
				'terms'    => $terms,
			),
		);
	}
	return new WP_Query( $args );
}

// Transforme un chemin relatif (/newsletter/) en URL complète.
function kmb_link( $url ) {
	$url = (string) $url;
	if ( '' !== $url && '/' === $url[0] ) {
		return home_url( $url );
	}
	return $url;
}

// Identifiant de l'article « À la une » (le plus récent coché), mis en cache.
function kmb_featured_id() {
	static $id = null;
	if ( null !== $id ) {
		return $id;
	}
	$id = 0;
	if ( ! kmb_opt( 'show_featured' ) ) {
		return $id;
	}
	$q = new WP_Query( array(
		'post_type'           => 'km_blog',
		'posts_per_page'      => 1,
		'fields'              => 'ids',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
		'meta_key'            => '_kmb_featured',
		'meta_value'          => '1',
	) );
	if ( ! empty( $q->posts ) ) {
		$id = (int) $q->posts[0];
	}
	return $id;
}
