<?php
/**
 * FKC_Plateforme_Console — commandes d'exploitation (bin/finakop) (1.876.0).
 *
 * Remplace la page de réglages WordPress, WP-Cron et WP-CLI. Deux familles :
 *
 *   • commandes PLATEFORME (aucun client chargé) : clients, sauvegardes,
 *     diagnostic, cron ;
 *   • commandes CLIENT (--tenant=<slug>) : un seul client par processus, parce
 *     que FinaKop repose sur des constantes (FKC_DATA_DIR…) fixées une fois.
 *     Le cron lance donc un sous-processus par client.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

require_once __DIR__ . '/Instantane.php';
require_once __DIR__ . '/Sauvegarde.php';

class FKC_Plateforme_Console {

	protected static $opts = array();
	protected static $args = array();

	public static function executer( array $argv ) {
		array_shift( $argv );
		$tenant = null;
		foreach ( $argv as $a ) {
			if ( preg_match( '/^--([a-z-]+)(?:=(.*))?$/', $a, $m ) ) { self::$opts[ $m[1] ] = $m[2] ?? true; }
			else { self::$args[] = $a; }
		}
		if ( isset( self::$opts['tenant'] ) ) { $tenant = (string) self::$opts['tenant']; }
		$cmd = self::$args ? array_shift( self::$args ) : 'aide';

		try {
			FKC_Plateforme_Amorcage::socle();
		} catch ( \Throwable $e ) {
			if ( ! in_array( $cmd, array( 'aide', 'help' ), true ) ) { self::fin( 1, 'Configuration : ' . $e->getMessage() ); }
		}
		try {
			if ( null !== $tenant ) { return self::commandeClient( $tenant, $cmd ); }
			return self::commandePlateforme( $cmd );
		} catch ( \Throwable $e ) {
			self::fin( 1, $e->getMessage() );
		}
	}

	/* ══════════════════════ Commandes plateforme ══════════════════════ */

	protected static function commandePlateforme( $cmd ) {
		switch ( $cmd ) {
			case 'aide': case 'help': return self::aide();

			case 'tenant:creer':
				list( $slug, $nom ) = self::args( 2, 'tenant:creer <identifiant> "<Nom de l\'entreprise>" [--contact=courriel]' );
				return self::creer( strtolower( $slug ), $nom );

			case 'tenant:lister':
				$l = FKC_Plateforme_Registre::tous( isset( self::$opts['statut'] ) ? (string) self::$opts['statut'] : null );
				if ( isset( self::$opts['slugs'] ) ) { foreach ( $l as $t ) { echo $t['slug'], "\n"; } return 0; }
				printf( "%-24s %-12s %-34s %s\n", 'IDENTIFIANT', 'STATUT', 'NOM', 'ADRESSE' );
				foreach ( $l as $t ) { printf( "%-24s %-12s %-34s %s\n", $t['slug'], $t['statut'], mb_substr( $t['nom'], 0, 34 ), FKC_Plateforme_Amorcage::adresseClient( $t )['url'] ); }
				echo count( $l ), " client(s)\n";
				return 0;

			case 'tenant:suspendre': case 'tenant:activer': case 'tenant:maintenance': case 'tenant:archiver':
				list( $slug ) = self::args( 1, $cmd . ' <identifiant>' );
				$statut = array( 'tenant:suspendre' => 'suspendu', 'tenant:activer' => 'actif', 'tenant:maintenance' => 'maintenance', 'tenant:archiver' => 'archive' )[ $cmd ];
				FKC_Plateforme_Registre::changerStatut( $slug, $statut );
				FKC_Plateforme_Registre::journaliser( 'statut', $statut, (int) self::client( $slug )['id'] );
				echo "Client {$slug} : {$statut}.\n";
				return 0;

			case 'tenant:domaine':
				list( $slug, $domaine ) = self::args( 2, 'tenant:domaine <identifiant> <domaine personnalisé> [--retirer]' );
				$t = self::client( $slug );
				$d = FKC_TenantResolver::normaliserHote( $domaine );
				if ( '' === $d ) { self::fin( 1, 'Domaine invalide.' ); }
				if ( isset( self::$opts['retirer'] ) ) { FKC_Plateforme_Registre::q( 'DELETE FROM tenant_domaines WHERE domaine=? AND tenant_id=?', array( $d, $t['id'] ) ); echo "Domaine {$d} retiré.\n"; return 0; }
				FKC_Plateforme_Registre::q( 'INSERT INTO tenant_domaines(domaine,tenant_id) VALUES(?,?)', array( $d, $t['id'] ) );
				FKC_Plateforme_Registre::journaliser( 'domaine', $d, (int) $t['id'] );
				echo "Domaine {$d} → {$slug}. Déclarez-le aussi chez l'hébergeur et dans Cloudflare, puis réémettez la licence pour ce domaine.\n";
				return 0;

			case 'tenant:importer':
				list( $slug, $dossier ) = self::args( 2, 'tenant:importer <identifiant> <dossier d\'export> [--nom="Nom"]' );
				return self::importer( strtolower( $slug ), $dossier );

			case 'tenant:sauvegarder':
				$cibles = isset( self::$opts['tous'] ) ? FKC_Plateforme_Registre::tous( 'actif' ) : array( self::client( self::args( 1, 'tenant:sauvegarder <identifiant>|--tous' )[0] ) );
				$code = 0;
				foreach ( $cibles as $t ) {
					try {
						$r = FKC_Plateforme_Sauvegarde::creer( $t );
						printf( "%-20s OK  %s (%s, %d base(s), %d lignes, %d fichier(s))\n", $t['slug'], basename( $r['fichier'] ), self::taille( $r['octets'] ), $r['controle']['bases'], $r['controle']['lignes'], $r['controle']['fichiers'] );
					} catch ( \Throwable $e ) { $code = 2; printf( "%-20s ÉCHEC %s\n", $t['slug'], $e->getMessage() ); }
				}
				return $code;

			case 'sauvegardes:lister':
				$t = self::client( self::args( 1, 'sauvegardes:lister <identifiant>' )[0] );
				foreach ( FKC_Plateforme_Sauvegarde::lister( $t ) as $f ) { printf( "%s  %10s  %s\n", gmdate( 'Y-m-d H:i', filemtime( $f ) ), self::taille( filesize( $f ) ), $f ); }
				return 0;

			case 'tenant:restaurer':
				list( $slug, $archive ) = self::args( 2, 'tenant:restaurer <identifiant> <archive> [--vers=<nouvel identifiant>] [--confirmer]' );
				$vers = isset( self::$opts['vers'] ) ? strtolower( (string) self::$opts['vers'] ) : null;
				if ( ! $vers && ! isset( self::$opts['confirmer'] ) ) {
					self::fin( 1, "Restaurer {$slug} remplace ses données actuelles (elles sont mises de côté, pas effacées).\nAjoutez --confirmer, ou restaurez d'abord vers un client de vérification : --vers={$slug}-verif" );
				}
				if ( $vers ) { self::client( $slug ); }
				$r = FKC_Plateforme_Sauvegarde::restaurer( $archive, $vers ?: $slug, (bool) $vers );
				printf( "Restauré dans %s : %d base(s), %d lignes, %d fichier(s) — contrôle conforme.\n", $r['client']['slug'], $r['controle']['bases'], $r['controle']['lignes'], $r['controle']['fichiers'] );
				if ( $r['mis_de_cote'] ) { echo "Ancien état conservé : {$r['mis_de_cote']}\n"; }
				if ( $vers ) { echo "Client de vérification : " . FKC_Plateforme_Amorcage::adresseClient( $r['client'] )['url'] . " (créez le sous-domaine chez l'hébergeur pour l'ouvrir ; archivez-le ensuite).\n"; }
				return 0;

			case 'plateforme:sauvegarder':
				$r = FKC_Plateforme_Sauvegarde::plateforme();
				printf( "Plateforme OK  %s (%s, registre + configuration) — relue et contrôlée.\n", $r['fichier'], self::taille( $r['octets'] ) );
				return 0;

			case 'plateforme:restaurer':
				list( $archive, $dossier ) = self::args( 2, 'plateforme:restaurer <archive> <dossier vide>' );
				$r = FKC_Plateforme_Sauvegarde::restaurerPlateforme( $archive, $dossier );
				printf( "Archive conforme, extraite dans %s : plateforme.db (intégrité ok) et config.php.\n", $dossier );
				echo "Remise en place (voir docs/06-EXPLOITATION.md §5) :\n"
					. "  cp {$dossier}/plateforme.db " . FKC_Config::dossier( 'plateforme/plateforme.db' ) . "\n"
					. "  cp {$dossier}/config.php " . FKC_Config::fichier() . "   (si la configuration est perdue)\n";
				return 0;

			case 'sauvegarde:cle':
				echo base64_encode( random_bytes( 32 ) ), "\n";
				fwrite( STDERR, "Copiez cette valeur dans config.php (sauvegarde.cle) ET dans un coffre hors du serveur : sans elle, aucune sauvegarde chiffrée ne se restaure.\n" );
				return 0;

			case 'cron':
				require_once __DIR__ . '/Cron.php';
				return FKC_Plateforme_Cron::executer( self::$opts );

			case 'plateforme:verifier':
				return self::verifierPlateforme();

			case 'cloudflare:plages':
				$p = array();
				foreach ( array( 'https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6' ) as $u ) {
					$r = @file_get_contents( $u, false, stream_context_create( array( 'http' => array( 'timeout' => 10 ) ) ) );
					if ( false === $r ) { self::fin( 1, "Téléchargement impossible : {$u}" ); }
					foreach ( preg_split( '/\s+/', trim( $r ) ) as $c ) { if ( preg_match( '#^[0-9a-f:.]+/\d{1,3}$#i', $c ) ) { $p[] = $c; } }
				}
				if ( count( $p ) < 10 ) { self::fin( 1, 'Liste reçue incomplète : rien n\'est modifié.' ); }
				file_put_contents( FKC_Config::dossier( 'plateforme/cloudflare-ips.json' ), json_encode( $p ) );
				echo count( $p ), " plages Cloudflare enregistrées.\n";
				return 0;

			case 'config:set':
				list( $cle, $val ) = self::args( 2, 'config:set <clé.à.points> <valeur>' );
				FKC_Config::set( $cle, in_array( $val, array( 'true', 'false' ), true ) ? 'true' === $val : $val );
				echo "{$cle} mis à jour.\n";
				return 0;
		}
		self::fin( 1, "Commande inconnue : {$cmd}. « finakop aide » liste les commandes." );
	}

	/* ══════════════════════ Commandes d'un client ══════════════════════ */

	protected static function commandeClient( $slug, $cmd ) {
		$t = self::client( $slug );
		self::chargerClient( $t );
		switch ( $cmd ) {
			case 'bpe':
				$l = self::verrou( $t, 'bpe' );
				$r = FKC_BPEWorker::traiterToutesSocietes( max( 1, (int) ( self::$opts['max'] ?? 25 ) ) );
				foreach ( (array) $r['erreurs'] as $m ) { fwrite( STDERR, "! {$m}\n" ); }
				printf( "%d société(s) — %d tâche(s) : %d réussie(s), %d en échec.\n", $r['societes'], $r['traitees'], $r['succes'], $r['echecs'] );
				return $r['erreurs'] ? 2 : 0;

			case 'balayage':
				$l = self::verrou( $t, 'balayage' );
				$r = FKC_Balayeur::balayerTout( isset( self::$opts['forcer'] ) );
				printf( "%d société(s) — %d publiée(s), %d refusée(s), %d retenue(s).\n", $r['societes'], $r['publiees'], $r['refusees'], $r['retenues'] );
				return $r['erreurs'] ? 2 : 0;

			case 'cron:client':
				require_once __DIR__ . '/Cron.php';
				return FKC_Plateforme_Cron::client( $t, self::$opts );

			case 'licence':
				$s = FKC_License::resolve();
				printf( "Statut   : %s%s\nClient   : %s\nPalier   : %s\nPacks    : %s\nExpire   : %s\nDomaine  : %s (contrôle %s)\nMessage  : %s\n",
					$s['status'], $s['valid'] ? ' (valide)' : '', $s['client'] ?? '—', $s['profile_label'] ?? ( $s['profile'] ?? '—' ),
					implode( ', ', (array) ( $s['packs'] ?? array() ) ) ?: '—', $s['expires_at'] ?? '—', $_SERVER['HTTP_HOST'],
					defined( 'FKC_LICENSE_STRICT_DOMAIN' ) && FKC_LICENSE_STRICT_DOMAIN ? 'strict' : 'historique', $s['message'] ?? '' );
				return $s['valid'] ? 0 : 3;

			case 'licence:installer':
				list( $jeton ) = self::args( 1, '--tenant=<id> licence:installer <jeton>' );
				$r = FKC_License::install( trim( $jeton ) );
				$ok = is_array( $r ) ? ! empty( $r[0] ) : (bool) $r;
				FKC_License::reresolve();
				$s = FKC_License::resolve();
				FKC_Plateforme_Registre::journaliser( 'licence', ( $s['status'] ?? '?' ) . ' ' . ( $s['profile'] ?? '' ), (int) $t['id'] );
				printf( "Jeton %s — statut : %s (%s)\n", $ok ? 'installé' : 'REFUSÉ', $s['status'], $s['message'] ?? '' );
				return $ok && ! empty( $s['valid'] ) ? 0 : 3;

			case 'comptes-compromis':
				foreach ( FKC_Master::comptesCompromis() as $u ) { printf( "%-20s %s\n", $u['login'], $u['nom_complet'] ); }
				return 0;

			case 'reemettre-mdp':
				list( $login ) = self::args( 1, '--tenant=<id> reemettre-mdp <identifiant>' );
				$mdp = FKC_Master::reemettreMotDePasse( $login );
				if ( null === $mdp ) { self::fin( 1, "Compte inconnu : {$login}" ); }
				@unlink( FKC_DATA_DIR . '.fkc-mot-de-passe-initial.txt' );
				FKC_Plateforme_Registre::journaliser( 'mdp_reemis', $login, (int) $t['id'] );
				echo "Mot de passe à usage unique pour {$login} : {$mdp}\n(changement imposé à la première connexion)\n";
				return 0;

			case 'mail:test':
				list( $a ) = self::args( 1, '--tenant=<id> mail:test <adresse>' );
				list( $ok, $msg ) = FKC_Courriel::envoyer( $a, 'FinaKop — test d\'envoi', "Ce message confirme que l'envoi de courriels de l'espace « {$t['nom']} » fonctionne.", null, 'test:console' );
				echo ( $ok ? 'OK : ' : 'ÉCHEC : ' ), $msg, "\n";
				return $ok ? 0 : 2;

			case 'verifier':
				return self::verifierClient( $t );
		}
		self::fin( 1, "Commande client inconnue : {$cmd}." );
	}

	/** Charge FinaKop pour UN client dans ce processus. */
	public static function chargerClient( array $t, $creation = false ) {
		$a = FKC_Plateforme_Amorcage::adresseClient( $t );
		$_SERVER['HTTP_HOST'] = $a['hote'];  // le contrôle de domaine de la licence lit l'hôte
		$_SERVER['HTTPS'] = 'on';
		$_SERVER['REQUEST_URI'] = $a['base_path'] . '/';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		FKC_Plateforme_Amorcage::contexte( $t, $a['hote'], $a['base_path'], 'https', $creation );
		if ( $creation ) { define( 'FKC_NOYAU_CREER', true ); }
		if ( true !== ( require FKC_ROOT . 'noyau.php' ) ) { throw new \RuntimeException( 'Chargement de FinaKop impossible pour ' . $t['slug'] ); }
	}

	/* ══════════════════════ Création et import ══════════════════════ */

	protected static function creer( $slug, $nom ) {
		$t = FKC_Plateforme_Registre::ajouter( $slug, $nom, isset( self::$opts['contact'] ) ? (string) self::$opts['contact'] : null );
		$dir = FKC_Plateforme_Registre::dossierDonnees( $t );
		try {
			if ( is_dir( $dir ) && ( new \FilesystemIterator( $dir ) )->valid() ) { throw new \RuntimeException( "Le dossier {$dir} existe déjà et n'est pas vide." ); }
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) ) { throw new \RuntimeException( "Création impossible : {$dir}" ); }
			self::chargerClient( $t, true );
			// Le registre vient d'être amorcé (première société, compte « admin ») : on y inscrit le nom du client.
			FKC_Master::q( "UPDATE societes SET raison_sociale=? WHERE id=(SELECT MIN(id) FROM societes)", array( $nom ) );
			FKC_Crypto::key();                          // clé propre au client + empreinte
			FKC_Tenant::bindById( (int) FKC_Master::q( 'SELECT MIN(id) FROM societes' )->fetchColumn() ); // base de la société : migrations maintenant, pas au premier clic
			$f = FKC_DATA_DIR . '.fkc-mot-de-passe-initial.txt';
			$mdp = '';
			if ( is_file( $f ) && preg_match( '/Mot de passe : (\S+)/', (string) file_get_contents( $f ), $m ) ) { $mdp = $m[1]; }
			@unlink( $f );
		} catch ( \Throwable $e ) {
			FKC_Plateforme_Registre::q( 'DELETE FROM tenants WHERE id=?', array( $t['id'] ) );
			throw $e;
		}
		FKC_Plateforme_Registre::journaliser( 'creation', $nom, (int) $t['id'] );
		$a = FKC_Plateforme_Amorcage::adresseClient( $t );
		echo "Client créé : {$nom}\n";
		echo "  Adresse          : {$a['url']}\n";
		echo "  Identifiant      : admin\n";
		echo "  Mot de passe     : {$mdp}   (à usage unique, changement imposé à la première connexion)\n";
		echo "  Données          : {$dir}\n\n";
		echo "Reste à faire :\n";
		echo '' === $a['base_path']
			? "  1. hPanel → Sous-domaines : créer « {$slug} » vers le dossier web FinaKop (voir docs/04-INSTALLATION-HOSTINGER.md).\n"
			: "  1. Mode chemin : rien à créer chez l'hébergeur.\n";
		echo "  2. Émettre sa licence (domaine : {$a['hote']}) puis : finakop --tenant={$slug} licence:installer <jeton>\n";
		return 0;
	}

	protected static function importer( $slug, $dossier ) {
		$dossier = rtrim( $dossier, '/' );
		$donnees = is_dir( $dossier . '/data' ) ? $dossier . '/data' : $dossier;
		$mf = is_file( $dossier . '/MANIFESTE.json' ) ? $dossier . '/MANIFESTE.json' : $donnees . '/MANIFESTE.json';
		$m = json_decode( (string) @file_get_contents( $mf ), true );
		if ( ! is_array( $m ) ) { self::fin( 1, 'MANIFESTE.json introuvable ou illisible dans ' . $dossier ); }
		$ctl = FKC_Plateforme_Instantane::controler( $donnees, $m );
		if ( ! $ctl['ok'] ) { self::fin( 1, "Export non conforme — rien n'est importé :\n  " . implode( "\n  ", $ctl['anomalies'] ) ); }
		echo "Export conforme : {$ctl['bases']} base(s), {$ctl['lignes']} lignes, {$ctl['fichiers']} fichier(s).\n";
		if ( empty( $m['cle_fichier'] ) && empty( $m['cle_constante'] ) ) {
			echo "⚠ L'export ne contient pas la clé de chiffrement (.fkc-secret.key, ni FKC_ENCRYPTION_KEY relevée dans wp-config.php).\n"
				. "  Refaites l'export en donnant le chemin de wp-config.php : migration-donnees.php exporter <données> <sortie> <wp-config.php>.\n"
				. "  Sans clé, les secrets chiffrés (clés d'API, Mobile Money…) seraient illisibles ; FinaKop refuse alors de démarrer.\n";
			if ( ! isset( self::$opts['forcer'] ) ) { self::fin( 1, 'Import interrompu (--forcer pour passer outre).' ); }
		}
		$existant = FKC_Plateforme_Registre::parSlug( $slug );
		$miseDeCote = null;
		if ( $existant && ! isset( self::$opts['remplacer'] ) ) {
			self::fin( 1, "L'espace « {$slug} » existe déjà. Pour remplacer son contenu par cet export (le contenu actuel est conservé à part, pas effacé) : ajoutez --remplacer." );
		}
		if ( $existant ) {
			// Remplacement d'un espace existant (ex. créé vide avant la reprise).
			$t = $existant;
			$statutAvant = $t['statut'];
			FKC_Plateforme_Registre::changerStatut( $slug, 'maintenance' );
			$dest = FKC_Plateforme_Registre::dossierDonnees( $t );
			$miseDeCote = rtrim( $dest, '/' ) . '.avant-import-' . gmdate( 'Ymd-His' );
			if ( is_dir( $dest ) && ! @rename( rtrim( $dest, '/' ), $miseDeCote ) ) {
				FKC_Plateforme_Registre::changerStatut( $slug, $statutAvant );
				self::fin( 1, "Impossible de mettre de côté {$dest}." );
			}
			try {
				FKC_Plateforme_Instantane::copier( $donnees, $dest );
				@unlink( $dest . 'MANIFESTE.json' );
				@unlink( $dest . 'A-REPORTER-DANS-config.php.txt' );
				$ctl2 = FKC_Plateforme_Instantane::controler( $dest, $m );
				if ( ! $ctl2['ok'] ) { throw new \RuntimeException( "Contrôle à l'arrivée non conforme :\n  " . implode( "\n  ", $ctl2['anomalies'] ) ); }
			} catch ( \Throwable $e ) {
				// Retour exact à l'état d'avant : rien n'est perdu.
				FKC_Plateforme_Instantane::effacer( $dest );
				if ( is_dir( $miseDeCote ) ) { @rename( $miseDeCote, rtrim( $dest, '/' ) ); }
				FKC_Plateforme_Registre::changerStatut( $slug, $statutAvant );
				throw $e;
			}
			// Les sessions ouvertes sur l'ancien contenu n'ont plus de sens.
			foreach ( glob( FKC_Plateforme_Registre::dossierSessions( $t ) . 'sess_*' ) ?: array() as $f ) { @unlink( $f ); }
			if ( isset( self::$opts['nom'] ) && '' !== trim( (string) self::$opts['nom'] ) ) {
				FKC_Plateforme_Registre::q( 'UPDATE tenants SET nom=? WHERE id=?', array( trim( (string) self::$opts['nom'] ), (int) $t['id'] ) );
			}
		} else {
		$t = FKC_Plateforme_Registre::ajouter( $slug, isset( self::$opts['nom'] ) ? (string) self::$opts['nom'] : $slug );
		FKC_Plateforme_Registre::changerStatut( $slug, 'maintenance' );
		$dest = FKC_Plateforme_Registre::dossierDonnees( $t );
		try {
			if ( is_dir( $dest ) && ( new \FilesystemIterator( $dest ) )->valid() ) { throw new \RuntimeException( "Le dossier {$dest} n'est pas vide." ); }
			FKC_Plateforme_Instantane::copier( $donnees, $dest );
			@unlink( $dest . 'MANIFESTE.json' );
			@unlink( $dest . 'A-REPORTER-DANS-config.php.txt' );
			$ctl2 = FKC_Plateforme_Instantane::controler( $dest, $m );
			if ( ! $ctl2['ok'] ) { throw new \RuntimeException( "Contrôle à l'arrivée non conforme :\n  " . implode( "\n  ", $ctl2['anomalies'] ) ); }
		} catch ( \Throwable $e ) {
			FKC_Plateforme_Registre::q( 'DELETE FROM tenants WHERE id=?', array( $t['id'] ) );
			throw $e;
		}
		}
		FKC_Plateforme_Registre::changerStatut( $slug, 'actif' );
		FKC_Plateforme_Registre::journaliser( 'import', basename( $dossier ) . " {$ctl2['lignes']} lignes", (int) $t['id'] );
		$decal = (int) ( $m['decalage_sqlite_s'] ?? 0 );
		$a = FKC_Plateforme_Amorcage::adresseClient( $t );
		echo "Importé et contrôlé à l'arrivée : {$a['url']}\n";
		if ( $miseDeCote ) { echo "Ancien contenu de l'espace conservé : {$miseDeCote}\n"; }
		if ( 0 !== $decal ) { printf( "⚠ L'ancien serveur avait un décalage horaire SQLite de %+d h : réglez « fuseau » en conséquence si les horodatages doivent rester alignés.\n", $decal / 3600 ); }
		echo "Licence : l'ancien jeton est lié à l'ancien domaine. Émettez-en un pour {$a['hote']} puis : finakop --tenant={$slug} licence:installer <jeton>\n";
		return 0;
	}

	/* ══════════════════════ Diagnostics ══════════════════════ */

	protected static function verifierPlateforme() {
		$ok = true;
		$l = function ( $b, $t ) use ( &$ok ) { if ( ! $b ) { $ok = false; } echo ( $b ? '  [OK]  ' : '  [!!]  ' ), $t, "\n"; };
		echo 'FinaKop Plateforme ', FKC_Plateforme_Amorcage::version(), " — PHP ", PHP_VERSION, "\n\n";
		$l( version_compare( PHP_VERSION, '8.1', '>=' ), 'PHP 8.1 ou plus (8.4 recommandé)' );
		foreach ( array( 'pdo_sqlite', 'openssl', 'json', 'sodium', 'mbstring', 'phar', 'zlib' ) as $x ) { $l( extension_loaded( $x ), "extension {$x}" ); }
		$l( function_exists( 'proc_open' ), 'proc_open disponible (cron : un processus par client)' );
		$l( is_file( FKC_Config::fichier() ) && 0 === ( fileperms( FKC_Config::fichier() ) & 0077 ), 'config.php lisible par vous seul (0600)' );
		$d = FKC_Config::get( 'donnees' );
		$l( is_dir( $d ) && is_writable( $d ), "dossier de données inscriptible : {$d}" );
		// Dossier web mémorisé par scripts/deployer.sh (~/finakop/.dossier-web).
		$fw = dirname( realpath( FKC_Plateforme_Amorcage::racine() ) ?: FKC_Plateforme_Amorcage::racine(), 2 ) . '/.dossier-web';
		$web = is_file( $fw ) ? realpath( trim( (string) file_get_contents( $fw ) ) ) : false;
		if ( $web && realpath( $d ) ) {
			$l( 0 !== strpos( realpath( $d ) . '/', $web . '/' ) && 0 !== strpos( $web . '/', realpath( $d ) . '/' ), "dossier de données hors du dossier web ({$web})" );
		}
		$l( '' !== (string) FKC_Config::get( 'sauvegarde.cle' ), 'sauvegardes chiffrées (sauvegarde.cle)' );
		$l( class_exists( 'FKC_Mailer' ) || true, 'service de courriel présent' );
		$l( '' !== (string) FKC_Config::get( 'smtp.hote' ) || 'smtp' !== FKC_Config::get( 'smtp.transport' ), 'SMTP configuré' );
		$l( ! FKC_Config::get( 'cloudflare.actif' ) || '' !== (string) FKC_Config::get( 'cloudflare.secret_origine' ), 'Cloudflare : garde de l\'origine (secret) configurée' );
		$ts = FKC_Plateforme_Registre::tous();
		echo "\n", count( $ts ), " client(s) :\n";
		foreach ( $ts as $t ) {
			$dir = FKC_Plateforme_Registre::dossierDonnees( $t );
			$b = glob( $dir . '*.db' ) ?: array();
			$ic = 'ok';
			foreach ( $b as $f ) {
				try { $r = ( new \PDO( 'sqlite:' . $f ) )->query( 'PRAGMA quick_check' )->fetchColumn(); if ( 'ok' !== $r ) { $ic = basename( $f ) . ' : ' . $r; } } catch ( \Throwable $e ) { $ic = $e->getMessage(); }
			}
			$sv = FKC_Plateforme_Sauvegarde::lister( $t );
			$l( 'ok' === $ic && is_file( $dir . 'finakopcore-master.db' ), sprintf( '%-20s %-11s %d base(s), intégrité %s, dernière sauvegarde : %s', $t['slug'], $t['statut'], count( $b ), $ic, $sv ? gmdate( 'Y-m-d H:i', filemtime( $sv[0] ) ) : 'AUCUNE' ) );
		}
		echo "\n", $ok ? "Tout est en ordre.\n" : "Des points demandent une action.\n";
		return $ok ? 0 : 4;
	}

	protected static function verifierClient( array $t ) {
		$ok = true;
		$l = function ( $b, $x ) use ( &$ok ) { if ( ! $b ) { $ok = false; } echo ( $b ? '  [OK]  ' : '  [!!]  ' ), $x, "\n"; };
		echo "Client {$t['slug']} — {$t['nom']}\n";
		$l( is_file( FKC_DATA_DIR . '.fkc-secret.key' ) || defined( 'FKC_ENCRYPTION_KEY' ), 'clé de chiffrement présente (source : ' . FKC_Crypto::keySource() . ')' );
		foreach ( FKC_Master::q( 'SELECT id, raison_sociale, db_file, actif FROM societes ORDER BY id' )->fetchAll() as $s ) {
			$f = FKC_DATA_DIR . $s['db_file'];
			$ic = is_file( $f ) ? (string) ( new \PDO( 'sqlite:' . $f ) )->query( 'PRAGMA integrity_check' )->fetchColumn() : 'fichier absent';
			$l( 'ok' === $ic, sprintf( 'société #%d %s (%s) : %s', $s['id'], $s['raison_sociale'], $s['db_file'], $ic ) );
		}
		$s = FKC_License::resolve();
		$l( ! empty( $s['valid'] ), 'licence : ' . $s['status'] . ' — ' . ( $s['message'] ?? '' ) );
		echo $ok ? "Conforme.\n" : "Des points demandent une action.\n";
		return $ok ? 0 : 4;
	}

	/* ══════════════════════ Outils ══════════════════════ */

	protected static function client( $slug ) {
		$t = FKC_Plateforme_Registre::parSlug( strtolower( (string) $slug ) );
		if ( ! $t ) { self::fin( 1, "Client inconnu : {$slug}" ); }
		return $t;
	}

	protected static function args( $n, $usage ) {
		if ( count( self::$args ) < $n ) { self::fin( 1, "Usage : finakop {$usage}" ); }
		return self::$args;
	}

	/** Verrou par client et par tâche : deux passages ne se chevauchent jamais. */
	public static function verrou( array $t, $tache ) {
		$d = FKC_Config::dossier( 'plateforme/verrous' );
		if ( ! is_dir( $d ) ) { @mkdir( $d, 0750, true ); }
		$f = fopen( $d . '/' . $t['dossier'] . '-' . $tache . '.lock', 'c' );
		if ( ! $f || ! flock( $f, LOCK_EX | LOCK_NB ) ) { fwrite( STDERR, "Déjà en cours : {$t['slug']} {$tache}\n" ); exit( 0 ); }
		return $f;
	}

	protected static function taille( $o ) { return $o >= 1048576 ? round( $o / 1048576, 1 ) . ' Mo' : round( $o / 1024 ) . ' Ko'; }

	protected static function fin( $code, $msg ) {
		fwrite( STDERR, $msg . "\n" );
		exit( $code );
	}

	protected static function aide() {
		echo <<<TXT
FinaKop Plateforme — commandes

  Clients
    tenant:creer <id> "<Nom>" [--contact=courriel]   Crée un espace (id = sous-domaine)
    tenant:lister [--statut=actif] [--slugs]          Liste les clients
    tenant:suspendre|activer|maintenance|archiver <id>
    tenant:domaine <id> <domaine> [--retirer]        Domaine personnalisé
    tenant:importer <id> <dossier d'export> [--nom="Nom"] [--remplacer]
                                                     Reprise depuis l'extension WordPress

  Sauvegardes
    tenant:sauvegarder <id> | --tous                 Sauvegarde chiffrée, relue et contrôlée
    sauvegardes:lister <id>
    tenant:restaurer <id> <archive> --vers=<id-verif>   Restauration de vérification (sans risque)
    tenant:restaurer <id> <archive> --confirmer         Restauration en place (ancien état conservé)
    plateforme:sauvegarder                           Registre des clients + configuration (chiffrés)
    plateforme:restaurer <archive> <dossier vide>    Extrait et contrôle, sans rien remplacer
    sauvegarde:cle                                   Génère une clé de chiffrement des sauvegardes

  Exploitation
    cron                                             Tâche cron unique (tous les clients)
    plateforme:verifier                              Diagnostic complet
    cloudflare:plages                                Met à jour les plages IP Cloudflare
    config:set <clé> <valeur>

  Un client (--tenant=<id>)
    bpe | balayage | licence | licence:installer <jeton> | verifier
    comptes-compromis | reemettre-mdp <login> | mail:test <adresse>

TXT;
		return 0;
	}
}
