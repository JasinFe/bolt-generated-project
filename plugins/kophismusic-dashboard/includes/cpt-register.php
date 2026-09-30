<?php
// ============================================================
// KOPHI'S MUSIC — Enregistrement automatique des CPTs
// ============================================================
defined( 'ABSPATH' ) || exit;

add_action( 'init', 'km_register_all_cpts' );

function km_register_all_cpts() {

    // NOTE : Les CPTs "nos-artistes" et "discographies" sont déjà
    // enregistrés par votre thème/plugin existant — on ne les redéclare pas.

    // ── 1. RAPPORTS MENSUELS (nouveau) ───────────────────────
    register_post_type( 'rapport_mensuel', array(
        'labels' => array(
            'name'               => 'Rapports Mensuels',
            'singular_name'      => 'Rapport Mensuel',
            'add_new'            => 'Ajouter un rapport',
            'add_new_item'       => 'Ajouter un nouveau rapport',
            'edit_item'          => 'Modifier le rapport',
            'not_found'          => 'Aucun rapport trouvé',
            'menu_name'          => 'Rapports Streaming',
        ),
        'public'            => false,
        'publicly_queryable'=> false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_in_rest'      => false,
        'menu_icon'         => 'dashicons-chart-bar',
        'menu_position'     => 25,
        'supports'          => array( 'title' ),
        'rewrite'           => false,
        'capability_type'   => 'post',
        'capabilities'      => array(
            'create_posts'   => 'manage_options',
        ),
        'map_meta_cap'      => true,
    ) );

    // ── 4. PAIEMENTS (nouveau) ───────────────────────────────
    register_post_type( 'paiement', array(
        'labels' => array(
            'name'               => 'Paiements',
            'singular_name'      => 'Paiement',
            'add_new'            => 'Ajouter un paiement',
            'add_new_item'       => 'Enregistrer un paiement',
            'edit_item'          => 'Modifier le paiement',
            'not_found'          => 'Aucun paiement trouvé',
            'menu_name'          => 'Paiements',
        ),
        'public'            => false,
        'publicly_queryable'=> false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_in_rest'      => false,
        'menu_icon'         => 'dashicons-money-alt',
        'menu_position'     => 26,
        'supports'          => array( 'title' ),
        'rewrite'           => false,
        'capability_type'   => 'post',
        'capabilities'      => array(
            'create_posts'   => 'manage_options',
        ),
        'map_meta_cap'      => true,
    ) );

    // ── 5. CONTRATS ARTISTES (nouveau) ───────────────────────
    register_post_type( 'contrat_artiste', array(
        'labels' => array(
            'name'               => 'Contrats',
            'singular_name'      => 'Contrat',
            'add_new'            => 'Ajouter un contrat',
            'menu_name'          => 'Contrats',
        ),
        'public'            => false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'menu_icon'         => 'dashicons-media-document',
        'menu_position'     => 27,
        'supports'          => array( 'title' ),
        'rewrite'           => false,
    ) );
}

// ── Limitation max 3 genres dans l'admin ACF ─────────────────
add_action( 'admin_footer', 'km_genre_max_js' );
function km_genre_max_js() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->post_type, array('nos-artistes') ) ) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var genreField = document.querySelector('[data-name="genre_musical"]');
        if (!genreField) return;
        var MAX = 3;
        function enforceMax() {
            var boxes = genreField.querySelectorAll('input[type="checkbox"]');
            var checked = genreField.querySelectorAll('input[type="checkbox"]:checked');
            if (checked.length >= MAX) {
                boxes.forEach(function(b) {
                    if (!b.checked) {
                        b.disabled = true;
                        b.closest('li') && (b.closest('li').style.opacity = '.4');
                    }
                });
            } else {
                boxes.forEach(function(b) {
                    b.disabled = false;
                    b.closest('li') && (b.closest('li').style.opacity = '1');
                });
            }
        }
        genreField.addEventListener('change', enforceMax);
        enforceMax();
    });
    </script>
    <?php
}
