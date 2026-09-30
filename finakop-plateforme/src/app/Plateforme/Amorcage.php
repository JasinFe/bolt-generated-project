<?php
/**
 * FKC_Plateforme_Amorcage — démarrage de FinaKop SANS WordPress (1.876.0).
 *
 * Remplace fkc_intercept_request() de l'extension. Ordre, pour chaque requête :
 *
 *   1. configuration système (hors web), fuseau PHP + SQLite ;
 *   2. relais : IP réelle et HTTPS, uniquement depuis Cloudflare ou un relais déclaré ;
 *   3. garde de l'origine (en-tête secret Cloudflare, si configuré) ;
 *   4. hôte → client (FKC_TenantResolver) ; refus de tout le reste ;
 *   5. HTTPS obligatoire ;
 *   6. contexte du client : constantes FKC_*, dossier de données, sessions à part ;
 *   7. app/index.php — FinaKop inchangé (auth, RBAC, sociétés, modules, packs).
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || define( 'FKC_PLATEFORME', true );

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Registre.php';
require_once __DIR__ . '/Cloudflare.php';
require_once __DIR__ . '/TenantResolver.php';
require_once __DIR__ . '/Pages.php';

class FKC_Plateforme_Amorcage {

	/** Racine du code déployé (dossier contenant app/, bin/, cron/…). */
	public static function racine() {
		return defined( 'FINAKOP_RACINE' ) ? rtrim( FINAKOP_RACINE, '/' ) : dirname( __DIR__, 2 );
	}

	/** Emplacement de la configuration : constante, variable d'environnement, ou ../config.php à côté des versions. */
	public static function fichierConfig() {
		if ( defined( 'FINAKOP_CONFIG' ) ) { return FINAKOP_CONFIG; }
		$e = getenv( 'FINAKOP_CONFIG' );
		if ( $e ) { return $e; }
		// ~/finakop/current → ~/finakop/config.php ; en développement : <racine>/config/config.php
		$r = realpath( self::racine() ) ?: self::racine();
		foreach ( array( dirname( $r, 2 ) . '/config.php', dirname( $r ) . '/config.php', $r . '/config/config.php' ) as $f ) {
			if ( is_file( $f ) ) { return $f; }
		}
		return dirname( $r, 2 ) . '/config.php';
	}

	public static function version() {
		$f = self::racine() . '/VERSION';
		$v = is_file( $f ) ? trim( (string) file_get_contents( $f ) ) : '';
		return '' !== $v ? $v : '0.0.0';
	}

	/** Socle commun web et ligne de commande. */
	public static function socle() {
		umask( 0027 ); // fichiers créés par FinaKop : illisibles des autres comptes du serveur
		if ( ! FKC_Config::charge() ) { FKC_Config::charger( self::fichierConfig() ); }
		$tz = (string) FKC_Config::get( 'fuseau', 'UTC' );
		@date_default_timezone_set( $tz );
		// SQLite calcule datetime('now','localtime') avec le fuseau du PROCESSUS : on
		// l'aligne sur celui de PHP, pour que les deux horloges de FinaKop concordent.
		@putenv( 'TZ=' . $tz );
		if ( function_exists( 'mb_internal_encoding' ) ) { @mb_internal_encoding( 'UTF-8' ); }
	}

	/* ══════════════════════ Requête web ══════════════════════ */

	public static function web() {
		$client = null;
		try {
			self::socle();
			@ini_set( 'display_errors', FKC_Config::get( 'debug' ) ? '1' : '0' );
			FKC_Plateforme_Proxy::appliquer( $_SERVER );
			if ( ! FKC_Plateforme_Proxy::origineAutorisee( $_SERVER ) ) {
				self::journalWeb( 'origine_refusee', (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
				return FKC_Plateforme_Pages::origineRefusee();
			}
			$r = FKC_TenantResolver::resoudre( $_SERVER['HTTP_HOST'] ?? '', $_SERVER['REQUEST_URI'] ?? '/' );
			if ( FKC_TenantResolver::HOTE_REFUSE === $r['type'] ) { return FKC_Plateforme_Pages::hoteRefuse(); }

			if ( FKC_Config::get( 'https', true ) && ! FKC_Plateforme_Proxy::estHttps( $_SERVER ) ) {
				header( 'Location: https://' . $r['hote'] . ( $_SERVER['REQUEST_URI'] ?? '/' ), true, 301 );
				return;
			}
			switch ( $r['type'] ) {
				case FKC_TenantResolver::SITE:        header( 'Location: ' . FKC_Config::get( 'site_public' ), true, 301 ); return;
				case FKC_TenantResolver::PORTAIL:     return FKC_Plateforme_Pages::portail();
				case FKC_TenantResolver::INCONNU:     return FKC_Plateforme_Pages::inconnu();
				case FKC_TenantResolver::SUSPENDU:    return FKC_Plateforme_Pages::suspendu();
				case FKC_TenantResolver::MAINTENANCE: return FKC_Plateforme_Pages::maintenance();
			}
			self::contexte( $r['client'], $r['hote'], $r['base_path'], self::schema() );
			$client = $r['client'];
		} catch ( \Throwable $e ) {
			self::journalWeb( 'erreur_amorcage', $e->getMessage() );
			return FKC_Plateforme_Pages::indisponible();
		}
		// FinaKop proprement dit, hors du try : il gère ses propres erreurs et sorties.
		while ( ob_get_level() ) { ob_end_clean(); }
		require FKC_ROOT . 'index.php';
	}

	/**
	 * Contexte d'UN client : constantes lues par FinaKop, dossier de données,
	 * sessions séparées. Utilisé par le web et par la ligne de commande.
	 *
	 * @param bool $creation autorise un dossier encore vide (« finakop tenant:creer »).
	 */
	public static function contexte( array $t, $hote, $basePath = '', $schema = 'https', $creation = false ) {
		if ( defined( 'FKC_DATA_DIR' ) ) { throw new \LogicException( 'Un contexte client est déjà chargé dans ce processus.' ); }
		$donnees = FKC_Plateforme_Registre::dossierDonnees( $t );
		if ( ! is_dir( $donnees ) ) { throw new \RuntimeException( 'Dossier de données absent pour ' . $t['slug'] ); }
		if ( ! $creation && ! is_file( $donnees . 'finakopcore-master.db' ) ) { throw new \RuntimeException( 'Registre absent pour ' . $t['slug'] ); }

		$app = self::racine() . '/app/';
		define( 'FKC_ROOT', $app );
		define( 'FKC_APP_DIR', $app );
		define( 'FKC_VERSION', self::version() );
		define( 'FKC_DATA_DIR', $donnees );
		define( 'FKC_BASE_PATH', rtrim( (string) $basePath, '/' ) );
		define( 'FKC_BASE_URL', $schema . '://' . $hote . FKC_BASE_PATH . '/' );
		define( 'FKC_ASSETS_URL', '/_fkc' );
		define( 'FKC_TENANT', $t['slug'] );
		define( 'FKC_TENANT_NOM', $t['nom'] );

		define( 'FKC_LICENSE_SERVER', rtrim( (string) FKC_Config::get( 'licence.serveur' ), '/' ) );
		if ( '' !== (string) FKC_Config::get( 'licence.depot', '' ) ) { define( 'FKC_LICENSE_DEPOT', rtrim( (string) FKC_Config::get( 'licence.depot' ), '/' ) ); }
		define( 'FKC_LICENSE_STRICT_DOMAIN', (bool) FKC_Config::get( 'licence.domaine_strict', true ) );
		define( 'FKC_API_AUTHORITY', 'local' === FKC_Config::get( 'api.autorite' ) ? 'local' : 'service' );
		if ( '' !== (string) FKC_Config::get( 'api.cors', '' ) ) { define( 'FKC_API_CORS', (string) FKC_Config::get( 'api.cors' ) ); }
		if ( '' !== (string) FKC_Config::get( 'connect.push_hotes', '' ) ) { define( 'FKC_PUSH_HOTES', (string) FKC_Config::get( 'connect.push_hotes' ) ); }
		define( 'FKC_ADMIN_EMAIL', (string) FKC_Config::get( 'admin_email', '' ) );
		define( 'FKC_CRON_EXTERNE', true );
		define( 'FKC_CRON_INTERVALLE', (int) FKC_Config::get( 'cron.intervalle', 300 ) );

		$smtp = (array) FKC_Config::get( 'smtp', array() );
		if ( 'fichier' === ( $smtp['transport'] ?? '' ) && '' === (string) ( $smtp['dossier'] ?? '' ) ) {
			$smtp['dossier'] = FKC_Config::dossier( 'plateforme/courriels/' . $t['dossier'] );
		}
		if ( '' === (string) ( $smtp['nom_expediteur'] ?? '' ) || 'FinaKop' === $smtp['nom_expediteur'] ) {
			$smtp['nom_expediteur'] = $t['nom'] . ' via FinaKop';
		}
		define( 'FKC_SMTP', $smtp );

		// Sessions : un dossier et un nom de cookie par client. Une session d'un
		// client présentée à un autre n'y existe tout simplement pas.
		$sess = FKC_Plateforme_Registre::dossierSessions( $t );
		if ( ! is_dir( $sess ) ) { @mkdir( $sess, 0700, true ); }
		define( 'FKC_SESSION_NAME', 'FKC_' . strtoupper( substr( hash( 'sha256', 'finakop-session:' . $t['slug'] ), 0, 12 ) ) );
		@ini_set( 'session.save_path', rtrim( $sess, '/' ) );
		@ini_set( 'session.gc_probability', '0' );   // nettoyage par le cron (voir cron/worker.php)
		@ini_set( 'session.gc_maxlifetime', '28800' );
		@ini_set( 'session.use_strict_mode', '1' );
		@ini_set( 'session.use_only_cookies', '1' );
	}

	/** Schéma des adresses produites : https en production ; http seulement si la configuration l'autorise (recette locale). */
	public static function schema() {
		return ( FKC_Config::get( 'https', true ) || FKC_Plateforme_Proxy::estHttps( $_SERVER ) ) ? 'https' : 'http';
	}

	/** Journal de la plateforme (hors client), avec rotation. */
	public static function journal( $fichier, $ligne ) {
		$f = FKC_Config::dossier( 'plateforme/logs/' . $fichier );
		$d = dirname( $f );
		if ( ! is_dir( $d ) ) { @mkdir( $d, 0750, true ); }
		$max = max( 1, (int) FKC_Config::get( 'journaux.taille_max_mo', 5 ) ) * 1048576;
		if ( is_file( $f ) && @filesize( $f ) > $max ) {
			$n = max( 1, (int) FKC_Config::get( 'journaux.generations', 5 ) );
			for ( $i = $n - 1; $i >= 1; $i-- ) { if ( is_file( "$f.$i" ) ) { @rename( "$f.$i", "$f." . ( $i + 1 ) ); } }
			@rename( $f, "$f.1" );
		}
		@file_put_contents( $f, gmdate( 'Y-m-d\TH:i:s\Z' ) . ' ' . str_replace( array( "\r", "\n" ), ' ', (string) $ligne ) . "\n", FILE_APPEND | LOCK_EX );
	}

	protected static function journalWeb( $action, $detail ) {
		try {
			self::journal( 'plateforme.log', $action . ' ip=' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) . ' hote=' . substr( (string) ( $_SERVER['HTTP_HOST'] ?? '' ), 0, 100 ) . ' ' . $detail );
		} catch ( \Throwable $e ) {}
	}
}
