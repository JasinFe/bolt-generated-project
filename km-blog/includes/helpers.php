<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kmb_default_settings() {
	return array(
		'slug'           => 'blog',
		'title'          => 'Le Blog',
		'intro'          => 'Réflexions, coulisses et paroles de fond, loin du fil d’actualité du label.',
		'per_page'       => 9,
		'accent'         => '#d4a24c',
		'mode'           => 'dark',
		'use_templates'  => 1,
		'show_author'    => 1,
		'show_reading'   => 1,
		'show_share'     => 1,
		'show_related'   => 1,
		'show_featured'  => 1,
		'show_progress'  => 1,
		'show_toc'       => 1,
		'cta_newsletter' => 1,
		'newsletter_url' => '/newsletter/',
		'cta_family'     => 1,
		'family_url'     => '/rejoindre-km-family/',
	);
}

/**
 * Réglages fusionnés avec les valeurs par défaut, mis en cache pour la requête.
 * Le cache est invalidé dès que l'option est modifiée.
 */
function kmb_get_settings() {
	static $cache = null;
	if ( null === $cache ) {
		$saved = get_option( 'kmb_settings', array() );
		$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), kmb_default_settings() );
	}
	return $cache;
}

function kmb_opt( $key ) {
	$s = kmb_get_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/** Mode d'affichage validé (dark|light), sûr pour une classe CSS. */
function kmb_mode() {
	return 'light' === kmb_opt( 'mode' ) ? 'light' : 'dark';
}

/** Compte les mots d'un contenu (shortcodes et HTML retirés). */
function kmb_count_words( $content ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) $content ) );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	return count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
}

/**
 * Temps de lecture en minutes (230 mots/min).
 * Calculé à l'enregistrement et stocké dans _kmb_reading pour éviter de
 * re-parser chaque contenu à chaque affichage de carte.
 */
function kmb_reading_time( $post_id = null ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return 1;
	}
	$cached = (int) get_post_meta( $post->ID, '_kmb_reading', true );
	if ( $cached > 0 ) {
		return $cached;
	}
	$minutes = max( 1, (int) ceil( kmb_count_words( $post->post_content ) / 230 ) );
	if ( 'publish' === $post->post_status ) {
		update_post_meta( $post->ID, '_kmb_reading', $minutes );
	}
	return $minutes;
}

function kmb_is_blog_page() {
	return is_singular( 'km_blog' ) || is_post_type_archive( 'km_blog' ) || is_tax( array( 'km_blog_cat', 'km_blog_tag' ) );
}

/** Le contenu courant contient-il le shortcode [km_blog] ? */
function kmb_has_shortcode() {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_queried_object();
	return $post instanceof WP_Post && has_shortcode( $post->post_content, 'km_blog' );
}

function kmb_locate( $name ) {
	$name  = sanitize_file_name( $name );
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

/** Titre en texte brut (entités décodées) pour le partage et le JSON-LD. */
function kmb_plain_title( $post = null ) {
	return html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
}

/** Première rubrique d'un article, ou null. */
function kmb_primary_term( $post_id ) {
	$terms = get_the_terms( $post_id, 'km_blog_cat' );
	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
}

/**
 * Articles similaires : même rubrique d'abord, complétés par les plus récents
 * si la rubrique ne suffit pas.
 */
function kmb_related_posts( $post_id, $limit = 3 ) {
	$limit = max( 1, (int) $limit );
	$ids   = array();
	$terms = wp_get_post_terms( $post_id, 'km_blog_cat', array( 'fields' => 'ids' ) );

	if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
		$ids = get_posts( array(
			'post_type'        => 'km_blog',
			'posts_per_page'   => $limit,
			'post__not_in'     => array( $post_id ),
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'km_blog_cat',
					'field'    => 'term_id',
					'terms'    => $terms,
				),
			),
		) );
	}

	if ( count( $ids ) < $limit ) {
		$more = get_posts( array(
			'post_type'        => 'km_blog',
			'posts_per_page'   => $limit - count( $ids ),
			'post__not_in'     => array_merge( array( $post_id ), $ids ),
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) );
		$ids  = array_merge( $ids, $more );
	}

	return new WP_Query( array(
		'post_type'           => 'km_blog',
		'post__in'            => $ids ? $ids : array( 0 ),
		'orderby'             => 'post__in',
		'posts_per_page'      => $limit,
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	) );
}

// Transforme un chemin relatif (/newsletter/) en URL complète.
function kmb_link( $url ) {
	$url = trim( (string) $url );
	if ( '' !== $url && '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
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
		'post_status'         => 'publish',
		'posts_per_page'      => 1,
		'fields'              => 'ids',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
		'meta_key'            => '_kmb_featured', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'          => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	if ( ! empty( $q->posts ) ) {
		$id = (int) $q->posts[0];
	}
	return $id;
}

/** Une SEO extension gère-t-elle déjà les balises meta / JSON-LD ? */
function kmb_seo_plugin_active() {
	$active = defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' )
		|| defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' ) || defined( 'SLIM_SEO_VER' )
		|| class_exists( 'SQ_Classes_ObjController' );
	return (bool) apply_filters( 'kmb_seo_plugin_active', $active );
}

/** Couleur de texte lisible (noir ou blanc) sur un fond de couleur donnée. */
function kmb_on_color( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
		return '#111111';
	}
	$lin = function ( $c ) {
		$c = $c / 255;
		return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	};
	$l = 0.2126 * $lin( hexdec( substr( $hex, 0, 2 ) ) ) + 0.7152 * $lin( hexdec( substr( $hex, 2, 2 ) ) ) + 0.0722 * $lin( hexdec( substr( $hex, 4, 2 ) ) );
	// Contraste avec le noir (#111) vs le blanc.
	return ( ( $l + 0.05 ) / 0.0556 ) >= ( 1.05 / ( $l + 0.05 ) ) ? '#111111' : '#ffffff';
}

/** Icônes SVG inline (décoratives, masquées aux lecteurs d'écran). */
function kmb_icon( $name ) {
	$paths = array(
		'whatsapp' => '<path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.3c0-.1-.2-.2-.5-.3Z" fill="currentColor"/>',
		'facebook' => '<path d="M14 8.5V6.8c0-.8.2-1.3 1.4-1.3H17V2.2A21 21 0 0 0 14.6 2C12.2 2 10.6 3.5 10.6 6.2v2.3H8V12h2.6v10H14V12h2.7l.4-3.5H14Z" fill="currentColor"/>',
		'x'        => '<path d="M17.8 3h3l-6.6 7.6L22 21h-6.1l-4.8-6.2L5.6 21h-3l7.1-8.1L2.2 3h6.2l4.3 5.7L17.8 3Zm-1 16.2h1.7L7.3 4.7H5.5l11.3 14.5Z" fill="currentColor"/>',
		'link'     => '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1m2 5a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
		'share'    => '<path d="M12 3v12m0-12L7.5 7.5M12 3l4.5 4.5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
		'arrow'    => '<path d="M5 12h14m-6-6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
		'back'     => '<path d="M19 12H5m6 6-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
		'mail'     => '<path d="M4 6h16v12H4zM4 7l8 6 8-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
		'heart'    => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
		'clock'    => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg class="kmb-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}
