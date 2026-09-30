<?php
/**
 * CLÉ DE CHIFFREMENT ET TRAITEMENTS PLANIFIÉS (1.875.6).
 *
 *   K-1  Une clé absente n'est plus remplacée en silence quand des données
 *        chiffrées existent : refus explicite, aucune clé neuve écrite.
 *   K-2  Une clé différente de celle qui a chiffré (constante erronée) est
 *        refusée ; la bonne clé relit ses secrets.
 *   K-3  Installation antérieure sans empreinte : les valeurs chiffrées
 *        présentes suffisent à refuser.
 *   K-4  Installation neuve : clé générée et empreinte posée.
 *   N-1  app/noyau.php charge le noyau hors requête (cron, WP-CLI, autonome).
 *   N-2  Le crochet WP-Cron « fkc_bpe_worker » traite réellement la file et
 *        rend HTTP_HOST tel quel (avant : class_exists() toujours faux).
 *
 * Usage : php tests/cle_chiffrement_noyau_1875_6.php
 *
 * @package FinaKop_ERP_Core
 */
$racine = dirname( __DIR__ );
putenv( 'FKC_APP=' . $racine . '/app/' );
putenv( 'FKC_TESTS=' . __DIR__ );
putenv( 'FKC_PLUGIN=' . $racine . '/finakop-erp-core.php' );

$base = sys_get_temp_dir() . '/fkc_cle1875_6_' . getmypid();
@mkdir( $base, 0777, true );

/** Exécute un worker dans le dossier $dir ; $pre = code PHP placé avant l'amorçage. */
function lancer( $nom, $dir, $corps, $pre = '' ) {
	global $base;
	@mkdir( $dir, 0777, true );
	$code = "<?php\n" . $pre . "\nrequire getenv('FKC_TESTS') . '/worker-bootstrap.php';\n\$out = [];\ntry {\n" . $corps . "\n} catch (\\Throwable \$e) { \$out['exception'] = \$e->getMessage(); }\necho \"\\n\" . json_encode(\$out, JSON_UNESCAPED_UNICODE);\n";
	file_put_contents( $base . '/' . $nom . '.php', $code );
	$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $base . '/' . $nom . '.php' ) . ' ' . escapeshellarg( $dir ) . ' 2>&1' );
	$l = array_values( array_filter( array_map( 'trim', explode( "\n", $o ) ) ) );
	$r = json_decode( (string) end( $l ), true );
	if ( ! is_array( $r ) ) { fwrite( STDERR, "Worker $nom sans JSON :\n$o\n" ); exit( 1 ); }
	return $r;
}

$n = 0; $fail = 0;
function verifie( $ok, $desc, $obtenu = null ) {
	global $n, $fail;
	$n++;
	if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; }
	else { $fail++; echo "  \033[31m✗ $desc\033[0m" . ( null !== $obtenu ? '  ' . json_encode( $obtenu, JSON_UNESCAPED_UNICODE ) : '' ) . "\n"; }
}

$D = $base . '/dossier/';
$boot = "fkc_test_boot(\$argv[1] . '/', 'generique');\n";

