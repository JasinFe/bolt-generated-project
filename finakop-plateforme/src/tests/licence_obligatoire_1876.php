<?php
/**
 * LICENCE OBLIGATOIRE ET INSTALLATION RÉSERVÉE (1.876.0).
 *
 *   L-1  Sans FKC_LICENSE_OBLIGATOIRE (extension WordPress) : comportement
 *        historique, édition Starter sans jeton.
 *   L-2  Avec FKC_LICENSE_OBLIGATOIRE et sans jeton : aucun module, espace
 *        verrouillé ; le routeur renvoie vers « licence » ; l'API répond 402.
 *   L-3  Seules « licence », « mot-de-passe » et « logout » restent ouvertes.
 *   L-4  Installer ou remplacer la licence est réservé à l'administrateur.
 *
 * Usage : php tests/licence_obligatoire_1876.php
 */
$racine = dirname( __DIR__ ) . '/app/';
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }
function etat( $racine, $obligatoire ) {
	$code = '<?php define("FKC_ROOT",' . var_export( $racine, true ) . ');' . ( $obligatoire ? 'define("FKC_LICENSE_OBLIGATOIRE",true);' : '' )
		. 'class FKC_Plans { static function get($p){return array("modules"=>array("comptabilite","facturation"),"caps"=>array());} static function edition($p){return "core";} static function limitsFor($p){return array();} }'
		. 'class FKC_Modules { static function profileLabel($p){return "Starter";} static function profiles(){return array();} static function isSubModule($c){return false;} }'
		. 'require FKC_ROOT."Core/License.php";'
		. '$r=new ReflectionClass("FKC_License"); $m=$r->getMethod("localStarter"); $s=$m->invoke(null);'
		. 'echo json_encode(array("status"=>$s["status"],"valid"=>$s["valid"],"mods"=>count($s["modules"]),"verrou"=>FKC_License::verrouille()));';
	$tmp = tempnam( sys_get_temp_dir(), 'fkclo' ); file_put_contents( $tmp, $code );
	$o = (string) shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
	unlink( $tmp );
	return json_decode( $o, true ) ?: array( 'brut' => $o );
}
$sans = etat( $racine, false );
verifie( 'starter' === ( $sans['status'] ?? '' ) && ! empty( $sans['valid'] ) && empty( $sans['verrou'] ), 'L-1 sans la constante : édition Starter, pas de verrou' );
$avec = etat( $racine, true );
verifie( 'missing' === ( $avec['status'] ?? '' ) && empty( $avec['valid'] ) && 0 === ( $avec['mods'] ?? -1 ) && ! empty( $avec['verrou'] ), 'L-2 licence obligatoire sans jeton : aucun module, espace verrouillé' );

$router = (string) file_get_contents( $racine . 'Core/Router.php' );
verifie( false !== strpos( $router, "empty( \$r['opt']['sans_licence'] ) && FKC_License::verrouille()" ) && false !== strpos( $router, "redirect( 'licence' )" ), 'L-2 routeur : renvoi vers l\'écran Licence' );
$api = (string) file_get_contents( $racine . 'Core/Api.php' );
verifie( false !== strpos( $api, "self::error( 402, 'licence_requise'" ), 'L-2 API : 402 licence_requise' );
$index = (string) file_get_contents( $racine . 'index.php' );
preg_match_all( "/'sans_licence' => true/", $index, $m );
verifie( 5 === count( $m[0] ), 'L-3 exactement 5 routes hors verrou (licence ×2, mot-de-passe ×2, logout) — ' . count( $m[0] ) );
$ctl = (string) file_get_contents( $racine . 'Modules/Comptabilite/Controllers/DashboardController.php' );
$i = strpos( $ctl, 'function activateLicense' );
verifie( false !== $i && false !== strpos( substr( $ctl, $i, 600 ), 'FKC_Auth::isAdmin()' ), 'L-4 installation de licence réservée à l\'administrateur' );
$vue = (string) file_get_contents( $racine . 'views/license.php' );
verifie( false !== strpos( $vue, "<?php if ( FKC_Auth::isAdmin() ) : // seul l'administrateur" ), 'L-4 formulaire affiché au seul administrateur' );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
