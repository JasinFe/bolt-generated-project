<?php
/**
 * FKC_DeuxFacteurs — double authentification TOTP (RFC 6238), 1.876.2.
 *
 * Un mot de passe volé (hameçonnage, réutilisation, poste infecté) ne suffit
 * plus : la connexion exige aussi le code à 6 chiffres d'une application
 * d'authentification (Google Authenticator, Microsoft Authenticator, Authy,
 * 2FAS, Aegis…), qui change toutes les 30 secondes.
 *
 *   • secret de 160 bits, CHIFFRÉ dans le registre avec la clé de l'espace ;
 *   • fenêtre de ±1 pas (horloges décalées de 30 s tolérées) ;
 *   • un code accepté ne peut pas être rejoué (dernier pas mémorisé) ;
 *   • 10 codes de secours à usage unique, stockés hachés (password_hash) ;
 *   • obligatoire pour les administrateurs si FKC_2FA_ADMIN_OBLIGATOIRE.
 *
 * Perte du téléphone : un code de secours, ou la console de la plateforme
 * (« finakop --tenant=<id> 2fa:desactiver <login> »).
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_DeuxFacteurs {

	const PERIODE  = 30;
	const CHIFFRES = 6;
	const FENETRE  = 1;

	protected static $pret = false;

	/** Table créée à la demande dans le registre (aucune migration à jouer). */
	protected static function table() {
		if ( self::$pret ) { return; }
		FKC_Master::q( 'CREATE TABLE IF NOT EXISTS auth_deux_facteurs(
			user_id INTEGER PRIMARY KEY,
			secret TEXT NOT NULL,
			actif INTEGER NOT NULL DEFAULT 0,
			secours TEXT,
			dernier_pas INTEGER NOT NULL DEFAULT 0,
			active_le TEXT
		)' );
		self::$pret = true;
	}

	/** La 2FA est-elle active pour ce compte ? */
	public static function actif( $uid ) {
		try {
			self::table();
			return (bool) FKC_Master::q( 'SELECT actif FROM auth_deux_facteurs WHERE user_id=? AND actif=1', array( (int) $uid ) )->fetchColumn();
		} catch ( \Throwable $e ) { return false; }
	}

	/** Politique : la 2FA est-elle exigée pour ce compte et encore absente ? */
	public static function aConfigurer( $user ) {
		if ( ! defined( 'FKC_2FA_ADMIN_OBLIGATOIRE' ) || ! FKC_2FA_ADMIN_OBLIGATOIRE ) { return false; }
		if ( ! is_array( $user ) || 'admin' !== ( $user['role'] ?? '' ) ) { return false; }
		return ! self::actif( (int) $user['id'] );
	}

	/* ── Secret, URI, vérification ── */

	public static function nouveauSecret() {
		return self::base32( random_bytes( 20 ) );
	}

	public static function uri( $secret, $compte, $emetteur ) {
		$label = rawurlencode( $emetteur ) . ':' . rawurlencode( $compte );
		return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode( $emetteur )
			. '&algorithm=SHA1&digits=' . self::CHIFFRES . '&period=' . self::PERIODE;
	}

	/** Code TOTP pour un pas donné. */
	public static function code( $secret, $pas ) {
		$cle = self::debase32( $secret );
		$msg = pack( 'N*', 0 ) . pack( 'N*', (int) $pas );
		$h = hash_hmac( 'sha1', $msg, $cle, true );
		$o = ord( $h[19] ) & 0x0f;
		$n = ( ( ord( $h[ $o ] ) & 0x7f ) << 24 ) | ( ord( $h[ $o + 1 ] ) << 16 ) | ( ord( $h[ $o + 2 ] ) << 8 ) | ord( $h[ $o + 3 ] );
		return str_pad( (string) ( $n % ( 10 ** self::CHIFFRES ) ), self::CHIFFRES, '0', STR_PAD_LEFT );
	}

	/**
	 * Vérifie un code TOTP. @return int|false le pas reconnu, false sinon.
	 * $apres : dernier pas déjà utilisé (anti-rejeu).
	 */
	public static function verifierCode( $secret, $code, $apres = 0, $maintenant = null ) {
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( strlen( $code ) !== self::CHIFFRES ) { return false; }
		$pas = intdiv( null === $maintenant ? time() : (int) $maintenant, self::PERIODE );
		for ( $d = -self::FENETRE; $d <= self::FENETRE; $d++ ) {
			$p = $pas + $d;
			if ( $p <= (int) $apres ) { continue; }
			if ( hash_equals( self::code( $secret, $p ), $code ) ) { return $p; }
		}
		return false;
	}

	/* ── Cycle de vie ── */

	/**
	 * Active la 2FA après vérification d'un premier code.
	 * @return array{0:bool,1:string,2:array} ok, message, codes de secours (affichés UNE fois)
	 */
	public static function activer( $uid, $secret, $code ) {
		self::table();
		$p = self::verifierCode( $secret, $code );
		if ( false === $p ) { return array( false, 'Code incorrect. Vérifiez l\'heure de votre téléphone et saisissez le code affiché.', array() ); }
		$enc = class_exists( 'FKC_Crypto' ) ? FKC_Crypto::encrypt( $secret ) : null;
		if ( ! $enc ) { return array( false, 'Chiffrement indisponible : activation refusée.', array() ); }
		$codes = array(); $hash = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$c = strtoupper( bin2hex( random_bytes( 2 ) ) . '-' . bin2hex( random_bytes( 2 ) ) );
			$codes[] = $c;
			$hash[]  = password_hash( $c, PASSWORD_DEFAULT );
		}
		FKC_Master::q( "INSERT INTO auth_deux_facteurs(user_id,secret,actif,secours,dernier_pas,active_le) VALUES(?,?,1,?,?,datetime('now','localtime'))
			ON CONFLICT(user_id) DO UPDATE SET secret=excluded.secret, actif=1, secours=excluded.secours, dernier_pas=excluded.dernier_pas, active_le=excluded.active_le",
			array( (int) $uid, $enc, json_encode( $hash ), (int) $p ) );
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( '2fa_activee', '', (int) $uid ); }
		return array( true, 'Double authentification activée.', $codes );
	}

	public static function desactiver( $uid, $motif = '' ) {
		self::table();
		FKC_Master::q( 'DELETE FROM auth_deux_facteurs WHERE user_id=?', array( (int) $uid ) );
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( '2fa_desactivee', (string) $motif, (int) $uid ); }
	}

	/**
	 * Second facteur à la connexion : code de l'application OU code de secours.
	 * @return string|false 'totp' | 'secours' | false
	 */
	public static function verifier( $uid, $saisie ) {
		self::table();
		$row = FKC_Master::q( 'SELECT * FROM auth_deux_facteurs WHERE user_id=? AND actif=1', array( (int) $uid ) )->fetch();
		if ( ! $row ) { return false; }
		$saisie = trim( (string) $saisie );
		if ( preg_match( '/^\d[\d ]{4,8}$/', $saisie ) ) {
			$secret = class_exists( 'FKC_Crypto' ) ? FKC_Crypto::decrypt( $row['secret'] ) : null;
			if ( ! $secret ) { return false; }
			$p = self::verifierCode( $secret, $saisie, (int) $row['dernier_pas'] );
			if ( false === $p ) { return false; }
			// Anti-rejeu : un code accepté ne l'est plus jamais.
			FKC_Master::q( 'UPDATE auth_deux_facteurs SET dernier_pas=? WHERE user_id=? AND dernier_pas<?', array( $p, (int) $uid, $p ) );
			return 'totp';
		}
		$saisie = strtoupper( preg_replace( '/[^0-9A-Fa-f]/', '', $saisie ) );
		if ( 8 !== strlen( $saisie ) ) { return false; }
		$saisie = substr( $saisie, 0, 4 ) . '-' . substr( $saisie, 4 );
		$hash = json_decode( (string) $row['secours'], true ) ?: array();
		foreach ( $hash as $i => $h ) {
			if ( password_verify( $saisie, $h ) ) {
				unset( $hash[ $i ] ); // usage unique
				FKC_Master::q( 'UPDATE auth_deux_facteurs SET secours=? WHERE user_id=?', array( json_encode( array_values( $hash ) ), (int) $uid ) );
				if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( '2fa_code_secours', 'restants=' . count( $hash ), (int) $uid ); }
				return 'secours';
			}
		}
		return false;
	}

	public static function secoursRestants( $uid ) {
		try {
			self::table();
			$s = FKC_Master::q( 'SELECT secours FROM auth_deux_facteurs WHERE user_id=? AND actif=1', array( (int) $uid ) )->fetchColumn();
			return $s ? count( json_decode( (string) $s, true ) ?: array() ) : 0;
		} catch ( \Throwable $e ) { return 0; }
	}

	/** QR code SVG de l'URI d'enrôlement (générateur interne, aucun service tiers). */
	public static function qrSvg( $uri ) {
		return class_exists( 'FKC_Barcode' ) ? FKC_Barcode::qr( $uri, array( 'taille' => 220 ) ) : '';
	}

	/* ── Base32 (RFC 4648) ── */

	public static function base32( $bin ) {
		$a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits = '';
		foreach ( str_split( $bin ) as $c ) { $bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT ); }
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) { $out .= $a[ bindec( str_pad( $chunk, 5, '0' ) ) ]; }
		return $out;
	}

	public static function debase32( $s ) {
		$a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$s = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', (string) $s ) );
		$bits = '';
		foreach ( str_split( $s ) as $c ) { $bits .= str_pad( decbin( strpos( $a, $c ) ), 5, '0', STR_PAD_LEFT ); }
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) { if ( 8 === strlen( $byte ) ) { $out .= chr( bindec( $byte ) ); } }
		return $out;
	}
}
