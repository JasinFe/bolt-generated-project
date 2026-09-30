<?php
/**
 * KM Family — Tableau de bord de sécurité (Smart Payment Center)
 * ===================================================================
 * Vue admin dédiée : intégrité du journal chaîné, commandes à risque élevé,
 * flux d'événements récents. Vient compléter (sans le remplacer) l'écran
 * "Abonnements > Commandes en attente de confirmation", qui reste l'écran
 * d'action (confirmer/rejeter) — celui-ci est l'écran de supervision.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Security_Dashboard {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
    }

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Sécurité Paiement', 'km-family' ),
            __( '🛡️ Sécurité Paiement', 'km-family' ),
            'manage_options',
            'kmfamily-security',
            array( __CLASS__, 'render' )
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $chain          = class_exists( 'KMFamily_Event_Log' ) ? KMFamily_Event_Log::verify_chain() : array( 'intact' => null, 'checked' => 0, 'broken_at' => null );
        $total_events   = class_exists( 'KMFamily_Event_Log' ) ? KMFamily_Event_Log::count_events() : 0;
        $stat_types     = array(
            'qr_generated'    => __( 'QR générés', 'km-family' ),
            'qr_scanned'      => __( 'QR scannés', 'km-family' ),
            'button_clicked'  => __( 'Boutons cliqués', 'km-family' ),
            'redirect_confirmed' => __( 'Redirections confirmées', 'km-family' ),
            'user_returned'   => __( 'Retours détectés', 'km-family' ),
            'payment_declared'=> __( 'Paiements déclarés', 'km-family' ),
        );
        $stats = array();
        foreach ( $stat_types as $type => $label ) {
            $stats[ $type ] = array( 'label' => $label, 'count' => class_exists( 'KMFamily_Event_Log' ) ? KMFamily_Event_Log::count_events( $type ) : 0 );
        }
        $recent_events  = class_exists( 'KMFamily_Event_Log' ) ? KMFamily_Event_Log::get_recent( 30 ) : array();

        // Commandes en attente de confirmation, triées par niveau de risque décroissant.
        $pending = class_exists( 'KMFamily_Orders' ) ? KMFamily_Orders::get_pending_manual_orders( 100 ) : array();
        $scored  = array();
        foreach ( $pending as $o ) {
            $risk = KMFamily_Risk_Engine::compute_score( $o );
            $scored[] = array_merge( $o, array( '_risk' => $risk ) );
        }
        usort( $scored, function( $a, $b ) { return $b['_risk']['score'] <=> $a['_risk']['score']; } );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1>🛡️ <?php esc_html_e( 'Sécurité — Smart Payment Center', 'km-family' ); ?></h1>
            <p class="description"><?php esc_html_e( "Supervision anti-fraude : intégrité du journal, commandes à risque, activité récente. La décision finale (confirmer/rejeter) se fait toujours depuis Abonnements > Commandes en attente de confirmation.", 'km-family' ); ?></p>

            <div class="kmfamily-admin-section" style="display:flex;gap:20px;flex-wrap:wrap;">
                <div style="flex:1;min-width:220px;padding:16px;border:1px solid #dcdcde;border-radius:6px;background:#fff;">
                    <div style="font-size:13px;color:#646970;"><?php esc_html_e( 'Intégrité du journal', 'km-family' ); ?></div>
                    <div style="font-size:22px;font-weight:600;">
                        <?php if ( $chain['intact'] === true ) : ?>
                            ✅ <?php esc_html_e( 'Chaîne intacte', 'km-family' ); ?>
                        <?php elseif ( $chain['intact'] === false ) : ?>
                            ⚠️ <?php printf( esc_html__( 'Rupture détectée (ligne #%d)', 'km-family' ), (int) $chain['broken_at'] ); ?>
                        <?php else : ?>
                            — <?php esc_html_e( 'Aucune donnée', 'km-family' ); ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:#646970;"><?php printf( esc_html__( '%d entrées vérifiées', 'km-family' ), (int) $chain['checked'] ); ?></div>
                </div>
                <div style="flex:1;min-width:220px;padding:16px;border:1px solid #dcdcde;border-radius:6px;background:#fff;">
                    <div style="font-size:13px;color:#646970;"><?php esc_html_e( 'Total événements journalisés', 'km-family' ); ?></div>
                    <div style="font-size:22px;font-weight:600;"><?php echo esc_html( $total_events ); ?></div>
                </div>
            </div>

            <div class="kmfamily-admin-section" style="display:flex;gap:20px;flex-wrap:wrap;margin-top:16px;">
                <?php foreach ( $stats as $s ) : ?>
                <div style="flex:1;min-width:150px;padding:14px;border:1px solid #dcdcde;border-radius:6px;background:#fbfbfc;">
                    <div style="font-size:12px;color:#646970;"><?php echo esc_html( $s['label'] ); ?></div>
                    <div style="font-size:19px;font-weight:600;"><?php echo esc_html( $s['count'] ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="kmfamily-admin-section">
                <h2><?php esc_html_e( 'Commandes en attente — triées par risque', 'km-family' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Risque', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Commande', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Membre', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Montant', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Motifs', 'km-family' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $scored ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'Aucune commande en attente. 🎉', 'km-family' ); ?></td></tr>
                    <?php else : foreach ( $scored as $o ) :
                        $u = get_userdata( $o['user_id'] );
                        $risk = $o['_risk'];
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( KMFamily_Risk_Engine::level_label( $risk['level'] ) ); ?></strong><br><span class="description"><?php echo esc_html( $risk['score'] ); ?>/100</span></td>
                            <td><code><?php echo esc_html( $o['order_ref'] ); ?></code></td>
                            <td><?php echo esc_html( $u ? $u->display_name : '#' . $o['user_id'] ); ?></td>
                            <td><?php echo esc_html( number_format( $o['montant'], 0, ',', ' ' ) . ' ' . $o['currency'] ); ?></td>
                            <td>
                                <?php if ( empty( $risk['reasons'] ) ) : ?>
                                    <span class="description"><?php esc_html_e( 'Aucun signal notable', 'km-family' ); ?></span>
                                <?php else : ?>
                                    <ul style="margin:0;padding-left:18px;">
                                        <?php foreach ( $risk['reasons'] as $r ) : ?><li><?php echo esc_html( $r ); ?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="kmfamily-admin-section">
                <h2><?php esc_html_e( 'Activité récente (journal chaîné)', 'km-family' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Événement', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Commande', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'Acteur', 'km-family' ); ?></th>
                            <th><?php esc_html_e( 'IP', 'km-family' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $recent_events ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'Aucun événement pour le moment.', 'km-family' ); ?></td></tr>
                    <?php else : foreach ( $recent_events as $ev ) : ?>
                        <tr>
                            <td><?php echo esc_html( date_i18n( 'd/m/Y H:i:s', strtotime( $ev['created_at'] ) ) ); ?></td>
                            <td><code><?php echo esc_html( $ev['event_type'] ); ?></code></td>
                            <td><?php echo $ev['order_ref'] ? '<code>' . esc_html( $ev['order_ref'] ) . '</code>' : '—'; ?></td>
                            <td><?php echo esc_html( $ev['actor'] ); ?></td>
                            <td><?php echo esc_html( $ev['ip'] ?: '—' ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
