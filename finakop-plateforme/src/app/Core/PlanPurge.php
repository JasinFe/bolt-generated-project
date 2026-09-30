<?php
/**
 * PURGE DU PLAN COMPTABLE — analyse complète et suppression définitive.
 *
 * ── CE QU'UNE PURGE PEUT ET NE PEUT PAS FAIRE ────────────────────────────
 *
 * Un plan comptable n'est pas une liste de préférences : c'est le référentiel
 * sur lequel sont écrites des opérations déjà passées, lettrées, rapprochées,
 * et souvent déjà déclarées. Trois limites en découlent, et elles ne se
 * négocient pas.
 *
 *  1. UN COMPTE MOUVEMENTÉ NE SE SUPPRIME PAS. Ses lignes d'écriture pointent
 *     son identifiant. L'effacer laisse un grand livre qui cite un compte
 *     introuvable et une balance qui ne boucle plus — sans message, sans
 *     trace, et sans retour possible. Ce qui se fait à sa place : virer son
 *     solde vers le compte correct, puis le désactiver. Il reste alors
 *     consultable, ce qui est précisément ce que l'audit exige.
 *
 *  2. UN COMPTE DU RÉFÉRENTIEL NE SE SUPPRIME PAS UTILEMENT. L'écran du plan
 *     resynchronise la bibliothèque à chaque affichage : un compte du socle
 *     OHADA ou du pack métier effacé réapparaît au rechargement suivant. Le
 *     supprimer donne l'illusion d'un nettoyage et n'en produit aucun. On le
 *     désactive, ce qui le sort des listes de saisie et des états.
 *
 *  3. UN COMPTE RÉFÉRENCÉ AILLEURS NE SE SUPPRIME PAS. Comptes de trésorerie,
 *     caisses, paramètres d'imputation : l'effacer casserait silencieusement
 *     le mécanisme qui s'appuie dessus.
 *
 * ── CE QUE LA PURGE SUPPRIME DONC RÉELLEMENT ─────────────────────────────
 *
 * Les comptes qui ne sont RIEN de tout cela : jamais mouvementés, absents du
 * référentiel et de la bibliothèque métier, cités par aucun paramètre ni
 * aucune table, et redondants ou désactivés. Ce sont les vrais déchets — ceux
 * nés d'une saisie à la volée, d'une amorce corrigée depuis, ou d'un doublon.
 * Leur disparition ne change aucun solde.
 *
 * Tout le reste est CLASSÉ et EXPLIQUÉ, jamais effacé en silence.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_PlanPurge {

	/** Verdicts possibles pour un compte. */
	public static function verdicts() {
		return array(
			'supprimable'  => array( 'Supprimable', 'Jamais mouvementé, hors référentiel, référencé nulle part.' ),
			'a_virer'      => array( 'À virer puis désactiver', 'Porte des écritures et a un compte cible : son solde doit être viré avant retrait.' ),
			'a_desactiver' => array( 'À désactiver', 'Porte des écritures sans cible connue : il se désactive, il ne s\'efface pas.' ),
			'protege'      => array( 'Protégé', 'Référentiel, paramètre ou table qui en dépend.' ),
		);
	}

	/** Motifs pour lesquels un compte entre dans l'analyse. */
	public static function motifs() {
		return array(
			'doublon'    => 'Doublon — un autre compte porte le même intitulé',
			'desactive'  => 'Désactivé',
			'remplace'   => 'Mal imputé — remplacé par un autre compte',
			'inutilise'  => 'Jamais utilisé et hors référentiel',
		);
	}

	/* ════════════════════════════════════════════════════════════════════
	 *  RÉFÉRENCES — tout ce qui empêche une suppression
	 * ════════════════════════════════════════════════════════════════════ */

	/**
	 * Numéros de compte cités dans les paramètres de la société.
	 *
	 * Les comptes d'imputation (posting_compte_*, lbl_*, dist_*, fne_*) sont
	 * stockés comme de simples valeurs texte : aucune clé étrangère ne les
	 * protège. On les relève par leur FORME — six chiffres — plutôt que par
	 * une liste de clés qu'il faudrait tenir à jour et qu'on oublierait.
	 *
	 * @return array<string,string> numéro => clé de paramètre
	 */
	public static function comptesParametres() {
		$out = array();
		try {
			foreach ( FKC_DB::q( 'SELECT cle, valeur FROM parametres' )->fetchAll() as $p ) {
				$v = trim( (string) $p['valeur'] );
				if ( preg_match( '/^\d{6,10}$/', $v ) ) { $out[ $v ] = (string) $p['cle']; }
			}
		} catch ( \Throwable $e ) {}
		return $out;
	}

	/** Identifiants de compte utilisés par les tables qui en dépendent. */
	public static function comptesLies() {
		$out = array();
		$sources = array(
			'tresorerie_comptes' => 'compte de trésorerie',
			'caisses'            => 'caisse',
		);
		foreach ( $sources as $table => $libelle ) {
			try {
				foreach ( FKC_DB::q( "SELECT DISTINCT compte_id FROM {$table} WHERE compte_id IS NOT NULL" )->fetchAll() as $r ) {
					$out[ (int) $r['compte_id'] ] = $libelle;
				}
			} catch ( \Throwable $e ) {}
		}
		return $out;
	}

	/**
	 * Nombre de lignes portées par un compte, BROUILLONS COMPRIS.
	 *
	 * Ne compter que les écritures validées laisserait supprimer un compte
	 * qu'un brouillon en cours de saisie référence : la pièce deviendrait
	 * impossible à valider, et l'utilisateur ne comprendrait pas pourquoi.
	 */
	protected static function nbLignes( $compteId ) {
		$n = 0;
		foreach ( array( 'ecriture_lignes', 'ecriture_brouillon_lignes' ) as $t ) {
			try { $n += (int) FKC_DB::q( "SELECT COUNT(*) FROM {$t} WHERE compte_id=?", array( (int) $compteId ) )->fetchColumn(); }
			catch ( \Throwable $e ) {}
		}
		return $n;
	}

	/** Numéros déclarés par le référentiel OHADA ou la bibliothèque métier. */
	public static function numerosReferentiels() {
		$out = array();
		try {
			foreach ( array_keys( FKC_PlanSyscohada::referentiel() ) as $n ) { $out[ (string) $n ] = 'référentiel SYSCOHADA'; }
		} catch ( \Throwable $e ) {}
		foreach ( array( 'socleTransversal', 'socleCommun' ) as $m ) {
			try {
				foreach ( array_keys( (array) FKC_PlanMetier::$m() ) as $n ) { $out[ (string) $n ] = 'socle transversal'; }
			} catch ( \Throwable $e ) {}
		}
		try {
			foreach ( (array) FKC_PlanMetier::bibliotheque() as $pack => $comptes ) {
				foreach ( array_keys( (array) $comptes ) as $n ) {
					if ( ! isset( $out[ (string) $n ] ) ) { $out[ (string) $n ] = 'bibliothèque métier'; }
				}
			}
		} catch ( \Throwable $e ) {}
		return $out;
	}

	/* ════════════════════════════════════════════════════════════════════
	 *  ANALYSE
	 * ════════════════════════════════════════════════════════════════════ */

	/** Intitulé réduit à sa substance, pour rapprocher deux libellés voisins. */
	public static function cleLibelle( $libelle ) {
		$l = (string) $libelle;
		$l = function_exists( 'mb_strtolower' ) ? mb_strtolower( $l, 'UTF-8' ) : strtolower( $l );
		$l = strtr( $l, array( 'à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
			'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','’'=>"'" ) );
		// Ponctuation et tirets d'incise réduits à l'espace : « Mobile Money —
		// Wave » et « Mobile Money - Wave » sont le même intitulé.
		$l = preg_replace( '/[^a-z0-9]+/u', ' ', $l );
		return trim( preg_replace( '/\s+/', ' ', (string) $l ) );
	}

	/**
	 * Analyse complète du plan de la société active.
	 *
	 * @return array{comptes: array, resume: array, total: int}
	 */
	public static function analyser() {
		$resume = array( 'supprimable' => 0, 'a_virer' => 0, 'a_desactiver' => 0, 'protege' => 0 );
		$out = array( 'comptes' => array(), 'resume' => $resume, 'total' => 0,
			'par_motif' => array( 'doublon' => 0, 'desactive' => 0, 'remplace' => 0, 'inutilise' => 0 ) );

		try { $comptes = FKC_DB::q( 'SELECT * FROM comptes ORDER BY numero' )->fetchAll(); }
		catch ( \Throwable $e ) { return $out; }
		$out['total'] = count( $comptes );
		if ( ! $comptes ) { return $out; }

		$refs       = self::numerosReferentiels();
		$params     = self::comptesParametres();
		$lies       = self::comptesLies();
		$divergences = class_exists( 'FKC_PlanAudit' ) ? FKC_PlanAudit::divergencesConnues() : array();
		// Classement calculé UNE fois pour toute l'analyse (1.876.0) : voir FKC_PlanAudit::cibleDans().
		$classement = class_exists( 'FKC_PlanAudit' ) ? FKC_PlanAudit::classerPlan() : array( 'comptes' => array() );

		// Regroupement par intitulé : un même libellé sur plusieurs numéros est
		// le signe le plus fiable d'un doublon. Le compte du référentiel est
		// toujours celui qu'on garde — c'est lui qui est « le bon ».
		$parLibelle = array();
		foreach ( $comptes as $c ) {
			$k = self::cleLibelle( $c['libelle'] );
			if ( '' === $k ) { continue; }
			$parLibelle[ $k ][] = $c;
		}

		foreach ( $comptes as $c ) {
			$num = (string) $c['numero'];
			$id  = (int) $c['id'];

			/* ── Motifs : pourquoi ce compte mérite un examen ───────────── */
			$motifs = array();
			$cible  = null;

			if ( empty( $c['actif'] ) ) { $motifs['desactive'] = true; }

			if ( isset( $divergences[ $num ] ) ) {
				$motifs['remplace'] = true;
				$cible = $divergences[ $num ][1];
			} elseif ( class_exists( 'FKC_PlanAudit' ) ) {
				$d = FKC_PlanAudit::cibleDans( $num, $classement );
				if ( $d && ! empty( $d['cible'] ) && $d['cible'] !== $num ) {
					$motifs['remplace'] = true;
					$cible = $d['cible'];
				}
			}

			$k = self::cleLibelle( $c['libelle'] );
			if ( '' !== $k && count( $parLibelle[ $k ] ?? array() ) > 1 ) {
				/*
				 * Entre deux comptes de même intitulé, le survivant est celui
				 * que le référentiel connaît. À défaut, le plus petit numéro —
				 * une règle arbitraire, mais stable : l'important est que le
				 * même compte gagne à chaque passage, sinon deux analyses
				 * successives proposeraient de supprimer l'un puis l'autre.
				 */
				$freres = $parLibelle[ $k ];
				$garde = null;
				foreach ( $freres as $f ) {
					if ( isset( $refs[ (string) $f['numero'] ] ) ) { $garde = $f; break; }
				}
				if ( ! $garde ) {
					usort( $freres, fn( $a, $b ) => strcmp( (string) $a['numero'], (string) $b['numero'] ) );
					$garde = $freres[0];
				}
				if ( (int) $garde['id'] !== $id ) {
					$motifs['doublon'] = true;
					if ( ! $cible ) { $cible = (string) $garde['numero']; }
				}
			}

			$nb = self::nbLignes( $id );
			if ( ! $motifs && 0 === $nb && ! isset( $refs[ $num ] ) && 'ohada' !== ( $c['origine'] ?? '' ) ) {
				$motifs['inutilise'] = true;
			}
			if ( ! $motifs ) { continue; }

			/* ── Verdict : ce qu'on peut réellement en faire ────────────── */
			$verdict = 'supprimable';
			$raison  = 'Jamais mouvementé, hors référentiel, référencé nulle part.';

			if ( isset( $refs[ $num ] ) ) {
				$verdict = 'protege';
				$raison  = 'Déclaré par le ' . $refs[ $num ] . ' : il serait recréé au prochain affichage du plan. Désactivez-le plutôt.';
			} elseif ( ! empty( $c['verrouille'] ) || 'ohada' === ( $c['origine'] ?? '' ) ) {
				$verdict = 'protege';
				$raison  = 'Compte réglementaire : le référentiel SYSCOHADA ne se soustrait pas.';
			} elseif ( isset( $lies[ $id ] ) ) {
				$verdict = 'protege';
				$raison  = 'Utilisé comme ' . $lies[ $id ] . ' : le supprimer casserait ce rattachement.';
			} elseif ( isset( $params[ $num ] ) ) {
				$verdict = 'protege';
				$raison  = 'Cité par le paramètre « ' . $params[ $num ] . ' » : les écritures automatiques qui s\'y réfèrent échoueraient.';
			} elseif ( $nb > 0 ) {
				$verdict = $cible ? 'a_virer' : 'a_desactiver';
				$raison  = $nb . ' ligne(s) d\'écriture' . ( $cible
					? ' : virez son solde vers ' . $cible . ', puis désactivez-le.'
					: ' : il se désactive, il ne s\'efface pas — le grand livre le cite.' );
			}

			$out['comptes'][] = array(
				'id' => $id, 'numero' => $num, 'libelle' => $c['libelle'],
				'actif' => (int) $c['actif'], 'origine' => $c['origine'] ?? '',
				'nb_lignes' => $nb, 'cible' => $cible,
				'motifs' => array_keys( $motifs ), 'verdict' => $verdict, 'raison' => $raison,
			);
			$out['resume'][ $verdict ] = ( $out['resume'][ $verdict ] ?? 0 ) + 1;
			foreach ( array_keys( $motifs ) as $m ) { $out['par_motif'][ $m ] = ( $out['par_motif'][ $m ] ?? 0 ) + 1; }
		}
		return $out;
	}

	/* ════════════════════════════════════════════════════════════════════
	 *  EXÉCUTION
	 * ════════════════════════════════════════════════════════════════════ */

	/**
	 * Supprime définitivement les comptes jugés supprimables.
	 *
	 * Le verdict est RECALCULÉ ici, jamais repris du formulaire : entre
	 * l'affichage de l'écran et le clic, une écriture a pu être passée sur un
	 * compte qui paraissait vierge. Faire confiance à un verdict transmis par
	 * le navigateur reviendrait à supprimer un compte mouvementé sur la foi
	 * d'une page périmée.
	 *
	 * @param bool  $simulation true = ne rien écrire, seulement compter.
	 * @param array $numeros    Restreint à ces numéros ; vide = tous les supprimables.
	 * @return array{supprimes: array, refuses: array, message: string}
	 */
	public static function purger( $simulation = true, array $numeros = array() ) {
		$a = self::analyser();
		$filtre = array();
		foreach ( $numeros as $n ) { $filtre[ (string) $n ] = true; }

		$supprimes = array(); $refuses = array();
		foreach ( $a['comptes'] as $c ) {
			if ( $filtre && ! isset( $filtre[ $c['numero'] ] ) ) { continue; }
			if ( 'supprimable' !== $c['verdict'] ) {
				if ( $filtre ) { $refuses[] = $c; }
				continue;
			}
			if ( $simulation ) { $supprimes[] = $c; continue; }
			try {
				FKC_DB::q( 'DELETE FROM comptes WHERE id=?', array( (int) $c['id'] ) );
				$supprimes[] = $c;
				if ( function_exists( 'fkc_log' ) ) {
					fkc_log( 'PlanPurge — compte ' . $c['numero'] . ' « ' . $c['libelle'] . ' » supprimé ('
						. implode( ', ', $c['motifs'] ) . ')', 'INFO' );
				}
			} catch ( \Throwable $e ) {
				$c['raison'] = 'Suppression refusée par la base : ' . $e->getMessage();
				$refuses[] = $c;
			}
		}

		$n = count( $supprimes );
		if ( $simulation ) {
			$msg = 0 === $n
				? 'Aucun compte n\'est supprimable en l\'état.'
				: $n . ' compte(s) seraient supprimés définitivement. Aucun ne porte d\'écriture : les soldes ne bougeront pas.';
		} else {
			$msg = 0 === $n
				? 'Aucun compte supprimé.'
				: $n . ' compte(s) supprimés définitivement du plan.';
		}
		if ( $refuses ) { $msg .= ' ' . count( $refuses ) . ' refusé(s) — voir le détail.'; }
		return array( 'supprimes' => $supprimes, 'refuses' => $refuses, 'message' => $msg );
	}

	/**
	 * Désactive les comptes qui ne peuvent pas être supprimés mais n'ont plus
	 * lieu d'être proposés à la saisie : doublons et comptes remplacés qui
	 * portent des écritures.
	 *
	 * Un compte à virer n'est PAS désactivé ici : tant que son solde n'a pas
	 * été transporté, le désactiver le rendrait invisible avec de l'argent
	 * dessus — exactement la situation qu'on cherche à éviter.
	 *
	 * @return array{0:int,1:string}
	 */
	public static function desactiverSoldes() {
		$a = self::analyser();
		$n = 0; $reportes = 0;
		foreach ( $a['comptes'] as $c ) {
			if ( 'a_desactiver' !== $c['verdict'] ) { continue; }
			if ( ! $c['actif'] ) { continue; }
			// Un compte au solde non nul reste actif : il doit d'abord être soldé.
			if ( class_exists( 'FKC_PlanAudit' ) ) {
				$s = FKC_PlanAudit::solde( $c['numero'] );
				if ( ! empty( $s['sens'] ) ) { $reportes++; continue; }
			}
			try { FKC_DB::q( 'UPDATE comptes SET actif=0 WHERE id=?', array( (int) $c['id'] ) ); $n++; }
			catch ( \Throwable $e ) {}
		}
		$msg = $n . ' compte(s) désactivés — ils restent consultables et le grand livre reste intact.';
		if ( $reportes ) {
			$msg .= ' ' . $reportes . ' compte(s) conservés actifs : leur solde n\'est pas nul, virez-le d\'abord.';
		}
		return array( $n, $msg );
	}
}
