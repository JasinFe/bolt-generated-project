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

// ── Destination « maison » d'un utilisateur, partagée par tous les parcours ─────
// PONT KM FAMILY : un simple membre KM Family (subscriber / kmfamily_member) n'a
// rien à faire sur le Dashboard Label — l'y envoyer affichait « Accès réservé au
// label ». On le renvoie vers son espace membre KM Family quand il existe.
if ( ! function_exists( 'km_user_home_url' ) ) {
    function km_user_home_url( $user ) {
        $roles = (array) ( $user->roles ?? array() );
        if ( in_array( 'artiste_label', $roles, true ) ) {
            return km_dashboard_url( 'mon-tableau-de-bord' );
        }
        if ( in_array( 'manager_label', $roles, true ) || in_array( 'administrator', $roles, true )
             || user_can( $user, 'km_view_label_dashboard' ) ) {
            return km_dashboard_url( 'dashboard-label' );
        }
        // Artiste rattaché à une fiche mais sans le rôle (compte créé côté KM Family).
        if ( class_exists( 'KMFamily_Revenue' ) && ! empty( $user->ID )
             && KMFamily_Revenue::get_artiste_id_by_user( $user->ID ) ) {
            return km_dashboard_url( 'mon-tableau-de-bord' );
        }
        $kmf = (int) get_option( 'kmfamily_page_dashboard' );
        return $kmf ? get_permalink( $kmf ) : home_url( '/' );
    }
}

if ( ! function_exists( 'km_user_is_label_staff' ) ) {
    function km_user_is_label_staff( $user = null ) {
        $user  = $user ?: wp_get_current_user();
        $roles = (array) ( $user->roles ?? array() );
        return in_array( 'administrator', $roles, true ) || in_array( 'manager_label', $roles, true )
            || user_can( $user, 'km_view_label_dashboard' );
    }
}

// Empêcher les artistes d'accéder à l'admin WordPress
add_action( 'admin_init', 'km_redirect_artists_from_admin' );
function km_redirect_artists_from_admin() {
    // CORRECTIF PONT KM FAMILY : admin-post.php déclenche aussi 'admin_init'. Le
    // formulaire « Parler à ma famille » (action kmfp_publish) y est envoyé : l'artiste
    // était redirigé AVANT que la publication ne soit traitée — la fonctionnalité ne
    // marchait donc jamais pour le rôle artiste_label. Idem pour les requêtes AJAX/REST.
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
    $script = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_basename( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
    if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) return;

    if ( is_user_logged_in() ) {
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
    if ( in_array( 'artiste_label', (array) $user->roles, true ) ) {
        return km_dashboard_url( 'mon-tableau-de-bord' );
    }
    // Un administrateur qui se connecte pour aller dans wp-admin doit y arriver :
    // on ne détourne que la destination par défaut.
    if ( in_array( 'manager_label', (array) $user->roles, true )
         || ( in_array( 'administrator', (array) $user->roles, true ) && ( ! $request || false === strpos( $request, 'wp-admin' ) ) ) ) {
        return km_dashboard_url( 'dashboard-label' );
    }
    return $redirect_to;
}

// Redirection après logout — vers la VRAIE page de connexion
// (slug réel : "connexion-artiste", pas "connexion" — l'ancienne
// version pointait vers une page inexistante et affichait un 404
// après chaque déconnexion).
//
// CORRECTIF PONT KM FAMILY : l'ancienne version faisait wp_redirect()+exit sur
// 'wp_logout' pour TOUT le monde — un membre KM Family (ou un administrateur) qui se
// déconnectait atterrissait sur « Connexion Artiste », et le paramètre redirect_to de
// wp_logout_url() (utilisé par KM Family) était ignoré. L'exit court-circuitait aussi
// les autres extensions accrochées à la déconnexion. On passe par le filtre prévu, et
// uniquement pour les comptes artiste / manager.
add_filter( 'logout_redirect', 'km_logout_redirect', 10, 3 );
function km_logout_redirect( $redirect_to, $requested, $user ) {
    $roles = (array) ( $user->roles ?? array() );
    if ( in_array( 'artiste_label', $roles, true ) || in_array( 'manager_label', $roles, true ) ) {
        return km_dashboard_url( 'connexion-artiste' );
    }
    return $redirect_to;
}
