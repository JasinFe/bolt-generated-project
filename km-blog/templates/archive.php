<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$kmb_term  = is_tax() ? get_queried_object() : null;
$kmb_title = $kmb_term ? $kmb_term->name : kmb_opt( 'title' );
$kmb_pills = get_terms( array( 'taxonomy' => 'km_blog_cat', 'hide_empty' => true ) );
?>
<main id="main-content" class="kmb kmb-archive kmb--<?php echo esc_attr( kmb_opt( 'mode' ) ); ?>">
	<div class="kmb-wrap">
		<header class="kmb-hero">
			<span class="kmb-eyebrow">KOPHI'S MUSIC</span>
			<h1 class="kmb-hero__title"><?php echo esc_html( $kmb_title ); ?></h1>
			<?php if ( $kmb_term && term_description( $kmb_term ) ) : ?>
				<div class="kmb-hero__intro"><?php echo wp_kses_post( term_description( $kmb_term ) ); ?></div>
			<?php elseif ( ! $kmb_term && kmb_opt( 'intro' ) ) : ?>
				<p class="kmb-hero__intro"><?php echo esc_html( kmb_opt( 'intro' ) ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( ! empty( $kmb_pills ) && ! is_wp_error( $kmb_pills ) ) : ?>
			<nav class="kmb-pills" aria-label="Rubriques du blog">
				<a class="kmb-pill<?php echo $kmb_term ? '' : ' is-active'; ?>" href="<?php echo esc_url( get_post_type_archive_link( 'km_blog' ) ); ?>">Tout</a>
				<?php foreach ( $kmb_pills as $kmb_pill ) : ?>
					<a class="kmb-pill<?php echo ( $kmb_term && $kmb_term->term_id === $kmb_pill->term_id ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( get_term_link( $kmb_pill ) ); ?>"><?php echo esc_html( $kmb_pill->name ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php
		$kmb_feat_id = ( ! $kmb_term && ! is_paged() ) ? kmb_featured_id() : 0;
		if ( $kmb_feat_id ) :
			$kmb_feat  = get_post( $kmb_feat_id );
			$kmb_fcats = get_the_terms( $kmb_feat_id, 'km_blog_cat' );
			?>
			<article class="kmb-featured">
				<a class="kmb-featured__media" href="<?php echo esc_url( get_permalink( $kmb_feat_id ) ); ?>" tabindex="-1" aria-hidden="true">
					<?php echo get_the_post_thumbnail( $kmb_feat_id, 'large', array( 'alt' => '' ) ); ?>
				</a>
				<div class="kmb-featured__body">
					<span class="kmb-eyebrow">À LA UNE<?php echo ( $kmb_fcats && ! is_wp_error( $kmb_fcats ) ) ? ' · ' . esc_html( mb_strtoupper( $kmb_fcats[0]->name ) ) : ''; ?></span>
					<h2 class="kmb-featured__title"><a href="<?php echo esc_url( get_permalink( $kmb_feat_id ) ); ?>"><?php echo esc_html( get_the_title( $kmb_feat_id ) ); ?></a></h2>
					<p class="kmb-featured__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $kmb_feat ), 36 ) ); ?></p>
					<div class="kmb-meta">
						<time datetime="<?php echo esc_attr( get_the_date( 'c', $kmb_feat ) ); ?>"><?php echo esc_html( get_the_date( '', $kmb_feat ) ); ?></time>
						<?php if ( kmb_opt( 'show_reading' ) ) : ?>
							<span>· <?php echo (int) kmb_reading_time( $kmb_feat_id ); ?> min de lecture</span>
						<?php endif; ?>
					</div>
					<a class="kmb-btn kmb-btn--solid" href="<?php echo esc_url( get_permalink( $kmb_feat_id ) ); ?>">Lire l’article</a>
				</div>
			</article>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="kmb-grid kmb-cols-3">
				<?php
				while ( have_posts() ) {
					the_post();
					kmb_get_template( 'card' );
				}
				?>
			</div>
			<?php
			the_posts_pagination( array(
				'mid_size'  => 1,
				'prev_text' => '←',
				'next_text' => '→',
			) );
			?>
		<?php else : ?>
			<p class="kmb-empty">Aucun article pour le moment. Revenez bientôt.</p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
