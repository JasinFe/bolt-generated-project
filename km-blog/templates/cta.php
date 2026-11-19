<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kmb_nl  = kmb_opt( 'cta_newsletter' );
$kmb_fam = kmb_opt( 'cta_family' );
if ( ! $kmb_nl && ! $kmb_fam ) {
	return;
}
?>
<section class="kmb-cta kmb-cta--<?php echo ( $kmb_nl && $kmb_fam ) ? 'two' : 'one'; ?>" aria-label="Rester en contact">
	<?php if ( $kmb_nl ) : ?>
		<div class="kmb-cta__box">
			<span class="kmb-cta__icon"><?php echo kmblog_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<h2 class="kmb-cta__title">Ne rate aucun article</h2>
			<p>Reçois les nouveaux textes et les coulisses du label directement dans ta boîte mail.</p>
			<a class="kmb-btn kmb-btn--solid" href="<?php echo esc_url( kmb_link( kmb_opt( 'newsletter_url' ) ) ); ?>">S’abonner à la newsletter</a>
		</div>
	<?php endif; ?>
	<?php if ( $kmb_fam ) : ?>
		<div class="kmb-cta__box kmb-cta__box--family">
			<span class="kmb-cta__icon"><?php echo kmblog_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<h2 class="kmb-cta__title">Deviens un pilier de la KM Family</h2>
			<p>Chaque contribution finance la prochaine production, le prochain clip, le prochain live.</p>
			<a class="kmb-btn kmb-btn--solid" href="<?php echo esc_url( kmb_link( kmb_opt( 'family_url' ) ) ); ?>">Rejoindre la KM Family</a>
		</div>
	<?php endif; ?>
</section>
