<?php
/** Moteur de chiffrement symétrique au repos (libsodium prioritaire, repli OpenSSL AES-256-GCM).
 * @package FinaKop_ERP_Core */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Crypto {

	protected static $key = null;
	protected static $source = 'absente';

	/** Le chiffrement est-il opérationnel sur cet hébergement ? */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) || ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) );
	}
	public static function algo() {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) { return 'libsodium (XSalsa20-Poly1305)'; }
		return 'OpenSSL AES-256-GCM';
	}
	public static function keySource() { self::key(); return self::$source; }

	/** Clé maître 32 octets : constante wp-config prioritaire, sinon fichier protégé auto-généré. */
	public static function key() {
		if ( null !== self::$key ) { return self::$key; }
		// 1) Constante définie dans wp-config.php (recommandé en production).
		if ( defined( 'FKC_ENCRYPTION_KEY' ) && '' !== (string) FKC_ENCRYPTION_KEY ) {
			// Dérivation HKDF plutôt qu'un simple SHA-256 : la constante est une
			// phrase choisie par un humain, donc de faible entropie. HKDF-SHA256
			// avec sel et contexte fixes donne une clé de 32 octets sans exposer
			// la phrase à une attaque par dictionnaire sur un simple condensat.
			if ( function_exists( 'hash_hkdf' ) ) {
				$cle = hash_hkdf( 'sha256', (string) FKC_ENCRYPTION_KEY, 32, 'finakop-erp-core:v1:master', 'fkc-kdf-salt-v1' );
			} else {
				$cle = hash( 'sha256', 'finakop-erp-core:v1:' . (string) FKC_ENCRYPTION_KEY, true );
			}
			self::controlerEmpreinte( $cle, 'la constante FKC_ENCRYPTION_KEY' );
			self::$source = 'constante';
			return self::$key = $cle;
		}
		// 2) Fichier clé dans le dossier de données (hors web, permissions restreintes).
		$path = ( defined( 'FKC_DATA_DIR' ) ? FKC_DATA_DIR : sys_get_temp_dir() . '/' ) . '.fkc-secret.key';
		if ( is_file( $path ) ) {
			$raw = trim( (string) @file_get_contents( $path ) );
			$bin = base64_decode( $raw, true );
			if ( false !== $bin && 32 === strlen( $bin ) ) {
				self::controlerEmpreinte( $bin, 'le fichier .fkc-secret.key' );
				self::$source = 'fichier';
				return self::$key = $bin;
			}
		}
		/*
		 * 3) Aucune clé : on n'en fabrique une que pour une installation NEUVE.
		 *
		 * Avant la 1.875.6, une clé neuve était générée en silence dès que le
		 * fichier manquait. Or il manque précisément dans le cas le plus
		 * courant de perte : un déménagement qui oublie ce fichier caché, ou
		 * une constante wp-config.php non reportée. Tous les secrets déjà
		 * chiffrés (clés d'API, Mobile Money, SMTP, pièces Connect…)
		 * devenaient illisibles, sans un message. On refuse désormais.
		 */
		if ( self::donneesDejaChiffrees() ) {
			self::$source = 'manquante';
			throw new \RuntimeException(
				'FinaKop : clé de chiffrement introuvable alors que des données chiffrées existent. '
				. 'Restaurez le fichier .fkc-secret.key dans le dossier de données, ou la constante '
				. 'FKC_ENCRYPTION_KEY d\'origine. Aucune clé neuve n\'a été créée : vos données sont intactes.'
			);
		}
		$bin = function_exists( 'random_bytes' ) ? random_bytes( 32 ) : openssl_random_pseudo_bytes( 32 );
		@file_put_contents( $path, base64_encode( $bin ) );
		@chmod( $path, 0600 );
		self::enregistrerEmpreinte( $bin );
		self::$source = 'fichier';
		return self::$key = $bin;
	}

	/* ── Empreinte de la clé (1.875.6) ────────────────────────────────────
	 *
	 * Le registre cabinet garde une EMPREINTE de la clé (HMAC, 16 octets en
	 * hexadécimal : elle ne permet pas de retrouver la clé). Les bases voyagent
	 * toujours avec les données ; un fichier caché ou une constante, non. Si la
	 * clé présente ne correspond plus à l'empreinte, on le dit au lieu de
	 * laisser l'application écrire avec une clé qui ne relit rien.
	 *
	 * Connexion PDO distincte de FKC_Master : la clé peut être demandée pendant
	 * les migrations du registre, et on ne doit ni les relancer ni en dépendre.
	 */
	const EMPREINTE = 'crypto_empreinte';

	/** Empreinte non réversible d'une clé. */
	public static function empreinte( $cle ) {
		return substr( hash_hmac( 'sha256', 'finakop-empreinte-cle-v1', (string) $cle ), 0, 32 );
	}

	/** Connexion courte au registre, ou null s'il n'existe pas encore. */
	protected static function registre() {
		if ( ! defined( 'FKC_DATA_DIR' ) ) { return null; }
		$f = defined( 'FKC_MASTER_FILE' ) ? FKC_MASTER_FILE : FKC_DATA_DIR . 'finakopcore-master.db';
		if ( ! is_file( $f ) || ! extension_loaded( 'pdo_sqlite' ) ) { return null; }
		try {
			$pdo = new \PDO( 'sqlite:' . $f, null, null, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
			$pdo->exec( 'PRAGMA busy_timeout=5000' );
			$ok = $pdo->query( "SELECT 1 FROM sqlite_master WHERE type='table' AND name='cab_parametres'" )->fetchColumn();
			return $ok ? $pdo : null;
		} catch ( \Throwable $e ) { return null; }
	}

	protected static function empreinteEnregistree() {
		$pdo = self::registre();
		if ( ! $pdo ) { return null; }
		try {
			$v = $pdo->query( "SELECT valeur FROM cab_parametres WHERE cle='" . self::EMPREINTE . "'" )->fetchColumn();
			return ( false === $v || '' === (string) $v ) ? null : (string) $v;
		} catch ( \Throwable $e ) { return null; }
	}

	protected static function enregistrerEmpreinte( $cle ) {
		$pdo = self::registre();
		if ( ! $pdo ) { return; } // installation neuve : posée au prochain appel, registre créé
		try {
			$pdo->prepare( 'INSERT INTO cab_parametres(cle,valeur) VALUES(?,?) ON CONFLICT(cle) DO NOTHING' )
				->execute( array( self::EMPREINTE, self::empreinte( $cle ) ) );
		} catch ( \Throwable $e ) {} // registre en lecture seule : on ne bloque pas pour autant
	}

	/**
	 * La clé présente est-elle celle qui a chiffré les données ?
	 * Première rencontre : l'empreinte est posée. Divergence : refus explicite.
	 */
	protected static function controlerEmpreinte( $cle, $origine ) {
		$connue = self::empreinteEnregistree();
		if ( null === $connue ) { self::enregistrerEmpreinte( $cle ); return; }
		if ( hash_equals( $connue, self::empreinte( $cle ) ) ) { return; }
		// Une constante d'avant la dérivation HKDF reste acceptée (voir legacyKey()).
		$heritee = self::legacyKey();
		if ( null !== $heritee && hash_equals( $connue, self::empreinte( $heritee ) ) ) { return; }
		self::$source = 'divergente';
		throw new \RuntimeException(
			'FinaKop : ' . $origine . ' ne correspond pas à la clé qui a chiffré ces données. '
			. 'Rétablissez la clé d\'origine (fichier .fkc-secret.key ou constante FKC_ENCRYPTION_KEY). '
			. 'Rien n\'a été modifié.'
		);
	}

	/**
	 * Des données chiffrées existent-elles déjà ? (appelé seulement quand
	 * aucune clé n'est trouvée — donc au plus une fois dans la vie d'une
	 * installation saine.)
	 */
	protected static function donneesDejaChiffrees() {
		if ( null !== self::empreinteEnregistree() ) { return true; }
		if ( ! defined( 'FKC_DATA_DIR' ) ) { return false; }
		$fin = microtime( true ) + 20; // borne : ce contrôle ne doit jamais figer une page
		foreach ( glob( FKC_DATA_DIR . '*.db' ) ?: array() as $f ) {
			try {
				$pdo = new \PDO( 'sqlite:' . $f, null, null, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
				$pdo->exec( 'PRAGMA busy_timeout=5000' );
				foreach ( $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" )->fetchAll( \PDO::FETCH_COLUMN ) as $t ) {
					$qt = '"' . str_replace( '"', '""', $t ) . '"';
					foreach ( $pdo->query( 'PRAGMA table_info(' . $qt . ')' )->fetchAll( \PDO::FETCH_ASSOC ) as $c ) {
						$type = strtoupper( (string) $c['type'] );
						if ( '' !== $type && false === strpos( $type, 'TEXT' ) && false === strpos( $type, 'CHAR' ) && false === strpos( $type, 'CLOB' ) ) { continue; }
						$qc = '"' . str_replace( '"', '""', $c['name'] ) . '"';
						if ( $pdo->query( "SELECT 1 FROM {$qt} WHERE {$qc} LIKE 'FKC_.%' LIMIT 1" )->fetchColumn() ) { return true; }
						if ( microtime( true ) > $fin ) { return false; }
					}
				}
			} catch ( \Throwable $e ) { continue; }
		}
		return false;
	}

	/**
	 * Clé héritée de la dérivation d'origine (SHA-256 simple de la constante).
	 *
	 * Indispensable à la compatibilité : les secrets chiffrés avant le passage à
	 * HKDF ne sont déchiffrables qu'avec elle. decrypt() l'essaie en second
	 * recours, et enc() réécrit à la clé courante — la migration se fait donc
	 * d'elle-même, à chaque lecture suivie d'écriture.
	 *
	 * @return string|null 32 octets, ou null si non applicable.
	 */
	protected static function legacyKey() {
		if ( ! defined( 'FKC_ENCRYPTION_KEY' ) || '' === (string) FKC_ENCRYPTION_KEY ) { return null; }
		return hash( 'sha256', (string) FKC_ENCRYPTION_KEY, true );
	}

	public static function isEncrypted( $v ) {
		return is_string( $v ) && ( 0 === strpos( $v, 'FKC1.' ) || 0 === strpos( $v, 'FKC2.' ) );
	}

	/** Chiffre une chaîne -> jeton opaque versionné (ou null si indisponible). */
	public static function encrypt( $plain ) {
		if ( null === $plain ) { return null; }
		$plain = (string) $plain;
		$key = self::key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ct = sodium_crypto_secretbox( $plain, $nonce, $key );
			return 'FKC1.' . self::b64( $nonce . $ct );
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = random_bytes( 12 ); $tag = '';
			$ct = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false === $ct ) { return null; }
			return 'FKC2.' . self::b64( $iv . $tag . $ct );
		}
		return null;
	}

	/** Déchiffre un jeton ; retourne null si invalide/altéré. */
	public static function decrypt( $cipher ) {
		if ( ! is_string( $cipher ) || strlen( $cipher ) < 6 ) { return null; }
		// Clé courante d'abord, puis clé héritée : un secret écrit avant le
		// passage à HKDF reste lisible.
		foreach ( array( self::key(), self::legacyKey() ) as $key ) {
			if ( null === $key ) { continue; }
			$pt = self::decryptAvec( $cipher, $key );
			if ( null !== $pt ) { return $pt; }
		}
		return null;
	}

	/** Tentative de déchiffrement avec une clé donnée. */
	protected static function decryptAvec( $cipher, $key ) {
		$ver = substr( $cipher, 0, 5 );
		$raw = self::unb64( substr( $cipher, 5 ) );
		if ( false === $raw ) { return null; }
		try {
			if ( 'FKC1.' === $ver && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$nl = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
				$nonce = substr( $raw, 0, $nl ); $ct = substr( $raw, $nl );
				$pt = sodium_crypto_secretbox_open( $ct, $nonce, $key );
				return false === $pt ? null : $pt;
			}
			if ( 'FKC2.' === $ver && function_exists( 'openssl_decrypt' ) ) {
				$iv = substr( $raw, 0, 12 ); $tag = substr( $raw, 12, 16 ); $ct = substr( $raw, 28 );
				$pt = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				return false === $pt ? null : $pt;
			}
		} catch ( \Throwable $e ) { return null; }
		return null;
	}

	/** Confort : chiffre pour le stockage (laisse passer null/chaîne vide). */
	public static function enc( $v ) {
		if ( null === $v || '' === $v ) { return $v; }
		if ( ! self::available() ) { return $v; }
		$c = self::encrypt( $v );
		return null === $c ? $v : $c;
	}
	/** Confort : déchiffre en lecture ; renvoie tel quel si non chiffré (compat données héritées). */
	public static function dec( $v ) {
		if ( ! self::isEncrypted( $v ) ) { return $v; }
		$p = self::decrypt( $v );
		return null === $p ? '' : $p;
	}

	protected static function b64( $b ) { return rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' ); }
	protected static function unb64( $s ) { return base64_decode( strtr( $s, '-_', '+/' ), true ); }
}
