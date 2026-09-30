<?php
/**
 * Panneau d'administration KM Family v2
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Admin {

    public static function init() {
        add_action( 'admin_menu',                          array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init',                          array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts',               array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_kmfamily_manual_activate', array( __CLASS__, 'handle_manual_activate' ) );
        add_action( 'admin_post_kmfamily_cleanup_pages',   array( __CLASS__, 'handle_cleanup_pages' ) );
        add_action( 'admin_notices',                       array( __CLASS__, 'welcome_notice' ) );

        // Afficher le palier dans la liste des utilisateurs
        add_filter( 'manage_users_columns',        array( __CLASS__, 'add_user_column' ) );
        add_filter( 'manage_users_custom_column',  array( __CLASS__, 'render_user_column' ), 10, 3 );
    }

    public static function register_menu() {
        // Badge (bulle rouge, comme "Commentaires") sur le menu et le sous-menu
        // Notifications : signal supplémentaire, indépendant de l'email et de la
        // barre d'outils, pour qu'une déclaration de paiement à vérifier ne passe
        // pas inaperçue.
        $unread_count = 0;
        $notifs = get_option( 'kmfamily_admin_notifications', array() );
        if ( $notifs ) {
            $unread_count = count( array_filter( $notifs, function( $n ) { return empty( $n['read'] ); } ) );
        }
        $badge = $unread_count ? sprintf( ' <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>', $unread_count ) : '';

        add_menu_page(
            __( 'KM Family', 'km-family' ) . $badge,
            __( 'KM Family', 'km-family' ) . $badge,
            'manage_options',
            'km-family',
            array( __CLASS__, 'render_dashboard_page' ),
            'dashicons-groups',
            4
        );

        add_submenu_page( 'km-family', __( 'Tableau de bord', 'km-family' ), __( 'Tableau de bord', 'km-family' ), 'manage_options', 'km-family', array( __CLASS__, 'render_dashboard_page' ) );
        add_submenu_page( 'km-family', __( 'Abonnements', 'km-family' ), __( 'Abonnements', 'km-family' ), 'manage_options', 'kmfamily-subscriptions', array( __CLASS__, 'render_subscriptions_page' ) );
        add_submenu_page( 'km-family', __( 'Notifications', 'km-family' ), __( 'Notifications', 'km-family' ) . $badge, 'manage_options', 'kmfamily-notifications', array( __CLASS__, 'render_notifications_page' ) );
        add_submenu_page( 'km-family', __( 'Réglages', 'km-family' ), __( 'Réglages', 'km-family' ), 'manage_options', 'kmfamily-settings', array( __CLASS__, 'render_settings_page' ) );
    }

    public static function register_settings() {
        register_setting( 'kmfamily_settings_group', 'kmfamily_settings', array(
            'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
        ) );
    }

    public static function sanitize_settings( $input ) {
        // Si l'input n'est pas un tableau (peut arriver avec certains WAF), retourner les anciennes valeurs
        if ( ! is_array( $input ) ) {
            $existing = get_option( 'kmfamily_settings', array() );
            return is_array( $existing ) ? $existing : array();
        }

        try {
            $clean = array(
                'currency'           => isset( $input['currency'] )           ? sanitize_text_field( (string) $input['currency'] )         : 'XOF',
                'notification_email' => isset( $input['notification_email'] ) && is_email( $input['notification_email'] ) ? sanitize_email( $input['notification_email'] ) : '',
                'payment_gateway'    => isset( $input['payment_gateway'] )    ? sanitize_key( (string) $input['payment_gateway'] )         : 'none',
                'subscription_days'  => isset( $input['subscription_days'] )  ? absint( $input['subscription_days'] )                       : 30,
                'test_mode'          => ! empty( $input['test_mode'] ) ? 1 : 0,
                'brand_color'        => isset( $input['brand_color'] )        ? ( sanitize_hex_color( (string) $input['brand_color'] ) ?: '#d4af37' ) : '#d4af37',
                'cinetpay_apikey'    => isset( $input['cinetpay_apikey'] )    ? sanitize_text_field( (string) $input['cinetpay_apikey'] )   : '',
                'cinetpay_siteid'    => isset( $input['cinetpay_siteid'] )    ? sanitize_text_field( (string) $input['cinetpay_siteid'] )   : '',
                'cinetpay_secret'    => isset( $input['cinetpay_secret'] )    ? sanitize_text_field( (string) $input['cinetpay_secret'] )   : '',
                'paystack_pubkey'    => isset( $input['paystack_pubkey'] )    ? sanitize_text_field( (string) $input['paystack_pubkey'] )   : '',
                'paystack_seckey'    => isset( $input['paystack_seckey'] )    ? sanitize_text_field( (string) $input['paystack_seckey'] )   : '',
                // Smart Payment Center — liens marchands manuels (Wave, Orange Money, MTN Money, Moov Money)
                'wave_merchant_link'    => isset( $input['wave_merchant_link'] )    ? esc_url_raw( (string) $input['wave_merchant_link'] )    : '',
                'orange_money_link'     => isset( $input['orange_money_link'] )     ? esc_url_raw( (string) $input['orange_money_link'] )     : '',
                'mtn_money_link'        => isset( $input['mtn_money_link'] )        ? esc_url_raw( (string) $input['mtn_money_link'] )        : '',
                'moov_money_link'       => isset( $input['moov_money_link'] )       ? esc_url_raw( (string) $input['moov_money_link'] )       : '',
                // Réductions par périodicité (en %, bornées 0-90)
                'discount_quarterly' => isset( $input['discount_quarterly'] ) ? max( 0, min( 90, absint( $input['discount_quarterly'] ) ) ) : 10,
                'discount_biannual'  => isset( $input['discount_biannual'] )  ? max( 0, min( 90, absint( $input['discount_biannual'] ) ) )  : 15,
                'discount_annual'    => isset( $input['discount_annual'] )    ? max( 0, min( 90, absint( $input['discount_annual'] ) ) )    : 20,
                'enable_periodicity' => ! empty( $input['enable_periodicity'] ) ? 1 : 0,
            );
            return $clean;
        } catch ( \Throwable $e ) {
            // Log l'erreur dans un fichier visible
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                @file_put_contents(
                    WP_CONTENT_DIR . '/kmfamily-settings-error.log',
                    '[' . date( 'Y-m-d H:i:s' ) . '] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n",
                    FILE_APPEND
                );
            }
            // Retourner les valeurs précédentes pour ne pas tout casser
            $existing = get_option( 'kmfamily_settings', array() );
            return is_array( $existing ) ? $existing : array();
        }
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'km-family' ) === false && strpos( $hook, 'kmfamily' ) === false ) return;
        wp_enqueue_style( 'kmfamily-admin', KMFAMILY_URL . 'assets/css/km-family-admin.css', array(), KMFAMILY_VERSION );
    }

    public static function render_dashboard_page() {
        $artistes = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1>
                <span class="kmfamily-admin-logo">👥</span>
                <?php esc_html_e( 'KM Family — Tableau de bord', 'km-family' ); ?>
                <span class="kmfamily-admin-version">v<?php echo esc_html( KMFAMILY_VERSION ); ?></span>
            </h1>

            <p class="kmfamily-admin-intro">
                <?php esc_html_e( 'Vue d\'ensemble de votre communauté KM Family.', 'km-family' ); ?>
            </p>

            <div class="kmfamily-admin-stats-grid">
                <?php
                $total = 0;
                foreach ( $artistes as $artiste ) :
                    $count = KMFamily_Subscriptions::count_members_for_artist( $artiste->ID );
                    $total += $count;
                ?>
                    <div class="kmfamily-admin-stat-card">
                        <h3><?php echo esc_html( $artiste->post_title ); ?></h3>
                        <div class="kmfamily-admin-stat-number"><?php echo esc_html( $count ); ?></div>
                        <div class="kmfamily-admin-stat-label"><?php esc_html_e( 'membres actifs', 'km-family' ); ?></div>
                        <a href="<?php echo esc_url( get_edit_post_link( $artiste->ID ) ); ?>" class="button">
                            <?php esc_html_e( 'Configurer', 'km-family' ); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $artistes ) ) : ?>
                <div class="kmfamily-admin-total">
                    <strong><?php echo esc_html( $total ); ?></strong>
                    <?php esc_html_e( 'membres actifs au total dans la KM Family', 'km-family' ); ?>
                </div>
            <?php endif; ?>

            <div class="kmfamily-admin-section">
                <h2><?php esc_html_e( 'Les 5 paliers standardisés', 'km-family' ); ?></h2>
                <table class="widefat striped kmfamily-paliers-preview">
                    <thead>
                        <tr>
                            <th>Niveau</th>
                            <th>Nom</th>
                            <th>Prix</th>
                            <th>Statut</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( KMFamily_Paliers::get_all() as $p ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $p['numero'] ); ?></strong></td>
                                <td>
                                    <span class="kmfamily-admin-palier-badge" style="background: <?php echo esc_attr( $p['gradient'] ); ?>">
                                        <?php echo esc_html( $p['icon'] . ' ' . $p['nom'] ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( number_format( $p['prix'], 0, ',', ' ' ) ); ?> FCFA</td>
                                <td><em><?php echo esc_html( $p['statut'] ); ?></em></td>
                                <td><?php echo esc_html( $p['description'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="kmfamily-admin-section">
                <h2><?php esc_html_e( 'Shortcodes disponibles', 'km-family' ); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr><th>Shortcode</th><th>Description</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>[kmfamily_login]</code></td><td>Page de connexion / inscription moderne</td></tr>
                        <tr><td><code>[kmfamily_paliers artiste_id="X"]</code></td><td>Paliers d'un artiste spécifique</td></tr>
                        <tr><td><code>[kmfamily_paliers_all]</code></td><td>Tous les artistes et tableau des paliers</td></tr>
                        <tr><td><code>[kmfamily_dashboard]</code></td><td>Espace membre (tabs, badges, changement mdp)</td></tr>
                        <tr><td><code>[kmfamily_exclusive_feed artiste_id="X" limit="6"]</code></td><td>Contenus exclusifs d'un artiste</td></tr>
                        <tr><td><code>[kmfamily_badge user_id="X"]</code></td><td>Badge d'un membre</td></tr>
                        <tr><td><code>[kmfamily_emotional_message]</code></td><td>Le message émotionnel KM Family</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public static function render_subscriptions_page() {
        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            KMFamily_Subscriptions::META_KEY
        ) );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'Abonnements KM Family', 'km-family' ); ?></h1>

            <?php if ( isset( $_GET['kmfamily_activated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Abonnement activé avec succès. Notification envoyée au membre.', 'km-family' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['kmfamily_order_confirmed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Commande confirmée — abonnement activé et membre notifié.', 'km-family' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['kmfamily_order_rejected'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Commande rejetée — le membre a été notifié.', 'km-family' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['kmfamily_order_error'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( "Impossible de traiter cette commande (introuvable ou déjà traitée).", 'km-family' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['kmfamily_order_risk_blocked'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p>🛡️ <?php esc_html_e( "Confirmation bloquée : cette commande a un score de risque critique. Coche la case de confirmation dans le tableau pour passer outre en connaissance de cause.", 'km-family' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['kmfamily_order_duplicate'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p>🧾 <?php
                    esc_html_e( "Confirmation bloquée : la référence de transaction de cette commande est DÉJÀ rattachée à une autre commande encore en cours ou déjà activée. Un même reçu Mobile Money ne peut pas couvrir deux commandes — vérifiez les deux dans l'application du moyen de paiement avant de décider. Pour confirmer malgré tout, cochez la case de confirmation dans le tableau.", 'km-family' );
                ?></p></div>
            <?php endif; ?>

            <?php if ( class_exists( 'KMFamily_Orders' ) ) :
                $pending_orders = KMFamily_Orders::get_pending_manual_orders();
            ?>
            <div class="kmfamily-admin-section">
                <h2>⏳ <?php esc_html_e( 'Smart Payment Center — Commandes en attente de confirmation', 'km-family' ); ?></h2>
                <p class="description"><?php esc_html_e( "Paiements déclarés par les adhérents via un moyen manuel (Wave, Orange Money, MTN, Moov). Vérifie la référence dans ton app avant de confirmer.", 'km-family' ); ?></p>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Risque', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Commande', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Membre', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Artiste / Palier', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Moyen', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Montant', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Référence déclarée', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Déclaré le', 'km-family' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $pending_orders ) ) : ?>
                        <tr><td colspan="9"><?php esc_html_e( 'Aucune commande en attente. 🎉', 'km-family' ); ?></td></tr>
                    <?php else : foreach ( $pending_orders as $o ) :
                        $is_ticket = class_exists( 'KMFamily_Orders' ) && KMFamily_Orders::is_ticket_order( $o );
                        $o_meta    = json_decode( $o['meta'] ?? '', true );
                        $o_meta    = is_array( $o_meta ) ? $o_meta : array();
                        $u         = $o['user_id'] ? get_userdata( $o['user_id'] ) : null;
                        $p         = $is_ticket ? null : KMFamily_Paliers::get( $o['palier'] );
                        $method    = KMFamily_Payment_Methods::get( $o['gateway'] );
                        $risk      = class_exists( 'KMFamily_Risk_Engine' ) ? KMFamily_Risk_Engine::compute_score( $o ) : null;
                    ?>
                        <tr>
                            <td>
                                <?php if ( $risk ) : ?>
                                    <strong><?php echo esc_html( KMFamily_Risk_Engine::level_label( $risk['level'] ) ); ?></strong>
                                    <?php if ( ! empty( $risk['reasons'] ) ) : ?>
                                        <br><span class="description" title="<?php echo esc_attr( implode( ' · ', $risk['reasons'] ) ); ?>"><?php echo esc_html( $risk['score'] ); ?>/100</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html( $o['order_ref'] ); ?></code><?php if ( $is_ticket ) : ?><br><span class="description">🎟️ <?php esc_html_e( 'Billet', 'km-family' ); ?></span><?php endif; ?></td>
                            <td>
                                <?php if ( $is_ticket ) : ?>
                                    <?php echo esc_html( trim( ( $o_meta['prenom'] ?? '' ) . ' ' . ( $o_meta['nom'] ?? '' ) ) ); ?>
                                    <?php if ( ! empty( $o_meta['email'] ) ) : ?><br><span class="description"><?php echo esc_html( $o_meta['email'] ); ?></span><?php endif; ?>
                                    <?php if ( ! $o['user_id'] ) : ?><br><span class="description">👤 <?php esc_html_e( 'Invité', 'km-family' ); ?></span><?php endif; ?>
                                <?php else : ?>
                                    <?php echo esc_html( $u ? ( $u->display_name . ' (' . $u->user_email . ')' ) : '#' . $o['user_id'] ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $is_ticket ) : ?>
                                    <?php echo esc_html( get_the_title( $o['artiste_id'] ) . ' — ' . ( $o_meta['ticket_type_nom'] ?? $o['palier'] ) . ' ×' . ( $o_meta['quantite'] ?? 1 ) ); ?>
                                <?php else : ?>
                                    <?php echo esc_html( get_the_title( $o['artiste_id'] ) . ' — ' . ( $p['nom'] ?? $o['palier'] ) ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( ( $method['icon'] ?? '' ) . ' ' . ( $method['label'] ?? $o['gateway'] ) ); ?></td>
                            <td><?php echo esc_html( number_format( $o['montant'], 0, ',', ' ' ) ); ?> <?php echo esc_html( $o['currency'] ); ?></td>
                            <td>
                                <?php echo esc_html( $o['proof_reference'] ); ?>
                                <?php if ( ! empty( $o['proof_note'] ) ) : ?>
                                    <br><span class="description"><?php echo esc_html( $o['proof_note'] ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html( $o['declared_at'] ? date_i18n( 'd/m/Y H:i', strtotime( $o['declared_at'] ) ) : '-' ); ?>
                                <?php if ( ! empty( $o['initiated_at'] ) && ! empty( $o['declared_at'] ) ) :
                                    $delay = strtotime( $o['declared_at'] ) - strtotime( $o['initiated_at'] );
                                ?>
                                    <br><span class="description" style="<?php echo $delay < 30 ? 'color:#b32d2e;font-weight:600' : ''; ?>">
                                        <?php echo $delay < 30 ? '⚠️ ' : '⏱ '; ?>
                                        <?php printf( esc_html__( '%ds après le clic paiement', 'km-family' ), max( 0, $delay ) ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="kmfamily_confirm_order">
                                    <input type="hidden" name="order_ref" value="<?php echo esc_attr( $o['order_ref'] ); ?>">
                                    <?php wp_nonce_field( 'kmfamily_confirm_order', 'kmfamily_nonce' ); ?>
                                    <?php if ( $risk && $risk['level'] === KMFamily_Risk_Engine::LEVEL_CRITICAL ) : ?>
                                        <label style="display:block;font-size:11px;color:#b32d2e;font-weight:600;max-width:180px;margin-bottom:4px;">
                                            <input type="checkbox" name="override_risk" value="1" required>
                                            <?php esc_html_e( "Je confirme malgré l'alerte de risque critique", 'km-family' ); ?>
                                        </label>
                                    <?php endif; ?>
                                    <button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Confirmer ce paiement et activer l\'abonnement ?', 'km-family' ) ); ?>');">
                                        ✅ <?php esc_html_e( 'Confirmer', 'km-family' ); ?>
                                    </button>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="kmfamily_reject_order">
                                    <input type="hidden" name="order_ref" value="<?php echo esc_attr( $o['order_ref'] ); ?>">
                                    <?php wp_nonce_field( 'kmfamily_reject_order', 'kmfamily_nonce' ); ?>
                                    <input type="hidden" name="admin_note" class="kmfamily-reject-note" value="">
                                    <button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e" onclick="var r=prompt('<?php echo esc_js( __( 'Motif du rejet (visible par le membre, optionnel) :', 'km-family' ) ); ?>'); if(r===null) return false; this.form.querySelector('.kmfamily-reject-note').value = r;">
                                        ❌ <?php esc_html_e( 'Rejeter', 'km-family' ); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="kmfamily-admin-section">
                <h2><?php esc_html_e( 'Activation manuelle', 'km-family' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Offrir un abonnement à un membre ou tester le système. Un email de bienvenue sera automatiquement envoyé.', 'km-family' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="kmfamily_manual_activate">
                    <?php wp_nonce_field( 'kmfamily_manual_activate', 'kmfamily_nonce' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="user_email"><?php esc_html_e( 'Email du membre', 'km-family' ); ?></label></th>
                            <td><input type="email" name="user_email" id="user_email" required class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="artiste_id"><?php esc_html_e( 'Artiste', 'km-family' ); ?></label></th>
                            <td>
                                <select name="artiste_id" id="artiste_id" required>
                                    <?php
                                    $artistes = get_posts( array( 'post_type' => 'nos-artistes', 'posts_per_page' => -1 ) );
                                    foreach ( $artistes as $a ) {
                                        printf( '<option value="%d">%s</option>', $a->ID, esc_html( $a->post_title ) );
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="palier"><?php esc_html_e( 'Palier', 'km-family' ); ?></label></th>
                            <td>
                                <select name="palier" id="palier">
                                    <?php foreach ( KMFamily_Paliers::get_all() as $p ) : ?>
                                        <option value="<?php echo esc_attr( $p['slug'] ); ?>">
                                            <?php echo esc_html( $p['icon'] . ' ' . $p['nom'] . ' — ' . $p['statut'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="duree_jours"><?php esc_html_e( 'Durée (jours)', 'km-family' ); ?></label></th>
                            <td><input type="number" name="duree_jours" id="duree_jours" value="30" min="1" max="365"></td>
                        </tr>
                        <tr>
                            <th><label for="montant"><?php esc_html_e( 'Montant (FCFA)', 'km-family' ); ?></label></th>
                            <td>
                                <input type="number" name="montant" id="montant" value="0" min="0" step="1">
                                <p class="description"><?php esc_html_e( 'Valeur indicative pour le suivi (aucun paiement réel n\'est déclenché ici). Laisser à 0 pour un don/test gratuit — utile notamment pour le palier "Libre", qui n\'a pas de prix fixe.', 'km-family' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Activer et notifier', 'km-family' ); ?></button></p>
                </form>
            </div>

            <div class="kmfamily-admin-section">
                <h2><?php esc_html_e( 'Abonnements actifs', 'km-family' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Membre</th>
                            <th>Artiste</th>
                            <th>Palier</th>
                            <th>Expire le</th>
                            <th>Montant</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $has_rows = false;
                    foreach ( $results as $row ) :
                        $subs = maybe_unserialize( $row->meta_value );
                        if ( ! is_array( $subs ) ) continue;
                        $user = get_userdata( $row->user_id );
                        if ( ! $user ) continue;

                        foreach ( $subs as $artiste_id => $sub ) :
                            if ( empty( $sub['expire'] ) || $sub['expire'] < time() ) continue;
                            $has_rows = true;
                            $p = KMFamily_Paliers::get( $sub['palier'] );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></td>
                            <td><?php echo esc_html( get_the_title( $artiste_id ) ); ?></td>
                            <td>
                                <?php if ( $p ) : ?>
                                    <span class="kmfamily-admin-palier-badge" style="background: <?php echo esc_attr( $p['gradient'] ); ?>">
                                        <?php echo esc_html( $p['icon'] . ' ' . $p['nom'] ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( date_i18n( 'd/m/Y', $sub['expire'] ) ); ?></td>
                            <td><?php echo esc_html( number_format( $sub['montant'] ?? 0, 0, ',', ' ' ) ); ?> FCFA</td>
                            <td><?php echo esc_html( $sub['gateway'] ?? '-' ); ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                    <?php if ( ! $has_rows ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'Aucun abonnement actif pour le moment.', 'km-family' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public static function render_notifications_page() {
        $notifs = get_option( 'kmfamily_admin_notifications', array() );
        $notifs = array_reverse( $notifs );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'Historique des notifications', 'km-family' ); ?></h1>

            <div class="kmfamily-admin-section">
                <?php if ( empty( $notifs ) ) : ?>
                    <p><?php esc_html_e( 'Aucune notification pour le moment. Les notifications apparaîtront ici à chaque nouvel abonnement.', 'km-family' ); ?></p>
                <?php else : ?>
                    <ul class="kmfamily-admin-notifs-list">
                        <?php foreach ( array_slice( $notifs, 0, 50 ) as $n ) : ?>
                            <li>
                                <span class="kmfamily-admin-notif-time">
                                    <?php echo esc_html( human_time_diff( $n['time'] ) ); ?> <?php esc_html_e( 'ago', 'km-family' ); ?>
                                </span>
                                <span class="kmfamily-admin-notif-msg"><?php echo wp_kses_post( $n['message'] ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function render_settings_page() {
        $settings = get_option( 'kmfamily_settings', array() );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'Réglages KM Family', 'km-family' ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'kmfamily_settings_group' ); ?>

                <div class="kmfamily-admin-section">
                    <h2><?php esc_html_e( 'Général', 'km-family' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label><?php esc_html_e( 'Email de notification', 'km-family' ); ?></label></th>
                            <td>
                                <input type="email" name="kmfamily_settings[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ?? '' ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e( 'Adresse qui reçoit les alertes KM Family (nouveau membre, paiement à confirmer...). Laissez vide pour utiliser l\'adresse e-mail d\'administration générale de WordPress — souvent une adresse oubliée, différente du compte réellement surveillé.', 'km-family' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Devise', 'km-family' ); ?></label></th>
                            <td>
                                <select name="kmfamily_settings[currency]">
                                    <option value="XOF" <?php selected( $settings['currency'] ?? 'XOF', 'XOF' ); ?>>XOF (Franc CFA)</option>
                                    <option value="EUR" <?php selected( $settings['currency'] ?? '', 'EUR' ); ?>>EUR</option>
                                    <option value="USD" <?php selected( $settings['currency'] ?? '', 'USD' ); ?>>USD</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Durée abonnement par défaut (jours)', 'km-family' ); ?></label></th>
                            <td><input type="number" name="kmfamily_settings[subscription_days]" value="<?php echo esc_attr( $settings['subscription_days'] ?? 30 ); ?>" min="1"></td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Couleur de marque', 'km-family' ); ?></label></th>
                            <td><input type="color" name="kmfamily_settings[brand_color]" value="<?php echo esc_attr( $settings['brand_color'] ?? '#d4af37' ); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Mode test', 'km-family' ); ?></label></th>
                            <td>
                                <label><input type="checkbox" name="kmfamily_settings[test_mode]" value="1" <?php checked( $settings['test_mode'] ?? 0, 1 ); ?>>
                                <?php esc_html_e( 'Activer le mode test (sandbox)', 'km-family' ); ?></label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="kmfamily-admin-section">
                    <h2><?php esc_html_e( 'Passerelle de paiement', 'km-family' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label><?php esc_html_e( 'Passerelle active', 'km-family' ); ?></label></th>
                            <td>
                                <select name="kmfamily_settings[payment_gateway]">
                                    <option value="none" <?php selected( $settings['payment_gateway'] ?? 'none', 'none' ); ?>>— <?php esc_html_e( 'Aucune (activation manuelle)', 'km-family' ); ?> —</option>
                                    <option value="cinetpay" <?php selected( $settings['payment_gateway'] ?? '', 'cinetpay' ); ?>>CinetPay (recommandé)</option>
                                    <option value="paystack" <?php selected( $settings['payment_gateway'] ?? '', 'paystack' ); ?>>Paystack</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <h3>CinetPay</h3>
                    <table class="form-table">
                        <tr><th><label>API Key</label></th><td><input type="text" name="kmfamily_settings[cinetpay_apikey]" value="<?php echo esc_attr( $settings['cinetpay_apikey'] ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th><label>Site ID</label></th><td><input type="text" name="kmfamily_settings[cinetpay_siteid]" value="<?php echo esc_attr( $settings['cinetpay_siteid'] ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th><label>Secret Key</label></th><td><input type="password" name="kmfamily_settings[cinetpay_secret]" value="<?php echo esc_attr( $settings['cinetpay_secret'] ?? '' ); ?>" class="regular-text"></td></tr>
                    </table>

                    <h3>Paystack</h3>
                    <table class="form-table">
                        <tr><th><label>Public Key</label></th><td><input type="text" name="kmfamily_settings[paystack_pubkey]" value="<?php echo esc_attr( $settings['paystack_pubkey'] ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th><label>Secret Key</label></th><td><input type="password" name="kmfamily_settings[paystack_seckey]" value="<?php echo esc_attr( $settings['paystack_seckey'] ?? '' ); ?>" class="regular-text"></td></tr>
                    </table>
                </div>

                <div class="kmfamily-admin-section">
                    <h2>📲 <?php esc_html_e( 'Smart Payment Center — Mobile Money manuel', 'km-family' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( "Colle ici tes liens marchands. Ils apparaîtront automatiquement dans le Centre de Paiement (QR code + bouton + copie du lien). Ces paiements sont validés manuellement (l'adhérent déclare sa transaction, tu confirmes en un clic dans « Abonnements »), en attendant une intégration API directe.", 'km-family' ); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><label>🌊 Wave</label></th>
                            <td>
                                <input type="url" name="kmfamily_settings[wave_merchant_link]" value="<?php echo esc_attr( $settings['wave_merchant_link'] ?? '' ); ?>" class="regular-text" placeholder="https://pay.wave.com/m/M_ci_XXXXXXXX/c/ci/">
                                <p class="description"><?php esc_html_e( "Lien marchand Wave (Business > Recevoir des paiements). Le montant n'est pas préempli par ce type de lien : l'adhérent le saisit lui-même, la page de paiement le lui affiche clairement.", 'km-family' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>🟠 Orange Money</label></th>
                            <td><input type="url" name="kmfamily_settings[orange_money_link]" value="<?php echo esc_attr( $settings['orange_money_link'] ?? '' ); ?>" class="regular-text" placeholder="https://..."></td>
                        </tr>
                        <tr>
                            <th><label>🟡 MTN Money</label></th>
                            <td><input type="url" name="kmfamily_settings[mtn_money_link]" value="<?php echo esc_attr( $settings['mtn_money_link'] ?? '' ); ?>" class="regular-text" placeholder="https://..."></td>
                        </tr>
                        <tr>
                            <th><label>🔵 Moov Money</label></th>
                            <td><input type="url" name="kmfamily_settings[moov_money_link]" value="<?php echo esc_attr( $settings['moov_money_link'] ?? '' ); ?>" class="regular-text" placeholder="https://..."></td>
                        </tr>
                    </table>
                    <p class="description">
                        💡 <?php esc_html_e( 'Si CinetPay est configuré comme passerelle active, Orange Money / MTN Money / Moov Money sont déjà couverts automatiquement via son canal Mobile Money — les liens manuels ci-dessus ne sont utiles que comme solution de secours ou en attendant CinetPay.', 'km-family' ); ?>
                    </p>
                </div>

                <div class="kmfamily-admin-section">
                    <h2><?php esc_html_e( 'URLs à configurer chez la passerelle', 'km-family' ); ?></h2>
                    <ul class="kmfamily-admin-urls">
                        <li><strong>Webhook CinetPay</strong><br><code><?php echo esc_url( home_url( '/wp-json/kmfamily/v1/webhook/cinetpay' ) ); ?></code></li>
                        <li><strong>Webhook Paystack</strong><br><code><?php echo esc_url( home_url( '/wp-json/kmfamily/v1/webhook/paystack' ) ); ?></code></li>
                        <li><strong>Return URL</strong><br><code><?php echo esc_url( home_url( '/paiement-reussi/' ) ); ?></code></li>
                    </ul>
                </div>

                <div class="kmfamily-admin-section">
                    <h2>🔐 <?php esc_html_e( 'Sécurité des médias', 'km-family' ); ?></h2>
                    <p><?php esc_html_e( 'Tous les fichiers médias uploadés pour les contenus exclusifs sont automatiquement protégés via :', 'km-family' ); ?></p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><?php esc_html_e( 'Stockage dans un dossier sécurisé (/wp-content/uploads/km-family-protected/)', 'km-family' ); ?></li>
                        <li><?php esc_html_e( 'Fichier .htaccess bloquant tout accès HTTP direct', 'km-family' ); ?></li>
                        <li><?php esc_html_e( 'URLs signées via token HMAC à durée limitée (1h)', 'km-family' ); ?></li>
                        <li><?php esc_html_e( 'Vérification en temps réel des droits avant streaming', 'km-family' ); ?></li>
                        <li><?php esc_html_e( 'Support streaming HTTP Range (lecture/seek)', 'km-family' ); ?></li>
                    </ul>
                    <?php
                    $upload_dir = wp_upload_dir();
                    $protected  = trailingslashit( $upload_dir['basedir'] ) . 'km-family-protected';
                    $status     = file_exists( $protected ) && file_exists( $protected . '/.htaccess' );
                    ?>
                    <p>
                        <?php if ( $status ) : ?>
                            <span style="color: #4a9c6c;">✓ Le dossier sécurisé est correctement configuré.</span>
                        <?php else : ?>
                            <span style="color: #d63638;">⚠ Le dossier sécurisé n'existe pas. Désactivez/réactivez le plugin pour le créer.</span>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="kmfamily-admin-section">
                    <h2>📅 <?php esc_html_e( 'Périodicité & Réductions', 'km-family' ); ?></h2>
                    <p><?php esc_html_e( 'Offrez des réductions pour les engagements longs. Les paliers proposeront mensuel / trimestriel / semestriel / annuel avec le pourcentage de réduction correspondant.', 'km-family' ); ?></p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="enable_periodicity"><?php esc_html_e( 'Activer la périodicité', 'km-family' ); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="kmfamily_settings[enable_periodicity]" id="enable_periodicity" value="1" <?php checked( ! empty( $settings['enable_periodicity'] ) ); ?>>
                                    <?php esc_html_e( 'Afficher les options trimestrielle, semestrielle et annuelle sur les pages de paliers', 'km-family' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="discount_quarterly"><?php esc_html_e( 'Réduction trimestrielle (3 mois)', 'km-family' ); ?></label>
                            </th>
                            <td>
                                <input type="number" name="kmfamily_settings[discount_quarterly]" id="discount_quarterly" min="0" max="90" value="<?php echo esc_attr( $settings['discount_quarterly'] ?? 10 ); ?>" style="width:80px;"> %
                                <p class="description"><?php esc_html_e( 'Exemple : 10% → 3 mois payés à 90% du prix total', 'km-family' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="discount_biannual"><?php esc_html_e( 'Réduction semestrielle (6 mois)', 'km-family' ); ?></label>
                            </th>
                            <td>
                                <input type="number" name="kmfamily_settings[discount_biannual]" id="discount_biannual" min="0" max="90" value="<?php echo esc_attr( $settings['discount_biannual'] ?? 15 ); ?>" style="width:80px;"> %
                                <p class="description"><?php esc_html_e( 'Exemple : 15% → 6 mois payés à 85% du prix total', 'km-family' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="discount_annual"><?php esc_html_e( 'Réduction annuelle (12 mois)', 'km-family' ); ?></label>
                            </th>
                            <td>
                                <input type="number" name="kmfamily_settings[discount_annual]" id="discount_annual" min="0" max="90" value="<?php echo esc_attr( $settings['discount_annual'] ?? 20 ); ?>" style="width:80px;"> %
                                <p class="description"><?php esc_html_e( 'Exemple : 20% → 12 mois payés à 80% du prix total (2,4 mois offerts)', 'km-family' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php
                    // Aperçu du calcul — avec casts explicites pour PHP 8
                    $q = (int) ( $settings['discount_quarterly'] ?? 10 );
                    $s = (int) ( $settings['discount_biannual']  ?? 15 );
                    $a = (int) ( $settings['discount_annual']    ?? 20 );

                    $trim_total = (int) round( 5000 * 3 * ( 100 - $q ) / 100 );
                    $sem_total  = (int) round( 5000 * 6 * ( 100 - $s ) / 100 );
                    $an_total   = (int) round( 5000 * 12 * ( 100 - $a ) / 100 );
                    ?>
                    <div style="background:#f7f7f7;padding:14px 18px;border-left:4px solid #d4af37;border-radius:4px;margin-top:14px;">
                        <strong><?php esc_html_e( 'Aperçu sur un palier à 5 000 FCFA/mois :', 'km-family' ); ?></strong>
                        <ul style="margin:10px 0 0;">
                            <li>Mensuel : <strong>5 000 FCFA</strong></li>
                            <li>Trimestriel : <strong><?php echo esc_html( number_format( $trim_total, 0, ',', ' ' ) ); ?> FCFA</strong> <small>(au lieu de 15 000 — économie de <?php echo esc_html( number_format( 15000 - $trim_total, 0, ',', ' ' ) ); ?> FCFA)</small></li>
                            <li>Semestriel : <strong><?php echo esc_html( number_format( $sem_total, 0, ',', ' ' ) ); ?> FCFA</strong> <small>(au lieu de 30 000 — économie de <?php echo esc_html( number_format( 30000 - $sem_total, 0, ',', ' ' ) ); ?> FCFA)</small></li>
                            <li>Annuel : <strong><?php echo esc_html( number_format( $an_total, 0, ',', ' ' ) ); ?> FCFA</strong> <small>(au lieu de 60 000 — économie de <?php echo esc_html( number_format( 60000 - $an_total, 0, ',', ' ' ) ); ?> FCFA)</small></li>
                        </ul>
                    </div>
                </div>

                <?php submit_button(); ?>
            </form>

            <div class="kmfamily-admin-section" style="margin-top:30px;padding:20px;background:#fff;border-left:4px solid #d63638;border-radius:4px;">
                <h2>🧹 <?php esc_html_e( 'Maintenance : Nettoyer les pages doublons', 'km-family' ); ?></h2>
                <p><?php esc_html_e( 'Si vous voyez des pages comme "mon-espace-km-family-2" (avec un -2, -3... dans l\'URL), utilisez cet outil pour supprimer les doublons créés par erreur.', 'km-family' ); ?></p>
                <p><strong><?php esc_html_e( 'Les pages référencées officiellement (dans les réglages) seront conservées.', 'km-family' ); ?></strong></p>

                <?php
                // Afficher les pages actuellement référencées
                $pages_config = KMFamily_Install::get_pages_config();
                echo '<div style="background:#f7f7f7;padding:15px;border-radius:4px;margin:15px 0;">';
                echo '<h3 style="margin-top:0;">' . esc_html__( 'Pages KM Family officielles actuellement référencées :', 'km-family' ) . '</h3>';
                echo '<ul>';
                foreach ( $pages_config as $slug => $cfg ) {
                    $page_id = get_option( $cfg['option'] );
                    $page = $page_id ? get_post( $page_id ) : null;
                    if ( $page ) {
                        printf(
                            '<li>✅ <strong>%s</strong> — <a href="%s" target="_blank">%s</a></li>',
                            esc_html( $cfg['title'] ),
                            esc_url( get_permalink( $page->ID ) ),
                            esc_html( get_permalink( $page->ID ) )
                        );
                    } else {
                        printf(
                            '<li>❌ <strong>%s</strong> — <em>%s</em></li>',
                            esc_html( $cfg['title'] ),
                            esc_html__( 'Page manquante, sera recréée au prochain rechargement.', 'km-family' )
                        );
                    }
                }
                echo '</ul></div>';
                ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Voulez-vous vraiment supprimer les pages doublons ? Cette action est irréversible.', 'km-family' ); ?>');">
                    <?php wp_nonce_field( 'kmfamily_cleanup_pages', 'kmfamily_nonce' ); ?>
                    <input type="hidden" name="action" value="kmfamily_cleanup_pages">
                    <button type="submit" class="button button-primary">
                        🗑 <?php esc_html_e( 'Nettoyer les pages doublons', 'km-family' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    public static function handle_cleanup_pages() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_cleanup_pages', 'kmfamily_nonce' ) ) {
            wp_die( 'Accès refusé' );
        }

        $deleted = KMFamily_Install::cleanup_duplicate_pages();
        $count = count( $deleted );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-settings&kmfamily_cleaned=' . $count ) );
        exit;
    }

    public static function handle_manual_activate() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_manual_activate', 'kmfamily_nonce' ) ) {
            wp_die( 'Accès refusé' );
        }

        $user = get_user_by( 'email', sanitize_email( $_POST['user_email'] ?? '' ) );
        if ( ! $user ) {
            wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-subscriptions&kmfamily_error=user_not_found' ) );
            exit;
        }

        $artiste_id = absint( $_POST['artiste_id'] ?? 0 );
        $palier     = sanitize_key( $_POST['palier'] ?? 'bronze' );
        $duree      = absint( $_POST['duree_jours'] ?? 30 );

        // BUGFIX : le montant n'était jamais renseigné ici, affichant "0 FCFA" dans
        // "Abonnements actifs" pour toute activation manuelle — y compris des dons/tests
        // légitimes, ce qui rend le tableau trompeur pour le suivi des revenus.
        // Priorité au montant explicitement saisi dans le formulaire ; à défaut (laissé
        // à 0), on retombe sur le prix officiel du palier (même source que le reste du
        // système) à titre indicatif — sauf pour "Libre", qui n'a pas de prix fixe et
        // reste à 0 si non précisé.
        $montant_saisi = absint( $_POST['montant'] ?? 0 );
        if ( $montant_saisi > 0 ) {
            $montant = $montant_saisi;
        } else {
            $palier_data = class_exists( 'KMFamily_Paliers' ) ? KMFamily_Paliers::get( $palier ) : null;
            $montant     = $palier_data ? (int) ( $palier_data['prix'] ?? 0 ) : 0;
        }

        KMFamily_Subscriptions::activate( $user->ID, $artiste_id, $palier, array(
            'duree_jours' => $duree,
            'gateway'     => 'manual_admin',
            'montant'     => $montant,
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-subscriptions&kmfamily_activated=1' ) );
        exit;
    }

    public static function welcome_notice() {
        if ( isset( $_GET['kmfamily_cleaned'] ) ) {
            $count = absint( $_GET['kmfamily_cleaned'] );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php if ( $count > 0 ) : ?>
                        <strong>🧹 <?php printf( esc_html( _n( '%d page doublon supprimée avec succès.', '%d pages doublons supprimées avec succès.', $count, 'km-family' ) ), $count ); ?></strong>
                    <?php else : ?>
                        <strong>✅ <?php esc_html_e( 'Aucun doublon trouvé. Tout est propre !', 'km-family' ); ?></strong>
                    <?php endif; ?>
                </p>
            </div>
            <?php
        }

        if ( get_transient( 'kmfamily_activated' ) ) {
            delete_transient( 'kmfamily_activated' );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong>🎉 <?php esc_html_e( 'Bienvenue sur KM Family v2.0 !', 'km-family' ); ?></strong>
                    <?php
                    printf(
                        esc_html__( 'Votre plateforme communautaire est prête. Configurez vos réglages dans %s.', 'km-family' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=km-family' ) ) . '">' . esc_html__( 'KM Family → Tableau de bord', 'km-family' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Ajouter une colonne "Badge KM Family" à la liste des utilisateurs.
     */
    public static function add_user_column( $columns ) {
        $columns['kmfamily_badge'] = __( 'Palier KM Family', 'km-family' );
        return $columns;
    }

    public static function render_user_column( $value, $column, $user_id ) {
        if ( $column !== 'kmfamily_badge' ) return $value;

        $badge = KMFamily_Badges::get_user_badge( $user_id );
        if ( ! $badge ) return '—';

        $p = KMFamily_Paliers::get( $badge );
        if ( ! $p ) return '—';

        return sprintf(
            '<span class="kmfamily-admin-palier-badge" style="background: %s">%s %s</span>',
            esc_attr( $p['gradient'] ),
            esc_html( $p['icon'] ),
            esc_html( $p['nom'] )
        );
    }
}
