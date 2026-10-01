<?php
/**
 * EXPORTS CSV (1.876.0) — pas d'injection de formule dans le tableur.
 *
 * Une cellule saisie par un utilisateur et commençant par = + - @ (ou une
 * tabulation, un retour chariot) était recopiée telle quelle dans les exports
 * CSV : Excel l'exécutait comme une formule à l'ouverture. fkc_csv_cellule()
 * la force en texte (apostrophe) sans toucher aux montants négatifs.
 *
 * Usage : php tests/export_csv_injection_1876.php
 */
define( 'FKC_ROOT', dirname( __DIR__ ) . '/app/' );
require FKC_ROOT . 'Core/helpers.php';
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }

verifie( function_exists( 'fkc_csv_cellule' ), 'fkc_csv_cellule() existe' );
foreach ( array( '=HYPERLINK("http://x";"clic")', '=1+1', '@SUM(A1:A9)', '+cmd|\' /C calc\'!A0', '-2+3+cmd|x', "\t=1", "\r=1", '- remise' ) as $c ) {
	verifie( "'" . $c === fkc_csv_cellule( $c ), 'neutralisée : ' . json_encode( $c ) );
}
foreach ( array( '-1500', '-1 500,00', "-1\xC2\xA0500,50", '+25', 'Facture 12', 'Dupont', '', '0' ) as $c ) {
	verifie( $c === fkc_csv_cellule( $c ), 'inchangée : ' . json_encode( $c ) );
}
verifie( -5 === fkc_csv_cellule( -5 ) && 2.5 === fkc_csv_cellule( 2.5 ) && null === fkc_csv_cellule( null ), 'nombres et null inchangés' );
$h = (string) file_get_contents( FKC_ROOT . 'Core/helpers.php' );
verifie( false !== strpos( $h, "array_map( 'fkc_csv_cellule'" ), 'fkc_csv() applique la neutralisation à chaque cellule' );
$c = (string) file_get_contents( FKC_ROOT . 'Services/Connect/Controllers/ConnectController.php' );
verifie( false !== strpos( $c, "array_map( 'fkc_csv_cellule'" ), 'export du journal Connect neutralisé' );
// Aucun autre fputcsv brut dans l'application.
$bruts = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( FKC_ROOT, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( 'php' !== $f->getExtension() ) { continue; }
	foreach ( file( $f->getPathname() ) as $i => $l ) {
		if ( false !== strpos( $l, 'fputcsv(' ) && false === strpos( $l, 'fkc_csv_cellule' ) ) { $bruts[] = substr( $f->getPathname(), strlen( FKC_ROOT ) ) . ':' . ( $i + 1 ); }
	}
}
verifie( ! $bruts, 'aucun fputcsv sans neutralisation' . ( $bruts ? ' — ' . implode( ', ', $bruts ) : '' ) );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
