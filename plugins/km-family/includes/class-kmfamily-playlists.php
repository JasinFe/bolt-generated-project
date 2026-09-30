<?php
/**
 * KM Family — Playlists membres (LOT 4)
 *
 * Ce qui transforme une page de paiement en plateforme que l'on rouvre :
 * le membre range lui-même ce qu'il écoute. Une playlist créée est un
 * engagement bien plus fort qu'un abonnement payé — elle donne une raison de
 * revenir qui ne dépend plus de la sortie d'un nouveau contenu.
 *
 * CHOIX DE CONCEPTION — les favoris ne sont PAS une playlist.
 * Ils vivent déjà dans KMFamily_Playback (colonne `favorite`), alimentée par
 * le cœur du lecteur. Les recopier ici créerait deux sources de vérité pour la
 * même information, avec la garantie qu'elles finiraient par diverger. Les
 * favoris sont donc exposés comme une playlist VIRTUELLE : même présentation
 * pour le membre, une seule source en base.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Playlists {

    const DB_VERSION_OPTION = 'kmfamily_playlists_db_version';
    const DB_VERSION        = '1.0';

    /** Garde-fous : une playlist est un outil de rangement, pas un entrepôt. */
    const MAX_PAR_MEMBRE = 30;
    const MAX_ITEMS      = 300;
    const MAX_NOM        = 60;

    public static function init() {
        self::maybe_upgrade_table();
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function table_playlists() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_playlists';
    }

    public static function table_items() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_playlist_items';
    }

    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;
        self::create_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();

        $p = self::table_playlists();
        dbDelta( "CREATE TABLE {$p} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            nom VARCHAR(80) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) {$cc};" );

        $i = self::table_items();
        // ON DELETE n'existe pas ici : WordPress ne garantit pas InnoDB partout.
        // La suppression en cascade est faite explicitement dans delete().
        dbDelta( "CREATE TABLE {$i} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            playlist_id BIGINT UNSIGNED NOT NULL,
            content_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            added_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY playlist_content (playlist_id, content_id),
            KEY playlist_id (playlist_id)
        ) {$cc};" );
    }

    // ==========================================================
    // LECTURE
    // ==========================================================

    public static function get( $playlist_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_playlists() . " WHERE id = %d", absint( $playlist_id )
        ), ARRAY_A ) ?: null;
    }

    /** Une playlist appartient à son membre, et à personne d'autre. */
    public static function owns( $user_id, $playlist_id ) {
        $p = self::get( $playlist_id );
        return $p && (int) $p['user_id'] === absint( $user_id );
    }

    public static function get_user_playlists( $user_id ) {
        global $wpdb;
        $t = self::table_playlists();
        $i = self::table_items();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, ( SELECT COUNT(*) FROM {$i} WHERE playlist_id = p.id ) AS nb
             FROM {$t} p WHERE p.user_id = %d ORDER BY p.updated_at DESC",
            absint( $user_id )
        ), ARRAY_A );
    }

    /**
     * Contenus d'une playlist, dans l'ordre, filtrés sur ce qui est encore
     * publié. On NE filtre PAS sur l'accessibilité : un titre devenu
     * inaccessible reste visible, grisé — le retirer silencieusement d'une
     * playlist que le membre a constituée serait déroutant.
     */
    public static function get_items( $playlist_id ) {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT it.content_id FROM " . self::table_items() . " it
             INNER JOIN {$wpdb->posts} p ON p.ID = it.content_id AND p.post_status = 'publish'
             WHERE it.playlist_id = %d ORDER BY it.position ASC, it.id ASC",
            absint( $playlist_id )
        ) );
    }

    public static function count_items( $playlist_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_items() . " WHERE playlist_id = %d", absint( $playlist_id )
        ) );
    }

    // ==========================================================
    // ÉCRITURE
    // ==========================================================

    /**
     * Nettoie et tronque un nom de playlist.
     *
     * mb_substr() n'est pas garanti : l'extension mbstring est absente de
     * certaines configurations d'hébergement mutualisé. WordPress fournit
     * bien un équivalent de secours, mais s'appuyer dessus implicitement
     * rendrait ce module dépendant d'un détail interne du cœur. On teste donc
     * la fonction, et on retombe sur substr() — au pire un nom accentué est
     * tronqué un caractère plus tôt, ce qui est sans conséquence.
     */
    private static function trim_nom( $nom ) {
        $nom = trim( wp_strip_all_tags( (string) $nom ) );
        if ( $nom === '' ) return '';
        return function_exists( 'mb_substr' )
            ? mb_substr( $nom, 0, self::MAX_NOM )
            : substr( $nom, 0, self::MAX_NOM );
    }

    public static function create( $user_id, $nom ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $nom     = self::trim_nom( $nom );
        if ( $nom === '' ) $nom = __( 'Ma playlist', 'km-family' );

        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_playlists() . " WHERE user_id = %d", $user_id ) );

        if ( $n >= self::MAX_PAR_MEMBRE ) {
            return new WP_Error( 'limite', sprintf(
                __( 'Vous avez atteint %d playlists. Supprimez-en une pour en créer une nouvelle.', 'km-family' ),
                self::MAX_PAR_MEMBRE
            ) );
        }

        $now = current_time( 'mysql' );
        $wpdb->insert( self::table_playlists(), array(
            'user_id'    => $user_id,
            'nom'        => $nom,
            'created_at' => $now,
            'updated_at' => $now,
        ) );

        return (int) $wpdb->insert_id;
    }

    public static function rename( $user_id, $playlist_id, $nom ) {
        global $wpdb;
        if ( ! self::owns( $user_id, $playlist_id ) ) {
            return new WP_Error( 'forbidden', __( 'Playlist introuvable.', 'km-family' ) );
        }

        $nom = self::trim_nom( $nom );
        if ( $nom === '' ) return new WP_Error( 'vide', __( 'Le nom ne peut pas être vide.', 'km-family' ) );

        $wpdb->update( self::table_playlists(),
            array( 'nom' => $nom, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => absint( $playlist_id ) ), array( '%s', '%s' ), array( '%d' ) );

        return true;
    }

    public static function delete( $user_id, $playlist_id ) {
        global $wpdb;
        if ( ! self::owns( $user_id, $playlist_id ) ) {
            return new WP_Error( 'forbidden', __( 'Playlist introuvable.', 'km-family' ) );
        }

        // Les items d'abord : si la suppression de la playlist échouait après,
        // mieux vaut une playlist vide qu'un lot d'items orphelins invisibles
        // qui compteraient encore dans les quotas.
        $wpdb->delete( self::table_items(), array( 'playlist_id' => absint( $playlist_id ) ), array( '%d' ) );
        $wpdb->delete( self::table_playlists(), array( 'id' => absint( $playlist_id ) ), array( '%d' ) );

        return true;
    }

    public static function add_item( $user_id, $playlist_id, $content_id ) {
        global $wpdb;

        if ( ! self::owns( $user_id, $playlist_id ) ) {
            return new WP_Error( 'forbidden', __( 'Playlist introuvable.', 'km-family' ) );
        }

        $content_id = absint( $content_id );
        if ( get_post_type( $content_id ) !== 'contenu_exclusif' || get_post_status( $content_id ) !== 'publish' ) {
            return new WP_Error( 'invalide', __( 'Contenu introuvable.', 'km-family' ) );
        }

        // On n'ajoute que ce à quoi le membre a réellement accès : une playlist
        // ne doit pas devenir un moyen de constituer une liste de contenus
        // verrouillés que l'on n'a jamais eu le droit de voir.
        if ( ! KMFamily_Availability::user_can_access( $user_id, $content_id ) ) {
            return new WP_Error( 'forbidden', __( 'Ce contenu n\'est pas accessible avec votre palier.', 'km-family' ) );
        }

        if ( self::count_items( $playlist_id ) >= self::MAX_ITEMS ) {
            return new WP_Error( 'limite', sprintf(
                __( 'Cette playlist a atteint %d titres.', 'km-family' ), self::MAX_ITEMS ) );
        }

        $pos = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(position),0) + 1 FROM " . self::table_items() . " WHERE playlist_id = %d",
            absint( $playlist_id ) ) );

        // INSERT IGNORE : la clé unique (playlist, contenu) fait office de
        // déduplication, donc un double-clic n'ajoute pas deux fois le titre.
        $t = self::table_items();
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$t} (playlist_id, content_id, position, added_at) VALUES (%d, %d, %d, %s)",
            absint( $playlist_id ), $content_id, $pos, current_time( 'mysql' )
        ) );

        $wpdb->update( self::table_playlists(),
            array( 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => absint( $playlist_id ) ), array( '%s' ), array( '%d' ) );

        return true;
    }

    public static function remove_item( $user_id, $playlist_id, $content_id ) {
        global $wpdb;
        if ( ! self::owns( $user_id, $playlist_id ) ) {
            return new WP_Error( 'forbidden', __( 'Playlist introuvable.', 'km-family' ) );
        }

        $wpdb->delete( self::table_items(), array(
            'playlist_id' => absint( $playlist_id ),
            'content_id'  => absint( $content_id ),
        ), array( '%d', '%d' ) );

        return true;
    }

    /** Purge des playlists d'un membre supprimé. */
    public static function delete_user_data( $user_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::table_playlists() . " WHERE user_id = %d", absint( $user_id ) ) );

        foreach ( $ids as $id ) {
            $wpdb->delete( self::table_items(), array( 'playlist_id' => (int) $id ), array( '%d' ) );
        }
        $wpdb->delete( self::table_playlists(), array( 'user_id' => absint( $user_id ) ), array( '%d' ) );
    }

    // ==========================================================
    // API REST
    // ==========================================================

    public static function register_routes() {
        $auth = function () { return is_user_logged_in(); };

        register_rest_route( 'kmfamily/v1', '/playlists', array(
            array( 'methods' => 'GET',  'permission_callback' => $auth, 'callback' => array( __CLASS__, 'rest_list' ) ),
            array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'rest_create' ) ),
        ) );

        register_rest_route( 'kmfamily/v1', '/playlists/(?P<id>\d+)', array(
            array( 'methods' => 'POST',   'permission_callback' => $auth, 'callback' => array( __CLASS__, 'rest_rename' ) ),
            array( 'methods' => 'DELETE', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'rest_delete' ) ),
        ) );

        register_rest_route( 'kmfamily/v1', '/playlists/(?P<id>\d+)/items', array(
            array( 'methods' => 'POST',   'permission_callback' => $auth, 'callback' => array( __CLASS__, 'rest_add' ) ),
            array( 'methods' => 'DELETE', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'rest_remove' ) ),
        ) );
    }

    private static function out( $r ) {
        return is_wp_error( $r ) ? $r : new WP_REST_Response( array( 'ok' => true, 'result' => $r ), 200 );
    }

    public static function rest_list() {
        $uid  = get_current_user_id();
        $out  = array();

        foreach ( self::get_user_playlists( $uid ) as $p ) {
            $out[] = array( 'id' => (int) $p['id'], 'nom' => $p['nom'], 'nb' => (int) $p['nb'] );
        }

        return new WP_REST_Response( array( 'playlists' => $out ), 200 );
    }

    public static function rest_create( WP_REST_Request $r ) {
        return self::out( self::create( get_current_user_id(), $r->get_param( 'nom' ) ) );
    }

    public static function rest_rename( WP_REST_Request $r ) {
        return self::out( self::rename( get_current_user_id(), $r->get_param( 'id' ), $r->get_param( 'nom' ) ) );
    }

    public static function rest_delete( WP_REST_Request $r ) {
        return self::out( self::delete( get_current_user_id(), $r->get_param( 'id' ) ) );
    }

    public static function rest_add( WP_REST_Request $r ) {
        return self::out( self::add_item( get_current_user_id(), $r->get_param( 'id' ), $r->get_param( 'content_id' ) ) );
    }

    public static function rest_remove( WP_REST_Request $r ) {
        return self::out( self::remove_item( get_current_user_id(), $r->get_param( 'id' ), $r->get_param( 'content_id' ) ) );
    }
}
