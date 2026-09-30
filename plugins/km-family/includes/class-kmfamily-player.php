<?php
/**
 * KM Family — Lecteur audio : rendu, shortcode et réglages médias (LOT 2)
 *
 * Le shortcode [kmfamily_player] affiche la liste des contenus audio d'un
 * artiste et alimente le lecteur. La liste montre TOUS les contenus, y compris
 * ceux que le membre n'a pas encore le droit d'écouter : voir ce qu'on
 * n'atteint pas encore est précisément ce qui donne envie de monter de palier.
 * En revanche, aucune source de lecture n'est jamais émise pour ces
 * contenus-là — le verrou est côté serveur, à l'ouverture de session.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Player {

    public static function init() {
        add_shortcode( 'kmfamily_player', array( __CLASS__, 'render' ) );
        add_shortcode( 'kmfamily_video',  array( __CLASS__, 'render_video' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 14 );
        add_action( 'admin_post_kmfamily_save_media', array( __CLASS__, 'handle_save' ) );
    }

    public static function register_assets() {
        wp_register_style( 'kmfamily-player', KMFAMILY_URL . 'assets/css/km-player.css', array(), KMFAMILY_VERSION );
        wp_register_style( 'kmfamily-hub', KMFAMILY_URL . 'assets/css/km-hub.css', array(), KMFAMILY_VERSION );
        wp_register_script( 'kmfamily-player', KMFAMILY_URL . 'assets/js/km-player.js', array(), KMFAMILY_VERSION, true );
        wp_register_script( 'kmfamily-video', KMFAMILY_URL . 'assets/js/km-video.js', array(), KMFAMILY_VERSION, true );
        wp_register_script( 'kmfamily-rewards', KMFAMILY_URL . 'assets/js/km-rewards.js', array(), KMFAMILY_VERSION, true );

        $conf = array(
            'rest'  => esc_url_raw( rest_url( 'kmfamily/v1/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            // hls.js est servi depuis le site, jamais depuis un CDN tiers : les
            // membres n'ont pas à voir leur navigateur interroger un domaine
            // qu'ils n'ont pas choisi pour regarder une vidéo.
            'hlsUrl' => KMFAMILY_URL . 'assets/js/vendor/hls.light.min.js',
        );
        wp_localize_script( 'kmfamily-player', 'KMFamilyPlayer', $conf );
        wp_localize_script( 'kmfamily-video', 'KMFamilyPlayer', $conf );
        wp_localize_script( 'kmfamily-rewards', 'KMFamilyPlayer', $conf );
    }

    /**
     * [kmfamily_player artiste_id="123" limit="30" type="audio"]
     */
    public static function render( $atts ) {
        $atts = shortcode_atts( array(
            'artiste_id' => 0,
            'limit'      => 30,
            'type'       => 'audio',
            'playlist'   => 0,       // LOT 4 — id de playlist membre
            'favoris'    => 'non',   // LOT 4 — playlist virtuelle des favoris
        ), $atts, 'kmfamily_player' );

        if ( ! is_user_logged_in() ) {
            return '<div class="kmp-locked-notice">'
                . esc_html__( 'Connectez-vous pour écouter les contenus exclusifs.', 'km-family' )
                . '</div>';
        }

        $user_id     = get_current_user_id();
        $artiste_nom = '';
        $titre_liste = __( 'Écoute exclusive', 'km-family' );

        // ── Source : playlist membre, favoris, ou catalogue d'un artiste ──
        if ( absint( $atts['playlist'] ) ) {
            $pl = KMFamily_Playlists::get( absint( $atts['playlist'] ) );

            // Une playlist ne se lit que par son propriétaire : sans ce
            // contrôle, deviner un identifiant suffirait à lire la collection
            // d'un autre membre.
            if ( ! $pl || ! KMFamily_Playlists::owns( $user_id, $pl['id'] ) ) return '';

            $titre_liste = $pl['nom'];
            $ids = KMFamily_Playlists::get_items( $pl['id'] );
            $contenus = $ids ? get_posts( array(
                'post_type'      => 'contenu_exclusif',
                'post__in'       => $ids,
                'orderby'        => 'post__in',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
            ) ) : array();

        } elseif ( $atts['favoris'] === 'oui' ) {
            $titre_liste = __( 'Mes favoris', 'km-family' );
            $ids = array();
            foreach ( KMFamily_Playback::get_favorites( $user_id, 200 ) as $f ) $ids[] = (int) $f['content_id'];
            $contenus = $ids ? get_posts( array(
                'post_type'      => 'contenu_exclusif',
                'post__in'       => $ids,
                'orderby'        => 'post__in',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
            ) ) : array();

        } else {
            $artiste_id = absint( $atts['artiste_id'] );
            if ( ! $artiste_id ) $artiste_id = get_queried_object_id();
            if ( ! $artiste_id || get_post_type( $artiste_id ) !== 'nos-artistes' ) return '';
            $artiste_nom = get_the_title( $artiste_id );
            $contenus = self::query_artist_contents( $artiste_id, $atts );
        }

        if ( ! $contenus ) return '';

        wp_enqueue_style( 'kmfamily-player' );
        wp_enqueue_style( 'kmfamily-hub' );
        wp_enqueue_script( 'kmfamily-player' );

        return self::render_list( $contenus, $user_id, $artiste_nom, $titre_liste );
    }

    private static function query_artist_contents( $artiste_id, $atts ) {
        return get_posts( array(
            'post_type'      => 'contenu_exclusif',
            'posts_per_page' => max( 1, min( 100, (int) $atts['limit'] ) ),
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array( 'key' => 'artiste_lie', 'value' => absint( $artiste_id ) ),
                array( 'key' => 'type_media',  'value' => sanitize_key( $atts['type'] ) ),
            ),
        ) );
    }

    private static function render_list( $contenus, $user_id, $artiste_nom, $titre_liste ) {
        ob_start(); ?>
<div class="kmp-playlist" data-kmp-playlist>

    <div class="kmp-playlist-head">
        <h3 class="kmp-playlist-title"><?php echo esc_html( $titre_liste ); ?></h3>
        <span class="kmp-playlist-count">
            <?php printf( esc_html( _n( '%d titre', '%d titres', count( $contenus ), 'km-family' ) ), count( $contenus ) ); ?>
        </span>
    </div>

    <ul class="kmp-list">
    <?php foreach ( $contenus as $c ) :
        $accessible = KMFamily_Media_Session::user_can_play( $user_id, $c->ID );
        $palier     = function_exists( 'get_field' ) ? get_field( 'palier_requis', $c->ID ) : '';
        $duree      = function_exists( 'get_field' ) ? get_field( 'duree_estimee', $c->ID ) : '';
        $cover      = get_the_post_thumbnail_url( $c->ID, 'medium' );
        $etat       = KMFamily_Playback::get( $user_id, $c->ID );
        $favori     = $etat && (int) $etat['favorite'];
        $progress   = $etat ? (int) $etat['max_progress'] : 0;
        $palier_nom = ( $palier && class_exists( 'KMFamily_Paliers' ) && KMFamily_Paliers::get( $palier ) )
            ? KMFamily_Paliers::get( $palier )['nom'] : strtoupper( (string) $palier );

        // Compte à rebours personnalisé : « Pour vous dans 2 j 04 h » donne une
        // raison d'attendre là où « Réservé Diamant » donne une raison de partir.
        $badge = class_exists( 'KMFamily_Availability' )
            ? KMFamily_Availability::badge( $c->ID, $user_id )
            : array( 'texte' => '', 'ton' => 'neutre' );
    ?>
        <li class="kmp-item <?php echo $accessible ? '' : 'kmp-item--locked'; ?>"
            <?php if ( $accessible ) : ?>
                data-kmp-track="<?php echo esc_attr( $c->ID ); ?>"
                data-kmp-title="<?php echo esc_attr( $c->post_title ); ?>"
                data-kmp-artist="<?php echo esc_attr( $artiste_nom ?: self::artiste_name( $c->ID ) ); ?>"
                data-kmp-cover="<?php echo esc_attr( $cover ); ?>"
                data-kmp-favorite="<?php echo $favori ? '1' : '0'; ?>"
                data-kmp-exclusive="1"
                tabindex="0" role="button"
            <?php endif; ?>>

            <span class="kmp-item-cover" <?php if ( $cover ) : ?>style="background-image:url('<?php echo esc_url( $cover ); ?>')"<?php endif; ?>>
                <span class="kmp-item-icon"><?php echo $accessible ? '▶' : '🔒'; ?></span>
            </span>

            <span class="kmp-item-main">
                <span class="kmp-item-title"><?php echo esc_html( $c->post_title ); ?></span>
                <?php if ( ! empty( $badge['texte'] ) ) : ?>
                    <span class="kmp-item-badge kmp-item-badge--<?php echo esc_attr( $badge['ton'] ); ?>"><?php echo esc_html( $badge['texte'] ); ?></span>
                <?php endif; ?>
                <span class="kmp-item-sub">
                    <?php if ( $duree ) : ?><?php echo esc_html( $duree ); ?> · <?php endif; ?>
                    <?php if ( $accessible ) : ?>
                        <?php echo esc_html( $palier_nom ?: __( 'Exclusif', 'km-family' ) ); ?>
                    <?php else : ?>
                        <?php printf( esc_html__( 'Réservé au palier %s', 'km-family' ), esc_html( $palier_nom ) ); ?>
                    <?php endif; ?>
                </span>
                <?php if ( $progress > 0 && $progress < 95 ) : ?>
                    <span class="kmp-item-progress"><span style="width:<?php echo esc_attr( $progress ); ?>%"></span></span>
                <?php endif; ?>
            </span>

            <?php if ( $accessible ) : ?>
                <button class="kmp-item-fav" type="button"
                        data-kmp-fav="<?php echo esc_attr( $c->ID ); ?>"
                        aria-pressed="<?php echo $favori ? 'true' : 'false'; ?>"
                        aria-label="<?php esc_attr_e( 'Ajouter aux favoris', 'km-family' ); ?>"><?php echo $favori ? '❤' : '♡'; ?></button>
            <?php else : ?>
                <span class="kmp-item-lock"><?php echo esc_html( $palier_nom ); ?></span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
        <?php
        return ob_get_clean();
    }

    /**
     * [kmfamily_video content_id="123"]
     *
     * Le contrôle d'accès reste intégralement côté serveur : ce rendu ne fait
     * qu'afficher un cadre. Aucune source n'est émise ici — le lecteur demande
     * une session, et c'est elle qui décide.
     */
    public static function render_video( $atts ) {
        $atts = shortcode_atts( array( 'content_id' => 0 ), $atts, 'kmfamily_video' );

        $content_id = absint( $atts['content_id'] ) ?: get_the_ID();
        if ( ! $content_id || get_post_type( $content_id ) !== 'contenu_exclusif' ) return '';

        if ( ! is_user_logged_in() ) {
            return '<div class="kmp-locked-notice">'
                . esc_html__( 'Connectez-vous pour regarder ce contenu.', 'km-family' ) . '</div>';
        }

        $user_id = get_current_user_id();
        if ( ! KMFamily_Media_Session::user_can_play( $user_id, $content_id ) ) {
            $badge = class_exists( 'KMFamily_Availability' ) ? KMFamily_Availability::badge( $content_id, $user_id ) : array( 'texte' => '' );
            return '<div class="kmp-locked-notice">'
                . esc_html( $badge['texte'] ?: __( 'Ce contenu n\'est pas accessible avec votre palier.', 'km-family' ) )
                . '</div>';
        }

        wp_enqueue_style( 'kmfamily-player' );
        wp_enqueue_script( 'kmfamily-video' );

        $filigrane = apply_filters(
            'kmfamily_video_watermark',
            sprintf( 'KM FAMILY · %s #%d', __( 'Membre', 'km-family' ), $user_id ),
            $user_id, $content_id
        );

        ob_start(); ?>
        <div class="kmv-cadre" data-kmv-content="<?php echo esc_attr( $content_id ); ?>"
             data-kmv-filigrane="<?php echo esc_attr( $filigrane ); ?>">
            <video controls playsinline preload="none" controlsList="nodownload"></video>
            <div class="kmv-statut" data-kmv-statut></div>
            <div class="kmv-barre">
                <span class="kmv-titre"><?php echo esc_html( get_the_title( $content_id ) ); ?></span>
                <span data-kmv-qualite></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Nom de l'artiste d'un contenu — utile quand la liste en mélange plusieurs. */
    private static function artiste_name( $content_id ) {
        $a = function_exists( 'get_field' ) ? get_field( 'artiste_lie', $content_id ) : get_post_meta( $content_id, 'artiste_lie', true );
        if ( ! $a ) return '';
        return get_the_title( is_object( $a ) ? $a->ID : absint( $a ) );
    }

    // ==========================================================
    // ADMIN — Réglages médias
    // ==========================================================

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Médias & Diffusion', 'km-family' ),
            __( 'Médias & Diffusion', 'km-family' ),
            'manage_options',
            'kmfamily-medias',
            array( __CLASS__, 'render_settings' )
        );
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_save_media', 'kmfamily_media_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        $mode = sanitize_key( $_POST['media_mode'] ?? 'auto' );
        if ( ! in_array( $mode, array( 'auto', 'local', 'remote' ), true ) ) $mode = 'auto';
        update_option( KMFamily_Media_Router::OPTION_MODE, $mode );

        $seuil = absint( $_POST['media_threshold'] ?? 50 );
        update_option( KMFamily_Media_Router::OPTION_THRESHOLD, max( 1, $seuil ) * 1048576 );

        update_option( KMFamily_Media_Session::OPTION_MAX_STREAMS, max( 1, min( 10, absint( $_POST['max_streams'] ?? 2 ) ) ) );

        update_option( KMFamily_Media_Driver_Remote::OPTION_BASE, esc_url_raw( wp_unslash( $_POST['remote_base'] ?? '' ) ) );

        // Le secret n'est réécrit que s'il est fourni : le formulaire affiche
        // un champ vide, pour ne jamais renvoyer la clé dans une page HTML.
        $secret = trim( (string) wp_unslash( $_POST['remote_secret'] ?? '' ) );
        if ( $secret !== '' ) update_option( KMFamily_Media_Driver_Remote::OPTION_SECRET, $secret );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-medias&saved=1' ) );
        exit;
    }

    public static function render_settings() {
        $mode     = get_option( KMFamily_Media_Router::OPTION_MODE, 'auto' );
        $seuil_mo = (int) round( KMFamily_Media_Router::threshold() / 1048576 );
        $remote   = new KMFamily_Media_Driver_Remote();
        $actives  = KMFamily_Media_Session::count_active_all();
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Médias & Diffusion', 'km-family' ); ?></h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'km-family' ); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-info inline" style="max-width:820px">
                <p>
                    <strong><?php esc_html_e( 'Lectures en cours :', 'km-family' ); ?> <?php echo esc_html( $actives ); ?></strong><br />
                    <?php esc_html_e( 'Chaque lecture servie localement occupe un processus PHP pendant toute sa durée. Surveillez ce chiffre aux heures de pointe : c\'est lui, et non une intuition, qui vous dira si la diffusion externe devient nécessaire.', 'km-family' ); ?>
                </p>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_save_media" />
                <?php wp_nonce_field( 'kmfamily_save_media', 'kmfamily_media_nonce' ); ?>

                <h2><?php esc_html_e( 'Règle de diffusion', 'km-family' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Mode', 'km-family' ); ?></th>
                        <td>
                            <label><input type="radio" name="media_mode" value="auto"   <?php checked( $mode, 'auto' ); ?> /> <?php esc_html_e( 'Automatique (recommandé)', 'km-family' ); ?></label><br />
                            <label><input type="radio" name="media_mode" value="local"  <?php checked( $mode, 'local' ); ?> /> <?php esc_html_e( 'Toujours local', 'km-family' ); ?></label><br />
                            <label><input type="radio" name="media_mode" value="remote" <?php checked( $mode, 'remote' ); ?> /> <?php esc_html_e( 'Toujours distant', 'km-family' ); ?></label>
                            <p class="description">
                                <?php esc_html_e( 'En automatique : l\'audio reste toujours local, la vidéo part chez le prestataire au-delà du seuil ci-dessous. Un contenu peut être forcé individuellement.', 'km-family' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="media_threshold"><?php esc_html_e( 'Seuil de bascule', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" min="1" max="2000" id="media_threshold" name="media_threshold" class="small-text" value="<?php echo esc_attr( $seuil_mo ); ?>" /> <?php esc_html_e( 'Mo', 'km-family' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_streams"><?php esc_html_e( 'Lectures simultanées par membre', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" min="1" max="10" id="max_streams" name="max_streams" class="small-text" value="<?php echo esc_attr( KMFamily_Media_Session::max_streams() ); ?>" />
                            <p class="description"><?php esc_html_e( 'Au-delà, le membre est invité à arrêter une lecture en cours. C\'est le principal frein au partage de compte.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Prestataire de diffusion (optionnel)', 'km-family' ); ?></h2>
                <p class="description" style="max-width:820px">
                    <?php esc_html_e( 'Tant qu\'aucun prestataire n\'est configuré, tout est servi localement — aucune fonction n\'est perdue, seul le débit adaptatif reste indisponible.', 'km-family' ); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="remote_base"><?php esc_html_e( 'URL de base', 'km-family' ); ?></label></th>
                        <td>
                            <input type="url" id="remote_base" name="remote_base" class="regular-text"
                                   value="<?php echo esc_attr( get_option( KMFamily_Media_Driver_Remote::OPTION_BASE, '' ) ); ?>"
                                   placeholder="https://vz-xxxxxxx.b-cdn.net" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="remote_secret"><?php esc_html_e( 'Clé de signature', 'km-family' ); ?></label></th>
                        <td>
                            <input type="password" id="remote_secret" name="remote_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( KMFamily_Media_Driver_Remote::OPTION_SECRET ) ? esc_attr__( '— enregistrée —', 'km-family' ) : ''; ?>" />
                            <p class="description"><?php esc_html_e( 'Laisser vide pour conserver la clé actuelle. Elle n\'est jamais réaffichée.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'État', 'km-family' ); ?></th>
                        <td>
                            <?php if ( $remote->is_configured() ) : ?>
                                <span style="color:#008a20;font-weight:600">✔ <?php esc_html_e( 'Prestataire configuré', 'km-family' ); ?></span>
                            <?php else : ?>
                                <span style="color:#8a8a8a">— <?php esc_html_e( 'Non configuré : tout passe en local', 'km-family' ); ?></span>
                            <?php endif; ?>
                            <p class="description">
                                <?php printf(
                                    esc_html__( 'Pour rattacher un contenu au prestataire, renseignez son identifiant distant dans le champ personnalisé %s du contenu exclusif.', 'km-family' ),
                                    '<code>' . esc_html( KMFamily_Media_Driver_Remote::META_REMOTE ) . '</code>'
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