/* ── K-4 puis K-1 / K-2 sur le même dossier ─────────────────────────── */
$r = lancer( 'init', $D, $boot . "
\$c = FKC_Crypto::encrypt('secret-client');
FKC_Master::setParam('test_secret', \$c);
\$out['chiffre'] = FKC_Crypto::isEncrypted(\$c);
\$out['source'] = FKC_Crypto::keySource();
\$out['empreinte'] = FKC_Master::param(FKC_Crypto::EMPREINTE);
\$out['fichier'] = is_file(FKC_DATA_DIR . '.fkc-secret.key');" );
echo "\n\033[1mK-4 · Installation neuve\033[0m\n";
verifie( ! empty( $r['chiffre'] ) && 'fichier' === ( $r['source'] ?? '' ) && ! empty( $r['fichier'] ), 'clé générée dans .fkc-secret.key, secret chiffré', $r );
verifie( 32 === strlen( (string) ( $r['empreinte'] ?? '' ) ), 'empreinte de la clé posée dans le registre', $r );

$r = lancer( 'relit', $D, $boot . "\$out['clair'] = FKC_Crypto::decrypt(FKC_Master::param('test_secret'));" );
echo "\n\033[1mK-2 · La bonne clé relit ses secrets\033[0m\n";
verifie( 'secret-client' === ( $r['clair'] ?? null ), 'secret relu après redémarrage', $r );

rename( $D . '.fkc-secret.key', $D . '.cle-de-cote' );
$r = lancer( 'sans_cle', $D, $boot . "\$k = FKC_Crypto::key(); \$out['cle_rendue'] = strlen(\$k);", '' );
echo "\n\033[1mK-1 · Clé absente après un déménagement\033[0m\n";
verifie( false !== strpos( (string) ( $r['exception'] ?? '' ), 'clé de chiffrement introuvable' ), 'refus explicite au lieu d\'une clé neuve', $r );
verifie( ! is_file( $D . '.fkc-secret.key' ), 'aucune clé neuve écrite sur le disque' );

$r = lancer( 'constante_fausse', $D, $boot . "\$k = FKC_Crypto::key(); \$out['cle_rendue'] = strlen(\$k);", "define('FKC_ENCRYPTION_KEY', 'une autre phrase');" );
verifie( false !== strpos( (string) ( $r['exception'] ?? '' ), 'ne correspond pas' ), 'une constante différente de la clé d\'origine est refusée', $r );

rename( $D . '.cle-de-cote', $D . '.fkc-secret.key' );
$r = lancer( 'restauree', $D, $boot . "\$out['clair'] = FKC_Crypto::decrypt(FKC_Master::param('test_secret'));" );
verifie( 'secret-client' === ( $r['clair'] ?? null ), 'clé restaurée : tout se relit, rien n\'a été perdu', $r );

/* ── K-3 : installation 1.875.5 (pas d'empreinte) ────────────────────── */
$A = $base . '/ancien/';
lancer( 'ancien_init', $A, $boot . "FKC_Master::setParam('test_secret', FKC_Crypto::encrypt('x')); FKC_Master::q('DELETE FROM cab_parametres WHERE cle=?', [FKC_Crypto::EMPREINTE]); \$out['ok'] = 1;" );
unlink( $A . '.fkc-secret.key' );
$r = lancer( 'ancien_sans_cle', $A, $boot . "FKC_Crypto::key(); \$out['cle_rendue'] = 1;" );
echo "\n\033[1mK-3 · Installation antérieure, sans empreinte\033[0m\n";
verifie( false !== strpos( (string) ( $r['exception'] ?? '' ), 'clé de chiffrement introuvable' ), 'les valeurs chiffrées présentes suffisent à refuser', $r );

/* ── N-1 : app/noyau.php ─────────────────────────────────────────────── */
$N = $base . '/noyau/';
lancer( 'noyau_init', $N, $boot . "FKC_Crypto::key(); \$out['ok'] = 1;" );
$code = "<?php
define('FKC_ROOT', getenv('FKC_APP')); define('FKC_DATA_DIR', \$argv[1] . '/'); define('FKC_BASE_URL', 'https://erp.exemple.ci/'); define('FKC_BASE_PATH', ''); define('FKC_VERSION', 't');
\$_SERVER['HTTP_HOST'] = 'erp.exemple.ci';
\$out = [];
try {
	\$out['charge'] = require FKC_ROOT . 'noyau.php';
	\$out['classes'] = class_exists('FKC_BPEWorker') && class_exists('FKC_Balayeur') && defined('FKC_NOYAU_CHARGE');
	\$r = FKC_BPEWorker::traiterToutesSocietes();
	\$out['bpe'] = isset(\$r['societes']) && ! in_array('Contexte multi-sociétés indisponible.', \$r['erreurs'], true);
	\$out['sans_sortie'] = true;
} catch (\\Throwable \$e) { \$out['exception'] = \$e->getMessage(); }
echo \"\\n\" . json_encode(\$out);";
file_put_contents( $base . '/noyau.php', $code );
$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $base . '/noyau.php' ) . ' ' . escapeshellarg( $N ) . ' 2>&1' );
$l = array_values( array_filter( array_map( 'trim', explode( "\n", $o ) ) ) );
$r = json_decode( (string) end( $l ), true ) ?: array( 'sortie' => $o );
echo "\n\033[1mN-1 · Noyau chargé hors requête\033[0m\n";
verifie( true === ( $r['charge'] ?? null ) && ! empty( $r['classes'] ), 'app/noyau.php déclare les classes sans servir de requête', $r );
verifie( ! empty( $r['bpe'] ), 'le worker parcourt les sociétés', $r );
$vide = $base . '/vide/'; @mkdir( $vide, 0777, true );
$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $base . '/noyau.php' ) . ' ' . escapeshellarg( $vide ) . ' 2>&1' );
verifie( ! is_file( $vide . 'finakopcore-master.db' ), 'dossier jamais servi : rien n\'est créé (aucun compte administrateur semé)' );

