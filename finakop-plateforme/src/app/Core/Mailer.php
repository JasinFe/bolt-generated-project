<?php
/**
 * FKC_Mailer — envoi de courriels sans WordPress (1.876.0).
 *
 * Jusqu'ici, FKC_Courriel ne connaissait qu'un transport : wp_mail(). Hors
 * WordPress, aucun courriel ne partait — c'était la SEULE fonction de FinaKop
 * réellement cassée en mode autonome. Ce client couvre ce qu'un hébergement
 * mutualisé ou un VPS offre partout :
 *
 *   • smtp    : SSL implicite (465), STARTTLS (587) ou clair (réseau local) ;
 *               authentification PLAIN ou LOGIN ; certificat VÉRIFIÉ par défaut ;
 *   • mail    : fonction mail() de PHP (secours) ;
 *   • fichier : écrit un .eml par message (développement, recette).
 *
 * Message : destinataires (à, cc, cci), texte, HTML, pièces jointes, réponse-à.
 * Aucun mot de passe ni corps de message n'est journalisé.
 *
 * Configuration : constante FKC_SMTP (tableau, posée par la plateforme) ou
 * FKC_Mailer::configurer(). Clés : transport, hote, port, securite
 * (ssl|tls|aucune), utilisateur, mot_de_passe, expediteur, nom_expediteur,
 * repondre_a, delai, verifier_certificat, dossier (transport « fichier »).
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Mailer {

	/** @var array|null configuration explicite (prioritaire sur FKC_SMTP) */
	protected static $config = null;
	protected static $erreur = '';
	/** @var string dernier message brut produit (diagnostic et tests) */
	protected static $brut = '';

	public static function configurer( array $c ) { self::$config = $c; }

	public static function config() {
		if ( is_array( self::$config ) ) { return self::$config; }
		return ( defined( 'FKC_SMTP' ) && is_array( FKC_SMTP ) ) ? FKC_SMTP : array();
	}

	/** Un transport est-il réglé ? */
	public static function configure() {
		$c = self::config();
		$t = (string) ( $c['transport'] ?? ( ! empty( $c['hote'] ) ? 'smtp' : '' ) );
		if ( 'smtp' === $t ) { return ! empty( $c['hote'] ) && ! empty( $c['expediteur'] ); }
		if ( 'mail' === $t ) { return ! empty( $c['expediteur'] ) && function_exists( 'mail' ); }
		if ( 'fichier' === $t ) { return ! empty( $c['dossier'] ); }
		return false;
	}

	public static function derniereErreur() { return self::$erreur; }
	public static function dernierMessage() { return self::$brut; }

	/**
	 * Envoie un message.
	 *
	 * @param array $m a, cc, cci (chaîne ou liste), sujet, texte, html,
	 *                 pieces : liste de ['chemin' => …] ou ['contenu' => …, 'nom' => …, 'type' => …],
	 *                 repondre_a, entetes (liste d'en-têtes supplémentaires « Nom: valeur »).
	 * @return array{0:bool,1:string}
	 */
	public static function envoyer( array $m ) {
		self::$erreur = '';
		$c = self::config();
		try {
			if ( ! self::configure() ) { throw new \RuntimeException( 'Aucun transport de courriel n\'est configuré.' ); }
			$exp = self::adresse( (string) $c['expediteur'] );
			$a   = self::liste( $m['a'] ?? array() );
			$cc  = self::liste( $m['cc'] ?? array() );
			$cci = self::liste( $m['cci'] ?? array() );
			if ( ! $a && ! $cc && ! $cci ) { throw new \InvalidArgumentException( 'Aucun destinataire.' ); }
			$rep = ! empty( $m['repondre_a'] ) ? self::adresse( (string) $m['repondre_a'] )
				: ( ! empty( $c['repondre_a'] ) ? self::adresse( (string) $c['repondre_a'] ) : '' );

			list( $entetes, $corps ) = self::composer( $exp, (string) ( $c['nom_expediteur'] ?? '' ), $a, $cc, $rep, $m );
			$transport = (string) ( $c['transport'] ?? 'smtp' );

			if ( 'fichier' === $transport ) {
				$dir = rtrim( (string) $c['dossier'], '/' ) . '/';
				if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) { throw new \RuntimeException( 'Dossier de courriels inaccessible.' ); }
				$f = $dir . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ) . '.eml';
				if ( false === @file_put_contents( $f, 'X-Destinataires: ' . implode( ', ', array_merge( $a, $cc, $cci ) ) . "\r\n" . $entetes . "\r\n\r\n" . $corps ) ) {
					throw new \RuntimeException( 'Écriture du courriel impossible.' );
				}
			} elseif ( 'mail' === $transport ) {
				$sujet = self::encoderEntete( (string) ( $m['sujet'] ?? '' ) );
				// mail() pose lui-même To et Subject : on les retire des en-têtes.
				$h = preg_replace( '/^(To|Subject): .*(\r\n[ \t].*)*\r\n/mi', '', $entetes . "\r\n" );
				if ( $cci ) { $h .= 'Bcc: ' . implode( ', ', $cci ) . "\r\n"; }
				if ( ! @mail( implode( ', ', $a ?: $cc ), $sujet, $corps, rtrim( $h ), '-f' . $exp ) ) {
					throw new \RuntimeException( 'La fonction mail() a refusé l\'envoi.' );
				}
			} else {
				self::smtp( $c, $exp, array_merge( $a, $cc, $cci ), $entetes . "\r\n\r\n" . $corps );
			}
			self::journal( 'envoye', $a, (string) ( $m['sujet'] ?? '' ), '' );
			return array( true, 'Courriel envoyé à ' . implode( ', ', $a ?: $cc ?: $cci ) . '.' );
		} catch ( \Throwable $e ) {
			self::$erreur = self::masquer( $e->getMessage(), $c );
			self::journal( 'echec', self::liste( $m['a'] ?? array(), false ), (string) ( $m['sujet'] ?? '' ), self::$erreur );
			return array( false, self::$erreur );
		}
	}

	/* ══════════════════════ Composition MIME ══════════════════════ */

	protected static function composer( $exp, $nom, array $a, array $cc, $rep, array $m ) {
		$sujet = trim( preg_replace( '/[\r\n]+/', ' ', (string) ( $m['sujet'] ?? '' ) ) );
		$texte = (string) ( $m['texte'] ?? '' );
		$html  = isset( $m['html'] ) && '' !== (string) $m['html'] ? (string) $m['html'] : null;
		if ( '' === $texte && null !== $html ) {
			$texte = trim( html_entity_decode( strip_tags( preg_replace( '#<br\s*/?>|</p>#i', "\n", $html ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		$domaine = substr( strrchr( $exp, '@' ), 1 ) ?: 'localhost';

		$h   = array();
		$h[] = 'Date: ' . date( DATE_RFC2822 );
		$h[] = 'From: ' . ( '' !== trim( $nom ) ? self::encoderEntete( self::propre( $nom ) ) . ' <' . $exp . '>' : $exp );
		if ( $a )  { $h[] = 'To: ' . implode( ', ', $a ); }
		if ( $cc ) { $h[] = 'Cc: ' . implode( ', ', $cc ); }
		if ( '' !== $rep ) { $h[] = 'Reply-To: ' . $rep; }
		$h[] = 'Subject: ' . self::encoderEntete( $sujet );
		$h[] = 'Message-ID: <' . bin2hex( random_bytes( 12 ) ) . '@' . $domaine . '>';
		$h[] = 'MIME-Version: 1.0';
		$h[] = 'X-Mailer: FinaKop';
		foreach ( (array) ( $m['entetes'] ?? array() ) as $x ) {
			$x = self::propre( (string) $x );
			if ( preg_match( '/^[A-Za-z0-9-]+: .+$/', $x ) && ! preg_match( '/^(From|To|Cc|Bcc|Subject|Date|Message-ID|MIME-Version|Content-[A-Za-z-]+):/i', $x ) ) { $h[] = $x; }
		}

		$partie = function ( $type, $contenu ) {
			return "Content-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
				. rtrim( chunk_split( base64_encode( $contenu ), 76, "\r\n" ) );
		};
		if ( null !== $html ) {
			$b = 'alt-' . bin2hex( random_bytes( 8 ) );
			$contenu = "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n\r\n"
				. "--{$b}\r\n" . $partie( 'text/plain', $texte ) . "\r\n"
				. "--{$b}\r\n" . $partie( 'text/html', $html ) . "\r\n--{$b}--";
		} else {
			$contenu = $partie( 'text/plain', $texte );
		}

		$pieces = (array) ( $m['pieces'] ?? array() );
		if ( $pieces ) {
			$b = 'mix-' . bin2hex( random_bytes( 8 ) );
			$corps = "--{$b}\r\n" . $contenu . "\r\n";
			foreach ( $pieces as $p ) {
				if ( isset( $p['chemin'] ) ) {
					if ( ! is_file( $p['chemin'] ) || ! is_readable( $p['chemin'] ) ) { throw new \RuntimeException( 'Pièce jointe introuvable : ' . basename( (string) $p['chemin'] ) ); }
					$donnees = (string) file_get_contents( $p['chemin'] );
					$nomP = (string) ( $p['nom'] ?? basename( $p['chemin'] ) );
				} else {
					$donnees = (string) ( $p['contenu'] ?? '' );
					$nomP = (string) ( $p['nom'] ?? 'piece.bin' );
				}
				$nomP = str_replace( array( '"', "\r", "\n", '/', '\\' ), '', $nomP ) ?: 'piece.bin';
				$type = preg_match( '#^[a-z]+/[a-z0-9.+-]+$#i', (string) ( $p['type'] ?? '' ) ) ? $p['type'] : 'application/octet-stream';
				$corps .= "--{$b}\r\nContent-Type: {$type}; name=\"" . self::encoderEntete( $nomP ) . "\"\r\n"
					. "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . self::encoderEntete( $nomP ) . "\"\r\n\r\n"
					. rtrim( chunk_split( base64_encode( $donnees ), 76, "\r\n" ) ) . "\r\n";
			}
			$corps .= "--{$b}--";
			$h[] = "Content-Type: multipart/mixed; boundary=\"{$b}\"";
		} else {
			// Les en-têtes de contenu de la partie unique remontent dans l'en-tête du message.
			list( $ct, $corps ) = explode( "\r\n\r\n", $contenu, 2 );
			foreach ( explode( "\r\n", $ct ) as $l ) { $h[] = $l; }
		}
		self::$brut = implode( "\r\n", $h ) . "\r\n\r\n" . $corps;
		return array( implode( "\r\n", $h ), $corps );
	}

	/** En-tête encodé RFC 2047 si nécessaire (UTF-8, base64, lignes ≤ 76). */
	public static function encoderEntete( $s ) {
		$s = self::propre( (string) $s );
		if ( ! preg_match( '/[^\x20-\x7E]/', $s ) ) { return $s; }
		if ( function_exists( 'mb_encode_mimeheader' ) ) { return mb_encode_mimeheader( $s, 'UTF-8', 'B', "\r\n" ); }
		return '=?UTF-8?B?' . base64_encode( $s ) . '?=';
	}

	protected static function propre( $s ) { return trim( str_replace( array( "\r", "\n" ), ' ', $s ) ); }

	/** Adresse seule, validée, sans caractère d'injection. */
	protected static function adresse( $s ) {
		$s = trim( $s );
		if ( preg_match( '/<([^<>]+)>\s*$/', $s, $x ) ) { $s = trim( $x[1] ); }
		if ( '' === $s || false !== strpbrk( $s, "\r\n,;<>\"" ) || false === filter_var( $s, FILTER_VALIDATE_EMAIL ) ) {
			throw new \InvalidArgumentException( 'Adresse invalide : ' . substr( $s, 0, 80 ) );
		}
		return $s;
	}

	protected static function liste( $v, $valider = true ) {
		$v = is_array( $v ) ? $v : ( '' === trim( (string) $v ) ? array() : explode( ',', (string) $v ) );
		$out = array();
		foreach ( $v as $x ) {
			$x = trim( (string) $x );
			if ( '' === $x ) { continue; }
			$out[] = $valider ? self::adresse( $x ) : $x;
		}
		return array_values( array_unique( $out ) );
	}

	/* ══════════════════════ Client SMTP ══════════════════════ */

	protected static function smtp( array $c, $exp, array $rcpt, $donnees ) {
		$hote = (string) $c['hote'];
		$sec  = strtolower( (string) ( $c['securite'] ?? 'ssl' ) );
		$port = (int) ( $c['port'] ?? ( 'ssl' === $sec ? 465 : ( 'tls' === $sec ? 587 : 25 ) ) );
		$delai = max( 3, (int) ( $c['delai'] ?? 15 ) );
		$verif = ! array_key_exists( 'verifier_certificat', $c ) || (bool) $c['verifier_certificat'];
		$ssl = array( 'verify_peer' => $verif, 'verify_peer_name' => $verif, 'allow_self_signed' => ! $verif, 'peer_name' => $hote );

		$ctx = stream_context_create( array( 'ssl' => $ssl ) );
		$url = ( 'ssl' === $sec ? 'ssl://' : 'tcp://' ) . $hote . ':' . $port;
		$s = @stream_socket_client( $url, $errno, $errstr, $delai, STREAM_CLIENT_CONNECT, $ctx );
		if ( ! $s ) { throw new \RuntimeException( "Connexion SMTP impossible ({$hote}:{$port}) : {$errstr}" ); }
		stream_set_timeout( $s, $delai );
		try {
			self::attendre( $s, 220 );
			$helo = (string) ( $c['helo'] ?? ( gethostname() ?: 'localhost' ) );
			$ext = self::commande( $s, 'EHLO ' . $helo, 250 );
			if ( 'tls' === $sec ) {
				if ( false === stripos( $ext, 'STARTTLS' ) ) { throw new \RuntimeException( 'Le serveur SMTP ne propose pas STARTTLS.' ); }
				self::commande( $s, 'STARTTLS', 220 );
				foreach ( $ssl as $k => $v ) { stream_context_set_option( $s, 'ssl', $k, $v ); }
				if ( true !== @stream_socket_enable_crypto( $s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT' ) ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0 ) ) ) {
					throw new \RuntimeException( 'Négociation TLS refusée (certificat ?).' );
				}
				$ext = self::commande( $s, 'EHLO ' . $helo, 250 );
			}
			$user = (string) ( $c['utilisateur'] ?? '' );
			if ( '' !== $user ) {
				$pass = (string) ( $c['mot_de_passe'] ?? '' );
				if ( preg_match( '/AUTH[ =][^\r\n]*PLAIN/i', $ext ) ) {
					self::commande( $s, 'AUTH PLAIN ' . base64_encode( "\0" . $user . "\0" . $pass ), 235, true );
				} else {
					self::commande( $s, 'AUTH LOGIN', 334 );
					self::commande( $s, base64_encode( $user ), 334, true );
					self::commande( $s, base64_encode( $pass ), 235, true );
				}
			}
			self::commande( $s, 'MAIL FROM:<' . $exp . '>', 250 );
			foreach ( $rcpt as $r ) { self::commande( $s, 'RCPT TO:<' . $r . '>', array( 250, 251 ) ); }
			self::commande( $s, 'DATA', 354 );
			// Normalisation CRLF puis « dot-stuffing » (RFC 5321 §4.5.2).
			$d = preg_replace( "/\r\n|\r|\n/", "\r\n", $donnees );
			$d = preg_replace( '/^\./m', '..', $d );
			fwrite( $s, $d . "\r\n.\r\n" );
			self::attendre( $s, 250 );
			@fwrite( $s, "QUIT\r\n" );
		} finally {
			@fclose( $s );
		}
	}

	protected static function commande( $s, $ligne, $attendu, $secret = false ) {
		if ( false === @fwrite( $s, $ligne . "\r\n" ) ) { throw new \RuntimeException( 'Connexion SMTP interrompue.' ); }
		return self::attendre( $s, $attendu, $secret ? 'AUTH' : strtok( $ligne, ' ' ) );
	}

	protected static function attendre( $s, $attendu, $etape = 'accueil' ) {
		$rep = '';
		while ( false !== ( $l = fgets( $s, 1024 ) ) ) {
			$rep .= $l;
			if ( strlen( $l ) < 4 || ' ' === $l[3] ) { break; }
		}
		$meta = stream_get_meta_data( $s );
		if ( ! empty( $meta['timed_out'] ) ) { throw new \RuntimeException( "Délai SMTP dépassé ({$etape})." ); }
		$code = (int) substr( $rep, 0, 3 );
		if ( ! in_array( $code, (array) $attendu, true ) ) {
			throw new \RuntimeException( "Refus SMTP à l'étape {$etape} : " . trim( preg_replace( '/\s+/', ' ', $rep ) ) );
		}
		return $rep;
	}

	/** Ne jamais laisser un secret dans un message d'erreur. */
	protected static function masquer( $msg, array $c ) {
		foreach ( array( 'mot_de_passe', 'utilisateur' ) as $k ) {
			if ( ! empty( $c[ $k ] ) && strlen( (string) $c[ $k ] ) > 2 ) {
				$msg = str_replace( array( (string) $c[ $k ], base64_encode( (string) $c[ $k ] ) ), '***', $msg );
			}
		}
		return $msg;
	}

	protected static function journal( $statut, array $a, $sujet, $erreur ) {
		if ( function_exists( 'fkc_log' ) ) {
			fkc_log( 'Courriel ' . $statut . ' → ' . implode( ',', array_slice( $a, 0, 5 ) ) . ' « ' . mb_substr( $sujet, 0, 80 ) . ' »'
				. ( '' !== $erreur ? ' : ' . $erreur : '' ), 'envoye' === $statut ? 'INFO' : 'WARN' );
		}
	}
}
