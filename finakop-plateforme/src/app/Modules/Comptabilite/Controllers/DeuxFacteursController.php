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
		$err = $_SESSION['__2fa_err'] ?? null; unset( $_SESSION['__2fa_err'] );
		FKC_View::render( 'verification', array( 'error' => $err ), false );
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
		$data = array(
			'user' => $u, 'actif' => $actif, 'impose' => FKC_DeuxFacteurs::aConfigurer( $u ),
			'error' => $_SESSION['__2fa_err'] ?? null, 'ok' => $_SESSION['__2fa_ok'] ?? null,
			'codes' => $_SESSION['__2fa_codes'] ?? null, 'restants' => $actif ? FKC_DeuxFacteurs::secoursRestants( (int) $u['id'] ) : 0,
		);
		unset( $_SESSION['__2fa_err'], $_SESSION['__2fa_ok'], $_SESSION['__2fa_codes'] );
		if ( ! $actif ) {
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

	public function desactiver() {
		$u = FKC_Auth::user();
		if ( defined( 'FKC_2FA_ADMIN_OBLIGATOIRE' ) && FKC_2FA_ADMIN_OBLIGATOIRE && 'admin' === ( $u['role'] ?? '' ) ) {
			$_SESSION['__2fa_err'] = 'La double authentification est obligatoire pour les administrateurs.';
			redirect( 'securite/deux-facteurs' );
		}
		$hash = FKC_Master::q( 'SELECT password_hash FROM cabinet_users WHERE id=?', array( (int) $u['id'] ) )->fetchColumn();
		if ( ! password_verify( (string) ( $_POST['password'] ?? '' ), (string) $hash ) || false === FKC_DeuxFacteurs::verifier( (int) $u['id'], (string) ( $_POST['code'] ?? '' ) ) ) {
			$_SESSION['__2fa_err'] = 'Mot de passe ou code incorrect.';
			redirect( 'securite/deux-facteurs' );
		}
		FKC_DeuxFacteurs::desactiver( (int) $u['id'], 'par l\'utilisateur' );
		$_SESSION['__2fa_ok'] = 'Double authentification désactivée.';
		redirect( 'securite/deux-facteurs' );
	}
}
