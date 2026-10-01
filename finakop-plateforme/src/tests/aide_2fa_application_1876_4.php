<?php
/**
 * 1.876.4 — application d'authentification privilégiée, centre d'aide à jour.
 *
 *   A-1  Code par e-mail : jamais pour un administrateur ; pour les autres, seulement si la plateforme l'autorise.
 *   A-2  Un compte resté en « e-mail » sans y avoir droit est conduit à passer à l'application ; l'obligation admin inchangée.
 *   H-1  Plateforme : articles « connexion », « double authentification », « confidentialité », « support » ajoutés.
 *   H-2  Plateforme : plus aucune mention de wp-config, wp-content ni du mot de passe « admin » par défaut.
 *   H-3  Parcours : sécurisation d'abord (mot de passe, 2FA) ; licence décrite comme obligatoire.
 *   H-4  Dépannage et glossaire complétés ; vidéos déclarées, présentes et chapitrées ; route aide/videos.
 *
 * Usage : php tests/aide_2fa_application_1876_4.php
 */
$racine = dirname( __DIR__ );
define( 'FKC_ROOT', $racine . '/app/' );
define( 'FKC_PLATEFORME', true );
define( 'FKC_2FA_ADMIN_OBLIGATOIRE', true );
define( 'FKC_2FA_EMAIL', false );
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }
function e( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function url( $p = '' ) { return '/' . ltrim( (string) $p, '/' ); }
function fkc_lc( $s ) { return mb_strtolower( (string) $s ); }

class FKC_Master {
	public static $pdo;
	public static function q( $sql, $p = array() ) { $s = self::$pdo->prepare( $sql ); $s->execute( $p ); return $s; }
}
FKC_Master::$pdo = new PDO( 'sqlite::memory:' );
FKC_Master::$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
FKC_Master::$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
require FKC_ROOT . 'Core/DeuxFacteurs.php';
FKC_DeuxFacteurs::methode( 0 ); // crée la table
FKC_Master::q( "INSERT INTO auth_deux_facteurs(user_id,secret,actif,secours,methode) VALUES(1,'x',1,'[]','email'),(2,'x',1,'[]','totp'),(3,'x',1,'[]','email')" );
$admin = array( 'id' => 1, 'role' => 'admin' ); $admin2 = array( 'id' => 2, 'role' => 'admin' ); $user = array( 'id' => 3, 'role' => 'comptable' ); $neuf = array( 'id' => 9, 'role' => 'admin' );

verifie( ! FKC_DeuxFacteurs::emailPermis( $admin ), 'A-1 e-mail jamais proposé à un administrateur' );
verifie( ! FKC_DeuxFacteurs::emailPermis( $user ), 'A-1 e-mail non proposé si la plateforme ne l\'autorise pas (défaut)' );
verifie( FKC_DeuxFacteurs::aConfigurer( $admin ), 'A-2 administrateur resté en « e-mail » : passage à l\'application imposé' );
verifie( FKC_DeuxFacteurs::aConfigurer( $user ), 'A-2 utilisateur en « e-mail » non autorisé : passage à l\'application' );
verifie( ! FKC_DeuxFacteurs::aConfigurer( $admin2 ), 'A-2 administrateur avec l\'application : rien à faire' );
verifie( FKC_DeuxFacteurs::aConfigurer( $neuf ), 'A-2 administrateur sans 2FA : activation toujours imposée' );
$cfg = (string) file_get_contents( FKC_ROOT . 'Plateforme/Config.php' );
verifie( false !== strpos( $cfg, "'2fa_email'             => false" ), 'A-1 réglage plateforme securite.2fa_email = false par défaut' );
$ctl = (string) file_get_contents( FKC_ROOT . 'Modules/Comptabilite/Controllers/DeuxFacteursController.php' );
verifie( 2 <= substr_count( $ctl, 'FKC_DeuxFacteurs::emailPermis( $u )' ), 'A-1 envoi et activation par e-mail refusés côté serveur si non permis' );

require FKC_ROOT . 'Modules/Aide/Models/Aide.php';
require FKC_ROOT . 'Modules/Aide/Models/AidePlateforme.php';
$ids = array_column( FKC_Aide::articles(), 'id' );
verifie( ! array_diff( array( 'acceder-espace', 'double-authentification', 'confidentialite', 'support' ), $ids ), 'H-1 articles de la plateforme présents' );
verifie( count( $ids ) === count( array_unique( $ids ) ), 'H-1 aucun identifiant d\'article en double' );
$tout = implode( ' ', array_map( function ( $a ) { return $a['corps'] . ' ' . $a['titre']; }, FKC_Aide::articles() ) );
verifie( false === stripos( $tout, 'wp-config' ) && false === stripos( $tout, 'wp-content' ), 'H-2 plus de wp-config ni de wp-content' );
verifie( false === strpos( $tout, 'identique sur toutes les installations' ), 'H-2 plus de mot de passe « admin » par défaut' );
$p1 = FKC_Aide::parcours()[0];
verifie( in_array( 'securite/deux-facteurs', array_column( $p1['etapes'], 'route' ), true ) && in_array( 'mot-de-passe', array_column( $p1['etapes'], 'route' ), true ), 'H-3 parcours : mot de passe et double authentification d\'abord' );
$lic = ''; foreach ( FKC_Aide::parcours() as $p ) { foreach ( $p['etapes'] as $s ) { if ( 'licence' === $s['route'] ) { $lic = $s['detail']; } } }
verifie( false !== strpos( $lic, 'Obligatoire' ), 'H-3 licence décrite comme obligatoire' );
$dep = array_column( FKC_Aide::depannage(), 'q' );
verifie( in_array( 'Je ne reçois pas le code de double authentification par e-mail', $dep, true ) && in_array( "J'ai perdu ou changé de téléphone", $dep, true ), 'H-4 dépannage : code e-mail, téléphone perdu' );
verifie( in_array( 'Code de secours', array_column( FKC_Aide::glossaire(), 'terme' ), true ), 'H-4 glossaire : code de secours' );
$vids = FKC_AidePlateforme::videos(); $ok = 4 === count( $vids );
foreach ( $vids as $v ) { $ok = $ok && is_file( FKC_ROOT . 'assets/' . $v['affiche'] ) && count( $v['chapitres'] ) >= 4; }
verifie( $ok, 'H-4 quatre vidéos déclarées, affiches présentes, chapitrées' );
$mp4 = true; foreach ( $vids as $v ) { $mp4 = $mp4 && is_file( FKC_ROOT . 'assets/' . $v['fichier'] ) && filesize( FKC_ROOT . 'assets/' . $v['fichier'] ) > 200000; }
verifie( $mp4, 'H-4 fichiers vidéo présents' );
verifie( false !== strpos( (string) file_get_contents( FKC_ROOT . 'index.php' ), "'aide/videos'" ) && is_file( FKC_ROOT . 'Modules/Aide/Views/videos.php' ), 'H-4 route et vue aide/videos' );

echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
