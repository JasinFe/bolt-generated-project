<?php
/**
 * 1.876.3 — double authentification par e-mail.
 *
 *   E-1  Activation : code envoyé à l'adresse du compte, haché (jamais en clair), activé avec ce code.
 *   E-2  Connexion : code valable 10 min, usage unique côté appelant, faux code et code expiré refusés.
 *   E-3  Un code d'application (TOTP) n'est pas accepté pour un compte « e-mail » ; codes de secours valables.
 *   E-4  Limites : 3 envois au plus, 1 par minute ; adresse absente ou invalide refusée ; adresse masquée.
 *   E-5  Migration : colonne « methode » ajoutée à un registre 1.876.2 ; comptes existants restent « totp ».
 *   E-6  Câblage : connexion, renvoi, routes, portail redessiné sans ressource extérieure.
 *
 * Usage : php tests/deux_facteurs_email_1876_3.php
 */
$racine = dirname( __DIR__ );
define( 'FKC_ROOT', $racine . '/app/' );
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }

/* Doublures minimales : registre SQLite en mémoire, chiffrement, courriel, audit. */
class FKC_Master {
	public static $pdo;
	public static function q( $sql, $p = array() ) { $s = self::$pdo->prepare( $sql ); $s->execute( $p ); return $s; }
}
class FKC_Crypto { static function encrypt( $v ) { return 'enc:' . base64_encode( $v ); } static function decrypt( $v ) { return base64_decode( substr( $v, 4 ) ); } }
class FKC_Courriel {
	public static $boite = array();
	static function adresseValide( $a ) { $a = trim( (string) $a ); return '' !== $a && false === strpbrk( $a, "\r\n," ) && false !== filter_var( $a, FILTER_VALIDATE_EMAIL ); }
	static function envoyer( $to, $sujet, $texte ) { self::$boite[] = array( $to, $sujet, $texte ); return array( true, 'ok' ); }
}
class FKC_Security { public static $audit = array(); static function audit( $a, $d = '', $u = 0 ) { self::$audit[] = $a; } }

FKC_Master::$pdo = new PDO( 'sqlite::memory:' );
FKC_Master::$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
FKC_Master::$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
FKC_Master::q( 'CREATE TABLE cabinet_users(id INTEGER PRIMARY KEY, login TEXT, email TEXT)' );
FKC_Master::q( "INSERT INTO cabinet_users VALUES(1,'awa','awa.kone@atlas.ci'),(2,'sans','') ,(3,'ancien','x@y.ci')" );
// Registre 1.876.2 (sans colonne methode) avec un compte déjà en TOTP.
FKC_Master::q( "CREATE TABLE auth_deux_facteurs(user_id INTEGER PRIMARY KEY, secret TEXT NOT NULL, actif INTEGER NOT NULL DEFAULT 0, secours TEXT, dernier_pas INTEGER NOT NULL DEFAULT 0, active_le TEXT)" );
FKC_Master::q( "INSERT INTO auth_deux_facteurs(user_id,secret,actif,secours) VALUES(3,'enc:x',1,'[]')" );

require FKC_ROOT . 'Core/DeuxFacteurs.php';

verifie( 'totp' === FKC_DeuxFacteurs::methode( 3 ), 'E-5 colonne ajoutée, compte existant resté « application »' );
verifie( null === FKC_DeuxFacteurs::methode( 1 ), 'E-5 compte sans 2FA : aucune méthode' );

/* E-1 activation */
$etat = array();
list( $ok, $msg ) = FKC_DeuxFacteurs::envoyerCodeEmail( 1, $etat, 'activation' );
$mail = end( FKC_Courriel::$boite );
preg_match( '/\b(\d{6})\b/', $mail[2], $m ); $code = $m[1] ?? '';
verifie( $ok && 'awa.kone@atlas.ci' === $mail[0] && 6 === strlen( $code ), 'E-1 code à 6 chiffres envoyé à l\'adresse du compte' );
verifie( false === strpos( json_encode( $etat ), $code ) && password_verify( $code, $etat['h'] ), 'E-1 code haché en session, jamais en clair' );
verifie( false !== strpos( $msg, 'a***@a***.ci' ) && false === strpos( $msg, 'awa.kone' ), 'E-4 adresse masquée dans le message' );
list( $ko ) = FKC_DeuxFacteurs::activerEmail( 1, $etat, '000000' === $code ? '111111' : '000000' );
verifie( ! $ko && null === FKC_DeuxFacteurs::methode( 1 ), 'E-1 mauvais code : pas d\'activation' );
list( $ok, , $secours ) = FKC_DeuxFacteurs::activerEmail( 1, $etat, $code );
verifie( $ok && 'email' === FKC_DeuxFacteurs::methode( 1 ) && 10 === count( $secours ), 'E-1 activation par e-mail, 10 codes de secours' );

