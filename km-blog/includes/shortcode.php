<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [km_blog limit="6" columns="3" category="slug" tag="slug" more="1" offset="0" title="À lire sur le blog"]
 */
add_shortcode( 'km_blog', function ( $atts ) {
	$a = shortcode_atts( array(
		'limit'    => 6,
		'columns'  => 3,
		'category' => '',
		'tag'      => '',
		'more'     => '1',
		'offset'   => 0,
		'title'    => '',
	), $atts, 'km_blog' );

	$args = array(
		'post_type'           => 'km_blog',
		'post_status'         => 'publish',
		'posts_per_page'      => min( 24, max( 1, absint( $a['limit'] ) ) ),
		'offset'              => absint( $a['offset'] ),
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);

	$tax = array();
	foreach ( array( 'category' => 'km_blog_cat', 'tag' => 'km_blog_tag' ) as $att => $taxonomy ) {
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $a[ $att ] ) ) );
		if ( $slugs ) {
			$tax[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
			);
		}
	}
	if ( $tax ) {
		$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	$q = new WP_Query( $args );
	kmb_load_assets();

	$cols = min( 4, max( 1, absint( $a['columns'] ) ) );
	$more = ! in_array( strtolower( trim( (string) $a['more'] ) ), array( '0', 'false', 'no', 'non', 'off', '' ), true );

	ob_start();
	echo '<div class="kmb kmb-embed kmb--' . esc_attr( kmblog_mode() ) . '">';
	if ( '' !== trim( $a['title'] ) ) {
		echo '<h2 class="kmb-section-title">' . esc_html( $a['title'] ) . '</h2>';
	}
	if ( $q->have_posts() ) {
		echo '<div class="kmb-grid kmb-cols-' . (int) $cols . '">';
		while ( $q->have_posts() ) {
			$q->the_post();
			kmb_get_template( 'card', array( 'kmb_heading' => '' !== trim( $a['title'] ) ? 'h3' : 'h2' ) );
		}
		echo '</div>';
		if ( $more ) {
			echo '<p class="kmb-more"><a class="kmb-btn" href="' . esc_url( get_post_type_archive_link( 'km_blog' ) ) . '">Tous les articles ' . kmblog_icon( 'arrow' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	} else {
		echo '<p class="kmb-empty">Aucun article pour le moment.</p>';
	}
	echo '</div>';
	wp_reset_postdata();
	return ob_get_clean();
} );
