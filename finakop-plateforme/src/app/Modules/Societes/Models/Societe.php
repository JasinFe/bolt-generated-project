<?php
/** Sociétés gérées par le cabinet (registre central). @package FinaKop_ERP_Core */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Societe {

	/** Formes juridiques usuelles (OHADA / Côte d'Ivoire). */
	public static function formes() {
		return array(
			'EI'    => 'Entreprise individuelle',
			'SARLU' => 'SARL unipersonnelle',
			'SARL'  => 'SARL',
			'SA'    => 'Société anonyme (SA)',
			'SAS'   => 'SAS',
			'SASU'  => 'SASU',
			'SCI'   => 'Société civile immobilière',
			'GIE'   => "Groupement d'intérêt économique",
			'ONG'   => 'Association / ONG',
			'AUTRE' => 'Autre',
		);
	}

	/** Régimes fiscaux DGI CI. */
	public static function regimes() {
		return array(
			'RNI' => 'Réel normal (RNI)',
			'RSI' => 'Réel simplifié (RSI)',
			'TEE' => 'Taxe des entreprises (TEE / microentreprise)',
			'RME' => 'Régime des microentreprises',
		);
	}

	public static function all() {
		return FKC_Master::q( 'SELECT * FROM societes ORDER BY actif DESC, raison_sociale COLLATE NOCASE' )->fetchAll();
	}

	public static function find( $id ) {
		$r = FKC_Master::q( 'SELECT * FROM societes WHERE id=?', array( (int) $id ) )->fetch();
		return $r ?: null;
	}

	public static function count( $actifOnly = false ) {
		$w = $actifOnly ? ' WHERE actif=1' : '';
		return (int) FKC_Master::q( 'SELECT COUNT(*) FROM societes' . $w )->fetchColumn();
	}

	public static function update( $id, $d ) {
		FKC_Master::q(
			'UPDATE societes SET raison_sociale=?, sigle=?, ncc=?, rccm=?, forme_juridique=?, regime_fiscal=?, devise=?, exercice_debut=?, adresse=?, ville=?, telephone=?, email=?, actif=? WHERE id=?',
			array(
				trim( (string) $d['raison_sociale'] ),
				trim( (string) ( $d['sigle'] ?? '' ) ) ?: null,
				trim( (string) ( $d['ncc'] ?? '' ) ) ?: null,
				trim( (string) ( $d['rccm'] ?? '' ) ) ?: null,
				trim( (string) ( $d['forme_juridique'] ?? '' ) ) ?: null,
				trim( (string) ( $d['regime_fiscal'] ?? '' ) ) ?: null,
				trim( (string) ( $d['devise'] ?? 'XOF' ) ) ?: 'XOF',
				trim( (string) ( $d['exercice_debut'] ?? '' ) ) ?: null,
				trim( (string) ( $d['adresse'] ?? '' ) ) ?: null,
				trim( (string) ( $d['ville'] ?? '' ) ) ?: null,
				trim( (string) ( $d['telephone'] ?? '' ) ) ?: null,
				trim( (string) ( $d['email'] ?? '' ) ) ?: null,
				empty( $d['actif'] ) ? 0 : 1,
				(int) $id,
			)
		);
	}

	/** Supprime une société : registre + accès + fichier de livres isolé. */
	public static function delete( $id ) {
		$s = self::find( $id );
		if ( ! $s ) { return; }
		FKC_Master::q( 'DELETE FROM societe_acces WHERE societe_id=?', array( (int) $id ) );
		FKC_Master::q( 'DELETE FROM societes WHERE id=?', array( (int) $id ) );
		self::deleteLogoFiles( $s );
		// Supprime les fichiers de base (db + journaux WAL/SHM) de la société.
		$base = FKC_DATA_DIR . $s['db_file'];
		foreach ( array( '', '-wal', '-shm' ) as $suf ) {
			$f = $base . $suf;
			if ( is_file( $f ) ) { @unlink( $f ); }
		}
	}

	/* ── Logo de la société ───────────────────────────────────────────────── */

	/** Dossier des logos (dans le dossier de données protégé ; diffusés via une route). */
	public static function logoDir() {
		$dir = FKC_DATA_DIR . 'logos/';
		if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0775, true ); }
		return $dir;
	}

	public static function hasLogo( $societe ) {
		return ! empty( $societe['logo_file'] ) && is_file( self::logoDir() . $societe['logo_file'] );
	}

	public static function logoAbsPath( $societe ) {
		return self::hasLogo( $societe ) ? ( self::logoDir() . $societe['logo_file'] ) : null;
	}

	/** URL servie par l'application (route societes/{id}/logo). */
	public static function logoUrl( $societe ) {
		return self::hasLogo( $societe ) ? url( 'societes/' . $societe['id'] . '/logo' ) : null;
	}

	/** Extensions/MIME acceptés pour le logo. */
	protected static function logoTypes() {
		return array(
			IMAGETYPE_PNG  => array( 'png',  'image/png' ),
			IMAGETYPE_JPEG => array( 'jpg',  'image/jpeg' ),
			IMAGETYPE_GIF  => array( 'gif',  'image/gif' ),
			IMAGETYPE_WEBP => array( 'webp', 'image/webp' ),
		);
	}

	/**
	 * Enregistre le logo téléversé pour une société.
	 *
	 * @param int   $id    identifiant société
	 * @param array $file  entrée $_FILES['logo']
	 * @return array [bool ok, string message]
	 */
	public static function setLogo( $id, $file ) {
		if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			return array( false, 'Aucun fichier reçu.' );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return array( false, 'Échec du téléversement (code ' . (int) $file['error'] . ').' );
		}
		if ( $file['size'] > 2 * 1024 * 1024 ) {
			return array( false, 'Logo trop volumineux (max 2 Mo).' );
		}
		$tmp = $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) && ! is_file( $tmp ) ) {
			return array( false, 'Fichier introuvable.' );
		}
		$info = @getimagesize( $tmp );
		if ( ! $info || ! isset( $info[2] ) || ! isset( self::logoTypes()[ $info[2] ] ) ) {
			return array( false, 'Format non supporté. Utilisez PNG, JPG, GIF ou WEBP.' );
		}
		list( $ext, $mime ) = self::logoTypes()[ $info[2] ];

		$s = self::find( $id );
		if ( ! $s ) { return array( false, 'Société introuvable.' ); }

		// Nettoie un éventuel logo précédent (extension différente).
		self::deleteLogoFiles( $s );

		$name = 'societe-' . (int) $id . '.' . $ext;
		$dest = self::logoDir() . $name;
		$moved = is_uploaded_file( $tmp ) ? @move_uploaded_file( $tmp, $dest ) : @rename( $tmp, $dest );
		if ( ! $moved ) { return array( false, "Impossible d'enregistrer le logo." ); }
		@chmod( $dest, 0640 ); // 1.876.0 : documents du client, illisibles des autres comptes du serveur

		FKC_Master::q( 'UPDATE societes SET logo_file=?, logo_mime=? WHERE id=?', array( $name, $mime, (int) $id ) );
		return array( true, 'Logo enregistré.' );
	}

	public static function removeLogo( $id ) {
		$s = self::find( $id );
		if ( ! $s ) { return; }
		self::deleteLogoFiles( $s );
		FKC_Master::q( 'UPDATE societes SET logo_file=NULL, logo_mime=NULL WHERE id=?', array( (int) $id ) );
	}

	/** Supprime physiquement les fichiers logo d'une société (toutes extensions). */
	protected static function deleteLogoFiles( $societe ) {
		$dir = self::logoDir();
		if ( ! empty( $societe['logo_file'] ) && is_file( $dir . $societe['logo_file'] ) ) {
			@unlink( $dir . $societe['logo_file'] );
		}
		foreach ( array( 'png', 'jpg', 'gif', 'webp' ) as $ext ) {
			$f = $dir . 'societe-' . (int) $societe['id'] . '.' . $ext;
			if ( is_file( $f ) ) { @unlink( $f ); }
		}
	}

	/** Statistiques rapides issues des livres isolés d'une société. */
	/**
	 * Chiffres et secteur d'une société, lus directement dans ses livres.
	 *
	 * Le PACK MÉTIER en fait partie. Un cabinet ou un CGA suit des dossiers de
	 * secteurs différents — une supérette, une pharmacie, une entreprise de
	 * BTP — et chacun a son propre pack : le paramètre « pack_actif » vit dans
	 * la base de la société, jamais dans le registre du cabinet. Sans cette
	 * colonne, la liste des entités ne disait pas de quel métier relevait
	 * chaque dossier, alors que c'est ce qui détermine ses écrans, sa
	 * terminologie et ses schémas comptables.
	 */
	public static function stats( $societe ) {
		$out = array( 'ecritures' => 0, 'comptes' => 0, 'factures' => 0, 'pack' => '' );
		try {
			$pdo = new PDO( 'sqlite:' . FKC_DATA_DIR . $societe['db_file'], null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table'" )->fetchAll( PDO::FETCH_COLUMN );
			if ( in_array( 'ecritures', $tables, true ) ) { $out['ecritures'] = (int) $pdo->query( 'SELECT COUNT(*) FROM ecritures' )->fetchColumn(); }
			if ( in_array( 'comptes', $tables, true ) )   { $out['comptes']   = (int) $pdo->query( 'SELECT COUNT(*) FROM comptes' )->fetchColumn(); }
			if ( in_array( 'factures', $tables, true ) )  { $out['factures']  = (int) $pdo->query( 'SELECT COUNT(*) FROM factures' )->fetchColumn(); }
			if ( in_array( 'parametres', $tables, true ) ) {
				$st = $pdo->prepare( 'SELECT valeur FROM parametres WHERE cle=?' );
				$st->execute( array( 'pack_actif' ) );
				$out['pack'] = (string) ( $st->fetchColumn() ?: '' );
			}
		} catch ( Exception $e ) { /* livres non encore initialisés */ }
		return $out;
	}

	/** Libellé lisible d'un code de pack ('' → « générique »). */
	public static function packLabel( $code ) {
		$code = (string) $code;
		if ( '' === $code ) { return 'Générique'; }
		if ( class_exists( 'FKC_Packs' ) ) {
			$p = FKC_Packs::get( $code );
			if ( $p && ! empty( $p['label'] ) ) { return (string) $p['label']; }
		}
		return ucfirst( str_replace( '_', ' ', $code ) );
	}
}
