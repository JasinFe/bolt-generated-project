<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<main id="main-content" class="kmb kmb-single kmb--<?php echo esc_attr( kmb_opt( 'mode' ) ); ?>">
<?php
while ( have_posts() ) :
	the_post();
	$kmb_id    = get_the_ID();
	$kmb_sub   = get_post_meta( $kmb_id, '_kmb_subtitle', true );
	$kmb_cats  = get_the_terms( $kmb_id, 'km_blog_cat' );
	$kmb_tags  = get_the_terms( $kmb_id, 'km_blog_tag' );
	$kmb_url   = rawurlencode( get_permalink() );
	$kmb_text  = rawurlencode( get_the_title() . ' — ' );
	?>
	<article class="kmb-article">
		<header class="kmb-article__head">
			<?php if ( $kmb_cats && ! is_wp_error( $kmb_cats ) ) : ?>
				<a class="kmb-badge" href="<?php echo esc_url( get_term_link( $kmb_cats[0] ) ); ?>"><?php echo esc_html( $kmb_cats[0]->name ); ?></a>
			<?php endif; ?>
			<h1 class="kmb-article__title"><?php the_title(); ?></h1>
			<?php if ( $kmb_sub ) : ?>
				<p class="kmb-article__sub"><?php echo esc_html( $kmb_sub ); ?></p>
			<?php endif; ?>
			<div class="kmb-meta">
				<?php if ( kmb_opt( 'show_author' ) ) : ?>
					<span><?php echo esc_html( get_the_author() ); ?></span><span>·</span>
				<?php endif; ?>
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				<?php if ( kmb_opt( 'show_reading' ) ) : ?>
					<span>· <?php echo (int) kmb_reading_time(); ?> min de lecture</span>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( has_post_thumbnail() ) : ?>
			<figure class="kmb-article__cover"><?php the_post_thumbnail( 'full' ); ?></figure>
		<?php endif; ?>

		<div class="kmb-content"><?php the_content(); ?></div>

		<?php if ( $kmb_tags && ! is_wp_error( $kmb_tags ) ) : ?>
			<div class="kmb-tags">
				<?php foreach ( $kmb_tags as $kmb_tag ) : ?>
					<a href="<?php echo esc_url( get_term_link( $kmb_tag ) ); ?>">#<?php echo esc_html( $kmb_tag->name ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( kmb_opt( 'show_share' ) ) : ?>
			<div class="kmb-share">
				<span>Partager</span>
				<a class="kmb-share__btn" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://wa.me/?text=' . $kmb_text . $kmb_url ); ?>">WhatsApp</a>
				<a class="kmb-share__btn" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . $kmb_url ); ?>">Facebook</a>
				<a class="kmb-share__btn" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://twitter.com/intent/tweet?text=' . $kmb_text . '&url=' . $kmb_url ); ?>">X</a>
				<button type="button" class="kmb-share__btn" data-kmb-copy="<?php echo esc_url( get_permalink() ); ?>">Copier le lien</button>
			</div>
		<?php endif; ?>

		<?php kmb_get_template( 'cta' ); ?>

		<?php if ( kmb_opt( 'show_author' ) ) : ?>
			<aside class="kmb-author">
				<?php echo get_avatar( get_the_author_meta( 'ID' ), 72, '', '', array( 'class' => 'kmb-author__img' ) ); ?>
				<div>
					<strong><?php echo esc_html( get_the_author() ); ?></strong>
					<?php if ( get_the_author_meta( 'description' ) ) : ?>
						<p><?php echo esc_html( get_the_author_meta( 'description' ) ); ?></p>
					<?php endif; ?>
				</div>
			</aside>
		<?php endif; ?>
	</article>

	<?php
	if ( kmb_opt( 'show_related' ) ) :
		$kmb_related = kmb_related_posts( $kmb_id, 3 );
		if ( $kmb_related->have_posts() ) :
			?>
			<section class="kmb-wrap kmb-related">
				<h2 class="kmb-related__title">À lire aussi</h2>
				<div class="kmb-grid kmb-cols-3">
					<?php
					while ( $kmb_related->have_posts() ) {
						$kmb_related->the_post();
						kmb_get_template( 'card' );
					}
					wp_reset_postdata();
					?>
				</div>
			</section>
			<?php
		endif;
	endif;
	?>

	<p class="kmb-back"><a href="<?php echo esc_url( get_post_type_archive_link( 'km_blog' ) ); ?>">← Retour au blog</a></p>
<?php endwhile; ?>
</main>
<?php
get_footer();
