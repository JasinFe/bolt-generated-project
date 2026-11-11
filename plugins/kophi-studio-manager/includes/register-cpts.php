<?php
/**
 * KOPHI'S MUSIC STUDIO — Enregistrement natif des CPTs & Taxonomies
 * ================================================================
 * Ce fichier enregistre tous les Custom Post Types directement en PHP.
 * Aucune dépendance à CPT UI — stable et permanent.
 *
 * Inclus automatiquement par kophi-studio-manager.php
 * Hook : init | Priorité : 5
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'kophi_register_all_cpts', 5);

function kophi_register_all_cpts() {

    // ============================================================
    // CPT 1 — SESSIONS STUDIO
    // ============================================================
    register_post_type('kophi_session', [
        'labels' => [
            'name'               => 'Sessions',
            'singular_name'      => 'Session',
            'menu_name'          => 'Sessions',
            'add_new'            => 'Ajouter',
            'add_new_item'       => 'Nouvelle session',
            'edit_item'          => 'Modifier la session',
            'new_item'           => 'Nouvelle session',
            'view_item'          => 'Voir la session',
            'search_items'       => 'Rechercher une session',
            'not_found'          => 'Aucune session trouvée',
            'not_found_in_trash' => 'Aucune session dans la corbeille',
        ],
        'description'         => 'Sessions d\'enregistrement au studio',
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => true,
        'show_in_menu'        => false,   // Menu géré par KOPHI Studio
        'show_in_nav_menus'   => false,
        'show_in_rest'        => true,
        'rest_base'           => 'kophi-sessions',
        'has_archive'         => false,
        'exclude_from_search' => true,
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'hierarchical'        => false,
        'rewrite'             => false,
        'query_var'           => false,
        'menu_icon'           => 'dashicons-clock',
        'menu_position'       => 5,
        'supports'            => ['title', 'custom-fields'],
    ]);

    // ============================================================
    // CPT 2 — CLIENTS
    // ============================================================
    register_post_type('kophi_client', [
        'labels' => [
            'name'               => 'Clients',
            'singular_name'      => 'Client',
            'menu_name'          => 'Clients',
            'add_new'            => 'Ajouter',
            'add_new_item'       => 'Nouveau client',
            'edit_item'          => 'Modifier le client',
            'new_item'           => 'Nouveau client',
            'view_item'          => 'Voir le client',
            'search_items'       => 'Rechercher un client',
            'not_found'          => 'Aucun client trouvé',
            'not_found_in_trash' => 'Aucun client dans la corbeille',
        ],
        'description'         => 'Fiches clients du studio',
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'show_in_nav_menus'   => false,
        'show_in_rest'        => true,
        'rest_base'           => 'kophi-clients',
        'has_archive'         => false,
        'exclude_from_search' => true,
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'hierarchical'        => false,
        'rewrite'             => false,
        'query_var'           => false,
        'menu_icon'           => 'dashicons-businessman',
        'supports'            => ['title', 'thumbnail', 'custom-fields'],
    ]);

    // ============================================================
    // CPT 3 — PROJETS MUSICAUX
    // ============================================================
    register_post_type('kophi_projet', [
        'labels' => [
            'name'               => 'Projets',
            'singular_name'      => 'Projet',
            'menu_name'          => 'Projets',
            'add_new'            => 'Nouveau projet',
            'add_new_item'       => 'Nouveau projet musical',
            'edit_item'          => 'Modifier le projet',
            'new_item'           => 'Nouveau projet',
            'view_item'          => 'Voir le projet',
            'search_items'       => 'Rechercher un projet',
            'not_found'          => 'Aucun projet trouvé',
            'not_found_in_trash' => 'Aucun projet dans la corbeille',
        ],
        'description'         => 'Projets musicaux en cours ou terminés',
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'show_in_nav_menus'   => false,
        'show_in_rest'        => true,
        'rest_base'           => 'kophi-projets',
        'has_archive'         => false,
        'exclude_from_search' => true,
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'hierarchical'        => false,
        'rewrite'             => false,
        'query_var'           => false,
        'menu_icon'           => 'dashicons-media-audio',
        'supports'            => ['title', 'editor', 'thumbnail', 'custom-fields'],
    ]);

    // ============================================================
    // CPT 4 — RÉSERVATIONS
    // ============================================================
    register_post_type('kophi_reservation', [
        'labels' => [
            'name'               => 'Réservations',
            'singular_name'      => 'Réservation',
            'menu_name'          => 'Réservations',
            'add_new'            => 'Nouvelle réservation',
            'add_new_item'       => 'Nouvelle réservation',
            'edit_item'          => 'Modifier la réservation',
            'new_item'           => 'Nouvelle réservation',
            'view_item'          => 'Voir la réservation',
            'search_items'       => 'Rechercher une réservation',
            'not_found'          => 'Aucune réservation trouvée',
            'not_found_in_trash' => 'Aucune réservation dans la corbeille',
        ],
        'description'         => 'Demandes de réservation studio et achats de beats',
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'show_in_nav_menus'   => false,
        'show_in_rest'        => true,
        'rest_base'           => 'kophi-reservations',
        'has_archive'         => false,
        'exclude_from_search' => true,
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'hierarchical'        => false,
        'rewrite'             => false,
        'query_var'           => false,
        'menu_icon'           => 'dashicons-calendar-alt',
        'supports'            => ['title', 'custom-fields'],
    ]);

    // ============================================================
    // CPT 5 — BEATS (PUBLIC — visible sur le site)
    // ============================================================
    register_post_type('kophi_beat', [
        'labels' => [
            'name'               => 'Beats',
            'singular_name'      => 'Beat',
            'menu_name'          => 'Beats',
            'add_new'            => 'Ajouter un beat',
            'add_new_item'       => 'Nouveau beat',
            'edit_item'          => 'Modifier le beat',
            'new_item'           => 'Nouveau beat',
            'view_item'          => 'Voir le beat',
            'search_items'       => 'Rechercher un beat',
            'not_found'          => 'Aucun beat trouvé',
            'not_found_in_trash' => 'Aucun beat dans la corbeille',
            'all_items'          => 'Tous les beats',
            'archives'           => 'Catalogue beats',
            'featured_image'     => 'Image de couverture',
            'set_featured_image' => 'Définir la couverture',
        ],
        'description'         => 'Catalogue de beats disponibles à la vente',
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'show_in_nav_menus'   => true,
        'show_in_rest'        => true,
        'rest_base'           => 'kophi-beats',
        'has_archive'         => false,   // Désactivé — on utilise une page WP avec shortcode
        'exclude_from_search' => false,
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'hierarchical'        => false,
        'rewrite'             => [
            'slug'       => 'beat',    // /beat/titre-du-beat — laisse /beats/ libre pour la page WP
            'with_front' => false,
        ],
        'query_var'           => true,
        'menu_icon'           => 'dashicons-format-audio',
        'menu_position'       => 6,
        'supports'            => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'taxonomies'          => ['kophi_genre'],
    ]);

    // ============================================================
    // TAXONOMIE — GENRES MUSICAUX (liée aux beats)
    // ============================================================
    register_taxonomy('kophi_genre', ['kophi_beat'], [
        'labels' => [
            'name'              => 'Genres musicaux',
            'singular_name'     => 'Genre',
            'menu_name'         => 'Genres',
            'all_items'         => 'Tous les genres',
            'edit_item'         => 'Modifier le genre',
            'view_item'         => 'Voir le genre',
            'update_item'       => 'Mettre à jour',
            'add_new_item'      => 'Nouveau genre',
            'new_item_name'     => 'Nom du genre',
            'parent_item'       => 'Genre parent',
            'parent_item_colon' => 'Genre parent :',
            'search_items'      => 'Rechercher un genre',
            'not_found'         => 'Aucun genre trouvé',
        ],
        'public'             => true,
        'publicly_queryable' => true,
        'hierarchical'       => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => true,
        'show_in_rest'       => true,
        'rest_base'          => 'kophi-genres',
        'show_tagcloud'      => true,
        'show_admin_column'  => true,
        'rewrite'            => [
            'slug'         => 'genre-beat',
            'with_front'   => true,
            'hierarchical' => true,
        ],
        'query_var'          => true,
    ]);

    // ============================================================
    // CPT 6 — MENU NAVIGATION PERSONNALISÉ
    // ============================================================
    register_post_type('kophi_menu_item', [
        'labels' => [
            'name'               => 'Menu Navigation',
            'singular_name'      => 'Élément de menu',
            'menu_name'          => '🔗 Menu Navigation',
            'add_new'            => 'Ajouter un lien',
            'add_new_item'       => 'Nouveau lien de menu',
            'edit_item'          => 'Modifier le lien',
            'new_item'           => 'Nouveau lien',
            'search_items'       => 'Rechercher',
            'not_found'          => 'Aucun lien trouvé',
        ],
        'public'            => false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_in_nav_menus' => false,
        'show_in_rest'      => false,
        'supports'          => ['title', 'page-attributes'],
        'menu_icon'         => 'dashicons-admin-links',
        'menu_position'     => 100,
        'capability_type'   => 'post',
        'map_meta_cap'      => true,
        'hierarchical'      => false,
        'rewrite'           => false,
        'has_archive'       => false,
    ]);

// ============================================================
// Genres musicaux par défaut (créés une seule fois)
// ============================================================
}
add_action('init', 'kophi_create_default_genres', 10);

function kophi_create_default_genres() {
    if (get_option('kophi_genres_created_v1')) return;

    $genres = [
        ['Gospel Urbain',  'gospel-urbain'],
        ['Afro Gospel',    'afro-gospel'],
        ['Trap Gospel',    'trap-gospel'],
        ['Worship',        'worship'],
        ['Afrobeats',      'afrobeats'],
        ['Coupé-Décalé',   'coupe-decale'],
        ['R&B / Zouk',     'rnb-zouk'],
        ['Hip-Hop / Trap', 'hip-hop-trap'],
        ['Instrumental',   'instrumental'],
    ];

    foreach ($genres as [$name, $slug]) {
        if (!term_exists($name, 'kophi_genre')) {
            wp_insert_term($name, 'kophi_genre', ['slug' => $slug]);
        }
    }

    update_option('kophi_genres_created_v1', true);
}
