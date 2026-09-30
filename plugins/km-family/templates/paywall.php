<?php
/**
 * Paywall KM Family v2
 * Variables : $post_id, $artiste_id, $palier_requis, $teaser, $artiste_name, $artiste_url, $is_logged_in, $label_palier
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$palier_data = KMFamily_Paliers::get( $palier_requis );
$login_page  = get_option( 'kmfamily_page_login' );
// « Rejoindre » ramène sur le soutien de l'artiste après connexion ; « J'ai déjà un compte »
// ramène sur le contenu que la personne voulait voir.
$join_url    = ! empty( $artiste_id ) ? KMFamily_Direct_Link::get_login_url( KMFamily_Direct_Link::get_support_url( $artiste_id, array( 'palier' => $palier_requis ) ) ) : ( $login_page ? get_permalink( $login_page ) : wp_login_url( get_permalink( $post_id ) ) );
$login_url   = KMFamily_Direct_Link::get_login_url( get_permalink( $post_id ) );
?>
<div class="kmfamily-paywall">
    <?php if ( $teaser ) : ?>
        <div class="kmfamily-teaser">
            <div class="kmfamily-teaser__label">
                <span class="kmfamily-teaser__dot"></span>
                Aperçu
            </div>
            <?php echo wp_kses_post( wpautop( $teaser ) ); ?>
        </div>
    <?php endif; ?>

    <div class="kmfamily-paywall-box">
        <div class="kmfamily-paywall-box__inner">
            <div class="kmfamily-paywall-box__icon">
                <span class="kmfamily-paywall-box__icon-bg" style="background: <?php echo esc_attr( $palier_data['gradient'] ?? 'linear-gradient(135deg, #d4af37, #b8941f)' ); ?>;">
                    <?php echo esc_html( $palier_data['icon'] ?? '🔒' ); ?>
                </span>
            </div>

            <div class="kmfamily-paywall-box__eyebrow">
                CONTENU RÉSERVÉ AUX MEMBRES
            </div>

            <h3 class="kmfamily-paywall-box__title">
                Palier <span style="color: <?php echo esc_attr( $palier_data['color'] ?? '#d4af37' ); ?>;"><?php echo esc_html( $label_palier ); ?></span>&nbsp;ou supérieur
            </h3>

            <p class="kmfamily-paywall-box__description">
                Rejoignez la <strong>KM&nbsp;FAMILY</strong> de&nbsp;<strong><?php echo esc_html( $artiste_name ); ?></strong>&nbsp;et accédez à ce contenu<?php if ( $palier_data && ! empty( $palier_data['statut'] ) ) : ?>&nbsp;en tant que&nbsp;<strong><em><?php echo esc_html( $palier_data['statut'] ); ?></em></strong><?php endif; ?>.
            </p>

            <div class="kmfamily-paywall-box__emotional">
                <?php echo esc_html( KMFamily_Notifications::get_emotional_message() ); ?>
            </div>

            <div class="kmfamily-paywall-box__actions">
                <?php if ( $is_logged_in ) : ?>
                    <a href="<?php echo esc_url( $artiste_url . '#paliers' ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--lg">
                        Devenir <?php echo esc_html( $label_palier ); ?> →
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( $join_url ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--lg">
                        Rejoindre la KM Family →
                    </a>
                    <a href="<?php echo esc_url( $login_url ); ?>" class="kmfamily-btn kmfamily-btn-ghost">
                        J'ai déjà un compte
                    </a>
                <?php endif; ?>
            </div>

            <div class="kmfamily-paywall-box__benefits">
                <p class="kmfamily-paywall-box__benefits-title">En devenant membre, vous bénéficiez de :</p>
                <ul>
                    <?php if ( $palier_data && ! empty( $palier_data['benefits'] ) ) : ?>
                        <?php foreach ( $palier_data['benefits'] as $benefit ) : ?>
                            <li><?php echo esc_html( $benefit ); ?></li>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <li>Tous les contenus exclusifs du palier</li>
                        <li>Coulisses, démos et lives en avant-première</li>
                        <li>Contact privilégié avec l'artiste</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
