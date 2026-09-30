<?php
/**
 * FinaKop ERP Core — point d'entrée web AUTONOME.
 *
 * Seul fichier PHP de la racine web. nginx y envoie toute requête qui n'est
 * pas un fichier statique de /_fkc/ (voir deploiement/nginx/finakop.conf).
 */
require dirname( __DIR__ ) . '/lib/amorcage.php';
fka_definir_constantes();

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fka_fatal( 'Extension pdo_sqlite manquante.' ); }

// Comme l'extension : aucune sortie parasite avant l'application.
while ( ob_get_level() ) { ob_end_clean(); }

require FKC_ROOT . 'index.php';
