<?php
/**
 * Interface d'administration KM SALLE D'ÉCOUTE
 * - Page de réglages
 * - Colonne personnalisée dans la liste des sessions
 * - Bouton "Copier le lien" dans la liste
 * - Page des logs d'accès
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_Admin {

    public static function init(): void {
        add_action( 'admin_menu',                     [ __CLASS__, 'add_menu' ] );
        add_filter( 'manage_kse_session_posts_columns',       [ __CLASS__, 'add_columns' ] );
        add_action( 'manage_kse_session_posts_custom_column', [ __CLASS__, 'render_columns' ], 10, 2 );
        add_action( 'admin_enqueue_scripts',          [ __CLASS__, 'admin_assets' ] );
        add_action( 'admin_enqueue_scripts',          [ __CLASS__, 'admin_style' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'edit.php?post_type=kse_session',
            __( 'Réglages KM Salle d\'Écoute', 'km-salle-ecoute' ),
            __( 'Réglages', 'km-salle-ecoute' ),
            'manage_options',
            'km-salle-ecoute-settings',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=kse_session',
            __( 'Logs d\'Accès', 'km-salle-ecoute' ),
            __( '📊 Logs d\'Accès', 'km-salle-ecoute' ),
            'manage_options',
            'km-salle-ecoute-logs',
            [ __CLASS__, 'render_logs_page' ]
        );
    }

    public static function add_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['km_artiste']  = __( 'Artiste', 'km-salle-ecoute' );
                $new['km_acces']    = __( 'Accès', 'km-salle-ecoute' );
                $new['km_dl']       = __( 'Téléchargement', 'km-salle-ecoute' );
                $new['km_expire']   = __( 'Expiration', 'km-salle-ecoute' );
                $new['km_lien']     = __( 'Lien Partenaire', 'km-salle-ecoute' );
            }
        }
        return $new;
    }

    public static function render_columns( string $column, int $post_id ): void {
        if ( ! function_exists( 'get_field' ) ) { echo '<em>—</em>'; return; }

        switch ( $column ) {
            case 'km_artiste':
                $nom = KM_Salle_Ecoute_ACF_Helper::artiste_nom( $post_id );
                echo '' !== $nom ? esc_html( $nom ) : '<em>—</em>';
                break;

            case 'km_acces':
                $type = KM_Salle_Ecoute_Access_Control::access_type( $post_id );
                $labels = [
                    'public'   => '🌍 Public',
                    'password' => '🔑 Mot de passe',
                    'prive'    => '🔒 Privé',
                ];
                if ( isset( $labels[ $type ] ) ) {
                    printf(
                        '<span class="km-acces-badge km-badge-%s">%s</span>',
                        esc_attr( $type ),
                        esc_html( $labels[ $type ] )
                    );
                } else {
                    echo '<em>—</em>';
                }
                break;

            case 'km_dl':
                $mode = get_option( 'kse_telechargement_mode', 'session' );
                if ( 'force_on' === $mode ) {
                    echo '<span style="color:#388E3C;">✅ ' . esc_html__( 'Autorisé (global)', 'km-salle-ecoute' ) . '</span>';
                } elseif ( 'force_off' === $mode ) {
                    echo '<span style="color:#e53935;">⛔ ' . esc_html__( 'Bloqué (global)', 'km-salle-ecoute' ) . '</span>';
                } else {
                    $dl = get_field( 'km_telechargement_autorise', $post_id );
                    echo $dl
                        ? '<span style="color:#388E3C;">✅ ' . esc_html__( 'Autorisé', 'km-salle-ecoute' ) . '</span>'
                        : '<span style="color:#999;">— ' . esc_html__( 'Non autorisé', 'km-salle-ecoute' ) . '</span>';
                }
                break;

            case 'km_expire':
                $ts = KM_Salle_Ecoute_Access_Control::expiration_timestamp( $post_id );
                if ( $ts ) {
                    $expired = $ts < time();
                    printf(
                        '<span style="color:%s;">%s%s</span>',
                        $expired ? '#e53935' : '#388E3C',
                        esc_html( wp_date( 'd/m/Y', $ts ) ),
                        $expired ? ' — ' . esc_html__( 'expirée', 'km-salle-ecoute' ) : ''
                    );
                } else {
                    echo '<em style="color:#999;">' . esc_html__( 'Aucune', 'km-salle-ecoute' ) . '</em>';
                }
                break;

            case 'km_lien':
                $permalink = get_permalink( $post_id );
                if ( 'publish' !== get_post_status( $post_id ) ) {
                    echo '<em style="color:#999;">' . esc_html__( 'Non publiée', 'km-salle-ecoute' ) . '</em>';
                    break;
                }
                echo '<button type="button" class="button button-small km-copy-link" data-url="' . esc_url( $permalink ) . '">'
                    . esc_html__( 'Copier le lien', 'km-salle-ecoute' )
                    . '</button>';
                break;
        }
    }

    public static function admin_assets( string $hook ): void {
        $allowed_hooks = [ 'edit.php', 'kse_session_page_km-salle-ecoute-settings', 'kse_session_page_km-salle-ecoute-logs' ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        if ( 'edit.php' === $hook && ( $_GET['post_type'] ?? '' ) !== 'kse_session' ) return; // phpcs:ignore

        // Copie du lien : API Clipboard si disponible (HTTPS), sinon repli execCommand
        // (auparavant le bouton ne faisait rien sur un site en HTTP).
        wp_add_inline_script( 'jquery-core', <<<'JS'
jQuery(function ($) {
    function fallbackCopy(text) {
        var $t = $('<textarea readonly>').val(text).css({ position: 'fixed', top: '-1000px' }).appendTo('body');
        $t[0].select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        $t.remove();
        return ok;
    }
    $(document).on('click', '.km-copy-link', function (e) {
        e.preventDefault();
        var $btn = $(this), url = String($btn.data('url')), original = $btn.data('label') || $btn.text();
        $btn.data('label', original);
        function done(ok) {
            $btn.toggleClass('copied', ok).text(ok ? 'Copié !' : 'Copie impossible');
            if (!ok) window.prompt('Copiez le lien :', url);
            setTimeout(function () { $btn.removeClass('copied').text(original); }, 2000);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(function () { done(true); }, function () { done(fallbackCopy(url)); });
        } else {
            done(fallbackCopy(url));
        }
    });
});
JS
        );
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Sauvegarde
        if ( isset( $_POST['kse_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kse_settings_nonce'] ) ), 'kse_settings' ) ) {
            update_option( 'kse_label_nom',   sanitize_text_field( wp_unslash( $_POST['km_label_nom'] ?? '' ) ) );
            update_option( 'kse_label_email', sanitize_email( wp_unslash( $_POST['km_label_email'] ?? '' ) ) );
            update_option( 'kse_couleur_primaire', sanitize_hex_color( wp_unslash( $_POST['km_couleur_primaire'] ?? '' ) ) ?: '#1a1a2e' );
            update_option( 'kse_couleur_accent',   sanitize_hex_color( wp_unslash( $_POST['km_couleur_accent'] ?? '' ) ) ?: '#e94560' );
            update_option( 'kse_logs_actifs',   empty( $_POST['kse_logs_actifs'] ) ? 0 : 1 );
            update_option( 'kse_masquer_theme', empty( $_POST['kse_masquer_theme'] ) ? 0 : 1 );
            update_option( 'kse_google_font',   empty( $_POST['kse_google_font'] ) ? 0 : 1 );

            $mode = sanitize_text_field( wp_unslash( $_POST['kse_telechargement_mode'] ?? 'session' ) );
            if ( ! in_array( $mode, [ 'session', 'force_on', 'force_off' ], true ) ) $mode = 'session';
            update_option( 'kse_telechargement_mode', $mode );

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Réglages sauvegardés.', 'km-salle-ecoute' ) . '</p></div>';
        }

        $nom   = KM_Salle_Ecoute_ACF_Helper::label_nom();
        $email = get_option( 'kse_label_email', '' );
        $prim  = get_option( 'kse_couleur_primaire', '#1a1a2e' );
        $acc   = get_option( 'kse_couleur_accent',   '#e94560' );
        $dl_mode = get_option( 'kse_telechargement_mode', 'session' );
        $logs_on = (int) get_option( 'kse_logs_actifs', 0 );
        $hide_th = (int) get_option( 'kse_masquer_theme', 1 );
        $gfont   = (int) get_option( 'kse_google_font', 1 );
        ?>
        <div class="wrap">
            <h1>🎵 <?php esc_html_e( 'KM SALLE D\'ÉCOUTE — Réglages', 'km-salle-ecoute' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'kse_settings', 'kse_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Nom du Label', 'km-salle-ecoute' ); ?></th>
                        <td><input type="text" name="km_label_nom" value="<?php echo esc_attr( $nom ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Email de contact', 'km-salle-ecoute' ); ?></th>
                        <td><input type="email" name="km_label_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Couleur principale', 'km-salle-ecoute' ); ?></th>
                        <td><input type="color" name="km_couleur_primaire" value="<?php echo esc_attr( $prim ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Couleur accent', 'km-salle-ecoute' ); ?></th>
                        <td><input type="color" name="km_couleur_accent" value="<?php echo esc_attr( $acc ); ?>" />
                            <p class="description"><?php esc_html_e( 'Couleur des boutons, de la progression et des éléments actifs du lecteur. La couleur principale teinte le fond du haut de page quand la pochette ne le permet pas.', 'km-salle-ecoute' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Journal des accès', 'km-salle-ecoute' ); ?></th>
                        <td><label><input type="checkbox" name="kse_logs_actifs" value="1" <?php checked( $logs_on, 1 ); ?> />
                            <?php esc_html_e( 'Enregistrer chaque ouverture de session par un partenaire (IP, navigateur, date)', 'km-salle-ecoute' ); ?></label>
                            <p class="description"><?php esc_html_e( '500 dernières entrées conservées. Pensez à le mentionner dans votre politique de confidentialité (RGPD).', 'km-salle-ecoute' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Affichage', 'km-salle-ecoute' ); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="kse_masquer_theme" value="1" <?php checked( $hide_th, 1 ); ?> />
                            <?php esc_html_e( 'Masquer l\'en-tête et le pied de page du thème sur les pages d\'écoute (plein écran)', 'km-salle-ecoute' ); ?></label>
                            <label style="display:block;"><input type="checkbox" name="kse_google_font" value="1" <?php checked( $gfont, 1 ); ?> />
                            <?php esc_html_e( 'Charger la police Inter depuis Google Fonts (décochez pour la police du système — recommandé RGPD)', 'km-salle-ecoute' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Téléchargement des fichiers audio', 'km-salle-ecoute' ); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block;margin-bottom:8px;">
                                    <input type="radio" name="kse_telechargement_mode" value="session" <?php checked( $dl_mode, 'session' ); ?> />
                                    <?php esc_html_e( 'Selon chaque session (recommandé)', 'km-salle-ecoute' ); ?>
                                    <p class="description" style="margin:2px 0 0 24px;">
                                        <?php esc_html_e( 'Le téléchargement dépend du réglage "Autoriser le téléchargement ?" défini sur chaque session — par exemple une fois que le client a réglé/soldé sa commande.', 'km-salle-ecoute' ); ?>
                                    </p>
                                </label>
                                <label style="display:block;margin-bottom:8px;">
                                    <input type="radio" name="kse_telechargement_mode" value="force_on" <?php checked( $dl_mode, 'force_on' ); ?> />
                                    <?php esc_html_e( 'Toujours autorisé (toutes les sessions)', 'km-salle-ecoute' ); ?>
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="kse_telechargement_mode" value="force_off" <?php checked( $dl_mode, 'force_off' ); ?> />
                                    <?php esc_html_e( 'Toujours désactivé (toutes les sessions)', 'km-salle-ecoute' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Enregistrer les réglages', 'km-salle-ecoute' ) ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Shortcodes disponibles', 'km-salle-ecoute' ); ?></h2>
            <table class="widefat striped">
                <thead><tr><th>Shortcode</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>[km_salle_ecoute_catalogue]</code></td><td><?php esc_html_e( 'Affiche toutes les sessions publiées.', 'km-salle-ecoute' ); ?></td></tr>
                    <tr><td><code>[km_salle_ecoute_catalogue type="demo"]</code></td><td><?php esc_html_e( 'Filtre par type (demo, produit_fini…).', 'km-salle-ecoute' ); ?></td></tr>
                    <tr><td><code>[km_salle_ecoute_player id="123"]</code></td><td><?php esc_html_e( 'Lecteur inline pour une session précise.', 'km-salle-ecoute' ); ?></td></tr>
                    <tr><td><code>[km_salle_ecoute_expired]</code></td><td><?php esc_html_e( 'Message lien expiré (page automatique).', 'km-salle-ecoute' ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_logs_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Effacer les logs
        if ( isset( $_POST['kse_clear_logs_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kse_clear_logs_nonce'] ) ), 'kse_clear_logs' ) ) {
            delete_option( 'kse_access_logs' );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Logs effacés.', 'km-salle-ecoute' ) . '</p></div>';
        }

        $logs = get_option( 'kse_access_logs', [] );
        $logs = is_array( $logs ) ? array_reverse( $logs ) : [];
        ?>
        <div class="wrap">
            <h1>📊 <?php esc_html_e( 'KM SALLE D\'ÉCOUTE — Logs d\'Accès', 'km-salle-ecoute' ); ?></h1>
            <p><?php printf( esc_html__( '%d entrée(s) enregistrée(s).', 'km-salle-ecoute' ), count( $logs ) ); ?></p>

            <?php if ( $logs ) : ?>
            <form method="post" style="margin-bottom:15px;">
                <?php wp_nonce_field( 'kse_clear_logs', 'kse_clear_logs_nonce' ); ?>
                <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Effacer tous les logs ?', 'km-salle-ecoute' ) ); ?>')">
                    🗑️ <?php esc_html_e( 'Effacer tous les logs', 'km-salle-ecoute' ); ?>
                </button>
            </form>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date & Heure', 'km-salle-ecoute' ); ?></th>
                        <th><?php esc_html_e( 'Session', 'km-salle-ecoute' ); ?></th>
                        <th><?php esc_html_e( 'Adresse IP', 'km-salle-ecoute' ); ?></th>
                        <th><?php esc_html_e( 'Compte', 'km-salle-ecoute' ); ?></th>
                        <th><?php esc_html_e( 'Navigateur', 'km-salle-ecoute' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log['timestamp'] ?? '' ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( (string) get_edit_post_link( (int) ( $log['post_id'] ?? 0 ) ) ); ?>">
                                <?php echo esc_html( $log['post_title'] ?? '' ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $log['ip'] ?? '' ); ?></td>
                        <td><?php echo esc_html( ( $log['user'] ?? '' ) ?: '—' ); ?></td>
                        <td style="font-size:11px;color:#666;"><?php echo esc_html( substr( $log['user_agent'] ?? '', 0, 80 ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p><em><?php
                if ( get_option( 'kse_logs_actifs', 0 ) ) {
                    esc_html_e( 'Aucun accès enregistré pour l\'instant.', 'km-salle-ecoute' );
                } else {
                    printf(
                        /* translators: %s: lien vers les réglages */
                        esc_html__( 'Le journal est désactivé. Activez-le dans %s.', 'km-salle-ecoute' ),
                        '<a href="' . esc_url( admin_url( 'edit.php?post_type=kse_session&page=km-salle-ecoute-settings' ) ) . '">' . esc_html__( 'Réglages', 'km-salle-ecoute' ) . '</a>'
                    );
                }
            ?></em></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function admin_style( string $hook ): void {
        $allowed_hooks = [ 'edit.php', 'kse_session_page_km-salle-ecoute-settings', 'kse_session_page_km-salle-ecoute-logs' ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        // Sur edit.php, ne charger que pour notre CPT (évite de polluer les autres listes)
        if ( 'edit.php' === $hook && ( $_GET['post_type'] ?? '' ) !== 'kse_session' ) return;

        wp_enqueue_style(
            'km-salle-ecoute-admin-style',
            KM_SALLE_ECOUTE_URL . 'assets/css/km-salle-ecoute-admin.css',
            [],
            KM_SALLE_ECOUTE_VERSION
        );
    }

}
