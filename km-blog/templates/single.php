<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<main id="main-content" class="kmblog kmblog-single kmblog--<?php echo esc_attr( kmblog_mode() ); ?>">
<?php
while ( have_posts() ) :
	the_post();
	$kmb_id    = get_the_ID();
	$kmb_sub   = get_post_meta( $kmb_id, '_kmb_subtitle', true );
	$kmb_term  = kmblog_primary_term( $kmb_id );
	$kmb_tags  = get_the_terms( $kmb_id, 'km_blog_tag' );
	$kmb_link  = get_permalink();
	$kmb_plain = kmblog_plain_title();
	$kmb_url   = rawurlencode( $kmb_link );
	$kmb_blog  = get_post_type_archive_link( 'km_blog' );
	$kmb_upd   = get_the_modified_date( 'U' ) - get_the_date( 'U' ) > DAY_IN_SECONDS;
	?>
	<?php if ( kmblog_opt( 'show_progress' ) ) : ?>
		<div class="kmblog-progress" aria-hidden="true"><span class="kmblog-progress__bar"></span></div>
	<?php endif; ?>

	<article class="kmblog-article">
		<header class="kmblog-article__head">
			<nav class="kmblog-crumbs" aria-label="Fil d’Ariane">
				<a href="<?php echo esc_url( $kmb_blog ); ?>"><?php echo esc_html( kmblog_opt( 'title' ) ); ?></a>
				<?php if ( $kmb_term ) : ?>
					<span aria-hidden="true">/</span>
					<a class="kmblog-crumbs__term" href="<?php echo esc_url( get_term_link( $kmb_term ) ); ?>"><?php echo esc_html( $kmb_term->name ); ?></a>
				<?php endif; ?>
			</nav>
			<h1 class="kmblog-article__title"><?php the_title(); ?></h1>
			<?php if ( $kmb_sub ) : ?>
				<p class="kmblog-article__sub"><?php echo esc_html( $kmb_sub ); ?></p>
			<?php endif; ?>
			<div class="kmblog-byline">
				<?php if ( kmblog_opt( 'show_author' ) ) : ?>
					<?php echo get_avatar( get_the_author_meta( 'ID' ), 40, '', '', array( 'class' => 'kmblog-byline__img' ) ); ?>
					<span class="kmblog-byline__name"><?php echo esc_html( get_the_author() ); ?></span>
					<span class="kmblog-meta__sep" aria-hidden="true">·</span>
				<?php endif; ?>
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				<?php if ( kmblog_opt( 'show_reading' ) ) : ?>
					<span class="kmblog-meta__sep" aria-hidden="true">·</span>
					<span><?php echo (int) kmblog_reading_time(); ?> min de lecture</span>
				<?php endif; ?>
				<?php if ( $kmb_upd ) : ?>
					<span class="kmblog-meta__sep" aria-hidden="true">·</span>
					<span>Mis à jour le <time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo esc_html( get_the_modified_date() ); ?></time></span>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( has_post_thumbnail() ) : ?>
			<figure class="kmblog-article__cover">
				<?php
				the_post_thumbnail( 'full', array(
					'loading'       => 'eager',
					'fetchpriority' => 'high',
					'sizes'         => '(max-width: 860px) 100vw, 840px',
				) );
				$kmb_cap = get_the_post_thumbnail_caption();
				if ( $kmb_cap ) {
					echo '<figcaption>' . wp_kses_post( $kmb_cap ) . '</figcaption>';
				}
				?>
			</figure>
		<?php endif; ?>

		<?php if ( kmblog_opt( 'show_toc' ) ) : ?>
			<nav class="kmblog-toc" aria-labelledby="kmblog-toc-title" hidden>
				<p class="kmblog-toc__title" id="kmblog-toc-title">Sommaire</p>
				<ol class="kmblog-toc__list"></ol>
			</nav>
		<?php endif; ?>

		<div class="kmblog-content"><?php the_content(); ?></div>

		<?php
		wp_link_pages( array(
			'before' => '<nav class="kmblog-pagelinks" aria-label="Pages de l’article"><span>Pages :</span>',
			'after'  => '</nav>',
		) );
		?>

		<?php if ( $kmb_tags && ! is_wp_error( $kmb_tags ) ) : ?>
			<ul class="kmblog-tags" aria-label="Sujets">
				<?php foreach ( $kmb_tags as $kmb_tag ) : ?>
					<li><a href="<?php echo esc_url( get_term_link( $kmb_tag ) ); ?>">#<?php echo esc_html( $kmb_tag->name ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( kmblog_opt( 'show_share' ) ) : ?>
			<div class="kmblog-share">
				<span class="kmblog-share__label" id="kmblog-share-label">Partager</span>
				<div class="kmblog-share__list" role="group" aria-labelledby="kmblog-share-label">
					<button type="button" class="kmblog-share__btn kmblog-share__native" data-kmblog-share data-title="<?php echo esc_attr( $kmb_plain ); ?>" data-url="<?php echo esc_url( $kmb_link ); ?>" hidden><?php echo kmblog_icon( 'share' ); // phpcs:ignore ?><span>Partager</span></button>
					<a class="kmblog-share__btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $kmb_plain . ' — ' . $kmb_link ) ); ?>"><?php echo kmblog_icon( 'whatsapp' ); // phpcs:ignore ?><span>WhatsApp</span><span class="screen-reader-text"> (nouvel onglet)</span></a>
					<a class="kmblog-share__btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . $kmb_url ); ?>"><?php echo kmblog_icon( 'facebook' ); // phpcs:ignore ?><span>Facebook</span><span class="screen-reader-text"> (nouvel onglet)</span></a>
					<a class="kmblog-share__btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( 'https://x.com/intent/post?text=' . rawurlencode( $kmb_plain ) . '&url=' . $kmb_url ); ?>"><?php echo kmblog_icon( 'x' ); // phpcs:ignore ?><span>X</span><span class="screen-reader-text"> (nouvel onglet)</span></a>
					<button type="button" class="kmblog-share__btn" data-kmblog-copy="<?php echo esc_url( $kmb_link ); ?>"><?php echo kmblog_icon( 'link' ); // phpcs:ignore ?><span data-kmblog-copy-label>Copier le lien</span></button>
				</div>
				<span class="screen-reader-text" role="status" aria-live="polite" data-kmblog-live></span>
			</div>
		<?php endif; ?>

		<?php if ( kmblog_opt( 'show_author' ) ) : ?>
			<aside class="kmblog-author" aria-label="À propos de l’auteur">
				<?php echo get_avatar( get_the_author_meta( 'ID' ), 72, '', '', array( 'class' => 'kmblog-author__img' ) ); ?>
				<div>
					<span class="kmblog-author__kicker">Écrit par</span>
					<strong class="kmblog-author__name"><?php echo esc_html( get_the_author() ); ?></strong>
					<?php if ( get_the_author_meta( 'description' ) ) : ?>
						<p><?php echo esc_html( get_the_author_meta( 'description' ) ); ?></p>
					<?php endif; ?>
				</div>
			</aside>
		<?php endif; ?>

		<?php kmblog_get_template( 'cta' ); ?>
	</article>

	<?php
	if ( kmblog_opt( 'show_related' ) ) :
		$kmb_related = kmblog_related_posts( $kmb_id, 3 );
		if ( $kmb_related->have_posts() ) :
			?>
			<section class="kmblog-wrap kmblog-related" aria-labelledby="kmblog-related-title">
				<div class="kmblog-related__head">
					<h2 class="kmblog-section-title" id="kmblog-related-title">À lire aussi</h2>
					<a class="kmblog-link-arrow" href="<?php echo esc_url( $kmb_blog ); ?>">Tous les articles <?php echo kmblog_icon( 'arrow' ); // phpcs:ignore ?></a>
				</div>
				<div class="kmblog-grid kmblog-cols-3">
					<?php
					while ( $kmb_related->have_posts() ) {
						$kmb_related->the_post();
						kmblog_get_template( 'card', array( 'kmb_heading' => 'h3' ) );
					}
					wp_reset_postdata();
					?>
				</div>
			</section>
			<?php
		endif;
	endif;
	?>

	<p class="kmblog-back"><a href="<?php echo esc_url( $kmb_blog ); ?>"><?php echo kmblog_icon( 'back' ); // phpcs:ignore ?> Retour au blog</a></p>
<?php endwhile; ?>
</main>
<?php
get_footer();
