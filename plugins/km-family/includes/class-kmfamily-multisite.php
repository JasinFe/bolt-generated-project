<?php
/**
 * Compatibilité WordPress Multisite
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Multisite {

    public static function init() {
        if ( ! is_multisite() ) return;

        add_action( 'wpmu_new_user', array( __CLASS__, 'add_user_to_main_site' ) );
        add_action( 'user_register', array( __CLASS__, 'add_user_to_main_site' ) );
        add_action( 'init',          array( __CLASS__, 'ensure_member_role_on_subsite' ) );
    }

    public static function add_user_to_main_site( $user_id ) {
        if ( ! is_multisite() ) return;

        $main_site_id = get_main_site_id();
        if ( ! is_user_member_of_blog( $user_id, $main_site_id ) ) {
            add_user_to_blog( $main_site_id, $user_id, 'subscriber' );
        }
    }

    public static function ensure_member_role_on_subsite() {
        if ( ! is_user_logged_in() || is_main_site() ) return;

        $user_id = get_current_user_id();
        $subs    = KMFamily_Subscriptions::get_user_subscriptions( $user_id );

        if ( empty( $subs ) ) return;

        $has_active = false;
        foreach ( $subs as $sub ) {
            if ( ! empty( $sub['expire'] ) && $sub['expire'] > time() ) {
                $has_active = true;
                break;
            }
        }

        if ( ! $has_active ) return;

        $current_blog_id = get_current_blog_id();
        if ( ! is_user_member_of_blog( $user_id, $current_blog_id ) ) {
            add_user_to_blog( $current_blog_id, $user_id, 'subscriber' );
        }
    }
}
