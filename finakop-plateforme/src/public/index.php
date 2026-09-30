<?php
/**
 * FinaKop Plateforme — contrôleur frontal UNIQUE de tous les espaces clients.
 *
 * Ce fichier est copié dans le dossier web des sous-domaines par
 * scripts/deployer.sh, qui y inscrit l'emplacement du code et de la
 * configuration (tous deux HORS de la racine web). Ne le modifiez pas à la main.
 */
define( 'FINAKOP_RACINE', '__FINAKOP_RACINE__' );
define( 'FINAKOP_CONFIG', '__FINAKOP_CONFIG__' );

$fkcRacine = 0 === strpos( FINAKOP_RACINE, '__' ) ? dirname( __DIR__ ) : FINAKOP_RACINE; // copie non déployée : développement
require $fkcRacine . '/app/Plateforme/Amorcage.php';
FKC_Plateforme_Amorcage::web();
