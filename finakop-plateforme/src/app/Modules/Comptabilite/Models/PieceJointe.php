<?php
/**
 * Pièces justificatives / pièces comptables attachées aux écritures.
 * Les fichiers sont stockés sur disque (dossier protégé FKC_DATA_DIR/pieces),
 * les métadonnées dans la base de la société (table pieces_jointes).
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_PieceJointe {

	const MAX = 8388608; // 8 Mo

	/** Types autorisés : extension => mime. */
	public static function types() {
		return array(
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'gif'  => 'image/gif',
		);
	}

	/** Dossier de stockage (créé + protégé à la première utilisation). */
	public static function dir() {
		$base = ( defined( 'FKC_DATA_DIR' ) ? FKC_DATA_DIR : sys_get_temp_dir() . '/' );
		$dir  = rtrim( $base, '/' ) . '/pieces/';
		if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0775, true ); }
		$ht = $dir . '.htaccess';
		if ( ! is_file( $ht ) ) { @file_put_contents( $ht, "Require all denied\nDeny from all\n" ); }
		return $dir;
	}

	/**
	 * Types de pièces source pris en charge (libellés FR).
	 *
	 * `facture_fournisseur` (1.738.0) — LA PIÈCE QUI VENAIT DE L'EXTÉRIEUR
	 * ÉTAIT LA SEULE À NE PAS POUVOIR ÊTRE JOINTE.
	 *
	 * L'écriture, le brouillon, la facture de vente et l'immobilisation
	 * acceptaient un justificatif depuis longtemps. Pas la facture d'achat —
	 * qui est pourtant la seule dont l'original ne sort PAS du logiciel : on le
	 * reçoit par courrier, par e-mail ou en main propre, et c'est lui que
	 * réclamera un contrôle. On saisissait un montant sans pouvoir conserver le
	 * document qui le justifie.
	 */
	public static function sourceLabels() {
		return array(
			'ecriture'            => 'écriture',
			'brouillon'           => 'brouillon',
			'facture'             => 'facture',
			'facture_fournisseur' => 'facture fournisseur',
			'immobilisation'      => 'immobilisation',
		);
	}

	/** Réaffecte les pièces d'une source vers une autre (ex. brouillon → écriture à la validation). */
	public static function reassign( $fromType, $fromId, $toType, $toId ) {
		return (int) FKC_DB::q(
			'UPDATE pieces_jointes SET source_type=?, source_id=? WHERE source_type=? AND source_id=?',
			array( (string) $toType, (int) $toId, (string) $fromType, (int) $fromId )
		)->rowCount();
	}

	/** Supprime toutes les pièces d'une source (fichiers + lignes). */
	public static function purge( $type, $id ) {
		foreach ( self::forSource( $type, $id ) as $pj ) {
			$path = self::dir() . $pj['fichier'];
			if ( is_file( $path ) ) { @unlink( $path ); }
		}
		FKC_DB::q( 'DELETE FROM pieces_jointes WHERE source_type=? AND source_id=?', array( (string) $type, (int) $id ) );
	}

	/** Compte par lot : source_id => nombre de pièces (pour les indicateurs de liste). */
	public static function countMap( $type, $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids ) { return array(); }
		$ph = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$out = array();
		try {
			foreach ( FKC_DB::q( "SELECT source_id, COUNT(*) n FROM pieces_jointes WHERE source_type=? AND source_id IN ($ph) GROUP BY source_id", array_merge( array( (string) $type ), $ids ) )->fetchAll() as $r ) {
				$out[ (int) $r['source_id'] ] = (int) $r['n'];
			}
		} catch ( \Throwable $e ) {}
		return $out;
	}

	public static function forSource( $type, $id ) {
		return FKC_DB::q( 'SELECT * FROM pieces_jointes WHERE source_type=? AND source_id=? ORDER BY id DESC', array( (string) $type, (int) $id ) )->fetchAll();
	}
	public static function count( $type, $id ) {
		return (int) FKC_DB::q( 'SELECT COUNT(*) FROM pieces_jointes WHERE source_type=? AND source_id=?', array( (string) $type, (int) $id ) )->fetchColumn();
	}
	public static function find( $id ) {
		return FKC_DB::q( 'SELECT * FROM pieces_jointes WHERE id=?', array( (int) $id ) )->fetch() ?: null;
	}
	public static function absPath( $pj ) {
		return self::dir() . $pj['fichier'];
	}

	/** Diffuse une pièce au navigateur (inline pour PDF/images). */
	public static function stream( $pj ) {
		$path = self::absPath( $pj );
		if ( ! is_file( $path ) ) { http_response_code( 404 ); echo 'Fichier absent.'; return; }
		$inline = in_array( $pj['mime'], array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true );
		header( 'Content-Type: ' . ( $pj['mime'] ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $pj['nom_origine'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
	}

	/** Valide et enregistre un fichier téléversé pour une source (type+id). @return array( bool ok, string message ) */
	public static function add( $type, $id, $file ) {
		$type = (string) $type; $id = (int) $id;
		if ( ! $id || ! isset( self::sourceLabels()[ $type ] ) ) { return array( false, 'Source invalide.' ); }
		if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			return array( false, 'Aucun fichier reçu.' );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return array( false, 'Échec du téléversement (code ' . (int) $file['error'] . ').' );
		}
		if ( (int) $file['size'] <= 0 ) { return array( false, 'Fichier vide.' ); }
		if ( (int) $file['size'] > self::MAX ) { return array( false, 'Fichier trop volumineux (max 8 Mo).' ); }
		if ( class_exists( 'FKC_Storage' ) && ! FKC_Storage::within( (int) $file['size'] ) ) {
			return array( false, 'Quota de stockage atteint pour votre licence. Libérez de l\'espace ou passez à un palier supérieur.' );
		}

		$tmp = $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) && ! is_file( $tmp ) ) { return array( false, 'Fichier introuvable.' ); }

		$orig = (string) $file['name'];
		$ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
		$types = self::types();
		if ( ! isset( $types[ $ext ] ) ) {
			return array( false, 'Type non autorisé. Acceptés : PDF, JPG, PNG, WEBP, GIF.' );
		}
		$mime = self::sniff( $tmp, $ext );
		if ( null === $mime ) { return array( false, 'Le contenu du fichier ne correspond pas à son extension.' ); }

		$token = bin2hex( random_bytes( 8 ) );
		$name  = $type . '-' . $id . '-' . $token . '.' . $ext;
		$dest  = self::dir() . $name;
		$moved = is_uploaded_file( $tmp ) ? @move_uploaded_file( $tmp, $dest ) : @rename( $tmp, $dest );
		if ( ! $moved ) { return array( false, "Impossible d'enregistrer le fichier." ); }
		@chmod( $dest, 0640 ); // 1.876.0 : documents du client, illisibles des autres comptes du serveur

		FKC_DB::q(
			'INSERT INTO pieces_jointes(source_type,source_id,fichier,nom_origine,mime,taille,created_by) VALUES(?,?,?,?,?,?,?)',
			array( $type, $id, $name, self::cleanName( $orig ), $mime, (int) $file['size'], class_exists( 'FKC_Auth' ) ? FKC_Auth::id() : null )
		);
		return array( true, 'Pièce jointe ajoutée.' );
	}

	/** Nombre maximal de pièces acceptées en un seul téléversement. */
	const MAX_LOT = 20;

	/**
	 * Téléverse plusieurs fichiers (champ multiple) ; renvoie le nb ajoutés.
	 *
	 * Le plafond par lot complète celui par fichier (8 Mo) : sans lui, une
	 * requête pouvait enchaîner un nombre illimité de pièces et saturer le
	 * disque avant que le quota de licence soit consulté.
	 */
	public static function addMany( $type, $id, $files ) {
		if ( empty( $files ) || ! isset( $files['name'] ) ) { return 0; }
		$n = 0;
		$traites = 0;
		$names = (array) $files['name'];
		foreach ( $names as $k => $nm ) {
			if ( '' === (string) $nm ) { continue; }
			if ( ++$traites > self::MAX_LOT ) { break; }
			$one = array(
				'name' => $files['name'][ $k ], 'tmp_name' => $files['tmp_name'][ $k ],
				'size' => $files['size'][ $k ], 'error' => $files['error'][ $k ],
			);
			list( $ok ) = self::add( $type, $id, $one );
			if ( $ok ) { $n++; }
		}
		return $n;
	}

	public static function delete( $id ) {
		$pj = self::find( $id );
		if ( ! $pj ) { return array( false, 'Pièce introuvable.' ); }
		$path = self::absPath( $pj );
		if ( is_file( $path ) ) { @unlink( $path ); }
		FKC_DB::q( 'DELETE FROM pieces_jointes WHERE id=?', array( (int) $id ) );
		return array( true, 'Pièce supprimée.' );
	}

	/** Vérifie le type réel du fichier ; renvoie le mime ou null si incohérent. */
	protected static function sniff( $tmp, $ext ) {
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ) {
			$info = @getimagesize( $tmp );
			if ( ! $info || empty( $info['mime'] ) ) { return null; }
			return $info['mime'];
		}
		if ( 'pdf' === $ext ) {
			$fh = @fopen( $tmp, 'rb' );
			if ( ! $fh ) { return null; }
			$head = fread( $fh, 5 );
			fclose( $fh );
			return ( '%PDF-' === $head ) ? 'application/pdf' : null;
		}
		return null;
	}

	protected static function cleanName( $name ) {
		$name = basename( (string) $name );
		$name = preg_replace( '/[^\w .()\-]+/u', '_', $name );
		return substr( $name, 0, 150 );
	}

	public static function humanSize( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes >= 1048576 ) { return number_format( $bytes / 1048576, 1, ',', ' ' ) . ' Mo'; }
		if ( $bytes >= 1024 ) { return number_format( $bytes / 1024, 0, ',', ' ' ) . ' Ko'; }
		return $bytes . ' o';
	}
}