/* ── N-2 : crochet WP-Cron de l'extension ────────────────────────────── */
$code = <<<'W'
<?php
define('ABSPATH', '/');
define('FKC_DATA_DIR', $argv[1] . '/');
$GLOBALS['crochets'] = [];
function add_action($h, $f, $p = 10) { $GLOBALS['crochets'][$h][] = $f; }
function add_filter($h, $f, $p = 10, $a = 1) {}
function register_activation_hook($f, $c) {} function register_deactivation_hook($f, $c) {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function plugins_url($p, $f) { return 'https://site.exemple.ci/wp-content/plugins/finakop-erp-core/' . $p; }
function get_option($k, $d = false) { return ['fkc_access_mode' => 'auto', 'fkc_subdomain_prefix' => 'finakopcore'][$k] ?? $d; }
function home_url($p = '') { return 'https://www.exemple.ci' . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function is_admin() { return false; }
function wp_next_scheduled($h) { return time() + 60; }
function wp_schedule_event() { return true; }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
$_SERVER['HTTP_HOST'] = 'www.exemple.ci';
require getenv('FKC_PLUGIN');
$out = [];
try {
	foreach ($GLOBALS['crochets']['fkc_bpe_worker'] ?? [] as $f) { $f(); }
	$out['classes'] = class_exists('FKC_BPEWorker');
	$out['hote_rendu'] = $_SERVER['HTTP_HOST'];
	$out['hote_app'] = fkc_hote_application();
	$out['base'] = FKC_BASE_URL;
	foreach ($GLOBALS['crochets']['fkc_balayage_temporisation'] ?? [] as $f) { $f(); }
	$out['balayage'] = true;
} catch (\Throwable $e) { $out['exception'] = $e->getMessage(); }
echo "\n" . json_encode($out);
W;
file_put_contents( $base . '/wpcron.php', $code );
$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $base . '/wpcron.php' ) . ' ' . escapeshellarg( $N ) . ' 2>&1' );
$l = array_values( array_filter( array_map( 'trim', explode( "\n", $o ) ) ) );
$r = json_decode( (string) end( $l ), true ) ?: array( 'sortie' => $o );
echo "\n\033[1mN-2 · WP-Cron de l'extension\033[0m\n";
verifie( ! empty( $r['classes'] ) && empty( $r['exception'] ), 'le crochet fkc_bpe_worker charge le noyau et traite la file', $r );
verifie( 'www.exemple.ci' === ( $r['hote_rendu'] ?? '' ), 'HTTP_HOST est rendu à WordPress après le traitement', $r );
verifie( 'finakopcore.exemple.ci' === ( $r['hote_app'] ?? '' ) && 'https://finakopcore.exemple.ci/' === ( $r['base'] ?? '' ), 'hôte de l\'application déduit du préfixe de sous-domaine', $r );
verifie( ! empty( $r['balayage'] ), 'le crochet de balayage s\'exécute aussi', $r );

// Nettoyage.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
@rmdir( $base );

echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
