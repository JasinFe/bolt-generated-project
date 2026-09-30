<?php
/**
 * Archive contenus exclusifs v2
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>

<div class="kmfamily-archive-header">
    <div class="kmfamily-container">
        <span class="kmfamily-archive-header__eyebrow">KM FAMILY — EXCLUSIVITÉS</span>
        <h1>Contenus Exclusifs Urban Gospel</h1>
        <p class="kmfamily-archive-header__subtitle">
            Les exclusivités réservées aux membres de la famille.
        </p>
    </div>
</div>

<div class="kmfamily-container">
    <div class="kmfamily-filters">
        <?php
        $artistes = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <select id="kmfamily-filter-artiste" class="kmfamily-filter" data-filter="artiste">
            <option value="">Tous les artistes</option>
            <?php foreach ( $artistes as $artiste ) : ?>
                <option value="<?php echo esc_attr( $artiste->ID ); ?>"><?php echo esc_html( $artiste->post_title ); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="kmfamily-filter-type" class="kmfamily-filter" data-filter="type">
            <option value="">Tous les types</option>
            <?php
            $types = get_terms( array( 'taxonomy' => 'type_contenu', 'hide_empty' => false ) );
            if ( ! is_wp_error( $types ) ) {
                foreach ( $types as $type ) {
                    printf( '<option value="%s">%s</option>', esc_attr( $type->slug ), esc_html( $type->name ) );
                }
            }
            ?>
        </select>

        <select id="kmfamily-filter-palier" class="kmfamily-filter" data-filter="palier">
            <option value="">Tous les paliers</option>
            <?php foreach ( KMFamily_Paliers::get_all() as $slug => $p ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $p['icon'] . ' ' . $p['nom'] ); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="kmfamily-exclusive-grid">
        <?php if ( have_posts() ) :
            while ( have_posts() ) : the_post();
                include KMFamily_Access::locate_template( 'card-contenu-exclusif.php' );
            endwhile;
        else : ?>
            <div class="kmfamily-empty-state kmfamily-empty-state--small">
                <p>Aucun contenu exclusif disponible pour le moment.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="kmfamily-pagination">
        <?php
        the_posts_pagination( array(
            'mid_size'  => 2,
            'prev_text' => __( '← Précédent', 'km-family' ),
            'next_text' => __( 'Suivant →', 'km-family' ),
        ) );
        ?>
    </div>
</div>

<?php get_footer(); ?>
