<?php
/**
 * KM SALLE D'ÉCOUTE v1.7 — Archive taxonomie
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$label    = KM_Salle_Ecoute_ACF_Helper::label_nom();
$queried  = get_queried_object();
$term_name= $queried ? $queried->name : '';
$taxonomy = $queried ? $queried->taxonomy : '';

$sessions = new WP_Query([
    'post_type'      => 'kse_session',
    'post_status'    => 'publish',
    'posts_per_page' => (int) apply_filters( 'kse_catalogue_limit', 60 ),
    'no_found_rows'  => true,
    'tax_query'      => [[
        'taxonomy' => $taxonomy,
        'field'    => 'term_id',
        'terms'    => $queried ? $queried->term_id : 0,
    ]],
    'meta_query'     => KM_Salle_Ecoute_ACF_Helper::catalogue_meta_query(),
]);

KM_Salle_Ecoute_Player::enqueue_base();
get_header();
$I = 'KM_Salle_Ecoute_Icons';
$cat_url = KM_Salle_Ecoute_Pages::catalogue_url();
?>
<div class="km-page km-scope">
    <div class="km-cat-hero">
        <div class="km-cat-hero-inner">
            <div class="km-label-pill"><?php echo $I::svg( 'headphones', 14 ); ?><?php echo esc_html( $label ); ?></div>
            <h1><?php echo esc_html( $term_name ); ?></h1>
            <p class="km-cat-sub">
                <?php echo $queried && $queried->description
                    ? esc_html( $queried->description )
                    : esc_html( sprintf( __( 'Sessions d\'écoute — %s', 'km-salle-ecoute' ), $term_name ) ); ?>
            </p>
            <a href="<?php echo esc_url( $cat_url ); ?>" class="km-back-link"><?php echo $I::svg( 'arrow-left', 16 ); ?><?php esc_html_e( 'Toutes les sessions', 'km-salle-ecoute' ); ?></a>
        </div>
    </div>

    <div class="km-cat-body">
        <div class="km-cat-grid">
            <?php if ( $sessions->have_posts() ) :
                $card_i = 0;
                while ( $sessions->have_posts() ) : $sessions->the_post();
                    $card_i++;
                    include KM_SALLE_ECOUTE_TEMPLATES . 'partials/card.php';
                endwhile; wp_reset_postdata();
            else : ?>
            <div class="km-empty"><?php echo $I::svg( 'music', 56 ); ?><p><?php esc_html_e( 'Aucune session dans cette catégorie.', 'km-salle-ecoute' ); ?></p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="km-footer">
        <div class="km-footer-inner">
            <p class="km-footer-brand"><strong><?php echo esc_html( $label ); ?></strong></p>
            <p class="km-footer-copy">© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( $label ); ?></p>
        </div>
    </div>
</div>
<?php get_footer(); ?>
