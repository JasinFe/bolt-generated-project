<?php
/**
 * Template Name: KM Salle d'Écoute – Catalogue
 * KM SALLE D'ÉCOUTE v1.7
 */
if ( ! defined( 'ABSPATH' ) ) exit;

KM_Salle_Ecoute_Player::enqueue_base();
wp_enqueue_script( 'km-salle-ecoute-player' );

$sessions = new WP_Query([
    'post_type'      => 'kse_session',
    'post_status'    => 'publish',
    'posts_per_page' => (int) apply_filters( 'kse_catalogue_limit', 60 ),
    'no_found_rows'  => true,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'meta_query'     => KM_Salle_Ecoute_ACF_Helper::catalogue_meta_query(),
]);

get_header();
include KM_SALLE_ECOUTE_TEMPLATES . 'catalogue.php';
get_footer();
