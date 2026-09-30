<?php
/**
 * Audit de conformité du plan comptable d'une société.
 *
 * POURQUOI ON AUDITE AU LIEU DE CORRIGER
 *
 * Le plan livré par les versions précédentes contenait des numéros qui ne
 * correspondent pas au SYSCOHADA révisé. La tentation serait de les renuméroter
 * automatiquement. Ce serait une faute : un compte porte des écritures, des
 * lettrages, des rapprochements et des états déjà déposés. Déplacer 613000 vers
 * 622000 dans le dos du comptable modifie rétroactivement des exercices clos.
 *
 * Ce service se contente donc de VOIR et de DIRE : quels comptes divergent, ce
 * que le référentiel prévoit à la place, et combien d'écritures sont concernées.
 * L'arbitrage reste humain — et il est différent selon qu'un compte est vierge
 * (on peut le désactiver) ou chargé de trois exercices (on le garde et on
 * bascule au prochain exercice).
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_PlanAudit {

	/* ══════════════════════════════════════════════════════════════════════
	   CLASSIFICATION À QUATRE STATUTS
	   ══════════════════════════════════════════════════════════════════════ */

	/** Libellé et couleur de chaque statut, pour l'affichage. */
	public static function statutsConnus() {
		return array(
			/*
			 * CINQUIÈME ENTRÉE : « RÉGLEMENTAIRE ».
			 *
			 * Elle ne figure pas dans les quatre statuts d'arbitrage, et c'est
			 * voulu : les comptes du référentiel OHADA ne s'arbitrent pas. Mais
			 * sans elle, ils tombaient dans SPÉCIFIQUE — et la synthèse d'un
			 * plan de mille comptes annonçait « 994 spécifiques », noyant les
			 * deux comptes qui demandaient réellement une décision. Un audit
			 * qu'on ne peut pas lire ne sert à rien.
			 */
			'reglementaire' => array( 'RÉGLEMENTAIRE', '#94a3b8', 'Compte du référentiel SYSCOHADA — non arbitrable' ),
			'standard'   => array( 'STANDARD', '#4ade80', 'Peut utiliser un compte FinaKop commun' ),
			'specifique' => array( 'SPÉCIFIQUE', '#60a5fa', 'Nécessaire au métier' ),
			'analytique' => array( 'ANALYTIQUE', '#fbbf24', 'Devrait probablement être un axe plutôt qu\'un compte' ),
			'doublon'    => array( 'DOUBLON', '#f43f5e', 'Même sens qu\'un compte existant' ),
		);
	}

	/**
	 * Concepts que le socle transversal porte déjà — un pack qui les rouvre
	 * fabrique un compte de plus pour dire la même chose.
	 *
	 * Chaque entrée : motif de reconnaissance => array( numéro cible, intitulé ).
	 */
	protected static function conceptsTransversaux() {
		return array(
			'/\bcaiss/'                                  => array( '571100', 'Caisse' ),
			'/\bbanqu/'                                  => array( '521100', 'Banque' ),
			'/fournisseur.*(marchandise|approvisionn)/'  => array( '401600', 'Fournisseurs — marchandises' ),
			'/achats?\b.*marchandise/'                   => array( '601100', 'Achats de marchandises' ),
			'/ventes?\b.*marchandise/'                   => array( '701100', 'Ventes de marchandises' ),
			'/^clients?\b/'                              => array( '411100', 'Clients' ),
			'/(salaires?|appointements?)\b/'             => array( '661100', 'Rémunérations du personnel' ),
			'/\b(electricite|energie)\b/'                => array( '605200', 'Électricité' ),
			'/\beau\b/'                                  => array( '605100', 'Eau' ),
			'/\bassurances?\b/'                          => array( '625100', 'Assurances' ),
		);
	}

	/**
	 * Marqueurs d'un découpage qui relève d'un AXE, pas d'un compte.
	 *
	 * « Caisse comptoir », « Ventes — rayon alimentaire », « Stock dépôt 2 » ne
	 * décrivent pas une nature comptable différente : ils décrivent OÙ ou PAR
	 * QUEL CANAL l'opération a eu lieu. Le plan n'a pas à porter cette
	 * information — les axes analytiques la restituent sans se multiplier, et
	 * surtout sans empêcher le total.
	 */
	protected static function marqueursAxe() {
		return '/\b(n\s*\d|principale?|secondaire|annexe|succursale|agence|site|'
			. 'point de vente|comptoir|guichet|boutique|kiosque|rayon|zone|secteur|'
			. 'antenne|depot \d|salle \d|etage)\b/';
	}

	/** Réduit un intitulé à ses mots significatifs, sans accents ni mots vides. */
	protected static function mots( $libelle ) {
		$s = (string) $libelle;
		if ( function_exists( 'iconv' ) ) {
			$t = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $s );
			if ( false !== $t ) { $s = $t; }
		}
		$s = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', ' ', $s ) );
		$vides = array( 'de', 'du', 'des', 'la', 'le', 'les', 'et', 'a', 'au', 'aux',
			'sur', 'en', 'd', 'l', 'pour', 'par', 'un', 'une', 'ou' );
		$out = array();
		foreach ( explode( ' ', $s ) as $m ) {
			if ( '' !== $m && ! in_array( $m, $vides, true ) ) { $out[] = $m; }
		}
		return $out;
	}

	/** Clé de comparaison de sens : mots triés, indépendante de l'ordre. */
	protected static function sens( $libelle ) {
		$m = self::mots( $libelle );
		sort( $m );
		return implode( ' ', $m );
	}

	/**
	 * Classe un compte selon les quatre statuts.
	 *
	 * L'ORDRE DES TESTS EST LE RAISONNEMENT LUI-MÊME
	 *
	 * On cherche d'abord si le compte redit quelque chose qui existe déjà
	 * (doublon), puis s'il décrit une dimension et non une nature
	 * (analytique), puis s'il reprend un concept transversal (standard). Ce
	 * qui survit aux trois est spécifique — et c'est le seul cas où ouvrir un
	 * compte se justifie.
	 *
	 * Le statut n'est jamais qu'une PROPOSITION : un compte mouvementé ne se
	 * bascule pas parce qu'un classificateur l'a rangé quelque part. C'est
	 * pourquoi le nombre d'écritures accompagne systématiquement le verdict.
	 *
	 * @param string $numero  Numéro du compte.
	 * @param string $libelle Intitulé porté.
	 * @param array  $vus     Sens déjà rencontrés : sens => numéro.
	 * @return array{statut:string, motif:string, cible:string}
	 */
	public static function classer( $numero, $libelle, array $vus = array() ) {
		$numero = (string) $numero;
		$sens   = self::sens( $libelle );
		$mots   = implode( ' ', self::mots( $libelle ) );

		// Un compte du référentiel n'est jamais à discuter : il est la norme.
		if ( FKC_PlanSyscohada::estOfficiel( $numero ) ) {
			return array( 'statut' => 'reglementaire', 'motif' => 'compte du référentiel OHADA', 'cible' => '' );
		}
		// Ni un compte du socle transversal : c'est LUI la cible des autres.
		if ( class_exists( 'FKC_PlanMetier' )
			&& array_key_exists( $numero, FKC_PlanMetier::socleTransversal() ) ) {
			return array( 'statut' => 'specifique', 'motif' => 'compte transversal du socle FinaKop', 'cible' => '' );
		}

		/*
		 * LE RÉGIME DE TVA N'EST PAS UNE DIMENSION.
		 *
		 * « Ventes de marchandises (taxables) » ressemble à « Ventes de
		 * marchandises » au point que le classificateur le rangeait en STANDARD
		 * et proposait de les fusionner. Or le régime de taxation ne se porte
		 * pas sur un axe : il commande une ligne de la déclaration. Les réunir
		 * sur un même compte rendrait la déclaration DGI infaisable sans
		 * retraitement manuel.
		 *
		 * Ce garde-fou existait dans le script de fusion en lot ; il manquait
		 * ici — ce qui n'était sans conséquence que tant qu'aucun bouton ne
		 * suivait le verdict. Depuis que l'écran en propose un, l'oubli
		 * deviendrait destructeur.
		 */
		if ( preg_match( '/taxable|exoner|exonér|\btva\b|export|suspension/i', (string) $libelle ) ) {
			return array( 'statut' => 'specifique',
				'motif'  => 'distinction de régime fiscal : elle commande une ligne de déclaration, elle ne se porte pas sur un axe',
				'cible'  => '' );
		}

		// 1. DOUBLON — un autre numéro porte déjà exactement ce sens.
		if ( isset( $vus[ $sens ] ) && $vus[ $sens ] !== $numero ) {
			return array(
				'statut' => 'doublon',
				'motif'  => 'même sens que le compte ' . $vus[ $sens ],
				'cible'  => $vus[ $sens ],
			);
		}

		/*
		 * 2. STANDARD AVANT ANALYTIQUE — l'ordre a été inversé après essai.
		 *
		 * « Caisse comptoir » déclenche les deux règles : c'est un concept
		 * commun ET un découpage par lieu. Rangé en ANALYTIQUE, le conseil
		 * était « mettez ça sur un axe » — vrai, mais il laissait l'utilisateur
		 * sans compte où imputer. Rangé en STANDARD, le conseil devient
		 * « imputez sur 571100, et portez le comptoir sur un axe » : les deux
		 * moitiés, dans l'ordre où on les exécute.
		 */
		$dimension = (bool) preg_match( self::marqueursAxe(), $mots );
		foreach ( self::conceptsTransversaux() as $motif => $cible ) {
			if ( preg_match( $motif, $mots ) ) {
				if ( $cible[0] === $numero ) { break; }
				/*
				 * LA CIBLE DOIT PARTAGER LA MÊME RACINE À TROIS CHIFFRES.
				 *
				 * Sans ce garde-fou, le classificateur commettait deux erreurs
				 * qui auraient déplacé de l'argent :
				 *
				 *   411116 « Assurances et mutuelles — tiers payant » était
				 *   dirigé vers 625100 « Assurances ». Or 4114 est une CRÉANCE
				 *   sur un organisme payeur, 6251 une CHARGE de prime. Le
				 *   libellé contient « assurances », le sens comptable est
				 *   l'inverse — et la bascule aurait transformé un actif en
				 *   charge.
				 *
				 *   419210 « Clients — acomptes sur projets » était dirigé vers
				 *   411100 « Clients ». Même classe, sens opposé : 419 est un
				 *   compte CRÉDITEUR (ce que le client a versé d'avance), 411
				 *   un compte débiteur. Les confondre efface l'avance.
				 *
				 * Le mot déclencheur se trouve dans l'intitulé ; la nature, elle,
				 * est dans le NUMÉRO. C'est le numéro qui tranche.
				 */
				if ( substr( $numero, 0, 3 ) !== substr( $cible[0], 0, 3 ) ) { continue; }
				return array(
					'statut' => 'standard',
					'motif'  => 'concept commun : ' . $cible[0] . ' « ' . $cible[1] . ' » le porte déjà'
						. ( $dimension ? ', la distinction se porte sur un axe analytique' : '' ),
					'cible'  => $cible[0],
				);
			}
		}

		// 3. ANALYTIQUE — une dimension déguisée en compte, sans concept commun
		//    identifiable derrière.
		if ( $dimension ) {
			return array(
				'statut' => 'analytique',
				'motif'  => 'découpage par lieu, rayon ou canal : un axe le porte sans multiplier le plan',
				'cible'  => '',
			);
		}

		return array( 'statut' => 'specifique', 'motif' => 'nécessaire au métier', 'cible' => '' );
	}

	/**
	 * Classe tout le plan de la société active.
	 *
	 * @return array{par_statut: array, comptes: array, total: int}
	 */
	public static function classerPlan() {
		$out = array(
			'par_statut' => array( 'reglementaire' => 0, 'standard' => 0, 'specifique' => 0,
				'analytique' => 0, 'doublon' => 0 ),
			'comptes' => array(), 'total' => 0,
			// Comptes réellement soumis à arbitrage : le dénominateur qui a du sens.
			'a_arbitrer' => 0,
			// Et parmi eux, ceux sur lesquels un geste est réellement possible.
			'actionnables' => 0,
		);
		try {
			$comptes = FKC_DB::q( 'SELECT numero, libelle, origine FROM comptes WHERE actif=1 ORDER BY numero' )->fetchAll();
		} catch ( \Throwable $e ) { return $out; }

		/*
		 * Les comptes officiels sont enregistrés EN PREMIER comme sens de
		 * référence : ainsi un compte de pack qui redit « Caisse » est le
		 * doublon, et non l'inverse. Sans cet ordre, le classement dépendrait
		 * de l'ordre alphabétique des numéros — et désignerait parfois le
		 * compte réglementaire comme le fautif.
		 */
		$vus = array();
		foreach ( $comptes as $c ) {
			if ( FKC_PlanSyscohada::estOfficiel( (string) $c['numero'] ) ) {
				$s = self::sens( $c['libelle'] );
				if ( ! isset( $vus[ $s ] ) ) { $vus[ $s ] = (string) $c['numero']; }
			}
		}
		foreach ( $comptes as $c ) {
			$num = (string) $c['numero'];
			$r   = self::classer( $num, (string) $c['libelle'], $vus );
			$s   = self::sens( $c['libelle'] );
			if ( ! isset( $vus[ $s ] ) ) { $vus[ $s ] = $num; }
			/*
			 * Le nombre d'écritures accompagne le verdict : c'est lui qui décide
			 * du geste possible. Vierge → désactivation immédiate. Mouvementé →
			 * virement de solde, daté et écrit. Sans cette information, l'écran
			 * proposerait des boutons qui échoueraient une fois sur deux.
			 */
			if ( in_array( $r['statut'], array( 'doublon', 'standard' ), true ) && ! empty( $r['cible'] ) ) {
				$r['ecritures']  = self::compterEcritures( $num );
				$r['actionnable'] = true;
			} else {
				$r['ecritures']  = 0;
				$r['actionnable'] = false;
			}
			$out['par_statut'][ $r['statut'] ]++;
			if ( 'reglementaire' !== $r['statut'] ) { $out['a_arbitrer']++; }
			if ( ! empty( $r['actionnable'] ) ) { $out['actionnables']++; }
			$out['comptes'][ $num ] = $r;
			$out['total']++;
		}
		return $out;
	}

	/**
	 * Corrige EN LOT tous les comptes non conformes qui peuvent l'être.
	 *
	 * CE QUE « SUPPRIMER » VEUT DIRE ICI, ET CE QU'IL NE VEUT PAS DIRE
	 *
	 * Un compte non conforme vierge est désactivé, pas effacé : la ligne reste
	 * en base, inactive, et son numéro ne peut plus être réattribué à autre
	 * chose. C'est ce qui permet, six mois plus tard, de comprendre ce qu'on a
	 * fait — un plan comptable dont on efface les traces devient invérifiable.
	 *
	 * Un compte non conforme qui PORTE DES ÉCRITURES n'est jamais touché, et
	 * aucune option ne permet de forcer. Le supprimer romprait le lien entre la
	 * ligne d'écriture et son compte : la balance ne se justifierait plus, le
	 * grand livre afficherait des montants sans intitulé, et la liasse
	 * n'agrégerait plus ces sommes nulle part. Il faut alors un VIREMENT DE
	 * COMPTE À COMPTE, daté et écrit — une opération comptable, décidée par le
	 * comptable, qui laisse une trace. C'est précisément ce qu'un bouton ne
	 * doit pas faire à sa place.
	 *
	 * @return array{corriges: array, refuses: array, echecs: array}
	 */
	public static function corrigerTout() {
		$out = array( 'corriges' => array(), 'refuses' => array(), 'echecs' => array() );
		$a = self::analyser();
		foreach ( $a['divergences'] as $d ) {
			if ( (int) $d['ecritures'] > 0 ) {
				$out['refuses'][] = array(
					'numero' => $d['numero'], 'libelle' => $d['libelle'],
					'correct' => $d['correct'], 'ecritures' => (int) $d['ecritures'],
				);
				continue;
			}
			list( $ok, $msg ) = self::corrigerSiVierge( $d['numero'] );
			if ( $ok ) {
				$out['corriges'][] = array( 'numero' => $d['numero'], 'correct' => $d['correct'] );
			} else {
				$out['echecs'][] = array( 'numero' => $d['numero'], 'message' => $msg );
			}
		}
		return $out;
	}

	/**
	 * Résumé lisible d'une correction en lot.
	 *
	 * Le message dit ce qui a été fait ET ce qui ne l'a pas été. Annoncer
	 * « 12 comptes corrigés » en taisant les 3 refusés laisserait croire le
	 * plan assaini alors que les cas les plus lourds — ceux qui portent des
	 * écritures — restent entiers.
	 */
	public static function resumerCorrection( array $r ) {
		$parts = array();
		$n = count( $r['corriges'] );
		$parts[] = $n . ' compte' . ( $n > 1 ? 's' : '' ) . ' non conforme' . ( $n > 1 ? 's' : '' )
			. ' désactivé' . ( $n > 1 ? 's' : '' ) . ' (ils étaient vierges)';
		if ( $r['refuses'] ) {
			$lignes = array();
			foreach ( $r['refuses'] as $x ) {
				$lignes[] = $x['numero'] . ' (' . $x['ecritures'] . ' écriture' . ( $x['ecritures'] > 1 ? 's' : '' ) . ' → ' . $x['correct'] . ')';
			}
			$parts[] = count( $r['refuses'] ) . ' conservé' . ( count( $r['refuses'] ) > 1 ? 's' : '' )
				. ' car mouvementé' . ( count( $r['refuses'] ) > 1 ? 's' : '' )
				. ' : ' . implode( ', ', $lignes )
				. ' — ceux-là demandent un virement de compte à compte, daté et écrit';
		}
		if ( $r['echecs'] ) { $parts[] = count( $r['echecs'] ) . ' en échec'; }
		return implode( ' · ', $parts );
	}

	/* ══════════════════════════════════════════════════════════════════════
	   BASCULE ASSISTÉE DES COMPTES MOUVEMENTÉS
	   ══════════════════════════════════════════════════════════════════════ */

	/**
	 * Solde d'un compte : total débit, total crédit, sens et montant.
	 *
	 * Lit les écritures COMPTABILISÉES uniquement. Un brouillon n'est pas encore
	 * une écriture : le virer reviendrait à solder ce qui n'existe pas, et le
	 * brouillon publié plus tard remouvementerait un compte qu'on croit clos.
	 */
	public static function solde( $numero ) {
		$out = array( 'debit' => 0.0, 'credit' => 0.0, 'sens' => null, 'montant' => 0.0, 'lignes' => 0 );
		try {
			$r = FKC_DB::q(
				'SELECT COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c, COUNT(*) n
				 FROM ecriture_lignes l
				 JOIN comptes cp ON cp.id = l.compte_id
				 WHERE cp.numero = ?',
				array( FKC_PlanSyscohada::normaliser( $numero ) )
			)->fetch();
		} catch ( \Throwable $e ) { return $out; }
		if ( ! $r ) { return $out; }
		$out['debit']  = (float) $r['d'];
		$out['credit'] = (float) $r['c'];
		$out['lignes'] = (int) $r['n'];
		$ecart = $out['debit'] - $out['credit'];
		if ( fkc_cents( abs( $ecart ) ) > 0 ) {
			$out['sens']    = $ecart > 0 ? 'debit' : 'credit';
			$out['montant'] = abs( $ecart );
		}
		return $out;
	}

	/**
	 * Prépare — SANS RIEN ÉCRIRE — le virement d'un compte non conforme.
	 *
	 * POURQUOI UNE PRÉPARATION SÉPARÉE DE L'EXÉCUTION
	 *
	 * Le comptable doit voir l'écriture AVANT qu'elle existe : son sens, son
	 * montant, sa date, les deux comptes touchés. Une opération qui déplace un
	 * solde sans qu'on ait pu la relire n'est pas une aide, c'est un pari.
	 *
	 * TROIS CAS, TROIS RÉPONSES DIFFÉRENTES
	 *
	 *   — solde nul mais écritures présentes : rien à virer. Le compte a servi
	 *     puis a été soldé ; il suffit de le désactiver, et l'historique reste
	 *     lisible là où il est.
	 *   — solde non nul : une écriture de virement, dans le sens qui solde le
	 *     compte fautif et reporte la somme sur la cible.
	 *   — aucune écriture : ce n'est pas un cas de virement, corrigerSiVierge()
	 *     s'en charge.
	 *
	 * @param string      $numero Compte non conforme.
	 * @param string|null $date   Date de l'écriture ; aujourd'hui par défaut.
	 * @return array{ok:bool, message:string, action:string, ...}
	 */
	public static function preparerVirement( $numero, $date = null ) {
		$numero = FKC_PlanSyscohada::normaliser( $numero );
		// Même élargissement que corrigerSiVierge() : divergence connue OU
		// compte classé doublon / reprise d'un compte commun.
		$d = self::cibleDe( $numero );
		if ( ! $d ) {
			return array( 'ok' => false, 'action' => 'aucune',
				'message' => 'Aucune cible pour ce compte : il n\'est ni une divergence connue, '
					. 'ni classé comme doublon ou reprise d\'un compte commun.' );
		}
		$connues = array( $numero => array( '', $d['cible'], $d['raison'] ) );
		$cible = $d['cible'];
		if ( $cible === $numero ) {
			return array( 'ok' => false, 'action' => 'aucune', 'message' => 'La cible est le compte lui-même.' );
		}
		$s    = self::solde( $numero );
		$date = $date ?: date( 'Y-m-d' );

		if ( 0 === $s['lignes'] ) {
			return array( 'ok' => false, 'action' => 'vierge',
				'message' => 'Ce compte est vierge : il se désactive directement, sans écriture.' );
		}
		/*
		 * La période close est vérifiée ICI, pas seulement au moment d'écrire.
		 * Proposer une écriture que FKC_Ecriture refusera ensuite ferait
		 * remplir un formulaire pour rien.
		 */
		if ( class_exists( 'FKC_Cloture' ) && FKC_Cloture::isLocked( $date ) ) {
			return array( 'ok' => false, 'action' => 'bloquee',
				'message' => 'La période du ' . $date . ' est clôturée : choisissez une date ouverte.' );
		}
		if ( null === $s['sens'] ) {
			return array(
				'ok' => true, 'action' => 'desactiver', 'numero' => $numero, 'cible' => $cible,
				'solde' => $s, 'date' => $date,
				'message' => 'Compte déjà soldé (' . $s['lignes'] . ' ligne(s), solde nul) : '
					. 'aucune écriture n\'est nécessaire, il peut être désactivé.',
			);
		}

		$libelle = 'Virement de compte — ' . $numero . ' vers ' . $cible . ' (mise en conformité SYSCOHADA)';
		// Solde débiteur : on crédite le fautif et on débite la cible. Et l'inverse.
		$lignes = ( 'debit' === $s['sens'] )
			? array(
				array( 'numero' => $cible,  'debit' => $s['montant'], 'credit' => 0 ),
				array( 'numero' => $numero, 'debit' => 0, 'credit' => $s['montant'] ),
			)
			: array(
				array( 'numero' => $numero, 'debit' => $s['montant'], 'credit' => 0 ),
				array( 'numero' => $cible,  'debit' => 0, 'credit' => $s['montant'] ),
			);

		return array(
			'ok' => true, 'action' => 'virer', 'numero' => $numero, 'cible' => $cible,
			'cible_libelle' => self::libelleCible( $cible ), 'solde' => $s,
			'date' => $date, 'libelle' => $libelle, 'lignes' => $lignes,
			'raison' => $connues[ $numero ][2],
			'message' => 'Solde ' . ( 'debit' === $s['sens'] ? 'débiteur' : 'créditeur' ) . ' de '
				. fkc_money( $s['montant'] ) . ' F à virer vers ' . $cible . '.',
		);
	}

	/**
	 * Exécute le virement préparé, puis désactive le compte d'origine.
	 *
	 * L'ÉCRITURE PASSE EN COMPTABILITÉ, PAS AU BROUILLARD.
	 *
	 * Une mise en conformité du plan qui resterait au brouillard laisserait le
	 * compte fautif porter son solde jusqu'à publication — et le compte serait
	 * désactivé entre-temps, donc invisible. L'option force le passage direct :
	 * c'est une opération technique de reprise, du même ordre qu'un import.
	 *
	 * La désactivation n'a lieu QUE si l'écriture est acceptée. Dans le cas
	 * contraire le compte reste tel quel, avec son solde : mieux vaut un plan
	 * encore fautif qu'un compte désactivé portant un solde que plus personne
	 * ne voit.
	 */
	public static function executerVirement( $numero, $date = null, $journal = 'OD' ) {
		$p = self::preparerVirement( $numero, $date );
		if ( empty( $p['ok'] ) ) { return array( false, $p['message'] ); }

		if ( 'desactiver' === $p['action'] ) {
			try { FKC_DB::q( 'UPDATE comptes SET actif=0 WHERE numero=?', array( $p['numero'] ) ); }
			catch ( \Throwable $e ) { return array( false, 'Désactivation impossible : ' . $e->getMessage() ); }
			return array( true, 'Compte ' . $p['numero'] . ' désactivé : il était déjà soldé.' );
		}

		// La cible doit exister avant qu'on impute dessus.
		if ( ! self::existe( $p['cible'] ) ) {
			try {
				$lib = FKC_PlanSyscohada::libelle( $p['cible'] ) ?: $p['cible'];
				FKC_DB::q(
					'INSERT INTO comptes(numero,libelle,classe,type,actif,origine,parent,sens,lettrable,verrouille)
					 VALUES(?,?,?,?,1,?,?,?,?,?)',
					array( $p['cible'], $lib, (int) substr( $p['cible'], 0, 1 ), 'detail', 'ohada',
						FKC_PlanSyscohada::parentOfficiel( $p['cible'] ),
						FKC_PlanSyscohada::sensNaturel( $p['cible'] ),
						FKC_PlanSyscohada::lettrable( $p['cible'] ) ? 1 : 0,
						FKC_PlanSyscohada::estOfficiel( $p['cible'] ) ? 1 : 0 )
				);
			} catch ( \Throwable $e ) { return array( false, 'Création du compte cible impossible : ' . $e->getMessage() ); }
		}

		$ids = array();
		foreach ( array( $p['numero'], $p['cible'] ) as $n ) {
			$id = (int) FKC_DB::q( 'SELECT id FROM comptes WHERE numero=?', array( $n ) )->fetchColumn();
			if ( ! $id ) { return array( false, 'Compte ' . $n . ' introuvable.' ); }
			$ids[ $n ] = $id;
		}
		$jid = 0;
		try { $jid = (int) FKC_DB::q( 'SELECT id FROM journaux WHERE code=? LIMIT 1', array( $journal ) )->fetchColumn(); }
		catch ( \Throwable $e ) { $jid = 0; }
		if ( ! $jid ) { return array( false, 'Journal ' . $journal . ' introuvable.' ); }

		$lignes = array();
		foreach ( $p['lignes'] as $l ) {
			$lignes[] = array(
				'compte_id' => $ids[ $l['numero'] ], 'libelle' => $p['libelle'],
				'debit' => (float) $l['debit'], 'credit' => (float) $l['credit'],
			);
		}
		list( $ok, $msg ) = FKC_Ecriture::enregistrer(
			array( 'journal_id' => $jid, 'date_ecriture' => $p['date'],
				'libelle' => $p['libelle'], 'piece' => 'CONF-' . $p['numero'],
				'reference' => 'Mise en conformité du plan comptable' ),
			$lignes,
			/*
			 * PUBLICATION DIRECTE ASSUMÉE (revue en 1.542.0).
			 *
			 * Le solde d'un compte que l'on désactive est transféré vers un
			 * compte cible, puis le compte est fermé dans la foulée. Différer
			 * l'écriture laisserait un compte désactivé portant encore un
			 * solde : le plan comptable et les journaux se contrediraient
			 * jusqu'à la validation.
			 *
			 * L'écriture est un simple virement de solde entre deux comptes,
			 * décidé et daté par l'utilisateur à l'écran, sans effet sur le
			 * résultat.
			 */
			array( 'brouillard' => false, 'source' => 'manuelle' )
		);
		if ( ! $ok ) { return array( false, 'Écriture refusée : ' . $msg ); }

		try { FKC_DB::q( 'UPDATE comptes SET actif=0 WHERE numero=?', array( $p['numero'] ) ); }
		catch ( \Throwable $e ) { /* l'écriture est passée : on ne la défait pas */ }

		if ( class_exists( 'FKC_Security' ) ) {
			FKC_Security::audit( 'plan_virement_conformite',
				$p['numero'] . ' → ' . $p['cible'] . ' pour ' . fkc_money( $p['solde']['montant'] ) . ' F' );
		}
		return array( true, 'Solde de ' . fkc_money( $p['solde']['montant'] ) . ' F viré de '
			. $p['numero'] . ' vers ' . $p['cible'] . ' ; le compte d\'origine est désactivé.' );
	}

	/**
	 * Divergences connues introduites par les plans FinaKop antérieurs.
	 *
	 * numéro fautif => array( intitulé tel que livré, numéro correct, raison )
	 */
	public static function divergencesConnues() {
		return array(
			'613000' => array( 'Locations et charges locatives', '622000', '613 est « Transports pour le compte de tiers » ; les locations sont en 622.' ),
			'615000' => array( 'Primes d\'assurance', '625000', '615 n\'existe pas ; les primes d\'assurance sont en 625.' ),
			'621000' => array( 'Personnel extérieur et intérimaire', '637000', '621 est « Sous-traitance générale » ; le personnel extérieur est en 637.' ),
			'623000' => array( 'Publicité, publications, relations publiques', '627000', '623 est « Redevances de location acquisition » — une charge de crédit-bail, pas de la publicité.' ),
			'626000' => array( 'Frais postaux et télécommunications', '628000', '626 est « Études, recherches et documentation » ; les télécoms sont en 628.' ),
			'627000' => array( 'Frais bancaires et services financiers', '631000', '627 est « Publicité » ; les frais bancaires sont en 631.' ),
			'104000' => array( 'Primes liées au capital social', '105000', '104 est « Compte de l\'exploitant » ; les primes sont en 105.' ),
			'105000' => array( 'Écarts de réévaluation', '106000', '105 est « Primes liées aux capitaux propres » ; les écarts de réévaluation sont en 106.' ),
			'119000' => array( 'Report à nouveau (solde créditeur)', '121000', '119 n\'existe pas au SYSCOHADA ; le report créditeur est en 121.' ),
			'131000' => array( 'Subventions d\'équipement', '141000', '131 est « Résultat net : bénéfice » ; les subventions d\'équipement sont en 141.' ),
			'151000' => array( 'Provisions pour risques', '191000', '151 est « Amortissements dérogatoires » ; les provisions pour litiges sont en 191.' ),
			'166000' => array( 'Dettes de crédit-bail', '173000', '166 est « Intérêts courus » ; les dettes de location acquisition sont en 172/173.' ),
			'201000' => array( 'Frais d\'établissement', '632500', 'Le compte 20 « charges immobilisées » a été supprimé par le révisé : frais d\'actes, notaire et immatriculation passent en charges (6325).' ),
			'202000' => array( 'Charges à répartir sur plusieurs exercices', '638800', 'Supprimé par le SYSCOHADA révisé : à comptabiliser en charges de l\'exercice.' ),
			'211000' => array( 'Noms de domaine et actifs numériques stratégiques', '218800', '211 est « Frais de développement » ; les autres droits incorporels sont en 218.' ),
			'205100' => array( 'Droits, licences et catalogues acquis', '217400', 'Le compte 20 n\'existe plus en classe 2 ; les catalogues relèvent de 217 « Investissements de création ».' ),
			'421100' => array( 'Artistes — avances recouvrables', '409110', '4211 est « Personnel, avances » : une avance à un artiste sous contrat n\'est pas une avance de salaire, et la faire figurer là gonfle les états sociaux.' ),
			'421200' => array( 'Artistes — royalties dues', '401160', '4212 est « Personnel, acomptes » : les royalties contractuelles ne sont pas une dette de personnel et n\'ont rien à faire dans la masse salariale.' ),
			'205200' => array( 'Masters et enregistrements originaux', '217100', 'Le compte 217 vise explicitement les producteurs de phonogrammes : c\'est le compte des masters.' ),
			'205300' => array( 'Catalogue éditorial (œuvres)', '217200', 'Même motif : 217 « Investissements de création ».' ),
			'205400' => array( 'Contrats artistes capitalisés', '218200', 'Les coûts d\'obtention de contrat sont en 2182.' ),
			/*
			 * MOBILE MONEY — CIBLES CORRIGÉES (1.748.0).
			 *
			 * Ces quatre lignes dirigeaient les comptes vers 551000, 552000,
			 * 553000 et 554000. Trois erreurs s'y cumulaient :
			 *
			 *  — 551, 553 et 554 sont des comptes de CARTES (carburant, péage,
			 *    autres) au SYSCOHADA révisé. Y loger Wave ou Moov, c'est
			 *    exactement la faute que le reste de cette table dénonce ;
			 *  — 552000 est le compte PÈRE des quatre subdivisions, celui qui
			 *    les totalise. Écrire dessus le met au même rang que la somme
			 *    de ses fils — la dérive déjà corrigée sur 411000 ;
			 *  — depuis 1.747.0, le socle ne crée plus 551000-554000. L'audit
			 *    proposait donc de virer un solde vers un compte que plus rien
			 *    ne fabrique.
			 *
			 * Les cibles sont désormais celles du socle (FKC_PlanMetier) et de
			 * FKC_MoyenPaiement, qui y postent réellement les encaissements.
			 */
			'532100' => array( 'Mobile Money — MTN MoMo', '552300', 'Le révisé a créé le compte 55 pour la monnaie électronique ; 53 vise les établissements financiers. La subdivision MTN du socle est 552300.' ),
			'532200' => array( 'Mobile Money — Orange Money', '552200', 'Idem : monnaie électronique en 55, subdivision Orange Money.' ),
			'532300' => array( 'Mobile Money — Moov Money', '552400', 'Idem : monnaie électronique en 55, subdivision Moov Money.' ),
			'532400' => array( 'Mobile Money — Wave', '552100', 'Idem : monnaie électronique en 55, subdivision Wave.' ),
			/*
			 * 418100 « Clients, factures à établir » est un numéro RÉSERVÉ.
			 * Les versions 1.751.0 à 1.754.0 y logeaient la créance sur les
			 * distributeurs de royalties. Les soldes déjà passés dessus ne
			 * sont pas perdus — ils sont sur un compte réglementaire au mauvais
			 * intitulé — et se virent vers la subdivision correcte.
			 */
			'418100' => array( 'Distributeurs — royalties à encaisser', '418140', "418100 est la forme normalisée de « 4181 Clients, factures à établir », réservée par le référentiel : une créance sur distributeur doit vivre dans une subdivision propre." ),
			// Comptes de cartes détournés par les amorces antérieures à 1.747.0.
			'551000' => array( 'Mobile Money - Wave', '552100', '551 est « Cartes de carburant » : un portefeuille Wave n\'y a pas sa place. La subdivision du socle est 552100.' ),
			'553000' => array( 'Mobile Money - MTN Money', '552300', '553 est un compte de cartes ; la subdivision MTN du socle est 552300.' ),
			'554000' => array( 'Mobile Money - Moov Money', '552400', '554 est un compte de cartes ; la subdivision Moov du socle est 552400.' ),
			'581000' => array( 'Virements internes', '585000', '581 est « Régies d\'avances » ; les virements de fonds sont en 585.' ),
			'812100' => array( 'Charges HAO — annulation de concerts', '831000', '81 est « Valeurs comptables des cessions d\'immobilisations » ; une annulation d\'événement est une charge H.A.O. (83).' ),
			'443000' => array( 'État — TVA à décaisser', '444100', '443 est « TVA facturée » ; la TVA due est en 4441.' ),
			'442200' => array( 'TEE — impôt forfaitaire sur chiffre d\'affaires dû', '446000', '442 vise les autres impôts et taxes ; la taxe sur le chiffre d\'affaires relève de 446.' ),
		);
	}

	/**
	 * Analyse le plan de la société active.
	 *
	 * @return array{divergences: array, orphelins: array, manquants: int, total: int}
	 */
	public static function analyser() {
		$out = array( 'divergences' => array(), 'orphelins' => array(), 'manquants' => 0, 'total' => 0, 'ohada' => 0, 'metier' => 0, 'societe' => 0 );
		try {
			/*
			 * LES COMPTES DÉSACTIVÉS SORTENT DE L'AUDIT.
			 *
			 * analyser() lisait toute la table. Un compte non conforme qu'on
			 * venait de basculer restait donc signalé : l'utilisateur cliquait
			 * « Basculer », l'opération réussissait, et l'avertissement ne
			 * bougeait pas. Sur une correction en lot, trois comptes traités
			 * laissaient l'écran afficher les cinq d'origine — de quoi croire
			 * que le bouton ne fait rien.
			 *
			 * Un compte inactif ne reçoit plus d'écriture et ne fausse plus
			 * aucun état : il n'a rien à faire dans une liste de choses à
			 * corriger. Il reste consultable dans le plan, marqué inactif, ce
			 * qui préserve la trace.
			 */
			$comptes = FKC_DB::q(
				'SELECT numero, libelle, origine, parent FROM comptes WHERE actif=1 ORDER BY numero'
			)->fetchAll();
		} catch ( \Throwable $e ) { return $out; }

		$out['total'] = count( $comptes );
		$connues = self::divergencesConnues();
		/*
		 * RÉFÉRENTIEL DU DOSSIER (1.875.3). Un dossier CIMA ou SYCEBNL se
		 * contrôle contre SA nomenclature : 801500 est rattaché au 80
		 * « Exploitation générale » du CIMA, orphelin au SYSCOHADA. Les
		 * divergences connues, relevées sur le SYSCOHADA, ne s'appliquent pas
		 * aux rubriques que le référentiel redéfinit.
		 */
		$ref = class_exists( 'FKC_Referentiel' ) ? FKC_Referentiel::actif() : 'syscohada';
		$propres = class_exists( 'FKC_Referentiel' ) ? FKC_Referentiel::rubriquesPropres( $ref ) : array();
		$dansPropres = function ( $n ) use ( $propres ) {
			foreach ( $propres as $r ) { if ( 0 === strpos( (string) $n, (string) $r ) ) { return true; } }
			return false;
		};
		$presents = array();

		foreach ( $comptes as $c ) {
			$num = (string) $c['numero'];
			$presents[ $num ] = true;
			$org = (string) ( $c['origine'] ?? 'societe' );
			if ( 'ohada' === $org ) { $out['ohada']++; }
			elseif ( 'finakop' === $org ) { $out['metier']++; }
			else { $out['societe']++; }

			if ( isset( $connues[ $num ] ) && ! $dansPropres( $num ) && self::porteEncoreLErreur( $num, (string) $c['libelle'] ) ) {
				$d = $connues[ $num ];
				$out['divergences'][] = array(
					'numero' => $num,
					'libelle' => (string) $c['libelle'],
					'correct' => $d[1],
					'correct_libelle' => self::libelleCible( $d[1] ),
					'raison' => $d[2],
					'ecritures' => self::compterEcritures( $num ),
					'cible_existe' => (bool) self::existe( $d[1] ),
				);
				continue;
			}

			// Orphelin : ni officiel, ni rattachable à un compte officiel.
			$officiel = 'syscohada' === $ref ? FKC_PlanSyscohada::estOfficiel( $num ) : FKC_Referentiel::estOfficielDans( $num, $ref );
			$parentOk = 'syscohada' === $ref ? FKC_PlanSyscohada::parentOfficiel( $num ) : FKC_Referentiel::parentOfficielDans( $num, $ref );
			if ( ! $officiel && ! $parentOk ) {
				$out['orphelins'][] = array(
					'numero' => $num, 'libelle' => (string) $c['libelle'],
					'ecritures' => self::compterEcritures( $num ),
				);
			}
		}

		$idxRef = ( 'syscohada' !== $ref && class_exists( 'FKC_Referentiel' ) ) ? FKC_Referentiel::indexNomenclature( $ref ) : FKC_PlanSyscohada::index();
		foreach ( array_keys( $idxRef ) as $num ) {
			if ( ! isset( $presents[ (string) $num ] ) ) { $out['manquants']++; }
		}
		return $out;
	}

	/**
	 * Ce compte porte-t-il ENCORE le sens fautif ?
	 *
	 * Le premier jet de cet audit signalait sur le seul NUMÉRO : une fois
	 * 627000 réaligné sur « Publicité » par le générateur, il continuait donc
	 * de figurer parmi les non-conformes. Une alerte qui persiste après
	 * correction apprend vite à l'utilisateur à ignorer toutes les alertes.
	 *
	 * Le bon critère n'est pas le numéro mais le SENS PORTÉ :
	 *   — un numéro qui existe au référentiel n'est fautif que si son intitulé
	 *     s'écarte de l'intitulé réglementaire (il désigne alors autre chose
	 *     que ce que la liasse attend à cet endroit) ;
	 *   — un numéro absent du référentiel (205x, 532x, 812100…) est fautif par
	 *     sa seule présence : il ne remonte nulle part.
	 */
	/**
	 * Intitulé de la cible, référentiel OU bibliothèque métier.
	 *
	 * Plusieurs cibles ne sont pas des comptes officiels mais des
	 * subdivisions FinaKop — 217100 « Masters », 551000 « Wave ». Ne
	 * consulter que le niveau 1 laissait la colonne vide, et l'utilisateur
	 * devait deviner vers quoi on lui proposait de basculer.
	 */
	protected static function libelleCible( $numero ) {
		$l = FKC_PlanSyscohada::libelle( $numero );
		if ( $l ) { return $l; }
		if ( class_exists( 'FKC_PlanMetier' ) ) {
			$libs = FKC_PlanMetier::bibliotheque();
			array_unshift( $libs, FKC_PlanMetier::socleCommun() );
			foreach ( $libs as $lib ) {
				if ( isset( $lib[ $numero ] ) ) { return (string) $lib[ $numero ]; }
			}
		}
		return '';
	}

	protected static function porteEncoreLErreur( $numero, $libelle ) {
		$officiel = FKC_PlanSyscohada::libelle( $numero );
		if ( null === $officiel ) { return true; }
		return trim( (string) $libelle ) !== $officiel;
	}

	protected static function existe( $numero ) {
		try { return (bool) FKC_DB::q( 'SELECT 1 FROM comptes WHERE numero=?', array( (string) $numero ) )->fetchColumn(); }
		catch ( \Throwable $e ) { return false; }
	}

	/** Nombre de lignes d'écriture portées par un compte (0 = compte vierge). */
	public static function compterEcritures( $numero ) {
		try {
			return (int) FKC_DB::q(
				'SELECT COUNT(*) FROM ecriture_lignes el JOIN comptes c ON c.id = el.compte_id WHERE c.numero = ?',
				array( (string) $numero )
			)->fetchColumn();
		} catch ( \Throwable $e ) { return 0; }
	}

	/**
	 * Désactive un compte divergent VIERGE et s'assure que sa cible existe.
	 *
	 * Refuse net dès qu'une écriture existe : la seule opération sûre sur un
	 * compte mouvementé est un virement d'écritures décidé et daté par le
	 * comptable, pas une bascule automatique.
	 *
	 * @return array{0:bool,1:string}
	 */
	/**
	 * Vers quel compte ce compte-ci devrait-il être basculé ?
	 *
	 * DEUX SOURCES, ET C'EST LE DÉFAUT QUE CETTE MÉTHODE RÉPARE.
	 *
	 * La correction ne consultait que divergencesConnues() — une liste FERMÉE
	 * d'une quarantaine de numéros hérités des anciens plans. Or l'audit, lui,
	 * classe TOUS les comptes du dossier et en range en DOUBLON ou en STANDARD
	 * bien au-delà de cette liste : « Caisse boutique » à côté de « Caisse »,
	 * « Clients — officine » à côté de « Clients ».
	 *
	 * Ces comptes-là étaient donc signalés à l'écran sans qu'aucun bouton ne
	 * puisse les traiter. Signaler un problème et n'offrir aucun geste est pire
	 * que se taire : l'utilisateur voit l'avertissement revenir à chaque
	 * ouverture et finit par ne plus lire aucune alerte.
	 *
	 * On interroge donc les deux sources : la liste des divergences connues
	 * d'abord, qui porte un motif rédigé et un numéro vérifié à la main ; le
	 * classificateur ensuite, qui couvre le reste.
	 *
	 * @return array{cible:string, raison:string}|null
	 */
	public static function cibleDe( $numero ) {
		$numero  = FKC_PlanSyscohada::normaliser( $numero );
		$connues = self::divergencesConnues();
		if ( isset( $connues[ $numero ] ) ) {
			return array( 'cible' => $connues[ $numero ][1], 'raison' => $connues[ $numero ][2] );
		}
		/*
		 * Le classificateur a besoin des sens déjà rencontrés pour désigner un
		 * doublon. classerPlan() les construit dans le bon ordre — référentiel
		 * d'abord — de sorte que c'est bien le compte de pack qui est désigné
		 * comme le doublon, et non le compte réglementaire.
		 */
		return self::cibleDans( $numero, self::classerPlan() );
	}

	/**
	 * Cible d'un compte dans un classement DÉJÀ calculé (1.876.0).
	 *
	 * cibleDe() recalculait classerPlan() — tout le plan, plus un décompte
	 * d'écritures par compte — à CHAQUE appel. FKC_PlanPurge::analyser()
	 * l'appelle pour chaque compte : 1 058 classements complets, soit 23 s
	 * pour afficher l'écran de purge. L'analyse calcule désormais le
	 * classement une fois et interroge celui-ci. Résultat identique : même
	 * divergences connues d'abord, même lecture du classement ensuite.
	 */
	public static function cibleDans( $numero, array $plan ) {
		$numero  = FKC_PlanSyscohada::normaliser( $numero );
		$connues = self::divergencesConnues();
		if ( isset( $connues[ $numero ] ) ) {
			return array( 'cible' => $connues[ $numero ][1], 'raison' => $connues[ $numero ][2] );
		}
		if ( ! isset( $plan['comptes'][ $numero ] ) ) { return null; }
		$r = $plan['comptes'][ $numero ];
		if ( ! in_array( $r['statut'], array( 'doublon', 'standard' ), true ) ) { return null; }
		if ( empty( $r['cible'] ) ) { return null; }
		return array( 'cible' => $r['cible'], 'raison' => $r['motif'] );
	}

	public static function corrigerSiVierge( $numero ) {
		$numero = FKC_PlanSyscohada::normaliser( $numero );
		$d = self::cibleDe( $numero );
		if ( ! $d ) {
			return array( false, 'Aucune cible pour ce compte : il n\'est ni une divergence connue, '
				. 'ni classé comme doublon ou reprise d\'un compte commun.' );
		}
		$connues = array( $numero => array( '', $d['cible'], $d['raison'] ) );
		$n = self::compterEcritures( $numero );
		if ( $n > 0 ) {
			return array( false, 'Impossible : ce compte porte ' . $n . ' ligne(s) d\'écriture. '
				. 'Le corriger effacerait la piste d\'audit ; il faut un virement daté, décidé par le comptable.' );
		}
		$cible = $connues[ $numero ][1];
		try {
			if ( ! self::existe( $cible ) ) {
				$lib = FKC_PlanSyscohada::libelle( $cible ) ?: $connues[ $numero ][0];
				FKC_DB::q(
					'INSERT INTO comptes(numero,libelle,classe,type,actif,origine,parent,sens,lettrable,verrouille) VALUES(?,?,?,?,1,?,?,?,?,?)',
					array( $cible, $lib, (int) substr( $cible, 0, 1 ), 'detail', 'ohada',
						FKC_PlanSyscohada::parentOfficiel( $cible ), FKC_PlanSyscohada::sensNaturel( $cible ),
						FKC_PlanSyscohada::lettrable( $cible ) ? 1 : 0, FKC_PlanSyscohada::estOfficiel( $cible ) ? 1 : 0 )
				);
			}
			FKC_DB::q( 'UPDATE comptes SET actif=0 WHERE numero=?', array( $numero ) );
		} catch ( \Throwable $e ) { return array( false, 'Correction impossible : ' . $e->getMessage() ); }
		return array( true, 'Compte ' . $numero . ' désactivé (il était vierge) ; ' . $cible . ' est disponible à la place.' );
	}
}
