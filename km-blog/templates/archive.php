<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

global $wp_query;
$kmb_term    = is_tax() ? get_queried_object() : null;
$kmb_is_tag  = $kmb_term && 'km_blog_tag' === $kmb_term->taxonomy;
$kmb_title   = $kmb_term ? ( $kmb_is_tag ? '#' . $kmb_term->name : $kmb_term->name ) : kmb_opt( 'title' );
$kmb_pills   = get_terms( array( 'taxonomy' => 'km_blog_cat', 'hide_empty' => true ) );
$kmb_feat_id = ( ! $kmb_term && ! is_paged() ) ? kmb_featured_id() : 0;
$kmb_total   = (int) $wp_query->found_posts + ( $kmb_feat_id ? 1 : 0 );
$kmb_blog    = get_post_type_archive_link( 'km_blog' );
?>
<main id="main-content" class="kmb kmb-archive kmb--<?php echo esc_attr( kmblog_mode() ); ?>">
	<div class="kmb-wrap">
		<header class="kmb-hero">
			<?php if ( $kmb_term ) : ?>
				<a class="kmb-eyebrow kmb-eyebrow--link" href="<?php echo esc_url( $kmb_blog ); ?>"><?php echo kmblog_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( kmb_opt( 'title' ) ); ?></a>
			<?php else : ?>
				<span class="kmb-eyebrow">KOPHI’S MUSIC</span>
			<?php endif; ?>
			<h1 class="kmb-hero__title"><?php echo esc_html( $kmb_title ); ?></h1>
			<?php if ( $kmb_term && term_description( $kmb_term ) ) : ?>
				<div class="kmb-hero__intro"><?php echo wp_kses_post( term_description( $kmb_term ) ); ?></div>
			<?php elseif ( ! $kmb_term && kmb_opt( 'intro' ) ) : ?>
				<p class="kmb-hero__intro"><?php echo esc_html( kmb_opt( 'intro' ) ); ?></p>
			<?php endif; ?>
			<?php if ( $kmb_total ) : ?>
				<p class="kmb-hero__count"><?php echo esc_html( sprintf( _n( '%s article', '%s articles', $kmb_total, 'km-blog' ), number_format_i18n( $kmb_total ) ) ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( ! empty( $kmb_pills ) && ! is_wp_error( $kmb_pills ) ) : ?>
			<nav class="kmb-pills" aria-label="Rubriques du blog">
				<a class="kmb-pill<?php echo $kmb_term ? '' : ' is-active'; ?>" href="<?php echo esc_url( $kmb_blog ); ?>"<?php echo $kmb_term ? '' : ' aria-current="page"'; ?>>Tout</a>
				<?php
				foreach ( $kmb_pills as $kmb_pill ) :
					$kmb_on = $kmb_term && (int) $kmb_term->term_id === (int) $kmb_pill->term_id;
					?>
					<a class="kmb-pill<?php echo $kmb_on ? ' is-active' : ''; ?>" href="<?php echo esc_url( get_term_link( $kmb_pill ) ); ?>"<?php echo $kmb_on ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $kmb_pill->name ); ?> <span class="kmb-pill__n"><?php echo (int) $kmb_pill->count; ?></span></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php
		if ( $kmb_feat_id ) :
			$kmb_feat  = get_post( $kmb_feat_id );
			$kmb_fterm = kmblog_primary_term( $kmb_feat_id );
			$kmb_fsub  = get_post_meta( $kmb_feat_id, '_kmb_subtitle', true );
			$kmb_fex   = has_excerpt( $kmb_feat ) ? get_the_excerpt( $kmb_feat ) : ( $kmb_fsub ? $kmb_fsub : get_the_excerpt( $kmb_feat ) );
			?>
			<article class="kmb-featured">
				<div class="kmb-featured__media">
					<?php
					if ( has_post_thumbnail( $kmb_feat_id ) ) {
						echo get_the_post_thumbnail( $kmb_feat_id, 'large', array(
							'alt'           => '',
							'loading'       => 'eager',
							'fetchpriority' => 'high',
							'sizes'         => '(max-width: 820px) 100vw, 640px',
						) );
					} else {
						echo '<span class="kmb-card__ph" aria-hidden="true"><span>KM</span></span>';
					}
					?>
				</div>
				<div class="kmb-featured__body">
					<span class="kmb-eyebrow"><span class="kmb-dot" aria-hidden="true"></span>À la une<?php echo $kmb_fterm ? ' · ' . esc_html( $kmb_fterm->name ) : ''; ?></span>
					<h2 class="kmb-featured__title"><a class="kmb-stretched" href="<?php echo esc_url( get_permalink( $kmb_feat_id ) ); ?>"><?php echo esc_html( get_the_title( $kmb_feat_id ) ); ?></a></h2>
					<p class="kmb-featured__excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $kmb_fex ), 34, '…' ) ); ?></p>
					<div class="kmb-meta">
						<time datetime="<?php echo esc_attr( get_the_date( 'c', $kmb_feat ) ); ?>"><?php echo esc_html( get_the_date( '', $kmb_feat ) ); ?></time>
						<?php if ( kmb_opt( 'show_reading' ) ) : ?>
							<span class="kmb-meta__sep" aria-hidden="true">·</span>
							<span><?php echo (int) kmb_reading_time( $kmb_feat_id ); ?> min de lecture</span>
						<?php endif; ?>
					</div>
					<span class="kmb-btn kmb-btn--solid" aria-hidden="true">Lire l’article <?php echo kmblog_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</div>
			</article>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="kmb-grid kmb-cols-3">
				<?php
				while ( have_posts() ) {
					the_post();
					kmb_get_template( 'card', array( 'kmb_heading' => 'h2' ) );
				}
				?>
			</div>
			<?php
			the_posts_pagination( array(
				'mid_size'           => 1,
				'prev_text'          => kmblog_icon( 'back' ) . '<span class="screen-reader-text">Articles plus récents</span>',
				'next_text'          => kmblog_icon( 'arrow' ) . '<span class="screen-reader-text">Articles plus anciens</span>',
				'screen_reader_text' => 'Pagination du blog',
				'aria_label'         => 'Pagination du blog',
				'class'              => 'kmb-pagination',
			) );
			?>
		<?php elseif ( ! $kmb_feat_id ) : ?>
			<div class="kmb-empty">
				<p>Aucun article pour le moment. Revenez bientôt.</p>
				<?php if ( $kmb_term ) : ?>
					<a class="kmb-btn" href="<?php echo esc_url( $kmb_blog ); ?>">Voir tous les articles</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
