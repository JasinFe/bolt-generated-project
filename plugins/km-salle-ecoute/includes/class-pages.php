<?php
/**
 * Création automatique des pages à l'activation du plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_Pages {

    /**
     * Pages à créer lors de l'activation
     */
    private static array $pages = [
        [
            'title'   => 'KM Salle d’Écoute – Catalogue',
            'slug'    => 'km-salle-ecoute-catalogue',
            'content' => '[km_salle_ecoute_catalogue]',
            'template'=> 'km-salle-ecoute-catalogue.php',
            'option'  => 'kse_page_catalogue',
        ],
        [
            'title'   => 'Lien Expiré',
            'slug'    => 'ecoute-expiree',
            'content' => '[km_salle_ecoute_expired]',
            'option'  => 'kse_page_expired',
        ],
    ];

    /**
     * URL de la page « Lien expiré » (page créée à l'activation, sinon repli sur le slug).
     */
    public static function expired_url(): string {
        $id = (int) get_option( 'kse_page_expired' );
        if ( $id && 'publish' === get_post_status( $id ) ) {
            $url = get_permalink( $id );
            if ( $url ) return $url;
        }
        return home_url( '/ecoute-expiree/' );
    }

    /**
     * URL de la page catalogue.
     */
    public static function catalogue_url(): string {
        $id = (int) get_option( 'kse_page_catalogue' );
        if ( $id && 'publish' === get_post_status( $id ) ) {
            $url = get_permalink( $id );
            if ( $url ) return $url;
        }
        return home_url( '/km-salle-ecoute-catalogue/' );
    }

    /**
     * Corrige les titres de pages créés par les versions précédentes
     * (antislash littéral : « KM Salle d\'Écoute – Catalogue »).
     */
    public static function fix_titles(): void {
        foreach ( self::$pages as $data ) {
            $id = (int) get_option( $data['option'] );
            if ( ! $id ) continue;
            $post = get_post( $id );
            if ( $post && false !== strpos( $post->post_title, '\\' ) ) {
                wp_update_post( [ 'ID' => $id, 'post_title' => stripslashes( $post->post_title ) ] );
            }
        }
    }

    public static function create_pages(): void {
        foreach ( self::$pages as $page_data ) {
            self::create_page( $page_data );
        }
    }

    private static function create_page( array $data ): void {
        $option_key = $data['option'];
        $existing   = get_option( $option_key );

        // Si la page existe déjà et est valide, on skip
        if ( $existing && get_post( $existing ) ) return;

        // Cherche par slug
        $page = get_page_by_path( $data['slug'] );
        if ( $page ) {
            update_option( $option_key, $page->ID );
            return;
        }

        $args = [
            'post_title'    => $data['title'],
            'post_name'     => $data['slug'],
            'post_content'  => $data['content'],
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => get_current_user_id() ?: 1,
        ];

        $page_id = wp_insert_post( $args );

        if ( ! is_wp_error( $page_id ) ) {
            update_option( $option_key, $page_id );

            // Assigner le template de page si défini
            if ( ! empty( $data['template'] ) ) {
                update_post_meta( $page_id, '_wp_page_template', $data['template'] );
            }
        }
    }
}
