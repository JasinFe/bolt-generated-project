<?php
/**
 * FinaKop — diagnostic d'un hébergement MUTUALISÉ, avant migration.
 *
 * Vérifie sur le compte réel ce qu'aucune fiche commerciale ne dit :
 * pdo_sqlite, dossier de données hors du web, SQLite en mode WAL, débit et
 * latence disque (limites I/O des hébergements mutualisés), OPcache, fonctions
 * désactivées, sorties réseau (serveur de licences, SMTP), et la vraie IP du
 * visiteur derrière Cloudflare.
 *
 * UTILISATION (5 minutes) :
 *   1. Remplacez JETON ci-dessous par une chaîne de votre choix.
 *   2. Déposez ce fichier à la racine web du sous-domaine prévu.
 *   3. Ouvrez https://<sous-domaine>/diagnostic-hebergement.php?jeton=<JETON>
 *      (une fois AVANT d'activer Cloudflare, une fois APRÈS).
 *      Et en SSH :  php diagnostic-hebergement.php   (teste le PHP des tâches cron)
 *   4. SUPPRIMEZ le fichier ensuite.
 *
 * Le script ne crée rien de durable : son dossier et sa base d'essai sont effacés.
 */

const JETON = 'CHANGEZ-MOI';

/* Dossier de données prévu : hors de la racine web, dans le répertoire du compte. */
function dossier_prevu() {
	if ( ! empty( $_GET['dir'] ) ) { return rtrim( (string) $_GET['dir'], '/' ) . '/'; }
	$home = getenv( 'HOME' ) ?: ( function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( posix_geteuid() )['dir'] ?? '' ) : '' );
	if ( '' === $home ) { $home = dirname( __DIR__, 3 ); }
	return rtrim( $home, '/' ) . '/finakop-data-diagnostic/';
}

$cli = 'cli' === PHP_SAPI;
if ( ! $cli ) {
	if ( 'CHANGEZ-MOI' === JETON || ! hash_equals( JETON, (string) ( $_GET['jeton'] ?? '' ) ) ) {
		http_response_code( 404 ); exit;
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'X-Robots-Tag: noindex' );
}
@set_time_limit( 120 );

$lignes = array();
function note( $niveau, $sujet, $detail ) { global $lignes; $lignes[] = array( $niveau, $sujet, $detail ); }
function octets( $v ) {
	$v = trim( (string) $v ); if ( '' === $v || '-1' === $v ) { return PHP_INT_MAX; }
	$n = (float) $v; $u = strtolower( substr( $v, -1 ) );
	return (int) ( 'g' === $u ? $n * 1073741824 : ( 'm' === $u ? $n * 1048576 : ( 'k' === $u ? $n * 1024 : $n ) ) );
}

/* ── 1. PHP ─────────────────────────────────────────────────────────────── */
note( version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'bloquant', 'Version de PHP',
	PHP_VERSION . ' (' . PHP_SAPI . ') — minimum 8.1, 8.3 ou 8.4 recommandé' );
foreach ( array( 'pdo_sqlite' => 'bloquant', 'openssl' => 'bloquant', 'json' => 'bloquant', 'sodium' => 'attention',
	'mbstring' => 'attention', 'curl' => 'attention', 'intl' => 'attention', 'gd' => 'attention', 'zip' => 'attention' ) as $e => $n ) {
	note( extension_loaded( $e ) ? 'ok' : $n, "Extension {$e}", extension_loaded( $e ) ? 'présente' : 'ABSENTE — activez-la dans le panneau PHP' );
}
$op = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;
if ( $cli ) {
	note( 'info', 'OPcache', 'non mesurable en ligne de commande — voir le test par le navigateur' );
} else {
	$mem = is_array( $op ) ? (int) ( $op['memory_usage']['used_memory'] + $op['memory_usage']['free_memory'] ) : 0;
	note( is_array( $op ) && ! empty( $op['opcache_enabled'] ) ? ( $mem >= 128 * 1048576 ? 'ok' : 'attention' ) : 'attention', 'OPcache',
		is_array( $op ) && ! empty( $op['opcache_enabled'] ) ? 'actif, ' . round( $mem / 1048576 ) . ' Mo (FinaKop : ~2 100 fichiers, 128 Mo conseillés)' : 'INACTIF — les pages seront 3 à 5 fois plus lentes' );
}
$ml = octets( ini_get( 'memory_limit' ) );
note( $ml >= 256 * 1048576 ? 'ok' : 'attention', 'memory_limit', ini_get( 'memory_limit' ) . ' (256M minimum, 512M conseillé)' );
$met = (int) ini_get( 'max_execution_time' );
note( 0 === $met || $met >= 120 ? 'ok' : 'attention', 'max_execution_time', $met . ' s (120 s minimum pour clôtures et exports)' );
$up = min( octets( ini_get( 'upload_max_filesize' ) ), octets( ini_get( 'post_max_size' ) ) );
note( $up >= 32 * 1048576 ? 'ok' : 'attention', 'Taille des envois', ini_get( 'upload_max_filesize' ) . ' / ' . ini_get( 'post_max_size' ) . ' (pièces justificatives : 32M conseillé)' );
$desact = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
$utiles = array_intersect( $desact, array( 'mail', 'fsockopen', 'stream_socket_client', 'set_time_limit', 'ignore_user_abort', 'symlink', 'proc_nice', 'flock' ) );
note( $utiles ? 'attention' : 'ok', 'Fonctions désactivées', $utiles ? 'utiles à FinaKop : ' . implode( ', ', $utiles ) : 'aucune de celles dont FinaKop a besoin' );
note( 'info', 'open_basedir', ini_get( 'open_basedir' ) ?: '(aucune restriction)' );
note( 'info', 'Fuseau horaire PHP', date_default_timezone_get() . ' — FinaKop sera réglé en UTC, comme sous WordPress' );

