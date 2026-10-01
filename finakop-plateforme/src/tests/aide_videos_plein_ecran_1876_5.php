<?php
/**
 * 1.876.5 — lecteur des vidéos d'aide : bouton de lecture masqué pendant la lecture, plein écran.
 *
 *   V-1  Chaque vidéo a un bouton de lecture et un bouton plein écran rattachés à son lecteur.
 *   V-2  Le bouton de lecture se masque à la lecture et réapparaît à la fin ; [hidden] l'emporte sur le style.
 *   V-3  Plein écran : API standard, préfixe WebKit et repli iPhone (webkitEnterFullscreen).
 *   V-4  Les en-têtes de sécurité n'interdisent pas le plein écran.
 *
 * Usage : php tests/aide_videos_plein_ecran_1876_5.php
 */
$racine = dirname( __DIR__ );
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }
$vue = (string) file_get_contents( $racine . '/app/Modules/Aide/Views/videos.php' );
$css = (string) file_get_contents( $racine . '/app/assets/css/app.css' );
$sec = (string) file_get_contents( $racine . '/app/Core/Security.php' );
verifie( false !== strpos( $vue, 'class="ah-vlire" data-video="vid-<?= e( $v[\'id\'] ) ?>"' ) && false !== strpos( $vue, 'class="ah-vplein" data-video="vid-<?= e( $v[\'id\'] ) ?>"' ), 'V-1 boutons lecture et plein écran par vidéo' );
verifie( false !== strpos( $vue, "addEventListener('play', function () { lire.hidden = true; })" ) && false !== strpos( $vue, "addEventListener('ended', function () { lire.hidden = false; })" ), 'V-2 bouton de lecture masqué pendant la lecture, rétabli à la fin' );
verifie( false !== strpos( $css, '.ah-vlire[hidden]{display:none}' ), 'V-2 [hidden] masque bien le bouton de lecture' );
verifie( false !== strpos( $vue, 'requestFullscreen' ) && false !== strpos( $vue, 'webkitRequestFullscreen' ) && false !== strpos( $vue, 'webkitEnterFullscreen' ), 'V-3 plein écran standard, WebKit et iPhone' );
verifie( false !== strpos( $css, '.ah-vplayer:fullscreen' ), 'V-3 lecteur adapté en plein écran' );
verifie( false === stripos( $sec, 'fullscreen=()' ), 'V-4 Permissions-Policy n\'interdit pas le plein écran' );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
