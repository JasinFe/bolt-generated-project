<?php
/**
 * Helper : construit un tableau de pistes depuis les champs ACF individuels
 * Compatible ACF Free (pas de Repeater)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_ACF_Helper {

    /**
     * Retourne un tableau de pistes pour un post donné
     * Chaque piste : [ 'titre', 'fichier' (valeur ACF brute), 'url', 'filename', 'mime',
     *                  'type', 'duree', 'num' (numéro ACF 1..MAX) ]
     */
    public static function get_pistes( int $post_id ): array {
        $nb     = (int) ( get_field( 'km_nb_pistes', $post_id ) ?: 1 );
        $nb     = max( 1, min( $nb, KM_Salle_Ecoute_ACF_Fields::MAX_PISTES ) );
        $pistes = [];

        for ( $i = 1; $i <= $nb; $i++ ) {
            $fichier = get_field( "km_piste_{$i}_fichier", $post_id );
            $titre   = trim( (string) get_field( "km_piste_{$i}_titre", $post_id ) );

            // On n'inclut la piste que si elle a au moins un fichier OU un titre
            if ( ! $fichier && '' === $titre ) continue;

            $file = self::normalize_file( $fichier );
            $type = (string) ( get_field( "km_piste_{$i}_type", $post_id ) ?: 'demo' );

            $pistes[] = [
                'titre'    => '' !== $titre ? $titre : sprintf( __( 'Piste %d', 'km-salle-ecoute' ), $i ),
                'fichier'  => $fichier,
                'url'      => $file['url'],
                'filename' => $file['filename'],
                'mime'     => $file['mime'],
                'type'     => sanitize_key( $type ),
                'duree'    => trim( (string) get_field( "km_piste_{$i}_duree", $post_id ) ),
                'num'      => $i,
            ];
        }

        return $pistes;
    }

    /**
     * Normalise un champ fichier ACF quel que soit son format de retour
     * (tableau, ID de pièce jointe ou URL).
     */
    public static function normalize_file( $fichier ): array {
        $out = [ 'url' => '', 'filename' => '', 'mime' => '' ];
        if ( is_array( $fichier ) ) {
            $out['url']      = (string) ( $fichier['url'] ?? '' );
            $out['filename'] = (string) ( $fichier['filename'] ?? '' );
            $out['mime']     = (string) ( $fichier['mime_type'] ?? '' );
        } elseif ( is_numeric( $fichier ) && (int) $fichier > 0 ) {
            $out['url']      = (string) wp_get_attachment_url( (int) $fichier );
            $out['filename'] = basename( (string) get_attached_file( (int) $fichier ) );
            $out['mime']     = (string) get_post_mime_type( (int) $fichier );
        } elseif ( is_string( $fichier ) ) {
            $out['url'] = $fichier;
        }
        if ( $out['url'] && ! $out['mime'] ) {
            $ft = wp_check_filetype( (string) wp_parse_url( $out['url'], PHP_URL_PATH ) );
            $out['mime'] = (string) ( $ft['type'] ?? '' );
        }
        return $out;
    }

    /**
     * Nom d'artiste affiché : artiste lié (CPT « Nos Artistes ») & artiste externe.
     */
    public static function artiste_nom( int $post_id ): string {
        $obj = get_field( 'km_artiste_lie', $post_id );
        if ( is_numeric( $obj ) ) $obj = get_post( (int) $obj );
        $ext = trim( (string) get_field( 'km_artiste_externe', $post_id ) );
        $nom = ( $obj instanceof WP_Post ) ? $obj->post_title : '';
        if ( '' !== $ext ) $nom .= ( $nom ? ' & ' : '' ) . $ext;
        return $nom;
    }

    /** Objet WP_Post de l'artiste lié, ou null. */
    public static function artiste_obj( int $post_id ): ?WP_Post {
        $obj = get_field( 'km_artiste_lie', $post_id );
        if ( is_numeric( $obj ) ) $obj = get_post( (int) $obj );
        return ( $obj instanceof WP_Post ) ? $obj : null;
    }

    /** Convertit « 3:45 » / « 1:02:03 » en secondes (0 si invalide). */
    public static function duration_to_seconds( string $d ): int {
        if ( ! preg_match( '/^\d{1,3}(:\d{1,2}){1,2}$/', trim( $d ) ) ) return 0;
        $sec = 0;
        foreach ( explode( ':', trim( $d ) ) as $part ) $sec = $sec * 60 + (int) $part;
        return $sec;
    }

    /**
     * Retourne le nom du Label, en nettoyant d'éventuels antislashs
     * résiduels (anciens enregistrements affectés par les magic quotes WP).
     */
    public static function label_nom(): string {
        $nom = get_option( 'kse_label_nom', 'KM Label' );
        return is_string( $nom ) ? stripslashes( $nom ) : 'KM Label';
    }

    /**
     * Contrainte meta_query à appliquer aux requêtes de catalogue (grille, archives
     * de taxonomie) pour que les visiteurs non-éditeurs ne voient QUE les sessions
     * en accès "public". Les sessions "mot de passe" / "privé" restent accessibles
     * par lien direct (mot de passe / connexion), mais ne sont plus listées
     * publiquement — leur existence même n'est plus exposée aux visiteurs.
     * Les admins/éditeurs (current_user_can('edit_posts')) voient tout, sans filtre.
     */
    public static function catalogue_meta_query(): array {
        if ( current_user_can( 'edit_posts' ) ) return [];

        return [
            [
                'key'     => 'km_acces_type',
                'value'   => 'public',
                'compare' => '=',
            ],
        ];
    }
}
