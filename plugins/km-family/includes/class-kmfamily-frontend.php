<?php
/**
 * Frontend v2.1 — Assets + Full-width + Template de page custom
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_filter( 'template_include',   array( __CLASS__, 'load_templates' ), 99 );
        add_action( 'wp_head',            array( __CLASS__, 'output_custom_css_vars' ), 5 );

        // Pleine largeur pour les pages KM Family
        add_filter( 'body_class',         array( __CLASS__, 'add_body_class' ) );
        // NETTOYAGE v3.3.1 : le filtre 'the_content' wrap_shortcodes_fullwidth() était
        // branché ici alors qu'il retournait $content strictement inchangé (son corps se
        // terminait par un `return $content;` placé AVANT toute utilisation des variables
        // calculées juste au-dessus). Il s'exécutait donc sur chaque contenu de chaque page
        // du site pour ne rien faire — y compris dans les boucles d'archives. Hook retiré,
        // la méthode est conservée en no-op pour ne casser aucun appel externe éventuel.

        // Injecter du CSS inline pour forcer la pleine largeur sur les pages KM Family
        // Utiliser wp_head avec priorité très haute pour passer APRÈS tout CSS d'Astra
        add_action( 'wp_head', array( __CLASS__, 'inject_fullwidth_css' ), 9999 );

        // Astra-specific : forcer le layout pleine largeur via les filtres Astra
        add_filter( 'astra_page_layout',              array( __CLASS__, 'astra_force_fullwidth' ) );
        add_filter( 'astra_get_content_layout',       array( __CLASS__, 'astra_force_content_layout' ) );
        add_filter( 'astra_main_header_display',      array( __CLASS__, 'astra_keep_header' ) );

        // Appliquer la meta post sur création des pages KM Family
        add_action( 'init', array( __CLASS__, 'ensure_astra_meta_on_kmfamily_pages' ), 20 );

        // Template de page personnalisé "KM Family — Pleine largeur"
        add_filter( 'theme_page_templates', array( __CLASS__, 'register_page_template' ) );
        add_filter( 'template_include',    array( __CLASS__, 'load_page_template' ), 100 );
    }

    public static function enqueue_assets() {
        wp_enqueue_style(
            'km-family',
            KMFAMILY_URL . 'assets/css/km-family.css',
            array(),
            KMFAMILY_VERSION
        );

        wp_enqueue_script(
            'km-family',
            KMFAMILY_URL . 'assets/js/km-family.js',
            array( 'jquery' ),
            KMFAMILY_VERSION,
            true
        );

        wp_localize_script( 'km-family', 'KMFamily', array(
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'rest_url'     => rest_url( 'kmfamily/v1/' ),
            'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
            'nonce'        => wp_create_nonce( 'kmfamily_nonce' ),
            'auth_nonce'   => wp_create_nonce( 'kmfamily_auth_nonce' ),
            'login_url'    => ( $p = get_option( 'kmfamily_page_login' ) ) ? get_permalink( $p ) : wp_login_url(),
            'register_url' => ( $p = get_option( 'kmfamily_page_login' ) ) ? get_permalink( $p ) : wp_registration_url(),
            'is_logged_in' => is_user_logged_in(),
            'i18n'         => array(
                'loading'        => __( 'Chargement...', 'km-family' ),
                'error'          => __( 'Une erreur est survenue.', 'km-family' ),
                'redirecting'    => __( 'Redirection vers le paiement...', 'km-family' ),
                'login_required' => __( 'Vous devez créer un compte pour rejoindre la KM Family.', 'km-family' ),
                'password_weak'  => __( 'Faible', 'km-family' ),
                'password_fair'  => __( 'Moyen', 'km-family' ),
                'password_good'  => __( 'Bon', 'km-family' ),
                'password_strong'=> __( 'Excellent', 'km-family' ),
                'confirm_palier_change' => __( 'Changer de palier pour %s vers %s ?', 'km-family' ),
            ),
        ) );
    }

    public static function load_templates( $template ) {
        if ( is_post_type_archive( KMFamily_CPT::POST_TYPE ) ) {
            $theme_template = locate_template( 'archive-contenu_exclusif.php' );
            if ( ! $theme_template ) {
                return KMFAMILY_PATH . 'templates/archive-contenu_exclusif.php';
            }
        }

        if ( is_singular( KMFamily_CPT::POST_TYPE ) ) {
            $theme_template = locate_template( 'single-contenu_exclusif.php' );
            if ( ! $theme_template ) {
                return KMFAMILY_PATH . 'templates/single-contenu_exclusif.php';
            }
        }

        return $template;
    }

    public static function output_custom_css_vars() {
        $settings = get_option( 'kmfamily_settings', array() );
        $color = $settings['brand_color'] ?? '#d4af37';
        echo '<style id="km-family-vars">:root{--kmfamily-brand:' . esc_attr( $color ) . ';}</style>' . "\n";
    }

    /**
     * ASTRA — Forcer le layout "page-builder" (sans container, sans sidebar)
     * pour les pages KM Family.
     */
    public static function astra_force_content_layout( $layout ) {
        if ( self::is_kmfamily_page() ) {
            return 'page-builder';
        }
        return $layout;
    }

    /**
     * ASTRA — Forcer no-sidebar sur les pages KM Family.
     */
    public static function astra_force_fullwidth( $layout ) {
        if ( self::is_kmfamily_page() ) {
            return 'no-sidebar';
        }
        return $layout;
    }

    /**
     * ASTRA — Garder le header (on ne veut pas un mode full-screen)
     */
    public static function astra_keep_header( $display ) {
        return $display;
    }

    /**
     * Appliquer les métas Astra sur les pages KM Family pour qu'elles soient
     * en pleine largeur sans container, ni titre de page, ni sidebar.
     */
    public static function ensure_astra_meta_on_kmfamily_pages() {
        $applied = get_option( 'kmfamily_astra_meta_applied', 0 );
        // On applique une fois par version pour couvrir les upgrades
        if ( $applied === KMFAMILY_VERSION ) return;

        $pages_config = array(
            'kmfamily_page_dashboard',
            'kmfamily_page_profile',
            'kmfamily_page_paliers',
            'kmfamily_page_login',
            'kmfamily_page_payment',
            'kmfamily_page_payment_success',
        );

        foreach ( $pages_config as $option_key ) {
            $page_id = get_option( $option_key );
            if ( ! $page_id ) continue;

            // Métadonnées Astra pour layout pleine largeur sans titre
            update_post_meta( $page_id, 'site-sidebar-layout',       'no-sidebar' );
            update_post_meta( $page_id, 'site-content-layout',       'page-builder' );
            update_post_meta( $page_id, 'ast-main-header-display',   '' );   // garder header
            update_post_meta( $page_id, 'ast-hfb-above-header-display', '' );
            update_post_meta( $page_id, 'ast-hfb-below-header-display', '' );
            update_post_meta( $page_id, 'ast-hfb-mobile-header-display','' );
            update_post_meta( $page_id, 'site-post-title',            'disabled' );
            update_post_meta( $page_id, 'ast-title-bar-layout',       'disabled' );
            update_post_meta( $page_id, 'ast-featured-img',           'disabled' );
            update_post_meta( $page_id, 'footer-sml-layout',          '' );   // laisser défaut du thème
        }

        update_option( 'kmfamily_astra_meta_applied', KMFAMILY_VERSION );
    }

    /**
     * Détecter si la page courante est une page KM Family.
     */
    public static function is_kmfamily_page() {
        // BUGFIX : la page du Smart Payment Center (/km-payment/{reference}/) n'est pas une
        // vraie "Page" WordPress mais une URL générée par une règle de réécriture personnalisée
        // (voir KMFamily_Payment_Center::register_rewrite_rule). is_page() renvoie donc
        // toujours faux pour elle, et elle n'a jamais reçu le traitement "pleine largeur" —
        // contrairement aux autres pages KM Family — d'où l'espace blanc persistant en haut
        // de cette page precise, la plus visitée du parcours (paiement).
        if ( get_query_var( 'kmfamily_order_ref' ) ) return true;

        if ( ! is_page() ) return false;

        $page_id = get_queried_object_id();
        if ( ! $page_id ) return false;

        $kmfamily_pages = array(
            get_option( 'kmfamily_page_dashboard' ),
            get_option( 'kmfamily_page_profile' ),
            get_option( 'kmfamily_page_paliers' ),
            get_option( 'kmfamily_page_login' ),
            get_option( 'kmfamily_page_payment' ),
            get_option( 'kmfamily_page_payment_success' ),
        );

        return in_array( (int) $page_id, array_map( 'intval', array_filter( $kmfamily_pages ) ), true );
    }

    /**
     * Ajouter une classe body pour les pages KM Family.
     */
    public static function add_body_class( $classes ) {
        if ( self::is_kmfamily_page() ) {
            $classes[] = 'kmfamily-page';
            $classes[] = 'kmfamily-fullwidth';
        }
        return $classes;
    }

    /**
     * Injecter le CSS pour forcer la pleine largeur.
     *
     * CORRECTIF v3.3.4 — la remise à zéro « pleine largeur » de ce bloc visait le
     * sélecteur nu `main` avec `padding-left/right: 0 !important`. Or la page de
     * connexion est bâtie sur `<main class="kmfamily-auth-main">` : elle perdait donc
     * toute sa marge latérale, et ce `!important` l'emportait sur le padding défini
     * dans la feuille de styles. Sur téléphone, le formulaire, les libellés et la
     * photo de l'artiste se retrouvaient collés au bord gauche et les champs
     * débordaient à droite hors de l'écran — c'est le défaut visible sur la capture
     * remontée par l'utilisateur. Le sélecteur exclut désormais explicitement la page
     * de connexion, et la marge y est réaffirmée en fin de bloc.
     *
     * Note : la chaîne ci-dessous est délimitée par des quotes simples — ne jamais y
     * écrire d'apostrophe non échappée, y compris dans un commentaire CSS.
     */
    public static function inject_fullwidth_css() {
        if ( ! self::is_kmfamily_page() ) return;

        $css = '
        /* ========================================================
         * KM Family — Pleine largeur (compatible Astra + thèmes génériques)
         * ======================================================== */

        /* Thèmes génériques */
        body.kmfamily-fullwidth .site-content,
        body.kmfamily-fullwidth .content-area,
        body.kmfamily-fullwidth #content,
        body.kmfamily-fullwidth #primary,
        body.kmfamily-fullwidth .entry-content,
        body.kmfamily-fullwidth article,
        body.kmfamily-fullwidth .post,
        body.kmfamily-fullwidth .page-content,
        body.kmfamily-fullwidth .wp-block-post-content,
        body.kmfamily-fullwidth main:not(.kmfamily-auth-main),
        body.kmfamily-fullwidth main article {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }

        /* ======= THÈMES BLOCS (FSE : Twenty Twenty-Four/Five…) =======
         * CORRECTIF v3.4.1 : le conteneur principal perdait son padding (règle ci-dessus)
         * mais ses enfants .alignfull gardaient la marge négative prévue pour « sortir » de
         * ce padding : la page débordait de ~30 px à droite sur mobile (défilement latéral).
         */
        body.kmfamily-fullwidth main .alignfull,
        body.kmfamily-fullwidth .has-global-padding > .alignfull {
            margin-left: 0 !important;
            margin-right: 0 !important;
            max-width: 100% !important;
        }
        body.kmfamily-fullwidth .has-global-padding:has(.kmfamily-page-wrap) {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }
        /* Filet anti-défilement horizontal (clip ne casse pas position:sticky). */
        body.kmfamily-fullwidth { overflow-x: clip; }

        /* ======= ASTRA THEME SPECIFIC ======= */
        body.kmfamily-fullwidth .ast-container,
        body.kmfamily-fullwidth .site-content .ast-container,
        body.kmfamily-fullwidth #primary,
        body.kmfamily-fullwidth .ast-article-single,
        body.kmfamily-fullwidth .ast-article-post,
        body.kmfamily-fullwidth .entry-content .ast-container,
        body.kmfamily-fullwidth .ast-no-sidebar #primary,
        body.kmfamily-fullwidth .ast-plain-container .site-content > .ast-container {
            max-width: 100% !important;
            width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        /* Astra : désactiver le padding du site-content (layout page-builder) */
        body.kmfamily-fullwidth.ast-page-builder-template .site-content > .ast-container,
        body.kmfamily-fullwidth.ast-page-builder-template #primary,
        body.kmfamily-fullwidth.ast-page-builder-template .ast-article-post,
        body.kmfamily-fullwidth.ast-page-builder-template .entry-content {
            padding: 0 !important;
            margin: 0 !important;
            max-width: 100% !important;
        }

        /* Astra : cacher le titre de page et meta */
        body.kmfamily-fullwidth .entry-header,
        body.kmfamily-fullwidth .entry-title,
        body.kmfamily-fullwidth .ast-archive-title,
        body.kmfamily-fullwidth .ast-single-post-meta,
        body.kmfamily-fullwidth .entry-meta,
        body.kmfamily-fullwidth .page-title,
        body.kmfamily-fullwidth .wp-block-post-title,
        body.kmfamily-fullwidth h1.entry-title,
        body.kmfamily-fullwidth .ast-breadcrumbs-wrapper,
        body.kmfamily-fullwidth .ast-title-bar-wrap {
            display: none !important;
        }

        /* Astra : site-content pas de padding */
        body.kmfamily-fullwidth .site-content,
        body.kmfamily-fullwidth #content.site-content {
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            margin-top: 0 !important;
        }

        /* Forcer suppression de TOUT espace entre header et contenu */
        body.kmfamily-fullwidth #content,
        body.kmfamily-fullwidth .site-content,
        body.kmfamily-fullwidth #primary,
        body.kmfamily-fullwidth .site-main,
        body.kmfamily-fullwidth .ast-container,
        body.kmfamily-fullwidth article,
        body.kmfamily-fullwidth .entry-content {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }

        /* Astra Breadcrumbs, title bar : cachés + pas d\'espace */
        body.kmfamily-fullwidth .ast-breadcrumbs-wrapper,
        body.kmfamily-fullwidth .ast-title-bar-wrap,
        body.kmfamily-fullwidth .ast-archive-description,
        body.kmfamily-fullwidth .ast-single-post-header,
        body.kmfamily-fullwidth .ast-page-header-section {
            display: none !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* Si le thème a une div "banner" ou "hero" entre header et content */
        body.kmfamily-fullwidth .ast-above-header,
        body.kmfamily-fullwidth .ast-header-break-point .ast-above-header {
            margin-bottom: 0 !important;
        }

        /* Remonter le wrap via margin négative pour absorber tout espace résiduel */
        body.kmfamily-fullwidth .kmfamily-page-wrap {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0 0 60px;
            background: #0a0a0a;
            color: #fff;
            min-height: 80vh;
            box-sizing: border-box;
        }

        /* Le hero du dashboard et du profil se colle au header */
        body.kmfamily-fullwidth .kmfamily-dashboard__hero,
        body.kmfamily-fullwidth .kmfamily-profile__hero,
        body.kmfamily-fullwidth .kmfamily-paliers-all__hero {
            margin-top: 0 !important;
            border-top-left-radius: 0 !important;
            border-top-right-radius: 0 !important;
        }

        /* Astra : retirer la marge de .ast-row */
        body.kmfamily-fullwidth .ast-row {
            margin-left: 0 !important;
            margin-right: 0 !important;
        }

        body.kmfamily-fullwidth .site-main {
            padding: 0 !important;
            margin: 0 !important;
        }

        body.kmfamily-fullwidth .kmfamily-page-wrap {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 40px 24px 60px;
            background: #0a0a0a;
            color: #fff;
            min-height: 80vh;
            box-sizing: border-box;
        }

        /* Pour le login : pleine hauteur sans padding */
        body.kmfamily-fullwidth .kmfamily-auth-page {
            margin: 0;
            padding: 0;
        }
        body.kmfamily-fullwidth .kmfamily-page-wrap:has(.kmfamily-auth-page) {
            padding: 0;
        }

        /* Mobile Astra : retirer padding supplémentaire */
        @media (max-width: 921px) {
            body.kmfamily-fullwidth .ast-container {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
        }

        /* ========================================================
         * FIX ESPACE BLANC : absorber avec marge négative si besoin
         * ======================================================== */
        body.kmfamily-fullwidth #content,
        body.kmfamily-fullwidth .site-content,
        body.kmfamily-fullwidth main#content {
            margin-top: 0 !important;
            padding-top: 0 !important;
            border-top: 0 !important;
        }

        /* Ciblage très agressif : TOUT élément entre header et le wrap */
        body.kmfamily-fullwidth header + *,
        body.kmfamily-fullwidth #masthead + *,
        body.kmfamily-fullwidth .site-header + * {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }

        /* Remonter le contenu via margin négative absolue si un espace persiste */
        body.kmfamily-fullwidth .kmfamily-page-wrap {
            margin-top: -1px !important;
        }

        /* ========================================================
         * CORRECTIF v3.3.4 — PAGE DE CONNEXION COLLEE AUX BORDS SUR MOBILE
         * --------------------------------------------------------
         * La regle pleine largeur ci-dessus visait le selecteur nu `main` avec
         * padding-left/right: 0 !important. Or la page de connexion est batie sur
         * <main class="kmfamily-auth-main"> : elle perdait donc TOUTE sa marge
         * laterale, et ce !important battait le .kmfamily-auth-main { padding } de
         * la feuille de styles, qui nen a pas. Sur telephone, le formulaire, les
         * libelles et la photo de lartiste se retrouvaient colles au bord gauche,
         * et les champs debordaient a droite hors de lecran.
         * Le selecteur est desormais exclu plus haut : main:not(.kmfamily-auth-main).
         * On reaffirme ici la marge pour rester correct meme si un autre theme ou
         * une extension applique la meme remise a zero agressive sur `main`.
         * ======================================================== */
        /* CORRECTIF v3.4.1 — double gouttière sur téléphone : l\'enveloppe ET ses
         * sections (onglets, contenu, grille profil) avaient chacune 24 px de marge,
         * soit 48 px perdus de chaque côté d\'un écran de 375 px. */
        @media (max-width: 768px) {
            body.kmfamily-fullwidth .kmfamily-page-wrap.kmfamily-dashboard,
            body.kmfamily-fullwidth .kmfamily-page-wrap.kmfamily-profile-page {
                padding-left: 0;
                padding-right: 0;
                padding-top: 0;
            }
            body.kmfamily-fullwidth .kmfamily-page-wrap .kmfamily-dashboard__tabs,
            body.kmfamily-fullwidth .kmfamily-page-wrap .kmfamily-dashboard__tab-content,
            body.kmfamily-fullwidth .kmfamily-page-wrap .kmfamily-profile__grid,
            body.kmfamily-fullwidth .kmfamily-page-wrap .kmfamily-dashboard__logout {
                padding-left: 16px;
                padding-right: 16px;
            }
        }

        body.kmfamily-fullwidth .kmfamily-auth-main {
            padding-left: 24px !important;
            padding-right: 24px !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 480px) {
            body.kmfamily-fullwidth .kmfamily-auth-main {
                padding-left: 18px !important;
                padding-right: 18px !important;
            }
        }
        ';

        echo "\n<style id=\"kmfamily-fullwidth-inline\" type=\"text/css\">\n" . $css . "\n</style>\n";
    }

    /**
     * @deprecated v3.3.1 — sans effet : les shortcodes sont déjà encapsulés par leurs
     * propres gabarits. Conservée en no-op, plus branchée sur 'the_content'.
     */
    public static function wrap_shortcodes_fullwidth( $content ) {
        return $content;
    }

    /**
     * Enregistrer un template de page "KM Family — Pleine largeur".
     */
    public static function register_page_template( $templates ) {
        $templates['km-family-fullwidth.php'] = __( 'KM Family — Pleine largeur', 'km-family' );
        return $templates;
    }

    /**
     * Charger le template de page custom.
     */
    public static function load_page_template( $template ) {
        if ( is_page() ) {
            $custom = get_page_template_slug( get_queried_object_id() );
            if ( $custom === 'km-family-fullwidth.php' ) {
                $plugin_template = KMFAMILY_PATH . 'templates/page-fullwidth.php';
                if ( file_exists( $plugin_template ) ) {
                    return $plugin_template;
                }
            }
        }
        return $template;
    }
}
