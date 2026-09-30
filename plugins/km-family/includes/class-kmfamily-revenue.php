<?php
/**
 * KM Family — Revenus artistes & partage Label/Artiste
 *
 * PONT AVEC « KOPHI'S MUSIC — Artist Dashboard ».
 *
 * Ce module est la SOURCE DE VÉRITÉ du volet « soutiens » : chaque abonnement
 * réellement activé produit UNE ligne de registre (ledger) figée, qui est
 * ensuite lue par l'espace artiste du dashboard streaming pour afficher, à
 * côté des revenus de streaming :
 *   - combien de personnes soutiennent l'artiste,
 *   - combien cela représente en argent (brut),
 *   - ce qui lui revient (sa part KM Family),
 *   - ce qui revient au label.
 *
 * POURQUOI UN REGISTRE PLUTÔT QU'UN CALCUL À LA VOLÉE :
 * le taux de partage KM Family est modifiable à tout moment par le label. Si
 * la part artiste était recalculée à chaque affichage à partir du taux
 * courant, changer le taux en septembre réécrirait rétroactivement ce que
 * l'artiste a gagné en janvier — l'inverse exact de l'objectif de
 * transparence. Le taux appliqué est donc FIGÉ dans la ligne au moment de
 * l'activation, et l'historique reste stable.
 *
 * IMPORTANT : le taux KM Family est volontairement INDÉPENDANT de la
 * commission contractuelle de streaming (ACF `commission_artiste`, utilisée
 * par km_get_artist_commission()). Les deux ne se mélangent jamais.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Revenue {

    const DB_VERSION_OPTION = 'kmfamily_revenue_db_version';
    const DB_VERSION        = '1.0';

    /** Réglages de partage : array( 'default' => float %, 'artists' => array( artiste_id => float % ) ) */
    const OPTION_SPLIT   = 'kmfamily_split_settings';
    const OPTION_BACKFILL = 'kmfamily_revenue_backfilled';

    /** Part artiste par défaut (en %) si rien n'est configuré. */
    const DEFAULT_ARTIST_PCT = 50.0;

    public static function init() {
        self::maybe_upgrade_table();

        // Chemin principal : une commande passe à « active » (webhook auto ou
        // confirmation manuelle admin). Priorité 20 pour laisser les autres
        // consommateurs (notifications, fidélité) s'exécuter d'abord.
        add_action( 'kmfamily_order_activated', array( __CLASS__, 'record_from_order' ), 20, 2 );

        // Chemin secondaire : activation manuelle depuis l'admin, sans commande
        // (KMFamily_Admin::handle_manual_activate → gateway 'manual_admin').
        // Sans ceci, un soutien encaissé en espèces/hors plateforme n'apparaîtrait
        // jamais dans l'espace artiste.
        add_action( 'kmfamily_subscription_activated', array( __CLASS__, 'record_from_subscription' ), 20, 4 );
        add_action( 'kmfamily_subscription_renewed',   array( __CLASS__, 'record_from_subscription' ), 20, 4 );
        add_action( 'kmfamily_subscription_upgraded',  array( __CLASS__, 'record_from_upgrade' ),      20, 5 );

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
        add_action( 'admin_post_kmfamily_save_splits',    array( __CLASS__, 'handle_save_splits' ) );
        add_action( 'admin_post_kmfamily_resync_history', array( __CLASS__, 'handle_resync_history' ) );
    }

    // ==========================================================
    // TABLE
    // ==========================================================

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_artist_revenue';
    }

    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;
        self::create_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        self::maybe_backfill();
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // `source_ref` est UNIQUE : c'est la garantie d'idempotence. Un webhook
        // rejoué, un double-clic admin ou une re-synchronisation ne peuvent pas
        // créditer deux fois le même soutien.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_ref VARCHAR(60) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'order',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            artiste_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            artiste_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            palier VARCHAR(30) NOT NULL DEFAULT '',
            periodicity VARCHAR(20) NOT NULL DEFAULT 'monthly',
            gateway VARCHAR(30) NOT NULL DEFAULT '',
            currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
            montant_brut BIGINT UNSIGNED NOT NULL DEFAULT 0,
            split_artiste_bps INT UNSIGNED NOT NULL DEFAULT 0,
            montant_artiste BIGINT UNSIGNED NOT NULL DEFAULT 0,
            montant_label BIGINT UNSIGNED NOT NULL DEFAULT 0,
            period_ym VARCHAR(7) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_ref (source_ref),
            KEY artiste_id (artiste_id),
            KEY artiste_user_id (artiste_user_id),
            KEY user_id (user_id),
            KEY period_ym (period_ym),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Import unique de l'historique déjà présent dans la table des commandes,
     * pour que l'espace artiste n'affiche pas « 0 soutien » alors que des
     * abonnements sont actifs depuis des mois. Les lignes importées reçoivent
     * le taux configuré au moment de l'import (modifiable ensuite artiste par
     * artiste via « Re-synchroniser l'historique »).
     */
    public static function maybe_backfill() {
        if ( get_option( self::OPTION_BACKFILL ) ) return;
        update_option( self::OPTION_BACKFILL, 1 );

        if ( ! class_exists( 'KMFamily_Orders' ) ) return;

        global $wpdb;
        $orders_table = KMFamily_Orders::table_name();

        // La table des commandes peut ne pas exister sur une installation neuve.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) ) !== $orders_table ) return;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$orders_table}
             WHERE status = 'active' AND product_type = 'subscription' AND montant > 0
             ORDER BY id ASC",
            ARRAY_A
        );

        foreach ( (array) $rows as $order ) {
            $date = $order['active_at'] ?: ( $order['confirmed_at'] ?: $order['created_at'] );
            self::insert_row( array(
                'source_ref'  => $order['order_ref'],
                'source'      => 'order',
                'user_id'     => $order['user_id'],
                'artiste_id'  => $order['artiste_id'],
                'palier'      => $order['palier'],
                'periodicity' => $order['periodicity'],
                'gateway'     => $order['gateway'],
                'currency'    => $order['currency'],
                'montant'     => $order['montant'],
                'created_at'  => $date,
            ) );
        }
    }

    // ==========================================================
    // TAUX DE PARTAGE
    // ==========================================================

    public static function get_split_settings() {
        $s = get_option( self::OPTION_SPLIT, array() );
        if ( ! is_array( $s ) ) $s = array();
        if ( ! isset( $s['default'] ) || $s['default'] === '' ) $s['default'] = self::DEFAULT_ARTIST_PCT;
        if ( ! isset( $s['artists'] ) || ! is_array( $s['artists'] ) ) $s['artists'] = array();
        return $s;
    }

    /**
     * Part artiste applicable (en %, 0-100) pour un artiste donné.
     * Override par artiste s'il existe, sinon taux global du label.
     */
    public static function get_artist_pct( $artiste_id ) {
        $s   = self::get_split_settings();
        $key = (string) absint( $artiste_id );

        if ( isset( $s['artists'][ $key ] ) && $s['artists'][ $key ] !== '' ) {
            $pct = (float) $s['artists'][ $key ];
        } else {
            $pct = (float) $s['default'];
        }

        /**
         * Permet une règle plus fine (par palier, par périodicité, par période
         * promotionnelle...) sans toucher à ce module.
         */
        $pct = (float) apply_filters( 'kmfamily_split_artist_pct', $pct, $artiste_id );

        return max( 0.0, min( 100.0, $pct ) );
    }

    public static function has_artist_override( $artiste_id ) {
        $s   = self::get_split_settings();
        $key = (string) absint( $artiste_id );
        return isset( $s['artists'][ $key ] ) && $s['artists'][ $key ] !== '';
    }

    /** Conversion % → points de base (5000 = 50,00 %), pour un stockage entier exact. */
    public static function pct_to_bps( $pct ) {
        return (int) round( max( 0.0, min( 100.0, (float) $pct ) ) * 100 );
    }

    public static function bps_to_pct( $bps ) {
        return round( absint( $bps ) / 100, 2 );
    }

    /**
     * Répartition d'un montant brut. La part label est calculée par SOUSTRACTION
     * et non par un second arrondi : artiste + label est ainsi toujours
     * rigoureusement égal au brut, sans franc perdu ni créé.
     */
    public static function split_amount( $montant, $bps ) {
        $montant = absint( $montant );
        $artiste = (int) round( $montant * absint( $bps ) / 10000 );
        if ( $artiste > $montant ) $artiste = $montant;
        return array( 'artiste' => $artiste, 'label' => $montant - $artiste );
    }

    // ==========================================================
    // ÉCRITURE DU REGISTRE
    // ==========================================================

    /**
     * Résolution artiste (post 'nos-artistes') → utilisateur WordPress.
     * Même clé que le dashboard streaming (ACF `artiste_user_id`), ce qui fait
     * du post artiste le pivot commun aux deux extensions.
     */
    public static function get_artist_user_id( $artiste_id ) {
        $artiste_id = absint( $artiste_id );
        if ( ! $artiste_id ) return 0;

        $uid = get_post_meta( $artiste_id, 'artiste_user_id', true );
        if ( ! $uid && function_exists( 'get_field' ) ) {
            $uid = get_field( 'artiste_user_id', $artiste_id );
        }
        return absint( $uid );
    }

    private static function insert_row( $args ) {
        global $wpdb;

        $artiste_id = absint( $args['artiste_id'] ?? 0 );
        $montant    = absint( $args['montant'] ?? 0 );
        if ( ! $artiste_id || $montant <= 0 ) return false;

        $bps   = self::pct_to_bps( self::get_artist_pct( $artiste_id ) );
        $split = self::split_amount( $montant, $bps );
        $date  = ! empty( $args['created_at'] ) ? $args['created_at'] : current_time( 'mysql' );

        // INSERT IGNORE : l'unicité de source_ref fait le travail de déduplication
        // au niveau de la base, donc sans fenêtre de course possible (contrairement
        // à un « SELECT puis INSERT » en PHP).
        $table = self::table_name();
        $sql   = $wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (source_ref, source, user_id, artiste_id, artiste_user_id, palier, periodicity, gateway,
              currency, montant_brut, split_artiste_bps, montant_artiste, montant_label, period_ym, created_at)
             VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s, %d, %d, %d, %d, %s, %s)",
            substr( (string) $args['source_ref'], 0, 60 ),
            sanitize_key( $args['source'] ?? 'order' ),
            absint( $args['user_id'] ?? 0 ),
            $artiste_id,
            self::get_artist_user_id( $artiste_id ),
            sanitize_key( $args['palier'] ?? '' ),
            sanitize_key( $args['periodicity'] ?? 'monthly' ),
            sanitize_key( $args['gateway'] ?? '' ),
            sanitize_text_field( $args['currency'] ?? 'XOF' ),
            $montant,
            $bps,
            $split['artiste'],
            $split['label'],
            substr( $date, 0, 7 ),
            $date
        );

        $ok = $wpdb->query( $sql );
        if ( false === $ok ) {
            error_log( 'KM Family - échec écriture registre revenus (' . $args['source_ref'] . ') : ' . $wpdb->last_error );
            return false;
        }

        if ( $ok > 0 ) {
            do_action( 'kmfamily_revenue_recorded', $artiste_id, $montant, $split, $args );
        }
        return true;
    }

    /** Hook : kmfamily_order_activated. */
    public static function record_from_order( $order_ref, $order ) {
        if ( ! is_array( $order ) ) return;
        if ( ( $order['product_type'] ?? 'subscription' ) !== 'subscription' ) return;

        self::insert_row( array(
            'source_ref'  => $order_ref,
            'source'      => 'order',
            'user_id'     => $order['user_id'] ?? 0,
            'artiste_id'  => $order['artiste_id'] ?? 0,
            'palier'      => $order['palier'] ?? '',
            'periodicity' => $order['periodicity'] ?? 'monthly',
            'gateway'     => $order['gateway'] ?? '',
            'currency'    => $order['currency'] ?? 'XOF',
            'montant'     => $order['montant'] ?? 0,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * Hook : activations d'abonnement SANS commande associée.
     * On ne traite QUE la passerelle 'manual_admin' : toutes les autres
     * activations proviennent de confirm_and_activate(), déjà couvert par
     * record_from_order() — les traiter ici aussi doublerait les montants.
     */
    public static function record_from_subscription( $user_id, $artiste_id, $palier, $args ) {
        $args = is_array( $args ) ? $args : array();
        if ( ( $args['gateway'] ?? '' ) !== 'manual_admin' ) return;

        $montant = absint( $args['montant'] ?? 0 );
        if ( $montant <= 0 ) return;

        self::insert_row( array(
            // Référence synthétique unique : une activation manuelle n'a pas de
            // numéro de commande, mais doit rester dédupliquée si le formulaire
            // est soumis deux fois dans la même seconde.
            'source_ref'  => 'KMADM-' . absint( $user_id ) . '-' . absint( $artiste_id ) . '-' . time(),
            'source'      => 'manual',
            'user_id'     => $user_id,
            'artiste_id'  => $artiste_id,
            'palier'      => $palier,
            'periodicity' => $args['periodicity'] ?? 'monthly',
            'gateway'     => 'manual_admin',
            'currency'    => 'XOF',
            'montant'     => $montant,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /** Hook : kmfamily_subscription_upgraded (signature différente). */
    public static function record_from_upgrade( $user_id, $artiste_id, $palier, $ancien_palier, $args ) {
        self::record_from_subscription( $user_id, $artiste_id, $palier, $args );
    }

    // ==========================================================
    // LECTURE / AGRÉGATION (API consommée par le dashboard artiste)
    // ==========================================================

    /**
     * Synthèse complète des soutiens KM Family pour un artiste.
     *
     * @param int   $artiste_id ID du post 'nos-artistes'.
     * @param array $args       'year' => '2026' (optionnel), 'limit_recent' => int.
     */
    public static function get_artist_summary( $artiste_id, $args = array() ) {
        global $wpdb;

        $artiste_id = absint( $artiste_id );
        $args = wp_parse_args( $args, array( 'year' => '', 'limit_recent' => 10 ) );
        $table = self::table_name();

        $summary = array(
            'artiste_id'        => $artiste_id,
            'currency'          => 'XOF',
            'split_pct'         => self::get_artist_pct( $artiste_id ),
            'split_is_override' => self::has_artist_override( $artiste_id ),
            'supporters_actifs' => 0,
            'supporters_uniques'=> 0,
            'transactions'      => 0,
            'brut'              => 0,
            'artiste'           => 0,
            'label'             => 0,
            'brut_mois'         => 0,
            'artiste_mois'      => 0,
            'by_month'          => array(),
            'by_palier'         => array(),
            'recents'           => array(),
            'has_data'          => false,
        );

        if ( ! $artiste_id ) return $summary;

        $where  = $wpdb->prepare( 'artiste_id = %d', $artiste_id );
        if ( $args['year'] ) {
            $where .= $wpdb->prepare( ' AND period_ym LIKE %s', $wpdb->esc_like( (string) $args['year'] ) . '-%' );
        }

        $totals = $wpdb->get_row(
            "SELECT COUNT(*) AS nb, COUNT(DISTINCT user_id) AS nb_users,
                    COALESCE(SUM(montant_brut),0) AS brut,
                    COALESCE(SUM(montant_artiste),0) AS part_artiste,
                    COALESCE(SUM(montant_label),0) AS part_label
             FROM {$table} WHERE {$where}",
            ARRAY_A
        );

        if ( $totals ) {
            $summary['transactions']       = (int) $totals['nb'];
            $summary['supporters_uniques'] = (int) $totals['nb_users'];
            $summary['brut']               = (int) $totals['brut'];
            $summary['artiste']            = (int) $totals['part_artiste'];
            $summary['label']              = (int) $totals['part_label'];
            $summary['has_data']           = $summary['transactions'] > 0;
        }

        // Nombre de soutiens ACTUELLEMENT actifs (abonnement non expiré) —
        // différent du nombre de personnes ayant soutenu au moins une fois.
        if ( class_exists( 'KMFamily_Subscriptions' ) ) {
            $summary['supporters_actifs'] = (int) KMFamily_Subscriptions::count_members_for_artist( $artiste_id );
        }

        // Mois courant
        $ym = substr( current_time( 'mysql' ), 0, 7 );
        $mois = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(montant_brut),0) AS brut, COALESCE(SUM(montant_artiste),0) AS part
             FROM {$table} WHERE artiste_id = %d AND period_ym = %s",
            $artiste_id, $ym
        ), ARRAY_A );
        if ( $mois ) {
            $summary['brut_mois']    = (int) $mois['brut'];
            $summary['artiste_mois'] = (int) $mois['part'];
        }

        // Évolution mensuelle (12 derniers mois présents)
        $rows = $wpdb->get_results(
            "SELECT period_ym, COUNT(*) AS nb, COUNT(DISTINCT user_id) AS nb_users,
                    SUM(montant_brut) AS brut, SUM(montant_artiste) AS part_artiste
             FROM {$table} WHERE {$where}
             GROUP BY period_ym ORDER BY period_ym ASC",
            ARRAY_A
        );
        foreach ( (array) $rows as $r ) {
            $summary['by_month'][ $r['period_ym'] ] = array(
                'nb'       => (int) $r['nb'],
                'nb_users' => (int) $r['nb_users'],
                'brut'     => (int) $r['brut'],
                'artiste'  => (int) $r['part_artiste'],
            );
        }
        if ( count( $summary['by_month'] ) > 12 ) {
            $summary['by_month'] = array_slice( $summary['by_month'], -12, 12, true );
        }

        // Répartition par palier
        $rows = $wpdb->get_results(
            "SELECT palier, COUNT(*) AS nb, COUNT(DISTINCT user_id) AS nb_users,
                    SUM(montant_brut) AS brut, SUM(montant_artiste) AS part_artiste
             FROM {$table} WHERE {$where}
             GROUP BY palier ORDER BY brut DESC",
            ARRAY_A
        );
        foreach ( (array) $rows as $r ) {
            $summary['by_palier'][ $r['palier'] ] = array(
                'nb'       => (int) $r['nb'],
                'nb_users' => (int) $r['nb_users'],
                'brut'     => (int) $r['brut'],
                'artiste'  => (int) $r['part_artiste'],
            );
        }

        // Derniers soutiens
        $limit = max( 1, min( 50, absint( $args['limit_recent'] ) ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, palier, periodicity, montant_brut, montant_artiste, split_artiste_bps, created_at
             FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d",
            $limit
        ), ARRAY_A );

        foreach ( (array) $rows as $r ) {
            $summary['recents'][] = array(
                'nom'         => self::supporter_label( (int) $r['user_id'] ),
                'palier'      => $r['palier'],
                'periodicity' => $r['periodicity'],
                'brut'        => (int) $r['montant_brut'],
                'artiste'     => (int) $r['montant_artiste'],
                'split_pct'   => self::bps_to_pct( $r['split_artiste_bps'] ),
                'date'        => $r['created_at'],
            );
        }

        return $summary;
    }

    /**
     * Nom affichable d'un soutien. Jamais l'e-mail : l'artiste voit qui le
     * soutient, pas de quoi le contacter directement en dehors de la plateforme.
     */
    public static function supporter_label( $user_id ) {
        $user = $user_id ? get_userdata( $user_id ) : null;
        if ( ! $user ) return __( 'Membre', 'km-family' );

        $nom = trim( $user->display_name );
        if ( ! $nom || is_email( $nom ) ) {
            $nom = trim( $user->first_name . ' ' . $user->last_name );
        }
        if ( ! $nom ) $nom = __( 'Membre', 'km-family' ) . ' #' . $user_id;

        return apply_filters( 'kmfamily_supporter_label', $nom, $user_id );
    }

    /** Même synthèse, mais à partir de l'utilisateur WordPress de l'artiste. */
    public static function get_summary_for_user( $artiste_user_id, $args = array() ) {
        $artiste_id = self::get_artiste_id_by_user( $artiste_user_id );
        return self::get_artist_summary( $artiste_id, $args );
    }

    /** Utilisateur artiste → post 'nos-artistes'. */
    public static function get_artiste_id_by_user( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return 0;

        $posts = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_key'       => 'artiste_user_id',
            'meta_value'     => $user_id,
        ) );

        return $posts ? (int) $posts[0] : 0;
    }

    /** Vue label : totaux par artiste. */
    public static function get_label_summary( $year = '' ) {
        global $wpdb;
        $table = self::table_name();

        $where = '1=1';
        if ( $year ) {
            $where .= $wpdb->prepare( ' AND period_ym LIKE %s', $wpdb->esc_like( (string) $year ) . '-%' );
        }

        $rows = $wpdb->get_results(
            "SELECT artiste_id, COUNT(*) AS nb, COUNT(DISTINCT user_id) AS nb_users,
                    SUM(montant_brut) AS brut, SUM(montant_artiste) AS part_artiste, SUM(montant_label) AS part_label
             FROM {$table} WHERE {$where}
             GROUP BY artiste_id ORDER BY brut DESC",
            ARRAY_A
        );

        $out = array( 'artistes' => array(), 'brut' => 0, 'artiste' => 0, 'label' => 0, 'transactions' => 0 );

        foreach ( (array) $rows as $r ) {
            $aid = (int) $r['artiste_id'];
            $out['artistes'][ $aid ] = array(
                'artiste_id' => $aid,
                'nom'        => get_the_title( $aid ) ?: ( __( 'Artiste', 'km-family' ) . ' #' . $aid ),
                'nb'         => (int) $r['nb'],
                'nb_users'   => (int) $r['nb_users'],
                'brut'       => (int) $r['brut'],
                'artiste'    => (int) $r['part_artiste'],
                'label'      => (int) $r['part_label'],
                'split_pct'  => self::get_artist_pct( $aid ),
                'actifs'     => class_exists( 'KMFamily_Subscriptions' ) ? (int) KMFamily_Subscriptions::count_members_for_artist( $aid ) : 0,
            );
            $out['brut']         += (int) $r['brut'];
            $out['artiste']      += (int) $r['part_artiste'];
            $out['label']        += (int) $r['part_label'];
            $out['transactions'] += (int) $r['nb'];
        }

        return $out;
    }

    // ==========================================================
    // ADMIN — Réglage du taux de partage
    // ==========================================================

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Partage & Revenus', 'km-family' ),
            __( 'Partage & Revenus', 'km-family' ),
            'manage_options',
            'kmfamily-splits',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function handle_save_splits() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_save_splits', 'kmfamily_splits_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        $settings = self::get_split_settings();

        if ( isset( $_POST['split_default'] ) ) {
            $settings['default'] = max( 0, min( 100, (float) str_replace( ',', '.', wp_unslash( $_POST['split_default'] ) ) ) );
        }

        $artists = array();
        if ( isset( $_POST['split_artist'] ) && is_array( $_POST['split_artist'] ) ) {
            foreach ( wp_unslash( $_POST['split_artist'] ) as $aid => $val ) {
                $val = trim( (string) $val );
                // Champ laissé vide = pas d'override, l'artiste suit le taux global.
                if ( $val === '' ) continue;
                $artists[ (string) absint( $aid ) ] = max( 0, min( 100, (float) str_replace( ',', '.', $val ) ) );
            }
        }
        $settings['artists'] = $artists;

        update_option( self::OPTION_SPLIT, $settings );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-splits&kmfamily_saved=1' ) );
        exit;
    }

    /**
     * Ré-applique le taux COURANT à tout l'historique d'un artiste.
     *
     * Opération volontairement explicite et manuelle : elle réécrit le passé.
     * Elle existe pour un cas précis et légitime — le taux a été configuré
     * après la mise en service du pont, donc l'historique repris automatiquement
     * porte un taux qui n'a jamais été négocié avec l'artiste.
     */
    public static function handle_resync_history() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_resync_history', 'kmfamily_resync_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        global $wpdb;
        $artiste_id = absint( $_POST['artiste_id'] ?? 0 );
        if ( ! $artiste_id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-splits' ) );
            exit;
        }

        $table = self::table_name();
        $bps   = self::pct_to_bps( self::get_artist_pct( $artiste_id ) );

        // Recalcul ligne par ligne pour conserver l'arrondi exact par transaction
        // (un recalcul global sur le cumul donnerait un total légèrement différent
        // de la somme des lignes affichées à l'artiste).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, montant_brut FROM {$table} WHERE artiste_id = %d",
            $artiste_id
        ), ARRAY_A );

        foreach ( (array) $rows as $r ) {
            $split = self::split_amount( $r['montant_brut'], $bps );
            $wpdb->update( $table, array(
                'split_artiste_bps' => $bps,
                'montant_artiste'   => $split['artiste'],
                'montant_label'     => $split['label'],
            ), array( 'id' => (int) $r['id'] ), array( '%d', '%d', '%d' ), array( '%d' ) );
        }

        // Rafraîchit aussi le lien artiste↔utilisateur, utile si le mapping ACF
        // a été renseigné après coup.
        $wpdb->update( $table,
            array( 'artiste_user_id' => self::get_artist_user_id( $artiste_id ) ),
            array( 'artiste_id' => $artiste_id ), array( '%d' ), array( '%d' )
        );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-splits&kmfamily_resynced=' . count( (array) $rows ) ) );
        exit;
    }

    private static function fcfa( $n ) {
        return number_format( (int) $n, 0, ',', ' ' ) . ' FCFA';
    }

    public static function render_page() {
        $settings = self::get_split_settings();
        $artistes = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        $label = self::get_label_summary();
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Partage & Revenus artistes', 'km-family' ); ?></h1>

            <?php if ( ! empty( $_GET['kmfamily_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Taux de partage enregistrés.', 'km-family' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['kmfamily_resynced'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf( esc_html__( '%d ligne(s) d\'historique recalculée(s) avec le taux courant.', 'km-family' ), absint( $_GET['kmfamily_resynced'] ) ); ?>
                </p></div>
            <?php endif; ?>

            <p style="max-width:820px">
                <?php esc_html_e( 'Ce taux répartit les soutiens KM Family entre l\'artiste et le label. Il est totalement indépendant de la commission contractuelle appliquée aux revenus de streaming : un artiste peut être à 70/30 sur son contrat et à 50/50 sur KM Family.', 'km-family' ); ?>
            </p>
            <p style="max-width:820px">
                <strong><?php esc_html_e( 'À savoir :', 'km-family' ); ?></strong>
                <?php esc_html_e( 'le taux appliqué est figé au moment où le soutien est encaissé. Modifier un taux ici change les soutiens à venir, jamais l\'historique déjà affiché à l\'artiste — sauf action explicite de re-synchronisation ci-dessous.', 'km-family' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_save_splits" />
                <?php wp_nonce_field( 'kmfamily_save_splits', 'kmfamily_splits_nonce' ); ?>

                <h2><?php esc_html_e( 'Taux global du label', 'km-family' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="split_default"><?php esc_html_e( 'Part artiste par défaut', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" step="0.01" min="0" max="100" id="split_default" name="split_default"
                                   value="<?php echo esc_attr( $settings['default'] ); ?>" class="small-text" /> %
                            <p class="description">
                                <?php printf(
                                    esc_html__( 'Appliqué à tout artiste sans taux personnalisé. Part label correspondante : %s %%.', 'km-family' ),
                                    esc_html( round( 100 - (float) $settings['default'], 2 ) )
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Taux par artiste', 'km-family' ); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Artiste', 'km-family' ); ?></th>
                            <th style="width:150px"><?php esc_html_e( 'Part artiste (%)', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Taux effectif', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Soutiens actifs', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Collecté (brut)', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Part artiste', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Part label', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Compte artiste', 'km-family' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $artistes as $a ) :
                        $aid  = $a->ID;
                        $data = $label['artistes'][ $aid ] ?? null;
                        $ovr  = self::has_artist_override( $aid ) ? $settings['artists'][ (string) $aid ] : '';
                        $uid  = self::get_artist_user_id( $aid );
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $a->post_title ); ?></strong></td>
                            <td>
                                <input type="number" step="0.01" min="0" max="100" class="small-text"
                                       name="split_artist[<?php echo esc_attr( $aid ); ?>]"
                                       value="<?php echo esc_attr( $ovr ); ?>"
                                       placeholder="<?php esc_attr_e( 'global', 'km-family' ); ?>" />
                            </td>
                            <td><?php echo esc_html( self::get_artist_pct( $aid ) ); ?> % / <?php echo esc_html( round( 100 - self::get_artist_pct( $aid ), 2 ) ); ?> %</td>
                            <td><?php echo esc_html( $data['actifs'] ?? ( class_exists( 'KMFamily_Subscriptions' ) ? KMFamily_Subscriptions::count_members_for_artist( $aid ) : 0 ) ); ?></td>
                            <td><?php echo esc_html( self::fcfa( $data['brut'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( self::fcfa( $data['artiste'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( self::fcfa( $data['label'] ?? 0 ) ); ?></td>
                            <td>
                                <?php if ( $uid ) : ?>
                                    <span style="color:#008a20">✔ <?php echo esc_html( get_userdata( $uid ) ? get_userdata( $uid )->display_name : $uid ); ?></span>
                                <?php else : ?>
                                    <span style="color:#b32d2e">⚠ <?php esc_html_e( 'non relié', 'km-family' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">
                    <?php esc_html_e( 'Laisser un champ vide = l\'artiste suit le taux global. « Compte artiste » indique si le post artiste est bien relié à un utilisateur WordPress (champ ACF artiste_user_id) — sans ce lien, l\'artiste ne verra rien dans son espace.', 'km-family' ); ?>
                </p>

                <?php submit_button( __( 'Enregistrer les taux', 'km-family' ) ); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Total KM Family', 'km-family' ); ?></h2>
            <p>
                <?php printf(
                    esc_html__( '%1$s collectés sur %2$d soutien(s) — part artistes : %3$s · part label : %4$s', 'km-family' ),
                    '<strong>' . esc_html( self::fcfa( $label['brut'] ) ) . '</strong>',
                    absint( $label['transactions'] ),
                    '<strong>' . esc_html( self::fcfa( $label['artiste'] ) ) . '</strong>',
                    '<strong>' . esc_html( self::fcfa( $label['label'] ) ) . '</strong>'
                ); ?>
            </p>

            <h2><?php esc_html_e( 'Re-synchroniser l\'historique d\'un artiste', 'km-family' ); ?></h2>
            <p style="max-width:820px" class="description">
                <?php esc_html_e( 'Réécrit toutes les lignes passées de cet artiste avec son taux actuel. À n\'utiliser qu\'après avoir fixé un taux qui n\'existait pas encore lors de la reprise de l\'historique : l\'artiste verra ses montants passés changer.', 'km-family' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  onsubmit="return confirm('<?php esc_attr_e( 'Cette action réécrit les montants déjà affichés à l\'artiste. Confirmer ?', 'km-family' ); ?>');">
                <input type="hidden" name="action" value="kmfamily_resync_history" />
                <?php wp_nonce_field( 'kmfamily_resync_history', 'kmfamily_resync_nonce' ); ?>
                <select name="artiste_id">
                    <?php foreach ( $artistes as $a ) : ?>
                        <option value="<?php echo esc_attr( $a->ID ); ?>"><?php echo esc_html( $a->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e( 'Re-synchroniser', 'km-family' ); ?></button>
            </form>
        </div>
        <?php
    }
}
