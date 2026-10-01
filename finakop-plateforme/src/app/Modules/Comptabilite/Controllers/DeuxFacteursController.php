<?php
/**
 * Double authentification : saisie du code à la connexion, activation et
 * désactivation par l'utilisateur (1.876.2). Voir FKC_DeuxFacteurs.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_DeuxFacteursController {

	/* ── Connexion : second facteur ── */

	public function showVerification() {
		if ( FKC_Auth::check() ) { redirect( '' ); }
		if ( ! FKC_Auth::deuxFacteursEnAttente() ) { redirect( 'login' ); }
		$a = FKC_Auth::deuxFacteursEnAttente();
		$err = $_SESSION['__2fa_err'] ?? null; $info = $_SESSION['__2fa_info'] ?? ( $a['info'] ?? null );
		unset( $_SESSION['__2fa_err'], $_SESSION['__2fa_info'], $_SESSION['fkc_2fa_attente']['info'] );
		FKC_View::render( 'verification', array( 'error' => $err, 'info' => $info, 'methode' => $a['methode'] ?? 'totp' ), false );
	}

	/** Nouveau code par courriel (1.876.3). */
	public function renvoyer() {
		if ( ! FKC_Auth::deuxFacteursEnAttente() ) { redirect( 'login' ); }
		list( $ok, $msg ) = FKC_Auth::renvoyerCodeEmail();
		$_SESSION[ $ok ? '__2fa_info' : '__2fa_err' ] = $msg;
		redirect( 'verification' );
	}

	public function verifier() {
		list( $ok, $msg ) = FKC_Auth::finaliserDeuxFacteurs( (string) ( $_POST['code'] ?? '' ) );
		if ( $ok ) {
			if ( '' !== $msg ) { $_SESSION['__flash'] = array( 'type' => 'warn', 'msg' => $msg ); }
			redirect( '' );
		}
		if ( FKC_Auth::deuxFacteursEnAttente() ) { $_SESSION['__2fa_err'] = $msg; redirect( 'verification' ); }
		$_SESSION['__login_err'] = $msg;
		redirect( 'login' );
	}

	/* ── Compte : activation / désactivation ── */

	public function show() {
		$u = FKC_Auth::user();
		$actif = FKC_DeuxFacteurs::actif( (int) $u['id'] );
		$email = FKC_DeuxFacteurs::emailCompte( (int) $u['id'] );
		$data = array(
			'user' => $u, 'actif' => $actif, 'impose' => FKC_DeuxFacteurs::aConfigurer( $u ),
			'methode' => FKC_DeuxFacteurs::methode( (int) $u['id'] ), 'email' => '' !== $email ? FKC_DeuxFacteurs::masquer( $email ) : '',
			'email_envoye' => ! empty( $_SESSION['__2fa_email_etat']['h'] ), 'info' => $_SESSION['__2fa_info'] ?? null,
			'error' => $_SESSION['__2fa_err'] ?? null, 'ok' => $_SESSION['__2fa_ok'] ?? null,
			'codes' => $_SESSION['__2fa_codes'] ?? null, 'restants' => $actif ? FKC_DeuxFacteurs::secoursRestants( (int) $u['id'] ) : 0,
		);
		unset( $_SESSION['__2fa_err'], $_SESSION['__2fa_ok'], $_SESSION['__2fa_codes'], $_SESSION['__2fa_info'] );
		if ( ! $actif || 'email' === $data['methode'] ) {
			// Application : proposée à l'activation, et pour passer de l'e-mail à l'application.
			// Secret proposé, gardé en session jusqu'à confirmation par un premier code.
			if ( empty( $_SESSION['__2fa_secret'] ) ) { $_SESSION['__2fa_secret'] = FKC_DeuxFacteurs::nouveauSecret(); }
			$emetteur = 'FinaKop' . ( defined( 'FKC_TENANT_NOM' ) ? ' · ' . FKC_TENANT_NOM : '' );
			$data['secret'] = $_SESSION['__2fa_secret'];
			$data['qr'] = FKC_DeuxFacteurs::qrSvg( FKC_DeuxFacteurs::uri( $_SESSION['__2fa_secret'], (string) $u['login'], $emetteur ) );
		}
		FKC_View::render( 'deux_facteurs', $data, false );
	}

	public function activer() {
		$u = FKC_Auth::user();
		$secret = (string) ( $_SESSION['__2fa_secret'] ?? '' );
		if ( '' === $secret ) { redirect( 'securite/deux-facteurs' ); }
		list( $ok, $msg, $codes ) = FKC_DeuxFacteurs::activer( (int) $u['id'], $secret, (string) ( $_POST['code'] ?? '' ) );
		if ( $ok ) {
			unset( $_SESSION['__2fa_secret'] );
			$_SESSION['__2fa_ok'] = $msg; $_SESSION['__2fa_codes'] = $codes;
		} else {
			$_SESSION['__2fa_err'] = $msg;
		}
		redirect( 'securite/deux-facteurs' );
	}

	/** Activation par courriel, étape 1 : envoi du code à l'adresse du compte (1.876.3). */
	public function emailEnvoyer() {
		$u = FKC_Auth::user();
		$etat = $_SESSION['__2fa_email_etat'] ?? array();
		list( $ok, $msg ) = FKC_DeuxFacteurs::envoyerCodeEmail( (int) $u['id'], $etat, 'activation' );
		$_SESSION['__2fa_email_etat'] = $etat;
		$_SESSION[ $ok ? '__2fa_info' : '__2fa_err' ] = $msg;
		redirect( 'securite/deux-facteurs' );
	}

	/** Activation par courriel, étape 2 : vérification du code reçu. */
	public function emailActiver() {
		$u = FKC_Auth::user();
		$etat = $_SESSION['__2fa_email_etat'] ?? null;
		if ( ! is_array( $etat ) || empty( $etat['h'] ) ) { redirect( 'securite/deux-facteurs' ); }
		$_SESSION['__2fa_email_etat']['essais'] = (int) ( $etat['essais'] ?? 0 ) + 1;
		if ( $_SESSION['__2fa_email_etat']['essais'] > 5 ) {
			unset( $_SESSION['__2fa_email_etat'] );
			$_SESSION['__2fa_err'] = 'Trop d\'essais : demandez un nouveau code.';
			redirect( 'securite/deux-facteurs' );
		}
		list( $ok, $msg, $codes ) = FKC_DeuxFacteurs::activerEmail( (int) $u['id'], $etat, (string) ( $_POST['code'] ?? '' ) );
		if ( $ok ) {
			unset( $_SESSION['__2fa_email_etat'] );
			$_SESSION['__2fa_ok'] = $msg; $_SESSION['__2fa_codes'] = $codes;
		} else {
			$_SESSION['__2fa_err'] = $msg;
		}
		redirect( 'securite/deux-facteurs' );
	}

	public function desactiver() {
		$u = FKC_Auth::user();
		if ( defined( 'FKC_2FA_ADMIN_OBLIGATOIRE' ) && FKC_2FA_ADMIN_OBLIGATOIRE && 'admin' === ( $u['role'] ?? '' ) ) {
			$_SESSION['__2fa_err'] = 'La double authentification est obligatoire pour les administrateurs.';
			redirect( 'securite/deux-facteurs' );
		}
		$hash = FKC_Master::q( 'SELECT password_hash FROM cabinet_users WHERE id=?', array( (int) $u['id'] ) )->fetchColumn();
		$etat = $_SESSION['__2fa_email_etat'] ?? null;
		if ( ! password_verify( (string) ( $_POST['password'] ?? '' ), (string) $hash ) || false === FKC_DeuxFacteurs::verifier( (int) $u['id'], (string) ( $_POST['code'] ?? '' ), $etat ) ) {
			$_SESSION['__2fa_err'] = 'Mot de passe ou code incorrect.';
			redirect( 'securite/deux-facteurs' );
		}
		unset( $_SESSION['__2fa_email_etat'] );
		FKC_DeuxFacteurs::desactiver( (int) $u['id'], 'par l\'utilisateur' );
		$_SESSION['__2fa_ok'] = 'Double authentification désactivée.';
		redirect( 'securite/deux-facteurs' );
	}
}
