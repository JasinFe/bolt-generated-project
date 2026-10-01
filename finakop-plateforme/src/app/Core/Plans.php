<?php
/**
 * Référentiel des paliers de licence (multi-sociétés, multi-niveaux).
 *
 * Source de vérité partagée pour : libellés, modules, limites (quotas) et
 * capacités (caps) de chaque palier. Le générateur de licences embarque ces
 * limites dans le jeton signé ; le plugin les lit et les applique. Pour les
 * jetons anciens (sans bloc « limits »), le plugin retombe sur ce référentiel.
 *
 * Convention de limite : -1 = illimité, 0 = aucun, N = quota.
 *
 * Dimensionnement (1.876.7) : quotas réalistes pour l'hébergement réel.
 * Le stockage compte le dossier de données de l'espace (bases + pièces) ;
 * la sauvegarde quotidienne en garde 14 copies complètes sur le même disque,
 * d'où un disque consommé de l'ordre de 8 à 15 fois les données vivantes.
 * Starter → Pro : hébergement mutualisé ; Entreprise Standard/Avancée :
 * Cloud ou VPS ; Entreprise Premium : VPS dédié. Aucun palier n'est
 * « illimité » : la clé enterprise_illimitee est conservée pour les jetons.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Plans {

	const ILLIMITE = -1;

	/** Clés de limites gérées (ordre d'affichage). */
	public static function limitKeys() {
		return array(
			'societes'          => 'Entités (sociétés)',
			'etablissements'    => 'Établissements',
			'utilisateurs'      => 'Utilisateurs',
			'entrepots'         => 'Entrepôts',
			'entites_creatives' => 'Labels / Studios',
			'artistes'          => 'Artistes',
			'projets'           => 'Projets',
			'oeuvres'           => 'Œuvres',
			'contrats'          => 'Contrats',
			'royalties'         => 'Calculs de royalties / mois',
			'packs_complementaires' => 'Packs Métier complémentaires (multi-pack)',
			'api'               => 'Appels API / mois',
			'stockage_go'       => 'Stockage (Go)',
		);
	}

	/** Libellés lisibles des capacités avancées (caps), regroupées. */
	public static function capLabels() {
		return array(
			// Multi-entités & consolidation
			'consolidation'        => 'Consolidation',
			'inter_societes'       => 'Inter-sociétés',
			'dashboards_consolides' => 'Tableaux de bord consolidés',
			'etats_consolides'     => 'États consolidés',
			'multi_pays'           => 'Multi-pays',
			'multi_devises'        => 'Multi-devises',
			'multi_plans'          => 'Multi-plans comptables',
			// Pilotage & gouvernance
			'workflow'             => 'Workflow de validation',
			'bi_avancee'           => 'BI avancée',
			'gouvernance'          => 'Gouvernance',
			'audit_centralise'     => 'Audit centralisé',
			'portails'             => 'Portails',
			'data_warehouse'       => 'Entrepôt de données (DWH)',
			// Industrie créative
			'portail_artiste'      => 'Portail artiste',
			'distribution'         => 'Distribution',
			'publishing'           => 'Publishing / édition',
			// Exploitation
			'api_keys'             => 'Clés API',
			'replication'          => 'Réplication',
			'haute_dispo'          => 'Haute disponibilité',
			'support_premium'      => 'Support premium',
			'sauvegardes'          => 'Sauvegardes',
		);
	}

	/** Rend une valeur de cap lisible : true→Inclus, false→—, chaîne→capitalisée. */
	public static function capValueLabel( $v ) {
		if ( true === $v || 1 === $v || '1' === $v ) { return 'Inclus'; }
		if ( false === $v || null === $v || '' === $v || 0 === $v ) { return '—'; }
		$map = array(
			'simple' => 'Simple', 'complete' => 'Complète', 'basique' => 'Basique',
			'quotidiennes' => 'Quotidiennes', 'quotidiennes+replication' => 'Quotidiennes + réplication',
			'personnalisees' => 'Personnalisées',
		);
		$s = (string) $v;
		return $map[ $s ] ?? ucfirst( str_replace( '_', ' ', $s ) );
	}

	/** Modules CORE par niveau (réutilisés par les paliers). */
	protected static function modCore( $level ) {
		// Modules transversaux présents dès le palier Starter : le Moteur
		// d'Identification & Scan et la Caisse servent tous les métiers
		// (une supérette Starter a besoin de son scanner et de sa caisse).
		$base = array( 'comptabilite', 'facturation', 'inventaire', 'scan', 'caisse', 'analyse' );
		$bus  = array_merge( $base, array( 'analytique', 'fiscalite', 'recouvrement' ) );
		$pro  = array_merge( $bus, array( 'rh', 'api' ) );
		$ent  = array_merge( $pro, array( 'paie' ) );
		switch ( $level ) {
			case 'starter':  return $base;
			case 'business': return $bus;
			case 'pro':      return $pro;
			default:         return $ent; // enterprise *
		}
	}
	protected static function modCreative( $level ) {
		return array_merge( self::modCore( $level ), array( 'royalties' ) );
	}

	/**
	 * Définition complète des paliers.
	 * Chaque entrée : edition, label, rang, modules, limits, caps.
	 */
	public static function tiers() {
		$U = self::ILLIMITE;
		return array(
			// ───────────────────────── CORE ─────────────────────────
			'starter' => array(
				'edition' => 'core', 'label' => 'Starter', 'rang' => 0, 'modules' => self::modCore( 'starter' ),
				'limits'  => array( 'societes' => 1, 'etablissements' => 1, 'utilisateurs' => 5, 'entrepots' => 2, 'api' => 0, 'stockage_go' => 2 ),
				'caps'    => array( 'consolidation' => false, 'inter_societes' => false, 'api_keys' => false ),
			),
			'business' => array(
				'edition' => 'core', 'label' => 'Business', 'rang' => 1, 'modules' => self::modCore( 'business' ),
				'limits'  => array( 'societes' => 3, 'etablissements' => 3, 'utilisateurs' => 10, 'entrepots' => 5, 'api' => 0, 'stockage_go' => 5 ),
				'caps'    => array( 'consolidation' => 'simple', 'inter_societes' => false, 'api_keys' => false ),
			),
			'pro' => array(
				'edition' => 'core', 'label' => 'Pro', 'rang' => 2, 'modules' => self::modCore( 'pro' ),
				'limits'  => array( 'societes' => 5, 'etablissements' => 10, 'utilisateurs' => 25, 'entrepots' => 15, 'api' => 50000, 'stockage_go' => 10 ),
				'caps'    => array( 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true, 'dashboards_consolides' => true, 'etats_consolides' => true ),
			),
			'enterprise_standard' => array(
				'edition' => 'core', 'label' => 'Entreprise Standard', 'rang' => 3, 'modules' => self::modCore( 'enterprise' ),
				'limits'  => array( 'societes' => 10, 'etablissements' => 25, 'utilisateurs' => 50, 'entrepots' => 40, 'api' => 150000, 'stockage_go' => 25 ),
				'caps'    => array( 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true, 'dashboards_consolides' => true, 'etats_consolides' => true, 'workflow' => true, 'bi_avancee' => true, 'sauvegardes' => 'quotidiennes' ),
			),
			'enterprise_avancee' => array(
				'edition' => 'core', 'label' => 'Entreprise Avancée', 'rang' => 4, 'modules' => self::modCore( 'enterprise' ),
				'limits'  => array( 'societes' => 25, 'etablissements' => 60, 'utilisateurs' => 150, 'entrepots' => 100, 'api' => 500000, 'stockage_go' => 50 ),
				'caps'    => array( 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true, 'dashboards_consolides' => true, 'etats_consolides' => true, 'workflow' => true, 'bi_avancee' => true, 'multi_pays' => true, 'multi_devises' => true, 'multi_plans' => true, 'gouvernance' => true, 'audit_centralise' => true, 'portails' => true, 'sauvegardes' => 'quotidiennes' ),
			),
			'enterprise_illimitee' => array(
				'edition' => 'core', 'label' => 'Entreprise Premium', 'rang' => 5, 'modules' => self::modCore( 'enterprise' ),
				'limits'  => array( 'societes' => 50, 'etablissements' => 150, 'utilisateurs' => 300, 'entrepots' => 300, 'projets' => $U, 'api' => 2000000, 'stockage_go' => 100 ),
				'caps'    => array( 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true, 'dashboards_consolides' => true, 'etats_consolides' => true, 'workflow' => true, 'bi_avancee' => true, 'multi_pays' => true, 'multi_devises' => true, 'multi_plans' => true, 'gouvernance' => true, 'audit_centralise' => true, 'portails' => true, 'data_warehouse' => true, 'support_premium' => true, 'sauvegardes' => 'personnalisees' ),
			),
			// ───────────────────── CREATIVE SUITE ─────────────────────
			'creative_starter' => array(
				'edition' => 'creative', 'label' => 'Creative Starter', 'rang' => 0, 'modules' => self::modCreative( 'starter' ),
				// packs_complementaires = 0 : un seul Pack Métier actif (le principal), pas de multi-pack au palier Starter.
				'limits'  => array( 'societes' => 1, 'entites_creatives' => 1, 'utilisateurs' => 5, 'artistes' => 5, 'projets' => 20, 'oeuvres' => 100, 'contrats' => 20, 'royalties' => 1000, 'etablissements' => 1, 'entrepots' => 2, 'api' => 0, 'stockage_go' => 2, 'packs_complementaires' => 0 ),
				'caps'    => array( 'portail_artiste' => false, 'distribution' => false, 'publishing' => false, 'api_keys' => false ),
			),
			'creative_business' => array(
				'edition' => 'creative', 'label' => 'Creative Business', 'rang' => 1, 'modules' => self::modCreative( 'business' ),
				'limits'  => array( 'societes' => 3, 'entites_creatives' => 5, 'utilisateurs' => 10, 'artistes' => 50, 'projets' => 100, 'oeuvres' => 500, 'contrats' => 500, 'royalties' => 20000, 'etablissements' => 3, 'entrepots' => 5, 'api' => 0, 'stockage_go' => 5, 'packs_complementaires' => 2 ),
				'caps'    => array( 'portail_artiste' => true, 'distribution' => 'basique', 'publishing' => false, 'consolidation' => 'simple', 'api_keys' => false ),
			),
			'creative_pro' => array(
				'edition' => 'creative', 'label' => 'Creative Pro', 'rang' => 2, 'modules' => self::modCreative( 'pro' ),
				'limits'  => array( 'societes' => 5, 'entites_creatives' => 20, 'utilisateurs' => 25, 'artistes' => 200, 'projets' => 500, 'oeuvres' => 5000, 'contrats' => 2000, 'royalties' => 200000, 'etablissements' => 10, 'entrepots' => 15, 'api' => 50000, 'stockage_go' => 10, 'packs_complementaires' => 4 ),
				'caps'    => array( 'portail_artiste' => true, 'distribution' => 'complete', 'publishing' => true, 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true ),
			),
			'creative_enterprise_standard' => array(
				'edition' => 'creative', 'label' => 'Creative Enterprise Standard', 'rang' => 3, 'modules' => self::modCreative( 'enterprise' ),
				'limits'  => array( 'societes' => 10, 'entites_creatives' => 50, 'utilisateurs' => 50, 'artistes' => 1000, 'projets' => 2000, 'oeuvres' => 20000, 'contrats' => 5000, 'royalties' => 500000, 'etablissements' => 25, 'entrepots' => 40, 'api' => 150000, 'stockage_go' => 25, 'packs_complementaires' => 8 ),
				'caps'    => array( 'portail_artiste' => true, 'distribution' => 'complete', 'publishing' => true, 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true, 'workflow' => true, 'bi_avancee' => true ),
			),
			'creative_enterprise_avancee' => array(
				'edition' => 'creative', 'label' => 'Creative Enterprise Avancée', 'rang' => 4, 'modules' => self::modCreative( 'enterprise' ),
				'limits'  => array( 'societes' => 25, 'entites_creatives' => 150, 'utilisateurs' => 150, 'artistes' => 5000, 'projets' => 10000, 'oeuvres' => 100000, 'contrats' => 20000, 'royalties' => 2000000, 'etablissements' => 60, 'entrepots' => 100, 'api' => 500000, 'stockage_go' => 50, 'packs_complementaires' => $U ),
				'caps'    => array( 'portail_artiste' => true, 'distribution' => 'complete', 'publishing' => true, 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true, 'workflow' => true, 'bi_avancee' => true, 'multi_pays' => true, 'multi_devises' => true, 'multi_plans' => true, 'gouvernance' => true, 'audit_centralise' => true, 'portails' => true ),
			),
			'creative_enterprise_illimitee' => array(
				'edition' => 'creative', 'label' => 'Creative Enterprise Premium', 'rang' => 5, 'modules' => self::modCreative( 'enterprise' ),
				'limits'  => array( 'societes' => 50, 'entites_creatives' => 300, 'utilisateurs' => 300, 'artistes' => 20000, 'projets' => 50000, 'oeuvres' => 500000, 'contrats' => 100000, 'royalties' => 5000000, 'etablissements' => 150, 'entrepots' => 300, 'api' => 2000000, 'stockage_go' => 100, 'packs_complementaires' => $U ),
				'caps'    => array( 'portail_artiste' => true, 'distribution' => 'complete', 'publishing' => true, 'consolidation' => 'complete', 'inter_societes' => true, 'api_keys' => true, 'workflow' => true, 'bi_avancee' => true, 'multi_pays' => true, 'multi_devises' => true, 'multi_plans' => true, 'gouvernance' => true, 'audit_centralise' => true, 'portails' => true, 'data_warehouse' => true, 'support_premium' => true, 'sauvegardes' => 'personnalisees' ),
			),
		);
	}

	/** Alias historiques → palier canonique (compatibilité des jetons déjà émis). */
	public static function aliases() {
		return array(
			'enterprise'          => 'enterprise_standard',
			'creative'            => 'creative_enterprise_illimitee',
			'creative_studio'     => 'creative_enterprise_standard',
			'creative_enterprise' => 'creative_enterprise_illimitee',
		);
	}

	/** Résout un nom de palier (suit les alias). */
	public static function resolveKey( $profile ) {
		$profile = (string) $profile;
		$al = self::aliases();
		if ( isset( $al[ $profile ] ) ) { $profile = $al[ $profile ]; }
		return $profile;
	}

	public static function get( $profile ) {
		$t = self::tiers();
		$k = self::resolveKey( $profile );
		return $t[ $k ] ?? null;
	}

	/** Limites d'un palier, complétées par les clés manquantes (illimité par défaut côté CORE). */
	public static function limitsFor( $profile ) {
		$tier = self::get( $profile );
		$lim  = $tier ? $tier['limits'] : array();
		// Clés non définies sur un palier = illimité (ex. artistes sur une édition CORE).
		foreach ( array_keys( self::limitKeys() ) as $k ) {
			if ( ! array_key_exists( $k, $lim ) ) { $lim[ $k ] = self::ILLIMITE; }
		}
		return $lim;
	}

	public static function label( $profile ) {
		$t = self::get( $profile );
		return $t ? $t['label'] : ucfirst( (string) $profile );
	}
	public static function edition( $profile ) {
		$t = self::get( $profile );
		return $t ? $t['edition'] : 'core';
	}
	public static function editionLabel( $edition ) {
		return 'creative' === $edition ? 'CREATIVE SUITE' : 'CORE';
	}
}
