<?php
/**
 * KM Family — Publication par l'artiste (LOT 1)
 *
 * PROBLÈME TRAITÉ :
 * aujourd'hui, seul un administrateur peut créer un contenu exclusif.
 * L'artiste n'a aucun accès de publication — le rôle `artiste_label` n'a même
 * pas la capacité `upload_files`. Concrètement : chaque photo de coulisses,
 * chaque vocal, chaque message doit passer par le label. Un club de fans dont
 * le contenu dépend de la disponibilité d'une seule personne s'éteint en
 * quelques mois, et c'est la première cause de départ des membres.
 *
 * CHOIX DE CONCEPTION — pourquoi ne pas simplement donner les capacités WP :
 * accorder `upload_files` / `edit_posts` au rôle artiste ouvrirait la
 * médiathèque entière (donc les fichiers des AUTRES artistes) et l'accès à
 * wp-admin, que le dashboard s'emploie justement à fermer. On garde donc le
 * rôle verrouillé et on autorise ici, point par point, uniquement ce dont
 * l'artiste a besoin, avec sa propre vérification de droits.
 *
 * Les fichiers déposés passent par update_field('fichier_media'), ce qui
 * déclenche KMFamily_Media_Protector et place automatiquement le fichier dans
 * le dossier protégé — l'artiste n'a aucun moyen de publier un média en accès
 * direct.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Artist_Publishing {

    const OPTION_MODERATION = 'kmfamily_artist_moderation';   // 1 = relecture avant publication
    const OPTION_DAILY_MAX  = 'kmfamily_artist_daily_max';    // garde-fou anti-flood
    const META_AUTHOR       = '_kmfamily_publie_par_artiste';

    const DEFAULT_DAILY_MAX = 10;
    const MAX_UPLOAD_BYTES  = 62914560; // 60 Mo — au-delà, la vidéo doit passer par le label

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 13 );
        add_action( 'admin_post_kmfamily_save_publishing', array( __CLASS__, 'handle_save_settings' ) );

        // Signale à l'admin les contenus d'artistes en attente de relecture.
        add_action( 'admin_notices', array( __CLASS__, 'moderation_notice' ) );
    }

    // ==========================================================
    // DROITS
    // ==========================================================

    /** Post 'nos-artistes' rattaché à cet utilisateur, ou 0. */
    public static function get_artiste_id( $user_id ) {
        if ( class_exists( 'KMFamily_Revenue' ) ) {
            return KMFamily_Revenue::get_artiste_id_by_user( $user_id );
        }

        $posts = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_key'       => 'artiste_user_id',
            'meta_value'     => absint( $user_id ),
        ) );
        return $posts ? (int) $posts[0] : 0;
    }

    public static function can_publish( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return false;
        return self::get_artiste_id( $user_id ) > 0;
    }

    public static function moderation_enabled() {
        // Relecture activée par défaut : on préfère qu'un label découvre la
        // fonctionnalité avec un filet, quitte à le retirer ensuite.
        $v = get_option( self::OPTION_MODERATION, null );
        return $v === null ? true : (bool) $v;
    }

    public static function daily_max() {
        $v = (int) get_option( self::OPTION_DAILY_MAX, self::DEFAULT_DAILY_MAX );
        return $v > 0 ? $v : self::DEFAULT_DAILY_MAX;
    }

    /** Nombre de contenus publiés par cet artiste depuis 24 h. */
    public static function count_today( $artiste_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = 'artiste_lie'
             WHERE p.post_type = 'contenu_exclusif'
               AND p.post_status IN ('publish','pending','draft')
               AND a.meta_value = %s
               AND p.post_date_gmt > %s",
            self::META_AUTHOR, (string) absint( $artiste_id ),
            KMFamily_Orders::local_datetime( - DAY_IN_SECONDS )
        ) );
    }

    // ==========================================================
    // TYPES DE CONTENU AUTORISÉS
    // ==========================================================

    /**
     * Formats acceptés côté artiste. Volontairement restreint : pas de zip,
     * pas de pdf, pas de fichier exécutable. La liste blanche est appliquée
     * sur le type MIME réel détecté par WordPress, pas sur l'extension.
     */
    public static function allowed_mimes() {
        return apply_filters( 'kmfamily_artist_allowed_mimes', array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'mp3'          => 'audio/mpeg',
            'm4a'          => 'audio/mp4',
            'ogg'          => 'audio/ogg',
            'wav'          => 'audio/wav',
            'mp4'          => 'video/mp4',
            'mov'          => 'video/quicktime',
            'webm'         => 'video/webm',
        ) );
    }

    /** Types de publication proposés à l'artiste. */
    public static function post_types() {
        return array(
            'message'   => array( 'label' => __( 'Message à la famille', 'km-family' ), 'icon' => '💬', 'media' => 'optionnel', 'type_media' => 'texte' ),
            'photo'     => array( 'label' => __( 'Photo / coulisses', 'km-family' ),     'icon' => '📸', 'media' => 'requis',    'type_media' => 'image' ),
            'audio'     => array( 'label' => __( 'Vocal / extrait audio', 'km-family' ), 'icon' => '🎧', 'media' => 'requis',    'type_media' => 'audio' ),
            'video'     => array( 'label' => __( 'Vidéo courte', 'km-family' ),          'icon' => '🎬', 'media' => 'requis',    'type_media' => 'video' ),
        );
    }

    // ==========================================================
    // PUBLICATION
    // ==========================================================

    /**
     * Crée un contenu exclusif au nom de l'artiste.
     *
     * @param int   $user_id Utilisateur artiste.
     * @param array $args    format, titre, message, palier, file (clé de $_FILES).
     * @return array|WP_Error array( 'post_id', 'status' )
     */
    public static function publish( $user_id, array $args ) {
        $user_id    = absint( $user_id );
        $artiste_id = self::get_artiste_id( $user_id );

        if ( ! $artiste_id ) {
            return new WP_Error( 'not_artist', __( 'Votre compte n\'est rattaché à aucune fiche artiste.', 'km-family' ) );
        }

        $formats = self::post_types();
        $format  = isset( $args['format'] ) && isset( $formats[ $args['format'] ] ) ? $args['format'] : 'message';
        $cfg     = $formats[ $format ];

        $titre   = sanitize_text_field( (string) ( $args['titre'] ?? '' ) );
        $message = wp_kses_post( (string) ( $args['message'] ?? '' ) );
        $palier  = sanitize_key( (string) ( $args['palier'] ?? 'bronze' ) );

        $paliers_valides = array( 'public', 'libre', 'bronze', 'argent', 'or', 'platine', 'diamant' );
        if ( ! in_array( $palier, $paliers_valides, true ) ) $palier = 'bronze';

        if ( $titre === '' && $message === '' ) {
            return new WP_Error( 'empty', __( 'Ajoutez au moins un titre ou un message.', 'km-family' ) );
        }
        if ( $titre === '' ) {
            $titre = wp_trim_words( wp_strip_all_tags( $message ), 8, '…' );
        }

        if ( self::count_today( $artiste_id ) >= self::daily_max() ) {
            return new WP_Error( 'rate_limit', sprintf(
                __( 'Vous avez atteint la limite de %d publications sur 24 heures. Réessayez demain.', 'km-family' ),
                self::daily_max()
            ) );
        }

        // ── Média ─────────────────────────────────────────────
        $attachment_id = 0;
        $has_file = ! empty( $_FILES['kmfp_file']['name'] ?? '' );

        if ( $cfg['media'] === 'requis' && ! $has_file ) {
            return new WP_Error( 'file_required', __( 'Ce format demande un fichier.', 'km-family' ) );
        }

        if ( $has_file ) {
            $attachment_id = self::handle_upload( $user_id );
            if ( is_wp_error( $attachment_id ) ) return $attachment_id;
        }

        // ── Création du post ──────────────────────────────────
        // L'auteur du post est l'utilisateur artiste : la paternité est réelle,
        // ce qui permettra plus tard un filtre « publié par l'artiste ».
        $status = self::moderation_enabled() ? 'pending' : 'publish';

        $post_id = wp_insert_post( array(
            'post_type'    => 'contenu_exclusif',
            'post_title'   => $titre,
            'post_content' => $message,
            'post_status'  => $status,
            'post_author'  => $user_id,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            if ( $attachment_id ) wp_delete_attachment( $attachment_id, true );
            return $post_id;
        }

        update_post_meta( $post_id, self::META_AUTHOR, 1 );

        // update_field() plutôt que update_post_meta() : c'est ce qui déclenche
        // le filtre acf/update_value/name=fichier_media, donc la mise en
        // dossier protégé par KMFamily_Media_Protector.
        if ( function_exists( 'update_field' ) ) {
            update_field( 'artiste_lie', $artiste_id, $post_id );
            update_field( 'palier_requis', $palier, $post_id );
            update_field( 'type_media', $cfg['type_media'], $post_id );
            if ( $attachment_id ) update_field( 'fichier_media', $attachment_id, $post_id );
        } else {
            update_post_meta( $post_id, 'artiste_lie', $artiste_id );
            update_post_meta( $post_id, 'palier_requis', $palier );
            update_post_meta( $post_id, 'type_media', $cfg['type_media'] );
            if ( $attachment_id ) update_post_meta( $post_id, 'fichier_media', $attachment_id );
        }

        // Taxonomie de palier, pour rester cohérent avec les contenus créés en admin.
        if ( taxonomy_exists( 'palier_acces' ) ) {
            wp_set_object_terms( $post_id, $palier, 'palier_acces', false );
        }
        if ( taxonomy_exists( 'type_contenu' ) ) {
            $map = array( 'texte' => 'actualite', 'image' => 'galerie', 'audio' => 'audio', 'video' => 'video' );
            $term = $map[ $cfg['type_media'] ] ?? '';
            if ( $term ) wp_set_object_terms( $post_id, $term, 'type_contenu', false );
        }

        do_action( 'kmfamily_artist_published', $post_id, $user_id, $artiste_id, $status );

        if ( $status === 'pending' ) self::notify_admin( $post_id, $artiste_id );

        return array( 'post_id' => $post_id, 'status' => $status );
    }

    /**
     * Téléversement sans accorder `upload_files` au rôle artiste.
     * On vérifie nous-mêmes : taille, type MIME réel, et le fait que
     * l'appelant soit bien un artiste rattaché.
     */
    private static function handle_upload( $user_id ) {
        if ( ! self::can_publish( $user_id ) ) {
            return new WP_Error( 'forbidden', __( 'Droits insuffisants.', 'km-family' ) );
        }

        $file = $_FILES['kmfp_file'] ?? null;
        if ( ! $file || ! empty( $file['error'] ) ) {
            return new WP_Error( 'upload_error', __( 'Le fichier n\'a pas pu être reçu. Vérifiez sa taille et réessayez.', 'km-family' ) );
        }
        if ( (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
            return new WP_Error( 'too_big', sprintf(
                __( 'Fichier trop lourd (maximum %d Mo). Pour une vidéo plus longue, passez par le label.', 'km-family' ),
                (int) round( self::MAX_UPLOAD_BYTES / 1048576 )
            ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_mimes() );
        if ( empty( $check['type'] ) || ! in_array( $check['type'], array_values( self::allowed_mimes() ), true ) ) {
            return new WP_Error( 'bad_type', __( 'Format non autorisé. Formats acceptés : JPG, PNG, WEBP, MP3, M4A, WAV, OGG, MP4, MOV, WEBM.', 'km-family' ) );
        }

        $moved = wp_handle_upload( $file, array(
            'test_form' => false,
            'mimes'     => self::allowed_mimes(),
        ) );

        if ( ! $moved || ! empty( $moved['error'] ) ) {
            return new WP_Error( 'upload_failed', $moved['error'] ?? __( 'Échec du téléversement.', 'km-family' ) );
        }

        $attachment_id = wp_insert_attachment( array(
            'post_mime_type' => $moved['type'],
            'post_title'     => sanitize_file_name( pathinfo( $moved['file'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => absint( $user_id ),
        ), $moved['file'] );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            @unlink( $moved['file'] );
            return new WP_Error( 'attachment_failed', __( 'Le fichier n\'a pas pu être enregistré.', 'km-family' ) );
        }

        // Les métadonnées (miniatures, durée) sont générées AVANT le passage en
        // dossier protégé, qui intervient ensuite via update_field().
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $moved['file'] ) );

        return (int) $attachment_id;
    }

    private static function notify_admin( $post_id, $artiste_id ) {
        $to = get_option( 'admin_email' );
        if ( class_exists( 'KMFamily_Notifications' ) && method_exists( 'KMFamily_Notifications', 'get_notification_email' ) ) {
            $to = KMFamily_Notifications::get_notification_email() ?: $to;
        }
        if ( ! is_email( $to ) ) return;

        $subject = sprintf( __( '[KM Family] %s a publié un contenu à relire', 'km-family' ), get_the_title( $artiste_id ) );
        $body    = sprintf(
            __( "%1\$s vient de publier « %2\$s ».\n\nCe contenu attend votre relecture avant d'être visible par les membres :\n%3\$s", 'km-family' ),
            get_the_title( $artiste_id ),
            get_the_title( $post_id ),
            admin_url( 'post.php?post=' . absint( $post_id ) . '&action=edit' )
        );

        wp_mail( $to, $subject, $body );
    }

    /** Contenus d'artistes en attente de relecture. */
    public static function count_pending() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'contenu_exclusif' AND p.post_status = 'pending'",
            self::META_AUTHOR
        ) );
    }

    /** Dernières publications d'un artiste, pour l'affichage dans son espace. */
    public static function get_artist_posts( $artiste_id, $limit = 10 ) {
        return get_posts( array(
            'post_type'      => 'contenu_exclusif',
            'posts_per_page' => max( 1, min( 50, (int) $limit ) ),
            'post_status'    => array( 'publish', 'pending', 'draft' ),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'artiste_lie', 'value' => absint( $artiste_id ) ),
                array( 'key' => self::META_AUTHOR, 'value' => '1' ),
            ),
        ) );
    }

    // ==========================================================
    // ADMIN
    // ==========================================================

    public static function moderation_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $n = self::count_pending();
        if ( ! $n ) return;

        $screen = get_current_screen();
        if ( $screen && strpos( (string) $screen->id, 'km-family' ) === false
             && strpos( (string) $screen->id, 'kmfamily' ) === false
             && $screen->id !== 'dashboard' ) return;

        printf(
            '<div class="notice notice-warning"><p><strong>KM Family</strong> — %s <a href="%s">%s</a></p></div>',
            esc_html( sprintf( _n( '%d contenu d\'artiste attend votre relecture.', '%d contenus d\'artistes attendent votre relecture.', $n, 'km-family' ), $n ) ),
            esc_url( admin_url( 'edit.php?post_type=contenu_exclusif&post_status=pending' ) ),
            esc_html__( 'Relire maintenant', 'km-family' )
        );
    }

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Publication artistes', 'km-family' ),
            __( 'Publication artistes', 'km-family' ),
            'manage_options',
            'kmfamily-publishing',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_save_publishing', 'kmfamily_publishing_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        update_option( self::OPTION_MODERATION, ! empty( $_POST['moderation'] ) ? 1 : 0 );
        update_option( self::OPTION_DAILY_MAX, max( 1, min( 100, absint( $_POST['daily_max'] ?? self::DEFAULT_DAILY_MAX ) ) ) );
        update_option( KMFamily_Renewal::OPTION_ENABLED, ! empty( $_POST['renewal_reminders'] ) ? 1 : 0 );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-publishing&saved=1' ) );
        exit;
    }

    public static function render_page() {
        $pending  = self::count_pending();
        $last_run = get_option( KMFamily_Renewal::OPTION_LAST_RUN, array() );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Publication artistes & relances', 'km-family' ); ?></h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'km-family' ); ?></p></div>
            <?php endif; ?>

            <p style="max-width:820px">
                <?php esc_html_e( 'Les artistes peuvent publier eux-mêmes un message, une photo, un vocal ou une vidéo courte depuis leur tableau de bord, sans accès à l\'administration WordPress ni à la médiathèque. Les fichiers déposés sont automatiquement placés dans le dossier protégé.', 'km-family' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_save_publishing" />
                <?php wp_nonce_field( 'kmfamily_save_publishing', 'kmfamily_publishing_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Relecture avant publication', 'km-family' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="moderation" value="1" <?php checked( self::moderation_enabled() ); ?> />
                                <?php esc_html_e( 'Les publications d\'artistes passent en attente de relecture', 'km-family' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Décoché, les publications sont visibles immédiatement par les membres. Vous gagnez en fraîcheur ce que vous perdez en contrôle éditorial — un arbitrage à trancher artiste par artiste, dans la durée.', 'km-family' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="daily_max"><?php esc_html_e( 'Publications maximum / 24 h', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" min="1" max="100" id="daily_max" name="daily_max" class="small-text"
                                   value="<?php echo esc_attr( self::daily_max() ); ?>" />
                            <p class="description"><?php esc_html_e( 'Garde-fou par artiste, contre une publication accidentelle en boucle.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Relances de renouvellement', 'km-family' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="renewal_reminders" value="1" <?php checked( KMFamily_Renewal::is_enabled() ); ?> />
                                <?php esc_html_e( 'Envoyer les relances J-7, J-1, J+2, J+7 et J+15', 'km-family' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'S\'ajoute aux messages existants de J-3 et du jour d\'expiration, sans les doubler. Chaque étape n\'est envoyée qu\'une seule fois par cycle d\'abonnement.', 'km-family' ); ?>
                            </p>
                            <?php if ( ! empty( $last_run['time'] ) ) : ?>
                                <p class="description">
                                    <?php printf(
                                        esc_html__( 'Dernière passe : %1$s — %2$d relance(s) envoyée(s).', 'km-family' ),
                                        esc_html( $last_run['time'] ), absint( $last_run['sent'] ?? 0 )
                                    ); ?>
                                </p>
                            <?php endif; ?>
                            <?php if ( ! class_exists( 'KMFamily_Memberships' ) || ! KMFamily_Memberships::is_ready() ) : ?>
                                <p class="description" style="color:#b32d2e">
                                    <strong><?php esc_html_e( 'Inactif :', 'km-family' ); ?></strong>
                                    <?php esc_html_e( 'les relances s\'appuient sur le socle des adhésions. Lancez d\'abord la reprise dans « Socle & Migration ».', 'km-family' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'À relire', 'km-family' ); ?></h2>
            <?php if ( $pending ) : ?>
                <p>
                    <strong><?php echo esc_html( $pending ); ?></strong>
                    <?php esc_html_e( 'contenu(s) d\'artiste en attente.', 'km-family' ); ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=contenu_exclusif&post_status=pending' ) ); ?>">
                        <?php esc_html_e( 'Ouvrir la file', 'km-family' ); ?>
                    </a>
                </p>
            <?php else : ?>
                <p><?php esc_html_e( 'Rien en attente.', 'km-family' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
