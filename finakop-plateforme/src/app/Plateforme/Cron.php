<?php
/**
 * FKC_Plateforme_Cron — la SEULE tâche cron de la plateforme (1.876.0).
 *
 * Remplace WP-Cron (qui ne partait qu'aux visites, et dont les crochets ne
 * trouvaient pas les classes de FinaKop). À déclarer toutes les 5 minutes :
 *
 *     php ~/finakop/current/cron/worker.php
 *
 * Garanties :
 *   • un seul passage à la fois (verrou global, non bloquant) ;
 *   • un sous-processus PAR CLIENT : ses constantes et ses bases ne se mélangent
 *     jamais avec celles d'un autre ; un client en erreur n'arrête pas les autres ;
 *   • délai maximal par client (arrêt forcé au-delà), budget total par passage ;
 *     les clients les moins récemment traités passent en premier : aucun n'est
 *     oublié quand le budget est court ;
 *   • état de chaque tâche dans le registre (début, fin, durée, échecs successifs) ;
 *   • journal avec rotation.
 *
 * Par client (cron:client) : file différée (BPE), temporisation comptable,
 * purge des sessions expirées, sauvegarde quotidienne.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_Plateforme_Cron {

	public static function log( $m ) { FKC_Plateforme_Amorcage::journal( 'cron.log', $m ); }

	public static function executer( array $opts ) {
		$d = FKC_Config::dossier( 'plateforme/verrous' );
		if ( ! is_dir( $d ) ) { @mkdir( $d, 0750, true ); }
		$verrou = fopen( $d . '/cron.lock', 'c' );
		if ( ! $verrou || ! flock( $verrou, LOCK_EX | LOCK_NB ) ) { self::log( 'passage ignoré : le précédent est encore en cours' ); return 0; }
		if ( ! function_exists( 'proc_open' ) ) {
			self::log( 'ERREUR : proc_open indisponible — utilisez cron/worker.sh (voir docs)' );
			fwrite( STDERR, "proc_open indisponible : utilisez cron/worker.sh.\n" );
			return 2;
		}

		$debut  = microtime( true );
		$budget = max( 30, (int) FKC_Config::get( 'cron.budget', 240 ) );
		$delai  = max( 30, (int) FKC_Config::get( 'cron.delai_tenant', 180 ) );
		$clients = FKC_Plateforme_Registre::q(
			"SELECT t.* FROM tenants t LEFT JOIN taches k ON k.tenant_id=t.id AND k.tache='cron'
			 WHERE t.statut='actif' ORDER BY COALESCE(k.dernier_debut,'') ASC, t.id ASC" )->fetchAll();
		if ( ! empty( $opts['tenant-seul'] ) ) { $clients = array_filter( $clients, function ( $t ) use ( $opts ) { return $t['slug'] === $opts['tenant-seul']; } ); }

		$faits = 0; $echecs = 0; $restants = 0;
		foreach ( $clients as $t ) {
			if ( microtime( true ) - $debut > $budget ) { $restants++; continue; }
			FKC_Plateforme_Registre::tacheDebut( $t['id'], 'cron' );
			$t0 = microtime( true );
			list( $code, $sortie ) = self::lancer( $t['slug'], $delai, ! empty( $opts['forcer-sauvegarde'] ) );
			$duree = microtime( true ) - $t0;
			$ok = 0 === $code;
			$resume = trim( (string) preg_replace( '/\s+/', ' ', mb_substr( $sortie, -400 ) ) );
			FKC_Plateforme_Registre::tacheFin( $t['id'], 'cron', $ok, $duree, $resume );
			self::log( sprintf( '%s %s en %.2f s — %s', $t['slug'], $ok ? 'ok' : 'ÉCHEC (code ' . $code . ')', $duree, $resume ) );
			$ok ? $faits++ : $echecs++;
		}
		self::log( sprintf( 'passage terminé : %d client(s) traité(s), %d en échec, %d reporté(s), %.1f s', $faits, $echecs, $restants, microtime( true ) - $debut ) );
		if ( empty( $opts['quiet'] ) ) { printf( "%d client(s) traité(s), %d en échec, %d reporté(s).\n", $faits, $echecs, $restants ); }
		flock( $verrou, LOCK_UN );
		return $echecs ? 2 : 0;
	}

	/** Lance « bin/finakop --tenant=<slug> cron:client » avec délai maximal. @return array{0:int,1:string} */
	protected static function lancer( $slug, $delai, $forcerSauvegarde ) {
		$cmd = array( PHP_BINARY, FKC_Plateforme_Amorcage::racine() . '/bin/finakop', '--tenant=' . $slug, 'cron:client' );
		if ( $forcerSauvegarde ) { $cmd[] = '--forcer-sauvegarde'; }
		$env = null;
		$p = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
		if ( ! is_resource( $p ) ) { return array( 127, 'lancement impossible' ); }
		stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
		$sortie = ''; $fin = microtime( true ) + $delai;
		while ( true ) {
			$sortie .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			$st = proc_get_status( $p );
			if ( ! $st['running'] ) { $code = $st['exitcode']; break; }
			if ( microtime( true ) > $fin ) {
				proc_terminate( $p, 15 ); usleep( 500000 );
				if ( proc_get_status( $p )['running'] ) { proc_terminate( $p, 9 ); }
				$sortie .= " [arrêté après {$delai} s]";
				$code = 124; break;
			}
			usleep( 100000 );
		}
		$sortie .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		proc_close( $p );
		return array( (int) $code, $sortie );
	}

	/** Passage d'UN client (dans son propre processus, FinaKop déjà chargé). */
	public static function client( array $t, array $opts ) {
		$v = FKC_Plateforme_Console::verrou( $t, 'cron' );
		$code = 0; $msg = array();

		try {
			$r = FKC_BPEWorker::traiterToutesSocietes( 25 );
			$msg[] = "bpe {$r['traitees']}/{$r['succes']}";
			if ( $r['erreurs'] ) { $code = 2; $msg[] = 'bpe:' . implode( ' | ', array_slice( $r['erreurs'], 0, 3 ) ); }
		} catch ( \Throwable $e ) { $code = 2; $msg[] = 'bpe:' . $e->getMessage(); }

		try {
			$r = FKC_Balayeur::balayerTout();
			$msg[] = "balayage {$r['publiees']}";
			if ( $r['erreurs'] ) { $code = 2; $msg[] = 'balayage:' . implode( ' | ', array_slice( $r['erreurs'], 0, 3 ) ); }
		} catch ( \Throwable $e ) { $code = 2; $msg[] = 'balayage:' . $e->getMessage(); }

		// Sessions expirées : PHP ne les ramasse pas ici (gc_probability=0, voir Amorcage).
		$n = 0; $limite = time() - 28800;
		foreach ( glob( FKC_Plateforme_Registre::dossierSessions( $t ) . 'sess_*' ) ?: array() as $f ) {
			if ( @filemtime( $f ) < $limite && @unlink( $f ) ) { $n++; }
		}
		if ( $n ) { $msg[] = "sessions -{$n}"; }

		// Sauvegarde quotidienne, à partir de l'heure configurée, au plus une fois par 20 h.
		$heure = (int) FKC_Config::get( 'sauvegarde.heure', 2 );
		$derniere = FKC_Plateforme_Registre::tacheDerniere( $t['id'], 'sauvegarde' );
		$due = ( (int) gmdate( 'G' ) >= $heure && time() - $derniere > 72000 ) || ( time() - $derniere > 36 * 3600 );
		if ( ! empty( $opts['forcer-sauvegarde'] ) || $due ) {
			FKC_Plateforme_Registre::tacheDebut( $t['id'], 'sauvegarde' );
			$t0 = microtime( true );
			try {
				$r = FKC_Plateforme_Sauvegarde::creer( $t );
				FKC_Plateforme_Registre::tacheFin( $t['id'], 'sauvegarde', true, microtime( true ) - $t0, basename( $r['fichier'] ) );
				$msg[] = 'sauvegarde ' . basename( $r['fichier'] );
			} catch ( \Throwable $e ) {
				FKC_Plateforme_Registre::tacheFin( $t['id'], 'sauvegarde', false, microtime( true ) - $t0, $e->getMessage() );
				$code = 2; $msg[] = 'sauvegarde:' . $e->getMessage();
			}
		}
		echo implode( ' ; ', $msg ), "\n";
		return $code;
	}
}