/* E-2 connexion */
$etat = array();
FKC_DeuxFacteurs::envoyerCodeEmail( 1, $etat, 'connexion' );
preg_match( '/\b(\d{6})\b/', end( FKC_Courriel::$boite )[2], $m ); $code = $m[1];
verifie( 'email' === FKC_DeuxFacteurs::verifier( 1, $code, $etat ), 'E-2 bon code accepté' );
verifie( 'email' === FKC_DeuxFacteurs::verifier( 1, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), $etat ), 'E-2 code saisi avec espace accepté' );
verifie( false === FKC_DeuxFacteurs::verifier( 1, str_pad( (string) ( ( (int) $code + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT ), $etat ), 'E-2 faux code refusé' );
verifie( false === FKC_DeuxFacteurs::verifier( 1, $code, null ), 'E-2 sans code envoyé (session vide) : refusé' );
$expire = $etat; $expire['exp'] = time() - 1;
verifie( false === FKC_DeuxFacteurs::verifier( 1, $code, $expire ), 'E-2 code expiré refusé' );

/* E-3 TOTP refusé pour un compte e-mail, secours accepté */
$secret = FKC_DeuxFacteurs::nouveauSecret();
verifie( false === FKC_DeuxFacteurs::verifier( 1, FKC_DeuxFacteurs::code( $secret, intdiv( time(), 30 ) ), array() ), 'E-3 code d\'application refusé pour un compte « e-mail »' );
verifie( 'secours' === FKC_DeuxFacteurs::verifier( 1, $secours[0], null ), 'E-3 code de secours accepté' );
verifie( false === FKC_DeuxFacteurs::verifier( 1, $secours[0], null ), 'E-3 code de secours à usage unique' );

/* E-4 limites */
$etat = array();
list( $a ) = FKC_DeuxFacteurs::envoyerCodeEmail( 1, $etat );
list( $b, $mb ) = FKC_DeuxFacteurs::envoyerCodeEmail( 1, $etat );
verifie( $a && ! $b && false !== strpos( $mb, 'Patientez' ), 'E-4 un envoi par minute' );
$etat['envoye'] = time() - 120; FKC_DeuxFacteurs::envoyerCodeEmail( 1, $etat );
$etat['envoye'] = time() - 120; FKC_DeuxFacteurs::envoyerCodeEmail( 1, $etat );
$etat['envoye'] = time() - 120; list( $c, $mc ) = FKC_DeuxFacteurs::envoyerCodeEmail( 1, $etat );
verifie( 3 === $etat['n'] && ! $c && false !== strpos( $mc, 'maximal' ), 'E-4 trois envois au plus' );
$vide = array(); list( $d ) = FKC_DeuxFacteurs::envoyerCodeEmail( 2, $vide );
verifie( ! $d, 'E-4 compte sans adresse : refus' );
verifie( 'x***@y***.ci' === FKC_DeuxFacteurs::masquer( 'xavier@yop.ci' ) && '***' === FKC_DeuxFacteurs::masquer( 'pas-une-adresse' ), 'E-4 masquage' );

/* E-6 câblage */
$auth = (string) file_get_contents( FKC_ROOT . 'Core/Auth.php' );
verifie( false !== strpos( $auth, "envoyerCodeEmail( (int) \$row['id'], \$etat, 'connexion' )" ) && false !== strpos( $auth, "\$a['email'] ?? null" ), 'E-6 connexion : code envoyé et vérifié contre la session' );
$idx = (string) file_get_contents( FKC_ROOT . 'index.php' );
verifie( false !== strpos( $idx, "'verification/renvoyer'" ) && false !== strpos( $idx, "'securite/deux-facteurs/email/activer'" ), 'E-6 routes de renvoi et d\'activation par e-mail' );
$pages = (string) file_get_contents( FKC_ROOT . 'Plateforme/Pages.php' );
verifie( ! preg_match( '#(src|href)="https?://(?!wa\.me|finakoperp\.com)#', $pages ) && false !== strpos( $pages, "img-src 'self'" ) && false !== strpos( $pages, "'portail' );" ), 'E-6 portail redessiné, aucune ressource extérieure' );

echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
