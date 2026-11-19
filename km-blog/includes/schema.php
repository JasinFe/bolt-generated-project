<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Données structurées (BlogPosting + fil d'Ariane), meta description et
 * balises Open Graph / Twitter, uniquement si aucune extension SEO ne s'en
 * charge déjà. Les aperçus WhatsApp / Facebook / X utilisent ces balises.
 */
add_action( 'wp_head', function () {
	if ( ! kmb_is_blog_page() || kmblog_seo_plugin_active() ) {
		return;
	}

	if ( is_singular( 'km_blog' ) ) {
		kmblog_head_single();
	} else {
		kmblog_head_archive();
	}
}, 5 );

function kmblog_meta_tag( $attr, $name, $content ) {
	if ( '' === (string) $content ) {
		return;
	}
	printf( '<meta %s="%s" content="%s">' . "\n", $attr, esc_attr( $name ), esc_attr( $content ) );
}

function kmblog_head_single() {
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	$title = kmblog_plain_title( $post );
	$sub   = (string) get_post_meta( $post->ID, '_kmb_subtitle', true );
	$desc  = has_excerpt( $post ) ? $post->post_excerpt : ( '' !== $sub ? $sub : wp_trim_words( strip_shortcodes( $post->post_content ), 30, '…' ) );
	$desc  = trim( html_entity_decode( wp_strip_all_tags( $desc ), ENT_QUOTES, 'UTF-8' ) );
	$url   = get_permalink( $post );
	$img   = get_the_post_thumbnail_url( $post, 'large' );
	$site  = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
	$term  = kmblog_primary_term( $post->ID );

	kmblog_meta_tag( 'name', 'description', $desc );
	kmblog_meta_tag( 'property', 'og:type', 'article' );
	kmblog_meta_tag( 'property', 'og:locale', str_replace( '-', '_', get_bloginfo( 'language' ) ) );
	kmblog_meta_tag( 'property', 'og:site_name', $site );
	kmblog_meta_tag( 'property', 'og:title', $title );
	kmblog_meta_tag( 'property', 'og:description', $desc );
	kmblog_meta_tag( 'property', 'og:url', $url );
	kmblog_meta_tag( 'property', 'og:image', $img );
	kmblog_meta_tag( 'property', 'article:published_time', get_the_date( 'c', $post ) );
	kmblog_meta_tag( 'property', 'article:modified_time', get_the_modified_date( 'c', $post ) );
	kmblog_meta_tag( 'property', 'article:section', $term ? $term->name : '' );
	kmblog_meta_tag( 'name', 'twitter:card', $img ? 'summary_large_image' : 'summary' );
	kmblog_meta_tag( 'name', 'twitter:title', $title );
	kmblog_meta_tag( 'name', 'twitter:description', $desc );
	kmblog_meta_tag( 'name', 'twitter:image', $img );

	$publisher = array(
		'@type' => 'Organization',
		'name'  => $site,
		'url'   => home_url( '/' ),
	);
	$logo_id   = (int) get_theme_mod( 'custom_logo' );
	$logo      = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : get_site_icon_url( 512 );
	if ( $logo ) {
		$publisher['logo'] = array( '@type' => 'ImageObject', 'url' => $logo );
	}

	$article = array(
		'@type'            => 'BlogPosting',
		'@id'              => $url . '#article',
		'headline'         => $title,
		'datePublished'    => get_the_date( 'c', $post ),
		'dateModified'     => get_the_modified_date( 'c', $post ),
		'mainEntityOfPage' => $url,
		'url'              => $url,
		'inLanguage'       => get_bloginfo( 'language' ),
		'wordCount'        => kmblog_count_words( $post->post_content ),
		'timeRequired'     => 'PT' . kmb_reading_time( $post->ID ) . 'M',
		'author'           => array(
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', $post->post_author ),
			'url'   => get_author_posts_url( $post->post_author ),
		),
		'publisher'        => $publisher,
	);
	if ( $img ) {
		$article['image'] = $img;
	}
	if ( $desc ) {
		$article['description'] = $desc;
	}
	if ( $term ) {
		$article['articleSection'] = $term->name;
	}

	$crumbs = array(
		array( 'name' => $site, 'item' => home_url( '/' ) ),
		array( 'name' => kmb_opt( 'title' ), 'item' => get_post_type_archive_link( 'km_blog' ) ),
	);
	if ( $term ) {
		$crumbs[] = array( 'name' => $term->name, 'item' => get_term_link( $term ) );
	}
	$crumbs[] = array( 'name' => $title, 'item' => $url );

	kmblog_print_jsonld( array( $article, kmblog_breadcrumb_schema( $crumbs ) ) );
}

function kmblog_head_archive() {
	$term  = is_tax() ? get_queried_object() : null;
	$title = $term ? $term->name . ' — ' . kmb_opt( 'title' ) : kmb_opt( 'title' );
	$desc  = $term && $term->description ? $term->description : kmb_opt( 'intro' );
	$desc  = trim( wp_strip_all_tags( (string) $desc ) );
	$url   = $term ? get_term_link( $term ) : get_post_type_archive_link( 'km_blog' );
	if ( is_wp_error( $url ) ) {
		return;
	}
	kmblog_meta_tag( 'name', 'description', $desc );
	kmblog_meta_tag( 'property', 'og:type', 'website' );
	kmblog_meta_tag( 'property', 'og:site_name', html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
	kmblog_meta_tag( 'property', 'og:title', $title );
	kmblog_meta_tag( 'property', 'og:description', $desc );
	kmblog_meta_tag( 'property', 'og:url', $url );
	kmblog_meta_tag( 'name', 'twitter:card', 'summary' );
}

function kmblog_breadcrumb_schema( $crumbs ) {
	$items = array();
	foreach ( array_values( $crumbs ) as $i => $c ) {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'name'     => html_entity_decode( wp_strip_all_tags( $c['name'] ), ENT_QUOTES, 'UTF-8' ),
			'item'     => is_wp_error( $c['item'] ) ? '' : $c['item'],
		);
	}
	return array( '@type' => 'BreadcrumbList', 'itemListElement' => $items );
}

function kmblog_print_jsonld( $graph ) {
	$data = array(
		'@context' => 'https://schema.org',
		'@graph'   => apply_filters( 'kmb_schema_graph', $graph ),
	);
	// JSON_HEX_TAG empêche toute sortie de la balise <script> via un titre piégé.
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n";
}
