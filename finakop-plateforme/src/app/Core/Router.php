<?php
/** Routeur basé sur le chemin (l'app occupe la racine du sous-domaine). @package FinaKop_ERP_Core */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Router {

	protected $routes = array();

	/**
	 * Registre STATIQUE des chemins déclarés, par méthode.
	 *
	 * POURQUOI IL EXISTE
	 *
	 * Le moteur UX (FKC_Ux) compose des menus à partir d'intentions métier,
	 * chacune portant plusieurs écrans candidats. Il lui faut savoir lequel
	 * existe RÉELLEMENT pour l'installation en cours : les routes des packs
	 * sont déclarées conditionnellement, un même pack n'ouvre pas les mêmes
	 * écrans selon son manifest, et une entrée de menu qui ouvrirait un 404
	 * coûte plus cher que son absence.
	 *
	 * Deux index : les chemins LITTÉRAUX dans une table associative (la quasi-
	 * totalité des cas, résolus sans expression régulière), les chemins
	 * PARAMÉTRÉS à part. Sur huit cents routes, la différence se voit.
	 *
	 * Ce registre ne donne AUCUN droit : il dit qu'un écran existe, jamais
	 * qu'on peut l'ouvrir. Les gardes du dispatch restent seules juges.
	 *
	 * @var array<string,array<string,bool>>
	 */
	protected static $litterales = array();
	/** @var array<string,array<int,string>> chemins paramétrés, compilés */
	protected static $motifs = array();

	public function get( $path, $handler, $opt = array() )  { $this->add( 'GET', $path, $handler, $opt ); }
	public function post( $path, $handler, $opt = array() ) { $this->add( 'POST', $path, $handler, $opt ); }

	protected function add( $method, $path, $handler, $opt ) {
		$this->routes[] = array(
			'method'  => $method,
			'regex'   => $this->compile( $path ),
			'handler' => $handler,
			'opt'     => $opt,
		);
		$p = trim( (string) $path, '/' );
		if ( false === strpos( $p, '{' ) ) { self::$litterales[ $method ][ $p ] = true; }
		else { self::$motifs[ $method ][] = $this->compile( $path ); }
	}

	/** Ce chemin est-il servi par une route déclarée ? (existence, pas droit) */
	public static function connue( $path, $method = 'GET' ) {
		$p = trim( (string) $path, '/' );
		$p = preg_replace( '~[?#].*$~', '', $p );
		$p = trim( preg_replace( '#/+#', '/', $p ), '/' );
		if ( isset( self::$litterales[ $method ][ $p ] ) ) { return true; }
		foreach ( (array) ( self::$motifs[ $method ] ?? array() ) as $rx ) {
			if ( preg_match( $rx, $p ) ) { return true; }
		}
		return false;
	}

	/** Chemins littéraux déclarés (diagnostic, écran d'aperçu UX, tests). */
	public static function litterales( $method = 'GET' ) {
		return array_keys( (array) ( self::$litterales[ $method ] ?? array() ) );
	}

	/** Remise à zéro du registre (tests). */
	public static function oublierRegistre() { self::$litterales = array(); self::$motifs = array(); }

	protected function compile( $path ) {
		$path = trim( $path, '/' );
		$rx   = preg_replace( '#\{[a-z_]+\}#i', '([^/]+)', $path );
		return '#^' . $rx . '$#';
	}

	/** Chemin courant relatif à la racine de l'application (gère le mode « chemin »). */
	public function currentPath() {
		if ( function_exists( 'fkc_rel_path' ) ) { return fkc_rel_path(); }
		$uri = parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
		return trim( (string) $uri, '/' );
	}

	public function dispatch() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$path   = $this->currentPath();

		foreach ( $this->routes as $r ) {
			if ( $r['method'] !== $method ) { continue; }
			if ( ! preg_match( $r['regex'], $path, $m ) ) { continue; }
			array_shift( $m );

			// Middleware : authentification.
			if ( ! empty( $r['opt']['auth'] ) && ! FKC_Auth::check() ) {
				redirect( 'login' );
			}
			/*
			 * Middleware : changement de mot de passe imposé.
			 *
			 * Le compte administrateur créé à l'installation porte un mot de
			 * passe à usage unique, et tout compte détecté avec l'ancien mot de
			 * passe par défaut est marqué de même. Aucune route — hors la page
			 * de changement elle-même et la déconnexion — n'est servie avant
			 * que ce mot de passe ait été remplacé. C'est ce verrou qui rend le
			 * secret d'installation réellement à usage unique.
			 */
			if ( ! empty( $r['opt']['auth'] ) && empty( $r['opt']['sans_mdp'] ) && FKC_Auth::doitChangerMotDePasse() ) {
				redirect( 'mot-de-passe' );
			}
			// Middleware : une société doit être active (routes métier).
			if ( ! empty( $r['opt']['societe'] ) && ! FKC_Tenant::ensureActive() ) {
				redirect( 'societes' );
			}
			/*
			 * Le pack métier est un paramètre de la SOCIÉTÉ. Il ne devient donc
			 * lisible qu'ici : à l'amorçage, FKC_Packs::boot() interroge le
			 * registre du cabinet — la seule base branchée à ce moment — n'y
			 * trouve rien, et retient « generique ».
			 *
			 * Cette re-résolution existait (FKC_Packs::ensureSociete) mais
			 * n'était appelée que depuis les écrans des packs eux-mêmes. Sur
			 * toute autre route — barre latérale, tableau de bord, comptabilité,
			 * et jusqu'à l'écran de choix du pack — l'application lisait donc
			 * « generique » : activer un pack affichait « activé » et ne changeait
			 * rien à l'écran suivant.
			 *
			 * L'appel est fait ici parce que c'est le seul endroit où la base de
			 * la société vient d'être branchée et par lequel toutes les routes
			 * passent.
			 */
			if ( ! empty( $r['opt']['societe'] ) && class_exists( 'FKC_Packs' ) ) {
				FKC_Packs::ensureSociete();
			}
			// Middleware : réservé à l'administrateur.
			if ( ! empty( $r['opt']['admin'] ) && ! FKC_Auth::isAdmin() ) {
				http_response_code( 403 );
				return FKC_View::render( 'errors.denied', array( 'reason' => 'admin' ) );
			}
			// Middleware : module activé par la licence.
			if ( ! empty( $r['opt']['module'] ) && ! FKC_License::moduleEnabled( $r['opt']['module'] ) ) {
				return FKC_View::render( 'errors.module', array( 'module' => $r['opt']['module'] ) );
			}
			/*
			 * Module de la route courante : positionné AVANT l'appel du
			 * contrôleur pour que FKC_Packs::verticalModule()/verticalConfig()
			 * et FKC_Term::t() puissent servir le pack COMPLÉMENTAIRE réellement
			 * demandé (multi-pack) plutôt que systématiquement le principal —
			 * voir FKC_Packs::requestModule(). Sans effet quand aucun
			 * complémentaire n'est actif : résout alors au même pack qu'avant.
			 */
			if ( class_exists( 'FKC_Packs' ) ) {
				FKC_Packs::setRequestModule( $r['opt']['module'] ?? null );
			}
			// Middleware : autorisation de l'utilisateur sur ce module.
			if ( ! empty( $r['opt']['module'] ) && FKC_Auth::check() && ! FKC_Auth::can( $r['opt']['module'] ) ) {
				http_response_code( 403 );
				return FKC_View::render( 'errors.denied', array( 'reason' => 'module', 'module' => $r['opt']['module'] ) );
			}
			// Middleware : capacité de licence requise (cap avancée).
			if ( ! empty( $r['opt']['cap'] ) && ! FKC_License::cap( $r['opt']['cap'] ) ) {
				http_response_code( 403 );
				return FKC_View::render( 'errors.denied', array( 'reason' => 'cap', 'cap' => $r['opt']['cap'] ) );
			}
			// CSRF sur POST (sauf routes publiques : webhooks signés, vérifiés côté serveur).
			if ( 'POST' === $method && empty( $r['opt']['public'] ) && ! FKC_Csrf::verify() ) {
				// 403 et non 419 (1.876.0) : 419 n'est pas un code HTTP standard ; Apache,
				// ne lui connaissant pas de libellé, l'envoyait au navigateur comme « 500 ».
				http_response_code( 403 );
				$msg = 'Jeton de sécurité invalide ou session expirée. Rechargez la page et réessayez.';
				// Requête fetch/XHR : répondre en JSON, sinon r.json() échoue côté
				// client et l'utilisateur voit un trompeur « Erreur réseau ».
				$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
				$xhr    = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( (string) $_SERVER['HTTP_X_REQUESTED_WITH'] );
				if ( $xhr || false !== stripos( $accept, 'application/json' ) || isset( $_SERVER['HTTP_X_CSRF_TOKEN'] ) ) {
					if ( ! headers_sent() ) { header( 'Content-Type: application/json; charset=utf-8' ); }
					echo json_encode( array( 'ok' => false, 'message' => $msg, 'csrf' => true ), JSON_UNESCAPED_UNICODE );
					return;
				}
				echo $msg;
				return;
			}
			return $this->call( $r['handler'], $m );
		}

		http_response_code( 404 );
		FKC_View::render( 'errors.404', array() );
	}

	protected function call( $handler, $args ) {
		if ( is_callable( $handler ) ) {
			return call_user_func_array( $handler, $args );
		}
		list( $class, $action ) = explode( '@', $handler );
		$obj = new $class();
		return call_user_func_array( array( $obj, $action ), $args );
	}
}
