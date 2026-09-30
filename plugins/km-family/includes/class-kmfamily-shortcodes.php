<?php
/**
 * Shortcodes KM Family v2.1
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Shortcodes {

    public static function init() {
        add_shortcode( 'kmfamily_paliers',           array( __CLASS__, 'paliers_artiste' ) );
        add_shortcode( 'kmfamily_paliers_all',       array( __CLASS__, 'paliers_all' ) );
        add_shortcode( 'kmfamily_dashboard',         array( __CLASS__, 'dashboard' ) );
        add_shortcode( 'kmfamily_profile',           array( __CLASS__, 'profile' ) );
        add_shortcode( 'kmfamily_payment_pending',   array( __CLASS__, 'payment_pending' ) );
        add_shortcode( 'kmfamily_payment_success',   array( __CLASS__, 'payment_success' ) );
        add_shortcode( 'kmfamily_exclusive_feed',    array( __CLASS__, 'exclusive_feed' ) );
        add_shortcode( 'kmfamily_login',             array( __CLASS__, 'login' ) );
        add_shortcode( 'kmfamily_badge',             array( __CLASS__, 'badge' ) );
        add_shortcode( 'kmfamily_emotional_message', array( __CLASS__, 'emotional_message' ) );
        add_shortcode( 'kmfamily_home_banner',       array( __CLASS__, 'home_banner' ) );

        add_action( 'wp_head', array( __CLASS__, 'print_full_bleed_css' ) );
    }

    /**
     * BUGFIX AFFICHAGE : nos sections (paliers, dashboard...) sont conçues en plein écran avec
     * un fond noir dès leur premier pixel — mais elles sont injectées via shortcode dans une
     * Page WordPress classique, dont le thème actif affiche par défaut un bandeau de titre/
     * breadcrumb (souvent invisible visuellement mais qui garde sa hauteur en blanc) entre le
     * header du site et le contenu. Résultat : une bande blanche entre le menu et nos sections
     * sombres. On neutralise ce bandeau, uniquement sur nos pages KM Family, en ciblant les
     * classes de bandeau de titre les plus courantes (thèmes Astra, GeneratePress, OceanWP,
     * Kadence, Divi, Twenty Twenty-*, Elementor). Si le thème actif utilise un sélecteur
     * différent, il restera à l'ajouter ici après inspection du DOM.
     */
    public static function print_full_bleed_css() {
        if ( ! is_page() ) return;

        $km_page_ids = array_filter( array_map( 'absint', array(
            get_option( 'kmfamily_page_paliers' ),
            get_option( 'kmfamily_page_dashboard' ),
            get_option( 'kmfamily_page_profile' ),
            get_option( 'kmfamily_page_login' ),
            get_option( 'kmfamily_page_payment' ),
            get_option( 'kmfamily_page_payment_success' ),
        ) ) );

        if ( ! in_array( get_queried_object_id(), $km_page_ids, true ) ) return;
        ?>
        <style id="kmfamily-full-bleed">
            .page-header, .entry-header, .ast-container > .page-header, .site-content .page-title,
            .elementor-page-title, header.entry-header, .breadcrumbs, .ast-breadcrumbs-container {
                display: none !important;
            }
            .site-content, .content-area, #primary, #main, .ast-container, main#main {
                padding-top: 0 !important;
                margin-top: 0 !important;
            }
        </style>
        <?php
    }

    public static function paliers_artiste( $atts ) {
        $atts = shortcode_atts( array( 'artiste_id' => 0 ), $atts );

        $artiste_id = absint( $atts['artiste_id'] );
        if ( ! $artiste_id ) $artiste_id = get_queried_object_id();
        if ( ! $artiste_id || get_post_type( $artiste_id ) !== 'nos-artistes' ) return '';

        $active = get_field( 'kmfamily_active', $artiste_id );
        if ( $active === null ) $active = true;
        if ( ! $active ) return '';

        $paliers_actifs = get_field( 'paliers_actifs', $artiste_id );
        if ( empty( $paliers_actifs ) || ! is_array( $paliers_actifs ) ) {
            $paliers_actifs = KMFamily_Paliers::LEVELS;
        }

        $message_accroche  = get_field( 'message_accroche', $artiste_id );
        $avantages_custom  = get_field( 'avantages_custom', $artiste_id );
        $artiste_name      = get_the_title( $artiste_id );

        $all_paliers = KMFamily_Paliers::get_all();
        $paliers = array();
        foreach ( $paliers_actifs as $slug ) {
            if ( isset( $all_paliers[ $slug ] ) ) {
                $paliers[ $slug ] = $all_paliers[ $slug ];
            }
        }

        ob_start();
        include KMFamily_Access::locate_template( 'paliers-artiste.php' );
        return ob_get_clean();
    }

    public static function paliers_all( $atts ) {
        // Si un artiste_id est passé en query string, basculer en mode "paliers de cet artiste"
        $requested_artiste = isset( $_GET['artiste_id'] ) ? absint( $_GET['artiste_id'] ) : 0;
        if ( $requested_artiste && get_post_type( $requested_artiste ) === 'nos-artistes' ) {
            return self::paliers_artiste( array( 'artiste_id' => $requested_artiste ) );
        }

        $artistes = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        ob_start();
        include KMFamily_Access::locate_template( 'paliers-all.php' );
        return ob_get_clean();
    }

    /**
     * Bannière promotionnelle KM Family pour la page d'accueil : accroche + stat social proof
     * + avatars des artistes actifs + CTA vers la page "Rejoindre la KM Family".
     * Usage : [kmfamily_home_banner]
     */
    public static function home_banner( $atts ) {
        $atts = shortcode_atts( array(
            'titre' => "Le mouvement a besoin de ses&nbsp;<span>piliers</span>",
            'texte' => "Chaque contribution finance la prochaine production, le prochain clip, le prochain live. Rejoins la KM Family et entre dans l'intimité de tes artistes préférés.",
        ), $atts, 'kmfamily_home_banner' );

        // BUGFIX (avant même publication) : un meta_query direct sur 'kmfamily_active' exclurait
        // à tort les artistes qui n'ont jamais touché ce réglage (pas de ligne en base), alors
        // que le champ ACF true_false a une valeur par défaut à 1 (actif). Le reste du plugin
        // (paliers-all.php, dashboard.php) évite déjà ce piège en filtrant avec get_field()
        // après coup — on fait pareil ici pour rester cohérent et correct.
        $tous_les_artistes = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => -1,
            'orderby'        => 'rand',
        ) );

        $artistes_actifs = array();
        foreach ( $tous_les_artistes as $a ) {
            if ( get_field( 'kmfamily_active', $a->ID ) ) $artistes_actifs[] = $a;
        }

        $total_artistes = count( $artistes_actifs );
        $artistes       = array_slice( $artistes_actifs, 0, 6 );

        $membres_actifs = class_exists( 'KMFamily_Subscriptions' ) ? KMFamily_Subscriptions::count_total_active_members() : 0;

        $paliers_page_id = get_option( 'kmfamily_page_paliers' );
        $cta_url = $paliers_page_id ? get_permalink( $paliers_page_id ) : home_url( '/rejoindre-km-family/' );

        if ( ! $artistes && ! $membres_actifs ) return ''; // Rien à montrer tant qu'aucun artiste n'est actif.

        ob_start();
        include KMFamily_Access::locate_template( 'home-banner.php' );
        return ob_get_clean();
    }

    public static function dashboard( $atts ) {
        if ( ! is_user_logged_in() ) {
            $login_page = get_option( 'kmfamily_page_login' );
            $login_url  = $login_page ? get_permalink( $login_page ) : wp_login_url( get_permalink() );
            return sprintf(
                '<div class="kmfamily-notice"><p>%s</p><p><a href="%s" class="kmfamily-btn kmfamily-btn-primary">%s</a></p></div>',
                esc_html__( 'Vous devez être connecté pour accéder à votre espace KM Family.', 'km-family' ),
                esc_url( $login_url ),
                esc_html__( 'Se connecter', 'km-family' )
            );
        }

        $user_id = get_current_user_id();
        $subs    = KMFamily_Subscriptions::get_user_subscriptions( $user_id );
        $history = KMFamily_Subscriptions::get_user_history( $user_id );
        $user    = wp_get_current_user();
        $badge   = KMFamily_Badges::get_user_badge( $user_id );

        ob_start();
        include KMFamily_Access::locate_template( 'dashboard.php' );
        return ob_get_clean();
    }

    /**
     * [kmfamily_profile] — page de modification du profil
     */
    public static function profile( $atts ) {
        if ( ! is_user_logged_in() ) {
            $login_page = get_option( 'kmfamily_page_login' );
            $login_url  = $login_page ? get_permalink( $login_page ) : wp_login_url( get_permalink() );
            return sprintf(
                '<div class="kmfamily-notice"><p>%s</p><p><a href="%s" class="kmfamily-btn kmfamily-btn-primary">%s</a></p></div>',
                esc_html__( 'Connectez-vous pour modifier votre profil.', 'km-family' ),
                esc_url( $login_url ),
                esc_html__( 'Se connecter', 'km-family' )
            );
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        ob_start();
        include KMFamily_Access::locate_template( 'profile.php' );
        return ob_get_clean();
    }

    public static function login( $atts ) {
        // Déjà connecté(e) : normalement KMFamily_Auth::redirect_logged_in_from_login_page()
        // a déjà redirigé (template_redirect, avant tout affichage). Ce bloc n'est qu'un
        // filet de sécurité (shortcode placé sur une autre page / constructeur de page) :
        // à ce stade les en-têtes sont déjà partis, un wp_safe_redirect() échouerait donc
        // silencieusement — on redirige côté navigateur, en respectant le paramètre "next".
        if ( is_user_logged_in() && ! is_admin() && ! isset( $_GET['elementor-preview'] ) && ! isset( $_GET['preview'] ) ) {
            $target = KMFamily_Auth::get_post_login_redirect( isset( $_GET['next'] ) ? wp_unslash( $_GET['next'] ) : '' );

            if ( ! headers_sent() ) {
                wp_safe_redirect( $target );
                exit;
            }

            return '<div class="kmfamily-notice"><p>' . esc_html__( 'Vous êtes déjà connecté(e). Redirection…', 'km-family' ) . '</p>'
                . '<p><a href="' . esc_url( $target ) . '" class="kmfamily-btn kmfamily-btn-primary">' . esc_html__( 'Continuer', 'km-family' ) . '</a></p></div>'
                . '<script>window.location.replace(' . wp_json_encode( $target ) . ');</script>';
        }

        $kmfamily_login_embedded = true; // lu par le gabarit : pas de get_header()/get_footer()
        ob_start();
        include KMFamily_Access::locate_template( 'login.php' );
        return ob_get_clean();
    }

    public static function badge( $atts ) {
        $atts = shortcode_atts( array(
            'user_id'    => 0,
            'size'       => 'medium',
            'show_label' => 'yes',
        ), $atts );

        $user_id = absint( $atts['user_id'] );
        if ( ! $user_id ) $user_id = get_current_user_id();
        if ( ! $user_id ) return '';

        $badge_slug = KMFamily_Badges::get_user_badge( $user_id );
        if ( ! $badge_slug ) return '';

        return KMFamily_Badges::render( $badge_slug, $atts['size'], $atts['show_label'] === 'yes' );
    }

    public static function emotional_message( $atts ) {
        $atts = shortcode_atts( array( 'format' => 'rich' ), $atts );
        if ( $atts['format'] === 'short' ) {
            return '<div class="kmfamily-emotional-message">' . wp_kses_post( KMFamily_Notifications::get_emotional_message() ) . '</div>';
        }
        return '<div class="kmfamily-emotional-message kmfamily-emotional-message--rich">' . KMFamily_Notifications::get_emotional_message_rich() . '</div>';
    }

    public static function payment_pending( $atts ) {
        $transaction_id = sanitize_text_field( $_GET['transaction_id'] ?? '' );
        ob_start();
        ?>
        <div class="kmfamily-page-wrap">
            <div class="kmfamily-payment-status kmfamily-payment-status--pending">
                <div class="kmfamily-spinner"></div>
                <h2><?php esc_html_e( 'Paiement en cours de traitement...', 'km-family' ); ?></h2>
                <p><?php esc_html_e( 'Merci de patienter pendant que nous confirmons votre paiement Mobile Money.', 'km-family' ); ?></p>
                <?php if ( $transaction_id ) : ?>
                    <p class="kmfamily-transaction-id">
                        <?php esc_html_e( 'Réf. transaction :', 'km-family' ); ?>
                        <code><?php echo esc_html( $transaction_id ); ?></code>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function payment_success( $atts ) {
        ob_start();
        ?>
        <div class="kmfamily-page-wrap">
            <div class="kmfamily-payment-status kmfamily-payment-status--success">
                <div class="kmfamily-success-icon">✓</div>
                <h2><?php esc_html_e( 'Bienvenue dans la KM Family !', 'km-family' ); ?></h2>
                <p class="kmfamily-payment-status__emotional">
                    <?php echo esc_html( KMFamily_Notifications::get_emotional_message() ); ?>
                </p>
                <?php
                $dashboard_page = get_option( 'kmfamily_page_dashboard' );
                if ( $dashboard_page ) :
                ?>
                    <a href="<?php echo esc_url( get_permalink( $dashboard_page ) ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--lg">
                        <?php esc_html_e( 'Accéder à mon espace', 'km-family' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function exclusive_feed( $atts ) {
        $atts = shortcode_atts( array(
            'artiste_id' => 0,
            'limit'      => 6,
        ), $atts );

        $artiste_id = absint( $atts['artiste_id'] );
        if ( ! $artiste_id ) $artiste_id = get_queried_object_id();

        $args = array(
            'post_type'      => KMFamily_CPT::POST_TYPE,
            'posts_per_page' => absint( $atts['limit'] ),
            'meta_query'     => array(
                array(
                    'key'     => 'artiste_lie',
                    'value'   => '"' . $artiste_id . '"',
                    'compare' => 'LIKE',
                ),
            ),
        );

        $query = new WP_Query( $args );
        if ( ! $query->have_posts() ) {
            return '<p class="kmfamily-notice">' . esc_html__( 'Aucun contenu exclusif pour cet artiste pour le moment.', 'km-family' ) . '</p>';
        }

        ob_start();
        echo '<div class="kmfamily-exclusive-grid kmfamily-exclusive-grid--feed">';
        while ( $query->have_posts() ) {
            $query->the_post();
            include KMFamily_Access::locate_template( 'card-contenu-exclusif.php' );
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }
}
