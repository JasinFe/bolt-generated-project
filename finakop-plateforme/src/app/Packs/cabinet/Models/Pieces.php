<?php
/**
 * Cabinet Comptable — PIÈCES DU DOSSIER & CIRCUIT DE PRODUCTION (1.804.0).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CE QUE CE REGISTRE EST, ET CE QU'IL N'EST PAS
 *
 * C'est le registre des pièces REÇUES DU CLIENT par le cabinet : facture
 * d'achat, relevé bancaire, note de frais… classées par dossier, période,
 * exercice et mission, et suivies le long du circuit de production :
 *
 *   brouillon → soumise → contrôlée → validée → comptabilisée → clôturée
 *                  ↘ rejetée (motif) → brouillon
 *
 * Ce n'est PAS une seconde comptabilité. L'écriture se passe dans la base du
 * client, avec le moteur comptable existant. Le passage à « comptabilisée »
 * exige la référence de cette écriture, et elle est VÉRIFIÉE dans les livres
 * du client (lecture seule) : une pièce ne peut pas se dire comptabilisée
 * d'une écriture qui n'existe pas.
 *
 * SÉPARATION DES TÂCHES
 *
 *   — seul un chef de mission ou un associé contrôle et valide ;
 *   — celui qui contrôle n'est pas celui qui a soumis ;
 *   — celui qui valide n'est pas celui qui a contrôlé.
 *
 * Un petit cabinet où une seule personne fait tout peut assouplir la règle
 * (paramètre `cb_separation_stricte` = 0, réservé à l'administrateur) : le
 * cumul reste alors permis mais il est TRACÉ au journal du dossier, jamais
 * silencieux. Les exigences de rôle, elles, ne s'assouplissent pas.
 *
 * « Clôturée » n'est pas une action : la pièce y passe quand la checklist
 * mensuelle de sa période est revue par le chef de mission — elle est alors
 * figée.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_CabinetPieces {

	const MAX = 10485760; // 10 Mo

	protected static $ready = array();

	public static function ensure() {
		FKC_CabinetDossiers::ensure();
		$f = FKC_DB::file();
		if ( isset( self::$ready[ $f ] ) ) { return; }
		self::$ready[ $f ] = true;
		FKC_DB::conn()->exec( "
			CREATE TABLE IF NOT EXISTS cb_pieces(
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				client_id INTEGER NOT NULL,
				mission_id INTEGER,
				nature TEXT NOT NULL DEFAULT 'achat',
				reference TEXT DEFAULT '',
				tiers TEXT DEFAULT '',
				date_piece TEXT NOT NULL,
				periode TEXT NOT NULL,            -- AAAA-MM
				exercice INTEGER NOT NULL,        -- exercice PROPRE du client
				montant REAL,
				fichier TEXT, nom_origine TEXT, mime TEXT, taille INTEGER,
				statut TEXT NOT NULL DEFAULT 'brouillon',
				saisi_par INTEGER, soumis_par INTEGER, soumis_at TEXT,
				controle_par INTEGER, controle_at TEXT,
				valide_par INTEGER, valide_at TEXT,
				-- Écriture dans les livres du CLIENT (autre base) : délibérément PAS
				-- nommée comme la colonne standard « écriture » : ce nom est réservé aux références vers les
				-- écritures de la base courante, que la carte des liaisons délie
				-- quand l'écriture disparaît. Ici, la base courante est celle du
				-- cabinet : supprimer l'écriture n° 5 du cabinet aurait délié la
				-- pièce d'un client comptabilisée par l'écriture n° 5 de SES livres.
				ecriture_client_id INTEGER, ecriture_ref TEXT, comptabilise_par INTEGER, comptabilise_at TEXT,
				motif_rejet TEXT,
				created_at TEXT DEFAULT(datetime('now','localtime'))
			);
			CREATE INDEX IF NOT EXISTS idx_cb_pieces ON cb_pieces(client_id, periode, statut);
		" );
	}

	public static function natures() {
		return array( 'achat' => 'Facture d\'achat', 'vente' => 'Facture de vente', 'banque' => 'Relevé bancaire',
			'caisse' => 'Pièce de caisse', 'frais' => 'Note de frais', 'social' => 'Social / paie',
			'fiscal' => 'Document fiscal', 'juridique' => 'Juridique', 'autre' => 'Autre' );
	}

	public static function statuts() {
		return array( 'brouillon' => 'Brouillon', 'soumise' => 'Soumise', 'controlee' => 'Contrôlée', 'validee' => 'Validée',
			'comptabilisee' => 'Comptabilisée', 'cloturee' => 'Clôturée', 'rejetee' => 'Rejetée' );
	}

	/** Types de fichiers acceptés et leur signature (pas de confiance dans l'extension seule). */
	protected static function types() {
		return array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
			'webp' => 'image/webp', 'csv' => 'text/csv', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	}

	protected static function signatureOk( $path, $ext ) {
		$h = (string) @file_get_contents( $path, false, null, 0, 12 );
		switch ( $ext ) {
			case 'pdf':  return 0 === strpos( $h, '%PDF' );
			case 'jpg': case 'jpeg': return 0 === strpos( $h, "\xFF\xD8\xFF" );
			case 'png':  return 0 === strpos( $h, "\x89PNG" );
			case 'webp': return 0 === strpos( $h, 'RIFF' ) && 'WEBP' === substr( $h, 8, 4 );
			case 'xlsx': return 0 === strpos( $h, "PK\x03\x04" );
			case 'csv':  return false === strpos( (string) @file_get_contents( $path, false, null, 0, 4096 ), "\0" );
		}
		return false;
	}

	public static function dir() {
		$dir = rtrim( defined( 'FKC_DATA_DIR' ) ? FKC_DATA_DIR : sys_get_temp_dir() . '/', '/' ) . '/cabinet_pieces/';
		if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0775, true ); }
		if ( ! is_file( $dir . '.htaccess' ) ) { @file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); }
		return $dir;
	}

	/* ── Paramètre de séparation des tâches ─────────────────────────────── */

	public static function separationStricte() {
		$v = FKC_DB::q( "SELECT valeur FROM parametres WHERE cle='cb_separation_stricte'" )->fetchColumn();
		return false === $v || null === $v || '' === $v ? true : '1' === (string) $v;
	}

	/** @return array{bool,string} */
	public static function definirSeparation( $stricte ) {
		if ( ! FKC_Auth::isAdmin() ) { return array( false, 'Réglage réservé à l\'administrateur du cabinet.' ); }
		FKC_DB::q( "INSERT INTO parametres(cle,valeur) VALUES('cb_separation_stricte',?) ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur", array( $stricte ? '1' : '0' ) );
		FKC_CabinetDossiers::journaliser( null, 'parametre', 'Séparation des tâches ' . ( $stricte ? 'stricte' : 'assouplie (cumul tracé)' ) );
		return array( true, $stricte ? 'Séparation stricte des tâches activée.' : 'Séparation assouplie : le cumul des rôles reste possible et sera tracé.' );
	}

	/* ── Lecture ─────────────────────────────────────────────────────────── */

	public static function piece( $id ) {
		self::ensure();
		return FKC_DB::q( 'SELECT * FROM cb_pieces WHERE id=?', array( (int) $id ) )->fetch() ?: null;
	}

	public static function liste( $clientId, array $f = array() ) {
		self::ensure();
		$sql = 'SELECT p.*, m.libelle AS mission FROM cb_pieces p LEFT JOIN cb_missions m ON m.id=p.mission_id WHERE p.client_id=?';
		$a = array( (int) $clientId );
		if ( ! empty( $f['periode'] ) ) { $sql .= ' AND p.periode=?'; $a[] = (string) $f['periode']; }
		if ( ! empty( $f['statut'] ) ) { $sql .= ' AND p.statut=?'; $a[] = (string) $f['statut']; }
		if ( ! empty( $f['exercice'] ) ) { $sql .= ' AND p.exercice=?'; $a[] = (int) $f['exercice']; }
		return FKC_DB::q( $sql . ' ORDER BY p.date_piece DESC, p.id DESC LIMIT 500', $a )->fetchAll();
	}

	/** Compteurs par statut (pour le cockpit du dossier). */
	public static function compteurs( $clientId, $periode = null ) {
		self::ensure();
		$out = array_fill_keys( array_keys( self::statuts() ), 0 );
		$sql = 'SELECT statut, COUNT(*) n FROM cb_pieces WHERE client_id=?'; $a = array( (int) $clientId );
		if ( $periode ) { $sql .= ' AND periode=?'; $a[] = $periode; }
		foreach ( FKC_DB::q( $sql . ' GROUP BY statut', $a )->fetchAll() as $r ) { $out[ $r['statut'] ] = (int) $r['n']; }
		$out['total'] = array_sum( $out );
		return $out;
	}

	/* ── Réception ───────────────────────────────────────────────────────── */

	/**
	 * Enregistre une pièce reçue. Le fichier est facultatif (une pièce peut
	 * être annoncée avant d'être reçue), mais s'il est fourni il est contrôlé
	 * par sa SIGNATURE, pas par son extension.
	 *
	 * @return array{bool,string,int|null}
	 */
	public static function recevoir( $clientId, array $d, $fichier = null ) {
		self::ensure();
		$c = FKC_CabinetDossiers::client( $clientId );
		if ( ! $c ) { return array( false, 'Dossier introuvable.', null ); }
		if ( ! FKC_CabinetDossiers::peutAcceder( $clientId ) ) { return array( false, 'Vous n\'êtes pas affecté à ce dossier.', null ); }
		if ( ! (int) $c['actif'] ) { return array( false, 'Dossier archivé : aucune pièce ne peut y entrer.', null ); }
		$date = (string) ( $d['date_piece'] ?? '' );
		// checkdate et non strtotime : strtotime accepte le 30 février et le reporte au 2 mars.
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm ) || ! checkdate( (int) $dm[2], (int) $dm[3], (int) $dm[1] ) ) { return array( false, 'Date de pièce invalide.', null ); }
		$nature = array_key_exists( $d['nature'] ?? '', self::natures() ) ? $d['nature'] : 'autre';
		$missionId = (int) ( $d['mission_id'] ?? 0 );
		if ( $missionId ) {
			$m = FKC_CabinetMissions::mission( $missionId );
			if ( ! $m || (int) $m['client_id'] !== (int) $clientId ) { return array( false, 'Cette mission n\'appartient pas au dossier.', null ); }
		}
		// Exercice PROPRE du client (décalé compris), à défaut l'année civile.
		$exercice = $c['societe'] ? FKC_CabinetDossiers::exerciceDe( $c['societe'], $date )['annee'] : (int) substr( $date, 0, 4 );

		$fic = array( null, null, null, null );
		if ( is_array( $fichier ) && isset( $fichier['error'] ) && UPLOAD_ERR_NO_FILE !== $fichier['error'] ) {
			list( $ok, $msg, $fic ) = self::stocker( $clientId, $fichier );
			if ( ! $ok ) { return array( false, $msg, null ); }
		}
		$montant = isset( $d['montant'] ) && '' !== trim( (string) $d['montant'] ) ? (float) str_replace( array( ' ', ',' ), array( '', '.' ), (string) $d['montant'] ) : null;
		FKC_DB::q(
			'INSERT INTO cb_pieces(client_id, mission_id, nature, reference, tiers, date_piece, periode, exercice, montant, fichier, nom_origine, mime, taille, saisi_par)
			 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
			array( (int) $clientId, $missionId ?: null, $nature, trim( (string) ( $d['reference'] ?? '' ) ), trim( (string) ( $d['tiers'] ?? '' ) ),
				$date, substr( $date, 0, 7 ), $exercice, $montant, $fic[0], $fic[1], $fic[2], $fic[3], FKC_Auth::id() )
		);
		$id = (int) FKC_DB::conn()->lastInsertId();
		FKC_CabinetDossiers::journaliser( $clientId, 'piece_recue', '#' . $id . ' ' . self::natures()[ $nature ] . ' du ' . $date );
		// Une pièce reçue répond peut-être à un justificatif réclamé.
		if ( ! empty( $d['suspens_id'] ) && class_exists( 'FKC_CabinetSuspens' ) ) {
			FKC_CabinetSuspens::resoudre( (int) $d['suspens_id'], 'Pièce #' . $id . ' reçue', $clientId );
		}
		return array( true, 'Pièce enregistrée pour la période ' . substr( $date, 0, 7 ) . ' (exercice ' . $exercice . ').', $id );
	}

	/** @return array{bool,string,array} */
	protected static function stocker( $clientId, array $f ) {
		if ( UPLOAD_ERR_OK !== ( $f['error'] ?? -1 ) ) { return array( false, 'Échec du téléversement.', array() ); }
		if ( (int) $f['size'] <= 0 ) { return array( false, 'Fichier vide.', array() ); }
		if ( (int) $f['size'] > self::MAX ) { return array( false, 'Fichier trop volumineux (10 Mo maximum).', array() ); }
		if ( class_exists( 'FKC_Storage' ) && ! FKC_Storage::within( (int) $f['size'] ) ) { return array( false, 'Quota de stockage de la licence atteint.', array() ); }
		$tmp = (string) $f['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) && ! is_file( $tmp ) ) { return array( false, 'Fichier introuvable.', array() ); }
		$ext = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
		$types = self::types();
		if ( ! isset( $types[ $ext ] ) ) { return array( false, 'Type non autorisé. Acceptés : PDF, JPG, PNG, WEBP, CSV, XLSX.', array() ); }
		if ( ! self::signatureOk( $tmp, $ext ) ) { return array( false, 'Le contenu du fichier ne correspond pas à son extension.', array() ); }
		$nom = 'c' . (int) $clientId . '-' . bin2hex( random_bytes( 10 ) ) . '.' . $ext;
		$dest = self::dir() . $nom;
		$ok = is_uploaded_file( $tmp ) ? @move_uploaded_file( $tmp, $dest ) : @rename( $tmp, $dest );
		if ( ! $ok ) { return array( false, 'Impossible d\'enregistrer le fichier.', array() ); }
		@chmod( $dest, 0640 ); // 1.876.0 : documents du client, illisibles des autres comptes du serveur
		$orig = preg_replace( '/[^\p{L}\p{N}._ -]+/u', '_', basename( (string) $f['name'] ) );
		return array( true, '', array( $nom, $orig, $types[ $ext ], (int) $f['size'] ) );
	}

	/** Diffuse le fichier d'une pièce (droits vérifiés par l'appelant). */
	public static function diffuser( array $p ) {
		$path = self::dir() . basename( (string) $p['fichier'] );
		if ( ! $p['fichier'] || ! is_file( $path ) ) { http_response_code( 404 ); echo 'Fichier absent.'; return; }
		$inline = in_array( $p['mime'], array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp' ), true );
		header( 'Content-Type: ' . $p['mime'] );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', (string) $p['nom_origine'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
	}

	/* ── Circuit ─────────────────────────────────────────────────────────── */

	/**
	 * Applique une transition. Toutes les règles vivent ICI, jamais dans
	 * l'écran : un formulaire forgé passe par les mêmes contrôles.
	 *
	 * @return array{bool,string}
	 */
	public static function transition( $pieceId, $action, array $d = array() ) {
		self::ensure();
		$p = self::piece( $pieceId );
		if ( ! $p ) { return array( false, 'Pièce introuvable.' ); }
		$cid = (int) $p['client_id'];
		$role = FKC_CabinetDossiers::roleSur( $cid );
		if ( ! $role ) { return array( false, 'Vous n\'êtes pas affecté à ce dossier.' ); }
		$uid = (int) FKC_Auth::id();
		$chef = in_array( $role, array( 'chef_mission', 'associe' ), true );
		$strict = self::separationStricte();
		$cumul = function ( $qui, $etape ) use ( $uid, $strict, $cid, $p ) {
			if ( (int) $qui !== $uid ) { return null; }
			if ( $strict ) { return 'Séparation des tâches : vous ne pouvez pas ' . $etape . ' une pièce que vous avez vous-même traitée à l\'étape précédente.'; }
			FKC_CabinetDossiers::journaliser( $cid, 'cumul_roles', 'Pièce #' . (int) $p['id'] . ' : ' . $etape . ' par la même personne (séparation assouplie)' );
			return null;
		};
		$maj = function ( $sql, array $a, $journal ) use ( $p, $cid ) {
			$a[] = (int) $p['id'];
			FKC_DB::q( 'UPDATE cb_pieces SET ' . $sql . ' WHERE id=?', $a );
			FKC_CabinetDossiers::journaliser( $cid, 'piece_' . $journal, '#' . (int) $p['id'] );
		};
		$now = date( 'Y-m-d H:i:s' );

		switch ( (string) $action ) {
			case 'soumettre':
				if ( 'brouillon' !== $p['statut'] ) { return array( false, 'Seule une pièce en brouillon se soumet.' ); }
				$maj( "statut='soumise', soumis_par=?, soumis_at=?, motif_rejet=NULL", array( $uid, $now ), 'soumise' );
				return array( true, 'Pièce soumise au contrôle.' );

			case 'controler':
				if ( 'soumise' !== $p['statut'] ) { return array( false, 'Seule une pièce soumise se contrôle.' ); }
				if ( ! $chef ) { return array( false, 'Le contrôle est réservé au chef de mission ou à l\'associé.' ); }
				if ( $e = $cumul( $p['soumis_par'], 'contrôler' ) ) { return array( false, $e ); }
				$maj( "statut='controlee', controle_par=?, controle_at=?", array( $uid, $now ), 'controlee' );
				return array( true, 'Pièce contrôlée.' );

			case 'valider':
				if ( 'controlee' !== $p['statut'] ) { return array( false, 'Seule une pièce contrôlée se valide.' ); }
				if ( ! $chef ) { return array( false, 'La validation est réservée au chef de mission ou à l\'associé.' ); }
				if ( $e = $cumul( $p['controle_par'], 'valider' ) ) { return array( false, $e ); }
				$maj( "statut='validee', valide_par=?, valide_at=?", array( $uid, $now ), 'validee' );
				return array( true, 'Pièce validée : elle peut être comptabilisée.' );

			case 'rejeter':
				if ( ! in_array( $p['statut'], array( 'soumise', 'controlee', 'validee' ), true ) ) { return array( false, 'Cette pièce ne peut pas être rejetée à ce stade.' ); }
				if ( ! $chef ) { return array( false, 'Le rejet est réservé au chef de mission ou à l\'associé.' ); }
				$motif = trim( (string) ( $d['motif'] ?? '' ) );
				if ( '' === $motif ) { return array( false, 'Un rejet sans motif ne dit pas quoi corriger : indiquez-le.' ); }
				$maj( "statut='rejetee', motif_rejet=?, controle_par=NULL, controle_at=NULL, valide_par=NULL, valide_at=NULL", array( $motif ), 'rejetee' );
				return array( true, 'Pièce rejetée.' );

			case 'reprendre':
				if ( 'rejetee' !== $p['statut'] ) { return array( false, 'Seule une pièce rejetée se reprend.' ); }
				$maj( "statut='brouillon'", array(), 'reprise' );
				return array( true, 'Pièce remise en brouillon.' );

			case 'comptabiliser':
				if ( 'validee' !== $p['statut'] ) { return array( false, 'Seule une pièce validée se comptabilise.' ); }
				$ref = trim( (string) ( $d['ecriture_ref'] ?? '' ) );
				if ( '' === $ref ) { return array( false, 'Indiquez le numéro (ou la référence de pièce) de l\'écriture passée dans la comptabilité du client.' ); }
				list( $ok, $msg, $eid ) = self::verifierEcriture( $cid, $ref );
				if ( ! $ok ) { return array( false, $msg ); }
				$maj( "statut='comptabilisee', ecriture_client_id=?, ecriture_ref=?, comptabilise_par=?, comptabilise_at=?", array( $eid, $ref, $uid, $now ), 'comptabilisee' );
				return array( true, 'Pièce comptabilisée — écriture ' . $ref . ' vérifiée dans les livres du client.' );
		}
		return array( false, 'Action inconnue.' );
	}

	/**
	 * L'écriture existe-t-elle dans les livres du CLIENT ? Recherche par
	 * numéro comptable, puis par référence de pièce ; une référence portée par
	 * plusieurs écritures est refusée (ambiguë) plutôt que devinée.
	 *
	 * @return array{bool,string,int|null}
	 */
	public static function verifierEcriture( $clientId, $ref ) {
		$c = FKC_CabinetDossiers::client( $clientId );
		if ( ! $c || ! $c['societe'] ) { return array( false, 'Le dossier n\'a pas de comptabilité rattachée : impossible de vérifier l\'écriture.', null ); }
		$pdo = FKC_CabinetDossiers::lecteur( $c['societe'] );
		if ( ! $pdo ) { return array( false, 'Comptabilité du client illisible.', null ); }
		try {
			$st = $pdo->prepare( "SELECT id FROM ecritures WHERE numero=? AND COALESCE(type_ecriture,'normale')='normale'" );
			$st->execute( array( $ref ) ); $ids = $st->fetchAll( PDO::FETCH_COLUMN );
			if ( ! $ids ) {
				$st = $pdo->prepare( 'SELECT id FROM ecritures WHERE piece=?' );
				$st->execute( array( $ref ) ); $ids = $st->fetchAll( PDO::FETCH_COLUMN );
			}
		} catch ( \Throwable $e ) { return array( false, 'Lecture des écritures impossible.', null ); }
		if ( ! $ids ) { return array( false, 'Aucune écriture « ' . $ref . ' » dans la comptabilité de ' . $c['raison_sociale'] . '.', null ); }
		if ( count( $ids ) > 1 ) { return array( false, 'La référence « ' . $ref . ' » désigne ' . count( $ids ) . ' écritures : indiquez le numéro comptable exact.', null ); }
		$eid = (int) $ids[0];
		$deja = FKC_DB::q( 'SELECT id FROM cb_pieces WHERE client_id=? AND ecriture_client_id=?', array( (int) $clientId, $eid ) )->fetchColumn();
		if ( $deja ) { return array( false, 'Cette écriture justifie déjà la pièce #' . (int) $deja . '.', null ); }
		return array( true, '', $eid );
	}

	/** Fige les pièces comptabilisées d'une période revue (appelé par la checklist). */
	public static function cloturerPeriode( $clientId, $periode ) {
		self::ensure();
		FKC_DB::q( "UPDATE cb_pieces SET statut='cloturee' WHERE client_id=? AND periode=? AND statut='comptabilisee'", array( (int) $clientId, (string) $periode ) );
		return (int) FKC_DB::q( 'SELECT changes()' )->fetchColumn();
	}
}
