<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'km_blog', function ( $atts ) {
	$a = shortcode_atts( array(
		'limit'    => 6,
		'columns'  => 3,
		'category' => '',
		'tag'      => '',
		'more'     => '1',
	), $atts, 'km_blog' );

	$args = array(
		'post_type'           => 'km_blog',
		'posts_per_page'      => max( 1, absint( $a['limit'] ) ),
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);

	$tax = array();
	if ( '' !== $a['category'] ) {
		$tax[] = array(
			'taxonomy' => 'km_blog_cat',
			'field'    => 'slug',
			'terms'    => array_map( 'sanitize_title', explode( ',', $a['category'] ) ),
		);
	}
	if ( '' !== $a['tag'] ) {
		$tax[] = array(
			'taxonomy' => 'km_blog_tag',
			'field'    => 'slug',
			'terms'    => array_map( 'sanitize_title', explode( ',', $a['tag'] ) ),
		);
	}
	if ( $tax ) {
		$args['tax_query'] = $tax;
	}

	$q = new WP_Query( $args );
	kmb_load_assets();

	$cols = min( 4, max( 1, absint( $a['columns'] ) ) );
	ob_start();
	echo '<div class="kmb kmb-embed kmb--' . esc_attr( kmb_opt( 'mode' ) ) . '">';
	if ( $q->have_posts() ) {
		echo '<div class="kmb-grid kmb-cols-' . (int) $cols . '">';
		while ( $q->have_posts() ) {
			$q->the_post();
			kmb_get_template( 'card' );
		}
		echo '</div>';
		if ( '0' !== $a['more'] ) {
			echo '<p class="kmb-more"><a class="kmb-btn" href="' . esc_url( get_post_type_archive_link( 'km_blog' ) ) . '">Tous les articles</a></p>';
		}
	} else {
		echo '<p class="kmb-empty">Aucun article pour le moment.</p>';
	}
	echo '</div>';
	wp_reset_postdata();
	return ob_get_clean();
} );
