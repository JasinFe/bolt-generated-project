<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kmb_cats = get_the_terms( get_the_ID(), 'km_blog_cat' );
?>
<article class="kmb-card">
	<a class="kmb-card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php
		if ( has_post_thumbnail() ) {
			the_post_thumbnail( 'large', array( 'loading' => 'lazy', 'alt' => '' ) );
		} else {
			echo '<span class="kmb-card__ph"></span>';
		}
		?>
	</a>
	<div class="kmb-card__body">
		<?php if ( $kmb_cats && ! is_wp_error( $kmb_cats ) ) : ?>
			<span class="kmb-badge"><?php echo esc_html( $kmb_cats[0]->name ); ?></span>
		<?php endif; ?>
		<h3 class="kmb-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<p class="kmb-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 24 ) ); ?></p>
		<div class="kmb-meta">
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			<?php if ( kmb_opt( 'show_reading' ) ) : ?>
				<span>· <?php echo (int) kmb_reading_time(); ?> min de lecture</span>
			<?php endif; ?>
		</div>
	</div>
</article>
