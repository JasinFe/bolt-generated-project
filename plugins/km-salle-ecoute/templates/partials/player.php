<?php
/**
 * Partial : Lecteur inline pour shortcode [km_salle_ecoute_player id="X"]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// $post_id doit être défini par la classe appelante
if ( empty( $post_id ) ) return;

$I = 'KM_Salle_Ecoute_Icons';
KM_Salle_Ecoute_Player::enqueue_base();

$has_access = KM_Salle_Ecoute_Access_Control::has_access( $post_id );

if ( ! $has_access ) {
    $expired = KM_Salle_Ecoute_Access_Control::is_expired( $post_id );
    echo '<div class="km-scope km-expired-message km-inline-locked">'
        . '<div class="km-expired-icon">' . $I::svg( $expired ? 'clock' : 'lock', 26 ) . '</div>'
        . '<p>' . esc_html( $expired
            ? __( 'Cette session d\'écoute a expiré.', 'km-salle-ecoute' )
            : __( 'Accès restreint à cette session.', 'km-salle-ecoute' ) ) . '</p>';
    if ( ! $expired ) {
        echo '<a class="km-btn km-btn-primary" href="' . esc_url( get_permalink( $post_id ) ) . '">'
            . $I::svg( 'key', 16 ) . esc_html__( 'Accéder à la session', 'km-salle-ecoute' ) . '</a>';
    }
    echo '</div>';
    return;
}

$pistes = KM_Salle_Ecoute_ACF_Helper::get_pistes( $post_id );
if ( ! $pistes ) {
    echo '<p class="km-scope km-inline-empty">' . esc_html__( 'Aucune piste disponible.', 'km-salle-ecoute' ) . '</p>';
    return;
}

KM_Salle_Ecoute_Player::enqueue_inline_player();

$cover       = get_field( 'km_cover_art', $post_id );
$artiste_nom = KM_Salle_Ecoute_ACF_Helper::artiste_nom( $post_id );
$dl_autorise = KM_Salle_Ecoute_Access_Control::download_allowed( $post_id );
$cover_src   = is_array( $cover ) ? ( $cover['sizes']['thumbnail'] ?? $cover['url'] ?? '' ) : '';

$type_labels = [
    'demo'          => __( 'Démo', 'km-salle-ecoute' ),
    'produit_fini'  => __( 'Produit Fini', 'km-salle-ecoute' ),
    'instrumental'  => __( 'Instrumental', 'km-salle-ecoute' ),
    'remix'         => __( 'Remix', 'km-salle-ecoute' ),
];
?>
<div class="km-inline-player km-scope" data-player="<?php echo esc_attr( $post_id ); ?>">
    <div class="km-inline-header">
        <?php if ( $cover_src ) : ?>
        <img src="<?php echo esc_url( $cover_src ); ?>" alt="" class="km-inline-cover" loading="lazy" />
        <?php endif; ?>
        <div>
            <p class="km-inline-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></p>
            <?php if ( $artiste_nom ) : ?><p class="km-inline-artist"><?php echo esc_html( $artiste_nom ); ?></p><?php endif; ?>
        </div>
    </div>

    <div class="km-tracks-list">
        <?php foreach ( $pistes as $index => $piste ) :
            $audio_url   = $piste['url'];
            $piste_titre = $piste['titre'];
            $piste_type  = $piste['type'];
            $piste_duree = $piste['duree'];
            $track_id    = 'inline-' . $post_id . '-' . $index;
        ?>
        <div class="km-track-card"<?php if ( $audio_url ) : ?> data-audio-url="<?php echo esc_url( $audio_url ); ?>"<?php endif; ?> data-track-id="<?php echo esc_attr( $track_id ); ?>">
            <div class="km-track-header">
                <div class="km-track-num"><?php echo esc_html( $index + 1 ); ?></div>
                <div class="km-track-info">
                    <p class="km-track-name"><?php echo esc_html( $piste_titre ); ?></p>
                    <span class="km-tr-badge km-b-<?php echo esc_attr( $piste_type ); ?>"><?php echo esc_html( $type_labels[ $piste_type ] ?? $piste_type ); ?></span>
                </div>
                <?php if ( $piste_duree ) : ?><div class="km-track-duration"><?php echo esc_html( $piste_duree ); ?></div><?php endif; ?>
            </div>
            <?php if ( $audio_url ) : ?>
            <div class="km-player-controls">
                <button type="button" class="km-b km-play-btn" aria-pressed="false" aria-label="<?php echo esc_attr( sprintf( __( 'Lire %s', 'km-salle-ecoute' ), $piste_titre ) ); ?>">
                    <span class="km-play-icon"><?php echo $I::svg( 'play', 18 ); ?></span><span class="km-pause-icon"><?php echo $I::svg( 'pause', 18 ); ?></span>
                </button>
                <div class="km-waveform-wrapper">
                    <div class="km-waveform" id="waveform-<?php echo esc_attr( $track_id ); ?>" tabindex="-1" role="slider" aria-label="<?php esc_attr_e( 'Position de lecture', 'km-salle-ecoute' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                    <span class="km-time-display" data-total="<?php echo esc_attr( $piste_duree ); ?>">0:00 / <?php echo esc_html( $piste_duree ?: '—' ); ?></span>
                </div>
                <?php if ( $dl_autorise ) : ?>
                <a href="<?php echo esc_url( KM_Salle_Ecoute_Access_Control::get_download_url( $post_id, $index ) ); ?>" class="km-b km-download-btn"
                   title="<?php esc_attr_e( 'Télécharger', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Télécharger', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'download', 15 ); ?></a>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <p class="km-track-noaudio"><?php esc_html_e( 'Aucun fichier audio pour cette piste.', 'km-salle-ecoute' ); ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
