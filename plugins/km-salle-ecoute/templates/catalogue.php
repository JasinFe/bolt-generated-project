<?php
/**
 * KM SALLE D'ÉCOUTE v1.7 — Catalogue des sessions
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$label = KM_Salle_Ecoute_ACF_Helper::label_nom();

if ( ! isset( $sessions ) ) {
    $sessions = new WP_Query([
        'post_type'      => 'kse_session',
        'post_status'    => 'publish',
        'posts_per_page' => (int) apply_filters( 'kse_catalogue_limit', 60 ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => KM_Salle_Ecoute_ACF_Helper::catalogue_meta_query(),
    ]);
}

// Filtres : uniquement les types/genres présents dans les sessions affichées
// (auparavant, des filtres menaient à « aucun résultat » car ils comptaient aussi
// les sessions protégées, masquées aux visiteurs).
$visible_ids = wp_list_pluck( $sessions->posts, 'ID' );
$type_terms  = $visible_ids ? wp_get_object_terms( $visible_ids, 'kse_type_contenu' ) : [];
$genre_terms = $visible_ids ? wp_get_object_terms( $visible_ids, 'kse_genre' ) : [];
$kse_uniq_terms = static function ( $terms ): array {
    if ( is_wp_error( $terms ) || ! is_array( $terms ) ) return [];
    $out = [];
    foreach ( $terms as $t ) $out[ $t->term_id ] = $t;
    uasort( $out, static fn( $a, $b ) => strcasecmp( $a->name, $b->name ) );
    return array_values( $out );
};
$type_terms  = $kse_uniq_terms( $type_terms );
$genre_terms = $kse_uniq_terms( $genre_terms );
?>
<div class="km-page km-scope">
<?php $I = 'KM_Salle_Ecoute_Icons'; ?>

    <!-- HERO -->
    <div class="km-cat-hero">
        <div class="km-cat-hero-inner">
            <div class="km-label-pill"><?php echo $I::svg( 'headphones', 14 ); ?><?php echo esc_html( $label ); ?></div>
            <h1><?php esc_html_e( 'Sessions d\'Écoute', 'km-salle-ecoute' ); ?></h1>
            <p class="km-cat-sub"><?php esc_html_e( 'Productions exclusives — Démos, Produits Finis & Instrumentaux.', 'km-salle-ecoute' ); ?></p>

            <div class="km-cat-tools">
                <label class="km-search">
                    <?php echo $I::svg( 'search', 16 ); ?>
                    <span class="km-sr"><?php esc_html_e( 'Rechercher une session', 'km-salle-ecoute' ); ?></span>
                    <input type="search" id="km-search" class="km-search-input" placeholder="<?php esc_attr_e( 'Rechercher un titre, un artiste…', 'km-salle-ecoute' ); ?>" autocomplete="off">
                </label>

                <div class="km-filters" id="km-filters" role="group" aria-label="<?php esc_attr_e( 'Filtres', 'km-salle-ecoute' ); ?>">
                    <button type="button" class="km-b km-filter on" data-filter="all" aria-pressed="true"><?php esc_html_e( 'Tout', 'km-salle-ecoute' ); ?></button>
                    <?php foreach ( $type_terms as $t ) : ?>
                    <button type="button" class="km-b km-filter" aria-pressed="false" data-filter="type-<?php echo esc_attr( $t->slug ); ?>">
                        <?php echo $I::svg( $I::for_type( $t->name ), 14 ); ?><?php echo esc_html( $t->name ); ?>
                    </button>
                    <?php endforeach; ?>
                    <?php if ( $genre_terms ) : ?>
                    <span class="km-filter-sep" aria-hidden="true"></span>
                    <?php foreach ( $genre_terms as $g ) : ?>
                    <button type="button" class="km-b km-filter" aria-pressed="false" data-filter="genre-<?php echo esc_attr( $g->slug ); ?>"><?php echo esc_html( $g->name ); ?></button>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- GRILLE -->
    <div class="km-cat-body">
        <p class="km-cat-count-line" id="km-count" aria-live="polite"></p>
        <div class="km-cat-grid" id="km-grid">
            <?php
            $card_i = 0;
            if ( $sessions->have_posts() ) :
                while ( $sessions->have_posts() ) : $sessions->the_post();
                    $card_i++;
                    include KM_SALLE_ECOUTE_TEMPLATES . 'partials/card.php';
                endwhile;
                wp_reset_postdata();
            else : ?>
            <div class="km-empty"><?php echo $I::svg( 'music', 56 ); ?><p><?php esc_html_e( 'Aucune session d\'écoute disponible pour le moment.', 'km-salle-ecoute' ); ?></p></div>
            <?php endif; ?>
        </div>
        <div class="km-empty-filter" id="km-empty-filter"><?php echo $I::svg( 'search', 40 ); ?><p><?php esc_html_e( 'Aucune session ne correspond à votre recherche.', 'km-salle-ecoute' ); ?></p></div>
    </div>

    <div class="km-footer">
        <div class="km-footer-inner">
            <p class="km-footer-brand"><strong><?php echo esc_html( $label ); ?></strong> — <?php esc_html_e( 'Productions protégées par le droit d\'auteur.', 'km-salle-ecoute' ); ?></p>
            <p class="km-footer-copy">© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( $label ); ?></p>
        </div>
    </div>
</div>
