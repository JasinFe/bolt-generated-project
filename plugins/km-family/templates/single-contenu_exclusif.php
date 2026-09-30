<?php
/**
 * Template single-contenu_exclusif v2 — avec streaming sécurisé
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>

<div class="kmfamily-container kmfamily-single">
    <?php while ( have_posts() ) : the_post();
        $artiste_obj   = get_field( 'artiste_lie' );
        $palier        = get_field( 'palier_requis' );
        $type_media    = get_field( 'type_media' );
        $url_externe   = get_field( 'url_video_externe' );
        $duree         = get_field( 'duree_estimee' );
        $artiste_id    = $artiste_obj ? ( is_object( $artiste_obj ) ? $artiste_obj->ID : $artiste_obj ) : 0;
        $has_access    = KMFamily_Access::can_view( get_current_user_id(), get_the_ID() );
        $palier_data   = KMFamily_Paliers::get( $palier );

        // URL sécurisée pour le média (token signé)
        $secure_url = '';
        if ( $has_access ) {
            $secure_url = KMFamily_Media_Protector::get_secure_media_url( get_the_ID() );
        }
    ?>
        <article class="kmfamily-single__article">
            <header class="kmfamily-single__header">
                <?php if ( $artiste_obj ) : ?>
                    <a href="<?php echo esc_url( get_permalink( $artiste_id ) ); ?>" class="kmfamily-single__artiste">
                        <?php if ( has_post_thumbnail( $artiste_id ) ) : ?>
                            <?php echo get_the_post_thumbnail( $artiste_id, 'thumbnail', array( 'class' => 'kmfamily-single__artiste-avatar' ) ); ?>
                        <?php endif; ?>
                        <span><?php echo esc_html( get_the_title( $artiste_id ) ); ?></span>
                    </a>
                <?php endif; ?>

                <h1 class="kmfamily-single__title"><?php the_title(); ?></h1>

                <div class="kmfamily-single__meta">
                    <span class="kmfamily-single__date">📅 <?php echo esc_html( get_the_date() ); ?></span>
                    <?php if ( $duree ) : ?>
                        <span class="kmfamily-single__duration">⏱ <?php echo esc_html( $duree ); ?></span>
                    <?php endif; ?>
                    <?php if ( $palier_data ) : ?>
                        <span class="kmfamily-badge kmfamily-badge--<?php echo esc_attr( $palier ); ?>" style="background: <?php echo esc_attr( $palier_data['gradient'] ); ?>;color:<?php echo in_array( $palier, array( 'argent', 'or', 'platine', 'diamant' ) ) ? '#0a0a0a' : '#fff'; ?>;">
                            <?php echo esc_html( $palier_data['icon'] . ' ' . $palier_data['nom'] ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ( has_post_thumbnail() && ! $has_access ) : ?>
                <div class="kmfamily-single__thumbnail">
                    <?php the_post_thumbnail( 'large' ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $has_access ) : ?>
                <div class="kmfamily-single__media">
                    <?php if ( $type_media === 'video' && $url_externe ) : ?>
                        <div class="kmfamily-video-embed">
                            <?php echo wp_oembed_get( $url_externe ); ?>
                        </div>
                    <?php elseif ( $type_media === 'video' && $secure_url ) : ?>
                        <video controls class="kmfamily-video-player" controlsList="nodownload" oncontextmenu="return false;" playsinline>
                            <source src="<?php echo esc_url( $secure_url ); ?>" type="video/mp4">
                            Votre navigateur ne supporte pas la lecture vidéo.
                        </video>
                        <p class="kmfamily-single__protected-notice">
                            🔒 Contenu protégé — Lien sécurisé à usage unique
                        </p>
                    <?php elseif ( $type_media === 'audio' && $secure_url ) : ?>
                        <audio controls class="kmfamily-audio-player" controlsList="nodownload" oncontextmenu="return false;">
                            <source src="<?php echo esc_url( $secure_url ); ?>" type="audio/mpeg">
                        </audio>
                        <p class="kmfamily-single__protected-notice">
                            🔒 Contenu protégé — Lien sécurisé à usage unique
                        </p>
                    <?php elseif ( $type_media === 'galerie' && has_post_thumbnail() ) : ?>
                        <?php the_post_thumbnail( 'full' ); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="kmfamily-single__content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</div>

<?php get_footer(); ?>
