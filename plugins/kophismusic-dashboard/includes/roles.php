<?php
// ============================================================
// KOPHI'S MUSIC — Rôles utilisateurs
// ============================================================
defined( 'ABSPATH' ) || exit;

// ── Création des rôles — UNIQUEMENT à l'activation ──────────────
// Cette fonction est appelée par km_run_setup() (dans le fichier
// principal, sur register_activation_hook). Elle ne doit JAMAIS être
// accrochée sur 'init' : cela recréerait remove_role()+add_role() —
// donc une écriture de l'option 'wp_user_roles' — à CHAQUE chargement
// de page sur tout le site, artiste ou pas, ce qui est à la fois
// inutile et risqué en cas de requêtes concurrentes.
function km_create_roles() {

    // Supprimer d'abord si existe déjà (évite les doublons)
    remove_role( 'artiste_label' );
    remove_role( 'manager_label' );

    // Rôle Artiste
    add_role( 'artiste_label', 'Artiste Label', array(
        'read'         => true,
        'upload_files' => false,
    ) );

    // Rôle Manager (peut voir tout le dashboard label, pas les réglages WP)
    add_role( 'manager_label', 'Manager Label', array(
        'read'                   => true,
        'upload_files'           => true,
        'edit_posts'             => false,
        'manage_options'         => false,
        'km_view_label_dashboard'=> true,
    ) );
}

// Empêcher les artistes d'accéder à l'admin WordPress
add_action( 'admin_init', 'km_redirect_artists_from_admin' );
function km_redirect_artists_from_admin() {
    if ( is_user_logged_in() && ! defined( 'DOING_AJAX' ) ) {
        $user = wp_get_current_user();
        if ( in_array( 'artiste_label', (array) $user->roles ) ) {
            $url = function_exists( 'km_dashboard_url' ) ? km_dashboard_url( 'mon-tableau-de-bord' ) : home_url( '/mon-tableau-de-bord/' );
            wp_redirect( $url );
            exit;
        }
    }
}

// Masquer la barre admin pour les artistes
add_filter( 'show_admin_bar', 'km_hide_admin_bar_for_artists' );
function km_hide_admin_bar_for_artists( $show ) {
    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        if ( in_array( 'artiste_label', (array) $user->roles ) ) {
            return false;
        }
    }
    return $show;
}

// Redirection après login selon le rôle
add_filter( 'login_redirect', 'km_login_redirect', 10, 3 );
function km_login_redirect( $redirect_to, $request, $user ) {
    if ( ! isset( $user->roles ) ) return $redirect_to;
    if ( in_array( 'artiste_label', (array) $user->roles ) ) {
        return function_exists( 'km_dashboard_url' ) ? km_dashboard_url( 'mon-tableau-de-bord' ) : home_url( '/mon-tableau-de-bord/' );
    }
    if ( in_array( 'manager_label', (array) $user->roles ) || in_array( 'administrator', (array) $user->roles ) ) {
        return function_exists( 'km_dashboard_url' ) ? km_dashboard_url( 'dashboard-label' ) : home_url( '/dashboard-label/' );
    }
    return $redirect_to;
}

// Redirection après logout — vers la VRAIE page de connexion
// (slug réel : "connexion-artiste", pas "connexion" — l'ancienne
// version pointait vers une page inexistante et affichait un 404
// après chaque déconnexion).
add_action( 'wp_logout', 'km_logout_redirect' );
function km_logout_redirect() {
    $url = function_exists( 'km_dashboard_url' ) ? km_dashboard_url( 'connexion-artiste' ) : home_url( '/connexion-artiste/' );
    wp_redirect( $url );
    exit;
}
