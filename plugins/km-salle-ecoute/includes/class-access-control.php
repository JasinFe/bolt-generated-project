<?php
/**
 * Contrôle d'accès aux Sessions d'Écoute
 * Gère : accès public, protégé par mot de passe, privé, expiration, téléchargement,
 * journal des accès.
 *
 * 1.7.2 : l'accès « mot de passe » ne repose plus sur les sessions PHP ($_SESSION),
 * qui ne pouvaient pas démarrer une fois la page commencée (shortcode inline toujours
 * bloqué, avertissements « headers already sent », conflits avec le cache et la santé
 * du site). Il utilise désormais un cookie signé (HMAC) propre à chaque session
 * d'écoute et lié au mot de passe : changer le mot de passe révoque immédiatement
 * tous les accès déjà accordés.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_Access_Control {

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 15 * MINUTE_IN_SECONDS;
    private const COOKIE_TTL   = 7 * DAY_IN_SECONDS;
    private const MAX_LOGS     = 500;

    public static function init(): void {
        add_action( 'template_redirect',  [ __CLASS__, 'check_access' ] );
        add_action( 'init',               [ __CLASS__, 'handle_password_form' ] );
        add_action( 'wp_ajax_nopriv_km_verify_password', [ __CLASS__, 'ajax_verify_password' ] );
        add_action( 'wp_ajax_km_verify_password',        [ __CLASS__, 'ajax_verify_password' ] );
        add_action( 'wp_ajax_nopriv_km_download_piste',  [ __CLASS__, 'ajax_download' ] );
        add_action( 'wp_ajax_km_download_piste',         [ __CLASS__, 'ajax_download' ] );
    }

    /* ================================================================
       EXPIRATION
       ================================================================ */

    /**
     * Timestamp (UTC) de fin de validité : fin de la journée d'expiration dans le
     * fuseau horaire du site (auparavant calculé dans le fuseau du serveur).
     */
    public static function expiration_timestamp( int $post_id ): int {
        $date = (string) get_field( 'km_date_expiration', $post_id );
        if ( '' === $date ) return 0;

        $tz = wp_timezone();
        foreach ( [ 'Y-m-d', 'Ymd', 'd/m/Y' ] as $format ) {
            $dt = DateTimeImmutable::createFromFormat( '!' . $format, $date, $tz );
            if ( $dt ) return $dt->setTime( 23, 59, 59 )->getTimestamp();
        }
        $ts = strtotime( $date . ' 23:59:59' );
        return $ts ?: 0;
    }

    public static function is_expired( int $post_id ): bool {
        $ts = self::expiration_timestamp( $post_id );
        return $ts > 0 && time() > $ts;
    }

    /* ================================================================
       CONTRÔLE À L'AFFICHAGE
       ================================================================ */

    /**
     * Vérifie les droits d'accès avant d'afficher la page
     */
    public static function check_access(): void {
        if ( ! is_singular( 'kse_session' ) ) return;

        $post_id    = (int) get_queried_object_id();
        $acces_type = self::access_type( $post_id );

        // Contenu non public : jamais mis en cache par un plugin de cache / proxy
        if ( 'public' !== $acces_type ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
            nocache_headers();
        }

        // Admins et éditeurs passent toujours
        if ( current_user_can( 'edit_posts' ) ) return;

        if ( self::is_expired( $post_id ) ) {
            wp_safe_redirect( KM_Salle_Ecoute_Pages::expired_url() );
            exit;
        }

        // Accès privé : connexion requise
        if ( 'prive' === $acces_type && ! is_user_logged_in() ) {
            auth_redirect();
            exit;
        }

        // Accès protégé par mot de passe : le template affiche le formulaire.
        if ( self::has_access( $post_id ) ) {
            self::log_access( $post_id );
        }
    }

    /** Mode d'accès normalisé (défaut : mot de passe, comme le champ ACF). */
    public static function access_type( int $post_id ): string {
        $type = (string) get_field( 'km_acces_type', $post_id );
        return in_array( $type, [ 'public', 'password', 'prive' ], true ) ? $type : 'password';
    }

    /* ================================================================
       MOT DE PASSE
       ================================================================ */

    /** Mot de passe saisi : on ne retire que les espaces autour (pas de sanitize qui altère). */
    private static function posted_password( string $key ): string {
        $raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security
        return is_string( $raw ) ? trim( $raw ) : '';
    }

    private static function real_password( int $post_id ): string {
        return trim( (string) get_field( 'km_mot_de_passe', $post_id ) );
    }

    private static function is_password_session( int $post_id ): bool {
        return $post_id
            && 'kse_session' === get_post_type( $post_id )
            && 'publish' === get_post_status( $post_id )
            && 'password' === self::access_type( $post_id );
    }

    /**
     * Vérifie un mot de passe et accorde l'accès. Retourne : 'ok' | 'locked' | 'bad' | 'invalid'
     */
    private static function attempt( int $post_id, string $saisi ): string {
        if ( ! self::is_password_session( $post_id ) ) return 'invalid';
        if ( self::is_rate_limited( $post_id ) )       return 'locked';

        $reel = self::real_password( $post_id );
        if ( '' !== $reel && '' !== $saisi && hash_equals( $reel, $saisi ) ) {
            self::clear_attempts( $post_id );
            self::grant( $post_id );
            return 'ok';
        }
        self::register_failed_attempt( $post_id );
        return 'bad';
    }

    /**
     * Traitement du formulaire mot de passe (repli sans JavaScript)
     */
    public static function handle_password_form(): void {
        if ( ! isset( $_POST['kse_password_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kse_password_nonce'] ) ), 'kse_password' ) ) return;

        $post_id = absint( $_POST['kse_post_id'] ?? 0 );
        $result  = self::attempt( $post_id, self::posted_password( 'km_password' ) );
        $url     = get_permalink( $post_id ) ?: home_url( '/' );

        if ( 'ok' === $result ) {
            wp_safe_redirect( $url );
        } else {
            wp_safe_redirect( add_query_arg( 'kse_error', 'locked' === $result ? '2' : '1', $url ) );
        }
        exit;
    }

    /**
     * Vérification AJAX du mot de passe
     */
    public static function ajax_verify_password(): void {
        if ( ! check_ajax_referer( 'kse_password', 'nonce', false ) ) {
            // Nonce périmé (page restée ouverte longtemps ou servie par un cache)
            wp_send_json_error( [
                'message' => __( 'Votre page a expiré. Rechargez-la puis réessayez.', 'km-salle-ecoute' ),
                'reload'  => true,
            ] );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $result  = self::attempt( $post_id, self::posted_password( 'password' ) );

        switch ( $result ) {
            case 'ok':
                wp_send_json_success( [ 'message' => __( 'Accès autorisé.', 'km-salle-ecoute' ) ] );
            case 'locked':
                wp_send_json_error( [ 'message' => __( 'Trop de tentatives. Réessayez dans quelques minutes.', 'km-salle-ecoute' ) ] );
            case 'invalid':
                wp_send_json_error( [ 'message' => __( 'Session introuvable.', 'km-salle-ecoute' ) ] );
            default:
                wp_send_json_error( [ 'message' => __( 'Mot de passe incorrect. Veuillez réessayer.', 'km-salle-ecoute' ) ] );
        }
    }

    /* ── Cookie d'accès signé ── */

    private static function cookie_name( int $post_id ): string {
        return 'kse_access_' . $post_id;
    }

    private static function access_token( int $post_id ): string {
        // Lié au mot de passe courant : le changer invalide les accès déjà accordés.
        return hash_hmac( 'sha256', $post_id . '|' . self::real_password( $post_id ), wp_salt( 'auth' ) );
    }

    private static function grant( int $post_id ): void {
        $name  = self::cookie_name( $post_id );
        $token = self::access_token( $post_id );
        $exp   = time() + self::COOKIE_TTL;
        $ts    = self::expiration_timestamp( $post_id );
        if ( $ts && $ts < $exp ) $exp = $ts;

        if ( ! headers_sent() ) {
            setcookie( $name, $token, [
                'expires'  => $exp,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ] );
        }
        $_COOKIE[ $name ] = $token;   // disponible dès la requête courante
    }

    /* ── Anti-bruteforce : 5 tentatives échouées max par IP et par session, puis 15 min ── */

    private static function attempts_key( int $post_id ): string {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
        return 'kse_pwd_try_' . md5( $ip . '|' . $post_id );
    }

    private static function is_rate_limited( int $post_id ): bool {
        return (int) get_transient( self::attempts_key( $post_id ) ) >= self::MAX_ATTEMPTS;
    }

    private static function register_failed_attempt( int $post_id ): void {
        $key      = self::attempts_key( $post_id );
        $attempts = (int) get_transient( $key );
        set_transient( $key, $attempts + 1, self::LOCKOUT_TIME );
    }

    private static function clear_attempts( int $post_id ): void {
        delete_transient( self::attempts_key( $post_id ) );
    }

    /* ================================================================
       DROITS
       ================================================================ */

    /**
     * Vérifie si le visiteur courant a accès au post.
     * Une session expirée n'est plus accessible (ni lecture, ni téléchargement).
     */
    public static function has_access( int $post_id ): bool {
        if ( current_user_can( 'edit_posts' ) ) return true;
        if ( 'kse_session' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) return false;
        if ( self::is_expired( $post_id ) ) return false;

        switch ( self::access_type( $post_id ) ) {
            case 'public':
                return true;
            case 'prive':
                return is_user_logged_in();
            case 'password':
                if ( '' === self::real_password( $post_id ) ) return false;
                $cookie = isset( $_COOKIE[ self::cookie_name( $post_id ) ] )
                    ? sanitize_text_field( wp_unslash( $_COOKIE[ self::cookie_name( $post_id ) ] ) )
                    : '';
                return '' !== $cookie && hash_equals( self::access_token( $post_id ), $cookie );
        }
        return false;
    }

    /**
     * Détermine si le téléchargement est autorisé pour une session donnée.
     *  - Réglage global : 'force_on' (toujours), 'force_off' (jamais), 'session' (défaut :
     *    on suit le champ « Autoriser le téléchargement ? » de chaque session).
     *  - L'accès à la session reste toujours requis.
     */
    public static function download_allowed( int $post_id ): bool {
        if ( ! self::has_access( $post_id ) ) return false;

        $mode = get_option( 'kse_telechargement_mode', 'session' );

        if ( 'force_on' === $mode )  return true;
        if ( 'force_off' === $mode ) return false;

        return (bool) get_field( 'km_telechargement_autorise', $post_id );
    }

    /* ================================================================
       TÉLÉCHARGEMENT SÉCURISÉ
       ================================================================ */

    public static function ajax_download(): void {
        $post_id = absint( $_GET['post_id'] ?? 0 );
        $index   = absint( $_GET['piste'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'kse_download_' . $post_id ) ) {
            wp_die( esc_html__( 'Lien de téléchargement invalide ou expiré. Rechargez la page de la session.', 'km-salle-ecoute' ), '', [ 'response' => 403 ] );
        }

        if ( ! self::download_allowed( $post_id ) ) {
            wp_die( esc_html__( 'Le téléchargement n\'est pas autorisé pour cette session.', 'km-salle-ecoute' ), '', [ 'response' => 403 ] );
        }

        $pistes = KM_Salle_Ecoute_ACF_Helper::get_pistes( $post_id );
        if ( ! isset( $pistes[ $index ] ) || ! $pistes[ $index ]['url'] ) {
            wp_die( esc_html__( 'Piste introuvable.', 'km-salle-ecoute' ), '', [ 'response' => 404 ] );
        }

        $piste = $pistes[ $index ];
        $url   = $piste['url'];
        $path  = self::url_to_path( $url );
        $name  = $piste['filename'] ?: ( $path ? basename( $path ) : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );

        if ( $path ) {
            self::stream_file( $path, $name, $piste['mime'] );
        }

        // Fichier hors uploads (CDN, etc.) : redirection simple
        wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- URL choisie par un éditeur
        exit;
    }

    /**
     * Envoie le fichier par blocs (pas de chargement complet en mémoire : les WAV
     * de plusieurs centaines de Mo ne font plus planter PHP).
     */
    private static function stream_file( string $path, string $name, string $mime ): void {
        $size = filesize( $path );
        $fh   = fopen( $path, 'rb' );
        if ( false === $fh || false === $size ) return;

        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 ); // phpcs:ignore
        while ( ob_get_level() ) ob_end_clean();

        $name  = sanitize_file_name( $name ) ?: 'piste';
        nocache_headers();
        header( 'Content-Type: ' . ( $mime ?: 'application/octet-stream' ) );
        header( 'Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
        header( 'Content-Length: ' . $size );
        header( 'X-Content-Type-Options: nosniff' );

        while ( ! feof( $fh ) ) {
            echo fread( $fh, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput
            flush();
        }
        fclose( $fh );
        exit;
    }

    /**
     * Convertit une URL d'upload WordPress en chemin disque, si possible
     * (en restant strictement dans le dossier uploads).
     */
    private static function url_to_path( string $url ): string {
        $uploads = wp_get_upload_dir();
        $url     = strtok( $url, '?#' );
        foreach ( [ $uploads['baseurl'], set_url_scheme( $uploads['baseurl'], is_ssl() ? 'http' : 'https' ) ] as $base ) {
            if ( 0 !== strpos( $url, $base . '/' ) ) continue;
            $rel  = rawurldecode( substr( $url, strlen( $base ) ) );
            $path = realpath( $uploads['basedir'] . $rel );
            $root = realpath( $uploads['basedir'] );
            if ( $path && $root && 0 === strpos( $path, $root . DIRECTORY_SEPARATOR ) && is_file( $path ) ) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Construit l'URL sécurisée de téléchargement pour une piste donnée.
     */
    public static function get_download_url( int $post_id, int $index ): string {
        return add_query_arg( [
            'action'  => 'km_download_piste',
            'post_id' => $post_id,
            'piste'   => $index,
            'nonce'   => wp_create_nonce( 'kse_download_' . $post_id ),
        ], admin_url( 'admin-ajax.php' ) );
    }

    /* ================================================================
       JOURNAL DES ACCÈS (intégré, activable dans Réglages)
       ================================================================ */

    public static function log_access( int $post_id ): void {
        if ( ! get_option( 'kse_logs_actifs', 0 ) ) return;
        if ( current_user_can( 'edit_posts' ) ) return;   // l'équipe du Label n'est pas journalisée

        $ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
        if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp/i', $ua ) ) return;

        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

        // Une seule entrée par visiteur et par session toutes les 30 minutes
        $dedupe = 'kse_log_' . md5( $ip . '|' . $ua . '|' . $post_id );
        if ( get_transient( $dedupe ) ) return;
        set_transient( $dedupe, 1, 30 * MINUTE_IN_SECONDS );

        $user = wp_get_current_user();
        $logs = get_option( 'kse_access_logs', [] );
        if ( ! is_array( $logs ) ) $logs = [];
        $logs[] = [
            'post_id'    => $post_id,
            'post_title' => get_the_title( $post_id ),
            'ip'         => $ip,
            'user'       => $user && $user->exists() ? $user->user_login : '',
            'user_agent' => $ua,
            'timestamp'  => current_time( 'mysql' ),
        ];
        if ( count( $logs ) > self::MAX_LOGS ) $logs = array_slice( $logs, -self::MAX_LOGS );
        update_option( 'kse_access_logs', $logs, false );   // pas d'autoload
    }
}
