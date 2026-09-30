<?php
/**
 * KM Family — Journal d'événements sécurisé (Smart Payment Center)
 * ===================================================================
 * Chaque événement du cycle de vie d'un paiement (redirection confirmée, paiement
 * initié, déclaration, confirmation/rejet admin, alerte de risque...) est inscrit
 * dans une table dédiée avec un CHAÎNAGE DE HACHAGE (façon "blockchain" locale) :
 * chaque ligne contient le hash de la ligne précédente + son propre contenu. Toute
 * modification a posteriori d'une ligne (édition directe en base, par exemple pour
 * maquiller une fraude) casse la chaîne à partir de ce point, et devient détectable
 * par verify_chain().
 *
 * IMPORTANT (honnêteté technique) : ceci rend la falsification DÉTECTABLE
 * ("tamper-evident"), pas physiquement impossible ("tamper-proof") — un accès
 * complet à la base de données permettrait toujours de recalculer une chaîne
 * cohérente depuis le point modifié. C'est le même principe de garantie qu'un
 * journal d'audit comptable à chaînage (cf. NF203/normes anti-fraude caisse) :
 * la valeur vient de la détectabilité, pas de l'inviolabilité absolue.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Event_Log {

    const DB_VERSION_OPTION = 'kmfamily_security_log_db_version';
    const DB_VERSION        = '1.0';
    const GENESIS_HASH      = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function init() {
        self::maybe_upgrade_table();
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_security_log';
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
            order_ref VARCHAR(40) DEFAULT NULL,
            event_type VARCHAR(60) NOT NULL,
            event_data LONGTEXT DEFAULT NULL,
            actor VARCHAR(60) NOT NULL DEFAULT 'system',
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            prev_hash CHAR(64) NOT NULL,
            entry_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entry_hash (entry_hash),
            KEY order_ref (order_ref),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    private static function client_ip(): string {
        foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '';
    }

    /**
     * RENFORCEMENT v3.4.0 — LE JOURNAL ÉTAIT REFORGEABLE AVEC LE SEUL ACCÈS À LA BASE.
     *
     * Le chaînage utilisait un SHA-256 nu. Il détectait donc une modification accidentelle,
     * mais pas une modification VOLONTAIRE : l'algorithme étant public et sans secret,
     * quiconque pouvait écrire dans la base — injection SQL ailleurs sur le site,
     * sauvegarde dérobée, accès phpMyAdmin d'un hébergement mutualisé compromis — pouvait
     * effacer un événement gênant puis recalculer toute la chaîne derrière lui. Le journal
     * se serait alors déclaré « intact », ce qui est pire que pas de journal du tout : on
     * s'appuierait dessus lors d'un litige de paiement.
     *
     * La chaîne est désormais scellée par un HMAC dont la clé est un sel WordPress. Ce sel
     * vit dans wp-config.php, c'est-à-dire HORS de la base : un accès à la seule base ne
     * suffit plus à produire une chaîne valide.
     *
     * Les lignes écrites avant cette version restent vérifiables : verify_chain() accepte
     * l'ancien format en repli, sans quoi tout l'historique existant serait signalé comme
     * falsifié au premier contrôle.
     */
    private static function seal( array $parts ): string {
        return hash_hmac( 'sha256', implode( '|', $parts ), wp_salt( 'auth' ) );
    }

    /** Ancien calcul, conservé pour vérifier l'historique antérieur à la v3.4.0. */
    private static function legacy_seal( array $parts ): string {
        return hash( 'sha256', implode( '|', $parts ) );
    }

    private static function last_hash(): string {
        global $wpdb;
        $table = self::table_name();
        $hash  = $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1" );
        return $hash ?: self::GENESIS_HASH;
    }

    /**
     * Inscrire un nouvel événement dans le journal chaîné.
     *
     * @param string|null $order_ref Référence de commande concernée (nullable pour un événement global).
     * @param string      $type      Type d'événement (ex. 'redirect_confirmed', 'payment_declared', 'admin_confirmed'...).
     * @param array       $data      Données structurées associées (jamais de données sensibles brutes en clair type mot de passe).
     * @param string      $actor     Qui a déclenché l'événement ('system', 'user:{id}', 'admin:{id}', 'webhook').
     */
    public static function log( $order_ref, string $type, array $data = array(), string $actor = 'system' ) {
        global $wpdb;
        $table = self::table_name();

        $prev_hash = self::last_hash();
        $created   = current_time( 'mysql' );
        $ip        = self::client_ip();
        $ua        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
        $data_json = wp_json_encode( $data );

        $entry_hash = self::seal( array(
            $prev_hash,
            (string) $order_ref,
            $type,
            $data_json,
            $actor,
            $ip,
            $created,
        ) );

        $wpdb->insert( $table, array(
            'order_ref'  => $order_ref ? sanitize_text_field( $order_ref ) : null,
            'event_type' => sanitize_key( $type ),
            'event_data' => $data_json,
            'actor'      => sanitize_text_field( $actor ),
            'ip'         => $ip,
            'user_agent' => $ua,
            'prev_hash'  => $prev_hash,
            'entry_hash' => $entry_hash,
            'created_at' => $created,
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        return $entry_hash;
    }

    public static function get_events_for_order( $order_ref, $limit = 100 ) {
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_ref = %s ORDER BY id ASC LIMIT %d",
            $order_ref, $limit
        ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $decoded = json_decode( $row['event_data'], true );
            $row['event_data'] = is_array( $decoded ) ? $decoded : array();
        }
        return $rows;
    }

    public static function get_recent( $limit = 50, $event_type = null ) {
        global $wpdb;
        $table = self::table_name();

        if ( $event_type ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_type = %s ORDER BY id DESC LIMIT %d",
                $event_type, $limit
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ), ARRAY_A );
        }

        foreach ( $rows as &$row ) {
            $decoded = json_decode( $row['event_data'], true );
            $row['event_data'] = is_array( $decoded ) ? $decoded : array();
        }
        return $rows;
    }

    public static function count_events( $event_type = null ) {
        global $wpdb;
        $table = self::table_name();
        if ( $event_type ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Reparcourir la chaîne complète et vérifier son intégrité. Retourne :
     *  - intact   : bool
     *  - checked  : nombre de lignes vérifiées
     *  - broken_at: id de la première ligne dont le hash ne correspond plus (ou null)
     */
    public static function verify_chain( $max_rows = 20000 ) {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_ref, event_type, event_data, actor, ip, prev_hash, entry_hash, created_at
             FROM {$table} ORDER BY id ASC LIMIT %d",
            $max_rows
        ), ARRAY_A );

        $expected_prev = self::GENESIS_HASH;
        foreach ( $rows as $row ) {
            if ( $row['prev_hash'] !== $expected_prev ) {
                return array( 'intact' => false, 'checked' => count( $rows ), 'broken_at' => (int) $row['id'] );
            }

            $parts = array(
                $row['prev_hash'], (string) $row['order_ref'], $row['event_type'],
                $row['event_data'], $row['actor'], $row['ip'], $row['created_at'],
            );

            // Format courant (scellé) puis, en repli, l'ancien format non scellé pour
            // les lignes écrites avant la v3.4.0.
            $ok = hash_equals( self::seal( $parts ), $row['entry_hash'] )
               || hash_equals( self::legacy_seal( $parts ), $row['entry_hash'] );

            if ( ! $ok ) {
                return array( 'intact' => false, 'checked' => count( $rows ), 'broken_at' => (int) $row['id'] );
            }

            $expected_prev = $row['entry_hash'];
        }

        return array( 'intact' => true, 'checked' => count( $rows ), 'broken_at' => null );
    }
}
