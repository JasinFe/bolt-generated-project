<?php
/**
 * FKC_Plateforme_Sauvegarde — sauvegarde et restauration d'UN client (1.876.0).
 *
 * Archive = instantané cohérent (FKC_Plateforme_Instantane) + manifeste,
 * empaqueté en tar.gz, puis CHIFFRÉ (libsodium, XChaCha20-Poly1305 par blocs)
 * si une clé de sauvegarde est configurée. L'archive contient la clé de
 * chiffrement du client : sans chiffrement, quiconque la copie lit tout.
 *
 * Une sauvegarde n'est déclarée réussie qu'après relecture : l'archive est
 * rouverte, déchiffrée, extraite et recontrôlée contre son manifeste.
 *
 * Restauration : dans le client lui-même (l'état courant est mis de côté, pas
 * effacé) ou dans un NOUVEAU client (--vers), pour vérifier une sauvegarde sans
 * toucher à la production.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_Plateforme_Sauvegarde {

	const MAGIQUE = "FKCSAVE1";
	const BLOC    = 1048576;

	protected static function cle() {
		$c = (string) FKC_Config::get( 'sauvegarde.cle', '' );
		if ( '' === $c ) { return null; }
		$b = base64_decode( $c, true );
		if ( false === $b || 32 !== strlen( $b ) ) { throw new \RuntimeException( 'sauvegarde.cle invalide : 32 octets en base64 attendus (finakop sauvegarde:cle).' ); }
		if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) ) { throw new \RuntimeException( 'Extension sodium requise pour chiffrer les sauvegardes.' ); }
		return $b;
	}

	/** Crée, vérifie et range une sauvegarde. @return array{fichier:string, octets:int, controle:array} */
	public static function creer( array $t ) {
		$src  = FKC_Plateforme_Registre::dossierDonnees( $t );
		$dest = FKC_Plateforme_Registre::dossierSauvegardes( $t );
		if ( ! is_dir( $dest ) && ! @mkdir( $dest, 0700, true ) ) { throw new \RuntimeException( 'Dossier de sauvegarde inaccessible.' ); }
		$tmp = $dest . '.tmp-' . bin2hex( random_bytes( 4 ) ) . '/';
		try {
			$m = FKC_Plateforme_Instantane::capturer( $src, $tmp . 'donnees', array(
				'client' => array( 'slug' => $t['slug'], 'nom' => $t['nom'] ),
				'version_finakop' => FKC_Plateforme_Amorcage::version(),
			) );
			$ctl = FKC_Plateforme_Instantane::controler( $tmp . 'donnees', $m );
			if ( ! $ctl['ok'] ) { throw new \RuntimeException( 'Instantané non conforme : ' . implode( ' ; ', $ctl['anomalies'] ) ); }

			list( $final, $ctl2 ) = self::emballer( $tmp, $dest, $t['slug'] . '-' . gmdate( 'Ymd-His' ) );
			FKC_Plateforme_Registre::journaliser( 'sauvegarde', basename( $final ) . ' ' . filesize( $final ) . ' octets', (int) $t['id'] );
			self::rotation( $t );
			return array( 'fichier' => $final, 'octets' => (int) filesize( $final ), 'controle' => $ctl2 );
		} finally {
			FKC_Plateforme_Instantane::effacer( $tmp );
		}
	}

	/**
	 * Empaquette $tmp/donnees (instantané + manifeste), chiffre, range dans
	 * $dest, puis RELIT l'archive : une sauvegarde non relue n'est pas une sauvegarde.
	 * @return array{0:string,1:array} fichier final, contrôle de relecture
	 */
	protected static function emballer( $tmp, $dest, $nom ) {
		$tar = $tmp . $nom . '.tar';
		$ph = new \PharData( $tar );
		$ph->buildFromDirectory( $tmp . 'donnees' );
		$ph->compress( \Phar::GZ );
		unset( $ph );
		@unlink( $tar );
		$gz = $tar . '.gz';

		$cle = self::cle();
		$final = $dest . $nom . ( $cle ? '.fkcsave' : '.tar.gz' );
		if ( $cle ) { self::chiffrer( $gz, $final, $cle ); } else { rename( $gz, $final ); }
		@chmod( $final, 0600 );

		$verif = $tmp . 'relecture';
		self::extraire( $final, $verif );
		$ctl = FKC_Plateforme_Instantane::controler( $verif, json_decode( (string) file_get_contents( $verif . '/MANIFESTE.json' ), true ) ?: array() );
		if ( ! $ctl['ok'] ) { @unlink( $final ); throw new \RuntimeException( 'Relecture de l\'archive non conforme : ' . implode( ' ; ', $ctl['anomalies'] ) ); }
		return array( $final, $ctl );
	}

	/* ── Plateforme elle-même : registre des clients + configuration système ── */

	/** Pseudo-client de rangement : « _plateforme » n'est pas un identifiant valide, aucune collision possible. */
	public static function plateformeCible() { return array( 'id' => 0, 'slug' => '_plateforme', 'nom' => 'Plateforme', 'dossier' => '_plateforme' ); }

	/**
	 * Sauvegarde plateforme.db (copie cohérente) et config.php, chiffrées et relues.
	 * Avec les sauvegardes des clients, c'est de quoi tout reconstruire.
	 */
	public static function plateforme() {
		$t = self::plateformeCible();
		$dest = FKC_Plateforme_Registre::dossierSauvegardes( $t );
		if ( ! is_dir( $dest ) && ! @mkdir( $dest, 0700, true ) ) { throw new \RuntimeException( 'Dossier de sauvegarde inaccessible.' ); }
		$tmp = $dest . '.tmp-' . bin2hex( random_bytes( 4 ) ) . '/';
		try {
			mkdir( $tmp . 'source', 0700, true );
			$reg = FKC_Config::dossier( 'plateforme/plateforme.db' );
			$p = new \PDO( 'sqlite:' . $reg, null, null, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
			$p->exec( 'PRAGMA busy_timeout=10000' );
			$p->exec( "VACUUM INTO '" . str_replace( "'", "''", $tmp . 'source/plateforme.db' ) . "'" );
			$p = null;
			if ( ! copy( FKC_Config::fichier(), $tmp . 'source/config.php' ) ) { throw new \RuntimeException( 'Copie de la configuration impossible.' ); }
			$m = FKC_Plateforme_Instantane::capturer( $tmp . 'source', $tmp . 'donnees', array(
				'plateforme' => true, 'version_finakop' => FKC_Plateforme_Amorcage::version(),
				'config_origine' => FKC_Config::fichier(), 'donnees_origine' => FKC_Config::get( 'donnees' ),
			) );
			$ctl = FKC_Plateforme_Instantane::controler( $tmp . 'donnees', $m );
			if ( ! $ctl['ok'] ) { throw new \RuntimeException( 'Instantané non conforme : ' . implode( ' ; ', $ctl['anomalies'] ) ); }
			list( $final, $ctl2 ) = self::emballer( $tmp, $dest, 'plateforme-' . gmdate( 'Ymd-His' ) );
			FKC_Plateforme_Registre::journaliser( 'sauvegarde_plateforme', basename( $final ) . ' ' . filesize( $final ) . ' octets' );
			self::rotation( $t );
			return array( 'fichier' => $final, 'octets' => (int) filesize( $final ), 'controle' => $ctl2 );
		} finally {
			FKC_Plateforme_Instantane::effacer( $tmp );
		}
	}

	/**
	 * Restauration de la plateforme : extrait et CONTRÔLE dans un dossier vide,
	 * sans rien remplacer. La remise en place (2 fichiers) reste un geste
	 * humain, documenté : écraser le registre ou la configuration en
	 * production à la main d'un script serait trop risqué.
	 */
	public static function restaurerPlateforme( $archive, $dossier ) {
		$dossier = rtrim( (string) $dossier, '/' );
		if ( is_dir( $dossier ) && ( new \FilesystemIterator( $dossier ) )->valid() ) { throw new \RuntimeException( "Le dossier {$dossier} n'est pas vide." ); }
		self::extraire( $archive, $dossier );
		$m = json_decode( (string) file_get_contents( $dossier . '/MANIFESTE.json' ), true ) ?: array();
		if ( empty( $m['plateforme'] ) ) { throw new \RuntimeException( 'Cette archive est celle d\'un client, pas de la plateforme.' ); }
		$ctl = FKC_Plateforme_Instantane::controler( $dossier, $m );
		if ( ! $ctl['ok'] ) { throw new \RuntimeException( 'Archive non conforme : ' . implode( ' ; ', $ctl['anomalies'] ) ); }
		$ic = ( new \PDO( 'sqlite:' . $dossier . '/plateforme.db' ) )->query( 'PRAGMA integrity_check' )->fetchColumn();
		if ( 'ok' !== $ic ) { throw new \RuntimeException( 'plateforme.db restaurée : ' . $ic ); }
		@chmod( $dossier . '/config.php', 0600 );
		return array( 'controle' => $ctl, 'manifeste' => $m );
	}

	/** Conserve « retention_jours » jours, et toujours au moins les 3 dernières. */
	public static function rotation( array $t ) {
		// Restes d'une sauvegarde interrompue (processus arrêté par le délai du cron).
		foreach ( glob( FKC_Plateforme_Registre::dossierSauvegardes( $t ) . '.tmp-*', GLOB_ONLYDIR ) ?: array() as $d ) {
			if ( @filemtime( $d ) < time() - 7200 ) { FKC_Plateforme_Instantane::effacer( $d ); }
		}
		$l = self::lister( $t );
		$jours = max( 1, (int) FKC_Config::get( 'sauvegarde.retention_jours', 14 ) );
		foreach ( array_slice( $l, 3 ) as $f ) {
			if ( filemtime( $f ) < time() - $jours * 86400 ) { @unlink( $f ); }
		}
	}

	/** Sauvegardes d'un client, la plus récente d'abord. */
	public static function lister( array $t ) {
		$l = glob( FKC_Plateforme_Registre::dossierSauvegardes( $t ) . '*.{fkcsave,tar.gz}', GLOB_BRACE ) ?: array();
		usort( $l, function ( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ) ?: strcmp( $b, $a ); } );
		return $l;
	}

	/** Déchiffre si besoin et extrait une archive dans $dir. */
	public static function extraire( $archive, $dir ) {
		if ( ! is_file( $archive ) ) { throw new \RuntimeException( 'Archive introuvable : ' . $archive ); }
		if ( ! is_dir( $dir ) ) { mkdir( $dir, 0700, true ); }
		$gz = $archive;
		$tmpGz = null;
		$f = fopen( $archive, 'rb' ); $ent = fread( $f, 8 ); fclose( $f );
		if ( self::MAGIQUE === $ent ) {
			$cle = self::cle();
			if ( ! $cle ) { throw new \RuntimeException( 'Archive chiffrée : la clé de sauvegarde n\'est pas configurée.' ); }
			$tmpGz = rtrim( $dir, '/' ) . '.dechiffre.tar.gz';
			self::dechiffrer( $archive, $tmpGz, $cle );
			$gz = $tmpGz;
		}
		try {
			$ph = new \PharData( $gz );
			$ph->extractTo( $dir, null, true );
		} finally {
			if ( $tmpGz ) { @unlink( $tmpGz ); }
		}
		if ( ! is_file( rtrim( $dir, '/' ) . '/MANIFESTE.json' ) ) { throw new \RuntimeException( 'Archive sans manifeste.' ); }
	}

	/**
	 * Restaure une archive.
	 * @param array      $t     client cible existant, ou null avec $nouveau pour créer un client de vérification
	 * @return array{controle:array, mis_de_cote:?string, client:array}
	 */
	public static function restaurer( $archive, $slugCible, $creer = false ) {
		$t = FKC_Plateforme_Registre::parSlug( $slugCible );
		if ( $creer ) {
			if ( $t ) { throw new \RuntimeException( "Le client « {$slugCible} » existe déjà : choisissez un autre identifiant pour --vers." ); }
		} elseif ( ! $t ) {
			throw new \RuntimeException( "Client inconnu : {$slugCible}" );
		}
		$base = FKC_Config::dossier( 'tenants/' );
		$tmp = $base . '.restauration-' . bin2hex( random_bytes( 4 ) );
		self::extraire( $archive, $tmp );
		$m = json_decode( (string) file_get_contents( $tmp . '/MANIFESTE.json' ), true ) ?: array();
		$ctl = FKC_Plateforme_Instantane::controler( $tmp, $m );
		if ( ! $ctl['ok'] ) { FKC_Plateforme_Instantane::effacer( $tmp ); throw new \RuntimeException( 'Archive non conforme : ' . implode( ' ; ', $ctl['anomalies'] ) ); }
		@unlink( $tmp . '/MANIFESTE.json' );

		$misDeCote = null;
		if ( $creer ) {
			$t = FKC_Plateforme_Registre::ajouter( $slugCible, ( $m['client']['nom'] ?? $slugCible ) . ' (restauration)' );
			FKC_Plateforme_Registre::changerStatut( $slugCible, 'maintenance' );
			rename( $tmp, rtrim( FKC_Plateforme_Registre::dossierDonnees( $t ), '/' ) );
		} else {
			$statutAvant = $t['statut'];
			FKC_Plateforme_Registre::changerStatut( $slugCible, 'maintenance' );
			$actuel = rtrim( FKC_Plateforme_Registre::dossierDonnees( $t ), '/' );
			if ( is_dir( $actuel ) ) {
				$misDeCote = $actuel . '.avant-restauration-' . gmdate( 'Ymd-His' );
				rename( $actuel, $misDeCote );
			}
			rename( $tmp, $actuel );
			// Sessions ouvertes avant la restauration : elles désignent un état qui n'existe plus.
			FKC_Plateforme_Instantane::effacer( rtrim( FKC_Plateforme_Registre::dossierSessions( $t ), '/' ) );
			$t['statut'] = $statutAvant;
		}
		$donnees = FKC_Plateforme_Registre::dossierDonnees( $t );
		self::droits( $donnees );
		$ctl = FKC_Plateforme_Instantane::controler( $donnees, $m );
		if ( ! $ctl['ok'] ) { throw new \RuntimeException( 'Contrôle après restauration non conforme : ' . implode( ' ; ', $ctl['anomalies'] ) ); }
		FKC_Plateforme_Registre::changerStatut( $slugCible, $creer ? 'actif' : $t['statut'] ); // statut d'avant la restauration
		FKC_Plateforme_Registre::journaliser( 'restauration', basename( $archive ) . ( $misDeCote ? ' ; ancien état : ' . basename( $misDeCote ) : '' ), (int) $t['id'] );
		return array( 'controle' => $ctl, 'mis_de_cote' => $misDeCote, 'client' => FKC_Plateforme_Registre::parSlug( $slugCible ) );
	}

	/** Droits restreints après extraction : dossiers 0750, fichiers 0640, clé 0600. */
	protected static function droits( $dir ) {
		@chmod( rtrim( $dir, '/' ), 0750 );
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isLink() ) { continue; }
			@chmod( $f->getPathname(), $f->isDir() ? 0750 : ( FKC_Plateforme_Instantane::estCle( $f->getFilename() ) ? 0600 : 0640 ) );
		}
	}

	/* ── Chiffrement par blocs (libsodium secretstream) ───────────────────── */

	protected static function chiffrer( $src, $dst, $cle ) {
		list( $etat, $entete ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $cle );
		$in = fopen( $src, 'rb' ); $out = fopen( $dst, 'wb' );
		fwrite( $out, self::MAGIQUE . $entete );
		$taille = filesize( $src ); $lu = 0;
		do {
			$bloc = (string) fread( $in, self::BLOC );
			$lu += strlen( $bloc );
			$fin = $lu >= $taille || feof( $in );
			$c = sodium_crypto_secretstream_xchacha20poly1305_push( $etat, $bloc, '', $fin ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE );
			fwrite( $out, pack( 'N', strlen( $c ) ) . $c );
		} while ( ! $fin );
		fclose( $in ); fclose( $out );
		@unlink( $src );
	}

	protected static function dechiffrer( $src, $dst, $cle ) {
		$in = fopen( $src, 'rb' ); $out = fopen( $dst, 'wb' );
		try {
			if ( self::MAGIQUE !== fread( $in, 8 ) ) { throw new \RuntimeException( 'Format de sauvegarde inconnu.' ); }
			$etat = sodium_crypto_secretstream_xchacha20poly1305_init_pull( fread( $in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES ), $cle );
			$final = false;
			while ( ! $final ) {
				$l = fread( $in, 4 );
				if ( 4 !== strlen( (string) $l ) ) { throw new \RuntimeException( 'Archive tronquée.' ); }
				$n = unpack( 'N', $l )[1];
				$r = sodium_crypto_secretstream_xchacha20poly1305_pull( $etat, (string) fread( $in, $n ) );
				if ( false === $r ) { throw new \RuntimeException( 'Archive altérée ou mauvaise clé de sauvegarde.' ); }
				fwrite( $out, $r[0] );
				$final = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $r[1];
			}
		} finally {
			fclose( $in ); fclose( $out );
		}
	}
}
