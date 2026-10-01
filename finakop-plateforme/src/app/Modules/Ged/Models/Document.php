<?php
/**
 * GED — Bibliothèque de documents (indépendante des pièces comptables).
 * Fichiers stockés sur disque (FKC_DATA_DIR/ged, protégé), métadonnées en base
 * (table ged_documents).
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_GedDocument {

	const MAX = 16777216; // 16 Mo

	/** Extensions autorisées => libellé. */
	public static function types() {
		return array(
			'pdf' => 'PDF', 'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'webp' => 'Image', 'gif' => 'Image',
			'doc' => 'Word', 'docx' => 'Word', 'xls' => 'Excel', 'xlsx' => 'Excel', 'csv' => 'CSV',
			'ppt' => 'PowerPoint', 'pptx' => 'PowerPoint', 'txt' => 'Texte', 'zip' => 'Archive',
		);
	}

	/** Catégories suggérées. */
	public static function categories() {
		return array( 'Contrats', 'Juridique', 'RH', 'Fiscal & social', 'Banque', 'Achats', 'Ventes', 'Administratif', 'Divers' );
	}

	/** Types de rattachement (tiers / dossier). */
	public static function linkTypes() {
		return array(
			''           => 'Aucun',
			'client'     => 'Client',
			'fournisseur'=> 'Fournisseur',
			'employe'    => 'Employé',
			'projet'     => 'Projet / dossier',
			/*
			 * REVENU (1.666.0). Un revenu artistique s'accompagne presque
			 * toujours d'une pièce : contrat de cession, rapport du
			 * distributeur, avis de virement, attestation de retenue. Sans
			 * ce type de lien, ces pièces se rangeaient sous « Autre » et
			 * devenaient introuvables depuis le revenu qu'elles justifient —
			 * or c'est au moment de préparer une déclaration qu'on les
			 * cherche, et à ce moment-là seulement.
			 */
			'revenu'     => 'Revenu',
			'recu'       => 'Reçu',
			/*
			 * RÈGLEMENT (1.752.0). Un paiement fournisseur s'accompagne d'une
			 * pièce : reçu Wave ou Orange Money, avis de virement, bordereau
			 * de remise de chèque. Sans ce type de lien, ces justificatifs se
			 * rangeaient sous « Autre » et devenaient introuvables depuis le
			 * règlement qu'ils prouvent — or c'est au contrôle fiscal ou au
			 * rapprochement bancaire qu'on les cherche, et là seulement.
			 */
			'reglement'  => 'Règlement',
			'autre'      => 'Autre',
		);
	}

	public static function linkLabel( $type ) { $a = self::linkTypes(); return $a[ (string) $type ] ?? $type; }

	/** Découpe une chaîne de tags en liste normalisée (uniques, sans vides). */
	public static function parseTags( $str ) {
		$out = array();
		foreach ( preg_split( '/[,;]+/', (string) $str ) as $t ) {
			$t = trim( $t );
			if ( '' !== $t && ! in_array( $t, $out, true ) ) { $out[] = $t; }
		}
		return $out;
	}

	/** Tous les tags distincts utilisés (triés). */
	public static function allTags() {
		$set = array();
		foreach ( FKC_DB::q( "SELECT tags FROM ged_documents WHERE tags IS NOT NULL AND tags<>''" )->fetchAll() as $r ) {
			foreach ( self::parseTags( $r['tags'] ) as $t ) { $set[ $t ] = true; }
		}
		$tags = array_keys( $set );
		natcasesort( $tags );
		return array_values( $tags );
	}

	/** Dossier de stockage (créé + protégé à la première utilisation). */
	public static function dir() {
		$base = ( defined( 'FKC_DATA_DIR' ) ? FKC_DATA_DIR : sys_get_temp_dir() . '/' );
		$dir  = rtrim( $base, '/' ) . '/ged/';
		if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0775, true ); }
		$ht = $dir . '.htaccess';
		if ( ! is_file( $ht ) ) { @file_put_contents( $ht, "Require all denied\nDeny from all\n" ); }
		return $dir;
	}

	public static function all( $categorie = '', $tag = '' ) {
		$categorie = trim( (string) $categorie );
		$tag = trim( (string) $tag );
		if ( '' !== $categorie ) {
			$rows = FKC_DB::q( 'SELECT * FROM ged_documents WHERE categorie=? ORDER BY id DESC', array( $categorie ) )->fetchAll();
		} else {
			$rows = FKC_DB::q( 'SELECT * FROM ged_documents ORDER BY id DESC' )->fetchAll();
		}
		if ( '' !== $tag ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $tag ) {
				return in_array( $tag, self::parseTags( $r['tags'] ?? '' ), true );
			} ) );
		}
		return $rows;
	}

	public static function find( $id ) {
		return FKC_DB::q( 'SELECT * FROM ged_documents WHERE id=?', array( (int) $id ) )->fetch() ?: null;
	}

	/** Documents rattachés à une entité (lien_type + lien_id), du plus récent au plus ancien. */
	public static function byLink( $type, $id ) {
		return FKC_DB::q(
			'SELECT * FROM ged_documents WHERE lien_type=? AND lien_id=? ORDER BY id DESC',
			array( (string) $type, (int) $id )
		)->fetchAll();
	}

	public static function count() {
		return (int) FKC_DB::q( 'SELECT COUNT(*) FROM ged_documents' )->fetchColumn();
	}

	public static function totalSize() {
		return (int) FKC_DB::q( 'SELECT COALESCE(SUM(taille),0) FROM ged_documents' )->fetchColumn();
	}

	/** Catégories réellement utilisées (pour le filtre). */
	public static function usedCategories() {
		$out = array();
		foreach ( FKC_DB::q( "SELECT DISTINCT categorie FROM ged_documents WHERE categorie IS NOT NULL AND categorie<>'' ORDER BY categorie" )->fetchAll() as $r ) {
			$out[] = $r['categorie'];
		}
		return $out;
	}

	public static function absPath( $doc ) { return self::dir() . $doc['fichier']; }

	/** Détecte un mime plausible selon l'extension (contrôle de cohérence léger). */
	protected static function sniff( $tmp, $ext ) {
		$fh = @fopen( $tmp, 'rb' ); $head = $fh ? fread( $fh, 8 ) : ''; if ( $fh ) { fclose( $fh ); }
		$types = self::types();
		$label = $types[ $ext ] ?? '';
		// Types binaires à signature.
		if ( 'pdf' === $ext ) { return 0 === strncmp( $head, '%PDF', 4 ) ? 'application/pdf' : null; }
		if ( in_array( $ext, array( 'jpg', 'jpeg' ), true ) ) { return "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ? 'image/jpeg' : null; }
		if ( 'png' === $ext ) { return "\x89PNG" === substr( $head, 0, 4 ) ? 'image/png' : null; }
		if ( 'gif' === $ext ) { return 'GIF8' === substr( $head, 0, 4 ) ? 'image/gif' : null; }
		if ( 'webp' === $ext ) { return 'RIFF' === substr( $head, 0, 4 ) ? 'image/webp' : null; }
		// Conteneurs ZIP (docx/xlsx/pptx/zip) : signature PK.
		if ( in_array( $ext, array( 'docx', 'xlsx', 'pptx', 'zip' ), true ) ) {
			return ( "PK\x03\x04" === substr( $head, 0, 4 ) || "PK\x05\x06" === substr( $head, 0, 4 ) ) ? 'application/octet-stream' : null;
		}
		// Anciens formats Office (OLE) : signature D0 CF 11 E0.
		if ( in_array( $ext, array( 'doc', 'xls', 'ppt' ), true ) ) {
			return "\xD0\xCF\x11\xE0" === substr( $head, 0, 4 ) ? 'application/octet-stream' : null;
		}
		// Texte (csv/txt) : accepté tel quel.
		if ( in_array( $ext, array( 'csv', 'txt' ), true ) ) { return 'text/plain'; }
		return $label ? 'application/octet-stream' : null;
	}

	/** Valide et enregistre un document. @return array( bool ok, string message, int id ) */
	public static function add( $data, $file ) {
		$titre = trim( (string) ( $data['titre'] ?? '' ) );
		if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			return array( false, 'Aucun fichier reçu.', 0 );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] ) { return array( false, 'Échec du téléversement (code ' . (int) $file['error'] . ').', 0 ); }
		if ( (int) $file['size'] <= 0 ) { return array( false, 'Fichier vide.', 0 ); }
		if ( (int) $file['size'] > self::MAX ) { return array( false, 'Fichier trop volumineux (max 16 Mo).', 0 ); }
		if ( class_exists( 'FKC_Storage' ) && ! FKC_Storage::within( (int) $file['size'] ) ) {
			return array( false, 'Quota de stockage atteint pour votre licence.', 0 );
		}
		$tmp = $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) && ! is_file( $tmp ) ) { return array( false, 'Fichier introuvable.', 0 ); }
		$orig = (string) $file['name'];
		$ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
		if ( ! isset( self::types()[ $ext ] ) ) { return array( false, 'Type non autorisé.', 0 ); }
		$mime = self::sniff( $tmp, $ext );
		if ( null === $mime ) { return array( false, 'Le contenu du fichier ne correspond pas à son extension.', 0 ); }

		$token = bin2hex( random_bytes( 8 ) );
		$name  = 'ged-' . $token . '.' . $ext;
		$dest  = self::dir() . $name;
		$moved = is_uploaded_file( $tmp ) ? @move_uploaded_file( $tmp, $dest ) : @rename( $tmp, $dest );
		if ( ! $moved ) { return array( false, "Impossible d'enregistrer le fichier.", 0 ); }
		@chmod( $dest, 0640 ); // 1.876.0 : documents du client, illisibles des autres comptes du serveur

		if ( '' === $titre ) { $titre = pathinfo( $orig, PATHINFO_FILENAME ); }
		$cat = trim( (string) ( $data['categorie'] ?? '' ) );
		$linkTypes = self::linkTypes();
		$ltRaw     = trim( (string) ( $data['lien_type'] ?? '' ) );
		$lienType  = isset( $linkTypes[ $ltRaw ] ) ? $ltRaw : '';
		$lienLabel = trim( (string) ( $data['lien_label'] ?? '' ) );
		$tags = implode( ', ', self::parseTags( $data['tags'] ?? '' ) );
		FKC_DB::q(
			'INSERT INTO ged_documents(titre,categorie,fichier,nom_origine,mime,taille,note,created_by,lien_type,lien_id,lien_label,tags) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
			array(
				$titre, $cat ?: null, $name,
				preg_replace( '/[^\w .\-()]+/u', '_', $orig ),
				$mime, (int) $file['size'],
				trim( (string) ( $data['note'] ?? '' ) ) ?: null,
				class_exists( 'FKC_Auth' ) ? FKC_Auth::id() : null,
				$lienType ?: null,
				! empty( $data['lien_id'] ) ? (int) $data['lien_id'] : null,
				$lienLabel ?: null,
				$tags ?: null,
			)
		);
		return array( true, 'Document ajouté.', (int) FKC_DB::conn()->lastInsertId() );
	}

	/** GED a-t-il déjà importé cette pièce ? renvoie l'id GED ou 0. */
	public static function existsFromPiece( $pieceId ) {
		return (int) FKC_DB::q( 'SELECT id FROM ged_documents WHERE piece_origine_id=?', array( (int) $pieceId ) )->fetchColumn();
	}

	/** Promeut une pièce justificative comptable vers la GED (copie + lien vers la source). */
	public static function fromPiece( $pj ) {
		if ( empty( $pj['id'] ) ) { return array( false, 'Pièce invalide.', 0 ); }
		$existing = self::existsFromPiece( (int) $pj['id'] );
		if ( $existing ) { return array( false, 'Cette pièce est déjà dans la GED.', $existing ); }
		if ( ! class_exists( 'FKC_PieceJointe' ) ) { return array( false, 'Module pièces indisponible.', 0 ); }
		$src = FKC_PieceJointe::absPath( $pj );
		if ( ! is_file( $src ) ) { return array( false, 'Fichier source introuvable.', 0 ); }
		$size = (int) ( $pj['taille'] ?? filesize( $src ) );
		if ( class_exists( 'FKC_Storage' ) && ! FKC_Storage::within( $size ) ) { return array( false, 'Quota de stockage atteint pour votre licence.', 0 ); }
		$ext   = strtolower( pathinfo( $pj['nom_origine'] ?? $pj['fichier'], PATHINFO_EXTENSION ) );
		$token = bin2hex( random_bytes( 8 ) );
		$name  = 'ged-' . $token . ( $ext ? '.' . $ext : '' );
		$dest  = self::dir() . $name;
		if ( ! @copy( $src, $dest ) ) { return array( false, 'Copie du fichier impossible.', 0 ); }
		@chmod( $dest, 0640 ); // 1.876.0 : documents du client, illisibles des autres comptes du serveur
		$labels   = FKC_PieceJointe::sourceLabels();
		$srcLabel = $labels[ $pj['source_type'] ] ?? $pj['source_type'];
		$titre    = $pj['nom_origine'] ?: ( 'Pièce ' . $srcLabel );
		FKC_DB::q(
			'INSERT INTO ged_documents(titre,categorie,fichier,nom_origine,mime,taille,note,created_by,lien_type,lien_id,lien_label,tags,piece_origine_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
			array(
				$titre, 'Pièces comptables', $name,
				$pj['nom_origine'] ?: $name,
				$pj['mime'] ?: 'application/octet-stream', $size,
				null,
				class_exists( 'FKC_Auth' ) ? FKC_Auth::id() : null,
				'autre', (int) $pj['source_id'],
				ucfirst( (string) $srcLabel ) . ' #' . (int) $pj['source_id'],
				'pièce comptable',
				(int) $pj['id'],
			)
		);
		return array( true, 'Pièce ajoutée à la GED.', (int) FKC_DB::conn()->lastInsertId() );
	}

	public static function delete( $id ) {
		$doc = self::find( $id );
		if ( ! $doc ) { return false; }
		$path = self::absPath( $doc );
		if ( is_file( $path ) ) { @unlink( $path ); }
		FKC_DB::q( 'DELETE FROM ged_documents WHERE id=?', array( (int) $id ) );
		return true;
	}

	/** Diffuse le document (inline pour PDF/images, téléchargement sinon). */
	public static function stream( $doc ) {
		$path = self::absPath( $doc );
		if ( ! is_file( $path ) ) { http_response_code( 404 ); echo 'Fichier absent.'; return; }
		$inline = in_array( $doc['mime'], array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'text/plain' ), true );
		header( 'Content-Type: ' . ( $doc['mime'] ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $doc['nom_origine'] ?: 'document' ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
	}

	public static function typeLabel( $ext ) { $t = self::types(); return $t[ strtolower( (string) $ext ) ] ?? strtoupper( (string) $ext ); }
}
