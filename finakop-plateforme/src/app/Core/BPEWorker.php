<?php
/**
 * Consommateur central de la file des traitements différés.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CE QUI MANQUAIT, ET POURQUOI C'ÉTAIT GRAVE
 *
 * Depuis l'arrivée du socle BPE, `erp_sync_queue` savait recevoir des tâches
 * (`FKC_BPE::empiler`), les rendre (`aTraiter`) et les clore (`conclureTache`).
 * Il manquait celui qui appelle ces trois méthodes. Personne, nulle part dans
 * l'extension, ne consommait la file.
 *
 * Une file sans consommateur est pire qu'une absence de file : elle donne à
 * l'application entière une façon d'oublier du travail en croyant l'avoir
 * remis à plus tard. C'est aussi pour cela qu'aucun producteur n'avait été
 * branché — le premier `empiler()` posé quelque part aurait perdu son
 * traitement en silence.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CE QUE CE FICHIER NE FAIT PAS
 *
 * Il ne décide d'aucune écriture, ne touche aucun stock et n'invente aucune
 * règle métier. Il PORTE des traitements déclarés ailleurs, dans l'ordre où
 * ils ont été empilés, avec les mêmes contrôles que si l'utilisateur les
 * avait déclenchés lui-même. Le socle reste observateur.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TROIS PRINCIPES REPRIS DE FKC_Balayeur (1.545.0)
 *
 *   1. NE RIEN INVENTER pour le multi-sociétés : `FKC_Tenant::bindById()`
 *      existe et purge déjà les caches de licence, de pack et de schémas
 *      comptables. Un pack résolu pour la société A qui s'appliquerait aux
 *      écritures de B est exactement l'accident que ce détour évite.
 *   2. RENDRE LA BASE D'ORIGINE, quoi qu'il arrive. Le worker peut tourner
 *      au sein d'une requête HTTP (WP-Cron) : laisser FKC_DB branché sur la
 *      dernière société traitée ferait écrire la suite de la requête chez un
 *      tiers.
 *   3. UNE SOCIÉTÉ EN PANNE N'ARRÊTE PAS LES AUTRES.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_BPEWorker {

	/** Nom du crochet WP-Cron. */
	const HOOK = 'fkc_bpe_worker';

	/** Intervalle du crochet (secondes) — voir planifier(). */
	const INTERVALLE = 300;

	/** Traitements déclarés : nom de tâche => callable. */
	protected static $traitements = array();

	/** Traitements de base déjà posés ? */
	protected static $sociales = false;

	/* ── Déclaration des traitements ──────────────────────────────────── */

	/**
	 * Déclare le traitement d'un type de tâche.
	 *
	 * @param string   $tache Nom empilé par le producteur (ex. « evenement »).
	 * @param callable $fn    function( array $charge, array $tache ): mixed
	 *                        Lève FKC_EchecDefinitif si un rejeu est inutile.
	 */
	public static function traitement( $tache, callable $fn ) {
		self::$traitements[ (string) $tache ] = $fn;
	}

	/** Noms des traitements connus (diagnostic, écran de supervision). */
	public static function traitements() {
		self::socle();
		return array_keys( self::$traitements );
	}

	/**
	 * Traitements livrés avec le socle.
	 *
	 * UN SEUL, DÉLIBÉRÉMENT. La leçon des onze capacités de 1.520.140 — dont
	 * neuf n'étaient consultées par aucun moteur — vaut ici : on ne déclare
	 * pas un catalogue de traitements qu'aucun producteur n'empile. Celui-ci
	 * suffit à rendre la file utile immédiatement, puisque tout le métier de
	 * FinaKop passe déjà par des événements.
	 */
	protected static function socle() {
		if ( self::$sociales ) { return; }
		self::$sociales = true;

		/*
		 * Certification FNE différée (1.689.0). Enregistrée ICI, à l'amorçage
		 * du worker, et non au chargement de son fichier : FKC_Fne vit dans un
		 * module, chargé après le noyau, et un `class_exists()` évalué trop tôt
		 * serait toujours faux. La file se remplirait sans que rien ne la vide.
		 */
		if ( class_exists( 'FKC_FneFile' ) ) { FKC_FneFile::enregistrer(); }

		/*
		 * « evenement » — rejoue un événement métier hors du parcours
		 * utilisateur. C'est ce qui permet à une chaîne longue (valorisation,
		 * écriture, alerte, tableau de bord) de ne jamais retarder un
		 * encaissement : l'écran émet, la file finit.
		 */
		self::traitement( 'evenement', function ( array $charge ) {
			$nom = trim( (string) ( $charge['__evenement'] ?? '' ) );
			if ( '' === $nom ) {
				throw new FKC_EchecDefinitif( 'Tâche « evenement » sans nom d\'événement.' );
			}
			if ( ! class_exists( 'FKC_Events' ) ) {
				throw new \RuntimeException( 'Bus d\'événements indisponible.' );
			}
			$ctx = $charge;
			unset( $ctx['__evenement'] );
			$r = FKC_Events::emettre( $nom, $ctx, array( 'source' => 'file' ) );
			/*
			 * Le bus rend maintenant ses échecs (1.620.0) : un écouteur qui
			 * a levé, ou une écriture refusée, font échouer la TÂCHE. Sans
			 * cela, la file se déclarerait satisfaite d'un traitement qui
			 * n'a pas eu lieu — le défaut même que ce lot corrige côté bus.
			 */
			if ( ! empty( $r['echecs'] ) || ( isset( $r['ecriture_ok'] ) && false === $r['ecriture_ok'] ) ) {
				throw new \RuntimeException( implode( ' | ', (array) ( $r['erreurs'] ?? array( 'échec non détaillé' ) ) ) );
			}
			return $r;
		} );
	}

	/* ── Boucle de traitement ─────────────────────────────────────────── */

	/**
	 * Traite la file de la société COURANTE.
	 *
	 * @param int $max Nombre maximum de tâches par passage. Borné : un
	 *                 passage doit finir avant le temps d'exécution PHP, et
	 *                 une file de dix mille tâches ne doit pas transformer un
	 *                 cron en incident.
	 * @return array{recuperees:int,traitees:int,succes:int,echecs:int,abandons:int,messages:array}
	 */
	public static function run( $max = 25 ) {
		self::socle();
		$max = max( 1, min( 200, (int) $max ) );
		$res = array( 'recuperees' => 0, 'traitees' => 0, 'succes' => 0, 'echecs' => 0, 'abandons' => 0, 'messages' => array() );

		// 1) Rendre à la file ce qu'un worker mort retient depuis trop longtemps.
		$res['recuperees'] = FKC_BPE::recupererOrphelines();

		$jeton = self::jeton();
		foreach ( FKC_BPE::aTraiter( $max ) as $candidate ) {
			// 2) Prise de main atomique : si un autre worker l'a eue, on passe.
			$tache = FKC_BPE::reclamer( (int) $candidate['id'], $jeton );
			if ( ! $tache ) { continue; }

			$res['traitees']++;
			try {
				self::dispatch( $tache );
				self::succes( $tache );
				$res['succes']++;
			} catch ( FKC_EchecDefinitif $e ) {
				self::echec( $tache, $e->getMessage(), true );
				$res['abandons']++;
				$res['messages'][] = '#' . (int) $tache['id'] . ' (' . $tache['tache'] . ') abandonnée : ' . $e->getMessage();
			} catch ( \Throwable $e ) {
				self::echec( $tache, $e->getMessage(), false );
				$res['echecs']++;
				$res['messages'][] = '#' . (int) $tache['id'] . ' (' . $tache['tache'] . ') : ' . $e->getMessage();
			}
		}
		/*
		 * BATTEMENT — posé même quand le passage n'a RIEN fait.
		 *
		 * C'est justement le passage à vide qui prouve que le moteur vit :
		 * n'enregistrer que les passages productifs rendrait de nouveau
		 * indiscernables « moteur arrêté » et « rien à faire ». FinaKop a
		 * déjà payé cette confusion — la planification du balayeur, jamais
		 * exécutée pendant des dizaines de versions sans que rien ne le dise.
		 *
		 * Hors du try/catch de la boucle et sans effet sur le résultat : la
		 * supervision ne doit jamais faire échouer le traitement qu'elle
		 * observe.
		 */
		if ( class_exists( 'FKC_BPESante' ) ) {
			FKC_BPESante::battre( (int) $res['succes'], (int) $res['echecs'] + (int) $res['abandons'], self::declencheur() );
		}
		return $res;
	}

	/**
	 * D'où vient ce passage ? Utile en supervision : un moteur qui ne tourne
	 * QUE sur clic manuel n'est pas un moteur qui tourne.
	 */
	protected static function declencheur() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return 'wp-cli'; }
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) { return 'cron'; }
		return 'manuel';
	}

	/**
	 * Exécute le traitement d'une tâche réclamée.
	 *
	 * L'IDEMPOTENCE EST POSÉE ICI, ET NON DANS CHAQUE TRAITEMENT. Une tâche
	 * peut être rejouée : après un échec récupérable, après récupération
	 * d'orpheline, ou à la main depuis l'écran de supervision. Sans clé, le
	 * troisième rejeu d'une réception créerait un troisième stock. La clé est
	 * l'identifiant de la tâche elle-même, qui est unique par construction.
	 */
	public static function dispatch( array $tache ) {
		self::socle();
		$nom = (string) $tache['tache'];
		if ( ! isset( self::$traitements[ $nom ] ) ) {
			// Rejouer n'y changera rien : aucun code de cette installation ne
			// sait faire ce travail. On le dit tout de suite plutôt que de
			// consommer cinq tentatives sur quarante minutes.
			throw new FKC_EchecDefinitif( 'Aucun traitement déclaré pour la tâche « ' . $nom . ' ».' );
		}
		$charge = json_decode( (string) ( $tache['charge'] ?? '{}' ), true );
		if ( ! is_array( $charge ) ) { $charge = array(); }

		$fn  = self::$traitements[ $nom ];
		$cle = 'file:' . $nom . ':' . (int) $tache['id'];
		$r   = FKC_BPE::uneSeuleFois( $cle, function () use ( $fn, $charge, $tache ) {
			return call_user_func( $fn, $charge, $tache );
		} );

		/*
		 * Le registre a refusé l'exécution. Deux cas très différents :
		 *  — déjà traité : la tâche a abouti lors d'un passage précédent et
		 *    n'a simplement pas pu être close (worker tué entre les deux) ;
		 *    la clore maintenant est la bonne réponse, pas une erreur ;
		 *  — en cours ailleurs : on renonce sans marquer d'échec, la tâche
		 *    reviendra d'elle-même.
		 */
		if ( empty( $r['execute'] ) ) {
			if ( FKC_BPE::TRAITE === ( $r['statut'] ?? '' ) ) { return null; }
			if ( FKC_BPE::ECHEC_DEFINITIF === ( $r['statut'] ?? '' ) ) {
				throw new FKC_EchecDefinitif( (string) ( $r['motif'] ?? 'Échec définitif enregistré.' ) );
			}
			throw new \RuntimeException( (string) ( $r['motif'] ?? 'Exécution refusée par le registre.' ) );
		}
		return $r['resultat'];
	}

	/** Clôt une tâche réussie. */
	public static function succes( array $tache ) {
		FKC_BPE::conclureTache( (int) $tache['id'], true );
	}

	/** Clôt une tâche en échec, en distinguant le récupérable du définitif. */
	public static function echec( array $tache, $message, $definitif = false ) {
		FKC_BPE::conclureTache( (int) $tache['id'], false, (string) $message, (bool) $definitif );
	}

	/** Remet en file les tâches dont le worker a disparu. */
	public static function recupererOrphelines( $minutes = null ) {
		return FKC_BPE::recupererOrphelines( $minutes );
	}

	/**
	 * Identité du worker courant : hôte + processus.
	 *
	 * Sert au diagnostic plus qu'à la sûreté (celle-ci vient de l'UPDATE
	 * conditionnel). Savoir QUEL processus retient une tâche depuis une heure
	 * est ce qui permet de trancher entre un cron système en boucle et un
	 * WP-Cron interrompu.
	 */
	public static function jeton() {
		$hote = function_exists( 'gethostname' ) ? (string) gethostname() : 'hote';
		$pid  = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
		return substr( $hote, 0, 40 ) . '#' . $pid;
	}

	/* ── Passage sur toutes les sociétés ──────────────────────────────── */

	/**
	 * Traite la file de chaque société active.
	 *
	 * @return array{societes:int,traitees:int,succes:int,echecs:int,abandons:int,recuperees:int,erreurs:array}
	 */
	public static function traiterToutesSocietes( $max = 25 ) {
		$res = array( 'societes' => 0, 'traitees' => 0, 'succes' => 0, 'echecs' => 0,
			'abandons' => 0, 'recuperees' => 0, 'erreurs' => array() );

		if ( ! class_exists( 'FKC_Master' ) || ! class_exists( 'FKC_Tenant' ) ) {
			$res['erreurs'][] = 'Contexte multi-sociétés indisponible.';
			return $res;
		}

		// La base courante est rendue telle quelle à la fin — voir principe 2.
		$fichierInitial = FKC_DB::file();

		try {
			$societes = FKC_Master::q( 'SELECT id, raison_sociale FROM societes WHERE actif=1 ORDER BY id' )->fetchAll();
		} catch ( \Throwable $e ) {
			$res['erreurs'][] = 'Liste des sociétés illisible : ' . $e->getMessage();
			return $res;
		}

		foreach ( $societes as $soc ) {
			$id  = (int) $soc['id'];
			$nom = (string) ( $soc['raison_sociale'] ?? ( '#' . $id ) );
			try {
				if ( ! FKC_Tenant::bindById( $id ) ) {
					$res['erreurs'][] = '#' . $id . ' : base inaccessible';
					continue;
				}
				$r = self::run( $max );
				// Passe planifiée : les alertes franchies d'une société qu'on
				// n'ouvre pas partent quand même dans la cloche (1.837.0).
				if ( class_exists( 'FKC_Alerte' ) && class_exists( 'FKC_Modules' ) && FKC_Modules::featureEnabled( 'analyse.alertes' ) ) {
					try {
						if ( class_exists( 'FKC_Metrics' ) ) { FKC_Metrics::oublier(); } // mémo de la société précédente
						FKC_Alerte::notifierSiDu();
					} catch ( \Throwable $e ) {}
				}
				// Photo mensuelle du recouvrement (1.841.5) : une société qu'on
				// n'ouvre pas garde quand même son historique de fin de mois.
				if ( class_exists( 'FKC_RecouvrementPilotage' ) && class_exists( 'FKC_License' ) && FKC_License::moduleEnabled( 'recouvrement' ) ) {
					try { FKC_Recouvrement::oublier(); FKC_RecouvrementPilotage::photographier(); } catch ( \Throwable $e ) {}
				}
				$res['societes']++;
				foreach ( array( 'traitees', 'succes', 'echecs', 'abandons', 'recuperees' ) as $k ) {
					$res[ $k ] += (int) $r[ $k ];
				}
				foreach ( (array) $r['messages'] as $m ) { $res['erreurs'][] = $nom . ' — ' . $m; }
			} catch ( \Throwable $e ) {
				$res['erreurs'][] = $nom . ' : ' . $e->getMessage();
				if ( function_exists( 'fkc_log' ) ) {
					fkc_log( 'BPE worker société ' . $id . ' : ' . $e->getMessage(), 'WARN' );
				}
			}
		}

		try {
			FKC_DB::useFile( $fichierInitial );
			FKC_Tenant::oublierCaches();
			FKC_Tenant::oublier();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'fkc_log' ) ) {
				fkc_log( 'BPE worker : retour à la base initiale impossible — ' . $e->getMessage(), 'ERROR' );
			}
		}

		if ( $res['traitees'] && function_exists( 'fkc_log' ) ) {
			fkc_log( sprintf(
				'BPE worker : %d société(s), %d tâche(s), %d réussie(s), %d en échec, %d abandonnée(s), %d récupérée(s).',
				$res['societes'], $res['traitees'], $res['succes'], $res['echecs'], $res['abandons'], $res['recuperees']
			), 'INFO' );
		}
		return $res;
	}

	/* ── Planification ────────────────────────────────────────────────── */

	/**
	 * Programme le passage du worker.
	 *
	 * TOUTES LES CINQ MINUTES, et non toutes les heures comme le balayage de
	 * la temporisation : une tâche différée l'a été pour ne pas retarder un
	 * encaissement, pas pour attendre le prochain quart d'heure. Le coût est
	 * nul quand la file est vide — un COUNT sur un index.
	 */
	public static function planifier() {
		// Plateforme autonome (1.876.0) : le passage est assuré par le cron système.
		if ( defined( 'FKC_CRON_EXTERNE' ) && FKC_CRON_EXTERNE ) { return true; }
		if ( ! function_exists( 'wp_next_scheduled' ) ) { return false; }
		if ( wp_next_scheduled( self::HOOK ) ) { return true; }
		return (bool) wp_schedule_event( time() + 60, 'fkc_cinq_minutes', self::HOOK );
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
	 * WP-Cron est-il coupé sur cette installation ?
	 *
	 * `DISABLE_WP_CRON` est courant en hébergement sérieux, remplacé par un
	 * cron système. L'application doit le DIRE plutôt que de promettre un
	 * traitement différé qui n'aura jamais lieu.
	 */
	public static function cronDesactive() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
