<?php
/**
 * FLUX TEMPS RÉEL (1.876.0) — SSE désactivable pour l'hébergement mutualisé.
 *
 * L'afficheur client et le scanner gardaient un flux SSE ouvert en continu :
 * un processus PHP occupé par écran. FKC_SSE = false les fait passer par leur
 * interrogation courte. Sans la constante, rien ne change (extension WordPress).
 *
 * Usage : php tests/plateforme_temps_reel_1876.php
 */
define( 'FKC_ROOT', dirname( __DIR__ ) . '/app/' );
require FKC_ROOT . 'Core/helpers.php';
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }

verifie( function_exists( 'fkc_sse_actif' ) && fkc_sse_actif(), 'sans FKC_SSE : SSE actif (comportement historique)' );
$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' -r ' . escapeshellarg( 'define("FKC_ROOT",' . var_export( FKC_ROOT, true ) . ');define("FKC_SSE",false);require FKC_ROOT."Core/helpers.php";echo fkc_sse_actif()?"oui":"non";' ) );
verifie( 'non' === trim( $o ), 'FKC_SSE = false : SSE inactif' );
$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' -r ' . escapeshellarg( 'define("FKC_ROOT",' . var_export( FKC_ROOT, true ) . ');define("FKC_SSE",false);require FKC_ROOT."Core/helpers.php";fkc_sse_refuser_si_inactif();echo "SUITE";' ) );
verifie( false === strpos( $o, 'SUITE' ), 'flux désactivé : la requête s\'arrête aussitôt (204)' );
foreach ( array( 'Modules/Caisse/Views/afficheur.php', 'Modules/Scan/Views/smart.php' ) as $v ) {
	verifie( false !== strpos( (string) file_get_contents( FKC_ROOT . $v ), "'EventSource' in window && <?= fkc_sse_actif()" ), "{$v} : n'ouvre le flux que si SSE est actif, sinon interrogation" );
}
foreach ( array( 'Modules/Caisse/Controllers/PosController.php' => 'afficheurStream', 'Modules/Scan/Controllers/ScanController.php' => 'smartStream' ) as $f => $m ) {
	$s = (string) file_get_contents( FKC_ROOT . $f );
	$i = strpos( $s, 'function ' . $m );
	verifie( false !== $i && false !== strpos( substr( $s, $i, 1500 ), 'fkc_sse_refuser_si_inactif()' ), "{$m}() refuse le flux quand SSE est inactif" );
}
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
