<?php
/**
 * KM Family — Programme de fidélité
 * ===================================================================
 * Rendu possible par le compte obligatoire (billets ET abonnements, voir
 * KMFamily_Orders::create() / KMFamily_Tickets::create_order()) : chaque
 * commande activée est désormais rattachée à un membre identifié, ce qui
 * permet de lui attribuer des points de façon fiable.
 *
 * Choix délibérés :
 *  - Programme UNIFIÉ : un abonnement ET un billet rapportent des points sur
 *    le même compteur — la fidélité récompense l'engagement global envers
 *    KM Family, pas seulement un type d'achat.
 *  - Idempotent par construction : chaque attribution est journalisée dans un
 *    grand livre (ledger) indexé par order_ref UNIQUE — impossible de créditer
 *    deux fois la même commande, même si le hook se déclenchait deux fois par
 *    accident (webhook rejoué, double confirmation admin...).
 *  - Barème simple et transparent, ajustable via un filtre
 *    ('kmfamily_loyalty_points_for_order') sans toucher au code.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Loyalty {

    const DB_VERSION_OPTION = 'kmfamily_loyalty_db_version';
    const DB_VERSION        = '1.0';
    const BALANCE_META_KEY  = 'kmfamily_loyalty_points'; // cache dénormalisé pour lecture rapide (profil, dashboard)

    // Barème par défaut — ajustable via le filtre 'kmfamily_loyalty_points_for_order'.
    const POINTS_BASE_TICKET       = 10;  // points fixes par commande de billet activée
    const POINTS_BASE_SUBSCRIPTION = 20;  // points fixes par activation/renouvellement d'abonnement
    const POINTS_PER_1000_XOF      = 1;   // + 1 point par tranche de 1000 FCFA dépensés (les deux types)

    public static function init() {
        self::maybe_upgrade_table();
        // Se branche sur l'action déjà tirée par KMFamily_Orders::confirm_and_activate()
        // pour CHAQUE type de commande — aucune modification requise côté Orders/Tickets/
        // Subscriptions pour que ce module fonctionne.
        add_action( 'kmfamily_order_activated', array( __CLASS__, 'award_for_order' ), 20, 2 );
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_loyalty_ledger';
    }

    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;

        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            order_ref VARCHAR(40) NOT NULL,
            product_type VARCHAR(20) NOT NULL DEFAULT 'subscription',
            points INT NOT NULL,
            reason VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_ref (order_ref),
            KEY user_id (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Calcule le nombre de points pour une commande donnée. Séparé de award_for_order()
     * pour être testable/prévisualisable indépendamment (ex. afficher "vous gagnerez X
     * points" avant paiement, sur la page du Smart Payment Center).
     */
    public static function points_for_order( array $order ) {
        $is_ticket = class_exists( 'KMFamily_Orders' ) && KMFamily_Orders::is_ticket_order( $order );
        $base      = $is_ticket ? self::POINTS_BASE_TICKET : self::POINTS_BASE_SUBSCRIPTION;
        $bonus     = (int) floor( ( (float) $order['montant'] / 1000 ) * self::POINTS_PER_1000_XOF );
        $points    = $base + $bonus;

        /**
         * @param int   $points Points calculés par le barème par défaut.
         * @param array $order  Commande complète (product_type, montant, meta...).
         */
        return (int) apply_filters( 'kmfamily_loyalty_points_for_order', $points, $order );
    }

    /**
     * Attribue les points au moment de l'activation — appelé automatiquement via le hook
     * 'kmfamily_order_activated'. Idempotent : une contrainte UNIQUE sur order_ref empêche
     * tout doublon même en cas d'appel multiple.
     */
    public static function award_for_order( $order_ref, $order ) {
        global $wpdb;
        if ( empty( $order['user_id'] ) ) return; // filet de sécurité (ne devrait plus arriver, compte obligatoire)

        $points = self::points_for_order( $order );
        if ( $points <= 0 ) return;

        $inserted = $wpdb->insert( self::table_name(), array(
            'user_id'      => (int) $order['user_id'],
            'order_ref'    => $order_ref,
            'product_type' => $order['product_type'] ?? 'subscription',
            'points'       => $points,
            'reason'       => class_exists( 'KMFamily_Orders' ) && KMFamily_Orders::is_ticket_order( $order )
                ? 'ticket_purchase' : 'subscription_activation',
            'created_at'   => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%d', '%s', '%s' ) );

        // $inserted === false typiquement en cas de doublon (contrainte UNIQUE order_ref) —
        // c'est le comportement voulu, on ne fait rien de plus dans ce cas.
        if ( ! $inserted ) return;

        self::recalculate_balance_cache( (int) $order['user_id'] );

        do_action( 'kmfamily_loyalty_points_awarded', (int) $order['user_id'], $points, $order_ref, $order );
    }

    /**
     * Recalcule et met en cache le solde total (user_meta) — évite de sommer le ledger à
     * chaque affichage (profil, dashboard, barre d'outils admin...).
     */
    private static function recalculate_balance_cache( $user_id ) {
        global $wpdb;
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(points),0) FROM " . self::table_name() . " WHERE user_id = %d",
            $user_id
        ) );
        update_user_meta( $user_id, self::BALANCE_META_KEY, $total );
        return $total;
    }

    public static function get_balance( $user_id ) {
        $cached = get_user_meta( $user_id, self::BALANCE_META_KEY, true );
        if ( '' !== $cached ) return (int) $cached;
        return self::recalculate_balance_cache( $user_id ); // premier appel / cache absent
    }

    public static function get_ledger( $user_id, $limit = 20 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ), ARRAY_A );
    }

    /**
     * Classement (top N) — utile pour un futur affichage "membres les plus fidèles" ou un
     * KPI admin, sans requête ad hoc à écrire à chaque fois.
     */
    public static function get_leaderboard( $limit = 10 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, SUM(points) AS total_points, COUNT(*) AS nb_commandes
             FROM " . self::table_name() . "
             GROUP BY user_id ORDER BY total_points DESC LIMIT %d",
            $limit
        ), ARRAY_A );
    }
}
