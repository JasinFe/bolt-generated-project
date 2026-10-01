<?php
/**
 * Module Scan & Identification — interface du Moteur d'Identification & Scan.
 * Console de scan universelle, résolution JSON (AJAX/API interne), générateur
 * de codes, impression d'étiquettes, journal de traçabilité, affectation de
 * codes aux fiches. @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_ScanController {

	/* ── Console de scan universelle ── */
	public function index() {
		FKC_View::render( 'scan::console', array(
			'title' => 'Scan & Identification', 'tab' => 'console',
			'contextes' => $this->contextesLabels(), 'stats' => FKC_Scan::stats(),
			'flash' => $this->flash(),
		) );
	}

	/* ═══════════════ SCAN WORKSPACE (1.828.0) ═══════════════════════════════
	   Un seul écran pour tout le geste de scan : le champ (douchette, clavier,
	   caméra du téléphone via Smart Scan), le contexte, l'aperçu « ce scan
	   va… », la carte d'intention après chaque scan, l'opération en cours
	   (réception, comptage, transfert, préparation) qu'on démarre, fait
	   avancer et clôt SANS quitter l'écran, l'historique de l'opérateur et les
	   codes inconnus à rattacher. La console, le Smart Scan et l'écran
	   Processus restent disponibles : ils sont réunis ici, pas supprimés. */

	public function workspace() {
		$uid = class_exists( 'FKC_Auth' ) ? (int) FKC_Auth::id() : 0;
		$an = FKC_Scan::analytics( date( 'Y-m-d 00:00:00', strtotime( '-7 days' ) ) );
		FKC_View::render( 'scan::workspace', array(
			'title' => 'Scan Workspace', 'tab' => 'workspace',
			'contextes' => $this->contextesLabels(), 'stats' => FKC_Scan::stats(),
			'definitions' => FKC_ScanWorkflow::definitions(), 'ouvertes' => FKC_ScanWorkflow::enCours(),
			'historique' => FKC_Scan::journal( array( 'user_id' => $uid, 'depuis' => date( 'Y-m-d 00:00:00', strtotime( '-2 days' ) ) ), 12 ),
			'inconnus' => array_slice( $an['inconnus'] ?? array(), 0, 8 ),
			'flash' => $this->flash(),
		) );
	}

	/**
	 * Scan du Workspace (JSON). Si une opération est ouverte, le scan la fait
	 * avancer ; sinon il est résolu dans le contexte choisi, avec intention.
	 */
	/** Scan du Workspace (JSON) — logique dans FKC_ScanWorkspace::scanner(). */
	public function workspaceScan() {
		$this->json( FKC_ScanWorkspace::scanner( $_POST ) );
	}

	/** Pilotage de l'opération depuis le Workspace (JSON, sans quitter l'écran). */
	public function workspaceProcessus() {
		$this->json( FKC_ScanWorkspace::piloter( $_POST ) );
	}

	/**
	 * Résolution d'un scan (AJAX). Le cœur de l'expérience « scan continu » :
	 * le poste envoie le code lu, reçoit l'objet identifié + l'action à mener.
	 */
	/**
	 * Point d'entrée unique de la boîte « Scanner ou rechercher… ».
	 *
	 * Le client n'a plus à choisir entre deux routes selon ce que
	 * l'utilisateur a tapé — et n'a plus à connaître le contexte : la route
	 * d'où vient la demande suffit au moteur pour le deviner.
	 */
	/**
	 * Écran des processus pilotés au scan.
	 *
	 * Le moteur savait séquencer depuis sa création, mais rien ne permettait de
	 * LANCER un processus : il tournait à vide. Cet écran est ce qui manquait
	 * entre le moteur et le magasinier.
	 */
	public function processus() {
		$sid = (int) ( $_GET['session'] ?? 0 );
		$etat = $sid ? FKC_ScanWorkflow::etat( $sid ) : null;
		FKC_View::render( 'scan::processus', array(
			'title' => 'Scan · Processus', 'tab' => 'processus',
			'definitions' => FKC_ScanWorkflow::definitions(),
			'ouvertes' => FKC_ScanWorkflow::enCours(),
			'etat' => $etat,
			'entrepots' => class_exists( 'FKC_InvEntrepot' ) ? FKC_InvEntrepot::all( true ) : array(),
			'flash' => $this->flash(),
		) );
	}

	/** Ouvre, fait avancer, clôt ou abandonne un processus. */
	public function processusAction() {
		$action = (string) ( $_POST['action'] ?? '' );
		$sid = (int) ( $_POST['session'] ?? 0 );

		// 1.819.0 — « Scanner pour démarrer » : ouvre le processus et y inscrit le scan déclencheur.
		if ( 'demarrer' === $action ) {
			$res = FKC_ScanResult::depuis( (string) ( $_POST['depuis'] ?? '' ), 'controle', array( 'poste' => 'Console', 'journal' => 'aucun' ) );
			list( $ok, $msg, $id ) = FKC_ScanWorkflow::demarrerDepuis( (string) ( $_POST['code'] ?? '' ), $res, array( 'poste' => 'Console' ) );
			if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) {
				return $this->json( array( 'ok' => $ok, 'message' => $msg, 'url' => $id ? url( 'scan/processus?session=' . (int) $id ) : null ) );
			}
			$this->setFlash( $msg );
			redirect( 'scan/processus' . ( $id ? '?session=' . (int) $id : '' ) );
		}
		if ( 'ouvrir' === $action ) {
			list( , $msg, $id ) = FKC_ScanWorkflow::ouvrir( (string) ( $_POST['code'] ?? '' ), array( 'poste' => 'Console' ) );
			$this->setFlash( $msg );
			redirect( 'scan/processus' . ( $id ? '?session=' . (int) $id : '' ) );
		}
		if ( 'abandonner' === $action ) {
			list( , $msg ) = FKC_ScanWorkflow::abandonner( $sid );
			$this->setFlash( $msg );
			redirect( 'scan/processus' );
		}
		if ( 'cloturer' === $action ) {
			list( , $msg ) = FKC_ScanWorkflow::cloturer( $sid, array(
				'entrepot_id' => (int) ( $_POST['entrepot_id'] ?? 0 ),
			) );
			$this->setFlash( $msg );
			redirect( 'scan/processus' . ( $sid ? '?session=' . $sid : '' ) );
		}
		// Scan : le résultat est structuré avant d'entrer dans le workflow, de
		// sorte qu'une étape puisse exiger un lot ou un emplacement.
		$res = FKC_ScanResult::depuis( (string) ( $_POST['q'] ?? '' ), 'controle', array( 'poste' => 'Console' ) );
		$out = FKC_ScanWorkflow::avancer( $sid, $res, array(
			'etape_suivante' => ! empty( $_POST['suivante'] ),
			'passer' => ! empty( $_POST['passer'] ),
		) );
		$this->setFlash( $out['message'] );
		redirect( 'scan/processus?session=' . $sid );
	}

	public function chercher() {
		$q = (string) ( $_POST['q'] ?? $_GET['q'] ?? '' );
		$ctx = array(
			'quantite' => isset( $_POST['quantite'] ) ? (float) $_POST['quantite'] : 1,
			'poste'    => substr( (string) ( $_POST['poste'] ?? 'Console' ), 0, 60 ),
			'canal'    => in_array( $_POST['canal'] ?? 'clavier', array( 'clavier', 'camera', 'api' ), true ) ? ( $_POST['canal'] ?? 'clavier' ) : 'clavier',
			'module'   => 'scan',
			'sens'     => $_POST['sens'] ?? 'entree',
			// La route ORIGINE, pas celle de cette requête : l'appel vient
			// toujours de scan/chercher, qui ne dit rien de l'écran ouvert.
			'route'    => substr( (string) ( $_POST['route'] ?? $_GET['route'] ?? '' ), 0, 120 ),
			'limite'   => (int) ( $_POST['limite'] ?? 12 ),
		);
		$contexte = FKC_Scan::normaliserContexte( $_POST['contexte'] ?? $_GET['contexte'] ?? '' );

		// Choix explicite dans la liste de candidats : on résout l'entité comme
		// si elle avait été scannée, pour que le journal garde la même trace.
		$type = preg_replace( '/[^a-z_]/', '', (string) ( $_POST['entite_type'] ?? '' ) );
		$eid  = (int) ( $_POST['entite_id'] ?? 0 );
		if ( $type && $eid ) {
			$res = self::enrichir( FKC_ScanContexte::resoudreEntite( $type, $eid, $contexte, $ctx ) );
			return $this->json( array(
				'mode' => $res ? 'scan' : 'vide', 'resultat' => $res,
				'contexte' => $contexte, 'candidats' => array(),
				'message' => $res ? '' : 'Entité introuvable.',
			) );
		}

		$out = FKC_ScanContexte::chercher( $q, $contexte, $ctx );
		$out['resultat'] = self::enrichir( $out['resultat'] );
		$this->json( $out );
	}

	/**
	 * Complète un résultat brut de sa lecture structurée.
	 *
	 * L'écran recevait le tableau interne du moteur : pour lire le lot ou la
	 * date de péremption d'un code GS1, il aurait fallu connaître la place des
	 * identifiants applicatifs. Personne ne le faisait, et l'information —
	 * pourtant décodée — restait inexploitée sur chaque écran.
	 *
	 * On y joint donc « attributs » et « peremption », normalisés par
	 * FKC_ScanResult : le client lit des noms de champs, pas du GS1.
	 */
	protected static function enrichir( $res ) {
		if ( ! $res || ! class_exists( 'FKC_ScanResult' ) ) { return $res; }
		$sr = new FKC_ScanResult( (array) $res );
		unset( $res['entite']['brut'] );
		$res['attributs']  = $sr->attributs();
		$res['peremption'] = $sr->peremption();
		// 1.817.0 : les objets EUX-MÊMES (type, id, libellé), plus seulement leurs noms.
		$res['objets'] = array();
		foreach ( $sr->objets() as $type => $o ) { $res['objets'][] = array( 'type' => $type, 'id' => $o['id'] ?? null, 'libelle' => (string) ( $o['libelle'] ?? '' ) ); }
		// Couche Intent : phrase, indicateurs, alertes, prochaines actions.
		if ( class_exists( 'FKC_ScanIntent' ) ) { $res['intent'] = FKC_ScanIntent::pour( $res ); }
		/*
		 * Les DESTINATIONS accompagnent le résultat : c'est ce qui permet à un
		 * écran de liste de faire du scan une navigation — on scanne une
		 * facture, elle s'ouvre — sans que cet écran connaisse quoi que ce soit
		 * du type d'objet lu.
		 */
		if ( class_exists( 'FKC_ScanNavigation' ) ) {
			$res['navigation'] = FKC_ScanNavigation::pour( $sr );
		}
		return $res;
	}

	public function resolve() {
		$code = (string) ( $_POST['code'] ?? $_GET['code'] ?? '' );
		$contexte = FKC_Scan::normaliserContexte( $_POST['contexte'] ?? '', 'controle' );
		$ctx = array(
			'quantite' => isset( $_POST['quantite'] ) ? (float) $_POST['quantite'] : 1,
			'poste'    => substr( (string) ( $_POST['poste'] ?? 'Console' ), 0, 60 ),
			'canal'    => in_array( $_POST['canal'] ?? 'clavier', array( 'clavier', 'camera', 'api' ), true ) ? ( $_POST['canal'] ?? 'clavier' ) : 'clavier',
			'module'   => 'scan',
			'sens'     => $_POST['sens'] ?? 'entree',
			// 1.811.0 : identifiant d'événement (file hors ligne rejouée sans doublon) et audit.
			'event_uuid'    => (string) ( $_POST['event_uuid'] ?? '' ),
			'operation'     => substr( (string) ( $_POST['operation'] ?? '' ), 0, 60 ) ?: null,
			'document_type' => preg_replace( '/[^a-z_]/', '', (string) ( $_POST['document_type'] ?? '' ) ) ?: null,
			'document_id'   => (int) ( $_POST['document_id'] ?? 0 ) ?: null,
		);
		if ( '' === trim( $code ) ) { return $this->json( array( 'ok' => false, 'statut' => 'inconnu', 'message' => 'Code vide.' ) ); }
		$res = FKC_Scan::resoudre( $code, $contexte ?: 'controle', $ctx );
		// N'expose au client que l'utile (pas l'objet brut complet).
		$res = self::enrichir( $res );
		unset( $res['entite']['brut'] );
		$this->json( $res );
	}

	/** « Ce scan va… » — aperçu SANS effet (ni journal, ni abonné). 1.817.0 */
	public function apercu() {
		$code = (string) ( $_GET['code'] ?? '' );
		if ( '' === trim( $code ) ) { return $this->json( array( 'connu' => false, 'phrase' => 'Scannez ou saisissez un code.' ) ); }
		$this->json( FKC_ScanIntent::apercu( $code, $_GET['contexte'] ?? 'controle', array(
			'quantite' => max( 0.001, (float) ( $_GET['quantite'] ?? 1 ) ),
			'session_id' => (int) ( $_GET['session'] ?? 0 ) ?: null,
		) ) );
	}

	/** Crée (ou retrouve) le lot porté par un code GS1 scanné. 1.817.0 */
	public function lot() {
		$code = (string) ( $_POST['code'] ?? '' );
		$articleId = (int) ( $_POST['article_id'] ?? 0 );
		$lot = trim( (string) ( $_POST['lot'] ?? '' ) ); $per = null; $serie = null;
		if ( '' !== $code ) {
			$id = FKC_Scan::identifier( $code );
			if ( $id && 'article' === $id['entite_type'] ) { $articleId = $articleId ?: (int) $id['entite_id']; }
			if ( ! empty( $id['gs1'] ) ) {
				$lot = $lot ?: (string) ( $id['gs1']['lot'] ?? '' );
				$per = $id['gs1']['peremption'] ?? null; $serie = $id['gs1']['serie'] ?? null;
			}
		}
		list( $ok, $msg, $lid ) = FKC_ScanIntent::assurerLot( $articleId, $lot, $per, $serie, (int) ( $_POST['entrepot_id'] ?? 0 ) ?: null );
		$this->json( array( 'ok' => $ok, 'message' => $msg, 'lot_id' => $lid ) );
	}

	/* ── Générateur de codes ── */
	public function generateur() {
		$val = (string) ( $_GET['valeur'] ?? '' );
		$type = preg_replace( '/[^a-z0-9]/', '', (string) ( $_GET['type'] ?? 'auto' ) );
		FKC_View::render( 'scan::generateur', array(
			'title' => 'Scan · Générateur de codes', 'tab' => 'generateur',
			'valeur' => $val, 'type' => $type ?: 'auto', 'flash' => $this->flash(),
		) );
	}

	/** Rendu SVG d'un code (image) — utilisé par le générateur et les étiquettes. */
	public function image() {
		$code = (string) ( $_GET['code'] ?? '' );
		$type = (string) ( $_GET['type'] ?? 'auto' );
		if ( '' === trim( $code ) ) { http_response_code( 400 ); exit; }
		$opt = array( 'hauteur' => (int) ( $_GET['h'] ?? 60 ), 'module' => max( 1, (int) ( $_GET['m'] ?? 2 ) ), 'texte' => '0' !== ( $_GET['texte'] ?? '1' ) );
		if ( 'auto' !== $type ) { $opt['symbologie'] = $type; }
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		echo FKC_Barcode::rendre( $code, $opt );
		exit;
	}

	/** Diagnostic d'un code avant impression (AJAX) : ce qui sera réellement encodé. */
	public function verifier() {
		$this->json( FKC_Barcode::verifier(
			(string) ( $_GET['code'] ?? '' ),
			(string) ( $_GET['type'] ?? 'auto' )
		) );
	}

	/** Génère un EAN-13 interne (AJAX). */
	public function genererInterne() {
		// 1.811.0 : un code ABSENT du registre (le tirage au hasard pouvait proposer un code déjà affecté).
		$this->json( array( 'code' => FKC_Scan::ean13InterneLibre(), 'symbologie' => 'ean13' ) );
	}

	/* ── Impression d'étiquettes ── */
	public function etiquettes() {
		FKC_View::render( 'scan::etiquettes', array(
			'title' => 'Scan · Étiquettes', 'tab' => 'etiquettes',
			'articles' => $this->articlesAvecCode(), 'flash' => $this->flash(),
		) );
	}

	/** Planche d'étiquettes imprimable (une grille, N étiquettes par article). */
	public function planche() {
		$items = array();
		$codes = (array) ( $_POST['codes'] ?? explode( ',', (string) ( $_GET['codes'] ?? '' ) ) );
		$qte = max( 1, (int) ( $_POST['qte'] ?? $_GET['qte'] ?? 1 ) );
		foreach ( $codes as $c ) {
			$c = trim( (string) $c ); if ( '' === $c ) { continue; }
			$ligne = FKC_DB::q( 'SELECT * FROM scan_codes WHERE code=? LIMIT 1', array( $c ) )->fetch();
			$lib = $ligne['libelle'] ?? '';
			for ( $i = 0; $i < $qte; $i++ ) { $items[] = array( 'code' => $c, 'libelle' => $lib, 'symbologie' => $ligne['symbologie'] ?? 'auto' ); }
		}
		FKC_View::render( 'scan::planche', array( 'title' => 'Étiquettes', 'items' => $items ), null );
	}

	/* ── Affectation d'un code à une fiche (multi-codes) ── */
	public function affecter() {
		$d = array(
			'code' => (string) ( $_POST['code'] ?? '' ), 'entite_type' => (string) ( $_POST['entite_type'] ?? 'article' ),
			'entite_id' => (int) ( $_POST['entite_id'] ?? 0 ), 'module' => (string) ( $_POST['module'] ?? '' ),
			'unite' => (string) ( $_POST['unite'] ?? 'unite' ), 'facteur' => (float) ( $_POST['facteur'] ?? 1 ),
			'libelle' => (string) ( $_POST['libelle'] ?? '' ),
		);
		/*
		 * 1.827.0 — lier() et non enregistrer() : enregistrer() RÉAFFECTE sans
		 * contrôle un code déjà rattaché à un autre objet. Depuis la console,
		 * affecter un code inconnu… mais en réalité déjà pris retirait
		 * silencieusement le code-barres d'un autre article. Le conflit est
		 * désormais signalé ; forcer reste possible, explicitement.
		 */
		$r = FKC_Scan::lier( $d, ! empty( $_POST['force'] ) );
		$this->json( array( 'ok' => (bool) $r['ok'], 'id' => $r['id'], 'message' => $r['message'], 'conflit' => $r['conflit'] ? true : false ) );
	}

	/** Assistant d'identification d'un code inconnu (1.827.0). */
	public function assistant() {
		$this->json( FKC_ScanIdentification::diagnostiquer( (string) ( $_GET['code'] ?? '' ) ) );
	}

	/** Étiquette métier d'un article : libellé, prix, EAN, QR GS1 (lot, péremption, quantité). 1.827.0 */
	public function etiquetteArticle() {
		$aid = (int) ( $_GET['article_id'] ?? 0 );
		$art = class_exists( 'FKC_InvArticle' ) ? FKC_InvArticle::find( $aid ) : null;
		if ( ! $art ) { http_response_code( 404 ); echo 'Article introuvable.'; return; }
		$lot = substr( trim( (string) ( $_GET['lot'] ?? '' ) ), 0, 20 );
		$per = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_GET['peremption'] ?? '' ) ) ? (string) $_GET['peremption'] : '';
		$qte = max( 0, (int) ( $_GET['qte'] ?? 0 ) );
		$copies = max( 1, min( 200, (int) ( $_GET['copies'] ?? 1 ) ) );
		FKC_View::render( 'scan::etiquette_article', array(
			'title' => 'Étiquette', 'art' => $art,
			'etiquette' => FKC_ScanIdentification::etiquette( $art, $lot, $per, $qte ), 'copies' => $copies,
		), null );
	}

	/* ── Journal / traçabilité ── */
	public function journal() {
		$f = array(
			'contexte' => FKC_Scan::normaliserContexte( $_GET['contexte'] ?? '' ) ?: null,
			'statut'   => in_array( $_GET['statut'] ?? '', array( 'ok', 'inconnu', 'refuse' ), true ) ? ( $_GET['statut'] ?? '' ) : null,
		);
		FKC_View::render( 'scan::journal', array(
			'title' => 'Scan · Historique', 'tab' => 'journal',
			'lignes' => FKC_Scan::journal( $f, 200 ), 'stats' => FKC_Scan::stats( date( 'Y-m-01 00:00:00' ) ),
			'analytics' => FKC_Scan::analytics( date( 'Y-m-d 00:00:00', strtotime( '-30 days' ) ) ),
			'contextes' => $this->contextesLabels(), 'filtre' => $f, 'flash' => $this->flash(),
		) );
	}

	/* ── Helpers ── */
	protected function contextesLabels() {
		$map = array(
			'reception' => '📥 Réception fournisseur', 'vente' => '🛒 Caisse / Vente', 'inventaire' => '📋 Inventaire',
			'sortie' => '📤 Sortie de stock', 'immobilisation' => '🏢 Inventaire des actifs', 'badge' => '👤 Badge collaborateur',
			'controle' => '🔍 Identification / contrôle',
		);
		$out = array();
		foreach ( FKC_Scan::contextes() as $c ) { $out[ $c ] = $map[ $c ] ?? ucfirst( $c ); }
		foreach ( $map as $c => $l ) { if ( ! isset( $out[ $c ] ) ) { $out[ $c ] = $l; } } // contrôle toujours dispo
		return $out;
	}

	/* ═══════════════ FinaKop Smart Scan ═══════════════ */

	/** Écran récepteur (PC) : ouvre/reprend une session, affiche le QR d'appairage. */
	public function smart() {
		$sessId = (int) ( $_GET['session'] ?? 0 );
		$sess = $sessId ? FKC_ScanSync::session( $sessId ) : null;
		if ( ! $sess || ! FKC_ScanSync::sessionValide( $sess ) ) {
			$sess = FKC_ScanSync::ouvrirSession( array(
				'libelle' => $_GET['libelle'] ?? 'Session de scan',
				'entrepot_id' => $_GET['entrepot'] ?? null,
				'contexte' => $_GET['contexte'] ?? 'controle',
			) );
		}
		// Le QR encode l'URL avec le CODE COURT qualifié par la société
		// (« <socId>-<code> ») : charge brève (QR fiable) ET auto-suffisante pour
		// que le téléphone ouvre la bonne base sans session utilisateur.
		$socId = FKC_ScanSync::societeCourante();
		$codeQualifie = ( $socId ? $socId . '-' : '' ) . $sess['code_court'];
		$joinUrl = abs_url( 'scan/mobile?c=' . rawurlencode( $codeQualifie ) );
		// QR explicite : on force la symbologie et on garantit un vrai QR carré.
		// Si la matrice échoue, on encode le CODE COURT (charge minimale) plutôt
		// qu'une longue URL — jamais de repli code-barres 1D trompeur.
		$qrSvg = FKC_Barcode::qr( $joinUrl, array( 'taille' => 150 ) );
		if ( false !== strpos( $qrSvg, '<text' ) ) { // repli 1D détecté → on retente avec le code seul
			$qrSvg = FKC_Barcode::qr( $codeQualifie, array( 'taille' => 150 ) );
		}
		FKC_View::render( 'scan::smart', array(
			'title' => 'FinaKop Smart Scan', 'tab' => 'smart',
			'session' => $sess, 'join_url' => $joinUrl, 'code_qualifie' => $codeQualifie,
			'qr_svg' => $qrSvg, 'societe_id' => (int) $socId,
			'contextes' => $this->contextesLabels(), 'flash' => $this->flash(),
		) );
	}

	/** Flux temps réel (PC) : nouveaux événements depuis ?after=ID (polling/SSE). */
	/** Lie la société de la session (robustesse multi-bases : le PC lit la même base que le téléphone). */
	protected function smartBindSociete() {
		$socId = (int) ( $_POST['societe_id'] ?? $_GET['societe_id'] ?? 0 );
		if ( $socId > 0 && class_exists( 'FKC_Tenant' ) && ! FKC_Tenant::current() ) { FKC_Tenant::bindById( $socId ); }
	}

	public function smartPull() {
		$this->smartBindSociete();
		$sessId = (int) ( $_GET['session'] ?? 0 );
		$after = (int) ( $_GET['after'] ?? 0 );
		$sess = FKC_ScanSync::session( $sessId );
		if ( ! $sess ) { return $this->json( array( 'ok' => false, 'message' => 'Session inconnue.' ) ); }
		$this->json( array( 'ok' => true ) + FKC_ScanSync::tirer( $sessId, $after ) + array(
			'stats' => FKC_ScanSync::statsSession( $sessId ),
			'valide' => FKC_ScanSync::sessionValide( $sess ),
		) );
	}

	/**
	 * Flux temps réel en Server-Sent Events : un canal ouvert où les scans sont
	 * poussés dès leur arrivée (latence moindre que le polling). Le client bascule
	 * dessus quand EventSource est disponible, et retombe sur smartPull sinon.
	 */
	public function smartStream() {
		fkc_sse_refuser_si_inactif();
		$this->smartBindSociete();
		$sessId = (int) ( $_GET['session'] ?? 0 );
		$after  = (int) ( $_GET['after'] ?? 0 );
		if ( ! FKC_ScanSync::session( $sessId ) ) { http_response_code( 404 ); exit; }
		// En-têtes SSE ; on coupe toute bufferisation pour un envoi immédiat.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );
		}
		@set_time_limit( 0 );
		while ( ob_get_level() > 0 ) { @ob_end_flush(); }
		$fin = time() + 25; // durée de vie bornée ; le client se reconnecte
		while ( time() < $fin ) {
			$sess = FKC_ScanSync::session( $sessId );
			if ( ! $sess || ! FKC_ScanSync::sessionValide( $sess ) ) {
				echo "event: closed\ndata: {}\n\n"; @flush(); break;
			}
			$data = FKC_ScanSync::tirer( $sessId, $after );
			if ( $data['events'] ) {
				$after = $data['last_id'];
				$payload = array( 'events' => $data['events'], 'last_id' => $after, 'devices' => $data['devices'], 'stats' => FKC_ScanSync::statsSession( $sessId ) );
				echo 'data: ' . json_encode( $payload, JSON_UNESCAPED_UNICODE ) . "\n\n";
			} else {
				echo ": keep-alive\n\n"; // commentaire SSE : maintient la connexion
			}
			@flush();
			if ( connection_aborted() ) { break; }
			usleep( 700000 ); // 0,7 s
		}
		exit;
	}

	/** Applique l'agrégat fusionné à l'ERP (inventaire → ajustements, réception → BR). */
	public function smartAppliquer() {
		$this->smartBindSociete();
		$sessId = (int) ( $_POST['session'] ?? 0 );
		$contexte = (string) ( $_POST['contexte'] ?? '' );
		list( $ok, $msg, $faits ) = FKC_ScanSync::appliquer( $sessId, $contexte, array(
			'entrepot_id' => $_POST['entrepot_id'] ?? null,
		) );
		$this->json( array( 'ok' => $ok, 'message' => $msg, 'lignes' => count( $faits ), 'details' => $faits ) );
	}

	/** Écarts d'inventaire de la session (1.818.0). */
	public function smartEcarts() {
		$this->smartBindSociete();
		$this->json( array( 'ok' => true ) + FKC_ScanSync::ecarts( (int) ( $_GET['session'] ?? 0 ) ) );
	}

	/** Validation des écarts avant écriture (1.818.0). */
	public function smartValider() {
		$this->smartBindSociete();
		list( $ok, $msg ) = FKC_ScanSync::validerEcarts( (int) ( $_POST['session'] ?? 0 ), (string) ( $_POST['commentaire'] ?? '' ) );
		$this->json( array( 'ok' => $ok, 'message' => $msg ) );
	}

	/** Traçabilité Scan → Stock → Écriture d'une session (1.818.0). */
	public function smartTrace() {
		$this->smartBindSociete();
		$this->json( array( 'ok' => true ) + FKC_ScanSync::trace( (int) ( $_GET['session'] ?? 0 ) ) );
	}

	public function smartFermer() {
		$this->smartBindSociete();
		$sessId = (int) ( $_POST['session'] ?? $_GET['session'] ?? 0 );
		if ( $sessId ) { FKC_ScanSync::fermerSession( $sessId ); }
		$this->setFlash( 'Session de scan fermée. Les appareils ont été déconnectés.' );
		// Ne pas revenir sur scan/smart (qui rouvrirait aussitôt une session) :
		// on renvoie vers la console de scan.
		return redirect( 'scan' );
	}

	public function smartAgregat() {
		$this->smartBindSociete();
		$sessId = (int) ( $_GET['session'] ?? 0 );
		$this->json( array( 'ok' => true, 'agregat' => FKC_ScanSync::agregatInventaire( $sessId ) ) );
	}

	/** Retire un appareil (Device Manager). */
	public function smartDevice() {
		$this->smartBindSociete();
		$devId = (int) ( $_POST['device'] ?? 0 );
		if ( $devId ) { FKC_ScanSync::retirerDevice( $devId ); }
		$this->json( array( 'ok' => true ) );
	}

	/* ── Côté mobile (téléphone / PDA) ── */

	/** Page mobile : appairage (via ?t=token ou saisie du code court) puis scan. */
	public function mobile() {
		$token = (string) ( $_GET['t'] ?? $_GET['c'] ?? '' );
		FKC_View::render( 'scan::mobile', array(
			'title' => 'FinaKop Scan Mobile', 'token' => $token,
			'https' => class_exists( 'FKC_Security' ) ? FKC_Security::isHttps() : ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ),
			'user_nom' => class_exists( 'FKC_Auth' ) && FKC_Auth::user() ? ( FKC_Auth::user()['nom_complet'] ?? '' ) : '',
		), null );
	}

	/** Appairage effectif (POST depuis le mobile). */
	public function mobileJoin() {
		// Route publique (aucune session, aucun jeton CSRF) : sans compteur
		// d'essais, l'espace des codes courts pouvait être balayé. On limite par
		// IP avant tout accès à la base.
		if ( FKC_ScanSync::appairageBloque() ) {
			http_response_code( 429 );
			return $this->json( array(
				'ok' => false,
				'message' => "Trop de tentatives d'appairage depuis cet appareil. Réessayez dans quelques minutes.",
			) );
		}

		$code = (string) ( $_POST['token'] ?? $_POST['code'] ?? '' );
		if ( '' === trim( $code ) || strlen( $code ) > 128 ) {
			FKC_ScanSync::appairageEchec();
			return $this->json( array( 'ok' => false, 'message' => 'Code d\'appairage absent ou invalide.' ) );
		}
		// Bind de la société depuis le préfixe « <socId>-… » avant tout accès DB.
		$socId = 0;
		if ( preg_match( '/^(\d+)-/', $code, $mm ) ) { $socId = (int) $mm[1]; if ( class_exists( 'FKC_Tenant' ) && ! FKC_Tenant::current() ) { FKC_Tenant::bindById( $socId ); } }
		$res = FKC_ScanSync::appairer( $code, array(
			'nom' => $_POST['nom'] ?? 'Téléphone',
			'user_nom' => $_POST['user_nom'] ?? '',
			'role' => $_POST['role'] ?? 'operateur',
			'plateforme' => $_POST['plateforme'] ?? 'smartphone',
		) );
		list( $ok, $msg, $device, $sess ) = $res;
		if ( $ok ) {
			FKC_ScanSync::appairageOk();
		} else {
			FKC_ScanSync::appairageEchec();
		}
		$this->json( array(
			'ok' => $ok, 'message' => $msg,
			'device_id' => $device['id'] ?? null, 'jeton' => $device['jeton_appareil'] ?? null,
			'societe_id' => $socId ?: FKC_ScanSync::societeCourante(),
			'session_id' => $sess['id'] ?? null, 'session_libelle' => $sess['libelle'] ?? null,
			'entrepot_id' => $sess['entrepot_id'] ?? null, 'contexte' => $sess['contexte'] ?? 'controle',
		) );
	}

	/** Un scan poussé depuis le mobile (POST). */
	public function mobilePush() {
		$socId = (int) ( $_POST['societe_id'] ?? 0 );
		if ( $socId > 0 && class_exists( 'FKC_Tenant' ) && ! FKC_Tenant::current() ) { FKC_Tenant::bindById( $socId ); }
		$res = FKC_ScanSync::pousserScan(
			(int) ( $_POST['device_id'] ?? 0 ), (string) ( $_POST['jeton'] ?? '' ),
			(string) ( $_POST['code'] ?? '' ),
			array( 'quantite' => (float) ( $_POST['quantite'] ?? 1 ), 'contexte' => $_POST['contexte'] ?? null,
				'event_uuid' => (string) ( $_POST['event_uuid'] ?? '' ) ) // 1.811.0 : file hors ligne rejouée sans doublon
		);
		if ( isset( $res['entite']['brut'] ) ) { unset( $res['entite']['brut'] ); }
		$this->json( $res );
	}

	/** Lie la société portée par la requête (appareil non authentifié côté PHP session). */
	protected function mobileBindSociete() {
		$socId = (int) ( $_POST['societe_id'] ?? $_GET['societe_id'] ?? 0 );
		if ( $socId > 0 && class_exists( 'FKC_Tenant' ) && ! FKC_Tenant::current() ) { FKC_Tenant::bindById( $socId ); }
	}

	/** Recherche d'articles depuis le mobile (pour affecter un code inconnu). */
	public function mobileArticles() {
		$this->mobileBindSociete();
		$dev = FKC_ScanSync::authDevice( (int) ( $_GET['device_id'] ?? 0 ), (string) ( $_GET['jeton'] ?? '' ) );
		if ( ! $dev ) { return $this->json( array( 'ok' => false, 'message' => 'Appareil non autorisé.' ) ); }
		$q = trim( (string) ( $_GET['q'] ?? '' ) );
		$out = array();
		if ( class_exists( 'FKC_InvArticle' ) && '' !== $q ) {
			try {
				$rows = FKC_DB::q(
					"SELECT id, code, designation FROM inv_articles
					 WHERE actif=1 AND (designation LIKE ? OR code LIKE ?)
					 ORDER BY designation COLLATE NOCASE LIMIT 20",
					array( '%' . $q . '%', '%' . $q . '%' )
				)->fetchAll();
				foreach ( $rows as $r ) { $out[] = array( 'id' => (int) $r['id'], 'code' => $r['code'], 'designation' => $r['designation'] ); }
			} catch ( \Throwable $e ) {}
		}
		$this->json( array( 'ok' => true, 'articles' => $out ) );
	}

	/** Affecte un code (EAN scanné) à un article, depuis le mobile. */
	public function mobileAffecter() {
		$this->mobileBindSociete();
		$dev = FKC_ScanSync::authDevice( (int) ( $_POST['device_id'] ?? 0 ), (string) ( $_POST['jeton'] ?? '' ) );
		if ( ! $dev ) { return $this->json( array( 'ok' => false, 'message' => 'Appareil non autorisé.' ) ); }
		$code = (string) ( $_POST['code'] ?? '' );
		$artId = (int) ( $_POST['article_id'] ?? 0 );
		if ( '' === trim( $code ) || $artId <= 0 ) { return $this->json( array( 'ok' => false, 'message' => 'Code ou article manquant.' ) ); }
		$art = class_exists( 'FKC_InvArticle' ) ? FKC_InvArticle::find( $artId ) : null;
		if ( ! $art ) { return $this->json( array( 'ok' => false, 'message' => 'Article introuvable.' ) ); }
		$id = FKC_Scan::enregistrer( array(
			'code' => $code, 'entite_type' => 'article', 'entite_id' => $artId,
			'module' => 'inventaire', 'unite' => 'unite', 'facteur' => 1,
			'libelle' => $art['designation'] ?? '',
		) );
		$this->json( array( 'ok' => (bool) $id, 'message' => $id ? ( 'Code affecté à « ' . ( $art['designation'] ?? '' ) . ' ».' ) : 'Échec de l\'affectation.', 'libelle' => $art['designation'] ?? '' ) );
	}

	/** Crée un article minimal depuis le mobile et lui affecte le code scanné. */
	public function mobileCreerArticle() {
		$this->mobileBindSociete();
		$dev = FKC_ScanSync::authDevice( (int) ( $_POST['device_id'] ?? 0 ), (string) ( $_POST['jeton'] ?? '' ) );
		if ( ! $dev ) { return $this->json( array( 'ok' => false, 'message' => 'Appareil non autorisé.' ) ); }
		if ( ! class_exists( 'FKC_InvArticle' ) ) { return $this->json( array( 'ok' => false, 'message' => 'Module Inventaire requis.' ) ); }
		$code = trim( (string) ( $_POST['code'] ?? '' ) );
		$designation = trim( (string) ( $_POST['designation'] ?? '' ) );
		if ( '' === $designation ) { return $this->json( array( 'ok' => false, 'message' => 'Désignation obligatoire.' ) ); }
		// Garde-fou : si le code est déjà rattaché à un article, ne pas créer de doublon.
		if ( '' !== $code ) {
			$conf = FKC_Scan::conflit( $code, 'article', 0 );
			if ( $conf ) {
				return $this->json( array( 'ok' => false, 'message' => 'Ce code est déjà rattaché à « ' . ( $conf['libelle'] ?? ( 'article #' . $conf['entite_id'] ) ) . ' ». Aucun doublon créé.' ) );
			}
		}
		$prix = (float) ( $_POST['prix_vente'] ?? 0 );
		/*
		 * LE TAUX DE TVA NE S'ÉCRIT PLUS EN DUR (1.589.0).
		 *
		 * Cet appel posait « 'tva' => 18 » — exactement le défaut corrigé
		 * partout ailleurs par COM-001, où le taux se résout par le
		 * référentiel des catégories fiscales. Un article créé au téléphone
		 * naissait donc hors du paramétrage fiscal du dossier : un exploitant
		 * qui a saisi un autre taux normal, ou qui travaille sous un régime
		 * réduit, se retrouvait avec des articles à 18 % sans l'avoir demandé
		 * et sans que rien ne le signale.
		 *
		 * On prend le TAUX NORMAL DU DOSSIER, et non une nature de prestation :
		 * la création mobile est un geste d'urgence sur un code inconnu, elle
		 * ne sait rien de ce que l'article est. Le taux normal est le repli
		 * honnête — il reste modifiable sur la fiche article, qui, elle, porte
		 * la catégorie fiscale.
		 */
		$tva = null;
		if ( class_exists( 'FKC_CategorieFiscale' ) ) {
			try {
				$cat = FKC_CategorieFiscale::parCode( 'NORMAL' );
				if ( $cat && null !== ( $cat['taux'] ?? null ) ) { $tva = (float) $cat['taux']; }
			} catch ( \Throwable $e ) { /* référentiel indisponible : on descend d'un cran */ }
		}
		if ( null === $tva && class_exists( 'FKC_FiscalConfig' ) ) {
			try { $tva = (float) FKC_FiscalConfig::tauxTva()['normal']; } catch ( \Throwable $e ) {}
		}
		if ( null === $tva ) { $tva = 18.0; } // droit commun ivoirien, dernier repli
		try {
			$artId = (int) FKC_InvArticle::create( array(
				'code' => $code ?: null, 'designation' => $designation, 'type' => 'produit',
				'unite' => 'unite', 'prix_vente' => $prix, 'tva' => $tva, 'gere_stock' => 1, 'actif' => 1,
			) );
		} catch ( \Throwable $e ) { return $this->json( array( 'ok' => false, 'message' => 'Création impossible : ' . $e->getMessage() ) ); }
		if ( $artId <= 0 ) { return $this->json( array( 'ok' => false, 'message' => 'Création de l\'article échouée.' ) ); }
		if ( '' !== $code ) {
			FKC_Scan::enregistrer( array(
				'code' => $code, 'entite_type' => 'article', 'entite_id' => $artId,
				'module' => 'inventaire', 'unite' => 'unite', 'facteur' => 1, 'libelle' => $designation,
			) );
		}
		$this->json( array( 'ok' => true, 'message' => 'Article « ' . $designation . ' » créé' . ( $code ? ' et code affecté.' : '.' ), 'article_id' => $artId, 'libelle' => $designation ) );
	}

	protected function articlesAvecCode() {
		try {
			return FKC_DB::q( "SELECT sc.code, sc.libelle, sc.symbologie, sc.unite, sc.entite_id FROM scan_codes sc WHERE sc.actif=1 AND sc.entite_type='article' ORDER BY sc.id DESC LIMIT 200" )->fetchAll();
		} catch ( \Throwable $e ) { return array(); }
	}

	protected function json( $data ) {
		if ( ! headers_sent() ) { header( 'Content-Type: application/json; charset=utf-8' ); header( 'Cache-Control: no-store' ); }
		echo json_encode( $data, JSON_UNESCAPED_UNICODE );
		exit;
	}
	protected function setFlash( $m ) { $_SESSION['__flash_scan'] = $m; }
	protected function flash() { $m = $_SESSION['__flash_scan'] ?? null; unset( $_SESSION['__flash_scan'] ); return $m; }
}
