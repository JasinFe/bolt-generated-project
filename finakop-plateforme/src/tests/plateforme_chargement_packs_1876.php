<?php
/**
 * CHARGEMENT DES PACKS (1.876.0) — aucune double déclaration.
 *
 * FKC_Packs::boot() charge les modèles et contrôleurs du pack principal par
 * require_once. app/index.php rechargeait ensuite certains fichiers des packs
 * Industrie et Distribution par un simple require : « Cannot declare class
 * FKC_IndMrp » sur CHAQUE page d'une société au pack principal Industrie
 * (ou Distribution). Ce harnais interdit tout require simple d'un fichier de
 * pack dans app/index.php, et vérifie que FKC_Router n'émet plus le code non
 * standard 419 (converti en 500 par Apache).
 *
 * Usage : php tests/plateforme_chargement_packs_1876.php
 */
$racine = dirname( __DIR__ ) . '/app/';
$index  = (string) file_get_contents( $racine . 'index.php' );
$router = (string) file_get_contents( $racine . 'Core/Router.php' );
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }

preg_match_all( "/^\s*require\s+FKC_ROOT\s*\.\s*'Packs\/[^']+'/m", $index, $m );
verifie( 0 === count( $m[0] ), 'aucun require simple d\'un fichier de pack dans app/index.php (' . count( $m[0] ) . ' trouvé(s))' );
foreach ( array( 'industrie/Models/Mrp.php', 'industrie/Models/Production.php', 'distribution/Models/Depot.php' ) as $f ) {
	verifie( false !== strpos( $index, "require_once FKC_ROOT . 'Packs/" . $f . "'" ), "Packs/{$f} chargé par require_once" );
}
// Chargement effectif dans l'ordre de l'application : boot (require_once) puis index (require_once).
foreach ( array( 'industrie', 'distribution' ) as $pack ) {
	$code = '<?php define("FKC_ROOT", ' . var_export( $racine, true ) . '); foreach (glob(FKC_ROOT . "Packs/' . $pack . '/Models/*.php") as $f) { require_once $f; } '
		. 'preg_match_all("/require_once FKC_ROOT \\\\. \'(Packs\\\\/' . $pack . '\\\\/Models\\\\/[^\']+)\'/", file_get_contents(FKC_ROOT . "index.php"), $m); '
		. 'foreach ($m[1] as $f) { require_once FKC_ROOT . $f; } echo "OK";';
	$tmp = tempnam( sys_get_temp_dir(), 'fkcpk' ); file_put_contents( $tmp, $code );
	$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
	unlink( $tmp );
	verifie( 'OK' === trim( $o ), "pack {$pack} : chargement par boot puis par index sans double déclaration" . ( 'OK' === trim( $o ) ? '' : ' — ' . substr( $o, 0, 160 ) ) );
}
verifie( false === strpos( $router, 'http_response_code( 419 )' ), 'jeton CSRF invalide : plus de code 419 (non standard)' );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
