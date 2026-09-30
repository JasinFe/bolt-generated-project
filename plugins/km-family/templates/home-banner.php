<?php
/**
 * Bannière promotionnelle KM Family — pour la page d'accueil, via [kmfamily_home_banner]
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<section class="kmfamily-home-banner">
    <div class="kmfamily-home-banner__glow"></div>

    <div class="kmfamily-home-banner__inner">
        <span class="kmfamily-home-banner__eyebrow">KM FAMILY — URBAN GOSPEL</span>

        <h2 class="kmfamily-home-banner__title"><?php echo wp_kses_post( $atts['titre'] ); ?></h2>

        <p class="kmfamily-home-banner__text"><?php echo esc_html( $atts['texte'] ); ?></p>

        <?php if ( $artistes ) : ?>
        <div class="kmfamily-home-banner__avatars">
            <?php foreach ( $artistes as $artiste ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'artiste_id', $artiste->ID, $cta_url ) ); ?>"
                   class="kmfamily-home-banner__avatar"
                   title="<?php echo esc_attr( get_the_title( $artiste ) ); ?>">
                    <?php if ( has_post_thumbnail( $artiste ) ) : ?>
                        <?php echo get_the_post_thumbnail( $artiste, 'thumbnail' ); ?>
                    <?php else : ?>
                        <span class="kmfamily-home-banner__avatar-fallback"><?php echo esc_html( mb_substr( get_the_title( $artiste ), 0, 1 ) ); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if ( $total_artistes > count( $artistes ) ) : ?>
                <span class="kmfamily-home-banner__avatar kmfamily-home-banner__avatar--more">+<?php echo (int) ( $total_artistes - count( $artistes ) ); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ( $membres_actifs > 0 ) : ?>
        <p class="kmfamily-home-banner__stat">
            <strong><?php echo esc_html( number_format_i18n( $membres_actifs ) ); ?></strong>&nbsp;pilier<?php echo $membres_actifs > 1 ? 's' : ''; ?> soutiennent déjà le mouvement
        </p>
        <?php endif; ?>

        <a href="<?php echo esc_url( $cta_url ); ?>" class="kmfamily-home-banner__cta">
            Devenir un pilier
        </a>
    </div>
</section>
