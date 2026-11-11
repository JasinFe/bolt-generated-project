<?php
/**
 * Shortcodes KM SALLE D'ÉCOUTE
 * [km_salle_ecoute_catalogue]  — liste les sessions
 * [km_salle_ecoute_player id="123"] — lecteur inline
 * [km_salle_ecoute_expired]    — message de lien expiré
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_Shortcodes {

    public static function init(): void {
        add_shortcode( 'km_salle_ecoute_catalogue', [ __CLASS__, 'catalogue' ] );
        add_shortcode( 'km_salle_ecoute_player',    [ __CLASS__, 'player_inline' ] );
        add_shortcode( 'km_salle_ecoute_expired',   [ __CLASS__, 'expired_message' ] );
    }

    /**
     * Catalogue des sessions
     */
    public static function catalogue( $atts = [] ): string {
        $atts = shortcode_atts( [
            'artiste'  => '',
            'type'     => '',
            'genre'    => '',
            'limite'   => 20,
        ], $atts, 'km_salle_ecoute_catalogue' );

        $query_args = [
            'post_type'      => 'kse_session',
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, min( 100, absint( $atts['limite'] ) ?: 20 ) ),
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $tax_query = [];
        if ( $atts['type'] ) {
            $tax_query[] = [
                'taxonomy' => 'kse_type_contenu',
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_title', explode( ',', $atts['type'] ) ),
            ];
        }
        if ( $atts['genre'] ) {
            $tax_query[] = [
                'taxonomy' => 'kse_genre',
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_title', explode( ',', $atts['genre'] ) ),
            ];
        }
        if ( count( $tax_query ) > 1 ) $tax_query['relation'] = 'AND';
        if ( $tax_query ) $query_args['tax_query'] = $tax_query;

        // Filtre par artiste : ID du CPT "nos-artistes", ou nom. Le champ ACF stocke
        // un ID : un nom est donc d'abord résolu en ID, puis recherché aussi dans
        // « Artiste invité / externe » (auparavant un LIKE sur l'ID ne trouvait rien).
        $meta_query = [];
        $artiste    = trim( (string) $atts['artiste'] );
        if ( '' !== $artiste ) {
            if ( is_numeric( $artiste ) ) {
                $meta_query[] = [ 'key' => 'km_artiste_lie', 'value' => (int) $artiste, 'compare' => '=' ];
            } else {
                $nom   = sanitize_text_field( $artiste );
                $ids   = get_posts( [
                    'post_type'      => 'nos-artistes',
                    'title'          => $nom,
                    'posts_per_page' => 5,
                    'fields'         => 'ids',
                    'post_status'    => 'publish',
                ] );
                $or = [ 'relation' => 'OR', [ 'key' => 'km_artiste_externe', 'value' => $nom, 'compare' => 'LIKE' ] ];
                if ( $ids ) $or[] = [ 'key' => 'km_artiste_lie', 'value' => array_map( 'intval', $ids ), 'compare' => 'IN' ];
                $meta_query[] = $or;
            }
        }

        // N'affiche que les sessions en accès public aux visiteurs non-éditeurs
        $meta_query = array_merge( $meta_query, KM_Salle_Ecoute_ACF_Helper::catalogue_meta_query() );
        if ( count( $meta_query ) > 1 ) $meta_query['relation'] = 'AND';
        if ( $meta_query ) $query_args['meta_query'] = $meta_query;

        $sessions = new WP_Query( $query_args );

        KM_Salle_Ecoute_Player::enqueue_base();
        wp_enqueue_script( 'km-salle-ecoute-player' );   // recherche + filtres

        ob_start();
        include KM_SALLE_ECOUTE_TEMPLATES . 'catalogue.php';
        return ob_get_clean();
    }

    /**
     * Lecteur inline via shortcode
     */
    public static function player_inline( $atts = [] ): string {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'km_salle_ecoute_player' );
        $post_id = absint( $atts['id'] );
        if ( ! $post_id || 'kse_session' !== get_post_type( $post_id ) ) return '';
        if ( 'publish' !== get_post_status( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) return '';

        ob_start();
        include KM_SALLE_ECOUTE_TEMPLATES . 'partials/player.php';
        return ob_get_clean();
    }

    /**
     * Message lien expiré
     */
    public static function expired_message( $atts = [] ): string {
        KM_Salle_Ecoute_Player::enqueue_base();
        $email = get_option( 'kse_label_email', '' );
        $cta   = $email
            ? '<a href="mailto:' . esc_attr( $email ) . '" class="km-btn km-btn-primary">' . esc_html__( 'Contacter le Label', 'km-salle-ecoute' ) . '</a>'
            : '';

        return '<div class="km-expired-message km-scope">
            <div class="km-expired-icon">' . KM_Salle_Ecoute_Icons::svg( 'clock', 30 ) . '</div>
            <h2>' . esc_html__( 'Lien Expiré', 'km-salle-ecoute' ) . '</h2>
            <p>' . esc_html__( 'Ce lien d\'écoute a expiré. Veuillez contacter le Label pour obtenir un nouvel accès.', 'km-salle-ecoute' ) . '</p>
            ' . $cta . '
        </div>';
    }
}
