<?php
/**
 * Gestion de licence : jeton signé RSA + éditions + vérification locale.
 * Le jeton est stocké en base (parametres.license_token). La clé publique est
 * embarquée (assets/keys/license_public.pem). Sans jeton installé, le palier
 * « Starter » local s'applique (aucune clé signée n'est distribuée).
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_License {

	const SERVER_URL = 'https://license.kophisgroup.com/api/verify';
	const CHECK_INTERVAL = 604800; // 7 jours
	const GRACE = 1209600;         // 14 jours

	protected static $resolved = null;

	/** Clés stockées au niveau cabinet (registre central), partagées par toutes les sociétés. */
	const CABINET_KEYS = array( 'license_token', 'license_remote', 'license_remote_at' );

	public static function param( $cle, $default = null ) {
		if ( in_array( $cle, self::CABINET_KEYS, true ) ) {
			return FKC_Master::param( $cle, $default );
		}
		$row = FKC_DB::q( 'SELECT valeur FROM parametres WHERE cle=?', array( $cle ) )->fetch();
		return $row ? $row['valeur'] : $default;
	}

	public static function setParam( $cle, $valeur ) {
		if ( in_array( $cle, self::CABINET_KEYS, true ) ) {
			FKC_Master::setParam( $cle, $valeur );
			return;
		}
		FKC_DB::q( 'INSERT INTO parametres(cle,valeur) VALUES(?,?) ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur', array( $cle, $valeur ) );
	}

	/**
	 * Amorce la licence si aucune n'est présente.
	 *
	 * Depuis la 1.177.0, AUCUN jeton signé n'est distribué avec le plugin :
	 * le fichier assets/license-default.key a été retiré du paquet (un jeton
	 * signé par la clé privée KOPHI'S GROUP ne doit jamais être embarqué dans
	 * une archive publique). Sans jeton installé, resolve() bascule sur le
	 * palier « Starter » local (voir localStarter()). Compatibilité : si une
	 * installation antérieure possède encore le fichier, il est lu comme avant.
	 */
	public static function bootstrap() {
		if ( self::param( 'license_token' ) ) {
			return;
		}
		$file = dirname( FKC_ROOT ) . '/assets/license-default.key';
		if ( is_readable( $file ) ) {
			$raw = trim( (string) file_get_contents( $file ) );
			if ( $raw ) {
				self::install( $raw );
			}
		}
	}

	/**
	 * État « Starter » local : édition gratuite par défaut, dérivée du
	 * référentiel des paliers, sans jeton signé. Les limites et modules du
	 * palier Starter s'appliquent ; l'écran Licence invite à installer une
	 * clé pour monter en gamme.
	 */
	private static function localStarter() {
		/*
		 * LICENCE OBLIGATOIRE (1.876.0, plateforme). Sans jeton, l'extension
		 * accorde l'édition Starter. En plateforme multi-clients, un espace
		 * créé ne doit rien ouvrir avant que SA licence soit installée :
		 * aucun module, et le routeur renvoie vers l'écran Licence.
		 */
		if ( self::obligatoire() ) {
			return self::$resolved = array(
				'valid' => false, 'status' => 'missing', 'profile' => null, 'profile_label' => null,
				'client' => null, 'modules' => array(), 'expires_at' => null, 'remote' => 'none',
				'caps' => array(), 'edition' => 'core', 'limits' => array(), 'pack' => '', 'packs' => array(),
				'message' => 'Licence requise : installez la clé de licence de votre société pour accéder aux modules.',
			);
		}
		$profile = 'starter';
		$mods    = array();
		if ( class_exists( 'FKC_Plans' ) && FKC_Plans::get( $profile ) ) {
			$mods = FKC_Plans::get( $profile )['modules'];
		} elseif ( class_exists( 'FKC_Modules' ) && isset( FKC_Modules::profiles()[ $profile ] ) ) {
			$mods = FKC_Modules::profiles()[ $profile ];
		}
		$state = array(
			'valid'         => true,
			'status'        => 'starter',
			'profile'       => $profile,
			'profile_label' => class_exists( 'FKC_Modules' ) ? FKC_Modules::profileLabel( $profile ) : 'Starter',
			'client'        => 'Édition Standard',
			'modules'       => array_values( $mods ),
			'expires_at'    => null,
			'remote'        => 'none',
			'caps'          => class_exists( 'FKC_Plans' ) && FKC_Plans::get( $profile ) ? FKC_Plans::get( $profile )['caps'] : array(),
			'edition'       => class_exists( 'FKC_Plans' ) ? FKC_Plans::edition( $profile ) : 'core',
			'limits'        => class_exists( 'FKC_Plans' ) ? FKC_Plans::limitsFor( $profile ) : array(),
			'pack'          => '',
			// Aucun jeton : aucun pack nommé, donc le socle « generique » seul
			// (voir FKC_Packs::autorises()).
			'packs'         => array(),
			'message'       => 'Édition Starter par défaut — installez une clé de licence pour débloquer davantage de modules et de quotas.',
		);
		// Publication anticipée : FKC_Packs relit packCode()/edition() sans récursivité.
		self::$resolved = $state;
		if ( class_exists( 'FKC_Packs' ) ) {
			$state['modules'] = array_values( array_unique( array_merge( $state['modules'], FKC_Packs::modules() ) ) );
		}
		return self::$resolved = $state;
	}

	public static function resolve() {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}
		$state = array(
			'valid' => false, 'status' => 'missing', 'profile' => null, 'profile_label' => null,
			'client' => null, 'modules' => array(), 'expires_at' => null, 'remote' => 'unknown',
			'caps' => array(), 'edition' => 'core', 'limits' => array(),
			'message' => 'Aucune licence installée.',
		);

		$raw = self::param( 'license_token' );
		$pub = self::publicKey();
		if ( ! $raw ) {
			// Aucun jeton installé : édition Starter locale (aucune clé signée
			// n'est distribuée avec le plugin).
			return self::localStarter();
		}
		if ( ! $pub ) {
			$state['status']  = 'invalid';
			$state['message'] = 'Clé publique de vérification introuvable (assets/keys/license_public.pem).';
			return self::$resolved = $state;
		}

		$tok = self::parse( $raw, $pub );
		if ( ! $tok['ok'] ) {
			$state['status'] = 'invalid'; $state['message'] = $tok['error'];
			return self::$resolved = $state;
		}
		$p = $tok['payload'];

		if ( ! empty( $p['expires_at'] ) && strtotime( $p['expires_at'] ) < time() ) {
			$state['status'] = 'expired'; $state['expires_at'] = $p['expires_at'];
			$state['message'] = 'Licence expirée le ' . date( 'd/m/Y', strtotime( $p['expires_at'] ) ) . '.';
			return self::$resolved = $state;
		}
		if ( ! empty( $p['domain'] ) ) {
			$host = strtolower( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' );
			// Hôte inconnu (CLI, tests) : on ne bloque pas sur le domaine.
			if ( $host && ! self::domainAllowed( $host, (string) $p['domain'] ) ) {
				$state['status'] = 'invalid';
				$state['message'] = 'Licence liée au domaine ' . $p['domain'] . '.';
				return self::$resolved = $state;
			}
		}

		$profile = isset( $p['profile'] ) ? $p['profile'] : 'starter';
		// Les modules du palier (profil) sont toujours accordés ; le jeton peut en ajouter.
		$base = array();
		if ( class_exists( 'FKC_Plans' ) && FKC_Plans::get( $profile ) ) {
			$base = FKC_Plans::get( $profile )['modules'];
		} elseif ( isset( FKC_Modules::profiles()[ $profile ] ) ) {
			$base = FKC_Modules::profiles()[ $profile ];
		}
		$extra = ( ! empty( $p['modules'] ) && is_array( $p['modules'] ) ) ? $p['modules'] : array();
		$mods  = array_values( array_unique( array_merge( $base, $extra ) ) );

		$remote = self::remoteCheck( isset( $p['id'] ) ? $p['id'] : '', isset( $p['domain'] ) ? $p['domain'] : '' );
		if ( 'revoked' === $remote ) {
			$state['status'] = 'revoked'; $state['message'] = 'Licence révoquée par le serveur.'; $state['remote'] = $remote;
			return self::$resolved = $state;
		}

		$state['valid'] = true; $state['status'] = 'active';
		$state['profile'] = $profile; $state['profile_label'] = FKC_Modules::profileLabel( $profile );
		$state['client'] = isset( $p['client'] ) ? $p['client'] : null;
		$state['modules'] = array_values( $mods );
		// Pack métier (« Vertical Pack ») accordé par le jeton : generique, btp,
		// creative… ou « multi » (choix laissé à la société).
		$state['pack'] = isset( $p['pack'] ) ? strtolower( (string) $p['pack'] ) : '';
		/*
		 * JEU DE PACKS AUTORISÉS (depuis 1.680.0).
		 *
		 * Jusqu'ici le jeton ne portait qu'un pack SCALAIRE. Un jeton délivré
		 * en « multi » ouvrait donc l'intégralité du catalogue livré sur
		 * disque : la société voyait — et pouvait activer — les 63 packs,
		 * quel que soit ce qu'elle avait payé. Le seul frein était un quota
		 * NUMÉRIQUE de complémentaires, jamais une liste nominative.
		 *
		 * Le bloc « packs », DANS LA CHARGE SIGNÉE, nomme les packs que
		 * l'éditeur a réellement vendus. L'extension ne propose plus rien
		 * d'autre, et REFUSE l'activation d'un pack absent de la liste (ici,
		 * masquer ne suffit pas : c'est la licence qui interdit).
		 *
		 * NON-RÉGRESSION : un jeton « multi » ANTÉRIEUR ne porte pas ce bloc.
		 * Le confondre avec une liste vide fermerait d'un coup le catalogue
		 * de tous les jetons déjà émis. Absence de bloc = comportement
		 * d'avant, signalé comme tel sur l'écran Licence.
		 */
		$state['packs'] = array();
		if ( ! empty( $p['packs'] ) && is_array( $p['packs'] ) ) {
			foreach ( $p['packs'] as $c ) {
				$c = strtolower( preg_replace( '/[^a-z0-9_]/', '', (string) $c ) );
				if ( '' !== $c && 'multi' !== $c ) { $state['packs'][] = $c; }
			}
			$state['packs'] = array_values( array_unique( $state['packs'] ) );
		}
		// Caps : ceux du palier (référentiel) complétés/écrasés par ceux du jeton.
		$planCaps = class_exists( 'FKC_Plans' ) && FKC_Plans::get( $profile ) ? FKC_Plans::get( $profile )['caps'] : array();
		$tokCaps  = ( ! empty( $p['caps'] ) && is_array( $p['caps'] ) ) ? $p['caps'] : array();
		$state['caps'] = array_merge( $planCaps, $tokCaps );
		// Édition + limites : depuis le jeton si fournies, sinon depuis le référentiel des paliers.
		$state['edition'] = isset( $p['edition'] ) ? (string) $p['edition'] : ( class_exists( 'FKC_Plans' ) ? FKC_Plans::edition( $profile ) : 'core' );
		$baseLimits = class_exists( 'FKC_Plans' ) ? FKC_Plans::limitsFor( $profile ) : array();
		$tokLimits  = ( ! empty( $p['limits'] ) && is_array( $p['limits'] ) ) ? $p['limits'] : array();
		$state['limits'] = array_merge( $baseLimits, $tokLimits );
		$state['expires_at'] = isset( $p['expires_at'] ) ? $p['expires_at'] : null;
		$state['remote'] = $remote; $state['message'] = 'Licence active.';
		/*
		 * INTERRUPTEURS DE L'ÉDITEUR (depuis 1.520.156).
		 *
		 * Jusqu'ici le jeton ne savait qu'AJOUTER : « modules » complétait le
		 * palier, jamais l'inverse. Vendre un palier amputé d'un module, ou
		 * ouvrir une fonction au-dessus de son édition, exigeait donc de
		 * fabriquer un palier sur mesure dans le code — c'est-à-dire de livrer
		 * une version pour un client.
		 *
		 * Deux blocs optionnels y remédient, tous deux DANS LA CHARGE SIGNÉE
		 * (la société ne peut donc pas les retoucher) :
		 *   - modules_off : codes de modules retirés, quoi qu'en dise le palier ;
		 *   - features    : verdict explicite par fonction (true/false), qui
		 *                   l'emporte sur le rang d'édition, dans les deux sens.
		 *
		 * Un jeton sans ces blocs se comporte EXACTEMENT comme avant.
		 */
		$state['modules_off'] = array();
		if ( ! empty( $p['modules_off'] ) && is_array( $p['modules_off'] ) ) {
			foreach ( $p['modules_off'] as $m ) {
				$m = strtolower( preg_replace( '/[^a-z0-9_.]/i', '', (string) $m ) );
				if ( '' !== $m ) { $state['modules_off'][] = $m; }
			}
			$state['modules_off'] = array_values( array_unique( $state['modules_off'] ) );
		}
		$state['features'] = array();
		if ( ! empty( $p['features'] ) && is_array( $p['features'] ) ) {
			foreach ( $p['features'] as $code => $v ) {
				$code = strtolower( preg_replace( '/[^a-z0-9_.]/i', '', (string) $code ) );
				if ( '' === $code ) { continue; }
				$state['features'][ $code ] = ( true === $v || 1 === $v || '1' === $v || 'true' === $v || 'on' === $v );
			}
		}
		// Modules apportés par le pack métier actif, fusionnés à ceux de la licence.
		// Publication anticipée de l'état : FKC_Packs relit packCode()/edition() sans récursivité.
		self::$resolved = $state;
		if ( class_exists( 'FKC_Packs' ) ) {
			$state['modules'] = array_values( array_unique( array_merge( $state['modules'], FKC_Packs::modules() ) ) );
		}
		// Le retrait s'applique EN DERNIER, après le pack : sinon l'interrupteur
		// de l'éditeur serait décoratif dès qu'un pack réapporte le module.
		if ( $state['modules_off'] ) {
			$state['modules'] = array_values( array_diff( $state['modules'], $state['modules_off'] ) );
		}
		return self::$resolved = $state;
	}

	/** Code du pack métier accordé par le jeton ('' si absent ; « multi » = choix par société). */
	public static function packCode() {
		$s = self::resolve();
		return isset( $s['pack'] ) ? (string) $s['pack'] : '';
	}

	/**
	 * Le jeton NOMME-T-IL les packs autorisés ?
	 *
	 * Distingue « aucun pack vendu » (impossible : le générateur exige au
	 * moins le principal) de « jeton antérieur au bloc packs ». Les
	 * confondre transformerait une mise à jour du plugin en fermeture
	 * silencieuse du catalogue chez tous les clients déjà servis.
	 */
	public static function packsNommes() {
		$s = self::resolve();
		return ! empty( $s['packs'] ) && is_array( $s['packs'] );
	}

	/**
	 * Packs métier explicitement autorisés par le jeton signé.
	 * Tableau VIDE = le jeton ne les nomme pas (voir packsNommes()) ; c'est
	 * FKC_Packs::autorises() qui décide alors du repli.
	 */
	public static function packsAutorises() {
		$s = self::resolve();
		return ! empty( $s['packs'] ) && is_array( $s['packs'] ) ? array_values( $s['packs'] ) : array();
	}

	/** Force une nouvelle résolution (ex. licence « multi » : le pack choisi par la société devient lisible une fois sa base branchée). */
	public static function reresolve() {
		self::$resolved = null;
		return self::resolve();
	}

	public static function moduleEnabled( $code ) {
		$s = self::resolve();
		// Garde : une résolution partielle (cache vidé en cours de requête,
		// bascule de société) ne doit pas produire un avertissement PHP ni,
		// pire, laisser passer un module. En cas de doute, on ferme.
		if ( ! is_array( $s ) || empty( $s['valid'] ) || empty( $s['modules'] ) || ! is_array( $s['modules'] ) ) { return false; }
		// Un sous-module est activé si le module parent est couvert par la licence.
		if ( class_exists( 'FKC_Modules' ) && FKC_Modules::isSubModule( $code ) ) {
			$code = FKC_Modules::parentOf( $code );
		}
		return in_array( $code, $s['modules'], true );
	}

	/** Capacités optionnelles accordées par le jeton (bloc « caps »). */
	public static function caps() {
		$s = self::resolve();
		return ! empty( $s['caps'] ) && is_array( $s['caps'] ) ? $s['caps'] : array();
	}

	/**
	 * Verdicts explicites de l'éditeur sur les FONCTIONNALITÉS (bloc « features »
	 * du jeton). code => bool. Vide = aucun interrupteur, le rang d'édition
	 * décide seul, exactement comme avant.
	 */
	public static function featureOverrides() {
		$s = self::resolve();
		return ! empty( $s['features'] ) && is_array( $s['features'] ) ? $s['features'] : array();
	}

	/** Cette fonctionnalité porte-t-elle un verdict explicite de l'éditeur ? */
	public static function featureForced( $code ) {
		return array_key_exists( (string) $code, self::featureOverrides() );
	}

	/**
	 * Verdict explicite pour une fonctionnalité, ou null s'il n'y en a pas.
	 * On distingue « pas d'avis » de « désactivé » : les confondre ferait de
	 * l'absence d'interrupteur une interdiction, et couperait toutes les
	 * fonctions de tous les jetons déjà émis.
	 */
	public static function featureVerdict( $code ) {
		$o = self::featureOverrides();
		return array_key_exists( (string) $code, $o ) ? (bool) $o[ (string) $code ] : null;
	}

	/** Modules explicitement retirés par l'éditeur, malgré le palier. */
	public static function modulesOff() {
		$s = self::resolve();
		return ! empty( $s['modules_off'] ) && is_array( $s['modules_off'] ) ? $s['modules_off'] : array();
	}

	/** Valeur d'une capacité ; $default si absente. */
	public static function cap( $name, $default = false ) {
		$c = self::caps();
		return array_key_exists( $name, $c ) ? $c[ $name ] : $default;
	}

	/**
	 * La société est-elle autorisée à GÉRER ses clés API (créer/révoquer) ?
	 * Bloqué par défaut : seul un jeton délivré par KOPHI'S GROUP avec
	 * caps.api_keys = true (ou "unlocked") débloque l'écran. Une constante
	 * FKC_API_KEYS_UNLOCKED permet de forcer le déblocage (instance éditeur).
	 */
	public static function apiKeysAllowed() {
		if ( defined( 'FKC_API_KEYS_UNLOCKED' ) && FKC_API_KEYS_UNLOCKED ) { return true; }
		$v = self::cap( 'api_keys', false );
		return ( true === $v || 1 === $v || '1' === $v || 'unlocked' === $v || 'true' === $v );
	}

	/** La licence est-elle exigée avant tout accès (FKC_LICENSE_OBLIGATOIRE) ? */
	public static function obligatoire() {
		return defined( 'FKC_LICENSE_OBLIGATOIRE' ) && FKC_LICENSE_OBLIGATOIRE;
	}

	/**
	 * Espace verrouillé : licence exigée et aucune licence ACTIVE (absente,
	 * invalide, expirée, révoquée, liée à un autre domaine).
	 */
	public static function verrouille() {
		if ( ! self::obligatoire() ) { return false; }
		$s = self::resolve();
		return ! is_array( $s ) || 'active' !== ( $s['status'] ?? '' ) || empty( $s['valid'] );
	}

	/** Édition (profil) courante ; « starter » par défaut si aucune licence valide. */
	public static function profile() {
		$s = self::resolve();
		return ! empty( $s['profile'] ) ? $s['profile'] : 'starter';
	}

	/** Édition : 'core' ou 'creative'. */
	public static function edition() {
		$s = self::resolve();
		return ! empty( $s['edition'] ) ? $s['edition'] : 'core';
	}
	/** Libellé d'édition : « CORE » ou « CREATIVE SUITE ». */
	public static function editionLabel() {
		return class_exists( 'FKC_Plans' ) ? FKC_Plans::editionLabel( self::edition() ) : strtoupper( self::edition() );
	}

	/** Toutes les limites (quotas) de la licence courante. */
	public static function limits() {
		$s = self::resolve();
		if ( ! empty( $s['limits'] ) ) { return $s['limits']; }
		return class_exists( 'FKC_Plans' ) ? FKC_Plans::limitsFor( self::profile() ) : array();
	}
	/** Limite d'un quota ($default si non défini). -1 = illimité. */
	public static function limit( $key, $default = -1 ) {
		$l = self::limits();
		return array_key_exists( $key, $l ) ? (int) $l[ $key ] : (int) $default;
	}
	public static function isUnlimited( $key ) {
		return self::limit( $key ) < 0;
	}
	/**
	 * Peut-on créer un élément de plus pour ce quota ?
	 * $current = nombre d'éléments déjà existants. Sans licence valide, on
	 * applique malgré tout la limite du palier « starter » par prudence.
	 */
	public static function within( $key, $current ) {
		$max = self::limit( $key );
		if ( $max < 0 ) { return true; }            // illimité
		return (int) $current < $max;
	}
	/** Nombre restant (PHP_INT_MAX si illimité). */
	public static function remaining( $key, $current ) {
		$max = self::limit( $key );
		if ( $max < 0 ) { return PHP_INT_MAX; }
		return max( 0, $max - (int) $current );
	}

	/** Message standard « limite atteinte » pour un quota donné. */
	public static function quotaMessage( $key, $label ) {
		$s = self::resolve();
		$palier = ! empty( $s['profile_label'] ) ? $s['profile_label'] : 'Starter';
		return 'Limite atteinte : votre licence ' . self::editionLabel() . ' « ' . $palier . ' » autorise '
			. self::limit( $key ) . ' ' . $label . '. Passez à un palier supérieur pour en créer davantage.';
	}

	public static function install( $raw ) {
		$raw = trim( $raw );
		$pub = self::publicKey();
		if ( ! $pub ) { return array( false, 'Clé publique absente.' ); }
		$tok = self::parse( $raw, $pub );
		if ( ! $tok['ok'] ) { return array( false, $tok['error'] ); }
		self::setParam( 'license_token', $raw );
		self::setParam( 'license_remote', '' );
		self::$resolved = null;
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( 'license_install', 'Profil : ' . ( $tok['payload']['profile'] ?? '?' ) ); }
		return array( true, 'Licence installée avec succès.' );
	}

	/* -------- interne -------- */

	/**
	 * Clé publique de vérification embarquée.
	 *
	 * Rendue publique pour que FKC_ApiKey puisse vérifier la signature des
	 * réponses du service d'autorisation avec la même autorité que les jetons
	 * de licence.
	 */
	public static function publicKey() {
		$path = dirname( FKC_ROOT ) . '/assets/keys/license_public.pem';
		return is_readable( $path ) ? file_get_contents( $path ) : '';
	}

	protected static function parse( $raw, $pub ) {
		$out = array( 'ok' => false, 'payload' => array(), 'error' => '' );
		if ( false === strpos( $raw, '.' ) ) { $out['error'] = 'Format de jeton invalide.'; return $out; }
		list( $b64, $b64sig ) = explode( '.', $raw, 2 );
		$data = json_decode( self::b64d( $b64 ), true );
		if ( ! is_array( $data ) ) { $out['error'] = 'Charge utile illisible.'; return $out; }
		$out['payload'] = $data;
		if ( ! function_exists( 'openssl_verify' ) ) { $out['error'] = 'OpenSSL indisponible.'; return $out; }
		$key = openssl_pkey_get_public( $pub );
		if ( false === $key ) { $out['error'] = 'Clé publique invalide.'; return $out; }
		$out['ok'] = ( 1 === openssl_verify( $b64, self::b64d( $b64sig ), $key, OPENSSL_ALGO_SHA256 ) );
		if ( ! $out['ok'] ) { $out['error'] = 'Signature de licence invalide.'; }
		return $out;
	}

	/** Point de terminaison de vérification : serveur configuré (réglages) ou serveur par défaut. */
	protected static function serverUrl() {
		$base = ( defined( 'FKC_LICENSE_SERVER' ) && FKC_LICENSE_SERVER ) ? rtrim( (string) FKC_LICENSE_SERVER, '/' ) : '';
		return $base ? $base . '/verify' : self::SERVER_URL;
	}

	/**
	 * Verdict distant — LU DANS LE CACHE, JAMAIS APPELÉ D'ICI.
	 *
	 * ─────────────────────────────────────────────────────────────────────
	 * DEUX DÉFAUTS CORRIGÉS EN 1.711.0
	 *
	 * 1. L'APPEL PARTAIT D'ICI, donc au milieu de la requête d'un
	 *    utilisateur. Cinq secondes d'attente pour un serveur qui ne répond
	 *    pas, une fois par semaine et par société, sur un hébergement
	 *    mutualisé — pour une information qui ne concerne pas la personne
	 *    qui attend. La consultation passe désormais par la file différée
	 *    (FKC_License_Depot), et resolve() ne fait plus que lire.
	 *
	 * 2. LA RÉPONSE N'ÉTAIT PAS SIGNÉE. Un simple {"status":"active"} était
	 *    cru sur parole : une ligne dans le fichier hosts du serveur, un
	 *    proxy d'entreprise, et la révocation était annulée. Retournable
	 *    aussi contre un client, en répondant « revoked ». Le verdict est
	 *    maintenant signé avec une clé dédiée, vérifiée par FKC_License_Depot.
	 * ─────────────────────────────────────────────────────────────────────
	 */
	protected static function remoteCheck( $id, $domain ) {
		if ( ! $id ) { return 'skipped'; }
		$cache = self::param( 'license_remote' );
		$last  = (int) self::param( 'license_remote_at', 0 );
		if ( ! $cache ) { return $last ? 'grace' : 'offline'; }
		/*
		 * UNE RÉVOCATION NE SE LÈVE PAS EN DÉBRANCHANT LE CÂBLE : elle reste
		 * en cache jusqu'à ce qu'un verdict signé plus récent dise le
		 * contraire. Un « active » périmé, lui, retombe en grâce — et la
		 * grâce n'a jamais bloqué personne.
		 */
		if ( 'revoked' === $cache ) { return 'revoked'; }
		if ( $last && ( time() - $last ) > ( self::CHECK_INTERVAL + self::GRACE ) ) { return 'grace'; }
		return $cache;
	}

	/** Force une revérification distante au prochain resolve() (vide le cache). */
	public static function forceRemote() {
		self::setParam( 'license_remote_at', '0' );
		self::$resolved = null;
	}

	protected static function b64d( $t ) {
		$pad = strlen( $t ) % 4;
		if ( $pad ) { $t .= str_repeat( '=', 4 - $pad ); }
		return base64_decode( strtr( $t, '-_', '+/' ) );
	}

	/**
	 * L'hôte est-il autorisé pour le domaine lié par la licence ?
	 *
	 * Par défaut : comportement HISTORIQUE permissif — l'hôte est accepté s'il
	 * CONTIENT le domaine (correspondance de sous-chaîne). C'est ce qui permet
	 * à une même clé de fonctionner sur prod, test-… et staging sans régénérer
	 * de jeton. Pour verrouiller strictement (domaine exact + vrais sous-domaines
	 * uniquement), définir la constante FKC_LICENSE_STRICT_DOMAIN à true.
	 */
	protected static function domainAllowed( $host, $domain ) {
		if ( defined( 'FKC_LICENSE_STRICT_DOMAIN' ) && FKC_LICENSE_STRICT_DOMAIN ) {
			return self::hostMatchesDomain( $host, $domain );
		}
		// Comportement historique : la licence passe si l'hôte contient le domaine.
		return '' !== trim( (string) $domain ) && false !== stripos( (string) $host, (string) $domain );
	}

	/**
	 * Correspondance STRICTE hôte ↔ domaine : domaine exact OU vrai sous-domaine.
	 *
	 * Accepte « acme.com » et « caisse.acme.com », refuse « notacme.com » et
	 * « acme.com.pirate.com ». Le préfixe « www. » et le port sont ignorés.
	 * Utilisée uniquement lorsque FKC_LICENSE_STRICT_DOMAIN est activée.
	 *
	 * @param string $host   Hôte de la requête (ex. $_SERVER['HTTP_HOST']).
	 * @param string $domain Domaine inscrit dans le jeton.
	 * @return bool
	 */
	public static function hostMatchesDomain( $host, $domain ) {
		$norm = static function ( $h ) {
			$h = strtolower( trim( (string) $h ) );
			$h = (string) preg_replace( '#^https?://#', '', $h ); // schéma éventuel
			$h = explode( '/', $h, 2 )[0];                        // chemin éventuel
			$h = explode( ':', $h, 2 )[0];                        // port éventuel
			$h = preg_replace( '/^www\./', '', $h );              // www. non significatif
			return trim( $h, '.' );
		};
		$host   = $norm( $host );
		$domain = $norm( $domain );
		if ( '' === $host || '' === $domain ) { return false; }
		if ( $host === $domain ) { return true; }
		// Vrai sous-domaine uniquement : l'hôte se termine par « .domaine ».
		return str_ends_with( $host, '.' . $domain );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	/** Compat. PHP < 8.0. */
	function str_ends_with( $haystack, $needle ) {
		return '' === $needle || ( strlen( $haystack ) >= strlen( $needle )
			&& 0 === substr_compare( $haystack, $needle, -strlen( $needle ) ) );
	}
}
