<?php
/**
 * Plugin Name: FinaKop — maintenance de migration
 * Description: Fige FinaKop (503) pendant l'export des données. Le reste du site WordPress reste en ligne.
 *
 * À déposer dans wp-content/mu-plugins/ SUR L'ANCIEN HÉBERGEMENT, juste avant
 * l'export final. Plus aucune écriture n'atteint les bases : l'export est
 * alors la photographie exacte du dernier état. À retirer si la migration est
 * abandonnée (retour arrière).
 */
defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', function () {
	$hote   = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
	$prefix = (string) get_option( 'fkc_subdomain_prefix', 'finakopcore' );
	$slug   = trim( (string) get_option( 'fkc_path_slug', 'finakop' ), '/' );
	$chemin = '/' . ltrim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );

	$finakop = ( '' !== $prefix && 0 === strpos( $hote, $prefix . '.' ) )
		|| ( '' !== $slug && ( $chemin === '/' . $slug || 0 === strpos( $chemin, '/' . $slug . '/' ) ) );
	if ( ! $finakop || is_admin() ) { return; }

	status_header( 503 );
	header( 'Retry-After: 3600' );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><meta charset="utf-8"><title>Maintenance</title>'
		. '<div style="font-family:system-ui;max-width:32rem;margin:15vh auto;text-align:center">'
		. '<h1>FinaKop déménage</h1><p>Votre ERP est en cours de transfert vers un nouveau serveur. '
		. 'Aucune donnée n\'est perdue. Merci de revenir dans quelques instants.</p></div>';
	exit;
}, 0 ); // AVANT l'interception de FinaKop (priorité 1).
