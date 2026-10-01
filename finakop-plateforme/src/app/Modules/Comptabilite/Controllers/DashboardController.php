<?php
/** Accueil, authentification et licence. @package FinaKop_ERP_Core */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_DashboardController {

	public function showLogin() {
		if ( FKC_Auth::check() ) { redirect( '' ); }
		$err = isset( $_SESSION['__login_err'] ) ? $_SESSION['__login_err'] : null;
		unset( $_SESSION['__login_err'] );
		FKC_View::render( 'login', array( 'error' => $err ), false );
	}

	public function login() {
		$login = isset( $_POST['login'] ) ? trim( $_POST['login'] ) : '';
		$pass  = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$res = FKC_Auth::attempt( $login, $pass );
		if ( 'deux_facteurs' === $res ) { redirect( 'verification' ); }
		if ( $res ) {
			// Case « Modifier mon mot de passe après la connexion » de l'écran
			// de connexion : on conduit directement à l'écran dédié.
			redirect( empty( $_POST['changer_mdp'] ) ? '' : 'mot-de-passe' );
		}
		/*
		 * Message distinct quand le refus vient d'un mot de passe par défaut
		 * publié. Cela ne renseigne pas un attaquant — le couple est de notoriété
		 * publique — mais évite au propriétaire légitime de croire à une simple
		 * faute de frappe et de chercher pendant une heure.
		 */
		if ( FKC_Auth::refuseMotDePasseCompromis( $login, $pass ) ) {
			$_SESSION['__login_err'] = 'Ce compte utilise le mot de passe par défaut des versions antérieures, '
				. 'publié dans la documentation. Sa connexion est bloquée. Un administrateur du site doit réémettre '
				. 'un mot de passe depuis Réglages → FinaKop ERP Core.';
			redirect( 'login' );
		}
		if ( FKC_Auth::estBloque( $login ) ) {
			$_SESSION['__login_err'] = 'Trop de tentatives. Réessayez dans quelques minutes.';
			redirect( 'login' );
		}
		$_SESSION['__login_err'] = 'Identifiants incorrects.';
		redirect( 'login' );
	}

	public function logout() {
		FKC_Auth::logout();
		redirect( 'login' );
	}

	/**
	 * Formulaire de changement de mot de passe.
	 *
	 * Accessible en permanence, et SEULE route métier servie quand le
	 * changement est imposé (voir le middleware « sans_mdp » du routeur).
	 */
	public function showMotDePasse() {
		$err = isset( $_SESSION['__mdp_err'] ) ? $_SESSION['__mdp_err'] : null;
		$ok  = isset( $_SESSION['__mdp_ok'] ) ? $_SESSION['__mdp_ok'] : null;
		unset( $_SESSION['__mdp_err'], $_SESSION['__mdp_ok'] );
		FKC_View::render(
			'motdepasse',
			array(
				'error'  => $err,
				'ok'     => $ok,
				'impose' => FKC_Auth::doitChangerMotDePasse(),
				'user'   => FKC_Auth::user() ?: array(),
			),
			false
		);
	}

	public function changerMotDePasse() {
		list( $ok, $msg ) = FKC_Auth::changerMotDePasse(
			$_POST['ancien'] ?? '',
			$_POST['nouveau'] ?? '',
			$_POST['confirmation'] ?? ''
		);
		if ( ! $ok ) {
			$_SESSION['__mdp_err'] = $msg;
			redirect( 'mot-de-passe' );
		}
		$_SESSION['__flash'] = array( 'type' => 'ok', 'msg' => $msg );
		redirect( '' );
	}

	/**
	 * Sert une section différée, rendue côté serveur.
	 *
	 * Renvoie du HTML et non du JSON : le rendu passe par le même partiel que
	 * la page complète, donc une section a rigoureusement la même apparence
	 * qu'elle arrive avec la page ou juste après. Servir du JSON imposerait un
	 * second gabarit côté navigateur, condamné à diverger.
	 *
	 * Réponse vide si la section est inconnue, sans donnée ou en panne : le
	 * navigateur retire alors simplement l'emplacement.
	 */
	/**
	 * Dossier de conseil : le cockpit sous forme imprimable.
	 *
	 * Toutes les sections sont évaluées, différées comprises : un document
	 * imprimé n'a pas de navigateur pour aller chercher la suite.
	 */
	public function dossier() {
		$ckp = isset( $_GET['cockpit'] ) ? preg_replace( '/[^a-z0-9_]/', '', (string) $_GET['cockpit'] ) : 'direction';
		$annee = $this->anneeDemandee();

		$accessibles = FKC_Cockpit::accessibles( FKC_Auth::user() );
		if ( ! isset( $accessibles[ $ckp ] ) ) { return redirect( '' ); }

		FKC_View::render( 'cockpit_dossier', array(
			'title'    => 'Dossier de conseil',
			'cockpit'  => $accessibles[ $ckp ],
			'sections' => FKC_Cockpit::evaluer( $ckp, $annee, true ),
			'societe'  => FKC_Tenant::current(),
			'annee'    => $annee,
			'user'     => FKC_Auth::user(),
		), null );
	}

	public function section( $cockpit, $id ) {
		$cockpit = preg_replace( '/[^a-z0-9_]/', '', (string) $cockpit );
		$id      = preg_replace( '/[^a-z0-9_]/', '', (string) $id );
		$annee   = $this->anneeDemandee();

		// Le contrôle d'accès du cockpit s'applique : une section n'est pas
		// une porte dérobée vers des données réservées à un autre profil.
		$accessibles = FKC_Cockpit::accessibles( FKC_Auth::user() );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		if ( ! isset( $accessibles[ $cockpit ] ) ) { http_response_code( 403 ); return; }

		$s = FKC_Cockpit::evaluerSection( $cockpit, $id, $annee );
		if ( ! $s ) { return; }
		FKC_View::partial( '_cockpitsection', array( 's' => $s ) );
	}

	public function home() {
		/*
		 * MODE SIMPLE — l'accueil n'est pas un cockpit mais un plan de travail.
		 *
		 * Le cockpit de direction reste intact et redevient l'accueil dès que
		 * l'utilisateur repasse en Professionnel ou en Expert : rien n'est
		 * supprimé, seule la porte d'entrée change. Un cockpit explicitement
		 * demandé (?cockpit=…) est honoré même en Simple — un lien envoyé par
		 * un collègue ne doit pas s'évaporer.
		 */
		if ( class_exists( 'FKC_ModeUI' ) && FKC_ModeUI::estSimple() && empty( $_GET['cockpit'] ) ) {
			$mc = new FKC_ModeController();
			return $mc->accueil();
		}
		$annee = (string) $this->anneeDemandee();
		$licence  = FKC_License::resolve();
		/* ── Centre de Pilotage : cockpit par profil ─────────────────────
		   Le rôle de l'utilisateur détermine les cockpits accessibles (DG,
		   Finance, Commercial, Stock, RH + ceux des packs métier). Le
		   cockpit « direction » conserve la vue dashboard ci-dessous ; les
		   autres passent par le rendu générique views/cockpit.php. */
		$user     = FKC_Auth::user();
		$cockpits = class_exists( 'FKC_Cockpit' ) ? FKC_Cockpit::accessibles( $user ) : array();
		$ckp      = isset( $_GET['cockpit'] ) ? preg_replace( '/[^a-z0-9_]/', '', (string) $_GET['cockpit'] ) : '';
		if ( '' === $ckp || ! isset( $cockpits[ $ckp ] ) ) {
			$ckp = $cockpits ? FKC_Cockpit::parDefaut( $user ) : 'direction';
		}
		$defCkp = $cockpits[ $ckp ] ?? null;
		$fl = isset( $_SESSION['__flash'] ) ? $_SESSION['__flash'] : null;
		unset( $_SESSION['__flash'] );
		$vars = array(
			'title'        => $defCkp ? 'Cockpit ' . ( $defCkp['label'] ?? '' ) : 'Centre de Pilotage',
			'cockpit'      => $defCkp ?: array( 'label' => '', 'icone' => '🧭', 'code' => '' ),
			'sections'     => $defCkp ? FKC_Cockpit::evaluer( $ckp, $annee ) : array(),
			'cockpits'     => $cockpits,
			'cockpitActif' => $defCkp ? $ckp : '',
			'license'      => $licence,
			'user'         => $user,
			'societe'      => FKC_Tenant::current(),
			'annee'        => $annee,
			'annees'       => $this->annees(),
			'flash'        => $fl,
			/*
			 * AUCUN COCKPIT ACCESSIBLE (1.851.0).
			 *
			 * Ce cas retombait sur l'ancienne vue « dashboard », qui affichait
			 * la synthèse financière COMPLÈTE de la société (résultat,
			 * trésorerie, créances, dettes) — précisément à l'utilisateur à qui
			 * aucun cockpit n'est ouvert, ni par son rôle ni par la licence.
			 * On le dit désormais, sans rien montrer.
			 */
			'aucunCockpit' => ! $defCkp,
		);
		// Un cockpit de pack peut déclarer sa propre vue ('vue') : elle reçoit le même contexte.
		FKC_View::render( ( $defCkp && ! empty( $defCkp['vue'] ) ) ? (string) $defCkp['vue'] : 'cockpit', $vars );
	}

	/**
	 * Exercice affiché, et exercice de comparaison (1.800.0).
	 *
	 * Par défaut, l'exercice EN COURS et non l'année civile : sur un exercice
	 * clôturant au 30 juin, le Cockpit ouvert en janvier affichait l'exercice
	 * suivant, vide. `?comparer=AAAA` choisit l'exercice de comparaison
	 * (N-1 par défaut) ; il vaut pour tout l'écran.
	 */
	protected function anneeDemandee() {
		$a = isset( $_GET['annee'] ) ? (int) preg_replace( '/\D/', '', (string) $_GET['annee'] ) : 0;
		if ( $a < 1990 || $a > 2100 ) { $a = class_exists( 'FKC_Exercice' ) ? (int) FKC_Exercice::courant() : (int) date( 'Y' ); }
		/*
		 * L'option de comparaison (1.800.1) : `?comparer=` l'active (n1 ou un
		 * exercice) ou la désactive (0) ; le choix est MÉMORISÉ pour la session,
		 * pour ne pas devoir le refaire à chaque changement d'exercice ou
		 * d'onglet. Sans paramètre ni choix mémorisé : désactivée.
		 */
		if ( class_exists( 'FKC_PiloteLecture' ) ) {
			if ( isset( $_GET['comparer'] ) ) {
				$_SESSION['fkc_cockpit_comparer'] = preg_replace( '/[^a-z0-9]/i', '', (string) $_GET['comparer'] );
			}
			FKC_PiloteLecture::definirComparaison( $_SESSION['fkc_cockpit_comparer'] ?? '' );
		}
		return $a;
	}

	/** Exercices disponibles (écritures + factures), ordre décroissant, année courante incluse. */
	protected function annees() {
		if ( class_exists( 'FKC_Exercice' ) ) { $y = FKC_Exercice::annees(); if ( $y ) { return $y; } }
		$ys = array();
		try {
			$rows = FKC_DB::q(
				"SELECT DISTINCT substr(date_ecriture,1,4) y FROM ecritures WHERE date_ecriture IS NOT NULL
				 UNION SELECT DISTINCT substr(date_facture,1,4) FROM factures WHERE date_facture IS NOT NULL"
			)->fetchAll();
			foreach ( $rows as $r ) { if ( ! empty( $r['y'] ) ) { $ys[] = $r['y']; } }
		} catch ( Exception $e ) { /* tables non initialisées */ }
		$cur = date( 'Y' );
		if ( ! in_array( $cur, $ys, true ) ) { $ys[] = $cur; }
		$ys = array_values( array_unique( $ys ) );
		rsort( $ys );
		return $ys;
	}

	public function license() {
		$flash = isset( $_SESSION['__flash'] ) ? $_SESSION['__flash'] : null;
		unset( $_SESSION['__flash'] );
		FKC_View::render( 'license', array(
			'title'   => 'Licence',
			'license' => FKC_License::resolve(),
			'flash'   => $flash,
		) );
	}

	public function activateLicense() {
		// Remplacer la licence engage toute la société : réservé à l'administrateur
		// (1.876.0 — auparavant, tout utilisateur connecté pouvait la changer).
		if ( ! FKC_Auth::isAdmin() ) {
			if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( 'license_install_refuse', 'utilisateur non administrateur' ); }
			http_response_code( 403 );
			return FKC_View::render( 'errors.denied', array( 'reason' => 'admin' ) );
		}
		$raw = isset( $_POST['token'] ) ? trim( $_POST['token'] ) : '';
		list( $ok, $msg ) = FKC_License::install( $raw );
		$_SESSION['__flash'] = array( 'type' => $ok ? 'ok' : 'err', 'msg' => $msg );
		redirect( 'licence' );
	}

	/**
	 * Enregistre les sections masquées d'un cockpit pour l'utilisateur connecté
	 * (1.851.0). Appelé en arrière-plan par « ⚙ Personnaliser » ; CSRF vérifié
	 * par le routeur. Seul un cockpit ACCESSIBLE à l'utilisateur est accepté :
	 * la table ne doit pas devenir un réceptacle de codes arbitraires.
	 */
	public function cockpitPreferences() {
		header( 'Content-Type: application/json; charset=utf-8' );
		$user    = FKC_Auth::user();
		$cockpit = preg_replace( '/[^a-z0-9_]/', '', (string) ( $_POST['cockpit'] ?? '' ) );
		$acc     = class_exists( 'FKC_Cockpit' ) ? FKC_Cockpit::accessibles( $user ) : array();
		if ( empty( $user['id'] ) || ! isset( $acc[ $cockpit ] ) ) {
			http_response_code( 403 );
			echo json_encode( array( 'ok' => false ) );
			return;
		}
		$masques = json_decode( (string) ( $_POST['masques'] ?? '[]' ), true );
		$ok = FKC_CockpitPrefs::enregistrer( (int) $user['id'], $cockpit, is_array( $masques ) ? $masques : array() );
		echo json_encode( array( 'ok' => (bool) $ok ) );
	}

	/**
	 * Exécute une action déclenchée depuis un cockpit (boucle « agir »).
	 * Le bouton a POSTé : CSRF vérifié par le routeur, rôle vérifié par le
	 * moteur, écriture journalisée. On revient ensuite sur l'écran d'origine
	 * avec le résultat — le dirigeant ne perd jamais le fil.
	 */
	public function cockpitAction() {
		if ( ! class_exists( 'FKC_Actions' ) ) { return redirect( '' ); }
		$code   = preg_replace( '/[^a-z0-9_]/', '', (string) ( $_POST['action_code'] ?? '' ) );
		$params = array();
		foreach ( (array) ( $_POST['p'] ?? array() ) as $k => $v ) {
			if ( is_scalar( $v ) ) { $params[ preg_replace( '/[^a-z0-9_]/i', '', (string) $k ) ] = $v; }
		}
		list( $ok, $msg ) = FKC_Actions::executer( $code, $params );
		$_SESSION['__flash'] = array( 'type' => $ok ? 'ok' : 'err', 'msg' => $msg );

		/*
		 * Retour sur l'écran d'origine, en restant à l'intérieur de l'application.
		 *
		 * Deux défauts corrigés (1.851.0) :
		 *  — en mode « chemin » (/finakop), le préfixe était conservé puis
		 *    rajouté par url() : retour vers /finakop/finakop/…, soit une 404
		 *    après CHAQUE action lancée depuis un cockpit. fkc_url_interne(),
		 *    appelée par redirect(), sait retirer ce préfixe — encore faut-il
		 *    lui passer le chemin tel quel, barre initiale comprise ;
		 *  — une action lancée depuis une section DIFFÉRÉE portait comme
		 *    origine l'URL du fragment (cockpit/section/…) : l'utilisateur
		 *    atterrissait sur un morceau de HTML sans mise en page. On le
		 *    ramène sur le cockpit qui contenait la section.
		 */
		$retour = (string) ( $_POST['retour'] ?? '' );
		$path   = (string) parse_url( $retour, PHP_URL_PATH );
		$query  = (string) parse_url( $retour, PHP_URL_QUERY );
		$base   = defined( 'FKC_BASE_PATH' ) ? (string) FKC_BASE_PATH : '';
		$rel    = ( '' !== $base && 0 === strpos( $path, $base ) ) ? substr( $path, strlen( $base ) ) : $path;
		if ( preg_match( '#^/?cockpit/section/([a-z0-9_]+)/#', $rel, $m ) ) {
			parse_str( $query, $q );
			$q = array_intersect_key( $q, array_flip( array( 'annee', 'comparer' ) ) );
			return redirect( '?' . http_build_query( array( 'cockpit' => $m[1] ) + $q ) );
		}
		if ( '' === trim( $path, '/' ) && '' === $query ) { return redirect( '' ); }
		return redirect( $path . ( $query ? '?' . $query : '' ) );
	}

}
