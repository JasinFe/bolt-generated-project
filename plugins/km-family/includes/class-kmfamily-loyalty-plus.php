<?php
/**
 * KM Family — Fidélité étendue (LOT 6)
 *
 * Étend KMFamily_Loyalty sans toucher à son registre : on écrit dans la MÊME
 * table, avec des références synthétiques. La contrainte UNIQUE existante sur
 * `order_ref` devient ainsi le garde-fou anti-abus, appliqué par la base et non
 * par du code PHP :
 *
 *   ENG-7-401-20261005   → un seul gain d'engagement par membre, par contenu
 *                           et par jour. Impossible à contourner en bouclant.
 *   EXP-7-202610         → une seule péremption par membre et par mois.
 *   RDM-42               → un seul débit par échange.
 *
 * Aucune modification de schéma, donc aucun risque de migration : la colonne
 * `points` est déjà signée, elle accepte les débits.
 *
 * POINT COMPTABLE, qui conditionne tout le reste :
 * dès qu'un point devient convertible en billet, réduction ou marchandise, le
 * stock de points en circulation est une OBLIGATION envers les membres, pas un
 * simple compteur d'animation. Le catalogue de récompenses est donc vide par
 * défaut — tant qu'aucune récompense n'est active, aucun engagement n'existe.
 * L'écran « Fidélité » chiffre cette obligation en permanence.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Loyalty_Plus {

    const OPTION_ENGAGEMENT   = 'kmfamily_loyalty_engagement';    // 1 = points d'engagement actifs
    const OPTION_CAP_JOUR     = 'kmfamily_loyalty_cap_jour';      // plafond quotidien d'engagement
    const OPTION_EXPIRY_MOIS  = 'kmfamily_loyalty_expiry_mois';   // 0 = pas de péremption
    const OPTION_POINTS_AUDIO = 'kmfamily_loyalty_pts_audio';
    const OPTION_POINTS_VIDEO = 'kmfamily_loyalty_pts_video';

    const DEFAULT_CAP_JOUR    = 20;
    const DEFAULT_EXPIRY_MOIS = 18;
    const DEFAULT_PTS_AUDIO   = 2;
    const DEFAULT_PTS_VIDEO   = 3;

    /** Niveaux de fidélité, par points cumulés gagnés (jamais par solde). */
    const NIVEAUX = array(
        'fan'       => 0,
        'supporter' => 500,
        'insider'   => 2000,
        'vip'       => 6000,
        'legend'    => 15000,
    );

    public static function init() {
        // Le Lot 2 n'émet ce signal qu'au-delà de 60 % du contenu réellement
        // écouté, et au plus une fois par 24 h. Le garde-fou le plus important
        // est donc déjà en amont : on ne compte pas une lecture lancée puis
        // abandonnée.
        add_action( 'kmfamily_playback_counted', array( __CLASS__, 'award_engagement' ), 10, 3 );

        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'run_expiry' ), 40 );

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 16 );
        add_action( 'admin_post_kmfamily_save_loyalty', array( __CLASS__, 'handle_save' ) );

        add_shortcode( 'kmfamily_fidelite', array( __CLASS__, 'shortcode' ) );
    }

    // ==========================================================
    // RÉGLAGES
    // ==========================================================

    public static function engagement_actif() {
        $v = get_option( self::OPTION_ENGAGEMENT, null );
        return $v === null ? true : (bool) $v;
    }

    public static function cap_jour() {
        $v = (int) get_option( self::OPTION_CAP_JOUR, self::DEFAULT_CAP_JOUR );
        return $v > 0 ? $v : self::DEFAULT_CAP_JOUR;
    }

    public static function expiry_mois() {
        $v = get_option( self::OPTION_EXPIRY_MOIS, null );
        return $v === null ? self::DEFAULT_EXPIRY_MOIS : max( 0, (int) $v );
    }

    public static function points_pour( $type_media ) {
        return $type_media === 'video'
            ? (int) get_option( self::OPTION_POINTS_VIDEO, self::DEFAULT_PTS_VIDEO )
            : (int) get_option( self::OPTION_POINTS_AUDIO, self::DEFAULT_PTS_AUDIO );
    }

    // ==========================================================
    // ÉCRITURE AU REGISTRE
    // ==========================================================

    /**
     * Écrit une ligne au registre de fidélité.
     * La référence porte l'idempotence : si elle existe déjà, l'insertion est
     * refusée par la base et rien ne se passe. C'est volontaire.
     *
     * @return bool true si la ligne a bien été créée.
     */
    /**
     * Le type par défaut est volontairement « manuel » et non « engagement » :
     * le type `engagement` est ce qui consomme le plafond quotidien. Un appelant
     * qui oublierait de le préciser grillerait silencieusement le quota du
     * membre pour la journée — un défaut invisible et pénible à diagnostiquer.
     * Le plafond ne doit se déclencher que pour ce qui est explicitement de
     * l'engagement.
     */
    public static function ecrire( $user_id, $reference, $points, $raison, $type = 'manuel' ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $points  = (int) $points;
        if ( ! $user_id || $points === 0 ) return false;

        $insere = $wpdb->insert( KMFamily_Loyalty::table_name(), array(
            'user_id'      => $user_id,
            'order_ref'    => substr( $reference, 0, 40 ),
            'product_type' => substr( $type, 0, 20 ),
            'points'       => $points,
            'reason'       => substr( $raison, 0, 100 ),
            'created_at'   => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%d', '%s', '%s' ) );

        if ( ! $insere ) return false;

        self::rafraichir_solde( $user_id );
        do_action( 'kmfamily_loyalty_entry', $user_id, $points, $raison, $reference );

        return true;
    }

    /**
     * KMFamily_Loyalty garde le solde en cache dans une méta utilisateur, mais
     * la méthode de recalcul y est privée. On refait donc le calcul ici, sur la
     * même clé — une seule valeur de cache, pas deux qui divergeraient.
     */
    private static function rafraichir_solde( $user_id ) {
        global $wpdb;
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(points),0) FROM " . KMFamily_Loyalty::table_name() . " WHERE user_id = %d",
            absint( $user_id )
        ) );
        update_user_meta( absint( $user_id ), KMFamily_Loyalty::BALANCE_META_KEY, $total );
        return $total;
    }

    // ==========================================================
    // POINTS D'ENGAGEMENT
    // ==========================================================

    /** Hook : kmfamily_playback_counted (émis par le Lot 2). */
    public static function award_engagement( $user_id, $content_id, $artiste_id ) {
        if ( ! self::engagement_actif() ) return;

        $type   = function_exists( 'get_field' ) ? get_field( 'type_media', $content_id ) : get_post_meta( $content_id, 'type_media', true );
        $points = self::points_pour( $type );
        if ( $points <= 0 ) return;

        // Plafond quotidien, distinct des points d'abonnement : un membre ne
        // peut pas transformer une journée d'écoute en équivalent d'un
        // renouvellement payant.
        $deja = self::points_engagement_du_jour( $user_id );
        if ( $deja >= self::cap_jour() ) return;

        $points = min( $points, self::cap_jour() - $deja );

        $ref = sprintf( 'ENG-%d-%d-%s', absint( $user_id ), absint( $content_id ), current_time( 'Ymd' ) );
        self::ecrire( $user_id, $ref, $points, 'engagement_' . sanitize_key( (string) $type ), 'engagement' );
    }

    public static function points_engagement_du_jour( $user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(points),0) FROM " . KMFamily_Loyalty::table_name() . "
             WHERE user_id = %d AND product_type = 'engagement' AND points > 0 AND created_at >= %s",
            absint( $user_id ), current_time( 'Y-m-d' ) . ' 00:00:00'
        ) );
    }

    // ==========================================================
    // PÉREMPTION
    // ==========================================================

    /**
     * Périme les points gagnés il y a plus de N mois.
     *
     * Méthode : sur l'ensemble des gains antérieurs à la date de coupure, on
     * retranche tout ce qui a déjà été dépensé ou périmé (les débits sont
     * réputés consommer les points les plus anciens d'abord). Le reliquat est
     * périmé. C'est ce qui évite de périmer deux fois les mêmes points, et de
     * périmer des points déjà échangés.
     */
    public static function run_expiry() {
        global $wpdb;

        $mois = self::expiry_mois();
        if ( $mois <= 0 ) return 0; // péremption désactivée

        $table   = KMFamily_Loyalty::table_name();
        $coupure = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . $mois . ' months' ) );
        $periode = current_time( 'Ym' );

        $users = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$table} WHERE points > 0 AND created_at < %s", $coupure
        ) );

        $total = 0;

        foreach ( (array) $users as $user_id ) {
            $anciens = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(points),0) FROM {$table} WHERE user_id = %d AND points > 0 AND created_at < %s",
                $user_id, $coupure
            ) );

            $debits = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(ABS(SUM(points)),0) FROM {$table} WHERE user_id = %d AND points < 0",
                $user_id
            ) );

            $a_perimer = $anciens - $debits;
            if ( $a_perimer <= 0 ) continue;

            // Ne jamais faire passer un solde en négatif, même si les données
            // ont été touchées à la main en base.
            $solde = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(points),0) FROM {$table} WHERE user_id = %d", $user_id ) );
            $a_perimer = min( $a_perimer, $solde );
            if ( $a_perimer <= 0 ) continue;

            if ( self::ecrire( $user_id, sprintf( 'EXP-%d-%s', $user_id, $periode ), -$a_perimer, 'peremption', 'expiry' ) ) {
                $total += $a_perimer;
                do_action( 'kmfamily_loyalty_expired', $user_id, $a_perimer );
            }
        }

        return $total;
    }

    // ==========================================================
    // NIVEAUX
    // ==========================================================

    /** Points cumulés gagnés — un échange ne fait jamais redescendre de niveau. */
    public static function points_cumules( $user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(points),0) FROM " . KMFamily_Loyalty::table_name() . "
             WHERE user_id = %d AND points > 0", absint( $user_id )
        ) );
    }

    public static function niveau( $user_id ) {
        $cumul   = self::points_cumules( $user_id );
        $courant = 'fan';
        $suivant = null;
        $seuil_suivant = 0;

        foreach ( self::NIVEAUX as $slug => $seuil ) {
            if ( $cumul >= $seuil ) {
                $courant = $slug;
            } elseif ( $suivant === null ) {
                $suivant = $slug;
                $seuil_suivant = $seuil;
            }
        }

        return array(
            'slug'          => $courant,
            'nom'           => self::nom_niveau( $courant ),
            'cumul'         => $cumul,
            'suivant'       => $suivant,
            'nom_suivant'   => $suivant ? self::nom_niveau( $suivant ) : '',
            'reste'         => $suivant ? max( 0, $seuil_suivant - $cumul ) : 0,
            'progression'   => $suivant && $seuil_suivant > 0 ? min( 100, (int) round( $cumul / $seuil_suivant * 100 ) ) : 100,
        );
    }

    public static function nom_niveau( $slug ) {
        $noms = array(
            'fan'       => __( 'Fan', 'km-family' ),
            'supporter' => __( 'Supporter', 'km-family' ),
            'insider'   => __( 'Insider', 'km-family' ),
            'vip'       => __( 'VIP', 'km-family' ),
            'legend'    => __( 'Légende', 'km-family' ),
        );
        return $noms[ $slug ] ?? ucfirst( $slug );
    }

    // ==========================================================
    // ENGAGEMENT FINANCIER
    // ==========================================================

    /**
     * Chiffre l'obligation que représentent les points en circulation.
     *
     * Le taux de conversion est déduit du catalogue actif : pour chaque
     * récompense, le rapport entre sa valeur réelle en francs et son coût en
     * points. On retient la moyenne pondérée — c'est l'estimation la plus
     * défendable sans savoir ce que les membres choisiront réellement.
     *
     * Sans récompense active, il n'y a pas de conversion possible, donc pas
     * d'obligation : les points restent un compteur d'animation.
     */
    public static function engagement_financier() {
        global $wpdb;

        $table = KMFamily_Loyalty::table_name();

        $circulation = (int) $wpdb->get_var( "SELECT COALESCE(SUM(points),0) FROM {$table}" );
        $membres     = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table}" );

        $taux = 0.0;
        if ( class_exists( 'KMFamily_Rewards' ) ) {
            $taux = KMFamily_Rewards::taux_conversion();
        }

        return array(
            'circulation' => max( 0, $circulation ),
            'membres'     => $membres,
            'taux_xof'    => $taux,                                   // francs par point
            'provision'   => (int) round( max( 0, $circulation ) * $taux ),
            'convertible' => $taux > 0,
        );
    }

    // ==========================================================
    // AFFICHAGE MEMBRE
    // ==========================================================

    public static function shortcode() {
        if ( ! is_user_logged_in() ) return '';

        $user_id = get_current_user_id();
        $solde   = (int) KMFamily_Loyalty::get_balance( $user_id );
        $niveau  = self::niveau( $user_id );
        $lignes  = KMFamily_Loyalty::get_ledger( $user_id, 10 );

        wp_enqueue_style( 'kmfamily-hub' );

        ob_start(); ?>
<div class="kmf-fidelite">
    <div class="kmf-fid-entete">
        <div>
            <span class="kmf-fid-lib"><?php esc_html_e( 'Mes points KM', 'km-family' ); ?></span>
            <span class="kmf-fid-solde"><?php echo esc_html( number_format( $solde, 0, ',', ' ' ) ); ?></span>
        </div>
        <span class="kmf-fid-niveau"><?php echo esc_html( $niveau['nom'] ); ?></span>
    </div>

    <?php if ( $niveau['suivant'] ) : ?>
        <div class="kmf-fid-jauge"><span style="width:<?php echo esc_attr( $niveau['progression'] ); ?>%"></span></div>
        <p class="kmf-fid-reste">
            <?php printf(
                esc_html__( 'Encore %1$s points pour devenir %2$s', 'km-family' ),
                '<strong>' . esc_html( number_format( $niveau['reste'], 0, ',', ' ' ) ) . '</strong>',
                esc_html( $niveau['nom_suivant'] )
            ); ?>
        </p>
    <?php else : ?>
        <p class="kmf-fid-reste"><?php esc_html_e( 'Vous avez atteint le niveau le plus élevé. Merci.', 'km-family' ); ?></p>
    <?php endif; ?>

    <?php if ( self::expiry_mois() > 0 ) : ?>
        <p class="kmh-hint">
            <?php printf(
                esc_html__( 'Les points sont valables %d mois après leur obtention.', 'km-family' ),
                self::expiry_mois()
            ); ?>
        </p>
    <?php endif; ?>

    <?php if ( $lignes ) : ?>
    <div class="kmf-fid-histo">
        <div class="kmh-title"><?php esc_html_e( 'Derniers mouvements', 'km-family' ); ?></div>
        <ul class="kmf-fid-liste">
            <?php foreach ( $lignes as $l ) :
                $p = (int) ( is_object( $l ) ? $l->points : $l['points'] );
                $r = is_object( $l ) ? $l->reason : $l['reason'];
                $d = is_object( $l ) ? $l->created_at : $l['created_at'];
            ?>
                <li>
                    <span class="kmf-fid-raison"><?php echo esc_html( self::libelle_raison( $r ) ); ?></span>
                    <span class="kmf-fid-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $d ) ) ); ?></span>
                    <span class="kmf-fid-pts <?php echo $p < 0 ? 'est-debit' : ''; ?>">
                        <?php echo esc_html( ( $p > 0 ? '+' : '' ) . $p ); ?>
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

    public static function libelle_raison( $raison ) {
        $map = array(
            'subscription_activation' => __( 'Soutien à un artiste', 'km-family' ),
            'ticket_purchase'         => __( 'Achat de billet', 'km-family' ),
            'engagement_audio'        => __( 'Écoute d\'un contenu', 'km-family' ),
            'engagement_video'        => __( 'Visionnage d\'une vidéo', 'km-family' ),
            'engagement_image'        => __( 'Contenu consulté', 'km-family' ),
            'engagement_texte'        => __( 'Contenu consulté', 'km-family' ),
            'peremption'              => __( 'Points périmés', 'km-family' ),
        );
        if ( isset( $map[ $raison ] ) ) return $map[ $raison ];
        if ( strpos( (string) $raison, 'echange_' ) === 0 ) return __( 'Échange de points', 'km-family' );
        return ucfirst( str_replace( '_', ' ', (string) $raison ) );
    }

    // ==========================================================
    // ADMIN
    // ==========================================================

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Fidélité', 'km-family' ),
            __( 'Fidélité', 'km-family' ),
            'manage_options',
            'kmfamily-fidelite',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_save_loyalty', 'kmfamily_loyalty_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        update_option( self::OPTION_ENGAGEMENT, ! empty( $_POST['engagement'] ) ? 1 : 0 );
        update_option( self::OPTION_CAP_JOUR, max( 1, min( 500, absint( $_POST['cap_jour'] ?? self::DEFAULT_CAP_JOUR ) ) ) );
        update_option( self::OPTION_EXPIRY_MOIS, max( 0, min( 120, absint( $_POST['expiry_mois'] ?? self::DEFAULT_EXPIRY_MOIS ) ) ) );
        update_option( self::OPTION_POINTS_AUDIO, max( 0, min( 100, absint( $_POST['pts_audio'] ?? self::DEFAULT_PTS_AUDIO ) ) ) );
        update_option( self::OPTION_POINTS_VIDEO, max( 0, min( 100, absint( $_POST['pts_video'] ?? self::DEFAULT_PTS_VIDEO ) ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-fidelite&saved=1' ) );
        exit;
    }

    public static function render_page() {
        $eng = self::engagement_financier();
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Fidélité', 'km-family' ); ?></h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'km-family' ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Engagement envers les membres', 'km-family' ); ?></h2>
            <table class="widefat striped" style="max-width:720px">
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Points en circulation', 'km-family' ); ?></td>
                        <td><strong><?php echo esc_html( number_format( $eng['circulation'], 0, ',', ' ' ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Membres concernés', 'km-family' ); ?></td>
                        <td><?php echo esc_html( $eng['membres'] ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Valeur moyenne du point', 'km-family' ); ?></td>
                        <td><?php echo $eng['taux_xof'] > 0 ? esc_html( number_format( $eng['taux_xof'], 2, ',', ' ' ) ) . ' FCFA' : '—'; ?></td>
                    </tr>
                    <tr style="background:#fff8e5">
                        <td><strong><?php esc_html_e( 'Obligation estimée', 'km-family' ); ?></strong></td>
                        <td><strong><?php echo esc_html( number_format( $eng['provision'], 0, ',', ' ' ) ); ?> FCFA</strong></td>
                    </tr>
                </tbody>
            </table>

            <p class="description" style="max-width:820px">
                <?php if ( $eng['convertible'] ) : ?>
                    <?php esc_html_e( 'Des récompenses sont actives : les points en circulation sont convertibles en contrepartie réelle et constituent donc une obligation envers vos membres. Le montant ci-dessus est une estimation fondée sur la valeur moyenne pondérée du catalogue actuel — à confronter à votre propre appréciation du taux d\'utilisation réel.', 'km-family' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Aucune récompense active : les points ne sont convertibles en rien, et ne constituent donc aucune obligation. C\'est l\'état par défaut, et il est prudent. Activer une récompense change cette situation immédiatement.', 'km-family' ); ?>
                <?php endif; ?>
            </p>

            <hr />

            <h2><?php esc_html_e( 'Barème et garde-fous', 'km-family' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_save_loyalty" />
                <?php wp_nonce_field( 'kmfamily_save_loyalty', 'kmfamily_loyalty_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Points d\'engagement', 'km-family' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="engagement" value="1" <?php checked( self::engagement_actif() ); ?> />
                                <?php esc_html_e( 'Attribuer des points à l\'écoute et au visionnage', 'km-family' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Un contenu n\'est compté qu\'au-delà de 60 % réellement écouté, et au plus une fois par 24 heures. Une écoute en boucle ne rapporte donc rien de plus.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pts_audio"><?php esc_html_e( 'Points par écoute', 'km-family' ); ?></label></th>
                        <td><input type="number" min="0" max="100" id="pts_audio" name="pts_audio" class="small-text" value="<?php echo esc_attr( self::points_pour( 'audio' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pts_video"><?php esc_html_e( 'Points par visionnage', 'km-family' ); ?></label></th>
                        <td><input type="number" min="0" max="100" id="pts_video" name="pts_video" class="small-text" value="<?php echo esc_attr( self::points_pour( 'video' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cap_jour"><?php esc_html_e( 'Plafond quotidien d\'engagement', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" min="1" max="500" id="cap_jour" name="cap_jour" class="small-text" value="<?php echo esc_attr( self::cap_jour() ); ?>" />
                            <p class="description"><?php esc_html_e( 'Ne s\'applique qu\'aux points d\'engagement. Les points d\'abonnement et de billetterie ne sont jamais plafonnés.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expiry_mois"><?php esc_html_e( 'Validité des points', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" min="0" max="120" id="expiry_mois" name="expiry_mois" class="small-text" value="<?php echo esc_attr( self::expiry_mois() ); ?>" /> <?php esc_html_e( 'mois', 'km-family' ); ?>
                            <p class="description"><?php esc_html_e( '0 désactive la péremption. Sans péremption, l\'obligation ci-dessus ne cesse jamais de croître — c\'est la principale raison d\'en fixer une, et il vaut mieux le faire avant d\'avoir distribué beaucoup de points.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
