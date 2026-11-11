<?php
/**
 * Enregistrement du Custom Post Type "Session d'Écoute"
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_CPT {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_filter( 'post_updated_messages', [ __CLASS__, 'messages' ] );

        // Confidentialité : les sessions (souvent protégées) ne doivent apparaître ni dans
        // la recherche du site, ni dans les sitemaps, ni dans les moteurs de recherche.
        add_filter( 'wp_sitemaps_post_types',  [ __CLASS__, 'sitemap_post_types' ] );
        add_filter( 'wp_sitemaps_taxonomies',  [ __CLASS__, 'sitemap_taxonomies' ] );
        add_filter( 'wpseo_sitemap_exclude_post_type', [ __CLASS__, 'yoast_exclude_post_type' ], 10, 2 );
        add_filter( 'wpseo_sitemap_exclude_taxonomy',  [ __CLASS__, 'yoast_exclude_taxonomy' ], 10, 2 );
        add_filter( 'wp_robots',               [ __CLASS__, 'robots' ] );
    }

    public static function sitemap_post_types( array $types ): array {
        unset( $types['kse_session'] );
        return $types;
    }

    public static function sitemap_taxonomies( array $tax ): array {
        unset( $tax['kse_type_contenu'], $tax['kse_genre'] );
        return $tax;
    }

    public static function yoast_exclude_post_type( $exclude, $post_type ) {
        return 'kse_session' === $post_type ? true : $exclude;
    }

    public static function yoast_exclude_taxonomy( $exclude, $taxonomy ) {
        return in_array( $taxonomy, [ 'kse_type_contenu', 'kse_genre' ], true ) ? true : $exclude;
    }

    public static function robots( array $robots ): array {
        if ( is_singular( 'kse_session' ) || is_tax( [ 'kse_type_contenu', 'kse_genre' ] ) ) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
        }
        return $robots;
    }

    public static function register(): void {

        /* ── CPT : Session d\'Écoute ── */
        $labels = [
            'name'                  => __( 'Sessions d\'Écoute', 'km-salle-ecoute' ),
            'singular_name'         => __( 'Session d\'Écoute', 'km-salle-ecoute' ),
            'menu_name'             => __( 'KM Salle d\'Écoute', 'km-salle-ecoute' ),
            'add_new'               => __( 'Ajouter', 'km-salle-ecoute' ),
            'add_new_item'          => __( 'Nouvelle Session', 'km-salle-ecoute' ),
            'edit_item'             => __( 'Modifier la Session', 'km-salle-ecoute' ),
            'new_item'              => __( 'Nouvelle Session', 'km-salle-ecoute' ),
            'view_item'             => __( 'Voir la Session', 'km-salle-ecoute' ),
            'search_items'          => __( 'Rechercher', 'km-salle-ecoute' ),
            'not_found'             => __( 'Aucune session trouvée', 'km-salle-ecoute' ),
            'not_found_in_trash'    => __( 'Aucune session dans la corbeille', 'km-salle-ecoute' ),
            'all_items'             => __( 'Toutes les Sessions', 'km-salle-ecoute' ),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'exclude_from_search' => true,    // pas de fuite des titres dans la recherche du site
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => false,   // On garde les sessions privées des API REST
            'query_var'           => true,
            'rewrite'             => [ 'slug' => 'ecoute', 'with_front' => false ],
            'capability_type'     => 'post',
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => 26,
            'menu_icon'           => 'dashicons-format-audio',
            'supports'            => [ 'title', 'thumbnail', 'excerpt' ],
        ];

        register_post_type( 'kse_session', $args );

        /* ── Taxonomie : Type de Contenu (Démo / Produit Fini) ── */
        register_taxonomy( 'kse_type_contenu', 'kse_session', [
            'labels'            => [
                'name'              => __( 'Types de Contenu', 'km-salle-ecoute' ),
                'singular_name'     => __( 'Type de Contenu', 'km-salle-ecoute' ),
                'all_items'         => __( 'Tous les Types', 'km-salle-ecoute' ),
                'add_new_item'      => __( 'Ajouter un Type', 'km-salle-ecoute' ),
                'edit_item'         => __( 'Modifier le Type', 'km-salle-ecoute' ),
            ],
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'type-ecoute' ],
        ] );

        /* ── Taxonomie : Genre Musical ── */
        register_taxonomy( 'kse_genre', 'kse_session', [
            'labels'            => [
                'name'          => __( 'Genres', 'km-salle-ecoute' ),
                'singular_name' => __( 'Genre', 'km-salle-ecoute' ),
                'all_items'     => __( 'Tous les Genres', 'km-salle-ecoute' ),
                'add_new_item'  => __( 'Ajouter un Genre', 'km-salle-ecoute' ),
                'edit_item'     => __( 'Modifier le Genre', 'km-salle-ecoute' ),
            ],
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'genre-musical' ],
        ] );

        // Insérer les termes par défaut si absents (une seule fois — pas à chaque
        // chargement de 'init', pour éviter des requêtes term_exists() inutiles)
        if ( ! get_option( 'kse_terms_seeded' ) ) {
            self::insert_default_terms();
            update_option( 'kse_terms_seeded', 1 );
        }
    }

    private static function insert_default_terms(): void {
        $types = [ 'Démo', 'Produit Fini', 'Instrumental', 'Remix' ];
        foreach ( $types as $term ) {
            if ( ! term_exists( $term, 'kse_type_contenu' ) ) {
                wp_insert_term( $term, 'kse_type_contenu' );
            }
        }

        $genres = [ 'Afrobeat', 'Coupé-Décalé', 'Hip-Hop', 'R&B', 'Gospel', 'Pop', 'Reggae', 'Jazz' ];
        foreach ( $genres as $genre ) {
            if ( ! term_exists( $genre, 'kse_genre' ) ) {
                wp_insert_term( $genre, 'kse_genre' );
            }
        }
    }

    public static function messages( array $messages ): array {
        $post       = get_post();
        $post_type  = get_post_type( $post );
        if ( 'kse_session' !== $post_type ) return $messages;

        $messages['kse_session'] = [
            0  => '',
            1  => __( 'Session mise à jour.', 'km-salle-ecoute' ),
            2  => __( 'Champ personnalisé mis à jour.', 'km-salle-ecoute' ),
            3  => __( 'Champ personnalisé supprimé.', 'km-salle-ecoute' ),
            4  => __( 'Session mise à jour.', 'km-salle-ecoute' ),
            6  => __( 'Session publiée.', 'km-salle-ecoute' ),
            7  => __( 'Session sauvegardée.', 'km-salle-ecoute' ),
            8  => __( 'Session soumise.', 'km-salle-ecoute' ),
            10 => __( 'Brouillon de session mis à jour.', 'km-salle-ecoute' ),
        ];

        return $messages;
    }
}
