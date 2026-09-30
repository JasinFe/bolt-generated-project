<?php
/**
 * KM Family — Catalogue de récompenses (LOT 6)
 *
 * Ferme la boucle : je soutiens → j'utilise → je gagne des points → j'obtiens
 * quelque chose → je reviens.
 *
 * DEUX PRINCIPES QUI GOUVERNENT TOUT LE MODULE :
 *
 *  1. Chaque récompense porte sa VALEUR RÉELLE en francs, distincte de son coût
 *     en points. Sans ce champ, impossible de chiffrer ce que le stock de
 *     points engage : on saurait combien de points circulent, jamais combien
 *     ils coûtent. C'est une saisie de plus à chaque création, et elle est
 *     obligatoire pour cette raison.
 *
 *  2. Le débit est écrit AVANT la réservation du stock, et le stock est
 *     décrémenté par une requête conditionnelle. Deux membres qui échangent le
 *     dernier billet à la même seconde ne peuvent donc pas l'obtenir tous les
 *     deux : le second voit son débit annulé et ses points restitués.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Rewards {

    const DB_VERSION_OPTION = 'kmfamily_rewards_db_version';
    const DB_VERSION        = '1.0';

    public static function init() {
        self::maybe_upgrade_table();

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 17 );
        add_action( 'admin_post_kmfamily_save_reward',   array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_kmfamily_delete_reward', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_kmfamily_fulfil',        array( __CLASS__, 'handle_fulfil' ) );

        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_shortcode( 'kmfamily_recompenses', array( __CLASS__, 'shortcode' ) );
    }

    public static function table_rewards() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_rewards';
    }

    public static function table_redemptions() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_redemptions';
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

        $r = self::table_rewards();
        dbDelta( "CREATE TABLE {$r} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            titre VARCHAR(140) NOT NULL DEFAULT '',
            description TEXT,
            type VARCHAR(30) NOT NULL DEFAULT 'autre',
            cout_points INT UNSIGNED NOT NULL DEFAULT 0,
            valeur_xof INT UNSIGNED NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT -1,
            artiste_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY actif (actif),
            KEY artiste_id (artiste_id)
        ) {$cc};" );

        $d = self::table_redemptions();
        dbDelta( "CREATE TABLE {$d} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            reward_id BIGINT UNSIGNED NOT NULL,
            titre VARCHAR(140) NOT NULL DEFAULT '',
            points INT UNSIGNED NOT NULL DEFAULT 0,
            valeur_xof INT UNSIGNED NOT NULL DEFAULT 0,
            code VARCHAR(20) NOT NULL DEFAULT '',
            statut VARCHAR(20) NOT NULL DEFAULT 'en_attente',
            created_at DATETIME NOT NULL,
            fulfilled_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY user_id (user_id),
            KEY statut (statut)
        ) {$cc};" );
    }

    public static function types() {
        return array(
            'contenu'   => __( 'Contenu exclusif', 'km-family' ),
            'reduction' => __( 'Réduction', 'km-family' ),
            'billet'    => __( 'Billet / invitation', 'km-family' ),
            'merch'     => __( 'Marchandise', 'km-family' ),
            'experience'=> __( 'Expérience / rencontre', 'km-family' ),
            'tirage'    => __( 'Participation à un tirage', 'km-family' ),
            'autre'     => __( 'Autre', 'km-family' ),
        );
    }

    // ==========================================================
    // LECTURE
    // ==========================================================

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_rewards() . " WHERE id = %d", absint( $id )
        ), ARRAY_A ) ?: null;
    }

    public static function get_all( $actives_seulement = false ) {
        global $wpdb;
        $where = $actives_seulement ? 'WHERE actif = 1' : '';
        return $wpdb->get_results(
            "SELECT * FROM " . self::table_rewards() . " {$where} ORDER BY cout_points ASC", ARRAY_A );
    }

    public static function disponible( array $r ) {
        if ( ! (int) $r['actif'] ) return false;
        return ( (int) $r['stock'] < 0 ) || ( (int) $r['stock'] > 0 );
    }

    /**
     * Valeur moyenne d'un point, en francs, déduite du catalogue actif.
     * Pondérée par la valeur : une récompense chère pèse davantage sur
     * l'estimation qu'un fond d'écran.
     */
    public static function taux_conversion() {
        $total_valeur = 0;
        $total_points = 0;

        foreach ( self::get_all( true ) as $r ) {
            if ( (int) $r['cout_points'] <= 0 ) continue;
            $total_valeur += (int) $r['valeur_xof'];
            $total_points += (int) $r['cout_points'];
        }

        return $total_points > 0 ? round( $total_valeur / $total_points, 4 ) : 0.0;
    }

    public static function get_user_redemptions( $user_id, $limit = 20 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table_redemptions() . "
             WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            absint( $user_id ), max( 1, min( 100, (int) $limit ) )
        ), ARRAY_A );
    }

    // ==========================================================
    // ÉCHANGE
    // ==========================================================

    private static function code_unique() {
        // Alphabet sans caractères ambigus : un code se lit au téléphone ou se
        // recopie à la main sur un cahier de caisse.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = 'KM-';
        for ( $i = 0; $i < 8; $i++ ) {
            $code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
        }
        return $code;
    }

    /**
     * Échange des points contre une récompense.
     *
     * @return array|WP_Error
     */
    public static function redeem( $user_id, $reward_id ) {
        global $wpdb;

        $user_id   = absint( $user_id );
        $reward_id = absint( $reward_id );

        $r = self::get( $reward_id );
        if ( ! $r || ! self::disponible( $r ) ) {
            return new WP_Error( 'indisponible', __( 'Cette récompense n\'est plus disponible.', 'km-family' ) );
        }

        if ( (int) $r['artiste_id'] && class_exists( 'KMFamily_Access' ) ) {
            // Une récompense rattachée à un artiste n'a de sens que pour ceux
            // qui le soutiennent.
            if ( ! KMFamily_Access::user_has_access( $user_id, (int) $r['artiste_id'], 'bronze' ) ) {
                return new WP_Error( 'reserve', __( 'Cette récompense est réservée aux membres de cet artiste.', 'km-family' ) );
            }
        }

        $cout  = (int) $r['cout_points'];
        $solde = (int) KMFamily_Loyalty::get_balance( $user_id );

        if ( $cout <= 0 ) {
            return new WP_Error( 'invalide', __( 'Récompense mal configurée.', 'km-family' ) );
        }
        if ( $solde < $cout ) {
            return new WP_Error( 'solde', sprintf(
                __( 'Il vous manque %s points.', 'km-family' ),
                number_format( $cout - $solde, 0, ',', ' ' )
            ) );
        }

        // ── 1. Réservation du stock, en une requête conditionnelle ──
        // C'est la base qui arbitre : deux échanges simultanés du dernier
        // exemplaire ne peuvent pas réussir tous les deux, quelle que soit la
        // vitesse de PHP.
        if ( (int) $r['stock'] >= 0 ) {
            $table = self::table_rewards();
            $pris  = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET stock = stock - 1 WHERE id = %d AND stock > 0", $reward_id
            ) );
            if ( ! $pris ) {
                return new WP_Error( 'epuise', __( 'Cette récompense vient d\'être épuisée.', 'km-family' ) );
            }
        }

        // ── 2. Création de l'échange ──
        $code = self::code_unique();
        $ok = $wpdb->insert( self::table_redemptions(), array(
            'user_id'    => $user_id,
            'reward_id'  => $reward_id,
            'titre'      => $r['titre'],
            'points'     => $cout,
            'valeur_xof' => (int) $r['valeur_xof'],
            'code'       => $code,
            'statut'     => 'en_attente',
            'created_at' => current_time( 'mysql' ),
        ) );

        if ( ! $ok ) {
            self::restituer_stock( $reward_id, $r );
            return new WP_Error( 'db', __( 'L\'échange n\'a pas pu être enregistré.', 'km-family' ) );
        }

        $redemption_id = (int) $wpdb->insert_id;

        // ── 3. Débit des points ──
        // CORRECTIF v3.3.1 — COURSE SUR LE SOLDE.
        // Le stock était bien arbitré par la base (UPDATE conditionnel plus haut), mais pas
        // les points : la vérification `$solde < $cout` est une LECTURE, et le débit n'arrive
        // qu'ici. Deux requêtes d'échange simultanées (double-clic, requête rejouée par le
        // navigateur, ou envoi délibéré en parallèle) passaient toutes les deux le test avec
        // le même solde, et débitaient chacune leur tour — le membre repartait avec deux
        // récompenses pour le prix d'une, et un solde devenu négatif.
        // On revérifie donc le solde juste avant le débit, puis une dernière fois après, en
        // annulant l'échange si le total est passé sous zéro : la somme du registre étant
        // recalculée à chaque écriture, c'est elle qui arbitre, pas la lecture initiale.
        if ( (int) KMFamily_Loyalty::get_balance( $user_id ) < $cout ) {
            $wpdb->delete( self::table_redemptions(), array( 'id' => $redemption_id ), array( '%d' ) );
            self::restituer_stock( $reward_id, $r );
            return new WP_Error( 'solde', __( 'Solde de points insuffisant.', 'km-family' ) );
        }

        $debit = KMFamily_Loyalty_Plus::ecrire(
            $user_id, 'RDM-' . $redemption_id, -$cout,
            'echange_' . sanitize_key( $r['type'] ), 'redemption'
        );

        if ( $debit && (int) KMFamily_Loyalty::get_balance( $user_id ) < 0 ) {
            // Un échange concurrent est passé entre-temps : on annule celui-ci (la ligne de
            // débit comprise) pour ne jamais laisser un solde négatif ni une récompense
            // non payée.
            KMFamily_Loyalty_Plus::ecrire(
                $user_id, 'RDM-' . $redemption_id . '-ANN', $cout,
                'annulation_echange', 'redemption'
            );
            $wpdb->delete( self::table_redemptions(), array( 'id' => $redemption_id ), array( '%d' ) );
            self::restituer_stock( $reward_id, $r );
            return new WP_Error( 'solde', __( 'Solde de points insuffisant.', 'km-family' ) );
        }

        if ( ! $debit ) {
            // Aucun point n'a été retiré : on annule tout plutôt que de laisser
            // le membre avec une récompense gratuite et le stock entamé.
            $wpdb->delete( self::table_redemptions(), array( 'id' => $redemption_id ), array( '%d' ) );
            self::restituer_stock( $reward_id, $r );
            return new WP_Error( 'debit', __( 'Le débit des points a échoué, l\'échange est annulé.', 'km-family' ) );
        }

        do_action( 'kmfamily_reward_redeemed', $user_id, $reward_id, $redemption_id, $code );
        self::notifier_admin( $user_id, $r, $code );

        return array( 'id' => $redemption_id, 'code' => $code, 'titre' => $r['titre'], 'points' => $cout );
    }

    private static function restituer_stock( $reward_id, array $r ) {
        global $wpdb;
        if ( (int) $r['stock'] < 0 ) return;
        $table = self::table_rewards();
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET stock = stock + 1 WHERE id = %d", absint( $reward_id ) ) );
    }

    private static function notifier_admin( $user_id, array $r, $code ) {
        $to = get_option( 'admin_email' );
        if ( ! is_email( $to ) ) return;

        $user = get_userdata( $user_id );
        wp_mail(
            $to,
            sprintf( __( '[KM Family] Échange de points : %s', 'km-family' ), $r['titre'] ),
            sprintf(
                __( "%1\$s vient d'échanger ses points contre « %2\$s ».\n\nCode : %3\$s\n\nÀ honorer depuis KM Family → Récompenses.", 'km-family' ),
                $user ? $user->display_name : '#' . $user_id,
                $r['titre'],
                $code
            )
        );
    }

    /** Marque un échange comme honoré. */
    public static function fulfil( $redemption_id ) {
        global $wpdb;
        return (bool) $wpdb->update( self::table_redemptions(), array(
            'statut'       => 'honoree',
            'fulfilled_at' => current_time( 'mysql' ),
        ), array( 'id' => absint( $redemption_id ) ), array( '%s', '%s' ), array( '%d' ) );
    }

    // ==========================================================
    // API REST
    // ==========================================================

    public static function register_routes() {
        register_rest_route( 'kmfamily/v1', '/rewards/redeem', array(
            'methods'             => 'POST',
            'permission_callback' => function () { return is_user_logged_in(); },
            'callback'            => array( __CLASS__, 'rest_redeem' ),
        ) );
    }

    public static function rest_redeem( WP_REST_Request $req ) {
        $res = self::redeem( get_current_user_id(), absint( $req->get_param( 'reward_id' ) ) );
        if ( is_wp_error( $res ) ) return $res;
        return new WP_REST_Response( $res, 200 );
    }

    // ==========================================================
    // AFFICHAGE MEMBRE
    // ==========================================================

    public static function shortcode() {
        if ( ! is_user_logged_in() ) return '';

        $user_id = get_current_user_id();
        $solde   = (int) KMFamily_Loyalty::get_balance( $user_id );
        $liste   = self::get_all( true );
        $miens   = self::get_user_redemptions( $user_id, 10 );

        if ( ! $liste && ! $miens ) return '';

        wp_enqueue_style( 'kmfamily-hub' );
        wp_enqueue_script( 'kmfamily-rewards' );

        ob_start(); ?>
<div class="kmf-recompenses" data-kmf-recompenses>
    <div class="kmh-title-row">
        <h3 class="kmh-title"><?php esc_html_e( 'Récompenses', 'km-family' ); ?></h3>
        <span class="kmf-solde"><?php echo esc_html( number_format( $solde, 0, ',', ' ' ) ); ?> <?php esc_html_e( 'points', 'km-family' ); ?></span>
    </div>

    <div class="kmh-grid">
    <?php foreach ( $liste as $r ) :
        $cout      = (int) $r['cout_points'];
        $abordable = $solde >= $cout;
        $epuise    = ! self::disponible( $r );
    ?>
        <div class="kmf-recompense <?php echo $abordable && ! $epuise ? '' : 'est-hors-portee'; ?>">
            <span class="kmf-rec-type"><?php echo esc_html( self::types()[ $r['type'] ] ?? $r['type'] ); ?></span>
            <span class="kmf-rec-titre"><?php echo esc_html( $r['titre'] ); ?></span>
            <?php if ( $r['description'] ) : ?>
                <span class="kmf-rec-desc"><?php echo esc_html( wp_trim_words( $r['description'], 18 ) ); ?></span>
            <?php endif; ?>
            <span class="kmf-rec-cout"><?php echo esc_html( number_format( $cout, 0, ',', ' ' ) ); ?> <?php esc_html_e( 'points', 'km-family' ); ?></span>

            <?php if ( (int) $r['stock'] >= 0 ) : ?>
                <span class="kmf-rec-stock">
                    <?php printf( esc_html( _n( 'Encore %d exemplaire', 'Encore %d exemplaires', (int) $r['stock'], 'km-family' ) ), (int) $r['stock'] ); ?>
                </span>
            <?php endif; ?>

            <?php if ( $epuise ) : ?>
                <span class="kmf-rec-etat"><?php esc_html_e( 'Épuisé', 'km-family' ); ?></span>
            <?php elseif ( ! $abordable ) : ?>
                <span class="kmf-rec-etat">
                    <?php printf( esc_html__( 'Encore %s points', 'km-family' ), esc_html( number_format( $cout - $solde, 0, ',', ' ' ) ) ); ?>
                </span>
            <?php else : ?>
                <button type="button" class="kmf-rec-btn" data-kmf-echanger="<?php echo esc_attr( $r['id'] ); ?>">
                    <?php esc_html_e( 'Échanger', 'km-family' ); ?>
                </button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if ( $miens ) : ?>
    <div class="kmf-mes-echanges">
        <div class="kmh-title"><?php esc_html_e( 'Mes échanges', 'km-family' ); ?></div>
        <ul class="kmf-fid-liste">
            <?php foreach ( $miens as $m ) : ?>
                <li>
                    <span class="kmf-fid-raison"><?php echo esc_html( $m['titre'] ); ?></span>
                    <span class="kmf-fid-date"><code><?php echo esc_html( $m['code'] ); ?></code></span>
                    <span class="kmf-fid-pts <?php echo $m['statut'] === 'honoree' ? '' : 'est-debit'; ?>">
                        <?php echo esc_html( $m['statut'] === 'honoree' ? __( 'Honoré', 'km-family' ) : __( 'En attente', 'km-family' ) ); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
        <?php
        return ob_get_clean();
    }

    // ==========================================================
    // ADMIN
    // ==========================================================

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Récompenses', 'km-family' ),
            __( 'Récompenses', 'km-family' ),
            'manage_options',
            'kmfamily-recompenses',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_save_reward', 'kmfamily_reward_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        global $wpdb;
        $id = absint( $_POST['reward_id'] ?? 0 );

        $data = array(
            'titre'       => sanitize_text_field( wp_unslash( $_POST['titre'] ?? '' ) ),
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'type'        => array_key_exists( sanitize_key( $_POST['type'] ?? '' ), self::types() ) ? sanitize_key( $_POST['type'] ) : 'autre',
            'cout_points' => max( 1, absint( $_POST['cout_points'] ?? 0 ) ),
            'valeur_xof'  => absint( $_POST['valeur_xof'] ?? 0 ),
            'stock'       => ! empty( $_POST['illimite'] ) ? -1 : max( 0, absint( $_POST['stock'] ?? 0 ) ),
            'artiste_id'  => absint( $_POST['artiste_id'] ?? 0 ),
            'actif'       => ! empty( $_POST['actif'] ) ? 1 : 0,
            'updated_at'  => current_time( 'mysql' ),
        );

        if ( $data['titre'] === '' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-recompenses&err=titre' ) );
            exit;
        }

        if ( $id ) {
            $wpdb->update( self::table_rewards(), $data, array( 'id' => $id ) );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( self::table_rewards(), $data );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-recompenses&saved=1' ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_delete_reward', 'kmfamily_del_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        global $wpdb;
        // On supprime la récompense mais JAMAIS les échanges déjà réalisés :
        // ce sont des engagements pris envers des membres, et leur trace doit
        // survivre au retrait du catalogue.
        $wpdb->delete( self::table_rewards(), array( 'id' => absint( $_POST['reward_id'] ?? 0 ) ), array( '%d' ) );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-recompenses&deleted=1' ) );
        exit;
    }

    public static function handle_fulfil() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_fulfil', 'kmfamily_fulfil_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        self::fulfil( absint( $_POST['redemption_id'] ?? 0 ) );
        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-recompenses&fulfilled=1' ) );
        exit;
    }

    public static function render_page() {
        global $wpdb;

        $liste   = self::get_all();
        $attente = $wpdb->get_results(
            "SELECT * FROM " . self::table_redemptions() . " WHERE statut = 'en_attente' ORDER BY created_at ASC LIMIT 100", ARRAY_A );
        $edit = isset( $_GET['edit'] ) ? self::get( absint( $_GET['edit'] ) ) : null;

        $artistes = get_posts( array( 'post_type' => 'nos-artistes', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Récompenses', 'km-family' ); ?></h1>

            <?php foreach ( array( 'saved' => __( 'Récompense enregistrée.', 'km-family' ), 'deleted' => __( 'Récompense supprimée.', 'km-family' ), 'fulfilled' => __( 'Échange marqué comme honoré.', 'km-family' ) ) as $k => $msg ) : ?>
                <?php if ( isset( $_GET[ $k ] ) ) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ( isset( $_GET['err'] ) ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Le titre est obligatoire.', 'km-family' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $attente ) : ?>
            <h2><?php esc_html_e( 'Échanges à honorer', 'km-family' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Membre', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Récompense', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Code', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Points', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'km-family' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $attente as $a ) : $u = get_userdata( $a['user_id'] ); ?>
                    <tr>
                        <td><?php echo esc_html( $u ? $u->display_name : '#' . $a['user_id'] ); ?></td>
                        <td><?php echo esc_html( $a['titre'] ); ?></td>
                        <td><code><?php echo esc_html( $a['code'] ); ?></code></td>
                        <td><?php echo esc_html( number_format( (int) $a['points'], 0, ',', ' ' ) ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $a['created_at'] ) ) ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="kmfamily_fulfil" />
                                <input type="hidden" name="redemption_id" value="<?php echo esc_attr( $a['id'] ); ?>" />
                                <?php wp_nonce_field( 'kmfamily_fulfil', 'kmfamily_fulfil_nonce' ); ?>
                                <button class="button button-small"><?php esc_html_e( 'Marquer honoré', 'km-family' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <hr />
            <?php endif; ?>

            <h2><?php echo $edit ? esc_html__( 'Modifier la récompense', 'km-family' ) : esc_html__( 'Nouvelle récompense', 'km-family' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_save_reward" />
                <input type="hidden" name="reward_id" value="<?php echo esc_attr( $edit['id'] ?? 0 ); ?>" />
                <?php wp_nonce_field( 'kmfamily_save_reward', 'kmfamily_reward_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="titre"><?php esc_html_e( 'Titre', 'km-family' ); ?></label></th>
                        <td><input type="text" id="titre" name="titre" class="regular-text" value="<?php echo esc_attr( $edit['titre'] ?? '' ); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="description"><?php esc_html_e( 'Description', 'km-family' ); ?></label></th>
                        <td><textarea id="description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $edit['description'] ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="type"><?php esc_html_e( 'Type', 'km-family' ); ?></label></th>
                        <td>
                            <select id="type" name="type">
                                <?php foreach ( self::types() as $k => $l ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $edit['type'] ?? '', $k ); ?>><?php echo esc_html( $l ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cout_points"><?php esc_html_e( 'Coût en points', 'km-family' ); ?></label></th>
                        <td><input type="number" min="1" id="cout_points" name="cout_points" class="small-text" value="<?php echo esc_attr( $edit['cout_points'] ?? 500 ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="valeur_xof"><?php esc_html_e( 'Valeur réelle (FCFA)', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" min="0" id="valeur_xof" name="valeur_xof" class="small-text" value="<?php echo esc_attr( $edit['valeur_xof'] ?? 0 ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Ce que cette récompense vous coûte réellement lorsqu\'elle est honorée. C\'est ce champ, et lui seul, qui permet de chiffrer ce que le stock de points engage. Un contenu numérique déjà produit peut légitimement valoir 0.', 'km-family' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Stock', 'km-family' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="illimite" value="1" <?php checked( (int) ( $edit['stock'] ?? -1 ) < 0 ); ?> /> <?php esc_html_e( 'Illimité', 'km-family' ); ?></label>
                            <input type="number" min="0" name="stock" class="small-text" value="<?php echo esc_attr( max( 0, (int) ( $edit['stock'] ?? 0 ) ) ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="artiste_id"><?php esc_html_e( 'Réservée à un artiste', 'km-family' ); ?></label></th>
                        <td>
                            <select id="artiste_id" name="artiste_id">
                                <option value="0"><?php esc_html_e( '— Tous les membres —', 'km-family' ); ?></option>
                                <?php foreach ( $artistes as $a ) : ?>
                                    <option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( (int) ( $edit['artiste_id'] ?? 0 ), $a->ID ); ?>><?php echo esc_html( $a->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Statut', 'km-family' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="actif" value="1" <?php checked( (int) ( $edit['actif'] ?? 0 ) ); ?> /> <?php esc_html_e( 'Active et visible par les membres', 'km-family' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Activer une récompense rend les points convertibles : l\'obligation chiffrée sur l\'écran Fidélité devient effective.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( $edit ? __( 'Mettre à jour', 'km-family' ) : __( 'Créer', 'km-family' ) ); ?>
            </form>

            <?php if ( $liste ) : ?>
            <hr />
            <h2><?php esc_html_e( 'Catalogue', 'km-family' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Titre', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Points', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Valeur', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Stock', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Statut', 'km-family' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $liste as $r ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $r['titre'] ); ?></strong></td>
                        <td><?php echo esc_html( self::types()[ $r['type'] ] ?? $r['type'] ); ?></td>
                        <td><?php echo esc_html( number_format( (int) $r['cout_points'], 0, ',', ' ' ) ); ?></td>
                        <td><?php echo esc_html( number_format( (int) $r['valeur_xof'], 0, ',', ' ' ) ); ?> FCFA</td>
                        <td><?php echo (int) $r['stock'] < 0 ? '∞' : esc_html( (int) $r['stock'] ); ?></td>
                        <td><?php echo (int) $r['actif'] ? '<span style="color:#008a20">●</span> ' . esc_html__( 'Active', 'km-family' ) : '<span style="color:#8a8a8a">○</span> ' . esc_html__( 'Inactive', 'km-family' ); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=kmfamily-recompenses&edit=' . (int) $r['id'] ) ); ?>"><?php esc_html_e( 'Modifier', 'km-family' ); ?></a>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                  onsubmit="return confirm('<?php esc_attr_e( 'Retirer cette récompense du catalogue ? Les échanges déjà réalisés sont conservés.', 'km-family' ); ?>');">
                                <input type="hidden" name="action" value="kmfamily_delete_reward" />
                                <input type="hidden" name="reward_id" value="<?php echo esc_attr( $r['id'] ); ?>" />
                                <?php wp_nonce_field( 'kmfamily_delete_reward', 'kmfamily_del_nonce' ); ?>
                                <button class="button button-small"><?php esc_html_e( 'Supprimer', 'km-family' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
