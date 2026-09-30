<?php
/**
 * Tâches d'installation + nettoyage des doublons
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Install {

    /**
     * Liste des pages KM Family avec leur shortcode et option de stockage.
     */
    public static function get_pages_config() {
        return array(
            'mon-espace-km-family' => array(
                'title'   => __( 'Mon Espace KM Family', 'km-family' ),
                'content' => '[kmfamily_dashboard]',
                'option'  => 'kmfamily_page_dashboard',
            ),
            'mon-profil-km-family' => array(
                'title'   => __( 'Mon Profil', 'km-family' ),
                'content' => '[kmfamily_profile]',
                'option'  => 'kmfamily_page_profile',
            ),
            'rejoindre-km-family' => array(
                'title'   => __( 'Rejoindre la KM Family', 'km-family' ),
                'content' => '[kmfamily_paliers_all]',
                'option'  => 'kmfamily_page_paliers',
            ),
            'espace-membre' => array(
                'title'   => __( 'Espace Membre', 'km-family' ),
                'content' => '[kmfamily_login]',
                'option'  => 'kmfamily_page_login',
            ),
            'paiement-en-cours' => array(
                'title'   => __( 'Paiement en cours', 'km-family' ),
                'content' => '[kmfamily_payment_pending]',
                'option'  => 'kmfamily_page_payment',
            ),
            'paiement-reussi' => array(
                'title'   => __( 'Bienvenue dans la KM Family !', 'km-family' ),
                'content' => '[kmfamily_payment_success]',
                'option'  => 'kmfamily_page_payment_success',
            ),
        );
    }

    /**
     * Créer les pages si manquantes + nettoyer les doublons.
     */
    public static function create_pages() {
        $pages = self::get_pages_config();

        foreach ( $pages as $slug => $page ) {
            $existing_id = get_option( $page['option'] );

            // Si l'option existe et pointe vers une page valide, on ne fait rien
            if ( $existing_id && get_post_status( $existing_id ) ) {
                continue;
            }

            // Chercher une page existante avec ce slug exact
            $page_by_slug = get_page_by_path( $slug );
            if ( $page_by_slug && $page_by_slug->post_status === 'publish' ) {
                update_option( $page['option'], $page_by_slug->ID );
                continue;
            }

            // Chercher une page dont le contenu est exactement le shortcode
            $existing_by_content = get_posts( array(
                'post_type'   => 'page',
                'post_status' => array( 'publish', 'draft', 'private' ),
                's'           => $page['content'],
                'numberposts' => 1,
            ) );
            if ( ! empty( $existing_by_content ) ) {
                foreach ( $existing_by_content as $found ) {
                    if ( trim( $found->post_content ) === $page['content'] ) {
                        update_option( $page['option'], $found->ID );
                        continue 2;
                    }
                }
            }

            // Sinon créer la page
            $page_id = wp_insert_post( array(
                'post_title'     => $page['title'],
                'post_name'      => $slug,
                'post_content'   => $page['content'],
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ) );

            if ( ! is_wp_error( $page_id ) ) {
                update_option( $page['option'], $page_id );
            }
        }

        if ( ! get_option( 'kmfamily_settings' ) ) {
            update_option( 'kmfamily_settings', array(
                'currency'          => 'XOF',
                'payment_gateway'   => 'none',
                'subscription_days' => 30,
                'test_mode'         => 1,
                'brand_color'       => '#d4af37',
            ) );
        }
    }

    /**
     * Nettoyer les pages doublons (même shortcode, slug différent).
     * Méthode appelable manuellement depuis l'admin.
     *
     * @return array Liste des IDs supprimés.
     */
    public static function cleanup_duplicate_pages() {
        $pages    = self::get_pages_config();
        $deleted  = array();

        // Pour chaque shortcode, on garde UNE page (celle référencée dans l'option)
        foreach ( $pages as $slug => $cfg ) {
            $canonical_id = (int) get_option( $cfg['option'] );

            // Trouver toutes les pages dont le contenu est exactement le shortcode
            $matching_pages = get_posts( array(
                'post_type'      => 'page',
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                's'              => $cfg['content'],
            ) );

            foreach ( $matching_pages as $p ) {
                // Le shortcode doit être le contenu principal, pas juste mentionné
                if ( trim( $p->post_content ) !== $cfg['content'] ) continue;

                // Ne pas toucher la page canonique
                if ( $p->ID === $canonical_id ) continue;

                // Supprimer la page doublon (mise en corbeille)
                wp_delete_post( $p->ID, false );
                $deleted[] = $p->ID;
            }
        }

        return $deleted;
    }
}
