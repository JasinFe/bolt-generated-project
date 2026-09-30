<?php
/**
 * Gestion des rôles et capacités
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Roles {

    public static function create_roles() {
        add_role(
            'kmfamily_member',
            __( 'Membre KM Family', 'km-family' ),
            array(
                'read'                     => true,
                'kmfamily_access_exclusive' => true,
            )
        );

        foreach ( array( 'administrator', 'editor' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                $role->add_cap( 'kmfamily_access_exclusive' );
                $role->add_cap( 'kmfamily_manage_subscriptions' );
            }
        }
    }

    public static function remove_roles() {
        remove_role( 'kmfamily_member' );
    }
}
