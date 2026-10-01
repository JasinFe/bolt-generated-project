<?php
/**
 * FKC_Plateforme_Registre — registre des clients de la plateforme (1.876.0).
 *
 * Base SQLite ~/finakop-data/plateforme/plateforme.db, distincte des bases des
 * clients : elle ne contient AUCUNE donnée métier ni aucun utilisateur d'un
 * client. Seulement : qui existe, sous quel sous-domaine, dans quel dossier,
 * dans quel état — plus le journal des opérations et l'état des tâches cron.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_Plateforme_Registre {

	const SCHEMA = 1;
	const STATUTS = array( 'actif', 'suspendu', 'maintenance', 'archive' );

	protected static $pdo = null;

	public static function fichier() { return FKC_Config::dossier( 'plateforme/plateforme.db' ); }

	public static function conn() {
		if ( self::$pdo ) { return self::$pdo; }
		$dir = dirname( self::fichier() );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) ) { throw new \RuntimeException( 'Dossier de la plateforme inaccessible.' ); }
		self::$pdo = new \PDO( 'sqlite:' . self::fichier(), null, null, array(
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
		) );
		self::$pdo->exec( 'PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;' );
		self::migrer();
		return self::$pdo;
	}

	/** Pour les tests. */
	public static function oublier() { self::$pdo = null; }

	protected static function migrer() {
		$db = self::$pdo;
		$v = 0;
		try { $v = (int) $db->query( "SELECT valeur FROM plateforme_parametres WHERE cle='schema'" )->fetchColumn(); } catch ( \Throwable $e ) {}
		if ( $v >= self::SCHEMA ) { return; }
		$db->exec( "
			CREATE TABLE IF NOT EXISTS plateforme_parametres( cle TEXT PRIMARY KEY, valeur TEXT );
			CREATE TABLE IF NOT EXISTS tenants(
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				slug TEXT NOT NULL UNIQUE,
				nom TEXT NOT NULL,
				dossier TEXT NOT NULL UNIQUE,
				statut TEXT NOT NULL DEFAULT 'actif' CHECK( statut IN ('actif','suspendu','maintenance','archive') ),
				contact TEXT,
				notes TEXT,
				cree_le TEXT NOT NULL DEFAULT( strftime('%Y-%m-%dT%H:%M:%SZ','now') ),
				modifie_le TEXT
			);
			CREATE TABLE IF NOT EXISTS tenant_domaines(
				domaine TEXT PRIMARY KEY,
				tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
				cree_le TEXT NOT NULL DEFAULT( strftime('%Y-%m-%dT%H:%M:%SZ','now') )
			);
			CREATE TABLE IF NOT EXISTS journal(
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				ts TEXT NOT NULL DEFAULT( strftime('%Y-%m-%dT%H:%M:%SZ','now') ),
				tenant_id INTEGER, action TEXT NOT NULL, detail TEXT, acteur TEXT
			);
			CREATE TABLE IF NOT EXISTS taches(
				tenant_id INTEGER NOT NULL, tache TEXT NOT NULL,
				dernier_debut TEXT, dernier_fin TEXT, statut TEXT, duree REAL, message TEXT, echecs INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY( tenant_id, tache )
			);
			INSERT INTO plateforme_parametres(cle,valeur) VALUES('schema','" . self::SCHEMA . "')
				ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur;
		" );
	}

	public static function q( $sql, array $p = array() ) {
		$st = self::conn()->prepare( $sql );
		$st->execute( $p );
		return $st;
	}

	/* ── Clients ─────────────────────────────────────────────────────────── */

	/** Identifiant de sous-domaine : 1 à 40 caractères a-z 0-9 et tiret, ni en tête ni en fin. */
	public static function slugValide( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug ) && false === strpos( $slug, '--' );
	}

	public static function parSlug( $slug ) {
		if ( ! self::slugValide( $slug ) ) { return null; }
		return self::q( 'SELECT * FROM tenants WHERE slug=?', array( $slug ) )->fetch() ?: null;
	}

	public static function parDomaine( $domaine ) {
		return self::q( 'SELECT t.* FROM tenant_domaines d JOIN tenants t ON t.id=d.tenant_id WHERE d.domaine=?', array( strtolower( (string) $domaine ) ) )->fetch() ?: null;
	}

	public static function tous( $statut = null ) {
		if ( null !== $statut ) { return self::q( 'SELECT * FROM tenants WHERE statut=? ORDER BY slug', array( $statut ) )->fetchAll(); }
		return self::q( 'SELECT * FROM tenants ORDER BY slug' )->fetchAll();
	}

	public static function ajouter( $slug, $nom, $contact = null ) {
		// Création : 3 à 40 caractères ASCII (a-z, 0-9, tiret intérieur, jamais « -- » :
		// ni accent, ni homographe Unicode, ni nom punycode « xn--… »).
		if ( ! self::slugValide( $slug ) || strlen( $slug ) < 3 ) { throw new \InvalidArgumentException( "Identifiant invalide : « {$slug} » (a-z, 0-9, tiret ; 3 à 40 caractères)." ); }
		if ( in_array( $slug, (array) FKC_Config::get( 'reserves', array() ), true ) ) { throw new \InvalidArgumentException( "« {$slug} » est un sous-domaine réservé." ); }
		if ( self::parSlug( $slug ) ) { throw new \InvalidArgumentException( "Le client « {$slug} » existe déjà." ); }
		self::q( 'INSERT INTO tenants(slug,nom,dossier,contact) VALUES(?,?,?,?)', array( $slug, trim( (string) $nom ), $slug, $contact ) );
		return self::parSlug( $slug );
	}

	public static function changerStatut( $slug, $statut ) {
		if ( ! in_array( $statut, self::STATUTS, true ) ) { throw new \InvalidArgumentException( 'Statut inconnu : ' . $statut ); }
		$n = self::q( "UPDATE tenants SET statut=?, modifie_le=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE slug=?", array( $statut, $slug ) )->rowCount();
		if ( ! $n ) { throw new \InvalidArgumentException( "Client inconnu : {$slug}" ); }
	}

	/** Dossier de données d'un client (FKC_DATA_DIR). */
	public static function dossierDonnees( array $t ) { return FKC_Config::dossier( 'tenants/' . $t['dossier'] . '/' ); }
	public static function dossierSessions( array $t ) { return FKC_Config::dossier( 'sessions/' . $t['dossier'] . '/' ); }
	public static function dossierSauvegardes( array $t ) {
		$base = (string) FKC_Config::get( 'sauvegarde.dossier', '' );
		return ( '' !== $base ? rtrim( $base, '/' ) . '/' : FKC_Config::dossier( 'sauvegardes/' ) ) . $t['dossier'] . '/';
	}

	/* ── Journal et tâches ───────────────────────────────────────────────── */

	public static function journaliser( $action, $detail = '', $tenantId = null ) {
		try {
			$acteur = 'cli' === PHP_SAPI ? ( 'cli:' . ( getenv( 'USER' ) ?: 'inconnu' ) ) : ( 'web:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			self::q( 'INSERT INTO journal(tenant_id,action,detail,acteur) VALUES(?,?,?,?)', array( $tenantId, (string) $action, mb_substr( (string) $detail, 0, 1000 ), $acteur ) );
		} catch ( \Throwable $e ) {}
	}

	public static function tacheDebut( $tenantId, $tache ) {
		self::q( "INSERT INTO taches(tenant_id,tache,dernier_debut,statut) VALUES(?,?,strftime('%Y-%m-%dT%H:%M:%SZ','now'),'en_cours')
			ON CONFLICT(tenant_id,tache) DO UPDATE SET dernier_debut=excluded.dernier_debut, statut='en_cours'", array( (int) $tenantId, $tache ) );
	}

	public static function tacheFin( $tenantId, $tache, $ok, $duree, $message ) {
		self::q( "UPDATE taches SET dernier_fin=strftime('%Y-%m-%dT%H:%M:%SZ','now'), statut=?, duree=?, message=?,
			echecs=CASE WHEN ? THEN 0 ELSE echecs+1 END WHERE tenant_id=? AND tache=?",
			array( $ok ? 'ok' : 'echec', round( (float) $duree, 3 ), mb_substr( (string) $message, 0, 500 ), $ok ? 1 : 0, (int) $tenantId, $tache ) );
	}

	/** Dernière fin réussie d'une tâche (horodatage UNIX), ou 0. */
	public static function tacheDerniere( $tenantId, $tache ) {
		$v = self::q( "SELECT dernier_fin FROM taches WHERE tenant_id=? AND tache=? AND statut='ok'", array( (int) $tenantId, $tache ) )->fetchColumn();
		return $v ? (int) strtotime( $v ) : 0;
	}
}
