<?php
/**
 * FinaKop — export et contrôle des données, pour une migration SANS PERTE.
 *
 * Le même fichier sert aux deux bouts, pour que l'empreinte prise au départ et
 * celle vérifiée à l'arrivée soient calculées par le même code.
 *
 * ── SUR L'ANCIEN HÉBERGEMENT (site en maintenance, voir maintenance-wp.php) ──
 *
 *   php migration-donnees.php exporter <dossier_donnees> <dossier_sortie> [chemin/wp-config.php]
 *
 *   • chaque base SQLite est copiée par « VACUUM INTO » : instantané cohérent,
 *     même si des fichiers -wal/-shm traînent (une copie brute du .db seul
 *     perdrait les dernières écritures restées dans le WAL) ;
 *   • tous les autres fichiers (pièces, logos, GED, .fkc-secret.key…) sont copiés tels quels ;
 *   • MANIFESTE.json : nombre de lignes de CHAQUE table de CHAQUE base, et
 *     empreinte SHA-256 de chaque autre fichier ;
 *   • si wp-config.php est fourni : les constantes FKC_* (dont la clé de
 *     chiffrement) et les options fkc_* de WordPress sont relevées dans
 *     A-REPORTER-DANS-config.php.txt — fichier SENSIBLE, à ne jamais laisser traîner.
 *
 * ── SUR LE VPS, après copie ──
 *
 *   php migration-donnees.php controler <dossier_sortie_ou_donnees> <MANIFESTE.json>
 *
 *   Refait le décompte et les empreintes, lance PRAGMA integrity_check, et
 *   échoue (code 1) à la moindre différence.
 */

if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "pdo_sqlite requis.\n" ); exit( 2 ); }

$cmd = $argv[1] ?? '';
if ( 'exporter' === $cmd && isset( $argv[2], $argv[3] ) ) { exit( exporter( $argv[2], $argv[3], $argv[4] ?? '' ) ); }
if ( 'controler' === $cmd && isset( $argv[2], $argv[3] ) ) { exit( controler( $argv[2], $argv[3] ) ); }
fwrite( STDERR, "Usage :\n  php {$argv[0]} exporter <donnees> <sortie> [wp-config.php]\n  php {$argv[0]} controler <donnees> <MANIFESTE.json>\n" );
exit( 1 );

/* ───────────────────────────────────────────────────────────────────────── */

/** Fichiers régénérés par FinaKop ou propres à l'ancien serveur : non copiés. */
function ignore_fichier( $rel ) {
	$b = basename( $rel );
	return (bool) preg_match( '/\.(db|sqlite)-(wal|shm|journal)$/', $b )
		|| in_array( $b, array( '.htaccess', 'web.config', '.fkc-canary.txt', '.fkc-mot-de-passe-initial.txt' ), true );
}

function est_base( $chemin ) {
	if ( ! preg_match( '/\.(db|sqlite)$/', $chemin ) ) { return false; }
	$f = @fopen( $chemin, 'rb' );
	if ( ! $f ) { return false; }
	$ent = fread( $f, 16 );
	fclose( $f );
	return "SQLite format 3\0" === $ent;
}

/** @return array<string,string> chemin relatif => chemin absolu */
function lister( $racine ) {
	$racine = rtrim( $racine, '/' ) . '/';
	$out = array();
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) { continue; }
		$rel = substr( $f->getPathname(), strlen( $racine ) );
		if ( ! ignore_fichier( $rel ) ) { $out[ $rel ] = $f->getPathname(); }
	}
	ksort( $out );
	return $out;
}

