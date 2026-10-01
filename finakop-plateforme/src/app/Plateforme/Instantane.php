<?php
/**
 * FKC_Plateforme_Instantane — copie cohérente d'un dossier de données + manifeste (1.876.0).
 *
 * Même format que scripts/migration-donnees.php (export depuis l'ancien
 * hébergement) : un import, une sauvegarde et une restauration se vérifient
 * donc avec le même contrôle.
 *
 *   • bases SQLite : « VACUUM INTO » = instantané transactionnel, même en service
 *     et même si des fichiers -wal/-shm existent ; intégrité vérifiée ;
 *   • autres fichiers (pièces, GED, logos, clé) : copiés, empreinte SHA-256 ;
 *   • manifeste : lignes de CHAQUE table de CHAQUE base, empreinte de chaque fichier.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_Plateforme_Instantane {

	/** Fichiers régénérés ou propres à un serveur : jamais copiés. */
	public static function ignore( $rel ) {
		$b = basename( $rel );
		return (bool) preg_match( '/\.(db|sqlite)-(wal|shm|journal)$/', $b )
			|| in_array( $b, array( '.htaccess', 'web.config', '.fkc-canary.txt', '.fkc-mot-de-passe-initial.txt' ), true );
	}

	/**
	 * Fichiers de clé d'un client : .fkc-secret.key (clé générée), ou
	 * .fkc-encryption-key (phrase FKC_ENCRYPTION_KEY reprise de wp-config.php).
	 * Toujours en 0600.
	 */
	public static function estCle( $rel ) {
		return in_array( basename( $rel ), array( '.fkc-secret.key', '.fkc-encryption-key' ), true );
	}

	public static function estBase( $chemin ) {
		if ( ! preg_match( '/\.(db|sqlite)$/', $chemin ) ) { return false; }
		$f = @fopen( $chemin, 'rb' );
		if ( ! $f ) { return false; }
		$ent = fread( $f, 16 ); fclose( $f );
		return "SQLite format 3\0" === $ent;
	}

	/** @return array<string,string> chemin relatif => absolu */
	public static function lister( $racine ) {
		$racine = rtrim( $racine, '/' ) . '/';
		$out = array();
		if ( ! is_dir( $racine ) ) { return $out; }
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $racine, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() || $f->isLink() ) { continue; }
			$rel = substr( $f->getPathname(), strlen( $racine ) );
			if ( ! self::ignore( $rel ) ) { $out[ $rel ] = $f->getPathname(); }
		}
		ksort( $out );
		return $out;
	}

	protected static function pdo( $f ) {
		$p = new \PDO( 'sqlite:' . $f, null, null, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
		$p->exec( 'PRAGMA busy_timeout=20000' );
		return $p;
	}

	public static function tables( $f ) {
		$p = self::pdo( $f ); $t = array();
		foreach ( $p->query( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name" )->fetchAll( \PDO::FETCH_COLUMN ) as $n ) {
			$t[ $n ] = (int) $p->query( 'SELECT COUNT(*) FROM "' . str_replace( '"', '""', $n ) . '"' )->fetchColumn();
		}
		return $t;
	}

	/**
	 * Copie $src vers $dst (dossier neuf) et renvoie le manifeste.
	 * @return array manifeste
	 */
	public static function capturer( $src, $dst, array $meta = array() ) {
		$src = rtrim( $src, '/' ) . '/';
		$dst = rtrim( $dst, '/' ) . '/';
		if ( ! is_dir( $dst ) && ! @mkdir( $dst, 0700, true ) ) { throw new \RuntimeException( 'Création impossible : ' . $dst ); }
		$m = array_merge( array( 'format' => 1, 'cree_le' => gmdate( 'c' ), 'php' => PHP_VERSION,
			'sqlite' => (string) self::pdo( ':memory:' )->query( 'SELECT sqlite_version()' )->fetchColumn(),
			'decalage_sqlite_s' => (int) self::pdo( ':memory:' )->query( "SELECT strftime('%s','now','localtime') - strftime('%s','now')" )->fetchColumn(),
			'bases' => array(), 'fichiers' => array(), 'cle_fichier' => is_file( $src . '.fkc-secret.key' ),
			'cle_constante' => is_file( $src . '.fkc-encryption-key' ) ), $meta );

		foreach ( self::lister( $src ) as $rel => $abs ) {
			$cible = $dst . $rel;
			if ( ! is_dir( dirname( $cible ) ) ) { mkdir( dirname( $cible ), 0700, true ); }
			if ( self::estBase( $abs ) ) {
				$p = self::pdo( $abs );
				$ic = (string) $p->query( 'PRAGMA integrity_check' )->fetchColumn();
				if ( 'ok' !== $ic ) { throw new \RuntimeException( "Base corrompue à la source ({$rel}) : {$ic}" ); }
				$p->exec( 'VACUUM INTO ' . $p->quote( $cible ) );
				$p = null;
				$m['bases'][ $rel ] = array( 'tables' => self::tables( $cible ), 'integrite_source' => $ic );
			} else {
				if ( ! @copy( $abs, $cible ) ) { throw new \RuntimeException( 'Copie impossible : ' . $rel ); }
				@touch( $cible, (int) filemtime( $abs ) );
				$m['fichiers'][ $rel ] = hash_file( 'sha256', $cible );
			}
		}
		file_put_contents( $dst . 'MANIFESTE.json', json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		return $m;
	}

	/**
	 * Vérifie un dossier contre un manifeste.
	 * @param bool $exact true : les tables doivent avoir EXACTEMENT le même nombre de lignes.
	 * @return array{ok:bool, anomalies:array, bases:int, lignes:int, fichiers:int}
	 */
	public static function controler( $dir, array $m, $exact = true ) {
		$dir = rtrim( $dir, '/' ) . '/';
		$an = array(); $lignes = 0;
		foreach ( (array) ( $m['bases'] ?? array() ) as $rel => $info ) {
			if ( ! is_file( $dir . $rel ) ) { $an[] = "base manquante : {$rel}"; continue; }
			try {
				$ic = (string) self::pdo( $dir . $rel )->query( 'PRAGMA integrity_check' )->fetchColumn();
				if ( 'ok' !== $ic ) { $an[] = "{$rel} : intégrité {$ic}"; }
				$now = self::tables( $dir . $rel );
			} catch ( \Throwable $e ) { $an[] = "{$rel} : " . $e->getMessage(); continue; }
			foreach ( (array) $info['tables'] as $t => $n ) {
				$lignes += (int) $n;
				if ( ! array_key_exists( $t, $now ) ) { $an[] = "{$rel} : table disparue {$t}"; }
				elseif ( $exact ? $now[ $t ] !== (int) $n : $now[ $t ] < (int) $n ) { $an[] = "{$rel} : {$t} = {$now[$t]} ligne(s) au lieu de {$n}"; }
			}
		}
		foreach ( (array) ( $m['fichiers'] ?? array() ) as $rel => $sha ) {
			if ( ! is_file( $dir . $rel ) ) { $an[] = "fichier manquant : {$rel}"; }
			elseif ( ! hash_equals( (string) $sha, hash_file( 'sha256', $dir . $rel ) ) ) { $an[] = "fichier altéré : {$rel}"; }
		}
		if ( ! empty( $m['cle_fichier'] ) && ! is_file( $dir . '.fkc-secret.key' ) ) { $an[] = 'clé de chiffrement (.fkc-secret.key) absente'; }
		if ( ! empty( $m['cle_constante'] ) && ! is_file( $dir . '.fkc-encryption-key' ) ) { $an[] = 'clé de chiffrement (.fkc-encryption-key) absente'; }
		return array( 'ok' => ! $an, 'anomalies' => $an, 'bases' => count( (array) ( $m['bases'] ?? array() ) ),
			'lignes' => $lignes, 'fichiers' => count( (array) ( $m['fichiers'] ?? array() ) ) );
	}

	/** Suppression récursive (dossiers temporaires uniquement). */
	public static function effacer( $dir ) {
		if ( ! is_dir( $dir ) ) { return; }
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
		@rmdir( $dir );
	}

	/** Copie récursive (restauration, import). */
	public static function copier( $src, $dst ) {
		$src = rtrim( $src, '/' ) . '/'; $dst = rtrim( $dst, '/' ) . '/';
		if ( ! is_dir( $dst ) ) { mkdir( $dst, 0750, true ); }
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			$rel = substr( $f->getPathname(), strlen( $src ) );
			if ( $f->isLink() ) { continue; }
			if ( $f->isDir() ) { if ( ! is_dir( $dst . $rel ) ) { mkdir( $dst . $rel, 0750, true ); } continue; }
			if ( ! @copy( $f->getPathname(), $dst . $rel ) ) { throw new \RuntimeException( 'Copie impossible : ' . $rel ); }
			@chmod( $dst . $rel, self::estCle( $rel ) ? 0600 : 0640 );
		}
	}
}
