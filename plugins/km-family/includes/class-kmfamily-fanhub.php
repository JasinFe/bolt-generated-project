<?php
/**
 * KM Family — Fan Hub (LOT 4)
 *
 * L'espace membre cesse d'être un récapitulatif d'abonnement pour devenir un
 * endroit où l'on revient. Cinq blocs, dans l'ordre où ils servent :
 *
 *   1. Mon univers      — ce que je soutiens, ce que j'ai construit
 *   2. Continuer        — reprendre exactement où je me suis arrêté
 *   3. Pour vous        — les nouveautés de MES artistes, pas de tout le label
 *   4. Mes playlists    — ce que j'ai rangé moi-même
 *   5. Agenda           — ce qui arrive
 *
 * Le bloc « Continuer » est placé avant les nouveautés à dessein : un contenu
 * commencé a beaucoup plus de chances d'être terminé qu'un contenu neuf n'en a
 * d'être ouvert. Mettre la nouveauté en premier, c'est optimiser pour
 * l'impression de fraîcheur plutôt que pour l'usage réel.
 *
 * Ce module N'ALTÈRE PAS [kmfamily_dashboard], qui continue de fonctionner à
 * l'identique. Le hub est un nouveau shortcode, à placer quand vous le
 * souhaitez.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_FanHub {

    public static function init() {
        add_shortcode( 'kmfamily_hub', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    public static function register_assets() {
        wp_register_script( 'kmfamily-hub', KMFAMILY_URL . 'assets/js/km-hub.js', array(), KMFAMILY_VERSION, true );
        wp_localize_script( 'kmfamily-hub', 'KMFamilyHub', array(
            'rest'  => esc_url_raw( rest_url( 'kmfamily/v1/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => array(
                'nouvelle'  => __( 'Nom de la nouvelle playlist', 'km-family' ),
                'renommer'  => __( 'Nouveau nom', 'km-family' ),
                'confirmer' => __( 'Supprimer cette playlist ? Les titres ne sont pas supprimés.', 'km-family' ),
                'erreur'    => __( 'Action impossible pour le moment.', 'km-family' ),
            ),
        ) );
    }

    // ==========================================================
    // DONNÉES
    // ==========================================================

    /** Artistes réellement soutenus par ce membre, à cet instant. */
    public static function get_artistes_soutenus( $user_id ) {
        if ( class_exists( 'KMFamily_Memberships' ) && KMFamily_Memberships::is_ready() ) {
            $rows = KMFamily_Memberships::query_members( array(
                'status' => 'active', 'limit' => 100,
            ) );

            $ids = array();
            foreach ( $rows as $r ) {
                if ( (int) $r['user_id'] === absint( $user_id ) ) $ids[] = (int) $r['artiste_id'];
            }
            if ( $ids ) return array_values( array_unique( $ids ) );
        }

        // Repli sur le stockage historique tant que la reprise n'a pas eu lieu.
        $subs = KMFamily_Subscriptions::get_user_subscriptions( $user_id );
        $ids  = array();
        foreach ( (array) $subs as $artiste_id => $sub ) {
            if ( ! empty( $sub['expire'] ) && $sub['expire'] < time() ) continue;
            $ids[] = (int) $artiste_id;
        }
        return $ids;
    }

    /** Contribution cumulée du membre, tous artistes confondus. */
    public static function get_contribution_totale( $user_id ) {
        global $wpdb;
        if ( ! class_exists( 'KMFamily_Memberships' ) || ! KMFamily_Memberships::is_ready() ) return null;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount_total),0) FROM " . KMFamily_Memberships::table_name() . " WHERE user_id = %d",
            absint( $user_id )
        ) );
    }

    /**
     * Fil « Pour vous » : les contenus récents des artistes soutenus.
     *
     * On inclut délibérément les contenus encore verrouillés : voir passer une
     * avant-première Diamant avec « Pour vous dans 3 j » est exactement ce qui
     * fait monter un membre d'un palier. Un fil qui ne montrerait que
     * l'accessible n'aurait aucune force commerciale.
     */
    public static function get_feed( $user_id, $limit = 12 ) {
        $artistes = self::get_artistes_soutenus( $user_id );
        if ( ! $artistes ) return array();

        $posts = get_posts( array(
            'post_type'      => 'contenu_exclusif',
            'posts_per_page' => max( 1, (int) $limit ) * 2, // marge pour le filtrage ci-dessous
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array( 'key' => 'artiste_lie', 'value' => $artistes, 'compare' => 'IN' ),
            ),
        ) );

        $out = array();
        foreach ( $posts as $p ) {
            $dispo = KMFamily_Availability::resolve( $p->ID );

            // Un drop terminé et non archivé n'a plus rien à faire dans un fil.
            if ( $dispo['state'] === 'termine' ) continue;

            $out[] = array(
                'post'       => $p,
                'accessible' => KMFamily_Availability::user_can_access( $user_id, $p->ID ),
                'badge'      => KMFamily_Availability::badge( $p->ID, $user_id ),
                'dispo'      => $dispo,
            );

            if ( count( $out ) >= $limit ) break;
        }

        return $out;
    }

    // ==========================================================
    // RENDU
    // ==========================================================

    public static function render( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="kmh-vide">' . esc_html__( 'Connectez-vous pour accéder à votre espace.', 'km-family' ) . '</div>';
        }

        $atts    = shortcode_atts( array( 'agenda' => 'oui' ), $atts, 'kmfamily_hub' );
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        wp_enqueue_style( 'kmfamily-hub' );
        wp_enqueue_style( 'kmfamily-player' );
        wp_enqueue_script( 'kmfamily-hub' );

        $artistes     = self::get_artistes_soutenus( $user_id );
        $contribution = self::get_contribution_totale( $user_id );
        $continuer    = KMFamily_Playback::get_continue( $user_id, 6 );
        $favoris      = KMFamily_Playback::get_favorites( $user_id, 100 );
        $playlists    = KMFamily_Playlists::get_user_playlists( $user_id );
        $feed         = self::get_feed( $user_id, 10 );

        $prenom = $user->first_name ?: $user->display_name;

        ob_start(); ?>
