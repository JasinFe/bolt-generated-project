<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kmb_nl  = kmblog_opt( 'cta_newsletter' );
$kmb_fam = kmblog_opt( 'cta_family' );
if ( ! $kmb_nl && ! $kmb_fam ) {
	return;
}
?>
<section class="kmblog-cta kmblog-cta--<?php echo ( $kmb_nl && $kmb_fam ) ? 'two' : 'one'; ?>" aria-label="Rester en contact">
	<?php if ( $kmb_nl ) : ?>
		<div class="kmblog-cta__box">
			<span class="kmblog-cta__icon"><?php echo kmblog_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<h2 class="kmblog-cta__title">Ne rate aucun article</h2>
			<p>Reçois les nouveaux textes et les coulisses du label directement dans ta boîte mail.</p>
			<a class="kmblog-btn kmblog-btn--solid" href="<?php echo esc_url( kmblog_link( kmblog_opt( 'newsletter_url' ) ) ); ?>">S’abonner à la newsletter</a>
		</div>
	<?php endif; ?>
	<?php if ( $kmb_fam ) : ?>
		<div class="kmblog-cta__box kmblog-cta__box--family">
			<span class="kmblog-cta__icon"><?php echo kmblog_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<h2 class="kmblog-cta__title">Deviens un pilier de la KM Family</h2>
			<p>Chaque contribution finance la prochaine production, le prochain clip, le prochain live.</p>
			<a class="kmblog-btn kmblog-btn--solid" href="<?php echo esc_url( kmblog_link( kmblog_opt( 'family_url' ) ) ); ?>">Rejoindre la KM Family</a>
		</div>
	<?php endif; ?>
</section>
