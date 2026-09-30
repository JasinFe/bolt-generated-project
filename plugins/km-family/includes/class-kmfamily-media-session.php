<?php
/**
 * KM Family — Sessions média (LOT 2)
 *
 * CORRECTION D'UNE RECOMMANDATION PRÉCÉDENTE.
 * Le document d'architecture proposait de réduire la durée de vie du jeton
 * média à 60–120 secondes pour rendre un lien partagé inutilisable. Cette
 * piste est bonne pour du HLS, où chaque segment est une requête distincte,
 * mais elle est FAUSSE pour un fichier progressif : le navigateur conserve la
 * même URL pendant toute l'écoute et la ré-interroge à chaque déplacement dans
 * la piste. Un jeton de 90 secondes casserait donc le seek dès la deuxième
 * minute.
 *
 * Le bon levier n'est pas la durée du jeton, c'est L'ÉTAT SERVEUR. Une session
 * média est une ligne en base :
 *   - révocable à tout moment, sans attendre l'expiration ;
 *   - liée à un appareil (empreinte de l'en-tête User-Agent + langue) ;
 *   - maintenue en vie par les requêtes elles-mêmes et par un battement de
 *     cœur du lecteur — une session abandonnée meurt d'elle-même ;
 *   - comptée, ce qui permet de plafonner les lectures simultanées par membre.
 *
 * Un lien recopié vers un autre appareil est détecté à la première requête
 * (empreinte différente) : c'est ce contrôle, et non la durée du jeton, qui
 * rend le partage inopérant.
 *
 * Ce module N'ALTÈRE PAS KMFamily_Media_Protector, qui continue de servir les
 * anciens gabarits via son propre chemin.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Media_Session {

    const DB_VERSION_OPTION = 'kmfamily_media_sessions_db_version';
    const DB_VERSION        = '1.0';

    const OPTION_MAX_STREAMS = 'kmfamily_max_concurrent_streams';
    const OPTION_TTL         = 'kmfamily_media_session_ttl';       // durée de vie maximale
    const OPTION_IDLE        = 'kmfamily_media_session_idle';      // mort par inactivité

    const DEFAULT_MAX_STREAMS = 2;
    const DEFAULT_TTL         = 14400; // 4 h — couvre une longue écoute
    const DEFAULT_IDLE        = 600;   // 10 min sans aucune requête = session morte

    public static function init() {
        self::maybe_upgrade_table();

        add_action( 'init',              array( __CLASS__, 'register_rewrite' ) );
        add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_stream' ) );

        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'cleanup' ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_media_sessions';
    }

    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;
        self::create_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key CHAR(32) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            content_id BIGINT UNSIGNED NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            driver VARCHAR(20) NOT NULL DEFAULT 'local',
            device_hash CHAR(32) NOT NULL DEFAULT '',
            ip_hash CHAR(32) NOT NULL DEFAULT '',
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_key (session_key),
            KEY user_id (user_id),
            KEY expires_at (expires_at),
            KEY last_seen (last_seen)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    // ==========================================================
    // RÉGLAGES
    // ==========================================================

    public static function max_streams() {
        $n = (int) get_option( self::OPTION_MAX_STREAMS, self::DEFAULT_MAX_STREAMS );
        return $n > 0 ? $n : self::DEFAULT_MAX_STREAMS;
    }

    public static function ttl() {
        $n = (int) get_option( self::OPTION_TTL, self::DEFAULT_TTL );
        return $n > 0 ? $n : self::DEFAULT_TTL;
    }

    public static function idle_timeout() {
        $n = (int) get_option( self::OPTION_IDLE, self::DEFAULT_IDLE );
        return $n > 0 ? $n : self::DEFAULT_IDLE;
    }

    // ==========================================================
    // EMPREINTES
    // ==========================================================

    /**
     * Empreinte d'appareil. On N'UTILISE PAS l'IP pour lier la session : sur
     * les réseaux mobiles ivoiriens (Orange, MTN, Moov), l'IP publique change
     * en cours d'écoute — bascule Wi-Fi/données, CGNAT. La lier casserait la
     * lecture chez exactement les membres visés. L'IP n'est donc conservée que
     * hachée, à titre d'indice de diagnostic, jamais comme condition d'accès.
     */
    public static function device_hash() {
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        return substr( hash( 'sha256', $ua . '|' . $lang . '|' . wp_salt( 'auth' ) ), 0, 32 );
    }

    public static function ip_hash() {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( strpos( $ip, ',' ) !== false ) $ip = trim( explode( ',', $ip )[0] );
        return substr( hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) ), 0, 32 );
    }

    // ==========================================================
    // OUVERTURE DE SESSION
    // ==========================================================

    /**
     * Ouvre une session de lecture après vérification complète des droits.
     *
     * @return array|WP_Error
     */
    public static function open( $user_id, $content_id ) {
        global $wpdb;

        $user_id    = absint( $user_id );
        $content_id = absint( $content_id );

        if ( ! $user_id || ! $content_id ) {
            return new WP_Error( 'invalid', __( 'Requête invalide.', 'km-family' ) );
        }
        if ( get_post_type( $content_id ) !== 'contenu_exclusif' ) {
            return new WP_Error( 'invalid', __( 'Contenu introuvable.', 'km-family' ) );
        }
        if ( ! self::user_can_play( $user_id, $content_id ) ) {
            return new WP_Error( 'forbidden', __( 'Ce contenu n\'est pas accessible avec votre palier.', 'km-family' ), array( 'status' => 403 ) );
        }

        // Une session déjà ouverte par le même membre sur le même contenu et le
        // même appareil est RÉUTILISÉE : recharger la page ne doit pas
        // consommer un deuxième emplacement de lecture simultanée.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . "
             WHERE user_id = %d AND content_id = %d AND device_hash = %s
               AND revoked = 0 AND expires_at > %s
             ORDER BY id DESC LIMIT 1",
            $user_id, $content_id, self::device_hash(), current_time( 'mysql' )
        ), ARRAY_A );

        if ( $existing ) {
            self::touch( $existing['session_key'] );
            return self::describe( $existing );
        }

        $active = self::count_active( $user_id );
        if ( $active >= self::max_streams() ) {
            return new WP_Error(
                'too_many_streams',
                sprintf(
                    __( 'Votre compte est déjà utilisé sur %d appareil(s). Arrêtez une lecture en cours pour continuer ici.', 'km-family' ),
                    $active
                ),
                array( 'status' => 429 )
            );
        }

        $driver = KMFamily_Media_Router::resolve( $content_id );
        $now    = current_time( 'mysql' );

        $row = array(
            'session_key'   => bin2hex( random_bytes( 16 ) ),
            'user_id'       => $user_id,
            'content_id'    => $content_id,
            'attachment_id' => KMFamily_Media_Router::get_attachment_id( $content_id ),
            'driver'        => $driver->get_id(),
            'device_hash'   => self::device_hash(),
            'ip_hash'       => self::ip_hash(),
            'revoked'       => 0,
            'created_at'    => $now,
            'expires_at'    => gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::ttl() ),
            'last_seen'     => $now,
        );

        if ( ! $wpdb->insert( self::table_name(), $row ) ) {
            return new WP_Error( 'db', __( 'Impossible d\'ouvrir la lecture.', 'km-family' ) );
        }

        do_action( 'kmfamily_media_session_opened', $row );

        return self::describe( $row );
    }

    /** Descripteur remis au lecteur. */
    private static function describe( array $row ) {
        $driver   = KMFamily_Media_Router::get_driver( $row['driver'] );
        $playback = $driver->get_playback( $row );

        if ( is_wp_error( $playback ) ) return $playback;

        $position = class_exists( 'KMFamily_Playback' )
            ? KMFamily_Playback::get_position( $row['user_id'], $row['content_id'] )
            : 0;

        return array(
            'session_key'  => $row['session_key'],
            'content_id'   => (int) $row['content_id'],
            'driver'       => $driver->get_id(),
            'capabilities' => $driver->capabilities(),
            'playback'     => $playback,
            'resume_at'    => (int) $position,
            'expires_in'   => max( 0, strtotime( $row['expires_at'] ) - strtotime( current_time( 'mysql' ) ) ),
        );
    }

    // ==========================================================
    // VALIDATION
    // ==========================================================

    /**
     * Contrôle des droits. Délégué à KMFamily_Access : le moteur média ne
     * décide JAMAIS des droits, il applique une décision prise ailleurs.
     */
    public static function user_can_play( $user_id, $content_id ) {
        if ( user_can( $user_id, 'manage_options' ) ) return true;

        // LOT 3 — une première non encore ouverte, ou un drop terminé, ne
        // doivent jamais produire de source de lecture, même pour un membre
        // dont le palier serait par ailleurs suffisant.
        if ( class_exists( 'KMFamily_Availability' ) ) {
            return KMFamily_Availability::user_can_access( $user_id, $content_id );
        }

        $palier  = function_exists( 'get_field' ) ? get_field( 'palier_requis', $content_id ) : get_post_meta( $content_id, 'palier_requis', true );
        $artiste = function_exists( 'get_field' ) ? get_field( 'artiste_lie', $content_id ) : get_post_meta( $content_id, 'artiste_lie', true );

        if ( ! $artiste ) return false;
        $artiste_id = is_object( $artiste ) ? $artiste->ID : absint( $artiste );

        return KMFamily_Access::user_has_access( $user_id, $artiste_id, $palier );
    }

    /**
     * Valide une session à chaque requête. Zéro confiance : les droits sont
     * revérifiés, car un abonnement peut avoir expiré depuis l'ouverture.
     *
     * @return array|WP_Error
     */
    public static function validate( $session_key ) {
        global $wpdb;

        $session_key = preg_replace( '/[^a-f0-9]/', '', (string) $session_key );
        if ( strlen( $session_key ) !== 32 ) {
            return new WP_Error( 'invalid', __( 'Lien invalide.', 'km-family' ) );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE session_key = %s", $session_key
        ), ARRAY_A );

        if ( ! $row )                 return new WP_Error( 'not_found', __( 'Lecture introuvable.', 'km-family' ) );
        if ( (int) $row['revoked'] )  return new WP_Error( 'revoked', __( 'Cette lecture a été interrompue.', 'km-family' ) );

        $now = strtotime( current_time( 'mysql' ) );
        if ( strtotime( $row['expires_at'] ) < $now ) {
            return new WP_Error( 'expired', __( 'Lecture expirée, rechargez la page.', 'km-family' ) );
        }
        if ( $now - strtotime( $row['last_seen'] ) > self::idle_timeout() ) {
            self::revoke( $session_key );
            return new WP_Error( 'idle', __( 'Lecture expirée pour inactivité, rechargez la page.', 'km-family' ) );
        }

        // C'EST ICI que le partage de lien est neutralisé : une empreinte
        // d'appareil différente signifie que l'URL a quitté le navigateur qui
        // l'a obtenue. On refuse, et on révoque la session pour que le lien
        // recopié ne serve plus jamais.
        if ( $row['device_hash'] !== self::device_hash() ) {
            self::revoke( $session_key );
            do_action( 'kmfamily_media_session_hijack', $row );
            return new WP_Error( 'device_mismatch', __( 'Ce lien de lecture n\'est pas valide sur cet appareil.', 'km-family' ) );
        }

        if ( ! self::user_can_play( $row['user_id'], $row['content_id'] ) ) {
            self::revoke( $session_key );
            return new WP_Error( 'forbidden', __( 'Vous n\'avez plus accès à ce contenu.', 'km-family' ) );
        }

        return $row;
    }

    public static function touch( $session_key ) {
        global $wpdb;
        return $wpdb->update( self::table_name(),
            array( 'last_seen' => current_time( 'mysql' ) ),
            array( 'session_key' => $session_key ), array( '%s' ), array( '%s' ) );
    }

    public static function revoke( $session_key ) {
        global $wpdb;
        return $wpdb->update( self::table_name(),
            array( 'revoked' => 1 ),
            array( 'session_key' => $session_key ), array( '%d' ), array( '%s' ) );
    }

    /** Lectures réellement en cours pour ce membre. */
    public static function count_active( $user_id ) {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::idle_timeout() );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . "
             WHERE user_id = %d AND revoked = 0 AND expires_at > %s AND last_seen > %s",
            absint( $user_id ), current_time( 'mysql' ), $cutoff
        ) );
    }

    /** Sessions en cours, tous membres confondus — indicateur de charge. */
    public static function count_active_all() {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::idle_timeout() );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . "
             WHERE revoked = 0 AND expires_at > %s AND last_seen > %s",
            current_time( 'mysql' ), $cutoff
        ) );
    }

    /** Purge des sessions mortes. */
    public static function cleanup() {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );
        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::table_name() . " WHERE expires_at < %s", $cutoff
        ) );
    }

    // ==========================================================
    // POINT D'ENTRÉE DE DIFFUSION
    // ==========================================================

    public static function register_rewrite() {
        add_rewrite_rule( '^km-family-stream/([a-f0-9]{32})/?$', 'index.php?kmfamily_stream=$matches[1]', 'top' );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'kmfamily_stream';
        return $vars;
    }

    public static function handle_stream() {
        $key = get_query_var( 'kmfamily_stream' );
        if ( ! $key ) return;

        $session = self::validate( $key );
        if ( is_wp_error( $session ) ) {
            status_header( 403 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo esc_html( $session->get_error_message() );
            exit;
        }

        self::touch( $key );

        $driver = KMFamily_Media_Router::get_driver( $session['driver'] );
        if ( method_exists( $driver, 'stream' ) ) {
            $driver->stream( $session );
            exit;
        }

        // Un pilote distant n'a rien à servir ici : le navigateur s'adresse
        // directement au prestataire. Atterrir sur cette URL signale une
        // incohérence de configuration plutôt qu'un flux à produire.
        status_header( 409 );
        exit;
    }
}