<div class="kmh-hub" data-kmh-hub>

    <!-- ══ MON UNIVERS ══ -->
    <div class="kmh-entete">
        <h2 class="kmh-bonjour"><?php printf( esc_html__( 'Bonjour %s', 'km-family' ), esc_html( $prenom ) ); ?></h2>
        <p class="kmh-sous"><?php esc_html_e( 'Votre univers KM Family', 'km-family' ); ?></p>
    </div>

    <div class="kmh-stats">
        <div class="kmh-stat">
            <span class="kmh-stat-val"><?php echo esc_html( count( $artistes ) ); ?></span>
            <span class="kmh-stat-lib"><?php esc_html_e( 'Artiste(s) soutenu(s)', 'km-family' ); ?></span>
        </div>
        <?php if ( $contribution !== null ) : ?>
        <div class="kmh-stat">
            <span class="kmh-stat-val"><?php echo esc_html( number_format( $contribution, 0, ',', ' ' ) ); ?><small> F</small></span>
            <span class="kmh-stat-lib"><?php esc_html_e( 'Contribution totale', 'km-family' ); ?></span>
        </div>
        <?php endif; ?>
        <div class="kmh-stat">
            <span class="kmh-stat-val"><?php echo esc_html( count( $favoris ) ); ?></span>
            <span class="kmh-stat-lib"><?php esc_html_e( 'Contenus aimés', 'km-family' ); ?></span>
        </div>
        <div class="kmh-stat">
            <span class="kmh-stat-val"><?php echo esc_html( count( $playlists ) ); ?></span>
            <span class="kmh-stat-lib"><?php esc_html_e( 'Playlists', 'km-family' ); ?></span>
        </div>
    </div>

    <?php if ( ! $artistes ) : ?>
        <div class="kmh-vide">
            <p><?php esc_html_e( 'Vous ne soutenez encore aucun artiste.', 'km-family' ); ?></p>
            <?php $page = (int) get_option( 'kmfamily_page_paliers' ); if ( $page ) : ?>
                <a class="kmh-cta" href="<?php echo esc_url( get_permalink( $page ) ); ?>">
                    <?php esc_html_e( 'Découvrir les artistes', 'km-family' ); ?>
                </a>
            <?php endif; ?>
        </div>
    <?php else : ?>

    <!-- ══ CONTINUER ══ -->
    <?php if ( $continuer ) : ?>
    <section class="kmh-section">
        <h3 class="kmh-title"><?php esc_html_e( 'Reprendre', 'km-family' ); ?></h3>
        <div class="kmh-rangee">
        <?php foreach ( $continuer as $c ) :
            $pid = (int) $c['content_id'];
            if ( ! KMFamily_Availability::user_can_access( $user_id, $pid ) ) continue;
            $cover = get_the_post_thumbnail_url( $pid, 'medium' );
            $pct   = (int) $c['max_progress'];
        ?>
            <a class="kmh-carte" href="<?php echo esc_url( get_permalink( $pid ) ); ?>">
                <span class="kmh-carte-visuel" <?php if ( $cover ) : ?>style="background-image:url('<?php echo esc_url( $cover ); ?>')"<?php endif; ?>>
                    <span class="kmh-carte-play">▶</span>
                    <span class="kmh-carte-jauge"><span style="width:<?php echo esc_attr( min( 100, $pct ) ); ?>%"></span></span>
                </span>
                <span class="kmh-carte-titre"><?php echo esc_html( get_the_title( $pid ) ); ?></span>
                <span class="kmh-carte-sub"><?php printf( esc_html__( '%d %% écouté', 'km-family' ), $pct ); ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ══ POUR VOUS ══ -->
    <?php if ( $feed ) : ?>
    <section class="kmh-section">
        <h3 class="kmh-title"><?php esc_html_e( 'Pour vous', 'km-family' ); ?></h3>
        <ul class="kmh-feed">
        <?php foreach ( $feed as $f ) :
            $pid     = $f['post']->ID;
            $artiste = get_field( 'artiste_lie', $pid );
            $nom_a   = $artiste ? get_the_title( is_object( $artiste ) ? $artiste->ID : $artiste ) : '';
            $cover   = get_the_post_thumbnail_url( $pid, 'thumbnail' );
        ?>
            <li class="kmh-feed-item <?php echo $f['accessible'] ? '' : 'kmh-feed-item--verrou'; ?>">
                <span class="kmh-feed-visuel" <?php if ( $cover ) : ?>style="background-image:url('<?php echo esc_url( $cover ); ?>')"<?php endif; ?>>
                    <?php echo $f['accessible'] ? '' : '<span class="kmh-feed-cadenas">🔒</span>'; ?>
                </span>
                <span class="kmh-feed-main">
                    <?php if ( ! empty( $f['badge']['texte'] ) ) : ?>
                        <span class="kmh-badge kmh-badge--<?php echo esc_attr( $f['badge']['ton'] ); ?>"><?php echo esc_html( $f['badge']['texte'] ); ?></span>
                    <?php endif; ?>
                    <span class="kmh-feed-titre">
                        <?php if ( $f['accessible'] ) : ?>
                            <a href="<?php echo esc_url( get_permalink( $pid ) ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( get_the_title( $pid ) ); ?>
                        <?php endif; ?>
                    </span>
                    <span class="kmh-feed-sub">
                        <?php echo esc_html( $nom_a ); ?> · <?php echo esc_html( get_the_date( 'j M', $pid ) ); ?>
                    </span>
                </span>
                <?php if ( $f['accessible'] && $playlists ) : ?>
                    <span class="kmh-ajout" data-kmh-ajout="<?php echo esc_attr( $pid ); ?>">
                        <button type="button" class="kmh-ajout-btn" aria-label="<?php esc_attr_e( 'Ajouter à une playlist', 'km-family' ); ?>">+</button>
                        <select class="kmh-ajout-select" aria-label="<?php esc_attr_e( 'Choisir une playlist', 'km-family' ); ?>">
                            <option value=""><?php esc_html_e( '— Ajouter à…', 'km-family' ); ?></option>
                            <?php foreach ( $playlists as $pl ) : ?>
                                <option value="<?php echo esc_attr( $pl['id'] ); ?>"><?php echo esc_html( $pl['nom'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <!-- ══ PLAYLISTS ══ -->
    <section class="kmh-section">
        <div class="kmh-title-row">
            <h3 class="kmh-title"><?php esc_html_e( 'Mes playlists', 'km-family' ); ?></h3>
            <button type="button" class="kmh-mini" data-kmh-creer><?php esc_html_e( '+ Nouvelle', 'km-family' ); ?></button>
        </div>

        <div class="kmh-grid">
            <?php if ( $favoris ) : ?>
            <div class="kmh-collection kmh-collection--systeme">
                <span class="kmh-collection-nom">❤ <?php esc_html_e( 'Mes favoris', 'km-family' ); ?></span>
                <span class="kmh-collection-nb">
                    <?php printf( esc_html( _n( '%d titre', '%d titres', count( $favoris ), 'km-family' ) ), count( $favoris ) ); ?>
                </span>
            </div>
            <?php endif; ?>

            <?php foreach ( $playlists as $pl ) : ?>
                <div class="kmh-collection" data-kmh-playlist="<?php echo esc_attr( $pl['id'] ); ?>">
                    <span class="kmh-collection-nom"><?php echo esc_html( $pl['nom'] ); ?></span>
                    <span class="kmh-collection-nb">
                        <?php printf( esc_html( _n( '%d titre', '%d titres', (int) $pl['nb'], 'km-family' ) ), (int) $pl['nb'] ); ?>
                    </span>
                    <span class="kmh-collection-actions">
                        <button type="button" data-kmh-renommer><?php esc_html_e( 'Renommer', 'km-family' ); ?></button>
                        <button type="button" data-kmh-supprimer><?php esc_html_e( 'Supprimer', 'km-family' ); ?></button>
                    </span>
                </div>
            <?php endforeach; ?>

            <?php if ( ! $playlists && ! $favoris ) : ?>
                <p class="kmh-hint"><?php esc_html_e( 'Créez une playlist pour ranger ce que vous écoutez.', 'km-family' ); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ══ AGENDA ══ -->
    <?php if ( $atts['agenda'] !== 'non' ) echo do_shortcode( '[kmfamily_agenda jours="45"]' ); ?>

    <?php endif; ?>
</div>
        <?php
        return ob_get_clean();
    }
}
