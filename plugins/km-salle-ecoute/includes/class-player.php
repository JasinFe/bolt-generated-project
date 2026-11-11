<?php
/**
 * KM SALLE D'ÉCOUTE — Player : assets, couleurs personnalisées, CSS pleine largeur
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_Player {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register' ], 5 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'wp_head',            [ __CLASS__, 'fullwidth_css' ], 5 );
    }

    /** Page « application » plein écran (session, archive, page catalogue) */
    public static function is_fullwidth_context(): bool {
        return is_singular( 'kse_session' )
            || is_tax( [ 'kse_type_contenu', 'kse_genre' ] )
            || ( is_page() && 'km-salle-ecoute-catalogue.php' === get_page_template_slug() );
    }

    private static function is_km(): bool {
        if ( self::is_fullwidth_context() ) return true;
        $post = get_post();
        if ( $post && is_singular() ) {
            $c = $post->post_content;
            if ( has_shortcode( $c, 'km_salle_ecoute_catalogue' ) || has_shortcode( $c, 'km_salle_ecoute_player' ) || has_shortcode( $c, 'km_salle_ecoute_expired' ) ) return true;
        }
        return false;
    }

    /**
     * Enregistre TOUS les assets, sur toutes les pages : un shortcode placé dans un
     * widget, un bloc réutilisable ou un constructeur de page peut ainsi les charger
     * à la volée (auparavant le CSS et le lecteur n'existaient que si la détection
     * is_km() réussissait — lecteur inline sans style ni JS).
     */
    public static function register(): void {
        wp_register_style(
            'km-salle-ecoute-style',
            KM_SALLE_ECOUTE_URL . 'assets/css/km-salle-ecoute-18.css',
            [],
            KM_SALLE_ECOUTE_VERSION
        );
        wp_add_inline_style( 'km-salle-ecoute-style', self::color_css() );

        if ( get_option( 'kse_google_font', 1 ) ) {
            wp_register_style(
                'km-inter-font',
                'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
                [],
                null
            );
        }

        wp_register_script(
            'wavesurfer',
            KM_SALLE_ECOUTE_URL . 'assets/js/vendor/wavesurfer.min.js', // hébergé localement
            [],
            '7.8.6',
            true
        );
        wp_register_script(
            'km-salle-ecoute-player',
            KM_SALLE_ECOUTE_URL . 'assets/js/km-salle-ecoute-player-17.js',
            [ 'jquery' ],
            KM_SALLE_ECOUTE_VERSION,
            true
        );
        wp_register_script(
            'km-salle-ecoute-inline-player',
            KM_SALLE_ECOUTE_URL . 'assets/js/km-salle-ecoute-inline-player-17.js',
            [ 'jquery', 'wavesurfer' ],
            KM_SALLE_ECOUTE_VERSION,
            true
        );

        $i18n = self::i18n();
        wp_localize_script( 'km-salle-ecoute-player', 'kmEcoute', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'kse_password' ),
            'postId'     => is_singular( 'kse_session' ) ? (int) get_queried_object_id() : 0,
            'hideChrome' => self::is_fullwidth_context() && (bool) get_option( 'kse_masquer_theme', 1 ),
            'i18n'       => $i18n,
        ] );
        wp_localize_script( 'km-salle-ecoute-inline-player', 'kmEcouteInline', [ 'i18n' => $i18n ] );
    }

    /** Assets complets (style + police) — appelé aussi par les shortcodes. */
    public static function enqueue_base(): void {
        wp_enqueue_style( 'km-salle-ecoute-style' );
        if ( wp_style_is( 'km-inter-font', 'registered' ) ) wp_enqueue_style( 'km-inter-font' );
    }

    public static function enqueue_inline_player(): void {
        self::enqueue_base();
        wp_enqueue_script( 'wavesurfer' );
        wp_enqueue_script( 'km-salle-ecoute-inline-player' );
    }

    public static function enqueue(): void {
        if ( ! self::is_km() ) return;

        self::enqueue_base();
        wp_enqueue_script( 'km-salle-ecoute-player' );

        $post = get_post();
        if ( $post && has_shortcode( $post->post_content, 'km_salle_ecoute_player' ) ) {
            self::enqueue_inline_player();
        }
    }

    /** Textes de l'interface JS (traduisibles). */
    private static function i18n(): array {
        return [
            'listen'       => __( 'Écouter', 'km-salle-ecoute' ),
            'pause'        => __( 'Pause', 'km-salle-ecoute' ),
            'resume'       => __( 'Reprendre', 'km-salle-ecoute' ),
            'checking'     => __( 'Vérification…', 'km-salle-ecoute' ),
            'granted'      => __( 'Accès autorisé', 'km-salle-ecoute' ),
            'emptyPwd'     => __( 'Veuillez saisir le mot de passe.', 'km-salle-ecoute' ),
            'badPwd'       => __( 'Mot de passe incorrect.', 'km-salle-ecoute' ),
            'netError'     => __( 'Erreur réseau. Réessayez.', 'km-salle-ecoute' ),
            'showPwd'      => __( 'Afficher le mot de passe', 'km-salle-ecoute' ),
            'hidePwd'      => __( 'Masquer le mot de passe', 'km-salle-ecoute' ),
            'playError'    => __( 'Lecture impossible — vérifiez votre connexion.', 'km-salle-ecoute' ),
            'retry'        => __( 'Réessayer', 'km-salle-ecoute' ),
            'resumedAt'    => __( 'Reprise à %s', 'km-salle-ecoute' ),
            'fromStart'    => __( 'Depuis le début', 'km-salle-ecoute' ),
            'of'           => __( 'sur', 'km-salle-ecoute' ),
            'mute'         => __( 'Couper le son', 'km-salle-ecoute' ),
            'unmute'       => __( 'Rétablir le son', 'km-salle-ecoute' ),
            'speed'        => __( 'Vitesse de lecture : %s', 'km-salle-ecoute' ),
            'repeatOff'    => __( 'Répétition : désactivée', 'km-salle-ecoute' ),
            'repeatAll'    => __( 'Répétition : toutes les pistes', 'km-salle-ecoute' ),
            'repeatOne'    => __( 'Répétition : piste en boucle', 'km-salle-ecoute' ),
            'shuffleOn'    => __( 'Lecture aléatoire : activée', 'km-salle-ecoute' ),
            'shuffleOff'   => __( 'Lecture aléatoire : désactivée', 'km-salle-ecoute' ),
            'session'      => __( '%d session', 'km-salle-ecoute' ),
            'sessions'     => __( '%d sessions', 'km-salle-ecoute' ),
            'noLyrics'     => __( 'Pas de paroles pour ce titre.', 'km-salle-ecoute' ),
            'volume'       => __( 'Volume : %s %%', 'km-salle-ecoute' ),
        ];
    }

    /**
     * Applique les couleurs choisies dans Réglages (jusqu'ici enregistrées mais
     * jamais utilisées par le design).
     */
    private static function color_css(): string {
        $accent = sanitize_hex_color( (string) get_option( 'kse_couleur_accent', '' ) );
        $prim   = sanitize_hex_color( (string) get_option( 'kse_couleur_primaire', '' ) );
        $css    = '';
        if ( $accent && strtolower( $accent ) !== '#e94560' ) {
            $css .= '--km-accent:' . $accent . ';--km-accent-hi:' . self::shade( $accent, 0.18 ) . ';--km-accent2:' . self::shade( $accent, -0.22 ) . ';';
            $rgb  = self::rgb( $accent );
            $css .= '--km-accent-rgb:' . implode( ',', $rgb ) . ';';
        }
        if ( $prim && strtolower( $prim ) !== '#1a1a2e' ) {
            $css .= '--km-cover:' . $prim . ';';
        }
        return $css ? '.km-scope{' . $css . '}' : '';
    }

    private static function rgb( string $hex ): array {
        $hex = ltrim( $hex, '#' );
        if ( 3 === strlen( $hex ) ) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
    }

    /** Éclaircit (>0) ou assombrit (<0) une couleur hexadécimale. */
    private static function shade( string $hex, float $amount ): string {
        $out = '#';
        foreach ( self::rgb( $hex ) as $c ) {
            $c    = $amount >= 0 ? $c + ( 255 - $c ) * $amount : $c * ( 1 + $amount );
            $out .= str_pad( dechex( (int) round( max( 0, min( 255, $c ) ) ) ), 2, '0', STR_PAD_LEFT );
        }
        return $out;
    }

    public static function fullwidth_css(): void {
        if ( ! self::is_fullwidth_context() ) return;
        ?>
        <style id="km-fw">
        /* KM SALLE D'ÉCOUTE — Force full-width */
        body.km-fullwidth-page #page,
        body.km-fullwidth-page .site,
        body.km-fullwidth-page .ast-container,
        body.km-fullwidth-page .site-content .ast-container,
        body.km-fullwidth-page .wp-site-blocks { max-width:100%!important; padding-left:0!important; padding-right:0!important; }

        body.km-fullwidth-page #primary,
        body.km-fullwidth-page #content,
        body.km-fullwidth-page .site-content,
        body.km-fullwidth-page main,
        body.km-fullwidth-page .site-main,
        body.km-fullwidth-page .entry-content,
        body.km-fullwidth-page .inside-article,
        body.km-fullwidth-page .post-content,
        body.km-fullwidth-page .page-content {
            max-width:100%!important; width:100%!important;
            padding:0!important; margin:0!important; float:none!important;
        }
        body.km-fullwidth-page #secondary,
        body.km-fullwidth-page .sidebar,
        body.km-fullwidth-page .widget-area,
        body.km-fullwidth-page .entry-header,
        body.km-fullwidth-page .page-header { display:none!important; }

        body.km-fullwidth-page { background:#09090f!important; }

        /* Elementor */
        body.km-fullwidth-page .elementor-section-boxed .elementor-container { max-width:100%!important; }
        </style>
        <?php
    }
}
