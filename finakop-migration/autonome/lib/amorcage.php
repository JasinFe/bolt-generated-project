<?php
/**
 * FinaKop ERP Core — amorçage AUTONOME (sans WordPress).
 *
 * Remplace exactement ce que faisait fkc_intercept_request() dans
 * finakop-erp-core.php : définir les constantes, puis laisser app/index.php
 * servir la requête. Le dossier app/ n'est PAS modifié : la même version du
 * code reste distribuable comme extension WordPress.
 *
 * Lu par public/index.php (web) et par bin/finakop (tâches planifiées).
 */

defined( 'FKC_AUTONOME' ) || define( 'FKC_AUTONOME', true );

/** Emplacement de la configuration (surchargeable par variable d'environnement). */
function fka_fichier_config() {
	$env = getenv( 'FINAKOP_CONFIG' );
	return ( $env && is_file( $env ) ) ? $env : '/etc/finakop/config.php';
}

/** Charge et valide la configuration. Échoue bruyamment : mieux vaut un 500 qu'une base neuve créée au mauvais endroit. */
function fka_config() {
	static $cfg = null;
	if ( null !== $cfg ) { return $cfg; }
	$f = fka_fichier_config();
	if ( ! is_file( $f ) ) { fka_fatal( 'Configuration introuvable : ' . $f ); }
	$cfg = require $f;
	if ( ! is_array( $cfg ) ) { fka_fatal( 'Configuration invalide : ' . $f ); }

	foreach ( array( 'app_dir', 'data_dir', 'base_url' ) as $k ) {
		if ( empty( $cfg[ $k ] ) ) { fka_fatal( "Paramètre « {$k} » manquant dans {$f}" ); }
	}
	$cfg['app_dir']  = rtrim( $cfg['app_dir'], '/' ) . '/';
	$cfg['data_dir'] = rtrim( $cfg['data_dir'], '/' ) . '/';
	$cfg['base_url'] = rtrim( $cfg['base_url'], '/' ) . '/';

	if ( ! is_file( $cfg['app_dir'] . 'index.php' ) ) { fka_fatal( 'app/index.php introuvable dans ' . $cfg['app_dir'] ); }
	/*
	 * Le dossier de données DOIT exister d'avance. app/index.php le créerait
	 * sinon, vide, et FinaKop y sèmerait un registre neuf avec un compte
	 * administrateur : exactement la « perte de données » silencieuse qu'une
	 * migration mal pointée produirait.
	 */
	if ( ! is_dir( $cfg['data_dir'] ) ) { fka_fatal( 'Dossier de données absent : ' . $cfg['data_dir'] ); }
	return $cfg;
}

function fka_fatal( $msg ) {
	if ( 'cli' === PHP_SAPI ) { fwrite( STDERR, "FinaKop : {$msg}\n" ); exit( 1 ); }
	error_log( 'FinaKop : ' . $msg );
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	exit( "Service momentanément indisponible.\n" );
}

/** Version lue dans l'en-tête de l'extension (une seule source de vérité). */
function fka_version( $appDir ) {
	$entete = @file_get_contents( dirname( $appDir ) . '/finakop-erp-core.php', false, null, 0, 2048 );
	return ( $entete && preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $entete, $m ) ) ? $m[1] : '0.0.0';
}

/** Définit les constantes attendues par app/ — miroir de fkc_intercept_request(). */
function fka_definir_constantes() {
	$cfg = fka_config();

	defined( 'FKC_VERSION' )        || define( 'FKC_VERSION', fka_version( $cfg['app_dir'] ) );
	defined( 'FKC_ROOT' )           || define( 'FKC_ROOT', $cfg['app_dir'] );
	defined( 'FKC_APP_DIR' )        || define( 'FKC_APP_DIR', $cfg['app_dir'] );
	defined( 'FKC_DATA_DIR' )       || define( 'FKC_DATA_DIR', $cfg['data_dir'] );
	defined( 'FKC_BASE_URL' )       || define( 'FKC_BASE_URL', $cfg['base_url'] );
	defined( 'FKC_BASE_PATH' )      || define( 'FKC_BASE_PATH', '' ); // service à la racine du sous-domaine
	defined( 'FKC_ASSETS_URL' )     || define( 'FKC_ASSETS_URL', $cfg['assets_url'] ?? '/_fkc' );
	defined( 'FKC_LICENSE_SERVER' ) || define( 'FKC_LICENSE_SERVER', rtrim( $cfg['license_server'] ?? 'https://license.kophisgroup.com/api', '/' ) );
	defined( 'FKC_API_AUTHORITY' )  || define( 'FKC_API_AUTHORITY', $cfg['api_authority'] ?? 'service' );

	// Clé de chiffrement : UNIQUEMENT si elle existait dans wp-config.php.
	// Vide => app/ lit le fichier .fkc-secret.key du dossier de données.
	if ( ! empty( $cfg['encryption_key'] ) && ! defined( 'FKC_ENCRYPTION_KEY' ) ) {
		define( 'FKC_ENCRYPTION_KEY', (string) $cfg['encryption_key'] );
	}

	// Constantes optionnelles reconnues par app/ (liste fermée volontairement).
	$permises = array( 'FKC_LICENSE_DEPOT', 'FKC_LICENSE_STRICT_DOMAIN', 'FKC_DEBUG', 'FKC_CSP', 'FKC_API_DEBIT_MINUTE' );
	foreach ( (array) ( $cfg['constantes'] ?? array() ) as $nom => $val ) {
		if ( in_array( $nom, $permises, true ) && ! defined( $nom ) ) { define( $nom, $val ); }
	}

	require_once __DIR__ . '/compat-wp.php';
}
