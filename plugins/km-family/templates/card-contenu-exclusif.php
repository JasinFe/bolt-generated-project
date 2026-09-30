<?php
/**
 * Carte d'un contenu exclusif — v2
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$artiste_obj     = get_field( 'artiste_lie' );
$palier          = get_field( 'palier_requis' );
$type_media      = get_field( 'type_media' );
$duree           = get_field( 'duree_estimee' );
$user_has_access = false;
$artiste_id      = 0;

if ( $artiste_obj ) {
    $artiste_id      = is_object( $artiste_obj ) ? $artiste_obj->ID : $artiste_obj;
    $user_has_access = KMFamily_Access::can_view( get_current_user_id(), get_the_ID() );
}

$icons = array(
    'video'   => '🎬',
    'audio'   => '🎵',
    'texte'   => '📝',
    'live'    => '🔴',
    'galerie' => '📸',
);
$icon = $icons[ $type_media ] ?? '⭐';
$palier_data = KMFamily_Paliers::get( $palier );
?>
<article class="kmfamily-card kmfamily-card--palier-<?php echo esc_attr( $palier ); ?> <?php echo $user_has_access ? 'is-unlocked' : 'is-locked'; ?>"
         data-artiste="<?php echo esc_attr( $artiste_id ); ?>"
         data-type="<?php echo esc_attr( $type_media ); ?>"
         data-palier="<?php echo esc_attr( $palier ); ?>">

    <a href="<?php the_permalink(); ?>" class="kmfamily-card__media">
        <?php if ( has_post_thumbnail() ) : ?>
            <?php the_post_thumbnail( 'medium_large', array( 'class' => 'kmfamily-card__thumb' ) ); ?>
        <?php else : ?>
            <div class="kmfamily-card__thumb kmfamily-card__thumb--placeholder">
                <span class="kmfamily-card__placeholder-icon"><?php echo $icon; ?></span>
            </div>
        <?php endif; ?>

        <div class="kmfamily-card__overlay">
            <span class="kmfamily-card__type">
                <?php echo $icon; ?> <?php echo esc_html( ucfirst( $type_media ) ); ?>
            </span>

            <?php if ( $duree ) : ?>
                <span class="kmfamily-card__duration"><?php echo esc_html( $duree ); ?></span>
            <?php endif; ?>

            <?php if ( ! $user_has_access && $palier !== 'public' ) : ?>
                <span class="kmfamily-card__lock" <?php if ( $palier_data ) echo 'style="background: ' . esc_attr( $palier_data['gradient'] ) . ';color:' . esc_attr( in_array( $palier, array( 'argent', 'or', 'platine', 'diamant' ) ) ? '#0a0a0a' : '#fff' ) . ';"'; ?>>
                    <?php echo esc_html( $palier_data['icon'] ?? '🔒' ); ?> <?php echo esc_html( $palier_data['nom'] ?? ucfirst( $palier ) ); ?>
                </span>
            <?php elseif ( $palier === 'public' ) : ?>
                <span class="kmfamily-card__public">🌍 Gratuit</span>
            <?php endif; ?>
        </div>
    </a>

    <div class="kmfamily-card__body">
        <?php if ( $artiste_obj && $artiste_id ) : ?>
            <a href="<?php echo esc_url( get_permalink( $artiste_id ) ); ?>" class="kmfamily-card__artiste">
                <?php echo esc_html( get_the_title( $artiste_id ) ); ?>
            </a>
        <?php endif; ?>

        <h2 class="kmfamily-card__title">
            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
        </h2>

        <p class="kmfamily-card__excerpt">
            <?php echo esc_html( wp_trim_words( get_the_excerpt(), 18 ) ); ?>
        </p>

        <div class="kmfamily-card__footer">
            <span class="kmfamily-card__date"><?php echo esc_html( get_the_date() ); ?></span>
            <a href="<?php the_permalink(); ?>" class="kmfamily-card__cta">
                <?php echo $user_has_access ? esc_html__( 'Voir →', 'km-family' ) : esc_html__( 'Débloquer →', 'km-family' ); ?>
            </a>
        </div>
    </div>
</article>
