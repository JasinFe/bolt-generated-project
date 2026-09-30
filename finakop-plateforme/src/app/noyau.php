<?php
/**
 * FinaKop ERP Core — chargement du noyau HORS REQUÊTE HTTP (1.875.6).
 *
 * Sert les traitements planifiés : WP-Cron et WP-CLI (extension WordPress),
 * cron système (mode autonome, bin/finakop). Avant la 1.875.6, ces appels
 * testaient class_exists( 'FKC_BPEWorker' ) alors que les classes ne sont
 * déclarées que par app/index.php, chargé seulement quand l'application sert
 * une page : le test échouait et la file différée — dont la consultation du
 * dépôt de licences — ne se vidait qu'au bouton de l'écran Processus.
 *
 * Prérequis de l'appelant : FKC_ROOT, FKC_DATA_DIR, FKC_BASE_URL définis, et
 * $_SERVER['HTTP_HOST'] posé sur l'hôte de l'application (le contrôle de
 * domaine de la licence le lit). Idempotent.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || exit;

if ( ! defined( 'FKC_NOYAU_CHARGE' ) ) {
	$fkcCreation = defined( 'FKC_NOYAU_CREER' ) && FKC_NOYAU_CREER;
	if ( ! defined( 'FKC_DATA_DIR' ) || ( ! $fkcCreation && ! is_file( FKC_DATA_DIR . 'finakopcore-master.db' ) ) ) {
		// Installation jamais servie : rien à traiter, et surtout rien à créer
		// (un registre neuf sèmerait un compte administrateur hors de toute vue).
		// Seule la création explicite d'un espace (FKC_NOYAU_CREER, posée par
		// « finakop tenant:creer ») est autorisée à amorcer un registre neuf.
		return false;
	}
	defined( 'FKC_CLI_NOYAU' ) || define( 'FKC_CLI_NOYAU', true );
	if ( ! isset( $_SESSION ) ) { $_SESSION = array(); }
	$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
	$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

	require FKC_ROOT . 'index.php'; // s'arrête avant l'API (FKC_CLI_NOYAU)

	// Amorçages de app/index.php qui ne dépendent ni de la session ni d'un utilisateur.
	FKC_Master::conn();
	FKC_License::bootstrap();
	FKC_Immobilisation::brancher();
	FKC_License_Depot::enregistrerTraitement();
	FKC_Assistant::brancherForecast();

	define( 'FKC_NOYAU_CHARGE', true );
}
return true;
