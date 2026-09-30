<?php
/**
 * KM Family — État de lecture, favoris et analytique d'écoute (LOT 2)
 *
 * Une ligne par couple (membre, contenu) : position de reprise, nombre de
 * lectures, complétion, favori. C'est ce qui permet « Reprendre où vous en
 * étiez », le cœur ❤️ du lecteur, et — plus tard, sans nouvelle table —
 * l'analytique de contenu (taux de complétion, durée moyenne d'écoute).
 *
 * GARDE-FOU DÉLIBÉRÉ sur le comptage des lectures : une lecture n'est comptée
 * qu'une fois par contenu et par tranche de 24 heures, et seulement au-delà
 * d'un seuil d'écoute réel. Sans cela, laisser un titre en boucle gonflerait
 * les statistiques — et, le jour où les points de fidélité seront adossés à
 * l'écoute, fabriquerait de la valeur convertible en billets et en
 * marchandises. Le garde-fou est posé maintenant, avant que les points ne
 * soient distribués, parce qu'il sera impossible à rattraper après.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Playback {

    const DB_VERSION_OPTION = 'kmfamily_playback_db_version';
    const DB_VERSION        = '1.0';

    /** Part du contenu à écouter pour qu'une lecture soit comptée. */
    const COMPLETION_THRESHOLD = 0.6;

    public static function init() {
        self::maybe_upgrade_table();
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_playback';
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
            user_id BIGINT UNSIGNED NOT NULL,
            content_id BIGINT UNSIGNED NOT NULL,
            artiste_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            duration INT UNSIGNED NOT NULL DEFAULT 0,
            max_progress SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            plays INT UNSIGNED NOT NULL DEFAULT 0,
            completed TINYINT(1) NOT NULL DEFAULT 0,
            favorite TINYINT(1) NOT NULL DEFAULT 0,
            counted_at DATETIME DEFAULT NULL,
            last_played DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_content (user_id, content_id),
            KEY content_id (content_id),
            KEY artiste_id (artiste_id),
            KEY favorite (favorite),
            KEY last_played (last_played)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    // ==========================================================
    // LECTURE
    // ==========================================================

    public static function get( $user_id, $content_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE user_id = %d AND content_id = %d",
            absint( $user_id ), absint( $content_id )
        ), ARRAY_A ) ?: null;
    }

    /**
     * Position de reprise. On ne reprend PAS un contenu terminé, ni une
     * position toute proche de la fin : rouvrir un titre pour tomber sur les
     * trois dernières secondes est une mauvaise expérience.
     */
    public static function get_position( $user_id, $content_id ) {
        $row = self::get( $user_id, $content_id );
        if ( ! $row ) return 0;
        if ( (int) $row['completed'] ) return 0;

        $pos = (int) $row['position'];
        $dur = (int) $row['duration'];
        if ( $dur > 0 && $pos > $dur - 10 ) return 0;
        if ( $pos < 5 ) return 0; // rien à reprendre

        return $pos;
    }

    public static function is_favorite( $user_id, $content_id ) {
        $row = self::get( $user_id, $content_id );
        return $row ? (bool) $row['favorite'] : false;
    }

    // ==========================================================
    // ÉCRITURE
    // ==========================================================

    private static function artiste_of( $content_id ) {
        $a = function_exists( 'get_field' ) ? get_field( 'artiste_lie', $content_id ) : get_post_meta( $content_id, 'artiste_lie', true );
        if ( ! $a ) return 0;
        return is_object( $a ) ? (int) $a->ID : absint( $a );
    }

    private static function ensure_row( $user_id, $content_id ) {
        global $wpdb;
        $row = self::get( $user_id, $content_id );
        if ( $row ) return $row;

        $now = current_time( 'mysql' );
        $wpdb->insert( self::table_name(), array(
            'user_id'    => absint( $user_id ),
            'content_id' => absint( $content_id ),
            'artiste_id' => self::artiste_of( $content_id ),
            'created_at' => $now,
            'updated_at' => $now,
        ) );

        return self::get( $user_id, $content_id );
    }

    /**
     * Enregistre l'avancement. Appelé régulièrement par le lecteur.
     *
     * @return array état mis à jour
     */
    public static function save_progress( $user_id, $content_id, $position, $duration = 0 ) {
        global $wpdb;

        $user_id    = absint( $user_id );
        $content_id = absint( $content_id );
        $position   = max( 0, (int) $position );
        $duration   = max( 0, (int) $duration );

        $row = self::ensure_row( $user_id, $content_id );
        if ( ! $row ) return array();

        $now = current_time( 'mysql' );
        $dur = $duration ?: (int) $row['duration'];

        // Progression maximale atteinte, en pour-cent. On conserve le maximum
        // et non la position courante : revenir en arrière ne doit pas effacer
        // le fait que le contenu a été écouté jusqu'au bout.
        $progress = $dur > 0 ? min( 100, (int) round( $position / $dur * 100 ) ) : 0;
        $max_prog = max( (int) $row['max_progress'], $progress );

        $data = array(
            'position'     => $position,
            'duration'     => $dur,
            'max_progress' => $max_prog,
            'last_played'  => $now,
            'updated_at'   => $now,
        );

        if ( $max_prog >= 95 ) $data['completed'] = 1;

        // Comptage d'une lecture : au-delà du seuil, et au plus une fois par
        // tranche de 24 heures pour ce contenu.
        $seuil_atteint = $dur > 0 && $position >= $dur * self::COMPLETION_THRESHOLD;
        $deja_compte   = $row['counted_at'] && ( strtotime( $now ) - strtotime( $row['counted_at'] ) ) < DAY_IN_SECONDS;

        if ( $seuil_atteint && ! $deja_compte ) {
            $data['plays']      = (int) $row['plays'] + 1;
            $data['counted_at'] = $now;
            do_action( 'kmfamily_playback_counted', $user_id, $content_id, self::artiste_of( $content_id ) );
        }

        $wpdb->update( self::table_name(), $data, array( 'id' => (int) $row['id'] ) );

        return array_merge( $row, $data );
    }

    public static function set_favorite( $user_id, $content_id, $state ) {
        global $wpdb;
        $row = self::ensure_row( $user_id, $content_id );
        if ( ! $row ) return false;

        $wpdb->update( self::table_name(),
            array( 'favorite' => $state ? 1 : 0, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $row['id'] ), array( '%d', '%s' ), array( '%d' ) );

        return (bool) $state;
    }

    // ==========================================================
    // LISTES
    // ==========================================================

    /** Favoris d'un membre (contenus encore publiés). */
    public static function get_favorites( $user_id, $limit = 50 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM " . self::table_name() . " p
             INNER JOIN {$wpdb->posts} w ON w.ID = p.content_id AND w.post_status = 'publish'
             WHERE p.user_id = %d AND p.favorite = 1
             ORDER BY p.updated_at DESC LIMIT %d",
            absint( $user_id ), max( 1, min( 200, (int) $limit ) )
        ), ARRAY_A );
    }

    /** « Continuer l'écoute » : commencé, pas terminé. */
    public static function get_continue( $user_id, $limit = 10 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM " . self::table_name() . " p
             INNER JOIN {$wpdb->posts} w ON w.ID = p.content_id AND w.post_status = 'publish'
             WHERE p.user_id = %d AND p.completed = 0 AND p.position > 5 AND p.duration > 0
             ORDER BY p.last_played DESC LIMIT %d",
            absint( $user_id ), max( 1, min( 50, (int) $limit ) )
        ), ARRAY_A );
    }

    /**
     * Analytique d'un contenu — la base du « Content Analytics » réclamé par
     * l'audit, obtenue sans table supplémentaire.
     */
    public static function get_content_stats( $content_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS auditeurs, COALESCE(SUM(plays),0) AS lectures,
                    COALESCE(SUM(favorite),0) AS favoris,
                    COALESCE(AVG(max_progress),0) AS progression_moy,
                    COALESCE(SUM(completed),0) AS termines
             FROM " . self::table_name() . " WHERE content_id = %d",
            absint( $content_id )
        ), ARRAY_A );

        $auditeurs = (int) ( $row['auditeurs'] ?? 0 );

        return array(
            'auditeurs'       => $auditeurs,
            'lectures'        => (int) ( $row['lectures'] ?? 0 ),
            'favoris'         => (int) ( $row['favoris'] ?? 0 ),
            'progression_moy' => (int) round( (float) ( $row['progression_moy'] ?? 0 ) ),
            'taux_completion' => $auditeurs > 0 ? (int) round( ( (int) $row['termines'] ) / $auditeurs * 100 ) : 0,
        );
    }

    // ==========================================================
    // API REST
    // ==========================================================

    public static function register_routes() {
        $auth = function() { return is_user_logged_in(); };

        register_rest_route( 'kmfamily/v1', '/playback/session', array(
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => array( __CLASS__, 'rest_session' ),
        ) );

        register_rest_route( 'kmfamily/v1', '/playback/heartbeat', array(
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => array( __CLASS__, 'rest_heartbeat' ),
        ) );

        register_rest_route( 'kmfamily/v1', '/playback/progress', array(
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => array( __CLASS__, 'rest_progress' ),
        ) );

        register_rest_route( 'kmfamily/v1', '/playback/favorite', array(
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => array( __CLASS__, 'rest_favorite' ),
        ) );
    }

    public static function rest_session( WP_REST_Request $r ) {
        $res = KMFamily_Media_Session::open( get_current_user_id(), absint( $r->get_param( 'content_id' ) ) );
        if ( is_wp_error( $res ) ) return $res;
        return new WP_REST_Response( $res, 200 );
    }

    public static function rest_heartbeat( WP_REST_Request $r ) {
        $key = preg_replace( '/[^a-f0-9]/', '', (string) $r->get_param( 'session_key' ) );
        $s   = KMFamily_Media_Session::validate( $key );
        if ( is_wp_error( $s ) ) return $s;

        // Le battement de cœur n'est légitime que pour son propre membre :
        // sans ce contrôle, un tiers pourrait maintenir en vie une session
        // qui ne lui appartient pas.
        if ( (int) $s['user_id'] !== get_current_user_id() ) {
            return new WP_Error( 'forbidden', __( 'Session inconnue.', 'km-family' ), array( 'status' => 403 ) );
        }

        KMFamily_Media_Session::touch( $key );
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    public static function rest_progress( WP_REST_Request $r ) {
        $content_id = absint( $r->get_param( 'content_id' ) );

        // CORRECTIF SÉCURITÉ v3.3.1 — FABRICATION DE POINTS DE FIDÉLITÉ.
        // Cet endpoint n'effectuait AUCUN contrôle d'accès : il suffisait d'être connecté
        // (même sans le moindre abonnement) pour envoyer une progression sur n'importe quel
        // content_id, avec une position et une durée entièrement choisies par le client.
        // save_progress() déclenche 'kmfamily_playback_counted' au-delà du seuil, hook auquel
        // KMFamily_Loyalty_Plus::award_engagement() attribue des points — convertibles ensuite
        // en récompenses réelles via KMFamily_Rewards. Un membre pouvait donc créditer son
        // compte en boucle (un contenu par tranche de 24 h) sans jamais rien écouter ni payer.
        // On exige désormais le même droit de lecture que pour ouvrir une session média —
        // exactement le contrôle déjà appliqué à rest_favorite() juste en dessous.
        if ( ! KMFamily_Media_Session::user_can_play( get_current_user_id(), $content_id ) ) {
            return new WP_Error( 'forbidden', __( 'Contenu inaccessible.', 'km-family' ), array( 'status' => 403 ) );
        }

        $state = self::save_progress(
            get_current_user_id(),
            $content_id,
            (int) $r->get_param( 'position' ),
            (int) $r->get_param( 'duration' )
        );

        return new WP_REST_Response( array(
            'ok'        => true,
            'plays'     => (int) ( $state['plays'] ?? 0 ),
            'completed' => (int) ( $state['completed'] ?? 0 ),
        ), 200 );
    }

    public static function rest_favorite( WP_REST_Request $r ) {
        $content_id = absint( $r->get_param( 'content_id' ) );

        // On ne met en favori qu'un contenu auquel on a réellement accès.
        if ( ! KMFamily_Media_Session::user_can_play( get_current_user_id(), $content_id ) ) {
            return new WP_Error( 'forbidden', __( 'Contenu inaccessible.', 'km-family' ), array( 'status' => 403 ) );
        }

        $state = self::set_favorite( get_current_user_id(), $content_id, (bool) $r->get_param( 'state' ) );
        return new WP_REST_Response( array( 'favorite' => $state ), 200 );
    }
}
