<?php
/**
 * Carte d'article. Toute la carte est cliquable (lien étiré sur le titre),
 * avec un seul lien pour les lecteurs d'écran et le clavier.
 *
 * Variables : $kmb_heading (h2|h3, défaut h2).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kmb_tag  = ( isset( $kmb_heading ) && in_array( $kmb_heading, array( 'h2', 'h3', 'h4' ), true ) ) ? $kmb_heading : 'h2';
$kmb_term = kmblog_primary_term( get_the_ID() );
$kmb_ex   = wp_strip_all_tags( get_the_excerpt() );
?>
<article class="kmb-card">
	<div class="kmb-card__media">
		<?php
		if ( has_post_thumbnail() ) {
			the_post_thumbnail( 'medium_large', array(
				'loading' => 'lazy',
				'alt'     => '',
				'sizes'   => '(max-width: 620px) 100vw, (max-width: 980px) 50vw, 380px',
			) );
		} else {
			echo '<span class="kmb-card__ph" aria-hidden="true"><span>KM</span></span>';
		}
		?>
		<?php if ( $kmb_term ) : ?>
			<span class="kmb-badge kmb-badge--float"><?php echo esc_html( $kmb_term->name ); ?></span>
		<?php endif; ?>
	</div>
	<div class="kmb-card__body">
		<<?php echo esc_html( $kmb_tag ); ?> class="kmb-card__title"><a class="kmb-stretched" href="<?php the_permalink(); ?>"><?php the_title(); ?></a></<?php echo esc_html( $kmb_tag ); ?>>
		<p class="kmb-card__excerpt"><?php echo esc_html( wp_trim_words( $kmb_ex, 22, '…' ) ); ?></p>
		<div class="kmb-meta">
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			<?php if ( kmb_opt( 'show_reading' ) ) : ?>
				<span class="kmb-meta__sep" aria-hidden="true">·</span>
				<span><?php echo (int) kmb_reading_time(); ?> min de lecture</span>
			<?php endif; ?>
			<span class="kmb-card__go" aria-hidden="true"><?php echo kmblog_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		</div>
	</div>
</article>
