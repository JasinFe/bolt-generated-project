<?php
// Suppression des réglages uniquement. Les articles du blog sont conservés.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'kmb_settings' );
delete_option( 'kmb_needs_flush' );
