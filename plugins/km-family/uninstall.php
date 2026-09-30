<?php
/**
 * Désinstallation du plugin KM Family v2.1
 *
 * @package KMFamily
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Options du plugin
// CORRECTIF v3.3.1 : cette liste datait de la v2.1 et ne couvrait plus qu'une partie des
// options réellement créées depuis. Une désinstallation laissait donc derrière elle les
// versions de schéma de toutes les tables (kmfamily_*_db_version) — au point qu'une
// RÉINSTALLATION ultérieure considérait les tables comme déjà à jour et ne les recréait
// jamais : le plugin repartait sur des tables inexistantes, avec des erreurs SQL à chaque
// commande. Les clés secrètes de signature restaient elles aussi en base.
$options = array(
    'kmfamily_settings',
    'kmfamily_page_dashboard',
    'kmfamily_page_profile',
    'kmfamily_page_paliers',
    'kmfamily_page_login',
    'kmfamily_page_payment',
    'kmfamily_page_payment_success',
    'kmfamily_default_terms_inserted',
    'kmfamily_default_terms_v2_inserted',
    'kmfamily_media_secret',
    'kmfamily_admin_notifications',
    'kmfamily_direct_links_backfill',
    'kmfamily_direct_link_rewrite',

    // Versions de schéma — indispensables, sinon les tables ne seront pas recréées.
    'kmfamily_orders_db_version',
    'kmfamily_media_sessions_db_version',
    'kmfamily_rewards_db_version',
    'kmfamily_playlists_db_version',
    'kmfamily_playback_db_version',
    'kmfamily_memberships_db_version',
    'kmfamily_revenue_db_version',
    'kmfamily_loyalty_db_version',
    'kmfamily_payment_tracking_db_version',
    'kmfamily_security_log_db_version',
    'kmfamily_used_tokens_db_version',

    // Secrets et états divers.
    'kmfamily_token_secret',
    'kmfamily_media_htaccess_ineffective',
    'kmfamily_rewrite_version',
    'kmfamily_astra_meta_applied',
    'kmfamily_memberships_migrated',
    'kmfamily_libre_proportional',
    'kmfamily_max_concurrent_streams',
    'kmfamily_media_session_ttl',
    'kmfamily_media_session_idle',
);

foreach ( $options as $option ) {
    delete_option( $option );
    delete_site_option( $option );
}

// Meta utilisateurs — désactivé par défaut pour conserver les abonnements et profils
// Décommenter pour nettoyage total
/*
global $wpdb;
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s, %s, %s, %s, %s)",
    'kmfamily_memberships',
    'kmfamily_memberships_history',
    'kmfamily_badge',
    'kmfamily_avatar_id',
    'kmfamily_avatar_url',
    'kmfamily_phone',
    'kmfamily_city'
) );
*/

// Retirer rôle + capability
$role = get_role( 'administrator' );
if ( $role ) {
    $role->remove_cap( 'kmfamily_access_exclusive' );
}
remove_role( 'kmfamily_member' );

// Tables personnalisées — supprimées UNIQUEMENT si l'administrateur l'a explicitement
// demandé, en ajoutant dans wp-config.php :  define( 'KMFAMILY_REMOVE_ALL_DATA', true );
// Par défaut on les conserve : elles contiennent l'historique des paiements, les adhésions
// et le journal de sécurité, qu'une désinstallation accidentelle ne doit pas détruire.
if ( defined( 'KMFAMILY_REMOVE_ALL_DATA' ) && KMFAMILY_REMOVE_ALL_DATA ) {
    global $wpdb;
    $tables = array(
        $wpdb->prefix . 'kmfamily_orders',
        $wpdb->prefix . 'kmfamily_media_sessions',
        $wpdb->prefix . 'kmfamily_memberships',
        $wpdb->prefix . 'kmfamily_artist_revenue',
        $wpdb->prefix . 'kmfamily_playback',
        $wpdb->prefix . 'kmfamily_playlist_items',
        $wpdb->prefix . 'kmfamily_playlists',
        $wpdb->prefix . 'kmfamily_redemptions',
        $wpdb->prefix . 'kmfamily_rewards',
        $wpdb->prefix . 'kmfamily_loyalty_ledger',
        $wpdb->prefix . 'kmfamily_security_log',
        $wpdb->prefix . 'kmfamily_used_tokens',
        $wpdb->prefix . 'km_payment_tracking',
    );
    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
    }
}

// Cron
wp_clear_scheduled_hook( 'kmfamily_daily_subscription_check' );
