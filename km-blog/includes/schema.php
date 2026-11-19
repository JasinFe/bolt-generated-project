<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Données structurées simples, uniquement si aucun plugin SEO ne s'en charge.
add_action( 'wp_head', function () {
	if ( ! is_singular( 'km_blog' ) ) {
		return;
	}
	if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
		return;
	}
	$post = get_queried_object();
	$desc = wp_strip_all_tags( get_the_excerpt( $post ) );
	$data = array(
		'@context'         => 'https://schema.org',
		'@type'            => 'BlogPosting',
		'headline'         => get_the_title( $post ),
		'datePublished'    => get_the_date( 'c', $post ),
		'dateModified'     => get_the_modified_date( 'c', $post ),
		'mainEntityOfPage' => get_permalink( $post ),
		'inLanguage'       => get_bloginfo( 'language' ),
		'author'           => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $post->post_author ) ),
		'publisher'        => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
	);
	$img = get_the_post_thumbnail_url( $post, 'large' );
	if ( $img ) {
		$data['image'] = $img;
	}
	if ( $desc ) {
		$data['description'] = $desc;
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}, 5 );
