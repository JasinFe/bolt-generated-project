<?php
/**
 * 1.876.6 — nouveau logo FinaKop, libellé « registre + N société(s) ».
 *
 *   L-1  Logo et icône (favicon, PWA, terminal) : nouveau dessin rond, fond transparent.
 *   L-2  Plus de cadre blanc autour du logo sur les pages de connexion et de la plateforme.
 *   R-1  La console n'annonce plus « N base(s) » pour un espace : registre + sociétés.
 *
 * Usage : php tests/marque_logo_registre_1876_6.php
 */
$racine = dirname( __DIR__ );
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }
function png( $f ) { $i = @getimagesize( $f ); return $i ? array( $i[0], $i[1] ) : array( 0, 0 ); }
$img = $racine . '/app/assets/img/';
verifie( array( 512, 512 ) === png( $img . 'logo.png' ) && array( 256, 256 ) === png( $img . 'logo-256.png' ), 'L-1 logo 512 px et icône 256 px' );
// Coins transparents (logo rond) : octet de type de couleur PNG = 6 (RGBA).
verifie( 6 === ord( substr( (string) file_get_contents( $img . 'logo.png' ), 25, 1 ) ) && 6 === ord( substr( (string) file_get_contents( $img . 'logo-256.png' ), 25, 1 ) ), 'L-1 logo avec transparence (RGBA)' );
if ( function_exists( 'imagecreatefrompng' ) ) {
	$g = imagecreatefrompng( $img . 'logo-256.png' );
	$coin = ( imagecolorat( $g, 2, 2 ) >> 24 ) & 0x7F; $centre = ( imagecolorat( $g, 128, 128 ) >> 24 ) & 0x7F;
	verifie( $coin > 120 && 0 === $centre, 'L-1 coins transparents, centre opaque (dessin rond)' );
}
$css = (string) file_get_contents( $racine . '/app/assets/css/app.css' );
verifie( false === strpos( $css, '.hero-logos{display:inline-flex;align-self:flex-start;align-items:center;gap:14px;background:#fff' ), 'L-2 plus de pastille blanche autour du logo (connexion, 2FA)' );
$pages = (string) file_get_contents( $racine . '/app/Plateforme/Pages.php' );
verifie( false === strpos( $pages, '.logo img{width:62px;height:62px;border-radius:18px;background:#fff' ) && false !== strpos( $pages, '.logo img{width:66px;height:66px;border-radius:50%' ), 'L-2 pages de la plateforme : logo rond, sans fond blanc' );
$con = (string) file_get_contents( $racine . '/app/Plateforme/Console.php' );
verifie( false === strpos( $con, "%d base(s), intégrité" ) && false !== strpos( $con, 'registre + %d société(s), intégrité' ), 'R-1 vérification : « registre + N société(s) »' );
verifie( false !== strpos( $con, 'registre + %d société(s), %d lignes' ), 'R-1 sauvegarde : « registre + N société(s) »' );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
