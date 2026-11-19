<?php
// Suppression des réglages uniquement. Les articles du blog sont conservés.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'kmblog_settings' );
delete_option( 'kmblog_needs_flush' );
delete_option( 'kmblog_version' );
// Cache du temps de lecture (recalculable) ; sous-titres et « À la une » sont conservés.
delete_post_meta_by_key( '_kmb_reading' );
