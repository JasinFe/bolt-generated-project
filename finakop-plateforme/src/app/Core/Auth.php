<?php
/**
 * Authentification par session — comptes du cabinet (registre central).
 * Module Multi-sociétés : un même identifiant donne accès aux sociétés
 * autorisées ; le contrôle d'accès par module reste inchangé.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Auth {

	/** Durée d'inactivité au-delà de laquelle la session est fermée (secondes). */
	const INACTIVITE_MAX = 3600;      // 1 h

	/** Durée de vie absolue d'une session, quelle que soit l'activité (secondes). */
	const DUREE_ABSOLUE_MAX = 43200;  // 12 h

	/**
	 * Ligne utilisateur mémoïsée pour la requête courante.
	 *
	 * user() était appelée par isAdmin(), allowedModules(), rawGrants(), can()
	 * et canSection() — soit une requête SQL par entrée de barre latérale et
	 * par middleware de route, plusieurs dizaines par page. Le cache est
	 * invalidé par attempt(), logout() et oublier().
	 *
	 * @var array|false|null null = non chargé, false = introuvable
	 */
	protected static $cache = null;

	/** Identifiant auquel correspond le cache (garde contre un changement d'utilisateur en cours de requête). */
	protected static $cacheUid = null;

	public static function check() { return ! empty( $_SESSION['fkc_uid'] ); }

	public static function id() { return self::check() ? (int) $_SESSION['fkc_uid'] : null; }

	/** Vide le cache utilisateur (connexion, déconnexion, modification du compte courant). */
	public static function oublier() {
		self::$cache    = null;
		self::$cacheUid = null;
	}

	public static function user() {
		if ( ! self::check() ) { return null; }
		$uid = self::id();
		if ( null !== self::$cache && self::$cacheUid === $uid ) {
			return self::$cache ?: null;
		}
		$row = FKC_Master::q(
			'SELECT id,login,nom_complet,email,role,role_libelle,modules,cockpits,mode_ui,doit_changer,pwd_changed_at
			 FROM cabinet_users WHERE id=? AND actif=1',
			array( $uid )
		)->fetch();
		self::$cacheUid = $uid;
		self::$cache    = $row ?: false;
		return $row ?: null;
	}

	/** Le compte courant doit-il changer son mot de passe avant toute autre action ? */
	public static function doitChangerMotDePasse() {
		$u = self::user();
		return $u && ! empty( $u['doit_changer'] );
	}

	/**
	 * Contrôles de session à effectuer une fois par requête, avant le routage.
	 *
	 * - inactivité et durée absolue : une session oubliée sur un poste partagé
	 *   ne devait pas rester ouverte indéfiniment ;
	 * - empreinte du mot de passe : une réinitialisation ferme désormais les
	 *   sessions ouvertes avant elle (un compte compromis restait connecté
	 *   après changement du mot de passe).
	 *
	 * @return bool false si la session a été fermée.
	 */
	public static function enforceSession() {
		if ( ! self::check() ) { return true; }
		$now = time();

		$debut = isset( $_SESSION['fkc_login_at'] ) ? (int) $_SESSION['fkc_login_at'] : 0;
		$vue   = isset( $_SESSION['fkc_last_seen'] ) ? (int) $_SESSION['fkc_last_seen'] : 0;

		if ( $debut && ( $now - $debut ) > self::DUREE_ABSOLUE_MAX ) {
			self::fermer( 'session_absolue' );
			return false;
		}
		if ( $vue && ( $now - $vue ) > self::INACTIVITE_MAX ) {
			self::fermer( 'session_inactivite' );
			return false;
		}

		$u = self::user();
		if ( ! $u ) { // compte supprimé ou désactivé pendant la session
			self::fermer( 'compte_inactif' );
			return false;
		}
		$attendu = self::empreinteMotDePasse( $u['id'] );
		if ( $attendu && ! empty( $_SESSION['fkc_pwd_stamp'] )
			&& ! hash_equals( $attendu, (string) $_SESSION['fkc_pwd_stamp'] ) ) {
			self::fermer( 'mot_de_passe_change' );
			return false;
		}

		$_SESSION['fkc_last_seen'] = $now;
		return true;
	}

	/** Ferme la session courante en journalisant la raison. */
	protected static function fermer( $raison ) {
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( 'session_fermee', $raison ); }
		unset( $_SESSION['fkc_uid'], $_SESSION['fkc_login_at'], $_SESSION['fkc_last_seen'], $_SESSION['fkc_pwd_stamp'] );
		if ( class_exists( 'FKC_Tenant' ) ) { unset( $_SESSION[ FKC_Tenant::SESSION_KEY ] ); }
		self::oublier();
		if ( PHP_SESSION_ACTIVE === session_status() ) { session_regenerate_id( true ); }
	}

	/**
	 * Empreinte courte du hachage de mot de passe, servant de sceau de session.
	 * Ne divulgue rien d'exploitable : le hachage bcrypt lui-même n'est pas
	 * écrit en session.
	 */
	protected static function empreinteMotDePasse( $userId ) {
		try {
			$h = FKC_Master::q( 'SELECT password_hash FROM cabinet_users WHERE id=?', array( (int) $userId ) )->fetchColumn();
		} catch ( \Throwable $e ) { return ''; }
		return $h ? substr( hash( 'sha256', (string) $h ), 0, 32 ) : '';
	}

	/** L'utilisateur courant est-il administrateur du cabinet ? */
	public static function isAdmin() {
		$u = self::user();
		return $u && 'admin' === $u['role'];
	}

	/**
	 * Codes des rôles GÉNÉRIQUES (FKC_RoleEngine) attribués à l'utilisateur
	 * courant DANS LA SOCIÉTÉ ACTIVE. Liste vide pour un compte non migré
	 * (rien d'attribué) : ce n'est pas une anomalie, voir FKC_RoleEngine::peut().
	 */
	public static function roles() {
		if ( ! self::check() || ! class_exists( 'FKC_RoleEngine' ) ) { return array(); }
		try { return FKC_RoleEngine::rolesCodes( self::id() ); }
		catch ( \Throwable $e ) { return array(); } // base société pas encore branchée
	}

	/** L'utilisateur courant porte-t-il ce rôle générique (quel qu'en soit le périmètre) ? */
	public static function hasRole( $code ) {
		return in_array( (string) $code, self::roles(), true );
	}

	/** Modules auxquels l'utilisateur courant a accès (admin = tous ceux de la licence). */
	public static function allowedModules() {
		$u = self::user();
		if ( ! $u ) { return array(); }
		$licence = FKC_License::resolve();
		$dispo = $licence['modules'] ?? array();
		if ( 'admin' === $u['role'] ) { return $dispo; }
		$perso = $u['modules'] ? json_decode( $u['modules'], true ) : array();
		if ( ! is_array( $perso ) ) { $perso = array(); }
		return array_values( array_intersect( $dispo, $perso ) );
	}

	/** Grants explicites de l'utilisateur (codes bruts stockés), avant intersection licence. */
	protected static function rawGrants() {
		$u = self::user();
		if ( ! $u ) { return array(); }
		$p = $u['modules'] ? json_decode( $u['modules'], true ) : array();
		return is_array( $p ) ? $p : array();
	}

	/** L'utilisateur courant peut-il accéder à un module ou sous-module ? */
	public static function can( $module ) {
		if ( self::isAdmin() ) { return true; }
		// Sous-module : licence du parent requise, puis grant explicite OU héritage du parent.
		if ( class_exists( 'FKC_Modules' ) && FKC_Modules::isSubModule( $module ) ) {
			$parent = FKC_Modules::parentOf( $module );
			if ( ! FKC_License::moduleEnabled( $parent ) ) { return false; }
			$grants = self::rawGrants();
			if ( in_array( $module, $grants, true ) ) { return true; }
			// Héritage : si le parent est accordé et qu'AUCUN sous-module n'est explicitement coché → accès complet (compatibilité).
			$hasParent = in_array( $parent, $grants, true );
			$hasAnySub = (bool) array_intersect( FKC_Modules::subModulesOf( $parent ), $grants );
			return $hasParent && ! $hasAnySub;
		}
		return in_array( $module, self::allowedModules(), true );
	}

	/** Peut-il accéder à la SECTION d'un module (parent OU au moins un sous-module) ? Pour la barre latérale et le lanceur. */
	public static function canSection( $module ) {
		if ( self::isAdmin() ) { return true; }
		if ( self::can( $module ) ) { return true; }
		if ( class_exists( 'FKC_Modules' ) ) {
			foreach ( FKC_Modules::subModulesOf( $module ) as $sm ) {
				if ( self::can( $sm ) ) { return true; }
			}
		}
		return false;
	}

	public static function attempt( $login, $password ) {
		$ip = class_exists( 'FKC_Security' ) ? FKC_Security::clientIp() : '';
		if ( class_exists( 'FKC_Security' ) && FKC_Security::loginBlocked( $ip, $login ) ) {
			return false; // trop de tentatives : compte/IP temporairement bloqué
		}
		$row = FKC_Master::q( 'SELECT * FROM cabinet_users WHERE login=? AND actif=1', array( $login ) )->fetch();
		if ( ! $row || ! password_verify( $password, $row['password_hash'] ) ) {
			if ( class_exists( 'FKC_Security' ) ) { FKC_Security::loginFailed( $ip, $login ); }
			return false;
		}
		/*
		 * Mot de passe par défaut publié : la connexion est refusée même si le
		 * mot de passe est « correct ». Marquer le compte « doit changer » ne
		 * suffisait pas — celui qui connaît le mot de passe publié aurait pu
		 * atteindre l'écran de changement et s'approprier le compte avant son
		 * propriétaire. Le déblocage passe par l'administrateur du site
		 * WordPress (Réglages → FinaKop ERP Core).
		 */
		if ( ! empty( $row['mdp_compromis'] ) ) {
			if ( class_exists( 'FKC_Security' ) ) {
				FKC_Security::audit( 'login_refuse_mdp_compromis', 'login=' . $login, (int) $row['id'] );
			}
			return false;
		}
		// Remise à niveau du hachage si le coût par défaut de PHP a évolué :
		// sans cela, des comptes anciens conservent indéfiniment un hachage
		// calibré pour du matériel obsolète.
		if ( password_needs_rehash( $row['password_hash'], PASSWORD_DEFAULT ) ) {
			try {
				FKC_Master::q(
					'UPDATE cabinet_users SET password_hash=? WHERE id=?',
					array( password_hash( $password, PASSWORD_DEFAULT ), $row['id'] )
				);
			} catch ( \Throwable $e ) { /* sans conséquence sur la connexion */ }
		}

		/*
		 * Double authentification (1.876.2) : le mot de passe est juste, mais
		 * la session n'est PAS ouverte. Elle le sera par finaliserDeuxFacteurs()
		 * après le code de l'application. Jusque-là, check() reste faux partout.
		 */
		if ( class_exists( 'FKC_DeuxFacteurs' ) && FKC_DeuxFacteurs::actif( (int) $row['id'] ) ) {
			if ( PHP_SESSION_ACTIVE === session_status() ) { session_regenerate_id( true ); }
			unset( $_SESSION['fkc_csrf'] );
			$_SESSION['fkc_2fa_attente'] = array( 'uid' => (int) $row['id'], 'login' => (string) $login, 't' => time(), 'essais' => 0 );
			if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( '2fa_demande', 'login=' . $login, (int) $row['id'] ); }
			return 'deux_facteurs';
		}
		self::ouvrirSession( $row, $login, $ip );
		return true;
	}

	/** Second facteur en attente (mot de passe déjà vérifié) ? */
	public static function deuxFacteursEnAttente() {
		$a = $_SESSION['fkc_2fa_attente'] ?? null;
		if ( ! is_array( $a ) ) { return null; }
		if ( time() - (int) $a['t'] > 300 ) { unset( $_SESSION['fkc_2fa_attente'] ); return null; } // 5 minutes
		return $a;
	}

	/**
	 * Termine une connexion à deux facteurs.
	 * @return array{0:bool,1:string}
	 */
	public static function finaliserDeuxFacteurs( $saisie ) {
		$a = self::deuxFacteursEnAttente();
		if ( ! $a ) { return array( false, 'Délai dépassé : reconnectez-vous.' ); }
		$ip = class_exists( 'FKC_Security' ) ? FKC_Security::clientIp() : '';
		if ( class_exists( 'FKC_Security' ) && FKC_Security::loginBlocked( $ip, $a['login'] ) ) {
			unset( $_SESSION['fkc_2fa_attente'] );
			return array( false, 'Trop de tentatives. Réessayez dans quelques minutes.' );
		}
		$type = FKC_DeuxFacteurs::verifier( (int) $a['uid'], $saisie );
		if ( false === $type ) {
			$_SESSION['fkc_2fa_attente']['essais'] = (int) $a['essais'] + 1;
			if ( class_exists( 'FKC_Security' ) ) { FKC_Security::loginFailed( $ip, $a['login'] ); }
			if ( $_SESSION['fkc_2fa_attente']['essais'] >= 5 ) {
				unset( $_SESSION['fkc_2fa_attente'] );
				return array( false, 'Trop de codes incorrects : reconnectez-vous.' );
			}
			return array( false, 'Code incorrect.' );
		}
		$row = FKC_Master::q( 'SELECT * FROM cabinet_users WHERE id=? AND actif=1', array( (int) $a['uid'] ) )->fetch();
		unset( $_SESSION['fkc_2fa_attente'] );
		if ( ! $row ) { return array( false, 'Compte indisponible.' ); }
		self::ouvrirSession( $row, $a['login'], $ip );
		return array( true, 'secours' === $type ? 'Connecté avec un code de secours : il ne pourra plus servir. Il vous en reste ' . FKC_DeuxFacteurs::secoursRestants( (int) $row['id'] ) . '.' : '' );
	}

	/** Ouvre la session d'un compte authentifié (mot de passe, et 2FA s'il y a lieu). */
	protected static function ouvrirSession( $row, $login, $ip ) {
		if ( PHP_SESSION_ACTIVE === session_status() ) { session_regenerate_id( true ); }
		self::oublier();
		$_SESSION['fkc_uid']       = (int) $row['id'];
		$_SESSION['fkc_login_at']  = time();
		$_SESSION['fkc_last_seen'] = time();
		$_SESSION['fkc_pwd_stamp'] = self::empreinteMotDePasse( $row['id'] );
		// Le jeton anti-CSRF est renouvelé avec la session : un jeton hérité
		// d'une session anonyme ne doit pas rester valide après connexion.
		unset( $_SESSION['fkc_csrf'] );
		FKC_Master::q( "UPDATE cabinet_users SET derniere_cnx=datetime('now','localtime') WHERE id=?", array( $row['id'] ) );
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::loginOk( $ip, $login ); }
	}

	/**
	 * Change le mot de passe du compte courant et lève le drapeau « doit
	 * changer ». Resscelle la session en cours pour que l'utilisateur ne soit
	 * pas déconnecté par son propre changement, tout en fermant les autres.
	 *
	 * @return array{bool, string} ( bool ok, string message )
	 */
	public static function changerMotDePasse( $ancien, $nouveau, $confirmation ) {
		$u = self::user();
		if ( ! $u ) { return array( false, 'Session expirée.' ); }
		$hash = FKC_Master::q( 'SELECT password_hash FROM cabinet_users WHERE id=?', array( $u['id'] ) )->fetchColumn();
		if ( ! $hash || ! password_verify( (string) $ancien, (string) $hash ) ) {
			if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( 'pwd_change_echec', 'ancien mot de passe incorrect' ); }
			return array( false, 'Mot de passe actuel incorrect.' );
		}
		list( $ok, $msg ) = self::validerMotDePasse( $nouveau, $confirmation, $u['login'] );
		if ( ! $ok ) { return array( false, $msg ); }
		if ( password_verify( (string) $nouveau, (string) $hash ) ) {
			return array( false, 'Le nouveau mot de passe doit différer de l\'actuel.' );
		}

		FKC_Master::q(
			"UPDATE cabinet_users SET password_hash=?, doit_changer=0, pwd_changed_at=datetime('now','localtime') WHERE id=?",
			array( password_hash( (string) $nouveau, PASSWORD_DEFAULT ), $u['id'] )
		);
		self::oublier();
		// Nouveau sceau : les autres sessions du même compte tombent au
		// prochain contrôle, celle-ci reste valide.
		$_SESSION['fkc_pwd_stamp'] = self::empreinteMotDePasse( $u['id'] );
		if ( PHP_SESSION_ACTIVE === session_status() ) { session_regenerate_id( true ); }
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( 'pwd_change_ok' ); }
		return array( true, 'Mot de passe modifié.' );
	}

	/**
	 * Politique de mot de passe : 12 caractères minimum, trois familles de
	 * caractères sur quatre, et refus des variantes du nom de connexion.
	 * Volontairement sans expiration périodique (elle pousse aux mots de passe
	 * incrémentés, cf. recommandations ANSSI et NIST SP 800-63B).
	 *
	 * @return array{bool, string} ( bool ok, string message )
	 */
	public static function validerMotDePasse( $nouveau, $confirmation, $login = '' ) {
		$nouveau = (string) $nouveau;
		if ( $nouveau !== (string) $confirmation ) {
			return array( false, 'Les deux saisies ne correspondent pas.' );
		}
		$longueur = function_exists( 'mb_strlen' ) ? mb_strlen( $nouveau, 'UTF-8' ) : strlen( $nouveau );
		if ( $longueur < 12 ) {
			return array( false, 'Le mot de passe doit compter au moins 12 caractères.' );
		}
		if ( $longueur > 200 ) {
			return array( false, 'Mot de passe trop long (200 caractères maximum).' );
		}
		$familles = 0;
		if ( preg_match( '/[a-z]/u', $nouveau ) ) { $familles++; }
		if ( preg_match( '/[A-Z]/u', $nouveau ) ) { $familles++; }
		if ( preg_match( '/[0-9]/', $nouveau ) )  { $familles++; }
		if ( preg_match( '/[^a-zA-Z0-9]/u', $nouveau ) ) { $familles++; }
		if ( $familles < 3 ) {
			return array( false, 'Le mot de passe doit combiner au moins trois catégories : minuscules, majuscules, chiffres, caractères spéciaux.' );
		}
		if ( '' !== (string) $login && false !== stripos( $nouveau, (string) $login ) ) {
			return array( false, 'Le mot de passe ne doit pas contenir l\'identifiant.' );
		}
		$interdits = array( 'finakop', 'motdepasse', 'password', 'azerty', 'qwerty', '123456', 'admin' );
		$bas = strtolower( $nouveau );
		foreach ( $interdits as $mot ) {
			if ( false !== strpos( $bas, $mot ) ) {
				return array( false, 'Mot de passe trop prévisible : évitez « ' . $mot . ' ».' );
			}
		}
		return array( true, '' );
	}

	/**
	 * Le refus de connexion vient-il d'un mot de passe par défaut publié ?
	 *
	 * Interrogé par le contrôleur APRÈS un attempt() infructueux, uniquement
	 * pour choisir le message affiché. Le mot de passe est bien revérifié : on
	 * ne révèle l'état « compromis » que si l'appelant connaît réellement ce mot
	 * de passe, jamais sur un simple nom de compte.
	 */
	public static function refuseMotDePasseCompromis( $login, $password ) {
		if ( '' === (string) $login ) { return false; }
		try {
			$row = FKC_Master::q(
				'SELECT password_hash, mdp_compromis FROM cabinet_users WHERE login=? AND actif=1',
				array( $login )
			)->fetch();
		} catch ( \Throwable $e ) { return false; }
		if ( ! $row || empty( $row['mdp_compromis'] ) ) { return false; }
		return password_verify( (string) $password, (string) $row['password_hash'] );
	}

	/** L'accès est-il temporairement bloqué pour ce couple IP/login ? */
	public static function estBloque( $login ) {
		$ip = class_exists( 'FKC_Security' ) ? FKC_Security::clientIp() : '';
		return class_exists( 'FKC_Security' ) ? FKC_Security::loginBlocked( $ip, $login ) : false;
	}

	public static function logout() {
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( 'logout' ); }
		unset(
			$_SESSION['fkc_uid'], $_SESSION[ FKC_Tenant::SESSION_KEY ],
			$_SESSION['fkc_login_at'], $_SESSION['fkc_last_seen'],
			$_SESSION['fkc_pwd_stamp'], $_SESSION['fkc_csrf'], $_SESSION['fkc_2fa_attente']
		);
		self::oublier();
		if ( PHP_SESSION_ACTIVE === session_status() ) { session_regenerate_id( true ); }
	}
}
