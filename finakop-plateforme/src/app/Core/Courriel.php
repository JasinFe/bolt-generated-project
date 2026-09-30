<?php
/**
 * Envoi de courriels (1.838.0) — un seul chemin, tracé.
 *
 * L'ERP n'envoyait aucun courriel : toutes ses relances passaient par un
 * lien « mailto: » ou WhatsApp, ouvert à la main. Une alerte critique ne
 * peut pas attendre qu'un humain ouvre l'écran : elle doit partir seule.
 *
 * TRANSPORT (1.876.0) : FKC_Mailer (SMTP) quand il est configuré — c'est le
 * cas de toute installation autonome, sans WordPress. En extension WordPress,
 * wp_mail() reste utilisé : le serveur de messagerie et l'éventuelle extension
 * SMTP du site y sont déjà réglés.
 *
 * TOUT ENVOI EST JOURNALISÉ (table `courriels_journal` de la société) :
 * destinataire, sujet, origine, statut, erreur. Un courriel qui n'est pas
 * parti doit se voir à l'écran, pas se découvrir le jour où l'alerte
 * n'est pas arrivée.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Courriel {

	/**
	 * @var callable|null transport de remplacement (tests, diagnostic) :
	 *   function( string $to, string $sujet, string $corps, array $entetes ): bool
	 */
	public static $transport = null;

	/** @var string dernière erreur remontée par wp_mail (crochet wp_mail_failed) */
	protected static $derniereErreur = '';

	/** Un transport est-il disponible dans ce contexte ? */
	public static function disponible() {
		return is_callable( self::$transport )
			|| ( class_exists( 'FKC_Mailer' ) && FKC_Mailer::configure() )
			|| function_exists( 'wp_mail' );
	}

	/** Adresse valide et sans caractère d'injection d'en-tête. */
	public static function adresseValide( $adresse ) {
		$a = trim( (string) $adresse );
		return '' !== $a && false === strpbrk( $a, "\r\n," ) && false !== filter_var( $a, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Envoie un courriel.
	 *
	 * @param string      $to     une adresse
	 * @param string      $sujet  une ligne (retours à la ligne neutralisés)
	 * @param string      $texte  version texte
	 * @param string|null $html   version HTML (déjà échappée par l'appelant)
	 * @param string      $source origine, pour le journal (« alerte:12 »)
	 * @return array{0:bool,1:string}
	 */
	public static function envoyer( $to, $sujet, $texte, $html = null, $source = '' ) {
		$to = trim( (string) $to );
		$sujet = trim( preg_replace( '/[\r\n]+/', ' ', (string) $sujet ) );
		if ( ! self::adresseValide( $to ) ) {
			self::journaliser( $to, $sujet, $source, 'echec', 'Adresse invalide.' );
			return array( false, 'Adresse invalide.' );
		}
		if ( ! self::disponible() ) {
			self::journaliser( $to, $sujet, $source, 'indisponible', 'Aucun transport de courriel dans ce contexte.' );
			return array( false, 'L\'envoi de courriels n\'est pas disponible sur ce serveur.' );
		}
		$corps = null !== $html ? $html : $texte;
		$entetes = array( 'Content-Type: ' . ( null !== $html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8' );

		self::$derniereErreur = '';
		try {
			if ( is_callable( self::$transport ) ) {
				$ok = (bool) call_user_func( self::$transport, $to, $sujet, $corps, $entetes );
			} elseif ( class_exists( 'FKC_Mailer' ) && FKC_Mailer::configure() ) {
				list( $ok, $info ) = FKC_Mailer::envoyer( array( 'a' => $to, 'sujet' => $sujet, 'texte' => $texte, 'html' => $html ) );
				if ( ! $ok ) { self::$derniereErreur = (string) $info; }
			} else {
				self::ecouterErreurs();
				$ok = (bool) wp_mail( $to, $sujet, $corps, $entetes );
			}
		} catch ( \Throwable $e ) {
			$ok = false; self::$derniereErreur = $e->getMessage();
		}
		$err = $ok ? '' : ( self::$derniereErreur ?: 'Le serveur de messagerie a refusé l\'envoi.' );
		self::journaliser( $to, $sujet, $source, $ok ? 'envoye' : 'echec', $err );
		return array( $ok, $ok ? 'Courriel envoyé à ' . $to . '.' : $err );
	}

	protected static function ecouterErreurs() {
		static $pose = false;
		if ( $pose || ! function_exists( 'add_action' ) ) { return; }
		add_action( 'wp_mail_failed', function ( $err ) {
			FKC_Courriel::noterErreur( is_object( $err ) && method_exists( $err, 'get_error_message' ) ? $err->get_error_message() : 'Erreur d\'envoi.' );
		} );
		$pose = true;
	}

	public static function noterErreur( $m ) { self::$derniereErreur = (string) $m; }

	/* ═══════════════ Journal ═══════════════ */

	protected static function table() {
		static $fait = array();
		$f = (string) FKC_DB::file();
		if ( isset( $fait[ $f ] ) ) { return; }
		FKC_DB::conn()->exec( "CREATE TABLE IF NOT EXISTS courriels_journal(
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			cree_le TEXT NOT NULL DEFAULT(datetime('now','localtime')),
			destinataire TEXT, sujet TEXT, source TEXT,
			statut TEXT NOT NULL,            -- envoye | echec | indisponible
			erreur TEXT
		)" );
		$fait[ $f ] = true;
	}

	protected static function journaliser( $to, $sujet, $source, $statut, $erreur ) {
		try {
			self::table();
			FKC_DB::q( 'INSERT INTO courriels_journal(destinataire,sujet,source,statut,erreur) VALUES(?,?,?,?,?)',
				array( mb_substr( (string) $to, 0, 190 ), mb_substr( (string) $sujet, 0, 250 ), mb_substr( (string) $source, 0, 60 ), (string) $statut, $erreur ? mb_substr( (string) $erreur, 0, 400 ) : null ) );
		} catch ( \Throwable $e ) { /* le journal ne bloque jamais un envoi */ }
	}

	/** Derniers envois, éventuellement d'une origine (préfixe : « alerte »). */
	public static function journal( $limite = 10, $prefixeSource = '' ) {
		try {
			self::table();
			if ( '' !== $prefixeSource ) {
				return FKC_DB::q( 'SELECT * FROM courriels_journal WHERE source LIKE ? ORDER BY id DESC LIMIT ' . max( 1, (int) $limite ), array( $prefixeSource . '%' ) )->fetchAll();
			}
			return FKC_DB::q( 'SELECT * FROM courriels_journal ORDER BY id DESC LIMIT ' . max( 1, (int) $limite ) )->fetchAll();
		} catch ( \Throwable $e ) { return array(); }
	}
}
