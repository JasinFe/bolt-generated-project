<?php
/** Noyau Sécurité : durcissement HTTP, invisibilité moteurs, anti-bruteforce, journal d'audit, protection du dossier de données.
 * @package FinaKop_ERP_Core */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Security {

	const MAX_ESSAIS   = 6;      // tentatives avant blocage
	const FENETRE_MIN  = 15;     // durée du blocage (minutes)

	/* ── Détection HTTPS ── */
	public static function isHttps() {
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) { return true; }
		if ( isset( $_SERVER['SERVER_PORT'] ) && 443 == $_SERVER['SERVER_PORT'] ) { return true; }
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) { return true; }
		return false;
	}
	public static function clientIp() {
		// On ne fait confiance qu'à REMOTE_ADDR par défaut (les en-têtes XFF sont falsifiables).
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	/* ── Paramètres de session durcis (à appeler AVANT session_start) ── */
	public static function cookieParams() {
		if ( PHP_SESSION_NONE !== session_status() ) { return; }
		@ini_set( 'session.use_strict_mode', '1' );
		@ini_set( 'session.use_only_cookies', '1' );
		@ini_set( 'session.cookie_httponly', '1' );
		if ( self::isHttps() ) { @ini_set( 'session.cookie_secure', '1' ); }
		if ( PHP_VERSION_ID >= 70300 ) {
			// Chemin du cookie = chemin de base de l'application (1.876.0) : en mode
			// « chemin » (…/client-a/, …/client-b/), deux espaces ne se partagent
			// pas le même cookie. Sous-domaine : chemin « / », cookie propre à l'hôte.
			$chemin = ( defined( 'FKC_BASE_PATH' ) && '' !== (string) FKC_BASE_PATH ) ? rtrim( (string) FKC_BASE_PATH, '/' ) . '/' : '/';
			@session_set_cookie_params( array( 'lifetime' => 0, 'path' => $chemin, 'httponly' => true, 'secure' => self::isHttps(), 'samesite' => 'Lax' ) );
		} else {
			@ini_set( 'session.cookie_samesite', 'Lax' );
		}
	}

	/* ── En-têtes de sécurité + invisibilité moteurs de recherche ── */
	public static function sendHeaders() {
		if ( headers_sent() ) { return; }
		@header_remove( 'X-Powered-By' );
		// Invisibilité : aucun moteur ne doit indexer, suivre, archiver ou afficher d'extrait.
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai', true );
		// Anti-clickjacking / sniffing / fuite de référent.
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		// Caméra autorisée uniquement en propre origine (self) : nécessaire au
		// scanner mobile Smart Scan (getUserMedia). Micro et géolocalisation
		// restent désactivés, et aucun tiers ne peut accéder à la caméra.
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(self), interest-cohort=()' );
		header( 'Cross-Origin-Opener-Policy: same-origin' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		$csp = defined( 'FKC_CSP' ) ? (string) FKC_CSP :
			"default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
			// media-src : le flux de la caméra. Sans lui, un navigateur qui passe
			// par une URL blob: pour la vidéo se voit refuser la source, et le
			// scanner affiche une image noire sans rien décoder.
			. "media-src 'self' blob:; "
			// connect-src : QZ Tray (impression directe) écoute sur le poste lui-même,
			// en WebSocket local (localhost / localhost.qz.io, ports 8181-8485) — 1.843.0.
			. "script-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self' wss://localhost:* ws://localhost:* wss://localhost.qz.io:* ws://localhost.qz.io:*; "
			. "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
		header( 'Content-Security-Policy: ' . $csp );
		if ( self::isHttps() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}
	/** Balise <meta robots> pour le <head> (défense complémentaire). */
	public static function robotsMeta() {
		return '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">';
	}

	/* ── Protection du dossier de données (DB SQLite, clé, journaux) ── */
	public static function protectDataDir() {
		if ( ! defined( 'FKC_DATA_DIR' ) || ! is_dir( FKC_DATA_DIR ) ) { return; }
		$ht = FKC_DATA_DIR . '.htaccess';
		$want = "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\nOptions -Indexes\n";
		if ( ! is_file( $ht ) || trim( (string) @file_get_contents( $ht ) ) !== trim( $want ) ) { @file_put_contents( $ht, $want ); }
		$wc = FKC_DATA_DIR . 'web.config';
		if ( ! is_file( $wc ) ) {
			@file_put_contents( $wc, "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization>"
				. "<deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
		}
		$idx = FKC_DATA_DIR . 'index.html';
		if ( ! is_file( $idx ) ) { @file_put_contents( $idx, '' ); }
		$key = FKC_DATA_DIR . '.fkc-secret.key';
		if ( is_file( $key ) ) { @chmod( $key, 0600 ); }
	}

	/** Amorçage global : en-têtes + protection dossier + clé de chiffrement. */
	public static function boot() {
		self::sendHeaders();
		self::protectDataDir();
		if ( class_exists( 'FKC_Crypto' ) ) { FKC_Crypto::key(); } // garantit la présence/permissions de la clé
	}

	/* ── Journal d'audit ── */
	public static function audit( $action, $detail = '', $user_id = null ) {
		try {
			if ( null === $user_id && class_exists( 'FKC_Auth' ) ) { $user_id = FKC_Auth::id(); }
			FKC_Master::q( 'INSERT INTO security_events(user_id,ip,action,detail) VALUES(?,?,?,?)',
				array( $user_id, self::clientIp(), (string) $action, (string) $detail ) );
		} catch ( \Throwable $e ) { /* silencieux : la sécurité ne doit jamais casser l'app */ }
	}
	public static function events( $limit = 50 ) {
		try { return FKC_Master::q( 'SELECT * FROM security_events ORDER BY id DESC LIMIT ?', array( (int) $limit ) )->fetchAll(); }
		catch ( \Throwable $e ) { return array(); }
	}

	/* ── Anti-bruteforce (connexion) ── */
	protected static function cle( $ip, $login ) { return strtolower( $ip . '|' . $login ); }

	public static function loginBlocked( $ip, $login ) {
		try {
			$r = FKC_Master::q( 'SELECT attempts,locked_until FROM security_throttle WHERE cle=?', array( self::cle( $ip, $login ) ) )->fetch();
			if ( $r && ! empty( $r['locked_until'] ) && strtotime( $r['locked_until'] ) > time() ) { return true; }
		} catch ( \Throwable $e ) {}
		return false;
	}
	public static function blockedUntil( $ip, $login ) {
		try {
			$r = FKC_Master::q( 'SELECT locked_until FROM security_throttle WHERE cle=?', array( self::cle( $ip, $login ) ) )->fetch();
			return $r ? $r['locked_until'] : null;
		} catch ( \Throwable $e ) { return null; }
	}
	public static function loginFailed( $ip, $login ) {
		try {
			$cle = self::cle( $ip, $login );
			$r = FKC_Master::q( 'SELECT attempts FROM security_throttle WHERE cle=?', array( $cle ) )->fetch();
			$n = ( $r ? (int) $r['attempts'] : 0 ) + 1;
			$lock = ( $n >= self::MAX_ESSAIS ) ? date( 'Y-m-d H:i:s', time() + self::FENETRE_MIN * 60 ) : null;
			FKC_Master::q( "INSERT INTO security_throttle(cle,attempts,locked_until,updated_at) VALUES(?,?,?,datetime('now','localtime'))
				ON CONFLICT(cle) DO UPDATE SET attempts=excluded.attempts, locked_until=excluded.locked_until, updated_at=excluded.updated_at",
				array( $cle, $n, $lock ) );
			self::audit( $lock ? 'login_blocked' : 'login_fail', 'login=' . $login . ' tentatives=' . $n );
		} catch ( \Throwable $e ) {}
	}
	public static function loginOk( $ip, $login ) {
		try {
			FKC_Master::q( 'DELETE FROM security_throttle WHERE cle=?', array( self::cle( $ip, $login ) ) );
			self::audit( 'login_ok', 'login=' . $login );
		} catch ( \Throwable $e ) {}
	}

	/* ── Limiteur d'essais générique (routes publiques : appairage, webhooks) ── */

	/**
	 * L'anti-bruteforce ci-dessus ne couvrait que la connexion. Ces trois
	 * méthodes exposent le même mécanisme à n'importe quelle action sensible
	 * joignable sans session : appairage d'un appareil de scan, webhook,
	 * vérification d'un code. La table security_throttle appartient au registre
	 * cabinet, donc le compteur fonctionne avant même qu'une société soit
	 * résolue.
	 *
	 * @param string $action Identifiant de l'action (préfixe de clé).
	 * @param string $sujet  IP, identifiant ou couple des deux.
	 * @return bool true si l'action est temporairement refusée.
	 */
	public static function actionBloquee( $action, $sujet ) {
		try {
			$r = FKC_Master::q(
				'SELECT locked_until FROM security_throttle WHERE cle=?',
				array( self::cleAction( $action, $sujet ) )
			)->fetch();
			return (bool) ( $r && ! empty( $r['locked_until'] ) && strtotime( $r['locked_until'] ) > time() );
		} catch ( \Throwable $e ) { return false; }
	}

	/**
	 * Enregistre un échec et bloque au-delà du seuil.
	 *
	 * @param string $action  Identifiant de l'action.
	 * @param string $sujet   IP ou identifiant.
	 * @param int    $maxi    Nombre d'échecs tolérés.
	 * @param int    $minutes Durée du blocage.
	 */
	public static function actionEchec( $action, $sujet, $maxi = 10, $minutes = 15 ) {
		try {
			$cle = self::cleAction( $action, $sujet );
			$r = FKC_Master::q( 'SELECT attempts, locked_until FROM security_throttle WHERE cle=?', array( $cle ) )->fetch();
			// Un blocage échu remet le compteur à zéro : sinon un blocage
			// s'éterniserait sur un seul échec après expiration.
			$base = 0;
			if ( $r ) {
				$echu = empty( $r['locked_until'] ) || strtotime( $r['locked_until'] ) <= time();
				$base = $echu && ! empty( $r['locked_until'] ) ? 0 : (int) $r['attempts'];
			}
			$n = $base + 1;
			$lock = ( $n >= (int) $maxi ) ? date( 'Y-m-d H:i:s', time() + (int) $minutes * 60 ) : null;
			FKC_Master::q(
				"INSERT INTO security_throttle(cle,attempts,locked_until,updated_at) VALUES(?,?,?,datetime('now','localtime'))
				 ON CONFLICT(cle) DO UPDATE SET attempts=excluded.attempts, locked_until=excluded.locked_until, updated_at=excluded.updated_at",
				array( $cle, $n, $lock )
			);
			if ( $lock ) { self::audit( 'action_bloquee', $action . ' sujet=' . $sujet . ' essais=' . $n ); }
		} catch ( \Throwable $e ) {}
	}

	/** Réinitialise le compteur après un succès. */
	public static function actionReussie( $action, $sujet ) {
		try {
			FKC_Master::q( 'DELETE FROM security_throttle WHERE cle=?', array( self::cleAction( $action, $sujet ) ) );
		} catch ( \Throwable $e ) {}
	}

	protected static function cleAction( $action, $sujet ) {
		return 'act:' . strtolower( (string) $action ) . '|' . strtolower( (string) $sujet );
	}

	/* ── Posture de sécurité (pour le tableau de bord) ── */
	/**
	 * Posture de sécurité pour le tableau de bord.
	 *
	 * « data_protege » ne testait que la présence d'un .htaccess — un indicateur
	 * vert sous nginx, où ce fichier n'est jamais lu. On rapporte désormais
	 * l'emplacement réel du dossier et le résultat du test HTTP mené par le
	 * plugin, et l'on distingue une clé de chiffrement fournie par wp-config
	 * d'une clé auto-générée à côté des données qu'elle protège.
	 */
	public static function posture() {
		$dansRacineWeb = null;
		$exposition    = null;
		if ( defined( 'FKC_DATA_DIR' ) && function_exists( 'wp_upload_dir' ) ) {
			$base = function_exists( 'trailingslashit' ) ? trailingslashit( wp_upload_dir()['basedir'] ) : '';
			$dansRacineWeb = ( '' !== $base && 0 === strpos( FKC_DATA_DIR, $base ) );
		}
		// Hors WordPress (1.876.0) : le dossier de données est-il sous la racine web ?
		if ( null === $dansRacineWeb && defined( 'FKC_DATA_DIR' ) && ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$racine = realpath( (string) $_SERVER['DOCUMENT_ROOT'] );
			$donnees = realpath( (string) FKC_DATA_DIR );
			if ( $racine && $donnees ) { $dansRacineWeb = 0 === strpos( $donnees . '/', rtrim( $racine, '/' ) . '/' ); }
		}
		if ( function_exists( 'get_option' ) ) {
			$cache = get_option( 'fkc_data_expose', null );
			if ( is_array( $cache ) && array_key_exists( 'expose', $cache ) ) { $exposition = $cache['expose']; }
		}
		$source = class_exists( 'FKC_Crypto' ) ? FKC_Crypto::keySource() : 'absente';

		return array(
			'https'          => self::isHttps(),
			'chiffrement'    => class_exists( 'FKC_Crypto' ) && FKC_Crypto::available(),
			'cle_source'     => $source,
			// Une clé posée dans le dossier des données ne protège pas contre une
			// lecture du système de fichiers : ce n'est pas une posture « verte ».
			'cle_hors_donnees' => ( 'constante' === $source ),
			'algo'           => class_exists( 'FKC_Crypto' ) ? FKC_Crypto::algo() : '—',
			'noindex'        => true,
			'data_htaccess'  => defined( 'FKC_DATA_DIR' ) && is_file( FKC_DATA_DIR . '.htaccess' ),
			'data_hors_web'  => ( false === $dansRacineWeb ),
			// true = confirmé téléchargeable, false = confirmé inaccessible, null = non testé
			'data_expose'    => $exposition,
			'data_protege'   => ( false === $dansRacineWeb ) || ( false === $exposition ),
			'session_durcie' => true,
			'session_inactivite_min' => class_exists( 'FKC_Auth' ) ? (int) ( FKC_Auth::INACTIVITE_MAX / 60 ) : null,
			'session_duree_max_h'    => class_exists( 'FKC_Auth' ) ? (int) ( FKC_Auth::DUREE_ABSOLUE_MAX / 3600 ) : null,
			'api_cors'       => defined( 'FKC_API_CORS' ) ? (string) FKC_API_CORS : 'même origine',
			'api_debit_min'  => class_exists( 'FKC_ApiKey' ) ? FKC_ApiKey::debitParMinute() : null,
		);
	}
}
