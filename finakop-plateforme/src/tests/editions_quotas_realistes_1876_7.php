<?php
/**
 * 1.876.7 — quotas des éditions ramenés à la capacité réelle d'hébergement.
 *
 *   Q-1  Aucun palier illimité sur sociétés, utilisateurs, établissements, entrepôts, stockage, API.
 *   Q-2  Chaque quota progresse avec le rang (une édition ne donne jamais plus que celle du dessus).
 *   Q-3  Core et Creative partagent les mêmes socles au même rang.
 *   Q-4  Stockage tenable : 100 Go au plus (Premium, VPS dédié), 10 Go au plus jusqu'à Pro (mutualisé).
 *   Q-5  Plus de réplication ni de haute disponibilité promises ; libellé « Premium » ; clés et alias inchangés.
 *
 * Usage : php tests/editions_quotas_realistes_1876_7.php
 */
define( 'FKC_ROOT', dirname( __DIR__ ) . '/app/' );
require FKC_ROOT . 'Core/Plans.php';
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }
$T = FKC_Plans::tiers();
$socle = array( 'societes', 'utilisateurs', 'etablissements', 'entrepots', 'stockage_go', 'api' );
$ok = true; foreach ( $T as $k => $t ) { foreach ( $socle as $c ) { if ( ! isset( $t['limits'][ $c ] ) || $t['limits'][ $c ] < 0 ) { $ok = false; echo "    $k.$c\n"; } } }
verifie( $ok, 'Q-1 aucun quota de socle illimité' );
$gammes = array( 'core' => array(), 'creative' => array() );
foreach ( $T as $k => $t ) { $gammes[ $t['edition'] ][ $t['rang'] ] = $k; }
$ok = true;
foreach ( $gammes as $g => $par ) {
	ksort( $par ); $par = array_values( $par );
	for ( $i = 1; $i < count( $par ); $i++ ) {
		foreach ( $T[ $par[ $i ] ]['limits'] as $c => $v ) {
			$a = $T[ $par[ $i - 1 ] ]['limits'][ $c ] ?? 0;
			if ( -1 === $a && -1 !== $v ) { $ok = false; echo "    $g {$par[$i]}.$c < précédent (illimité)\n"; }
			if ( -1 !== $v && -1 !== $a && $v < $a ) { $ok = false; echo "    $g {$par[$i]}.$c=$v < $a\n"; }
		}
	}
}
verifie( $ok, 'Q-2 quotas croissants avec le rang (Core et Creative)' );
verifie( $T['pro']['limits']['utilisateurs'] < $T['enterprise_standard']['limits']['utilisateurs'], 'Q-2 Pro a moins d\'utilisateurs qu\'Entreprise Standard' );
$ok = true;
foreach ( $gammes['core'] as $r => $k ) { $c = $gammes['creative'][ $r ]; foreach ( $socle as $s ) { if ( $T[ $k ]['limits'][ $s ] !== $T[ $c ]['limits'][ $s ] ) { $ok = false; echo "    $k/$c.$s\n"; } } }
verifie( $ok, 'Q-3 socles identiques Core / Creative au même rang' );
$max = max( array_map( function ( $t ) { return $t['limits']['stockage_go']; }, $T ) );
verifie( $max <= 100 && $T['pro']['limits']['stockage_go'] <= 10 && $T['starter']['limits']['stockage_go'] >= 1, 'Q-4 stockage : ≤ 100 Go, ≤ 10 Go jusqu\'à Pro' );
$caps = ''; foreach ( $T as $t ) { $caps .= json_encode( $t['caps'] ); }
verifie( false === strpos( $caps, 'replication' ) && false === strpos( $caps, 'haute_dispo' ), 'Q-5 ni réplication ni haute disponibilité promises' );
verifie( 'Entreprise Premium' === FKC_Plans::label( 'enterprise_illimitee' ) && 'Creative Enterprise Premium' === FKC_Plans::label( 'creative_enterprise_illimitee' ) && false === strpos( json_encode( array_column( $T, 'label' ), JSON_UNESCAPED_UNICODE ), 'Illimit' ), 'Q-5 libellé « Premium », plus d\'« Illimitée »' );
verifie( 'Creative Enterprise Premium' === FKC_Plans::label( 'creative' ) && 'Entreprise Standard' === FKC_Plans::label( 'enterprise' ), 'Q-5 alias historiques toujours résolus' );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
