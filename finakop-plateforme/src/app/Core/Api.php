<?php
/** API REST v1 (JSON) — mobile, intégrations bancaires, partenaires. Court-circuite la pile HTML/session.
 * @package FinaKop_ERP_Core */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

/** Interruption contrôlée d'une requête API (au lieu d'un exit brutal). */
class FKC_ApiStop extends \Exception {
	public $http; public $codeStr;
	public function __construct( $http, $code, $msg ) { parent::__construct( $msg ); $this->http = $http; $this->codeStr = $code; }
}

class FKC_Api {

	const VERSION = 'v1';

	/* ── Entrée principale ── */
	public static function handle( $path ) {
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		self::cors();
		if ( 'OPTIONS' === $method ) { http_response_code( 204 ); exit; }

		$segments = array_values( array_filter( explode( '/', $path ) ) ); // ['api','v1',...]
		array_shift( $segments ); // retire 'api'

		// Page de documentation (HTML) : /api ou /api/docs
		if ( ! $segments || 'docs' === ( $segments[0] ?? '' ) ) { self::docs(); return; }

		$ver = array_shift( $segments );
		if ( self::VERSION !== $ver ) { return self::error( 404, 'version_inconnue', "Version d'API inconnue. Utilisez « " . self::VERSION . " »." ); }

		$route = implode( '/', $segments );

		// Endpoints publics
		if ( 'ping' === $route ) { return self::json( array( 'pong' => true, 'name' => 'FinaKop ERP Core', 'version' => defined( 'FKC_VERSION' ) ? FKC_VERSION : '?', 'time' => date( 'c' ) ) ); }
		if ( 'openapi.json' === $route ) { return self::raw( self::openapi() ); }

		// À partir d'ici : authentification requise
		// Licence obligatoire (plateforme) : aucune donnée servie sans licence active.
		if ( class_exists( 'FKC_License' ) && FKC_License::verrouille() ) {
			return self::error( 402, 'licence_requise', 'Licence requise : aucune licence active pour cet espace.' );
		}
		$key = self::auth();
		if ( ! $key ) { return self::error( 401, 'non_authentifie', 'Clé API absente ou invalide. En-tête « Authorization: Bearer <clé> ».' ); }

		// Limitation de débit PAR CLÉ. Seul un quota mensuel global au cabinet
		// existait : une clé bavarde ou compromise pouvait épuiser en quelques
		// minutes le quota de toutes les autres intégrations.
		$debit = FKC_ApiKey::debitAutorise( $key );
		if ( ! $debit['ok'] ) {
			if ( ! headers_sent() ) { header( 'Retry-After: ' . (int) $debit['retry'] ); }
			return self::error( 429, 'debit_depasse', 'Trop d\'appels pour cette clé (' . (int) $debit['limite'] . ' par minute). Réessayez dans ' . (int) $debit['retry'] . ' secondes.' );
		}

		// Quota mensuel d'appels API (limite de licence, à l'échelle du cabinet).
		$apiLimit = class_exists( 'FKC_License' ) ? FKC_License::limit( 'api' ) : -1;
		if ( $apiLimit >= 0 && FKC_ApiKey::monthlyCalls() >= $apiLimit ) {
			return self::error( 429, 'quota_appels_depasse', 'Quota mensuel d\'appels API atteint pour votre licence. Réessayez le mois prochain ou passez à un palier supérieur.' );
		}
		FKC_ApiKey::recordCall();

		try {
			switch ( true ) {
				case 'me' === $route:
					return self::json( array(
						'label' => $key['label'], 'prefix' => $key['prefix'],
						'scopes' => $key['scopes_list'], 'societe_id' => $key['societe_id'] ? (int) $key['societe_id'] : null,
					) );

				case 'societes' === $route:
					self::need( $key, 'read:societes' );
					return self::json( self::listeSocietes( $key ) );

				case 'metrics' === $route:
					self::need( $key, 'read:metrics' );
					$soc = self::bind( $key );
					return self::json( self::metrics( $soc ), array( 'societe' => (int) $soc['id'] ) );

				case 'scan' === $route || 'scan/resolve' === $route:
					// Résolution d'un code par un terminal/app externe (même moteur
					// que la console web). Nécessite le scope « scan ». POST ou GET.
					self::need( $key, 'scan' );
					self::bind( $key );
					if ( ! class_exists( 'FKC_Scan' ) ) { return self::error( 503, 'scan_indisponible', 'Moteur de scan indisponible.' ); }
					$code = self::param( 'code', '', 512 );
					if ( '' === $code ) { return self::error( 400, 'code_absent', 'Paramètre « code » requis.' ); }
					// 1.811.0 : contextes hiérarchiques admis, event_uuid pour l'idempotence.
					$contexte = FKC_Scan::normaliserContexte( self::param( 'contexte', 'controle', 80 ), 'controle' );
					$ctx = array(
						'quantite' => self::paramNombre( 'quantite', 1, 0, 1000000 ),
						'poste'    => substr( self::param( 'poste', 'API', 120 ), 0, 60 ),
						'canal'    => 'api', 'module' => 'api',
						'event_uuid' => self::param( 'event_uuid', '', 64 ),
					);
					/*
					 * 1.827.0 — RÉSOLUTION ENRICHIE. mode=apercu : « ce scan va… »
					 * sans aucun effet. Sinon : résultat + objets secondaires
					 * (lot, série, emplacement, SSCC) + intention (phrase,
					 * indicateurs, alertes, actions).
					 */
					if ( 'apercu' === self::param( 'mode', '', 10 ) ) {
						return self::json( FKC_ScanIntent::apercu( $code, $contexte, $ctx ) );
					}
					$r = FKC_Scan::resoudre( $code, $contexte ?: 'controle', $ctx );
					if ( class_exists( 'FKC_ScanResult' ) ) {
						$sr = new FKC_ScanResult( $r );
						$r['attributs'] = $sr->attributs();
						$r['objets'] = array();
						foreach ( $sr->objets() as $type => $o ) { $r['objets'][] = array( 'type' => $type, 'id' => $o['id'] ?? null, 'libelle' => (string) ( $o['libelle'] ?? '' ) ); }
					}
					if ( class_exists( 'FKC_ScanIntent' ) ) { $r['intent'] = FKC_ScanIntent::pour( $r ); }
					unset( $r['entite']['brut'] );
					return self::json( $r );

				case 'clients' === $route:
					self::need( $key, 'read:clients' );
					self::bind( $key );
					return self::collection( 'SELECT * FROM clients ORDER BY id DESC', array(), array( 'id', 'type', 'nom', 'raison_sociale', 'email', 'telephone' ) );

				case 'factures' === $route:
					self::need( $key, 'read:factures' );
					self::bind( $key );
					$where = ''; $p = array();
					/*
					 * Domaine fermé, tel qu'il est réellement écrit en base :
					 * « brouillon » à la création (FKC_Facture::creer),
					 * « certifiee » après signature FNE, « annulee » après avoir.
					 * Une valeur hors de ces trois est une erreur de l'appelant
					 * et doit le lui être dite.
					 */
					$statut = self::paramEnum( 'statut', array( 'brouillon', 'certifiee', 'annulee' ) );
					if ( '' !== $statut ) { $where = 'WHERE statut=?'; $p[] = $statut; }
					return self::collection( "SELECT * FROM factures $where ORDER BY date_facture DESC", $p,
						array( 'id', 'client_nom', 'date_facture', 'echeance', 'montant_ht', 'montant_ttc', 'statut' ) );

				case 'comptes/balance' === $route:
					self::need( $key, 'read:compta' );
					self::bind( $key );
					return self::json( self::balance(), array( 'societe' => 'active' ) );

				case 'catalogue' === $route:
					// Catalogue vendable + stock, pour un frontal e-commerce.
					self::need( $key, 'read:catalogue' );
					self::bind( $key );
					return self::json( self::catalogue() );

				case 'commandes' === $route:
					// Création d'une commande web (click & collect) — POST requis.
					self::need( $key, 'write:commandes' );
					self::bind( $key );
					if ( 'POST' !== $method ) { return self::error( 405, 'methode_non_autorisee', 'Utilisez POST pour créer une commande.' ); }
					if ( ! class_exists( 'FKC_PosCommande' ) ) { return self::error( 503, 'commandes_indisponibles', 'Module commandes indisponible.' ); }
					return self::json( self::creerCommande() );

				case 'hors-ligne/lot' === $route:
					/*
					 * Remontée d'un lot d'opérations saisies sans réseau.
					 *
					 * La portée « write:commandes » est réutilisée volontairement :
					 * ce qu'une caisse hors ligne remonte, ce sont des ventes de
					 * comptoir. Créer une portée dédiée donnerait l'illusion d'un
					 * pouvoir distinct alors que c'est le même geste métier — et
					 * les clés déjà émises n'auraient pas à être refaites.
					 */
					self::need( $key, 'write:commandes' );
					self::bind( $key );
					if ( 'POST' !== $method ) { return self::error( 405, 'methode_non_autorisee', 'Utilisez POST pour remonter un lot hors ligne.' ); }
					if ( ! class_exists( 'FKC_HorsLigne' ) ) { return self::error( 503, 'hors_ligne_indisponible', 'Rejeu hors ligne indisponible.' ); }
					return self::json( self::lotHorsLigne() );

				default:
					return self::error( 404, 'route_inconnue', 'Endpoint inconnu : ' . $route );
			}
		} catch ( FKC_ApiStop $e ) {
			return self::error( $e->http, $e->codeStr, $e->getMessage() );
		}
	}