/* ── 2. Dossier de données hors du web ─────────────────────────────────── */
$dir = dossier_prevu();
$docroot = realpath( $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ ) ?: __DIR__;
$ecrit = @is_dir( $dir ) || @mkdir( $dir, 0750, true );
if ( ! $ecrit ) {
	note( 'bloquant', 'Dossier de données', "impossible de créer {$dir} — vérifiez open_basedir ou passez ?dir=/chemin/autorisé" );
} else {
	$hors = 0 !== strpos( realpath( $dir ) . '/', rtrim( $docroot, '/' ) . '/' );
	note( $hors ? 'ok' : 'bloquant', 'Dossier de données', realpath( $dir ) . ( $hors ? ' — hors de la racine web' : ' — DANS la racine web : choisissez un autre emplacement' ) );

	/* ── 3. SQLite : WAL, transactions, durabilité ─────────────────────── */
	if ( extension_loaded( 'pdo_sqlite' ) ) {
		$f = $dir . 'essai.db';
		try {
			$p = new PDO( 'sqlite:' . $f, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$p->exec( 'PRAGMA busy_timeout=5000' );
			$jm = (string) $p->query( 'PRAGMA journal_mode=WAL' )->fetchColumn();
			note( 'wal' === strtolower( $jm ) ? 'ok' : 'bloquant', 'SQLite en mode WAL', 'journal_mode = ' . $jm . ' · SQLite ' . $p->query( 'select sqlite_version()' )->fetchColumn() );
			$p->exec( 'PRAGMA synchronous=NORMAL; CREATE TABLE t(id INTEGER PRIMARY KEY, v TEXT)' );

			$t = microtime( true ); $p->beginTransaction();
			$st = $p->prepare( 'INSERT INTO t(v) VALUES(?)' );
			for ( $i = 0; $i < 20000; $i++ ) { $st->execute( array( str_repeat( 'x', 120 ) ) ); }
			$p->commit(); $d = microtime( true ) - $t;
			note( $d < 1.5 ? 'ok' : 'attention', 'SQLite : 20 000 lignes en une transaction', round( $d, 2 ) . ' s (VPS : ~0,1 s)' );

			$t = microtime( true );
			for ( $i = 0; $i < 200; $i++ ) { $p->exec( "INSERT INTO t(v) VALUES('c')" ); }
			$d = ( microtime( true ) - $t ) / 200 * 1000;
			note( $d < 5 ? 'ok' : ( $d < 20 ? 'attention' : 'bloquant' ), 'SQLite : latence d\'une validation', round( $d, 2 ) . ' ms par écriture validée (< 5 ms idéal)' );

			// Deux connexions : la seconde doit attendre, pas échouer (busy_timeout).
			$p2 = new PDO( 'sqlite:' . $f, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$p2->exec( 'PRAGMA busy_timeout=3000' );
			$lu = (int) $p2->query( 'SELECT COUNT(*) FROM t' )->fetchColumn();
			note( $lu >= 20200 ? 'ok' : 'bloquant', 'SQLite : lecture concurrente', $lu . ' lignes lues par une seconde connexion (mémoire partagée WAL opérationnelle)' );

			$t = microtime( true ); $p->exec( "VACUUM INTO '" . $dir . "copie.db'" ); $d = microtime( true ) - $t;
			$taille = filesize( $dir . 'copie.db' );
			note( 'ok', 'SQLite : copie à chaud (sauvegarde)', round( $taille / 1048576, 1 ) . ' Mo en ' . round( $d, 2 ) . ' s' );
			$p = $p2 = null;
		} catch ( \Throwable $e ) {
			note( 'bloquant', 'SQLite', $e->getMessage() );
		}
	}

	/* ── 4. Débit disque (limite I/O du mutualisé) ─────────────────────── */
	$bloc = random_bytes( 1048576 ); $t = microtime( true );
	$h = fopen( $dir . 'debit.bin', 'wb' );
	for ( $i = 0; $i < 32; $i++ ) { fwrite( $h, $bloc ); }
	fflush( $h ); if ( function_exists( 'fsync' ) ) { fsync( $h ); } fclose( $h );
	$mbs = 32 / max( 0.001, microtime( true ) - $t );
	note( $mbs >= 20 ? 'ok' : ( $mbs >= 5 ? 'attention' : 'bloquant' ), 'Débit d\'écriture disque', round( $mbs, 1 ) . ' Mo/s sur 32 Mo (sauvegardes, imports ; < 5 Mo/s = bridé)' );

	foreach ( array( 'essai.db', 'essai.db-wal', 'essai.db-shm', 'copie.db', 'debit.bin' ) as $x ) { @unlink( $dir . $x ); }
	if ( empty( $_GET['dir'] ) ) { @rmdir( $dir ); }
}

/* ── 5. Processeur ──────────────────────────────────────────────────────── */
$t = microtime( true ); $x = 0;
for ( $i = 0; $i < 3000000; $i++ ) { $x += $i % 7; }
$d = microtime( true ) - $t;
note( $d < 0.12 ? 'ok' : 'attention', 'Processeur (boucle de référence)', round( $d * 1000 ) . ' ms (VPS récent : ~40 ms)' );

/* ── 6. Réseau sortant ──────────────────────────────────────────────────── */
$ctx = stream_context_create( array( 'http' => array( 'timeout' => 6, 'ignore_errors' => true ), 'ssl' => array( 'verify_peer' => true ) ) );
$t = microtime( true ); $r = @file_get_contents( 'https://license.kophisgroup.com/', false, $ctx );
note( false !== $r ? 'ok' : 'attention', 'Sortie HTTPS (serveur de licences)', false !== $r ? 'joignable en ' . round( ( microtime( true ) - $t ) * 1000 ) . ' ms' : 'injoignable — la licence fonctionne hors ligne, mais la révocation à distance non' );
$smtp = $_GET['smtp'] ?? 'smtp.hostinger.com';
$s = @stream_socket_client( 'ssl://' . $smtp . ':465', $en, $es, 6 );
note( $s ? 'ok' : 'attention', 'Sortie SMTP (465)', $s ? $smtp . ' joignable : envoi des courriels par SMTP possible' : $smtp . ' injoignable (' . $es . ')' );
if ( $s ) { fclose( $s ); }
note( function_exists( 'mail' ) && ! in_array( 'mail', $desact, true ) ? 'ok' : 'attention', 'Fonction mail()', 'disponible en secours' );

/* ── 7. Requête web : HTTPS et vraie IP derrière Cloudflare ─────────────── */
if ( ! $cli ) {
	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) || 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' );
	note( $https ? 'ok' : 'attention', 'HTTPS vu par PHP', $https ? 'oui' : 'non — cookies de session non sécurisés : activez SSL (et « Full (strict) » chez Cloudflare)' );
	$ra = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); $cf = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
	if ( '' === $cf ) {
		note( 'info', 'IP du visiteur', $ra . ' (pas de Cloudflare devant ce test)' );
	} elseif ( $cf === $ra ) {
		note( 'ok', 'IP du visiteur derrière Cloudflare', 'l\'hébergeur rétablit la vraie IP (' . $ra . ')' );
	} else {
		note( 'attention', 'IP du visiteur derrière Cloudflare', 'PHP voit ' . $ra . ' (Cloudflare) au lieu de ' . $cf . ' — le kit la rétablit lui-même (liste des IP Cloudflare vérifiée)' );
	}
}

