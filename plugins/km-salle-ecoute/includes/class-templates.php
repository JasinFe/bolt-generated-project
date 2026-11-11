<?php
/**
 * Gestion des templates pour KM SALLE D'ÉCOUTE
 * - Pleine largeur via body_class + suppression sidebar
 * - Archive taxonomie gérée
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_Templates {

    public static function init(): void {
        add_filter( 'single_template',   [ __CLASS__, 'single_session_template' ] );
        add_filter( 'page_template',     [ __CLASS__, 'page_template' ] );
        add_filter( 'taxonomy_template', [ __CLASS__, 'taxonomy_template' ] );
        add_filter( 'body_class',        [ __CLASS__, 'body_class' ] );

        // Déclare le modèle de page du catalogue : sans cela WordPress ne le connaît
        // pas et le réinitialise à « Modèle par défaut » dès que la page est modifiée.
        add_filter( 'theme_page_templates', [ __CLASS__, 'register_page_template' ] );

        // Vide réellement les zones de widgets sur nos pages (le CSS masque déjà la
        // sidebar visuellement, ceci évite en plus le rendu inutile des widgets).
        add_filter( 'sidebars_widgets', [ __CLASS__, 'clear_sidebars_widgets' ] );
    }

    public static function register_page_template( array $templates ): array {
        $templates['km-salle-ecoute-catalogue.php'] = __( 'KM Salle d’Écoute – Catalogue', 'km-salle-ecoute' );
        return $templates;
    }

    public static function single_session_template( string $template ): string {
        if ( ! is_singular( 'kse_session' ) ) return $template;
        $theme  = get_stylesheet_directory() . '/km-salle-ecoute/single-kse_session.php';
        if ( file_exists( $theme ) ) return $theme;
        $plugin = KM_SALLE_ECOUTE_TEMPLATES . 'single-session.php';
        if ( file_exists( $plugin ) ) return $plugin;
        return $template;
    }

    public static function page_template( string $template ): string {
        $slug = get_page_template_slug();
        if ( 'km-salle-ecoute-catalogue.php' === $slug ) {
            $theme  = get_stylesheet_directory() . '/km-salle-ecoute/catalogue.php';
            if ( file_exists( $theme ) ) return $theme;
            $plugin = KM_SALLE_ECOUTE_TEMPLATES . 'page-catalogue.php';
            if ( file_exists( $plugin ) ) return $plugin;
        }
        return $template;
    }

    /** Archive des taxonomies kse_type_contenu et kse_genre */
    public static function taxonomy_template( string $template ): string {
        if ( ! is_tax( [ 'kse_type_contenu', 'kse_genre' ] ) ) return $template;
        $theme  = get_stylesheet_directory() . '/km-salle-ecoute/taxonomy-km.php';
        if ( file_exists( $theme ) ) return $theme;
        $plugin = KM_SALLE_ECOUTE_TEMPLATES . 'taxonomy-km.php';
        if ( file_exists( $plugin ) ) return $plugin;
        return $template;
    }

    public static function body_class( array $classes ): array {
        if ( self::is_km_context() ) {
            $classes[] = 'km-fullwidth-page';
            if ( get_option( 'kse_masquer_theme', 1 ) ) $classes[] = 'km-hide-chrome';
        }
        return $classes;
    }

    private static function is_km_context(): bool {
        return KM_Salle_Ecoute_Player::is_fullwidth_context();
    }

    /**
     * Vide les zones de widgets (sidebars) sur nos pages, en conditions réelles
     * (contrairement à un hook 'get_sidebar' vide, qui n'empêche rien).
     */
    public static function clear_sidebars_widgets( array $sidebars_widgets ): array {
        if ( ! self::is_km_context() ) return $sidebars_widgets;

        foreach ( $sidebars_widgets as $id => $widgets ) {
            if ( 'wp_inactive_widgets' !== $id ) {
                $sidebars_widgets[ $id ] = [];
            }
        }
        return $sidebars_widgets;
    }
}