	/**
	 * Reçoit et rejoue un lot d'opérations hors ligne.
	 *
	 * LA RÉPONSE EST TOUJOURS 200, MÊME SI TOUT EST REFUSÉ, et ce n'est pas
	 * de la complaisance : un code d'erreur HTTP dirait au poste que le LOT
	 * n'est pas passé, alors que le verdict est rendu OPÉRATION PAR OPÉRATION.
	 * Une caisse qui recevrait 400 renverrait tout, y compris les ventes déjà
	 * enregistrées, et boucler ainsi n'aurait pas de fin. Les seuls codes
	 * d'erreur sont ceux qui empêchent d'even lire le lot.
	 */
	protected static function lotHorsLigne() {
		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) { throw new FKC_ApiStop( 400, 'corps_invalide', 'Corps JSON attendu.' ); }
		$poste = trim( (string) ( $body['poste'] ?? '' ) );
		$ops   = is_array( $body['operations'] ?? null ) ? $body['operations'] : array();
		if ( '' === $poste ) { throw new FKC_ApiStop( 400, 'poste_absent', 'Le champ « poste » est obligatoire : sans lui, l\'appartenance des numéros ne peut pas être vérifiée.' ); }
		if ( ! $ops ) { throw new FKC_ApiStop( 400, 'lot_vide', 'Aucune opération dans le lot.' ); }

