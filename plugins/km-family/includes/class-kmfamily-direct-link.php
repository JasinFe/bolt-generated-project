<?php
/**
 * KM Family — Liens directs de soutien artiste + retour après connexion
 *
 * PROBLÈMES TRAITÉS (v3.3.0)
 * --------------------------
 * 1. Après « Soutenir / Rejoindre » → connexion ou création de compte, la personne
 *    atterrissait dans son espace membre au lieu de revenir sur le soutien de
 *    l'artiste choisi : le bouton « Rejoindre » des paliers pointait vers la page de
 *    connexion SANS paramètre de retour (« next »).
 * 2. Aucun lien direct de soutien n'existait par artiste (lien à partager en bio
 *    Instagram, story, WhatsApp, flyer, QR code…).
 *
 * FONCTIONNEMENT
 * --------------
 * Lien direct : https://votre-site.com/soutenir/{slug-artiste}/
 *   (repli sans permaliens : https://votre-site.com/?kmfamily_soutenir={id})
 *   - visiteur déconnecté → page de connexion / création de compte, avec retour
 *     automatique vers la page de soutien de CET artiste une fois connecté(e) ;
 *   - visiteur déjà connecté → directement la page de soutien de l'artiste.
 *
 * Le lien est généré automatiquement pour chaque artiste (CPT « nos-artistes ») dès sa
 * création, et rattrapé une fois pour les artistes déjà existants.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Direct_Link {

    const POST_TYPE        = 'nos-artistes';
    const QUERY_VAR        = 'kmfamily_soutenir';
    const META_KEY         = '_kmfamily_direct_link';
    const OPT_BACKFILL     = 'kmfamily_direct_links_backfill';
    const OPT_REWRITE      = 'kmfamily_direct_link_rewrite';
    const BACKFILL_VERSION = '1';
    const RULES_VERSION    = '1';

    public static function init() {
        // Règle de réécriture /soutenir/{slug}/ + variable de requête.
        add_action( 'init',       array( __CLASS__, 'register_rewrite' ), 5 );
        add_action( 'init',       array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
        add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );

        // Traitement du clic sur un lien direct (avant tout affichage).
        add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 1 );

        // Aperçu de partage (Open Graph) sur la page de soutien d'un artiste.
        add_action( 'wp_head', array( __CLASS__, 'support_page_meta' ), 1 );

        // Génération automatique à la création / modification d'un artiste.
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'on_save_artist' ), 20, 3 );
        add_action( 'init', array( __CLASS__, 'maybe_backfill' ), 30 );

        // Admin : encart sur la fiche artiste, colonne dans la liste, page dédiée.
        add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',       array( __CLASS__, 'add_column' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 14 );
        add_action( 'admin_post_kmfamily_resync_direct_links', array( __CLASS__, 'handle_resync' ) );
        add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_copy_script' ) );

        // Shortcode bouton : [kmfamily_soutenir]
        add_shortcode( 'kmfamily_soutenir', array( __CLASS__, 'shortcode' ) );
    }

    // ==========================================================
    // URLS
    // ==========================================================

    /** Segment d'URL du lien direct (filtrable). */
    public static function get_base() {
        $base = apply_filters( 'kmfamily_direct_link_base', 'soutenir' );
        $base = sanitize_title( $base );
        return $base ?: 'soutenir';
    }

    /**
     * Page « de soutien » d'un artiste = page « Rejoindre la KM Family » qui bascule
     * sur les paliers de l'artiste demandé (?artiste_id=). Repli : fiche artiste.
     *
     * @param int   $artiste_id
     * @param array $extra  Paramètres additionnels (ex. palier, period).
     */
    public static function get_support_url( $artiste_id, $extra = array() ) {
        $artiste_id = absint( $artiste_id );
        $page_id    = (int) get_option( 'kmfamily_page_paliers' );
        $base       = $page_id ? get_permalink( $page_id ) : '';

        if ( ! $base ) {
            $url = get_permalink( $artiste_id );
            return $url ? $url . '#paliers' : home_url( '/' );
        }

        $args = array( 'artiste_id' => $artiste_id );
        foreach ( (array) $extra as $k => $v ) {
            if ( $v !== '' && $v !== null ) $args[ sanitize_key( $k ) ] = $v;
        }
        return add_query_arg( $args, $base );
    }

    /**
     * Lien direct de soutien à partager. Toujours recalculé à la volée (jamais lu depuis
     * la méta) pour rester juste après un changement de domaine, de permaliens ou de slug.
     */
    public static function get_url( $artiste_id ) {
        $artiste_id = absint( $artiste_id );
        $post       = $artiste_id ? get_post( $artiste_id ) : null;
        if ( ! $post ) return '';

        $slug = ( $post->post_status === 'publish' ) ? $post->post_name : '';

        if ( get_option( 'permalink_structure' ) ) {
            return home_url( '/' . self::get_base() . '/' . ( $slug !== '' ? $slug : $artiste_id ) . '/' );
        }
        return add_query_arg( self::QUERY_VAR, $artiste_id, home_url( '/' ) );
    }

    /**
     * URL de la page de connexion KM Family avec retour automatique.
     *
     * @param string $next  URL de retour (validée plus tard par safe_next()).
     * @param string $tab   '' | 'register' | 'forgot'
     */
    public static function get_login_url( $next = '', $tab = '' ) {
        $page = get_option( 'kmfamily_page_login' );
        $base = $page ? get_permalink( $page ) : '';

        if ( ! $base ) {
            return wp_login_url( $next );
        }

        $url = $base;
        if ( $tab === 'register' || $tab === 'forgot' ) {
            $url = add_query_arg( 'tab', $tab, $url );
        }
        if ( $next ) {
            // add_query_arg n'encode pas les valeurs : on encode nous-mêmes.
            $url = add_query_arg( 'next', rawurlencode( $next ), $url );
        }
        return $url;
    }

    // ==========================================================
    // VALIDATION DU RETOUR (« next »)
    // ==========================================================

    /**
     * Valide une URL de retour. Retourne l'URL si elle est sûre, sinon $fallback.
     * Sûre = même site (anti open-redirect), pas wp-login.php, et pas la page de
     * connexion elle-même (sinon boucle : on se reconnecterait à l'infini).
     */
    public static function safe_next( $raw, $fallback = '' ) {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( $raw === '' ) return $fallback;

        $url = esc_url_raw( $raw );
        if ( $url === '' ) return $fallback;

        $valid = wp_validate_redirect( $url, '' );
        if ( $valid === '' ) return $fallback;

        if ( strpos( $valid, 'wp-login.php' ) !== false ) return $fallback;

        $login_page = (int) get_option( 'kmfamily_page_login' );
        if ( $login_page ) {
            $login_path = untrailingslashit( (string) wp_parse_url( get_permalink( $login_page ), PHP_URL_PATH ) );
            $next_path  = untrailingslashit( (string) wp_parse_url( $valid, PHP_URL_PATH ) );
            if ( $login_path !== '' && $login_path === $next_path ) return $fallback;
        }

        return $valid;
    }

    // ==========================================================
    // ARTISTES
    // ==========================================================

    /** Un artiste peut-il recevoir du soutien (publié + KM Family activé) ? */
    public static function is_supportable( $artiste_id ) {
        $artiste_id = absint( $artiste_id );
        if ( ! $artiste_id ) return false;
        if ( get_post_type( $artiste_id ) !== self::POST_TYPE ) return false;
        if ( get_post_status( $artiste_id ) !== 'publish' ) return false;

        $active = function_exists( 'get_field' ) ? get_field( 'kmfamily_active', $artiste_id ) : null;
        return $active === null ? true : (bool) $active;
    }

    /** Identifiant (numérique) ou slug → ID d'artiste. 0 si introuvable. */
    public static function resolve_artist( $key ) {
        $key = is_scalar( $key ) ? trim( (string) $key ) : '';
        if ( $key === '' ) return 0;

        if ( ctype_digit( $key ) ) {
            return get_post_type( (int) $key ) === self::POST_TYPE ? (int) $key : 0;
        }

        $slug = sanitize_title( $key );
        if ( $slug === '' ) return 0;

        $found = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'name'           => $slug,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        if ( $found ) return (int) $found[0];

        // Ancien slug (WordPress mémorise les anciens slugs dans _wp_old_slug) :
        // un lien partagé avant un renommage d'artiste continue ainsi de fonctionner.
        $old = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => '_wp_old_slug',
            'meta_value'     => $slug,
        ) );
        return $old ? (int) $old[0] : 0;
    }

    /**
     * Retrouve l'artiste visé par une URL du site : page de soutien (?artiste_id=)
     * ou fiche artiste. Sert à reprendre le parcours quand un bouton « Se connecter »
     * du thème pointe vers la page de connexion sans paramètre de retour.
     */
    public static function artist_from_url( $url ) {
        if ( ! is_string( $url ) || $url === '' ) return 0;

        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        if ( $query !== '' ) {
            parse_str( $query, $args );
            if ( ! empty( $args['artiste_id'] ) ) {
                $id = absint( $args['artiste_id'] );
                if ( $id && get_post_type( $id ) === self::POST_TYPE ) return $id;
            }
        }

        // Uniquement les URL de CE site.
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        if ( $url_host && $home_host && strcasecmp( $url_host, $home_host ) !== 0 ) return 0;

        $post_id = url_to_postid( $url );
        if ( $post_id && get_post_type( $post_id ) === self::POST_TYPE ) return (int) $post_id;

        return 0;
    }

    // ==========================================================
    // REWRITE + REQUÊTE
    // ==========================================================

    public static function register_rewrite() {
        add_rewrite_rule(
            '^' . preg_quote( self::get_base(), '#' ) . '/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /** La règle est nouvelle : un flush unique suffit (voir aussi le bouton dans l'admin). */
    public static function maybe_flush_rewrite() {
        $stored = get_option( self::OPT_REWRITE );
        $wanted = self::RULES_VERSION . '|' . self::get_base();
        if ( $stored !== $wanted ) {
            flush_rewrite_rules( false );
            update_option( self::OPT_REWRITE, $wanted );
        }
    }

    public static function add_query_vars( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Clic sur un lien direct.
     * - connecté(e)      → page de soutien de l'artiste
     * - non connecté(e)  → connexion / création de compte, puis retour page de soutien
     */
    public static function handle_request() {
        $key = get_query_var( self::QUERY_VAR );
        if ( $key === '' || $key === null || $key === false ) return;

        // Réponse dépendante de la session : jamais mise en cache pleine page.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();

        $artiste_id = self::resolve_artist( $key );

        if ( ! $artiste_id || ! self::is_supportable( $artiste_id ) ) {
            // Artiste inconnu, désactivé ou non publié : on renvoie vers la liste des
            // artistes plutôt que d'afficher un 404 à quelqu'un qui voulait donner.
            $page_id  = (int) get_option( 'kmfamily_page_paliers' );
            $fallback = $page_id ? get_permalink( $page_id ) : home_url( '/' );
            wp_safe_redirect( $fallback );
            exit;
        }

        /**
         * APERÇU DE PARTAGE (v3.3.2)
         * --------------------------
         * Un robot d'aperçu (WhatsApp, Facebook, Messenger, Telegram…) n'a évidemment
         * aucune session : il suivait donc la redirection « visiteur déconnecté » et
         * lisait la PAGE DE CONNEXION. Résultat, tout lien de soutien partagé affichait
         * la même vignette générique — celle du formulaire de connexion — quel que soit
         * l'artiste, au lieu de sa photo. Comme ces liens sont justement faits pour être
         * collés en story, en bio ou dans une conversation WhatsApp, c'est l'endroit où
         * la photo de l'artiste compte le plus.
         *
         * On sert donc à ces robots — et à eux seuls — une page minimale portant les
         * balises Open Graph de l'artiste. Les visiteurs humains continuent d'être
         * redirigés exactement comme avant.
         */
        if ( self::is_social_crawler() ) {
            self::render_share_preview( $artiste_id );
            exit;
        }

        // Présélection optionnelle transmise dans le lien (?palier=or&period=yearly).
        $extra = array();
        if ( isset( $_GET['palier'] ) ) {
            $palier = sanitize_key( wp_unslash( $_GET['palier'] ) );
            if ( $palier && class_exists( 'KMFamily_Paliers' ) && KMFamily_Paliers::exists( $palier ) ) {
                $extra['palier'] = $palier;
            }
        }
        if ( isset( $_GET['period'] ) ) {
            $period = sanitize_key( wp_unslash( $_GET['period'] ) );
            if ( $period && class_exists( 'KMFamily_Periodicity' ) && KMFamily_Periodicity::exists( $period ) ) {
                $extra['period'] = $period;
            }
        }

        $target = self::get_support_url( $artiste_id, $extra );

        if ( is_user_logged_in() ) {
            wp_safe_redirect( $target );
            exit;
        }

        // ?tab=register sur le lien direct ouvre directement l'onglet « Inscription ».
        $tab = ( isset( $_GET['tab'] ) && sanitize_key( wp_unslash( $_GET['tab'] ) ) === 'register' ) ? 'register' : '';

        wp_safe_redirect( self::get_login_url( $target, $tab ) );
        exit;
    }

    // ==========================================================
    // APERÇU DE PARTAGE (Open Graph)
    // ==========================================================

    /**
     * Robot d'aperçu de lien ? Volontairement limité aux robots de PRÉVISUALISATION
     * (messageries, réseaux sociaux), jamais aux moteurs de recherche : servir à
     * Googlebot un contenu différent de celui d'un visiteur, c'est du cloaking, et ça
     * se paie au référencement. Les robots ci-dessous, eux, ne peuvent par nature pas
     * se connecter — leur donner les métadonnées de la page cible est l'usage prévu.
     */
    private static function is_social_crawler() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
        if ( $ua === '' ) return false;

        $robots = array(
            'facebookexternalhit', 'facebookcatalog', 'facebot',
            'whatsapp', 'telegrambot', 'viber', 'line-podcast',
            'twitterbot', 'linkedinbot', 'slackbot', 'slack-imgproxy',
            'discordbot', 'pinterest', 'redditbot', 'skypeuripreview',
            'embedly', 'quora link preview', 'vkshare', 'snapchat',
            'instagram', 'tiktok', 'iframely', 'nuzzel', 'outbrain',
        );

        foreach ( $robots as $robot ) {
            if ( strpos( $ua, $robot ) !== false ) return true;
        }
        return false;
    }

    /**
     * Image de l'aperçu : la photo de profil de l'artiste, c'est-à-dire son image mise
     * en avant — la même que celle utilisée par la couverture de la page de soutien,
     * l'espace membre et la liste des artistes. Aucun réglage supplémentaire à saisir.
     *
     * On vise la taille 'large' plutôt que l'originale : WhatsApp abandonne l'aperçu
     * au-delà de quelques centaines de kilo-octets, et une photo d'artiste sortie d'un
     * téléphone pèse souvent plusieurs méga-octets. Repli sur le logo du site si
     * l'artiste n'a pas encore de photo — mieux vaut la marque qu'aucune vignette.
     */
    private static function share_image( $artiste_id ) {
        $ids = array();

        $thumb_id = get_post_thumbnail_id( $artiste_id );
        if ( $thumb_id ) $ids[] = (int) $thumb_id;

        $logo_id = (int) get_theme_mod( 'custom_logo' );
        if ( $logo_id ) $ids[] = $logo_id;

        foreach ( $ids as $id ) {
            foreach ( array( 'large', 'medium_large', 'full' ) as $taille ) {
                $src = wp_get_attachment_image_src( $id, $taille );
                if ( $src && ! empty( $src[0] ) ) {
                    return array(
                        'url'    => $src[0],
                        'width'  => (int) ( $src[1] ?? 0 ),
                        'height' => (int) ( $src[2] ?? 0 ),
                        'alt'    => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
                    );
                }
            }
        }

        return null;
    }

    /** Accroche de l'aperçu : le message personnel de l'artiste, sinon un texte du label. */
    private static function share_description( $artiste_id, $artiste_name ) {
        $accroche = function_exists( 'get_field' ) ? get_field( 'message_accroche', $artiste_id ) : '';
        $accroche = trim( wp_strip_all_tags( (string) $accroche ) );

        if ( $accroche !== '' ) {
            return wp_trim_words( $accroche, 32, '…' );
        }

        return sprintf(
            /* translators: %s: nom de l'artiste */
            __( 'Rejoignez la KM Family et soutenez %s : contenus exclusifs, coulisses et avantages réservés aux membres.', 'km-family' ),
            $artiste_name
        );
    }

    /**
     * Balises Open Graph / Twitter Card d'un artiste. Partagées par les deux surfaces :
     * la page d'aperçu servie aux robots sur /soutenir/{slug}/, et la page de soutien
     * elle-même — qu'un membre connecté peut très bien copier depuis sa barre d'adresse
     * et partager telle quelle.
     */
    private static function render_og_tags( $artiste_id, $lien ) {
        $artiste_name = get_the_title( $artiste_id );
        $description  = self::share_description( $artiste_id, $artiste_name );
        $image        = self::share_image( $artiste_id );
        $titre        = sprintf(
            /* translators: %s: nom de l'artiste */
            __( 'Soutenir %s — KM Family', 'km-family' ),
            $artiste_name
        );
        $alt = ( $image && $image['alt'] !== '' ) ? $image['alt'] : $artiste_name;
        ?>
<meta property="og:type"        content="profile">
<meta property="og:site_name"   content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
<meta property="og:locale"      content="<?php echo esc_attr( get_locale() ); ?>">
<meta property="og:url"         content="<?php echo esc_url( $lien ); ?>">
<meta property="og:title"       content="<?php echo esc_attr( $titre ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $description ); ?>">
<?php if ( $image ) : ?>
<meta property="og:image"            content="<?php echo esc_url( $image['url'] ); ?>">
<meta property="og:image:secure_url" content="<?php echo esc_url( set_url_scheme( $image['url'], 'https' ) ); ?>">
<?php if ( $image['width'] && $image['height'] ) : ?>
<meta property="og:image:width"  content="<?php echo esc_attr( $image['width'] ); ?>">
<meta property="og:image:height" content="<?php echo esc_attr( $image['height'] ); ?>">
<?php endif; ?>
<meta property="og:image:alt" content="<?php echo esc_attr( $alt ); ?>">
<?php endif; ?>
<meta name="twitter:card"        content="<?php echo $image ? 'summary_large_image' : 'summary'; ?>">
<meta name="twitter:title"       content="<?php echo esc_attr( $titre ); ?>">
<meta name="twitter:description" content="<?php echo esc_attr( $description ); ?>">
<?php if ( $image ) : ?>
<meta name="twitter:image"     content="<?php echo esc_url( $image['url'] ); ?>">
<meta name="twitter:image:alt" content="<?php echo esc_attr( $alt ); ?>">
<?php endif; ?>
        <?php
    }

    /**
     * Page de soutien (?artiste_id=…) : elle sert le MÊME gabarit pour tous les artistes,
     * donc une extension SEO y produit la même vignette générique pour chacun. On émet
     * nos balises en tout début de <head> (priorité 1) : les robots d'aperçu retiennent
     * la première occurrence rencontrée.
     *
     * Filtre d'échappement si une extension SEO gère déjà l'Open Graph par artiste :
     *     add_filter( 'kmfamily_support_page_og', '__return_false' );
     */
    public static function support_page_meta() {
        if ( is_admin() ) return;
        if ( ! apply_filters( 'kmfamily_support_page_og', true ) ) return;

        $artiste_id = isset( $_GET['artiste_id'] ) ? absint( $_GET['artiste_id'] ) : 0;
        if ( ! $artiste_id || get_post_type( $artiste_id ) !== self::POST_TYPE ) return;

        $page_id = (int) get_option( 'kmfamily_page_paliers' );
        if ( ! $page_id || ! is_page( $page_id ) ) return;

        echo "\n<!-- KM Family — aperçu de partage de l'artiste -->\n";
        self::render_og_tags( $artiste_id, self::get_url( $artiste_id ) );
        echo "\n";
    }

    /**
     * Page minimale servie aux robots d'aperçu. Elle n'est jamais vue par un visiteur
     * humain arrivant normalement — mais elle reste une page valide et cliquable au cas
     * où (navigateur annonçant un agent utilisateur inhabituel, aperçu ouvert à la main).
     */
    private static function render_share_preview( $artiste_id ) {
        $artiste_name = get_the_title( $artiste_id );
        $lien         = self::get_url( $artiste_id );
        $description  = self::share_description( $artiste_id, $artiste_name );
        $image        = self::share_image( $artiste_id );

        $titre = sprintf(
            /* translators: %s: nom de l'artiste */
            __( 'Soutenir %s — KM Family', 'km-family' ),
            $artiste_name
        );

        nocache_headers();
        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $titre ); ?></title>

<?php // Page destinée aux aperçus, pas à l'indexation : on l'exclut explicitement. ?>
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="<?php echo esc_url( $lien ); ?>">

<meta name="description" content="<?php echo esc_attr( $description ); ?>">
<?php self::render_og_tags( $artiste_id, $lien ); ?>

<style>
 body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      background:#0a0a0a;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      text-align:center;padding:40px 20px}
 img{width:140px;height:140px;border-radius:50%;object-fit:cover;border:4px solid #d4af37;margin-bottom:24px}
 h1{font-size:26px;margin:0 0 12px} p{color:#a0a0a0;max-width:480px;margin:0 auto 28px;line-height:1.6}
 a{display:inline-block;background:#d4af37;color:#0a0a0a;text-decoration:none;font-weight:700;
   padding:14px 32px;border-radius:50px}
</style>
</head>
<body>
<main>
<?php if ( $image ) : ?>
    <img src="<?php echo esc_url( $image['url'] ); ?>" alt="<?php echo esc_attr( $artiste_name ); ?>">
<?php endif; ?>
    <h1><?php echo esc_html( $titre ); ?></h1>
    <p><?php echo esc_html( $description ); ?></p>
    <a href="<?php echo esc_url( $lien ); ?>"><?php esc_html_e( 'Soutenir cet artiste', 'km-family' ); ?></a>
</main>
</body>
</html>
        <?php
    }

    // ==========================================================
    // GÉNÉRATION AUTOMATIQUE
    // ==========================================================

    /** Création / mise à jour d'un artiste : (re)génère et mémorise son lien direct. */
    public static function on_save_artist( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! $post || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) return;

        $is_new = ( get_post_meta( $post_id, self::META_KEY, true ) === '' );
        $url    = self::get_url( $post_id );
        if ( $url === '' ) return;

        update_post_meta( $post_id, self::META_KEY, $url );

        if ( $is_new ) {
            /**
             * Déclenché la première fois qu'un lien direct est généré pour un artiste.
             *
             * @param int    $post_id ID de l'artiste.
             * @param string $url     Lien direct de soutien.
             */
            do_action( 'kmfamily_artist_direct_link_created', $post_id, $url );
        }
    }

    /** Rattrapage unique pour les artistes créés avant cette version. */
    public static function maybe_backfill() {
        if ( get_option( self::OPT_BACKFILL ) === self::BACKFILL_VERSION ) return;
        self::sync_all();
        update_option( self::OPT_BACKFILL, self::BACKFILL_VERSION );
    }

    /** Re-génère la méta de tous les artistes. Retourne le nombre traité. */
    public static function sync_all() {
        $ids = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        foreach ( $ids as $id ) {
            $url = self::get_url( $id );
            if ( $url ) update_post_meta( $id, self::META_KEY, $url );
        }
        return count( $ids );
    }

    // ==========================================================
    // SHORTCODE
    // ==========================================================

    /**
     * [kmfamily_soutenir artiste_id="123" label="Soutenir" class="" register="0" palier=""]
     * Sans artiste_id : utilise l'artiste de la page courante.
     */
    public static function shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'artiste_id' => 0,
            'label'      => __( 'Soutenir cet artiste', 'km-family' ),
            'class'      => '',
            'register'   => '0',
            'palier'     => '',
        ), $atts, 'kmfamily_soutenir' );

        $artiste_id = absint( $atts['artiste_id'] );
        if ( ! $artiste_id ) $artiste_id = (int) get_queried_object_id();
        if ( ! $artiste_id || get_post_type( $artiste_id ) !== self::POST_TYPE ) return '';
        if ( ! self::is_supportable( $artiste_id ) ) return '';

        $url  = self::get_url( $artiste_id );
        $args = array();
        if ( $atts['palier'] !== '' ) $args['palier'] = sanitize_key( $atts['palier'] );
        if ( in_array( strtolower( (string) $atts['register'] ), array( '1', 'yes', 'true', 'oui' ), true ) ) $args['tab'] = 'register';
        if ( $args ) $url = add_query_arg( $args, $url );

        $classes = trim( 'kmfamily-btn kmfamily-btn-primary kmfamily-btn--soutenir ' . sanitize_text_field( $atts['class'] ) );

        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( $url ),
            esc_attr( $classes ),
            esc_html( $atts['label'] )
        );
    }

    // ==========================================================
    // ADMIN
    // ==========================================================

    public static function add_meta_box() {
        add_meta_box(
            'kmfamily_direct_link',
            __( 'KM Family — Lien direct de soutien', 'km-family' ),
            array( __CLASS__, 'render_meta_box' ),
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        if ( $post->post_status !== 'publish' ) {
            echo '<p>' . esc_html__( 'Le lien direct définitif sera généré à la publication de la fiche artiste.', 'km-family' ) . '</p>';
            return;
        }
        echo '<p style="margin-top:0">' . esc_html__( 'À partager (Instagram, WhatsApp, flyers, QR code…). Le visiteur se connecte ou crée son compte, puis arrive directement sur le soutien de cet artiste.', 'km-family' ) . '</p>';
        self::render_copy_field( self::get_url( $post->ID ) );
        echo '<p class="description">' . esc_html__( 'Astuce : ajoutez ?tab=register au lien pour ouvrir directement l\'onglet « Inscription ».', 'km-family' ) . '</p>';
        if ( ! self::is_supportable( $post->ID ) ) {
            echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'KM Family est désactivé pour cet artiste : le lien redirige vers la liste des artistes.', 'km-family' ) . '</strong></p>';
        }
    }

    /** Champ en lecture seule + bouton « Copier ». */
    private static function render_copy_field( $url ) {
        printf(
            '<div class="kmfamily-copy-field" style="display:flex;gap:6px;align-items:center;max-width:100%%;">'
            . '<input type="text" readonly value="%s" class="widefat kmfamily-copy-input" onclick="this.select();" style="min-width:0;">'
            . '<button type="button" class="button kmfamily-copy-btn">%s</button></div>',
            esc_attr( $url ),
            esc_html__( 'Copier', 'km-family' )
        );
    }

    public static function add_column( $columns ) {
        $columns['kmfamily_direct_link'] = __( 'Lien de soutien', 'km-family' );
        return $columns;
    }

    public static function render_column( $column, $post_id ) {
        if ( $column !== 'kmfamily_direct_link' ) return;
        if ( get_post_status( $post_id ) !== 'publish' ) {
            echo '&mdash;';
            return;
        }
        self::render_copy_field( self::get_url( $post_id ) );
    }

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Liens de soutien', 'km-family' ),
            __( 'Liens de soutien', 'km-family' ),
            'manage_options',
            'kmfamily-liens-soutien',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $artistes = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Liens directs de soutien', 'km-family' ); ?></h1>
            <p><?php esc_html_e( 'Un lien par artiste, généré automatiquement dès la création de sa fiche. Une personne qui clique dessus se connecte (ou crée son compte) puis arrive directement sur le soutien de l\'artiste. Si elle est déjà connectée, elle y arrive tout de suite.', 'km-family' ); ?></p>

            <?php if ( isset( $_GET['resynced'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html( sprintf( __( 'Liens resynchronisés (%d artiste(s)) et règles d\'URL régénérées.', 'km-family' ), absint( $_GET['resynced'] ) ) ); ?>
                </p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:1100px;margin-top:16px;">
                <thead><tr>
                    <th><?php esc_html_e( 'Artiste', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Lien direct', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Lien (ouvre « Inscription »)', 'km-family' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $artistes ) : ?>
                    <tr><td colspan="3"><?php esc_html_e( 'Aucun artiste pour le moment.', 'km-family' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $artistes as $a ) :
                    $published = ( $a->post_status === 'publish' );
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( get_edit_post_link( $a->ID ) ); ?>"><?php echo esc_html( get_the_title( $a ) ); ?></a></strong>
                            <?php if ( ! $published ) : ?>
                                <br><em><?php esc_html_e( 'Non publié — lien définitif à la publication', 'km-family' ); ?></em>
                            <?php elseif ( ! self::is_supportable( $a->ID ) ) : ?>
                                <br><em style="color:#b32d2e"><?php esc_html_e( 'KM Family désactivé — le lien renvoie vers la liste des artistes', 'km-family' ); ?></em>
                            <?php endif; ?>
                        </td>
                        <?php if ( $published ) : ?>
                            <td><?php self::render_copy_field( self::get_url( $a->ID ) ); ?></td>
                            <td><?php self::render_copy_field( add_query_arg( 'tab', 'register', self::get_url( $a->ID ) ) ); ?></td>
                        <?php else : ?>
                            <td colspan="2">&mdash;</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
                <input type="hidden" name="action" value="kmfamily_resync_direct_links">
                <?php wp_nonce_field( 'kmfamily_resync_direct_links', 'kmfamily_resync_nonce' ); ?>
                <?php submit_button( __( 'Resynchroniser les liens et régénérer les URL', 'km-family' ), 'secondary', 'submit', false ); ?>
                <p class="description"><?php esc_html_e( 'À utiliser si un lien direct renvoie une page 404 (après un changement de permaliens, par exemple).', 'km-family' ); ?></p>
            </form>
        </div>
        <?php
    }

    public static function handle_resync() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_resync_direct_links', 'kmfamily_resync_nonce' ) ) {
            wp_die( esc_html__( 'Action non autorisée.', 'km-family' ), '', array( 'response' => 403 ) );
        }

        self::register_rewrite();
        flush_rewrite_rules( false );
        update_option( self::OPT_REWRITE, self::RULES_VERSION . '|' . self::get_base() );
        $count = self::sync_all();

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-liens-soutien&resynced=' . $count ) );
        exit;
    }

    /** Script « Copier » — affiché uniquement sur les écrans qui contiennent un champ. */
    public static function print_copy_script() {
        if ( ! function_exists( 'get_current_screen' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen ) return;

        $ok = in_array( $screen->id, array( 'edit-' . self::POST_TYPE, self::POST_TYPE ), true )
            || strpos( (string) $screen->id, 'kmfamily-liens-soutien' ) !== false;
        if ( ! $ok ) return;
        ?>
        <script>
        (function () {
            document.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.kmfamily-copy-btn') : null;
                if (!btn) return;
                var input = btn.parentNode.querySelector('.kmfamily-copy-input');
                if (!input) return;
                input.focus(); input.select();
                var done = function () {
                    var old = btn.textContent;
                    btn.textContent = '✓';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value).then(done, function () {
                        try { document.execCommand('copy'); done(); } catch (err) {}
                    });
                } else {
                    try { document.execCommand('copy'); done(); } catch (err) {}
                }
            });
        })();
        </script>
        <?php
    }
}
