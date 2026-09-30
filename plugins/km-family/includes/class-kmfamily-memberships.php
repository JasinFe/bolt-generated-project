<?php
/**
 * KM Family — Socle « Adhésions » (LOT 0)
 *
 * Table relationnelle des adhésions, qui remplace progressivement le stockage
 * historique en `user_meta` sérialisé (`kmfamily_memberships`).
 *
 * POURQUOI CE CHANTIER :
 * le tableau sérialisé en user_meta rend impossible toute requête du type
 * « les membres Or de cet artiste », « les adhésions qui expirent cette
 * semaine », « le churn du mois ». Pire, il rend certaines lectures coûteuses :
 * KMFamily_Subscriptions::count_members_for_artist() chargeait la ligne
 * usermeta de CHAQUE membre du site et la désérialisait en PHP, à chaque appel
 * — et le tableau de bord label en déclenche un par artiste.
 *
 * STRATÉGIE DE MIGRATION — sans rupture :
 *  1. Cette table est alimentée en DOUBLE ÉCRITURE, uniquement par les hooks
 *     déjà émis par KMFamily_Subscriptions. Aucune ligne de l'existant n'est
 *     modifiée, et user_meta continue d'être la source d'écriture.
 *  2. Une reprise unique importe l'historique déjà présent en user_meta.
 *  3. Les lectures basculent sur la table seulement une fois la reprise faite
 *     (is_ready()). Tant qu'elle ne l'est pas, l'ancien chemin reste actif.
 *  4. Retour arrière possible à tout moment : supprimer la table ne casse rien,
 *     user_meta reste complet et autoritaire.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Memberships {

    const DB_VERSION_OPTION = 'kmfamily_memberships_db_version';
    const DB_VERSION        = '1.0';
    const OPTION_MIGRATED   = 'kmfamily_memberships_migrated';

    /**
     * Mode « accès proportionnel au montant » pour le palier Libre.
     * DÉSACTIVÉ PAR DÉFAUT À DESSEIN : l'activer retire l'accès aux membres
     * Libre ayant versé moins que le prix Bronze. C'est une décision
     * commerciale, pas une décision technique — elle doit être prise
     * explicitement, jamais subie lors d'une mise à jour.
     */
    const OPTION_LIBRE_PROPORTIONAL = 'kmfamily_libre_proportional';

    /**
     * Rangs d'accès. Volontairement en dizaines : cela laisse la place à des
     * niveaux intermédiaires (5 = Supporter) sans renuméroter l'existant.
     */
    const RANKS = array(
        'public'    => 0,
        'supporter' => 5,
        'libre'     => 5,
        'bronze'    => 10,
        'argent'    => 20,
        'or'        => 30,
        'platine'   => 40,
        'diamant'   => 50,
    );

    public static function init() {
        self::maybe_upgrade_table();

        // DOUBLE ÉCRITURE — uniquement via les hooks existants, donc sans
        // toucher une ligne de KMFamily_Subscriptions.
        add_action( 'kmfamily_subscription_activated', array( __CLASS__, 'sync_from_hook' ), 5, 4 );
        add_action( 'kmfamily_subscription_renewed',   array( __CLASS__, 'sync_from_hook' ), 5, 4 );
        add_action( 'kmfamily_subscription_upgraded',  array( __CLASS__, 'sync_from_upgrade' ), 5, 5 );
        add_action( 'kmfamily_subscription_cancelled', array( __CLASS__, 'sync_cancelled' ), 5, 2 );
        add_action( 'kmfamily_subscription_expired',   array( __CLASS__, 'sync_expired' ), 5, 3 );

        // Filet de sécurité quotidien : passe en 'expired' les lignes dont la
        // date est dépassée mais dont le hook a pu être manqué (cron sauté,
        // activation manuelle en base, import...).
        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'reconcile_expired' ), 5 );

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 12 );
        add_action( 'admin_post_kmfamily_run_migration', array( __CLASS__, 'handle_run_migration' ) );
        add_action( 'admin_post_kmfamily_save_access',   array( __CLASS__, 'handle_save_access' ) );
    }

    // ==========================================================
    // TABLE
    // ==========================================================

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_memberships';
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

        // UNE ligne par couple (membre, artiste) — même granularité que le
        // tableau user_meta qu'elle remplace. L'historique des transactions
        // vit déjà dans le registre de revenus : on ne le duplique pas ici.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            artiste_id BIGINT UNSIGNED NOT NULL,
            palier VARCHAR(30) NOT NULL DEFAULT '',
            access_level VARCHAR(30) NOT NULL DEFAULT '',
            access_rank SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            periodicity VARCHAR(20) NOT NULL DEFAULT 'monthly',
            amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
            amount_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
            renewals INT UNSIGNED NOT NULL DEFAULT 0,
            auto_renew TINYINT(1) NOT NULL DEFAULT 0,
            source_order VARCHAR(40) NOT NULL DEFAULT '',
            gateway VARCHAR(30) NOT NULL DEFAULT '',
            reminders VARCHAR(120) NOT NULL DEFAULT '',
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_artiste (user_id, artiste_id),
            KEY artiste_id (artiste_id),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY palier (palier),
            KEY access_rank (access_rank),
            KEY started_at (started_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * La table est-elle exploitable en lecture ? Tant que la reprise n'a pas
     * été faite, on refuse de servir des chiffres partiels : mieux vaut une
     * lecture lente et juste qu'une lecture rapide et fausse.
     */
    public static function is_ready() {
        static $ready = null;
        if ( $ready !== null ) return $ready;
        $ready = (bool) get_option( self::OPTION_MIGRATED );
        return $ready;
    }

    // ==========================================================
    // NIVEAU D'ACCÈS (contribution ≠ accès)
    // ==========================================================

    public static function rank_of( $slug ) {
        $slug = (string) $slug;
        return self::RANKS[ $slug ] ?? 0;
    }

    /**
     * Rang requis par un contenu.
     *
     * Un palier VIDE n'est pas « public » : un contenu exclusif dont le champ
     * n'a pas été renseigné doit rester réservé aux membres, pas s'ouvrir à
     * tout le monde par omission. On retient le plancher payant (Bronze), ce
     * qui aligne cette méthode sur KMFamily_Availability::base_palier() — les
     * deux doivent répondre la même chose, sans quoi le paywall et le moteur
     * d'exclusivité divergeraient sur le même contenu.
     *
     * Un palier INCONNU, en revanche, est traité comme inaccessible : c'est
     * probablement une faute de frappe, et mieux vaut un contenu injoignable
     * qu'un contenu ouvert par erreur.
     */
    public static function required_rank( $palier_requis ) {
        if ( $palier_requis === '' || $palier_requis === null ) return self::RANKS['bronze'];
        return self::RANKS[ (string) $palier_requis ] ?? 9999;
    }

    /**
     * Niveau d'accès effectif d'une adhésion.
     *
     * Pour tous les paliers sauf Libre : accès = palier acheté, comportement
     * historique inchangé.
     *
     * Pour Libre, deux modes :
     *  - historique (défaut) : Libre ≡ Bronze, quel que soit le montant ;
     *  - proportionnel (option) : l'accès correspond au palier le plus élevé
     *    dont le prix est couvert par le montant versé. Cela supprime
     *    l'arbitrage (verser 500 pour obtenir l'équivalent de 1 000) et
     *    récompense le membre qui verse 8 000 en libre — il obtient les droits
     *    Or sans avoir eu à cliquer sur le bon bouton.
     *
     * @return array array( 'level' => slug, 'rank' => int )
     */
    public static function resolve_access( $palier, $amount ) {
        $palier = sanitize_key( $palier );
        $amount = absint( $amount );

        if ( $palier !== 'libre' ) {
            return array( 'level' => $palier, 'rank' => self::rank_of( $palier ) );
        }

        if ( ! get_option( self::OPTION_LIBRE_PROPORTIONAL ) ) {
            // Comportement historique : Libre donne les droits Bronze.
            return array( 'level' => 'bronze', 'rank' => self::rank_of( 'bronze' ) );
        }

        $paliers = class_exists( 'KMFamily_Paliers' ) ? KMFamily_Paliers::get_all() : array();
        $best    = array( 'level' => 'supporter', 'rank' => self::rank_of( 'supporter' ) );

        foreach ( array( 'bronze', 'argent', 'or', 'platine', 'diamant' ) as $slug ) {
            $prix = isset( $paliers[ $slug ]['prix'] ) ? (int) $paliers[ $slug ]['prix'] : 0;
            if ( $prix > 0 && $amount >= $prix && self::rank_of( $slug ) > $best['rank'] ) {
                $best = array( 'level' => $slug, 'rank' => self::rank_of( $slug ) );
            }
        }

        return $best;
    }

    // ==========================================================
    // DOUBLE ÉCRITURE
    // ==========================================================

    /**
     * Relit l'adhésion telle qu'elle vient d'être écrite en user_meta et la
     * reflète dans la table. On relit plutôt que de recalculer : user_meta
     * reste la source autoritaire pendant toute la migration, donc aucun
     * risque de divergence entre les deux stockages.
     */
    public static function sync_from_hook( $user_id, $artiste_id, $palier, $args = array() ) {
        if ( ! class_exists( 'KMFamily_Subscriptions' ) ) return;

        $sub = KMFamily_Subscriptions::get_user_subscription( $user_id, $artiste_id );
        if ( ! is_array( $sub ) ) return;

        self::upsert( $user_id, $artiste_id, $sub, is_array( $args ) ? $args : array() );
    }

    public static function sync_from_upgrade( $user_id, $artiste_id, $palier, $ancien_palier, $args = array() ) {
        self::sync_from_hook( $user_id, $artiste_id, $palier, $args );
    }

    public static function sync_cancelled( $user_id, $artiste_id ) {
        global $wpdb;
        $wpdb->update( self::table_name(), array(
            'status'       => 'cancelled',
            'cancelled_at' => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ), array( 'user_id' => absint( $user_id ), 'artiste_id' => absint( $artiste_id ) ),
           array( '%s', '%s', '%s' ), array( '%d', '%d' ) );
    }

    public static function sync_expired( $user_id, $artiste_id, $sub = array() ) {
        global $wpdb;
        $wpdb->update( self::table_name(), array(
            'status'     => 'expired',
            'updated_at' => current_time( 'mysql' ),
        ), array( 'user_id' => absint( $user_id ), 'artiste_id' => absint( $artiste_id ) ),
           array( '%s', '%s' ), array( '%d', '%d' ) );
    }

    /**
     * Insertion ou mise à jour d'une adhésion.
     *
     * @param array $sub  Enregistrement user_meta (palier, expire, montant...).
     * @param array $args Arguments d'activation (gateway, transaction_id...).
     */
    public static function upsert( $user_id, $artiste_id, array $sub, array $args = array(), $is_backfill = false ) {
        global $wpdb;

        $user_id    = absint( $user_id );
        $artiste_id = absint( $artiste_id );
        if ( ! $user_id || ! $artiste_id ) return false;

        $palier  = sanitize_key( $sub['palier'] ?? '' );
        $amount  = absint( $sub['montant'] ?? 0 );
        $access  = self::resolve_access( $palier, $amount );
        $now     = current_time( 'mysql' );

        $expires = ! empty( $sub['expire'] )
            ? gmdate( 'Y-m-d H:i:s', (int) $sub['expire'] + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) )
            : $now;
        $started = ! empty( $sub['date_debut'] )
            ? gmdate( 'Y-m-d H:i:s', (int) $sub['date_debut'] + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) )
            : $now;

        $status = ( ! empty( $sub['expire'] ) && (int) $sub['expire'] < time() ) ? 'expired' : 'active';

        $existing = self::get( $user_id, $artiste_id );

        $data = array(
            'user_id'      => $user_id,
            'artiste_id'   => $artiste_id,
            'palier'       => $palier,
            'access_level' => $access['level'],
            'access_rank'  => $access['rank'],
            'status'       => $status,
            'periodicity'  => sanitize_key( $sub['periodicity'] ?? 'monthly' ),
            'amount'       => $amount,
            'currency'     => 'XOF',
            'gateway'      => sanitize_key( $sub['gateway'] ?? ( $args['gateway'] ?? '' ) ),
            'source_order' => sanitize_text_field( (string) ( $args['transaction_id'] ?? ( $sub['transaction_id'] ?? '' ) ) ),
            'started_at'   => $started,
            'expires_at'   => $expires,
            'updated_at'   => $now,
        );

        if ( $existing ) {
            // Nouvelle échéance = nouveau cycle payé : on incrémente le compteur
            // de renouvellements et on cumule la contribution. Un simple
            // re-sync (même échéance) ne doit rien incrémenter, sinon le churn
            // et l'ARPU deviennent faux.
            $is_new_cycle = ( $expires !== $existing['expires_at'] ) && ! $is_backfill;

            $data['renewals']     = (int) $existing['renewals'] + ( $is_new_cycle ? 1 : 0 );
            $data['amount_total'] = (int) $existing['amount_total'] + ( $is_new_cycle ? $amount : 0 );
            $data['created_at']   = $existing['created_at'];

            // Une adhésion réactivée n'est plus annulée.
            if ( $status === 'active' ) $data['cancelled_at'] = null;

            $wpdb->update( self::table_name(), $data,
                array( 'user_id' => $user_id, 'artiste_id' => $artiste_id ) );
        } else {
            $data['renewals']     = 0;
            $data['amount_total'] = $amount;
            $data['created_at']   = $now;
            $wpdb->insert( self::table_name(), $data );
        }

        return true;
    }

    /**
     * Filet quotidien : bascule en 'expired' toute adhésion dont l'échéance est
     * passée. Sans ça, une adhésion dont le hook d'expiration a été manqué
     * resterait éternellement comptée comme active dans les statistiques.
     */
    public static function reconcile_expired() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'expired', updated_at = %s
             WHERE status = 'active' AND expires_at < %s",
            current_time( 'mysql' ), current_time( 'mysql' )
        ) );
    }

    // ==========================================================
    // REPRISE DE L'EXISTANT
    // ==========================================================

    /**
     * Importe les adhésions déjà stockées en user_meta.
     * Idempotent : relançable sans créer de doublon (clé unique user+artiste).
     *
     * @return array array( 'users' => int, 'rows' => int )
     */
    public static function run_migration() {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            KMFamily_Subscriptions::META_KEY
        ) );

        $n_users = 0;
        $n_rows  = 0;

        foreach ( (array) $rows as $row ) {
            $subs = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $subs ) || ! $subs ) continue;
            $n_users++;

            foreach ( $subs as $artiste_id => $sub ) {
                if ( ! is_array( $sub ) ) continue;
                if ( self::upsert( $row->user_id, $artiste_id, $sub, array(), true ) ) $n_rows++;
            }
        }

        update_option( self::OPTION_MIGRATED, 1 );
        return array( 'users' => $n_users, 'rows' => $n_rows );
    }

    // ==========================================================
    // LECTURE — l'API qui n'existait pas
    // ==========================================================

    public static function get( $user_id, $artiste_id ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND artiste_id = %d",
            absint( $user_id ), absint( $artiste_id )
        ), ARRAY_A ) ?: null;
    }

    public static function count_active_for_artist( $artiste_id ) {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE artiste_id = %d AND status = 'active' AND expires_at > %s",
            absint( $artiste_id ), current_time( 'mysql' )
        ) );
    }

    public static function count_total_active() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$table}
             WHERE status = 'active' AND expires_at > %s",
            current_time( 'mysql' )
        ) );
    }

    /**
     * Membres d'un artiste, filtrables et paginés — la requête qui était
     * tout simplement impossible avant cette table.
     *
     * @param array $args artiste_id, palier, status, orderby, order, limit, offset
     */
    public static function query_members( $args = array() ) {
        global $wpdb;
        $table = self::table_name();

        $args = wp_parse_args( $args, array(
            'artiste_id' => 0,
            'palier'     => '',
            'status'     => 'active',
            'min_rank'   => 0,
            'orderby'    => 'expires_at',
            'order'      => 'ASC',
            'limit'      => 50,
            'offset'     => 0,
        ) );

        $where = array( '1=1' );
        if ( $args['artiste_id'] ) $where[] = $wpdb->prepare( 'artiste_id = %d', absint( $args['artiste_id'] ) );
        if ( $args['palier'] )     $where[] = $wpdb->prepare( 'palier = %s', sanitize_key( $args['palier'] ) );
        if ( $args['min_rank'] )   $where[] = $wpdb->prepare( 'access_rank >= %d', absint( $args['min_rank'] ) );

        if ( $args['status'] === 'active' ) {
            $where[] = $wpdb->prepare( "status = 'active' AND expires_at > %s", current_time( 'mysql' ) );
        } elseif ( $args['status'] && $args['status'] !== 'any' ) {
            $where[] = $wpdb->prepare( 'status = %s', sanitize_key( $args['status'] ) );
        }

        // Liste blanche stricte : ces deux valeurs entrent dans la requête sans
        // pouvoir être préparées, elles ne doivent donc jamais venir de l'entrée.
        $allowed_orderby = array( 'expires_at', 'started_at', 'amount', 'amount_total', 'renewals', 'access_rank' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'expires_at';
        $order   = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) .
            " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            max( 1, min( 500, absint( $args['limit'] ) ) ), absint( $args['offset'] )
        ), ARRAY_A );
    }

    /**
     * Adhésions arrivant à échéance dans exactement N jours (fenêtre d'un jour).
     * Base de la relance de renouvellement.
     */
    public static function get_expiring_in_days( $days, $artiste_id = 0 ) {
        global $wpdb;
        $table = self::table_name();

        // BUG CORRIGÉ (trouvé au banc d'essai) : la fenêtre était construite en
        // concaténant " +{$days} days". Pour un décalage négatif, cela produit
        // la chaîne " +-7 days", que strtotime() n'interprète PAS comme -7
        // jours — la fenêtre basculait dans le futur. Résultat : les relances
        // d'APRÈS expiration (J+2, J+7, J+15) visaient les mauvaises adhésions,
        // et un membre encore actif recevait un email lui annonçant la fin de
        // son adhésion. On calcule donc le décalage en arithmétique pure,
        // sans jamais passer par l'analyse d'une chaîne relative.
        $days   = (int) $days;
        $target = strtotime( current_time( 'mysql' ) ) + ( $days * DAY_IN_SECONDS );
        $start  = date( 'Y-m-d 00:00:00', $target );
        $end    = date( 'Y-m-d 23:59:59', $target );

        $where = $wpdb->prepare( 'expires_at BETWEEN %s AND %s', $start, $end );
        if ( $artiste_id ) $where .= $wpdb->prepare( ' AND artiste_id = %d', absint( $artiste_id ) );

        // Les adhésions annulées volontairement ne sont jamais relancées.
        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE {$where} AND status <> 'cancelled' ORDER BY id ASC",
            ARRAY_A
        );
    }

    /**
     * Statistiques d'ensemble. Tout ce bloc était hors de portée avec le
     * stockage en user_meta.
     */
    public static function get_stats( $args = array() ) {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql' );

        $args = wp_parse_args( $args, array( 'artiste_id' => 0, 'days' => 30 ) );
        $scope = $args['artiste_id'] ? $wpdb->prepare( ' AND artiste_id = %d', absint( $args['artiste_id'] ) ) : '';
        $since = date( 'Y-m-d H:i:s', strtotime( $now . ' -' . absint( $args['days'] ) . ' days' ) );

        $actifs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status='active' AND expires_at > %s {$scope}", $now ) );

        $nouveaux = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s {$scope}", $since ) );

        $renouvelles = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE renewals > 0 AND updated_at >= %s {$scope}", $since ) );

        $perdus = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status IN ('expired','cancelled') AND expires_at BETWEEN %s AND %s {$scope}",
            $since, $now ) );

        $revenu = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_total),0) FROM {$table} WHERE 1=1 {$scope}" );

        // Churn = perdus sur la période / base au début de la période.
        // Dénominateur = actifs aujourd'hui + perdus sur la période, ce qui
        // reconstitue la base de départ sans avoir besoin d'un historique daté.
        $base   = $actifs + $perdus;
        $churn  = $base > 0 ? round( $perdus / $base * 100, 1 ) : 0.0;
        $arpu   = $actifs > 0 ? (int) round( $revenu / $actifs ) : 0;

        // Le tri secondaire n'est pas cosmétique : à nombre de membres égal,
        // un ORDER BY sur la seule colonne `nb` laisse la base choisir, et le
        // « palier dominant » affiché change alors d'un rechargement à
        // l'autre. On départage donc par la contribution, puis par le nom.
        $par_palier = $wpdb->get_results( $wpdb->prepare(
            "SELECT palier, COUNT(*) AS nb, COALESCE(SUM(amount_total),0) AS total
             FROM {$table} WHERE status='active' AND expires_at > %s {$scope}
             GROUP BY palier ORDER BY nb DESC, total DESC, palier ASC", $now ), ARRAY_A );

        $expirent_7j = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status='active' AND expires_at BETWEEN %s AND %s {$scope}",
            $now, date( 'Y-m-d H:i:s', strtotime( $now . ' +7 days' ) ) ) );

        return array(
            'actifs'       => $actifs,
            'nouveaux'     => $nouveaux,
            'renouvelles'  => $renouvelles,
            'perdus'       => $perdus,
            'churn_pct'    => $churn,
            'revenu_total' => $revenu,
            'arpu'         => $arpu,
            'expirent_7j'  => $expirent_7j,
            'par_palier'   => $par_palier,
            'periode_j'    => absint( $args['days'] ),
        );
    }

    /** Marque une étape de relance comme envoyée (idempotence des relances). */
    public static function mark_reminder_sent( $membership_id, $stage ) {
        global $wpdb;
        $table = self::table_name();

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT reminders FROM {$table} WHERE id = %d", absint( $membership_id ) ), ARRAY_A );
        if ( ! $row ) return false;

        $sent = array_filter( explode( ',', (string) $row['reminders'] ) );
        if ( in_array( $stage, $sent, true ) ) return false; // déjà envoyée

        $sent[] = $stage;
        $wpdb->update( $table,
            array( 'reminders' => substr( implode( ',', $sent ), 0, 120 ) ),
            array( 'id' => absint( $membership_id ) ), array( '%s' ), array( '%d' ) );

        return true;
    }

    /** Remet le compteur de relances à zéro (nouveau cycle payé). */
    public static function reset_reminders( $user_id, $artiste_id ) {
        global $wpdb;
        $wpdb->update( self::table_name(), array( 'reminders' => '' ),
            array( 'user_id' => absint( $user_id ), 'artiste_id' => absint( $artiste_id ) ),
            array( '%s' ), array( '%d', '%d' ) );
    }

    // ==========================================================
    // ADMIN
    // ==========================================================

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Socle & Migration', 'km-family' ),
            __( 'Socle & Migration', 'km-family' ),
            'manage_options',
            'kmfamily-socle',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function handle_run_migration() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_run_migration', 'kmfamily_migration_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }
        $res = self::run_migration();
        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-socle&migrated=' . absint( $res['rows'] ) ) );
        exit;
    }

    public static function handle_save_access() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_save_access', 'kmfamily_access_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        $enable = ! empty( $_POST['libre_proportional'] ) ? 1 : 0;
        update_option( self::OPTION_LIBRE_PROPORTIONAL, $enable );

        // Le changement de règle doit se refléter immédiatement sur les
        // adhésions Libre existantes, sinon l'accès resterait figé sur
        // l'ancienne règle jusqu'au prochain renouvellement.
        self::recalculate_libre_access();

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-socle&access_saved=1' ) );
        exit;
    }

    public static function recalculate_libre_access() {
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( "SELECT id, palier, amount FROM {$table} WHERE palier = 'libre'", ARRAY_A );

        foreach ( (array) $rows as $r ) {
            $a = self::resolve_access( 'libre', $r['amount'] );
            $wpdb->update( $table,
                array( 'access_level' => $a['level'], 'access_rank' => $a['rank'], 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => (int) $r['id'] ), array( '%s', '%d', '%s' ), array( '%d' ) );
        }
        return count( (array) $rows );
    }

    public static function render_page() {
        global $wpdb;
        $table   = self::table_name();
        $nb_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $nb_meta = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", KMFamily_Subscriptions::META_KEY ) );
        $stats = self::is_ready() ? self::get_stats() : null;
        $prop  = (bool) get_option( self::OPTION_LIBRE_PROPORTIONAL );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Socle des adhésions', 'km-family' ); ?></h1>

            <?php if ( isset( $_GET['migrated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf( esc_html__( '%d adhésion(s) reprises dans le socle.', 'km-family' ), absint( $_GET['migrated'] ) ); ?>
                </p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['access_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Règle d\'accès enregistrée et appliquée aux adhésions existantes.', 'km-family' ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Migration', 'km-family' ); ?></h2>
            <p>
                <?php printf(
                    esc_html__( 'Socle : %1$d adhésion(s) en table. Stockage historique : %2$d membre(s) avec au moins une adhésion en user_meta.', 'km-family' ),
                    $nb_rows, $nb_meta
                ); ?>
            </p>
            <p>
                <?php if ( self::is_ready() ) : ?>
                    <span style="color:#008a20;font-weight:600">✔ <?php esc_html_e( 'Reprise effectuée — les lectures utilisent le socle.', 'km-family' ); ?></span>
                <?php else : ?>
                    <span style="color:#b32d2e;font-weight:600">⚠ <?php esc_html_e( 'Reprise non effectuée — les lectures utilisent encore l\'ancien chemin (lent).', 'km-family' ); ?></span>
                <?php endif; ?>
            </p>
            <p class="description" style="max-width:820px">
                <?php esc_html_e( 'La reprise est relançable sans risque : elle ne crée jamais de doublon et n\'écrit rien dans le stockage historique, qui reste la source autoritaire pendant toute la migration.', 'km-family' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_run_migration" />
                <?php wp_nonce_field( 'kmfamily_run_migration', 'kmfamily_migration_nonce' ); ?>
                <button type="submit" class="button button-primary">
                    <?php echo self::is_ready() ? esc_html__( 'Relancer la reprise', 'km-family' ) : esc_html__( 'Lancer la reprise', 'km-family' ); ?>
                </button>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Palier Libre — contribution et accès', 'km-family' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_save_access" />
                <?php wp_nonce_field( 'kmfamily_save_access', 'kmfamily_access_nonce' ); ?>
                <label>
                    <input type="checkbox" name="libre_proportional" value="1" <?php checked( $prop ); ?> />
                    <?php esc_html_e( 'Accès proportionnel au montant versé', 'km-family' ); ?>
                </label>
                <p class="description" style="max-width:820px">
                    <?php esc_html_e( 'Désactivé (défaut) : un membre Libre a les droits Bronze, quel que soit son montant — comportement actuel.', 'km-family' ); ?><br />
                    <?php esc_html_e( 'Activé : l\'accès correspond au palier le plus élevé couvert par le montant versé. Un membre à 8 000 FCFA obtient les droits Or ; un membre à 500 FCFA passe sous le niveau Bronze et PERD l\'accès aux contenus Bronze.', 'km-family' ); ?>
                </p>
                <p class="description" style="max-width:820px;color:#b32d2e">
                    <strong><?php esc_html_e( 'Attention :', 'km-family' ); ?></strong>
                    <?php esc_html_e( 'activer cette option retire immédiatement l\'accès à des membres qui l\'avaient. Prévenez-les avant.', 'km-family' ); ?>
                </p>
                <?php submit_button( __( 'Enregistrer la règle d\'accès', 'km-family' ) ); ?>
            </form>

            <?php if ( $stats ) : ?>
            <hr />
            <h2><?php esc_html_e( 'Aperçu (30 derniers jours)', 'km-family' ); ?></h2>
            <table class="widefat striped" style="max-width:720px">
                <tbody>
                    <tr><td><?php esc_html_e( 'Adhésions actives', 'km-family' ); ?></td><td><strong><?php echo esc_html( $stats['actifs'] ); ?></strong></td></tr>
                    <tr><td><?php esc_html_e( 'Nouvelles adhésions', 'km-family' ); ?></td><td><?php echo esc_html( $stats['nouveaux'] ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Renouvellements', 'km-family' ); ?></td><td><?php echo esc_html( $stats['renouvelles'] ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Perdues (expirées / annulées)', 'km-family' ); ?></td><td><?php echo esc_html( $stats['perdus'] ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Taux d\'attrition (churn)', 'km-family' ); ?></td><td><strong><?php echo esc_html( $stats['churn_pct'] ); ?> %</strong></td></tr>
                    <tr><td><?php esc_html_e( 'Contribution moyenne par membre actif', 'km-family' ); ?></td><td><?php echo esc_html( number_format( $stats['arpu'], 0, ',', ' ' ) ); ?> FCFA</td></tr>
                    <tr><td><?php esc_html_e( 'Expirent dans les 7 jours', 'km-family' ); ?></td><td><strong><?php echo esc_html( $stats['expirent_7j'] ); ?></strong></td></tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