		list( $ok, $msg, $verdicts ) = FKC_HorsLigne::lot( $poste, $ops );
		if ( ! $ok ) { throw new FKC_ApiStop( 400, 'lot_refuse', $msg ); }

		$compte = array();
		foreach ( $verdicts as $v ) {
			$compte[ $v['verdict'] ] = ( $compte[ $v['verdict'] ] ?? 0 ) + 1;
		}
		return array(
			'poste'     => $poste,
			'message'   => $msg,
			'resume'    => $compte,
			'verdicts'  => $verdicts,
			'recu_a'    => date( 'c' ),
		);
	}

	/* ── Données ── */
	protected static function listeSocietes( $key ) {
		if ( $key['societe_id'] ) {
			$rows = FKC_Master::q( 'SELECT id,slug,raison_sociale,sigle,devise FROM societes WHERE id=? AND actif=1', array( (int) $key['societe_id'] ) )->fetchAll();
		} else {
			$rows = FKC_Master::q( 'SELECT id,slug,raison_sociale,sigle,devise FROM societes WHERE actif=1 ORDER BY raison_sociale' )->fetchAll();
		}
		return array_map( function ( $s ) { return array( 'id' => (int) $s['id'], 'slug' => $s['slug'], 'raison_sociale' => $s['raison_sociale'], 'sigle' => $s['sigle'], 'devise' => $s['devise'] ); }, $rows );
	}

	protected static function metrics( $soc ) {
		$fin = class_exists( 'FKC_Metrics' ) ? FKC_Metrics::finance() : null;
		$com = class_exists( 'FKC_Metrics' ) ? FKC_Metrics::commercial() : null;
		$rec = class_exists( 'FKC_Metrics' ) ? FKC_Metrics::recouvrement() : null;
		$inv = class_exists( 'FKC_Metrics' ) ? FKC_Metrics::inventaire() : null;
		$rh  = class_exists( 'FKC_Metrics' ) ? FKC_Metrics::rh() : null;
		return array(
			'devise'          => $soc['devise'] ?? 'XOF',
			'produits'        => round( $fin['produits'] ?? 0, 2 ),
			'charges'         => round( $fin['charges'] ?? 0, 2 ),
			'resultat'        => round( $fin['resultat'] ?? 0, 2 ),
			'marge_pct'       => round( $fin['marge'] ?? 0, 2 ),
			'chiffre_affaires'=> round( $com['ca'] ?? 0, 2 ),
			'creances'        => round( $rec['creances'] ?? 0, 2 ),
			'creances_retard' => round( $rec['en_retard'] ?? 0, 2 ),
			'stock_valeur'    => round( $inv['valeur'] ?? 0, 2 ),
			'effectif'        => (int) ( $rh['effectif'] ?? 0 ),
		);
	}

	/** Catalogue vendable + stock disponible (e-commerce / click & collect). */
	protected static function catalogue() {
		$limit = max( 1, min( 500, (int) ( $_GET['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $_GET['offset'] ?? 0 ) );
		$out = array();
		try {
			$rows = FKC_DB::q(
				"SELECT a.id, a.code, a.designation, a.prix_vente, a.tva, a.unite, a.gere_stock,
				 (SELECT COALESCE(SUM(quantite-reserve),0) FROM inv_stock s WHERE s.article_id=a.id) dispo
				 FROM inv_articles a WHERE a.actif=1 ORDER BY a.designation COLLATE NOCASE LIMIT ? OFFSET ?",
				array( $limit, $offset )
			)->fetchAll();
			foreach ( $rows as $r ) {
				$out[] = array(
					'id' => (int) $r['id'], 'code' => $r['code'], 'designation' => $r['designation'],
					'prix' => (float) $r['prix_vente'], 'tva' => (float) $r['tva'], 'unite' => $r['unite'],
					'stock_disponible' => (int) $r['gere_stock'] ? (float) $r['dispo'] : null,
				);
			}
		} catch ( \Throwable $e ) {}
		return $out;
	}

	/** Crée une commande à partir d'un corps JSON (client + lignes). */
	protected static function creerCommande() {
		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) { $body = $_POST; }
		$lignesIn = isset( $body['lignes'] ) && is_array( $body['lignes'] ) ? $body['lignes'] : array();
		$lignes = array();
		foreach ( $lignesIn as $l ) {
			$aid = isset( $l['article_id'] ) ? (int) $l['article_id'] : 0;
			$q   = (float) ( $l['quantite'] ?? 0 );
			if ( $q <= 0 ) { continue; }
			$art = $aid ? FKC_DB::q( 'SELECT designation, prix_vente, tva FROM inv_articles WHERE id=? AND actif=1', array( $aid ) )->fetch() : null;
			$lignes[] = array(
				'article_id' => $art ? $aid : null,
				'libelle' => $art ? $art['designation'] : (string) ( $l['libelle'] ?? 'Article' ),
				'quantite' => $q,
				'pu' => $art ? (float) $art['prix_vente'] : (float) ( $l['pu'] ?? 0 ),
				'tva' => $art ? (float) $art['tva'] : (float) ( $l['tva'] ?? 18 ),
			);
		}
		if ( ! $lignes ) { throw new FKC_ApiStop( 400, 'lignes_absentes', 'Fournissez au moins une ligne { article_id, quantite }.' ); }

		list( $ok, $msg, $id ) = FKC_PosCommande::creer( array(
			'canal' => 'web',
			'client_id' => isset( $body['client_id'] ) ? (int) $body['client_id'] : null,
			'client_nom' => (string) ( $body['client_nom'] ?? '' ),
			'client_tel' => (string) ( $body['client_tel'] ?? '' ),
			'retrait_prevu' => (string) ( $body['retrait_prevu'] ?? '' ),
			'note' => (string) ( $body['note'] ?? '' ),
		), $lignes );
		if ( ! $ok ) { throw new FKC_ApiStop( 422, 'commande_refusee', $msg ); }
		$cmd = FKC_PosCommande::get( $id );
		return array( 'ok' => true, 'commande_id' => (int) $id, 'numero' => $cmd['numero'] ?? null, 'statut' => 'nouvelle', 'total_estime' => (float) ( $cmd['total_estime'] ?? 0 ), 'message' => $msg );
	}

	protected static function balance() {
		$rows = array();
		try {
			$rows = FKC_DB::q( 'SELECT c.classe classe, SUM(l.debit) d, SUM(l.credit) cr
				FROM ecriture_lignes l JOIN comptes c ON c.id=l.compte_id GROUP BY c.classe ORDER BY c.classe' )->fetchAll();
		} catch ( \Throwable $e ) {}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'classe' => (int) $r['classe'], 'debit' => round( (float) $r['d'], 2 ), 'credit' => round( (float) $r['cr'], 2 ), 'solde' => round( (float) $r['d'] - (float) $r['cr'], 2 ) );
		}
		return $out;
	}

	/** Liste générique paginée (limit/offset) en ne renvoyant que les colonnes autorisées. */
	protected static function collection( $sql, $params, $champs ) {
		/*
		 * PAGINATION TOLÉRANTE, FILTRES MÉTIER STRICTS.
		 *
		 * La distinction n'est pas un compromis, c'est la conséquence de ce que
		 * chaque paramètre veut dire. Un filtre métier hors domaine change la
		 * RÉPONSE : « statut=payee » renverrait une collection vide qu'un
		 * appelant lirait comme « aucune facture ne correspond ». Il doit donc
		 * être refusé.
		 *
		 * « limit=abc » ne change rien au JEU DE DONNÉES, seulement à la
		 * tranche montrée — et la tranche appliquée est renvoyée dans « meta »,
		 * où l'appelant peut la lire. Refuser en 400 casserait une intégration
		 * qui fonctionne pour un paramètre décoratif. Le repli est donc
		 * silencieux, mais jamais muet : meta.limit dit ce qui a été fait.
		 */
		$limit  = self::paramBorne( 'limit', 50, 1, 200 );
		$offset = self::paramBorne( 'offset', 0, 0, 1000000 );
		/*
		 * UNE ERREUR SQL N'EST PAS UNE COLLECTION VIDE.
		 *
		 * Le catch précédent posait « $rows = array() » et la réponse partait
		 * en 200 avec « count: 0 ». Une table absente, un paramètre au mauvais
		 * type, une base verrouillée : tout se présentait à l'appelant comme
		 * « aucun résultat ». Une intégration partenaire ne pouvait pas
		 * distinguer « il n'y a rien à facturer ce mois-ci » de « la requête
		 * a échoué » — et un rapport vide n'alerte personne.
		 *
		 * L'erreur est désormais journalisée côté serveur et signalée en 500
		 * côté client, SANS divulguer le message de la base : il contient le
		 * SQL et la structure des tables.
		 */
		try {
			$rows = FKC_DB::q( $sql . ' LIMIT ' . $limit . ' OFFSET ' . $offset, $params )->fetchAll();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'fkc_log' ) ) {
				fkc_log( 'Api::collection ' . $e->getMessage() . ' | ' . $sql, 'ERROR' );
			}
			return self::error( 500, 'erreur_requete',
				'La requête n\'a pas pu être exécutée. L\'incident est journalisé côté serveur.' );
		}
		$data = array();
		foreach ( $rows as $r ) {
			$o = array(); foreach ( $champs as $c ) { if ( array_key_exists( $c, $r ) ) { $o[ $c ] = $r[ $c ]; } }
			$data[] = $o;
		}
		return self::json( $data, array( 'limit' => $limit, 'offset' => $offset, 'count' => count( $data ) ) );
	}

	/* ── Lecture des paramètres de requête ──────────────────────────────────
	 *
	 * CE QUE LA LECTURE DIRECTE DE $_GET LAISSAIT PASSER
	 *
	 * Les paramètres étaient lus par « (string) ( $_POST['x'] ?? $_GET['x'] ) ».
	 * PHP autorise « ?x[]=a&x[]=b » : la valeur reçue est alors un TABLEAU, et
	 * la conversion en chaîne donne « Array » avec un avertissement — ou, si le
	 * tableau part tel quel dans un paramètre lié, une erreur PDO. L'appelant
	 * recevait donc une réponse absurde ou une erreur 500 là où un 400 explicite
	 * était dû.
	 *
	 * Ces deux lecteurs refusent en une ligne ce que la couche SQL ne devrait
	 * jamais avoir à arbitrer.
	 */

	/**
	 * Paramètre scalaire (POST prioritaire sur GET).
	 *
	 * @param string $nom    Nom du paramètre.
	 * @param string $defaut Valeur si absent.
	 * @param int    $max    Longueur maximale ; au-delà, la valeur est refusée.
	 * @return string
	 * @throws FKC_ApiStop 400 si la valeur n'est pas un scalaire ou dépasse $max.
	 */
	protected static function param( $nom, $defaut = '', $max = 255 ) {
		$brut = $_POST[ $nom ] ?? $_GET[ $nom ] ?? null;
		if ( null === $brut ) { return (string) $defaut; }
		if ( is_array( $brut ) || is_object( $brut ) ) {
			throw new FKC_ApiStop( 400, 'parametre_invalide',
				'Le paramètre « ' . $nom . ' » attend une valeur simple, pas une liste.' );
		}
		$v = trim( (string) $brut );
		if ( strlen( $v ) > $max ) {
			throw new FKC_ApiStop( 400, 'parametre_trop_long',
				'Le paramètre « ' . $nom . ' » dépasse ' . (int) $max . ' caractères.' );
		}
		return $v;
	}

	/**
	 * Paramètre dont le domaine de valeurs est fermé.
	 *
	 * Une valeur hors domaine est REFUSÉE, pas ignorée. Auparavant, filtrer sur
	 * « statut=payée » (accentué, ou simplement inexistant) renvoyait 200 avec
	 * une collection vide : l'intégration en concluait qu'aucune facture ne
	 * correspondait, alors que son filtre ne voulait rien dire. Un rapport
	 * partenaire pouvait rester vide pendant des semaines sans que personne
	 * soupçonne une faute de frappe.
	 *
	 * @param string   $nom     Nom du paramètre.
	 * @param string[] $valeurs Domaine autorisé.
	 * @return string Valeur validée, ou '' si le paramètre est absent.
	 * @throws FKC_ApiStop 400 si la valeur est hors domaine.
	 */
	protected static function paramEnum( $nom, array $valeurs ) {
		$v = self::param( $nom, '', 64 );
		if ( '' === $v ) { return ''; }
		if ( ! in_array( $v, $valeurs, true ) ) {
			throw new FKC_ApiStop( 400, 'valeur_non_autorisee',
				'Valeur « ' . $v . ' » non autorisée pour « ' . $nom . ' ». Attendu : ' . implode( ', ', $valeurs ) . '.' );
		}
		return $v;
	}

	/** Paramètre numérique borné. */
	protected static function paramNombre( $nom, $defaut = 0.0, $min = null, $max = null ) {
		$v = self::param( $nom, '', 32 );
		if ( '' === $v ) { return (float) $defaut; }
		if ( ! is_numeric( $v ) ) {
			throw new FKC_ApiStop( 400, 'parametre_non_numerique',
				'Le paramètre « ' . $nom . ' » attend un nombre.' );
		}
		$n = (float) $v;
		if ( null !== $min && $n < $min ) { $n = (float) $min; }
		if ( null !== $max && $n > $max ) { $n = (float) $max; }
		return $n;
	}

	/**
	 * Entier borné, sans erreur : réservé aux paramètres de PRÉSENTATION.
	 *
	 * Une valeur absurde est ramenée dans les bornes plutôt que refusée. Le
	 * contrat est que la valeur appliquée soit toujours renvoyée dans « meta »,
	 * de sorte qu'un appelant qui a écrit n'importe quoi puisse le constater.
	 *
	 * Ne JAMAIS employer pour un filtre métier : là, une valeur silencieusement
	 * corrigée fausserait la réponse sans que personne le sache.
	 */
	protected static function paramBorne( $nom, $defaut, $min, $max ) {
		$brut = $_POST[ $nom ] ?? $_GET[ $nom ] ?? null;
		if ( null === $brut || is_array( $brut ) || is_object( $brut ) ) { return (int) $defaut; }
		$v = trim( (string) $brut );
		if ( '' === $v || ! is_numeric( $v ) ) { return (int) $defaut; }
		return (int) max( $min, min( $max, (int) $v ) );
	}

	/* ── Authentification & société ── */
	protected static function auth() {
		$hdr = self::header( 'Authorization' );
		$token = '';
		if ( $hdr && stripos( $hdr, 'bearer ' ) === 0 ) { $token = trim( substr( $hdr, 7 ) ); }
		if ( ! $token ) { $token = self::header( 'X-Api-Key' ); }
		if ( ! $token ) { return null; }
		return FKC_ApiKey::verify( $token );
	}
	protected static function need( $key, $scope ) {
		if ( ! FKC_ApiKey::hasScope( $key, $scope ) ) { throw new FKC_ApiStop( 403, 'scope_insuffisant', 'Cette clé ne dispose pas du scope « ' . $scope . ' ».' ); }
	}
	/** Cible la base de la société liée à la clé (ou ?societe= si clé multi-sociétés). */
	protected static function bind( $key ) {
		$req = isset( $_GET['societe'] ) ? (int) $_GET['societe'] : 0;
		$sid = $key['societe_id'] ? (int) $key['societe_id'] : $req;
		if ( $key['societe_id'] && $req && $req !== (int) $key['societe_id'] ) { throw new FKC_ApiStop( 403, 'societe_interdite', 'Cette clé est limitée à une autre société.' ); }
		if ( ! $sid ) { throw new FKC_ApiStop( 400, 'societe_requise', 'Précisez la société via le paramètre ?societe=ID (clé multi-sociétés).' ); }
		$soc = FKC_Master::q( 'SELECT * FROM societes WHERE id=? AND actif=1', array( $sid ) )->fetch();
		if ( ! $soc ) { throw new FKC_ApiStop( 404, 'societe_inconnue', 'Société introuvable.' ); }
		FKC_DB::useFile( FKC_DATA_DIR . $soc['db_file'] );
		FKC_DB::conn();
		return $soc;
	}

	/* ── Sortie JSON ── */
	/**
	 * En-têtes de réponse de l'API.
	 *
	 * Le défaut était « Access-Control-Allow-Origin: * », qui autorise
	 * n'importe quelle page web à appeler l'API depuis le navigateur d'un
	 * utilisateur. On retient désormais l'origine de l'application, et les
	 * intégrations tierces s'ouvrent explicitement dans wp-config.php :
	 *
	 *     define( 'FKC_API_CORS', 'https://boutique.exemple.ci' );  // une origine
	 *     define( 'FKC_API_CORS', '*' );                            // tout ouvrir (déconseillé)
	 *
	 * Une liste d'origines séparées par des virgules est acceptée : seule
	 * l'origine de la requête courante est renvoyée, jamais la liste entière.
	 */
	protected static function cors() {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store' );

		$configure = defined( 'FKC_API_CORS' ) ? trim( (string) FKC_API_CORS ) : '';
		$origine   = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( (string) $_SERVER['HTTP_ORIGIN'] ) : '';

		if ( '*' === $configure ) {
			header( 'Access-Control-Allow-Origin: *' );
		} else {
			$autorisees = array();
			if ( '' !== $configure ) {
				foreach ( explode( ',', $configure ) as $o ) {
					$o = trim( $o );
					if ( '' !== $o ) { $autorisees[] = rtrim( $o, '/' ); }
				}
			}
			// Origine propre de l'application, toujours admise.
			if ( defined( 'FKC_BASE_URL' ) ) {
				$p = (array) parse_url( (string) FKC_BASE_URL );
				if ( ! empty( $p['host'] ) ) {
					$autorisees[] = ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( ! empty( $p['port'] ) ? ':' . $p['port'] : '' );
				}
			}
			if ( '' !== $origine && in_array( rtrim( $origine, '/' ), $autorisees, true ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origine );
			}
			// Aucune origine correspondante : on n'émet pas l'en-tête. Les appels
			// serveur à serveur (cURL, mobile) ne sont pas concernés par le CORS.
		}
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, X-Api-Key, Content-Type' );
		header( 'Vary: Origin' );
	}
	protected static function json( $data, $meta = array() ) {
		$out = array( 'data' => $data );
		$meta['api'] = self::VERSION;
		$out['meta'] = $meta;
		echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}
	protected static function raw( $data ) { echo json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); }
	protected static function error( $http, $code, $message ) {
		http_response_code( $http );
		echo json_encode( array( 'error' => array( 'code' => $code, 'message' => $message ), 'meta' => array( 'api' => self::VERSION ) ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
	protected static function header( $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $key ] ) ) { return $_SERVER[ $key ]; }
		if ( function_exists( 'apache_request_headers' ) ) {
			foreach ( apache_request_headers() as $k => $v ) { if ( strcasecmp( $k, $name ) === 0 ) { return $v; } }
		}
		return '';
	}

	/* ── Documentation HTML (auto-portée) ── */
	protected static function docs() {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$spec = self::openapi();
		$base = ( defined( 'FKC_BASE_URL' ) ? rtrim( FKC_BASE_URL, '/' ) : '' );
		include FKC_ROOT . 'views/api_docs.php';
	}

	/* ── Spécification OpenAPI 3.0 ── */
	public static function openapi() {
		$base = ( defined( 'FKC_BASE_URL' ) ? rtrim( FKC_BASE_URL, '/' ) : '' ) . '/api/' . self::VERSION;
		$sec = array( array( 'bearerAuth' => array() ) );
		$mkget = function ( $summary, $tag, $sec_needed = true, $params = array() ) use ( $sec ) {
			$op = array( 'summary' => $summary, 'tags' => array( $tag ), 'responses' => array(
				'200' => array( 'description' => 'Succès' ), '401' => array( 'description' => 'Non authentifié' ), '403' => array( 'description' => 'Scope insuffisant' ),
			) );
			if ( $sec_needed ) { $op['security'] = $sec; }
			if ( $params ) { $op['parameters'] = $params; }
			return array( 'get' => $op );
		};
		$pSoc = array( array( 'name' => 'societe', 'in' => 'query', 'schema' => array( 'type' => 'integer' ), 'description' => 'ID société (clé multi-sociétés)' ) );
		$pPag = array(
			array( 'name' => 'limit', 'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 50, 'maximum' => 200 ) ),
			array( 'name' => 'offset', 'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 0 ) ),
		);
		return array(
			'openapi' => '3.0.3',
			'info' => array(
				'title' => 'FinaKop ERP Core — API REST',
				'version' => defined( 'FKC_VERSION' ) ? FKC_VERSION : '1.0',
				'description' => "API de lecture pour applications mobiles, intégrations bancaires et partenaires. Authentification par clé API (Bearer). Données isolées par société.",
			),
			'servers' => array( array( 'url' => $base ) ),
			'components' => array(
				'securitySchemes' => array( 'bearerAuth' => array( 'type' => 'http', 'scheme' => 'bearer', 'description' => 'Clé API : Authorization: Bearer fkc_xxx.xxx' ) ),
			),
			'tags' => array(
				array( 'name' => 'Système' ), array( 'name' => 'Sociétés' ), array( 'name' => 'Indicateurs' ),
				array( 'name' => 'Clients' ), array( 'name' => 'Factures' ), array( 'name' => 'Comptabilité' ),
			),
			'paths' => array(
				'/ping'            => $mkget( 'Santé de l\'API (public)', 'Système', false ),
				'/me'              => $mkget( 'Informations sur la clé courante', 'Système' ),
				'/societes'        => $mkget( 'Liste des sociétés accessibles', 'Sociétés' ),
				'/metrics'         => $mkget( 'Indicateurs clés (produits, charges, résultat, CA, créances, stock, effectif)', 'Indicateurs', true, $pSoc ),
				'/clients'         => $mkget( 'Liste des clients', 'Clients', true, array_merge( $pSoc, $pPag ) ),
				'/factures'        => $mkget( 'Liste des factures', 'Factures', true, array_merge( $pSoc, $pPag,
					// Le domaine est publié : un client généré depuis ce schéma
					// refusera la valeur invalide avant même l'appel réseau.
					array( array( 'name' => 'statut', 'in' => 'query', 'schema' => array(
						'type' => 'string', 'enum' => array( 'brouillon', 'certifiee', 'annulee' ),
					) ) ) ) ),
				'/comptes/balance' => $mkget( 'Balance par classe SYSCOHADA', 'Comptabilité', true, $pSoc ),
			),
		);
	}
}
