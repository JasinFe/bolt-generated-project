<?php
/**
 * CPT "Contenu Exclusif" + taxonomies
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_CPT {

    const POST_TYPE  = 'contenu_exclusif';
    const TAX_TYPE   = 'type_contenu';
    const TAX_PALIER = 'palier_acces';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ), 10 );
        add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 11 );
        add_action( 'init', array( __CLASS__, 'insert_default_terms' ), 12 );
    }

    public static function register_post_type() {
        $labels = array(
            'name'               => __( 'Contenus Exclusifs', 'km-family' ),
            'singular_name'      => __( 'Contenu Exclusif', 'km-family' ),
            'menu_name'          => __( 'Contenus Exclusifs', 'km-family' ),
            'all_items'          => __( 'Tous les contenus', 'km-family' ),
            'add_new_item'       => __( 'Ajouter un contenu exclusif', 'km-family' ),
            'add_new'            => __( 'Ajouter', 'km-family' ),
            'new_item'           => __( 'Nouveau contenu', 'km-family' ),
            'edit_item'          => __( 'Modifier le contenu', 'km-family' ),
            'view_item'          => __( 'Voir le contenu', 'km-family' ),
            'search_items'       => __( 'Rechercher un contenu', 'km-family' ),
            'not_found'          => __( 'Aucun contenu trouvé.', 'km-family' ),
            'not_found_in_trash' => __( 'Aucun contenu dans la corbeille.', 'km-family' ),
            'featured_image'     => __( 'Image à la une', 'km-family' ),
        );

        $args = array(
            'label'               => __( 'Contenu Exclusif', 'km-family' ),
            'description'         => __( 'Contenus exclusifs réservés aux membres KM Family.', 'km-family' ),
            'labels'              => $labels,
            'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields', 'revisions' ),
            'taxonomies'          => array( self::TAX_TYPE, self::TAX_PALIER ),
            'hierarchical'        => false,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 5,
            'menu_icon'           => 'dashicons-star-filled',
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => true,
            'can_export'          => true,
            'has_archive'         => 'contenus-exclusifs',
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'show_in_rest'        => true,
            'rest_base'           => 'contenus-exclusifs',
            'rewrite'             => array(
                'slug'       => 'contenu-exclusif',
                'with_front' => false,
            ),
        );

        register_post_type( self::POST_TYPE, $args );
    }

    public static function register_taxonomies() {
        register_taxonomy( self::TAX_TYPE, array( self::POST_TYPE ), array(
            'labels' => array(
                'name'          => __( 'Types de contenu', 'km-family' ),
                'singular_name' => __( 'Type de contenu', 'km-family' ),
                'menu_name'     => __( 'Types', 'km-family' ),
            ),
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'type-contenu' ),
        ) );

        register_taxonomy( self::TAX_PALIER, array( self::POST_TYPE ), array(
            'labels' => array(
                'name'          => __( 'Paliers d\'accès', 'km-family' ),
                'singular_name' => __( 'Palier d\'accès', 'km-family' ),
                'menu_name'     => __( 'Paliers', 'km-family' ),
            ),
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'palier' ),
        ) );
    }

    public static function insert_default_terms() {
        if ( get_option( 'kmfamily_default_terms_v21_inserted' ) ) return;

        $types = array(
            'video'     => __( 'Vidéo', 'km-family' ),
            'audio'     => __( 'Audio', 'km-family' ),
            'live'      => __( 'Live', 'km-family' ),
            'demo'      => __( 'Démo', 'km-family' ),
            'coulisses' => __( 'Coulisses', 'km-family' ),
            'galerie'   => __( 'Galerie photo', 'km-family' ),
            'actualite' => __( 'Actualité privée', 'km-family' ),
        );

        foreach ( $types as $slug => $name ) {
            if ( ! term_exists( $slug, self::TAX_TYPE ) ) {
                wp_insert_term( $name, self::TAX_TYPE, array( 'slug' => $slug ) );
            }
        }

        // Les 5 paliers standardisés + "public" + "libre"
        $paliers = array(
            'public'  => __( 'Public', 'km-family' ),
            'bronze'  => __( 'Bronze', 'km-family' ),
            'libre'   => __( 'Libre (Cœur Généreux)', 'km-family' ),
            'argent'  => __( 'Argent', 'km-family' ),
            'or'      => __( 'Or', 'km-family' ),
            'platine' => __( 'Platine', 'km-family' ),
            'diamant' => __( 'Diamant', 'km-family' ),
        );

        foreach ( $paliers as $slug => $name ) {
            if ( ! term_exists( $slug, self::TAX_PALIER ) ) {
                wp_insert_term( $name, self::TAX_PALIER, array( 'slug' => $slug ) );
            }
        }

        update_option( 'kmfamily_default_terms_v21_inserted', true );
    }

    /**
     * Proxies vers KMFamily_Paliers pour compatibilité.
     */
    public static function get_paliers_hierarchy() {
        return KMFamily_Paliers::get_hierarchy();
    }

    public static function get_palier_label( $slug ) {
        return KMFamily_Paliers::get_label( $slug );
    }
}
