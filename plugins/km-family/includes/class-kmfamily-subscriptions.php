<?php
/**
 * Gestion des abonnements KM Family v2
 *
 * Stockage : user_meta 'kmfamily_memberships' (globale multisite)
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Subscriptions {

    const META_KEY         = 'kmfamily_memberships';
    const HISTORY_META_KEY = 'kmfamily_memberships_history';

    public static function init() {
        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'check_expirations' ) );

        if ( ! wp_next_scheduled( 'kmfamily_daily_subscription_check' ) ) {
            wp_schedule_event( time(), 'daily', 'kmfamily_daily_subscription_check' );
        }
    }

    public static function get_user_subscriptions( $user_id ) {
        $subs = get_user_meta( $user_id, self::META_KEY, true );
        return is_array( $subs ) ? $subs : array();
    }

    public static function get_user_subscription( $user_id, $artiste_id ) {
        $subs = self::get_user_subscriptions( $user_id );
        return $subs[ $artiste_id ] ?? null;
    }

    public static function activate( $user_id, $artiste_id, $palier, $args = array() ) {
        $defaults = array(
            'duree_jours'    => 30,
            'transaction_id' => '',
            'montant'        => 0,
            'gateway'        => 'manual',
            'periodicity'    => 'monthly',
        );
        $args = wp_parse_args( $args, $defaults );

        // Validation du palier via la classe centrale
        if ( ! KMFamily_Paliers::exists( $palier ) ) return false;

        // Si périodicité fournie et valide, utiliser ses jours
        if ( class_exists( 'KMFamily_Periodicity' ) && KMFamily_Periodicity::exists( $args['periodicity'] ) ) {
            $args['duree_jours'] = KMFamily_Periodicity::get_days( $args['periodicity'] );
        }

        $subs = self::get_user_subscriptions( $user_id );

        $existing = $subs[ $artiste_id ] ?? null;
        $is_new = ! $existing || empty( $existing['expire'] ) || $existing['expire'] < time();
        $is_upgrade = $existing && ! empty( $existing['expire'] ) && $existing['expire'] > time() && $existing['palier'] !== $palier;

        $start_from = time();
        if ( $existing && ! empty( $existing['expire'] ) && $existing['expire'] > time() && $existing['palier'] === $palier ) {
            $start_from = $existing['expire'];
        }

        $subs[ $artiste_id ] = array(
            'palier'         => sanitize_key( $palier ),
            'periodicity'    => sanitize_key( $args['periodicity'] ),
            'date_debut'     => time(),
            'expire'         => $start_from + ( absint( $args['duree_jours'] ) * DAY_IN_SECONDS ),
            'transaction_id' => sanitize_text_field( $args['transaction_id'] ),
            'montant'        => absint( $args['montant'] ),
            'gateway'        => sanitize_text_field( $args['gateway'] ),
        );

        update_user_meta( $user_id, self::META_KEY, $subs );
        self::add_to_history( $user_id, $artiste_id, $palier, $args );

        // Attribuer le rôle membre
        $user = get_userdata( $user_id );
        if ( $user && ! in_array( 'kmfamily_member', $user->roles, true ) && ! in_array( 'administrator', $user->roles, true ) ) {
            $user->add_role( 'kmfamily_member' );
        }

        // Mettre à jour le badge utilisateur (palier le plus élevé)
        if ( class_exists( 'KMFamily_Badges' ) ) {
            KMFamily_Badges::update_user_badge( $user_id );
        }

        // Déclencher hooks pour notifications
        if ( $is_new ) {
            do_action( 'kmfamily_subscription_activated', $user_id, $artiste_id, $palier, $args );
        } elseif ( $is_upgrade ) {
            do_action( 'kmfamily_subscription_upgraded', $user_id, $artiste_id, $palier, $existing['palier'], $args );
        } else {
            do_action( 'kmfamily_subscription_renewed', $user_id, $artiste_id, $palier, $args );
        }

        return true;
    }

    public static function cancel( $user_id, $artiste_id ) {
        $subs = self::get_user_subscriptions( $user_id );
        if ( isset( $subs[ $artiste_id ] ) ) {
            $subs[ $artiste_id ]['expire'] = time() - 1;
            update_user_meta( $user_id, self::META_KEY, $subs );

            if ( class_exists( 'KMFamily_Badges' ) ) {
                KMFamily_Badges::update_user_badge( $user_id );
            }

            do_action( 'kmfamily_subscription_cancelled', $user_id, $artiste_id );
            return true;
        }
        return false;
    }

    private static function add_to_history( $user_id, $artiste_id, $palier, $args ) {
        $history = get_user_meta( $user_id, self::HISTORY_META_KEY, true );
        if ( ! is_array( $history ) ) $history = array();

        $history[] = array(
            'artiste_id'     => $artiste_id,
            'palier'         => $palier,
            'periodicity'    => $args['periodicity'] ?? 'monthly',
            'date'           => time(),
            'transaction_id' => $args['transaction_id'] ?? '',
            'montant'        => $args['montant'] ?? 0,
            'gateway'        => $args['gateway'] ?? 'manual',
        );

        if ( count( $history ) > 100 ) $history = array_slice( $history, -100 );

        update_user_meta( $user_id, self::HISTORY_META_KEY, $history );
    }

    public static function get_user_history( $user_id ) {
        $history = get_user_meta( $user_id, self::HISTORY_META_KEY, true );
        return is_array( $history ) ? array_reverse( $history ) : array();
    }

    public static function check_expirations() {
        global $wpdb;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_KEY
        ) );

        $now = time();
        $soon = $now + ( 3 * DAY_IN_SECONDS );

        foreach ( $results as $row ) {
            $subs = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $subs ) ) continue;

            $modified = false;
            foreach ( $subs as $artiste_id => $sub ) {
                if ( empty( $sub['expire'] ) ) continue;

                if ( $sub['expire'] < $now && empty( $sub['expired_notified'] ) ) {
                    do_action( 'kmfamily_subscription_expired', $row->user_id, $artiste_id, $sub );
                    $subs[ $artiste_id ]['expired_notified'] = true;
                    $modified = true;
                } elseif ( $sub['expire'] < $soon && $sub['expire'] > $now && empty( $sub['warning_sent'] ) ) {
                    do_action( 'kmfamily_subscription_expiring_soon', $row->user_id, $artiste_id, $sub );
                    $subs[ $artiste_id ]['warning_sent'] = true;
                    $modified = true;
                }
            }
            if ( $modified ) {
                update_user_meta( $row->user_id, self::META_KEY, $subs );

                if ( class_exists( 'KMFamily_Badges' ) ) {
                    KMFamily_Badges::update_user_badge( $row->user_id );
                }
            }
        }
    }

    public static function count_members_for_artist( $artiste_id ) {
        global $wpdb;

        // LOT 0 — CHEMIN RAPIDE : une fois le socle des adhésions repris, ce
        // comptage devient une requête indexée. L'ancien chemin ci-dessous
        // charge la ligne usermeta de CHAQUE membre du site et la désérialise
        // en PHP, à chaque appel — et le tableau de bord label en déclenche un
        // par artiste. Le repli reste actif tant que la reprise n'a pas eu
        // lieu : mieux vaut lent et juste que rapide et faux.
        if ( class_exists( 'KMFamily_Memberships' ) && KMFamily_Memberships::is_ready() ) {
            return KMFamily_Memberships::count_active_for_artist( $artiste_id );
        }

        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_KEY
        ) );

        $count = 0;
        $now = time();

        foreach ( $results as $meta ) {
            $subs = maybe_unserialize( $meta );
            if ( is_array( $subs ) && isset( $subs[ $artiste_id ] ) ) {
                if ( ! empty( $subs[ $artiste_id ]['expire'] ) && $subs[ $artiste_id ]['expire'] > $now ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Nombre total de membres ayant au moins un palier actif, tous artistes confondus
     * (un membre soutenant 3 artistes ne compte qu'une fois). Utilisé pour le stat social
     * proof de la bannière d'accueil.
     */
    public static function count_total_active_members() {
        global $wpdb;

        // LOT 0 — même chemin rapide que count_members_for_artist().
        if ( class_exists( 'KMFamily_Memberships' ) && KMFamily_Memberships::is_ready() ) {
            return KMFamily_Memberships::count_total_active();
        }

        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_KEY
        ) );

        $count = 0;
        $now   = time();

        foreach ( $results as $meta ) {
            $subs = maybe_unserialize( $meta );
            if ( ! is_array( $subs ) ) continue;

            foreach ( $subs as $sub ) {
                if ( ! empty( $sub['expire'] ) && $sub['expire'] > $now ) {
                    $count++;
                    break; // un seul comptage par membre, peu importe le nombre d'artistes soutenus.
                }
            }
        }

        return $count;
    }
}
