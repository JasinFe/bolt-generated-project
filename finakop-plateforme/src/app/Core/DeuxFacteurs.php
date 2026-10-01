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
			active_le TEXT,
			methode TEXT NOT NULL DEFAULT \'totp\'
		)' );
		// 1.876.3 : méthode « totp » (application) ou « email » (code envoyé par courriel).
		$cols = array_column( FKC_Master::q( 'PRAGMA table_info(auth_deux_facteurs)' )->fetchAll(), 'name' );
		if ( ! in_array( 'methode', $cols, true ) ) {
			FKC_Master::q( "ALTER TABLE auth_deux_facteurs ADD COLUMN methode TEXT NOT NULL DEFAULT 'totp'" );
		}
		self::$pret = true;
	}

	/** La 2FA est-elle active pour ce compte ? */
	public static function actif( $uid ) {
		try {
			self::table();
			return (bool) FKC_Master::q( 'SELECT actif FROM auth_deux_facteurs WHERE user_id=? AND actif=1', array( (int) $uid ) )->fetchColumn();
		} catch ( \Throwable $e ) { return false; }
	}

	/** Méthode active : 'totp' (application), 'email', ou null si la 2FA est inactive. */
	public static function methode( $uid ) {
		try {
			self::table();
			$m = FKC_Master::q( 'SELECT methode FROM auth_deux_facteurs WHERE user_id=? AND actif=1', array( (int) $uid ) )->fetchColumn();
			return false === $m ? null : ( 'email' === $m ? 'email' : 'totp' );
		} catch ( \Throwable $e ) { return null; }
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
		$codes = self::enregistrer( $uid, $enc, (int) $p, 'totp' );
		return array( true, 'Double authentification activée (application).', $codes );
	}

	/** Enregistre la 2FA active et renvoie 10 nouveaux codes de secours (affichés une fois). */
	protected static function enregistrer( $uid, $secretChiffre, $pas, $methode ) {
		$codes = array(); $hash = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$c = strtoupper( bin2hex( random_bytes( 2 ) ) . '-' . bin2hex( random_bytes( 2 ) ) );
			$codes[] = $c;
			$hash[]  = password_hash( $c, PASSWORD_DEFAULT );
		}
		FKC_Master::q( "INSERT INTO auth_deux_facteurs(user_id,secret,actif,secours,dernier_pas,active_le,methode) VALUES(?,?,1,?,?,datetime('now','localtime'),?)
			ON CONFLICT(user_id) DO UPDATE SET secret=excluded.secret, actif=1, secours=excluded.secours, dernier_pas=excluded.dernier_pas, active_le=excluded.active_le, methode=excluded.methode",
			array( (int) $uid, $secretChiffre, json_encode( $hash ), (int) $pas, $methode ) );
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( '2fa_activee', 'methode=' . $methode, (int) $uid ); }
		return $codes;
	}

	/* ── Code par courriel (1.876.3) ──
	 * Code à 6 chiffres, valable 10 minutes, à usage unique, haché en session
	 * (jamais en clair, jamais en base). 3 envois au plus, 1 par minute,
	 * 5 essais au plus. Moins robuste que l'application (une boîte mail
	 * piratée suffit), d'où la recommandation de l'application. */

	const EMAIL_DUREE      = 600;
	const EMAIL_ENVOIS     = 3;
	const EMAIL_INTERVALLE = 60;

	public static function emailCompte( $uid ) {
		$e = FKC_Master::q( 'SELECT email FROM cabinet_users WHERE id=?', array( (int) $uid ) )->fetchColumn();
		return ( class_exists( 'FKC_Courriel' ) && FKC_Courriel::adresseValide( $e ) ) ? trim( (string) $e ) : '';
	}

	/** a***@d***.com : assez pour se reconnaître, pas assez pour divulguer l'adresse. */
	public static function masquer( $email ) {
		if ( ! preg_match( '/^([^@])[^@]*@([^.@])[^@]*(\.[^.@]+)$/', (string) $email, $m ) ) { return '***'; }
		return $m[1] . '***@' . $m[2] . '***' . $m[3];
	}

	/**
	 * Envoie un nouveau code (l'ancien devient invalide). $etat est l'état en session, mis à jour.
	 * @return array{0:bool,1:string}
	 */
	public static function envoyerCodeEmail( $uid, &$etat, $motif = 'connexion' ) {
		$etat = is_array( $etat ) ? $etat : array();
		$email = self::emailCompte( $uid );
		if ( '' === $email ) { return array( false, 'Aucune adresse e-mail valide n\'est enregistrée sur ce compte.' ); }
		$n = (int) ( $etat['n'] ?? 0 );
		if ( $n >= self::EMAIL_ENVOIS ) { return array( false, 'Nombre maximal d\'envois atteint. Reconnectez-vous ou utilisez un code de secours.' ); }
		if ( ! empty( $etat['envoye'] ) && time() - (int) $etat['envoye'] < self::EMAIL_INTERVALLE ) {
			return array( false, 'Patientez une minute avant de demander un nouveau code.' );
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$espace = defined( 'FKC_TENANT_NOM' ) ? FKC_TENANT_NOM : 'FinaKop';
		$texte = "Bonjour,\n\nVotre code de vérification FinaKop (" . $espace . ") est :\n\n    " . $code . "\n\n"
			. "Il est valable 10 minutes et ne peut servir qu'une fois.\n"
			. ( 'connexion' === $motif ? "Il vous a été envoyé parce que votre mot de passe vient d'être saisi correctement.\n" : "Il vous a été envoyé pour activer la double authentification par e-mail.\n" )
			. "\nSi ce n'est pas vous, ne communiquez ce code à personne et changez votre mot de passe :\nquelqu'un connaît peut-être votre mot de passe.\n\nFinaKop ERP";
		list( $ok, $msg ) = FKC_Courriel::envoyer( $email, 'Votre code de vérification FinaKop', $texte, null, '2fa:' . $motif );
		if ( ! $ok ) { return array( false, 'Le code n\'a pas pu être envoyé (' . $msg . ').' ); }
		$etat = array( 'h' => password_hash( $code, PASSWORD_DEFAULT ), 'exp' => time() + self::EMAIL_DUREE, 'envoye' => time(), 'n' => $n + 1, 'essais' => 0 );
		if ( class_exists( 'FKC_Security' ) ) { FKC_Security::audit( '2fa_code_email', $motif . ' envoi=' . ( $n + 1 ), (int) $uid ); }
		return array( true, 'Un code à 6 chiffres a été envoyé à ' . self::masquer( $email ) . '. Il est valable 10 minutes.' );
	}

	/** Le code saisi correspond-il au dernier code envoyé, encore valable ? */
	public static function codeEmailValide( $etat, $saisie ) {
		if ( ! is_array( $etat ) || empty( $etat['h'] ) || time() > (int) ( $etat['exp'] ?? 0 ) ) { return false; }
		$c = preg_replace( '/\D/', '', (string) $saisie );
		return 6 === strlen( $c ) && password_verify( $c, (string) $etat['h'] );
	}

	/**
	 * Active la 2FA par courriel après vérification du code reçu.
	 * @return array{0:bool,1:string,2:array}
	 */
	public static function activerEmail( $uid, $etat, $saisie ) {
		self::table();
		if ( ! self::codeEmailValide( $etat, $saisie ) ) { return array( false, 'Code incorrect ou expiré.', array() ); }
		$enc = class_exists( 'FKC_Crypto' ) ? FKC_Crypto::encrypt( self::nouveauSecret() ) : null;
		if ( ! $enc ) { return array( false, 'Chiffrement indisponible : activation refusée.', array() ); }
		$codes = self::enregistrer( $uid, $enc, 0, 'email' );
		return array( true, 'Double authentification par e-mail activée.', $codes );
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
	public static function verifier( $uid, $saisie, $etatEmail = null ) {
		self::table();
		$row = FKC_Master::q( 'SELECT * FROM auth_deux_facteurs WHERE user_id=? AND actif=1', array( (int) $uid ) )->fetch();
		if ( ! $row ) { return false; }
		$saisie = trim( (string) $saisie );
		if ( preg_match( '/^\d[\d ]{4,8}$/', $saisie ) && 'email' === ( $row['methode'] ?? 'totp' ) ) {
			return self::codeEmailValide( $etatEmail, $saisie ) ? 'email' : false;
		}
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
