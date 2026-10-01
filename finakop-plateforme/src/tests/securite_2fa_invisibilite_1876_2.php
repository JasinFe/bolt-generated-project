<?php
/**
 * SÉCURITÉ 1.876.2 — double authentification et invisibilité.
 *
 *   D-1  TOTP conforme RFC 6238 (vecteurs officiels, SHA-1, 6 chiffres).
 *   D-2  Base32 réversible ; fenêtre ±1 pas ; anti-rejeu ; code mal formé refusé.
 *   D-3  Connexion : la session n'est ouverte qu'après le second facteur.
 *   I-1  Robots d'indexation, d'IA et d'aperçu refusés ; navigateurs, API et webhooks non.
 *   I-2  .htaccess : X-Robots-Tag noai sur toute réponse ; dossier inaccessible par l'adresse principale.
 *
 * Usage : php tests/securite_2fa_invisibilite_1876_2.php
 */
$racine = dirname( __DIR__ );
define( 'FKC_ROOT', $racine . '/app/' );
define( 'FKC_PLATEFORME', true );
require FKC_ROOT . 'Core/DeuxFacteurs.php';
require FKC_ROOT . 'Plateforme/Amorcage.php';
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }

$s = FKC_DeuxFacteurs::base32( '12345678901234567890' );
verifie( 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' === $s, 'D-2 base32 RFC 4648' );
verifie( '12345678901234567890' === FKC_DeuxFacteurs::debase32( $s ), 'D-2 base32 réversible' );
// RFC 6238, annexe B (SHA-1, 8 chiffres) : les 6 derniers chiffres.
foreach ( array( 59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037' ) as $t => $attendu ) {
	verifie( $attendu === FKC_DeuxFacteurs::code( $s, intdiv( $t, 30 ) ), "D-1 RFC 6238 T={$t} → {$attendu}" );
}
$t = 1700000000; $p = intdiv( $t, 30 );
verifie( $p === FKC_DeuxFacteurs::verifierCode( $s, FKC_DeuxFacteurs::code( $s, $p ), 0, $t ), 'D-2 code courant accepté' );
verifie( ( $p - 1 ) === FKC_DeuxFacteurs::verifierCode( $s, FKC_DeuxFacteurs::code( $s, $p - 1 ), 0, $t ), 'D-2 code du pas précédent accepté (±1)' );
verifie( false === FKC_DeuxFacteurs::verifierCode( $s, FKC_DeuxFacteurs::code( $s, $p - 3 ), 0, $t ), 'D-2 code ancien (−3 pas) refusé' );
verifie( false === FKC_DeuxFacteurs::verifierCode( $s, FKC_DeuxFacteurs::code( $s, $p ), $p, $t ), 'D-2 anti-rejeu : pas déjà utilisé refusé' );
verifie( false === FKC_DeuxFacteurs::verifierCode( $s, '12a456', 0, $t ) && false === FKC_DeuxFacteurs::verifierCode( $s, '', 0, $t ), 'D-2 code mal formé refusé' );
verifie( 32 === strlen( FKC_DeuxFacteurs::nouveauSecret() ), 'D-2 secret de 160 bits' );

$auth = (string) file_get_contents( FKC_ROOT . 'Core/Auth.php' );
$i = strpos( $auth, "FKC_DeuxFacteurs::actif( (int) \$row['id'] )" ); $j = strpos( $auth, "\$_SESSION['fkc_uid']       = (int) \$row['id'];" );
verifie( false !== $i && false !== $j && false !== strpos( substr( $auth, $i, 1500 ), "return 'deux_facteurs';" ), 'D-3 mot de passe juste + 2FA : retour avant ouverture de session' );
$router = (string) file_get_contents( FKC_ROOT . 'Core/Router.php' );
verifie( false !== strpos( $router, "deuxFacteursEnAttente() ? 'verification' : 'login'" ) && false !== strpos( $router, "FKC_DeuxFacteurs::aConfigurer( FKC_Auth::user() )" ), 'D-3 routeur : écran du code, activation imposée' );

$robots = array( 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'GPTBot/1.2', 'Mozilla/5.0 (compatible; ClaudeBot/1.0)', 'PerplexityBot/1.0', 'CCBot/2.0', 'facebookexternalhit/1.1', 'WhatsApp/2.23', 'Mozilla/5.0 (compatible; bingbot/2.0)' );
$humains = array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0 Safari/537.36', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Mobile Safari/604.1', 'Mozilla/5.0 (Linux; Android 10; CUBOT KINGKONG 5) Chrome/120 Mobile', 'curl/8.5.0' );
$ok = true; foreach ( $robots as $ua ) { $ok = $ok && FKC_Plateforme_Amorcage::estRobot( array( 'HTTP_USER_AGENT' => $ua, 'REQUEST_URI' => '/login' ) ); }
verifie( $ok, 'I-1 robots de moteurs, d\'IA et d\'aperçus refusés (' . count( $robots ) . ')' );
$ok = true; foreach ( $humains as $ua ) { $ok = $ok && ! FKC_Plateforme_Amorcage::estRobot( array( 'HTTP_USER_AGENT' => $ua, 'REQUEST_URI' => '/login' ) ); }
verifie( $ok, 'I-1 navigateurs (dont CUBOT) et outils non refusés' );
verifie( ! FKC_Plateforme_Amorcage::estRobot( array( 'HTTP_USER_AGENT' => 'TelegramBot (like TwitterBot)', 'REQUEST_URI' => '/webhook/x' ) ), 'I-1 webhooks exemptés' );
$ht = (string) file_get_contents( $racine . '/public/.htaccess' );
verifie( false !== strpos( $ht, 'X-Robots-Tag "noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai"' ), 'I-2 X-Robots-Tag sur toutes les réponses' );
verifie( false !== strpos( $ht, '^/__DOSSIER_WEB__(/|$)' ) && false !== strpos( (string) file_get_contents( $racine . '/scripts/deployer.sh' ), 's#__DOSSIER_WEB__#' ), 'I-2 dossier inaccessible par l\'adresse principale' );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
