<?php
/**
 * Partial : carte de session (catalogue + archives de taxonomie)
 * Variables attendues : $card_i (int) — dans la boucle WP_Query (the_post() appelé)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$pid         = get_the_ID();
$cover       = get_field( 'km_cover_art', $pid );
$acces       = KM_Salle_Ecoute_Access_Control::access_type( $pid );
$pistes      = KM_Salle_Ecoute_ACF_Helper::get_pistes( $pid );
$artiste_nom = KM_Salle_Ecoute_ACF_Helper::artiste_nom( $pid );
if ( ! is_array( $cover ) ) $cover = [];

$type_t  = get_the_terms( $pid, 'kse_type_contenu' ) ?: [];
$genre_t = get_the_terms( $pid, 'kse_genre' ) ?: [];
if ( is_wp_error( $type_t ) )  $type_t  = [];
if ( is_wp_error( $genre_t ) ) $genre_t = [];

$filter_data = '';
foreach ( $type_t  as $t ) $filter_data .= ' type-'  . $t->slug;
foreach ( $genre_t as $g ) $filter_data .= ' genre-' . $g->slug;

$search_blob = remove_accents( mb_strtolower( get_the_title() . ' ' . $artiste_nom ) );

$is_expired = KM_Salle_Ecoute_Access_Control::is_expired( $pid );
$acc        = KM_Salle_Ecoute_Icons::access( (string) $acces );

$first_type = $type_t[0]->name ?? '';
$badge_cls  = [ 'Démo' => 'km-b-demo', 'Produit Fini' => 'km-b-produit_fini',
                'Instrumental' => 'km-b-instrumental', 'Remix' => 'km-b-remix' ][ $first_type ] ?? 'km-b-demo';
$nb         = count( $pistes );
?>
<<?php echo $is_expired ? 'div' : 'a href="' . esc_url( get_permalink() ) . '"'; ?>
   class="km-cat-card<?php echo $is_expired ? ' is-expired' : ''; ?>"
   data-filter="<?php echo esc_attr( trim( $filter_data ) ); ?>"
   data-search="<?php echo esc_attr( $search_blob ); ?>"
   style="--i:<?php echo (int) ( $card_i ?? 1 ); ?>"
   <?php if ( $is_expired ) echo 'aria-disabled="true"'; ?>>

    <div class="km-cat-media">
        <?php if ( $cover ) : ?>
        <img src="<?php echo esc_url( $cover['sizes']['medium'] ?? $cover['url'] ); ?>"
             alt="<?php echo esc_attr( get_the_title() ); ?>" class="km-cat-thumb" loading="lazy">
        <?php else : ?>
        <div class="km-cat-thumb-ph"><?php echo KM_Salle_Ecoute_Icons::svg( 'music', 44 ); ?></div>
        <?php endif; ?>

        <?php if ( $first_type ) : ?>
        <span class="km-tr-badge km-cat-type <?php echo esc_attr( $badge_cls ); ?>"><?php echo esc_html( $first_type ); ?></span>
        <?php endif; ?>

        <?php if ( $is_expired ) : ?>
        <span class="km-cat-flag"><?php echo KM_Salle_Ecoute_Icons::svg( 'clock', 12 ); ?> <?php esc_html_e( 'Expiré', 'km-salle-ecoute' ); ?></span>
        <?php else : ?>
        <span class="km-cat-play" aria-hidden="true"><?php echo KM_Salle_Ecoute_Icons::svg( 'play', 20 ); ?></span>
        <?php endif; ?>
    </div>

    <div class="km-cat-body-card">
        <h3 class="km-cat-title"><?php echo esc_html( get_the_title() ); ?></h3>
        <?php if ( $artiste_nom ) : ?>
        <p class="km-cat-artist"><?php echo esc_html( $artiste_nom ); ?></p>
        <?php endif; ?>
        <div class="km-cat-footer">
            <span class="km-cat-count"><?php echo esc_html( sprintf( _n( '%d piste', '%d pistes', $nb, 'km-salle-ecoute' ), $nb ) ); ?></span>
            <span class="km-cat-lock" title="<?php echo esc_attr( $acc['label'] ); ?>">
                <?php echo KM_Salle_Ecoute_Icons::svg( $acc['icon'], 14 ); ?>
                <span class="screen-reader-text km-sr"><?php echo esc_html( $acc['label'] ); ?></span>
            </span>
        </div>
    </div>
</<?php echo $is_expired ? 'div' : 'a'; ?>>