/* ── Verdict ────────────────────────────────────────────────────────────── */
$b = count( array_filter( $lignes, fn( $l ) => 'bloquant' === $l[0] ) );
$a = count( array_filter( $lignes, fn( $l ) => 'attention' === $l[0] ) );
$verdict = $b ? "NON FAISABLE en l'état : {$b} point(s) bloquant(s)" : ( $a ? "FAISABLE, avec {$a} point(s) à régler" : 'FAISABLE' );

if ( $cli ) {
	foreach ( $lignes as $l ) { printf( "  %-9s %-42s %s\n", strtoupper( $l[0] ), $l[1], $l[2] ); }
	echo "\n  " . $verdict . "\n  Envoyez cette sortie avec celle de la version navigateur.\n";
	exit( $b ? 1 : 0 );
}
$c = array( 'ok' => '#1a7f37', 'attention' => '#9a6700', 'bloquant' => '#cf222e', 'info' => '#57606a' );
echo '<!doctype html><meta charset="utf-8"><title>Diagnostic FinaKop</title><body style="font:14px system-ui;max-width:1000px;margin:24px auto;padding:0 16px">';
echo '<h1 style="font-size:20px">Diagnostic d\'hébergement FinaKop</h1><p><strong>' . htmlspecialchars( $verdict ) . '</strong> — ' . htmlspecialchars( gmdate( 'Y-m-d H:i' ) ) . ' UTC</p>';
echo '<table style="border-collapse:collapse;width:100%">';
foreach ( $lignes as $l ) {
	echo '<tr style="border-top:1px solid #d0d7de"><td style="padding:6px;color:' . $c[ $l[0] ] . ';font-weight:600">' . strtoupper( $l[0] ) . '</td><td style="padding:6px">' . htmlspecialchars( $l[1] ) . '</td><td style="padding:6px">' . htmlspecialchars( $l[2] ) . '</td></tr>';
}
echo '</table><p style="color:#cf222e"><strong>Supprimez ce fichier après usage.</strong></p>';
