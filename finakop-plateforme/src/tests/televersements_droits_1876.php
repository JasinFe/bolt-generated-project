<?php
/**
 * TÉLÉVERSEMENTS (1.876.0) — fichiers des clients illisibles des autres comptes.
 *
 * GED, pièces comptables, logos et pièces du cabinet étaient forcés en 0644
 * (lisibles par tout compte du serveur) ; Connect et le terminal étaient déjà
 * en 0640. Sur un hébergement mutualisé, on aligne tout sur 0640 : les
 * fichiers ne sont jamais servis directement, toujours par PHP, sous le
 * compte propriétaire.
 *
 * Usage : php tests/televersements_droits_1876.php
 */
$racine = dirname( __DIR__ ) . '/app/';
$n = 0; $fail = 0;
function verifie( $ok, $desc ) { global $n, $fail; $n++; if ( $ok ) { echo "  \033[32m✓\033[0m $desc\n"; } else { $fail++; echo "  \033[31m✗ $desc\033[0m\n"; } }
$larges = array(); $serres = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( 'php' !== $f->getExtension() ) { continue; }
	$s = (string) file_get_contents( $f->getPathname() );
	if ( false === strpos( $s, 'move_uploaded_file' ) ) { continue; }
	if ( preg_match_all( '/chmod\(\s*\$dest\s*,\s*(0[0-7]{3})\s*\)/', $s, $m ) ) {
		foreach ( $m[1] as $mode ) { if ( '0640' === $mode ) { $serres++; } else { $larges[] = substr( $f->getPathname(), strlen( $racine ) ) . " ({$mode})"; } }
	}
}
verifie( $serres >= 7, "fichiers téléversés en 0640 ({$serres} emplacements)" );
verifie( ! $larges, 'aucun fichier téléversé lisible par les autres comptes' . ( $larges ? ' — ' . implode( ', ', $larges ) : '' ) );
echo "\n" . ( $fail ? "\033[31m$fail échec(s) sur $n\033[0m\n" : "\033[32m$n vérifications réussies\033[0m\n" );
exit( $fail ? 1 : 0 );
