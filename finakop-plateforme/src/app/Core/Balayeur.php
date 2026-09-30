<?php
/**
 * Balayage planifié de la temporisation comptable, sur toutes les sociétés.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POURQUOI CE FICHIER A ÉTÉ ÉCRIT EN DERNIER, ET AVEC PRÉCAUTION
 *
 * Depuis la 1.541.0, une écriture temporisée attend son délai au journal
 * temporaire puis rejoint les journaux. Encore faut-il que quelqu'un regarde
 * l'horloge. Jusqu'ici, ce quelqu'un était la requête suivante : le balayage
 * se déclenchait à l'occasion d'une visite, au plus une fois par quart
 * d'heure. Simple, sûr — et faux dès que personne n'ouvre l'application. Sur
 * un dossier laissé de côté une semaine, RIEN ne se publiait. Le délai
 * annoncé « six heures » pouvait en durer cent cinquante.
 *
 * CE QUI REND L'EXERCICE DÉLICAT. FinaKop est multi-sociétés : chaque dossier
 * a sa propre base. Un balayage planifié tourne donc HORS SESSION — sans
 * utilisateur, sans société active, sans le contexte de droits sur lequel
 * repose tout le reste de l'application. C'est exactement la configuration
 * dans laquelle une erreur d'aiguillage écrit dans la mauvaise base.
 *
 * TROIS PRINCIPES ONT GUIDÉ L'ÉCRITURE :
 *
 *   1. NE RIEN INVENTER. On ne rouvre pas les bases à la main : on emprunte
 *      FKC_Tenant::bindById(), écrit pour les flux authentifiés hors session
 *      (appairage de scan) et qui purge déjà les caches de licence, de pack
 *      et de schémas comptables — l'oubli le plus dangereux ici, puisqu'un
 *      pack résolu pour la société A s'appliquerait sinon aux écritures de B.
 *
 *   2. NE RIEN FAIRE QUE L'UTILISATEUR N'AIT DÉJÀ DÉCIDÉ. Ce balayeur ne
 *      publie que ce qu'une société a explicitement réglé en « temporisé »,
 *      au terme du délai qu'elle a choisi, avec les mêmes contrôles qu'une
 *      validation à la main. Il n'ouvre aucune permission nouvelle : il rend
 *      seulement fiable une décision déjà prise.
 *
 *   3. UNE SOCIÉTÉ EN PANNE N'ARRÊTE PAS LES AUTRES. Chaque dossier est
 *      traité dans son propre enclos ; une base corrompue, un fichier absent
 *      ou une écriture refusée est journalisée et le balayage continue.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Balayeur {

	/** Nom du crochet WP-Cron. */
	const HOOK = 'fkc_balayage_temporisation';

	/**
	 * Balaie toutes les sociétés actives.
	 *
	 * @param bool $forcer Ignore l'étranglement des quinze minutes (appel manuel).
	 * @return array{societes:int, publiees:int, refusees:int, retenues:int, erreurs:array}
	 */
	public static function balayerTout( $forcer = false ) {
		$res = array( 'societes' => 0, 'publiees' => 0, 'refusees' => 0, 'retenues' => 0, 'erreurs' => array() );

		/*
		 * On mémorise la base courante pour la rendre telle quelle à la fin.
		 * Ce balayeur peut être appelé depuis une requête ordinaire (WP-Cron
		 * s'exécute au sein d'une requête HTTP) : laisser FKC_DB branché sur
		 * la dernière société traitée ferait écrire la suite de la requête
		 * dans la base d'un tiers. C'est LE risque de ce fichier.
		 */
		$fichierInitial = FKC_DB::file();

		try {
			$societes = FKC_Master::q( 'SELECT id, raison_sociale FROM societes WHERE actif=1 ORDER BY id' )->fetchAll();
		} catch ( \Throwable $e ) {
			$res['erreurs'][] = 'Liste des sociétés illisible : ' . $e->getMessage();
			return $res;
		}

		foreach ( $societes as $soc ) {
			$id = (int) $soc['id'];
			try {
				if ( ! FKC_Tenant::bindById( $id ) ) {
					$res['erreurs'][] = '#' . $id . ' : base inaccessible';
					continue;
				}
				$r = $forcer ? FKC_PolitiqueCompta::publierEchues() : FKC_PolitiqueCompta::balayer();
				if ( null === $r ) { continue; } // étranglé : passage trop récent.
				$res['societes']++;
				$res['publiees'] += (int) ( $r['publiees'] ?? 0 );
				$res['refusees'] += (int) ( $r['refusees'] ?? 0 );
				$res['retenues'] += (int) ( $r['retenues'] ?? 0 );
				foreach ( (array) ( $r['messages'] ?? array() ) as $m ) {
					$res['erreurs'][] = ( $soc['raison_sociale'] ?? ( '#' . $id ) ) . ' — ' . $m;
				}
			} catch ( \Throwable $e ) {
				// Une société en panne n'arrête pas les autres.
				$res['erreurs'][] = ( $soc['raison_sociale'] ?? ( '#' . $id ) ) . ' : ' . $e->getMessage();
				if ( function_exists( 'fkc_log' ) ) {
					fkc_log( 'Balayeur société ' . $id . ' : ' . $e->getMessage(), 'WARN' );
				}
			}
		}

		/*
		 * Retour à la base d'origine, quoi qu'il arrive. `oublierCaches()` est
		 * indispensable : sans lui, la licence, le pack et les schémas
		 * comptables résolus pour la DERNIÈRE société balayée resteraient en
		 * mémoire et s'appliqueraient à la suite de la requête.
		 */
		try {
			FKC_DB::useFile( $fichierInitial );
			FKC_Tenant::oublierCaches();
			FKC_Tenant::oublier();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'fkc_log' ) ) {
				fkc_log( 'Balayeur : retour à la base initiale impossible — ' . $e->getMessage(), 'ERROR' );
			}
		}

		if ( $res['publiees'] || $res['refusees'] ) {
			if ( function_exists( 'fkc_log' ) ) {
				fkc_log( sprintf(
					'Balayage temporisation : %d société(s), %d publiée(s), %d refusée(s), %d retenue(s).',
					$res['societes'], $res['publiees'], $res['refusees'], $res['retenues']
				), 'INFO' );
			}
		}
		return $res;
	}

	/* ── Planification ───────────────────────────────────────────────────── */

	/**
	 * Programme le balayage horaire.
	 *
	 * POURQUOI TOUTES LES HEURES ET NON TOUTES LES SIX HEURES : le délai est
	 * réglable par origine et par société. Une société peut choisir une
	 * heure ; le planificateur doit donc repasser assez souvent pour honorer
	 * le plus court des délais possibles, pas le plus courant. Le coût est
	 * nul quand rien n'attend — l'étranglement de quinze minutes et le filtre
	 * par origine font que la plupart des passages ne lisent rien.
	 */
	public static function planifier() {
		// Plateforme autonome (1.876.0) : le passage est assuré par le cron système.
		if ( defined( 'FKC_CRON_EXTERNE' ) && FKC_CRON_EXTERNE ) { return true; }
		if ( ! function_exists( 'wp_next_scheduled' ) ) { return false; }
		if ( wp_next_scheduled( self::HOOK ) ) { return true; }
		return (bool) wp_schedule_event( time() + 300, 'hourly', self::HOOK );
	}

	/** Retire la planification (désactivation du plugin). */
	public static function deplanifier() {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { return; }
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Horodatage du prochain passage planifié, ou null. */
	public static function prochainPassage() {
		if ( defined( 'FKC_CRON_EXTERNE' ) && FKC_CRON_EXTERNE ) {
			// Créneau suivant du cron système (cron/worker.php, toutes les FKC_CRON_INTERVALLE s).
			$i = defined( 'FKC_CRON_INTERVALLE' ) ? max( 60, (int) FKC_CRON_INTERVALLE ) : 300;
			$t = time();
			return $t - ( $t % $i ) + $i;
		}
		if ( ! function_exists( 'wp_next_scheduled' ) ) { return null; }
		$t = wp_next_scheduled( self::HOOK );
		return $t ?: null;
	}

	/**
	 * WP-Cron est-il désactivé sur cette installation ?
	 *
	 * `DISABLE_WP_CRON` est courant en hébergement sérieux : on le coupe pour
	 * le remplacer par un vrai cron système. L'application doit le DIRE
	 * plutôt que de promettre une publication qui n'aura pas lieu — c'est la
	 * même exigence d'honnêteté que partout ailleurs dans ce module.
	 */
	public static function cronDesactive() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