function pdo( $f, $lecture = true ) {
	$p = new PDO( 'sqlite:' . $f, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	$p->exec( 'PRAGMA busy_timeout=10000' );
	return $p;
}

/** Nombre de lignes de chaque table (hors tables internes SQLite). */
function empreinte_base( $f ) {
	$p = pdo( $f );
	$t = array();
	foreach ( $p->query( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name" )->fetchAll( PDO::FETCH_COLUMN ) as $n ) {
		$t[ $n ] = (int) $p->query( 'SELECT COUNT(*) FROM "' . str_replace( '"', '""', $n ) . '"' )->fetchColumn();
	}
	return $t;
}

function exporter( $src, $dst, $wpConfig ) {
	$src = rtrim( realpath( $src ) ?: $src, '/' ) . '/';
	if ( ! is_file( $src . 'finakopcore-master.db' ) ) {
		fwrite( STDERR, "finakopcore-master.db absent de {$src} : ce n'est pas le dossier de données FinaKop.\n" );
		return 1;
	}
	if ( is_dir( $dst ) && ( new FilesystemIterator( $dst ) )->valid() ) {
		fwrite( STDERR, "Le dossier de sortie {$dst} n'est pas vide.\n" );
		return 1;
	}
	@mkdir( $dst, 0700, true );
	$dst = rtrim( realpath( $dst ), '/' ) . '/';
	$donnees = $dst . 'data/';
	mkdir( $donnees, 0700 );

	$m = array( 'cree_le' => gmdate( 'c' ), 'source' => $src, 'php' => PHP_VERSION,
		'sqlite' => pdo( ':memory:' )->query( 'SELECT sqlite_version()' )->fetchColumn(),
		// Décalage horaire de SQLite sur CE serveur : « datetime('now','localtime') » en dépend.
		'decalage_sqlite_s' => (int) pdo( ':memory:' )->query( "SELECT strftime('%s','now','localtime') - strftime('%s','now')" )->fetchColumn(),
		'fuseau_php' => date_default_timezone_get(),
		'bases' => array(), 'fichiers' => array(), 'cle_fichier' => is_file( $src . '.fkc-secret.key' ) );

	foreach ( lister( $src ) as $rel => $abs ) {
		$cible = $donnees . $rel;
		if ( ! is_dir( dirname( $cible ) ) ) { mkdir( dirname( $cible ), 0700, true ); }
		if ( est_base( $abs ) ) {
			$p = pdo( $abs );
			$ic = (string) $p->query( 'PRAGMA integrity_check' )->fetchColumn();
			if ( 'ok' !== $ic ) { fwrite( STDERR, "⚠ {$rel} : integrity_check = {$ic} (copiée quand même, à examiner)\n" ); }
			$p->exec( "VACUUM INTO '" . str_replace( "'", "''", $cible ) . "'" );
			$p = null;
			$m['bases'][ $rel ] = array( 'tables' => empreinte_base( $cible ), 'integrite_source' => $ic );
			echo "  base    {$rel}  (" . array_sum( $m['bases'][ $rel ]['tables'] ) . " lignes)\n";
		} else {
			copy( $abs, $cible );
			touch( $cible, filemtime( $abs ) );
			$m['fichiers'][ $rel ] = hash_file( 'sha256', $cible );
		}
	}
	echo '  ' . count( $m['fichiers'] ) . " autre(s) fichier(s) copiés\n";

	if ( ! $m['cle_fichier'] ) {
		echo "\n  ⚠ Pas de .fkc-secret.key : la clé vient donc de FKC_ENCRYPTION_KEY dans wp-config.php.\n"
			. "    Elle DOIT être reportée dans /etc/finakop/config.php (encryption_key).\n";
	}
	if ( '' !== $wpConfig ) { relever_wordpress( $wpConfig, $dst ); }

	file_put_contents( $dst . 'MANIFESTE.json', json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	echo "\n  Export terminé : {$dst}\n  Import dans la plateforme : php ~/finakop/current/bin/finakop tenant:importer <identifiant> <ce dossier> --nom=\"Nom\"\n";
	return 0;
}

/** Relève dans wp-config.php et la table des options tout ce que config.php doit reprendre. */
function relever_wordpress( $wpConfig, $dst ) {
	$src = @file_get_contents( $wpConfig );
	if ( false === $src ) { fwrite( STDERR, "wp-config.php illisible : {$wpConfig}\n" ); return; }
	$lignes = array( '# FinaKop — valeurs relevées dans WordPress le ' . gmdate( 'c' ),
		'# ⚠ SENSIBLE : contient la clé de chiffrement éventuelle. Supprimez ce fichier après report.', '' );

	preg_match_all( "/define\s*\(\s*['\"](FKC_[A-Z_]+)['\"]\s*,\s*(.+?)\s*\)\s*;/", $src, $mm, PREG_SET_ORDER );
	foreach ( $mm as $d ) { $lignes[] = "constante {$d[1]} = {$d[2]}"; }
	if ( ! $mm ) { $lignes[] = '(aucune constante FKC_* dans wp-config.php)'; }

	$c = function ( $nom ) use ( $src ) {
		return preg_match( "/define\s*\(\s*['\"]{$nom}['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $src, $x ) ? $x[1] : '';
	};
	$prefixe = preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)/', $src, $x ) ? $x[1] : 'wp_';
	$lignes[] = '';
	if ( extension_loaded( 'pdo_mysql' ) && $c( 'DB_NAME' ) ) {
		try {
			$hote = $c( 'DB_HOST' ); $port = null;
			if ( preg_match( '/^(.+):(\d+)$/', $hote, $h ) ) { $hote = $h[1]; $port = $h[2]; }
			$dsn = 'mysql:host=' . $hote . ( $port ? ';port=' . $port : '' ) . ';dbname=' . $c( 'DB_NAME' ) . ';charset=utf8mb4';
			$db = new PDO( $dsn, $c( 'DB_USER' ), $c( 'DB_PASSWORD' ), array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$st = $db->query( "SELECT option_name, option_value FROM `{$prefixe}options` WHERE option_name LIKE 'fkc\\_%' OR option_name IN ('admin_email','siteurl','home') ORDER BY option_name" );
			foreach ( $st as $r ) {
				if ( 'fkc_data_expose' === $r['option_name'] ) { continue; }
				$lignes[] = 'option ' . $r['option_name'] . ' = ' . $r['option_value'];
			}
		} catch ( \Throwable $e ) {
			$lignes[] = '(options WordPress non lues : ' . $e->getMessage() . ') — relevez-les dans Réglages → FinaKop ERP Core';
		}
	} else {
		$lignes[] = '(pdo_mysql absent : relevez les options dans Réglages → FinaKop ERP Core)';
	}
	file_put_contents( $dst . 'A-REPORTER-DANS-config.php.txt', implode( "\n", $lignes ) . "\n" );
	chmod( $dst . 'A-REPORTER-DANS-config.php.txt', 0600 );
	echo "  Réglages WordPress relevés : A-REPORTER-DANS-config.php.txt (SENSIBLE)\n";
}

function controler( $dir, $manifeste ) {
	$dir = rtrim( $dir, '/' ) . '/';
	if ( is_dir( $dir . 'data' ) && ! is_file( $dir . 'finakopcore-master.db' ) ) { $dir .= 'data/'; }
	$m = json_decode( (string) @file_get_contents( $manifeste ), true );
	if ( ! is_array( $m ) ) { fwrite( STDERR, "Manifeste illisible.\n" ); return 1; }
	$err = 0;
	$ko = function ( $t ) use ( &$err ) { $err++; echo "  [!!] {$t}\n"; };

	foreach ( $m['bases'] as $rel => $info ) {
		if ( ! is_file( $dir . $rel ) ) { $ko( "base manquante : {$rel}" ); continue; }
		$ic = (string) pdo( $dir . $rel )->query( 'PRAGMA integrity_check' )->fetchColumn();
		if ( 'ok' !== $ic ) { $ko( "{$rel} : integrity_check = {$ic}" ); }
		$now = empreinte_base( $dir . $rel );
		foreach ( $info['tables'] as $t => $n ) {
			if ( ! array_key_exists( $t, $now ) ) { $ko( "{$rel} : table disparue {$t}" ); }
			elseif ( $now[ $t ] < $n ) { $ko( "{$rel} : {$t} {$now[$t]} ligne(s) au lieu de {$n}" ); }
		}
		echo "  [OK] {$rel} — " . count( $info['tables'] ) . ' tables, ' . array_sum( $info['tables'] ) . " lignes\n";
	}
	foreach ( $m['fichiers'] as $rel => $sha ) {
		if ( ! is_file( $dir . $rel ) ) { $ko( "fichier manquant : {$rel}" ); }
		elseif ( hash_file( 'sha256', $dir . $rel ) !== $sha ) { $ko( "fichier altéré : {$rel}" ); }
	}
	echo '  ' . count( $m['fichiers'] ) . " fichier(s) vérifiés par SHA-256\n";
	if ( ! empty( $m['cle_fichier'] ) && ! is_file( $dir . '.fkc-secret.key' ) ) { $ko( '.fkc-secret.key ABSENTE : secrets chiffrés illisibles' ); }

	echo $err ? "\n  ÉCHEC : {$err} anomalie(s). NE PAS basculer le DNS.\n" : "\n  Données identiques à la source. Bascule possible.\n";
	return $err ? 1 : 0;
}
