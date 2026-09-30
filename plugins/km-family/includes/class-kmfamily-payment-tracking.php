<?php
/**
 * KM Family — Tracking dédié du parcours de paiement (Smart Payment Center)
 * =============================================================================
 * Table dédiée `wp_km_payment_tracking`, distincte du journal d'événements chaîné
 * (KMFamily_Event_Log) et des jetons de redirection signés (KMFamily_Security_Tokens) :
 *
 *  - KMFamily_Security_Tokens : jetons HMAC courts, JAMAIS stockés en base (juste un
 *    marqueur "déjà utilisé" en transient) — répondent à "ce clic est-il légitime ?".
 *  - KMFamily_Event_Log       : journal chaîné append-only de TOUS les événements —
 *    répond à "que s'est-il passé, dans quel ordre, et est-ce intact ?".
 *  - KMFamily_Payment_Tracking (ici) : UNE ligne par tentative de paiement (par
 *    commande + moyen), identifiée par un `tracking_token` central, avec des
 *    compteurs agrégés (clics, scans) et des horodatages clés — répond à "où en est
 *    CE parcours de paiement précis, et combien de fois a-t-il été retenté ?". C'est
 *    aussi ce `tracking_token` qui est encodé dans le QR Code, ce qui le rend
 *    dynamique : un nouveau chargement de la page de paiement génère un nouveau
 *    tracking_token, donc un QR Code différent, à usage limité dans le temps.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Payment_Tracking {

    const DB_VERSION_OPTION = 'kmfamily_payment_tracking_db_version';
    const DB_VERSION        = '1.0';
    const TOKEN_TTL         = 15 * MINUTE_IN_SECONDS; // aligné sur KMFamily_Security_Tokens::DEFAULT_TTL

    public static function init() {
        self::maybe_upgrade_table();
        // AUDIT QUALITÉ — NETTOYAGE : sans ça, cette table grossit indéfiniment — une ligne
        // est créée à CHAQUE chargement de la page de paiement, pour chaque moyen affiché,
        // y compris les visites jamais transformées en paiement. Réutilise le cron quotidien
        // déjà existant du plugin plutôt que d'en créer un nouveau.
        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'cleanup_old_records' ) );
    }

    /**
     * Purge les lignes de tracking anciennes et sans suite (30 jours). Les parcours qui ont
     * abouti à une confirmation/un rejet sont volontairement gardés plus longtemps (90 jours)
     * — utile pour un litige ou une analyse a posteriori — le détail fin (compteurs de
     * clics/scans) important surtout à chaud, pendant le traitement de la commande.
     */
    public static function cleanup_old_records() {
        global $wpdb;
        $table = self::table_name();

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE payment_confirmed_at IS NULL AND payment_rejected_at IS NULL
             AND generated_at < %s",
            KMFamily_Orders::local_datetime( - 30 * DAY_IN_SECONDS )
        ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE ( payment_confirmed_at IS NOT NULL OR payment_rejected_at IS NOT NULL )
             AND generated_at < %s",
            KMFamily_Orders::local_datetime( - 90 * DAY_IN_SECONDS )
        ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'km_payment_tracking';
    }

    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;
        self::create_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tracking_token VARCHAR(64) NOT NULL,
            order_ref VARCHAR(40) NOT NULL,
            method VARCHAR(40) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'generated',
            click_count INT UNSIGNED NOT NULL DEFAULT 0,
            scan_count INT UNSIGNED NOT NULL DEFAULT 0,
            generated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            qr_shown_at DATETIME DEFAULT NULL,
            first_click_at DATETIME DEFAULT NULL,
            last_click_at DATETIME DEFAULT NULL,
            redirected_at DATETIME DEFAULT NULL,
            returned_at DATETIME DEFAULT NULL,
            payment_confirmed_at DATETIME DEFAULT NULL,
            payment_rejected_at DATETIME DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tracking_token (tracking_token),
            KEY order_ref (order_ref),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * CORRECTIF v3.3.1 — source d'IP unique et non falsifiable pour tout le plugin
     * (voir KMFamily_Security::client_ip). Les en-têtes de proxy ne sont lus que si le
     * site est explicitement déclaré derrière un proxy de confiance : sans ce garde-fou,
     * un visiteur pouvait choisir l'IP enregistrée sur sa commande et fausser à la fois
     * la limitation de débit et le score du moteur de risque.
     */
    private static function client_ip(): string {
        $ip = KMFamily_Security::client_ip();
        return '0.0.0.0' === $ip ? '' : $ip;
    }

    /**
     * Démarre un nouveau parcours de paiement traçable pour une commande + un moyen
     * de paiement donnés. Génère un tracking_token frais (donc un QR Code différent
     * à chaque chargement de page — "QR Code dynamique"), journalise 'qr_generated',
     * et retourne le token.
     */
    public static function generate( string $order_ref, string $method ): string {
        global $wpdb;
        $table = self::table_name();
        $token = bin2hex( random_bytes( 20 ) );
        $now   = current_time( 'mysql' );

        $wpdb->insert( $table, array(
            'tracking_token' => $token,
            'order_ref'      => sanitize_text_field( $order_ref ),
            'method'         => sanitize_key( $method ),
            'status'         => 'generated',
            'generated_at'   => $now,
            'expires_at'     => KMFamily_Orders::local_datetime( self::TOKEN_TTL ),
            'ip'             => self::client_ip(),
            'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
            'created_at'     => $now,
            'updated_at'     => $now,
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, 'qr_generated', array( 'method' => $method, 'tracking_token' => $token ), 'system' );
        }

        return $token;
    }

    public static function get_by_token( string $token ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tracking_token = %s", $token ), ARRAY_A );
    }

    public static function get_for_order( string $order_ref, $limit = 20 ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_ref = %s ORDER BY id DESC LIMIT %d", $order_ref, $limit
        ), ARRAY_A );
    }

    /**
     * Dernière ligne de tracking active (non expirée) pour une commande + un moyen —
     * utilisée pour incrémenter click_count depuis le flux "bouton" (qui connaît
     * l'order_ref/method mais pas forcément le tracking_token exact si la page a été
     * rechargée entre deux clics).
     */
    private static function latest_row( string $order_ref, string $method ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_ref = %s AND method = %s ORDER BY id DESC LIMIT 1",
            $order_ref, $method
        ), ARRAY_A );
    }

    private static function touch( string $token, array $fields ) {
        global $wpdb;
        $fields['updated_at'] = current_time( 'mysql' );
        $wpdb->update( self::table_name(), $fields, array( 'tracking_token' => $token ) );
    }

    public static function record_qr_shown( string $token ) {
        $row = self::get_by_token( $token );
        if ( ! $row ) return false;
        self::touch( $token, array(
            'qr_shown_at' => $row['qr_shown_at'] ?: current_time( 'mysql' ),
            'status'      => 'qr_shown',
        ) );
        return true;
    }

    /**
     * Un clic sur le bouton "Payer avec X" — incrémente click_count sur la dernière
     * ligne de tracking active pour cette commande/moyen.
     */
    public static function record_click( string $order_ref, string $method ) {
        $row = self::latest_row( $order_ref, $method );
        if ( ! $row ) return false;
        $now = current_time( 'mysql' );
        self::touch( $row['tracking_token'], array(
            'click_count'    => (int) $row['click_count'] + 1,
            'first_click_at' => $row['first_click_at'] ?: $now,
            'last_click_at'  => $now,
            'redirected_at'  => $row['redirected_at'] ?: $now,
            'status'         => 'redirected',
        ) );
        return true;
    }

    /**
     * Un scan de QR (hit direct sur /km-pay/{token}/) — incrémente scan_count sur
     * la ligne exacte identifiée par le tracking_token.
     */
    public static function record_scan( string $token ) {
        $row = self::get_by_token( $token );
        if ( ! $row ) return false;
        $now = current_time( 'mysql' );
        self::touch( $token, array(
            'scan_count'    => (int) $row['scan_count'] + 1,
            'redirected_at' => $row['redirected_at'] ?: $now,
            'status'        => 'redirected',
        ) );
        return true;
    }

    /**
     * L'utilisateur revient sur l'onglet du navigateur après être parti payer
     * (détecté côté JS via l'API de visibilité de page — voir km-payment-center.js).
     */
    public static function record_returned( string $order_ref, string $method ) {
        $row = self::latest_row( $order_ref, $method );
        if ( ! $row || $row['returned_at'] ) return false;
        self::touch( $row['tracking_token'], array(
            'returned_at' => current_time( 'mysql' ),
            'status'      => 'returned',
        ) );
        return true;
    }

    public static function record_confirmed( string $order_ref ) {
        global $wpdb;
        $wpdb->update( self::table_name(),
            array( 'payment_confirmed_at' => current_time( 'mysql' ), 'status' => 'confirmed', 'updated_at' => current_time( 'mysql' ) ),
            array( 'order_ref' => $order_ref )
        );
    }

    public static function record_rejected( string $order_ref ) {
        global $wpdb;
        $wpdb->update( self::table_name(),
            array( 'payment_rejected_at' => current_time( 'mysql' ), 'status' => 'rejected', 'updated_at' => current_time( 'mysql' ) ),
            array( 'order_ref' => $order_ref )
        );
    }

    public static function is_expired( array $row ): bool {
        // Comparé sur la même horloge que l'écriture (heure locale du site), sans quoi un QR
        // naissait expiré — ou le restait trop longtemps — sur tout site hors UTC.
        return ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) < strtotime( current_time( 'mysql' ) );
    }
}
