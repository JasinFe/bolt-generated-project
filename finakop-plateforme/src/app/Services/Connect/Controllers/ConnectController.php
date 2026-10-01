<?php
/**
 * FINAKOP CONNECT — écrans.
 *
 * Le contrôleur ne décide de RIEN : il lit une intention, appelle le moteur,
 * rend le résultat. Toute règle d'accès vit dans FKC_Connect_Droits, pour
 * qu'il n'y ait qu'un seul endroit à relire le jour où l'on se demande qui
 * voit quoi.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_ConnectController {

	protected function flash( $m, $t = 'ok' ) { $_SESSION['__flash_cx'] = array( 'type' => $t, 'msg' => $m ); }
	protected function prendreFlash() { $m = $_SESSION['__flash_cx'] ?? null; unset( $_SESSION['__flash_cx'] ); return $m; }

	/**
	 * Le lien et le code d'une invitation, repris UNE SEULE FOIS.
	 *
	 * Ils ne sont stockés nulle part sous forme lisible : si l'utilisateur
	 * quitte l'écran sans les noter, il faut réinviter. C'est le prix d'un
	 * stockage qui ne permet pas de se faire passer pour l'invité en lisant
	 * la base.
	 */
	protected function prendreAcces() { $a = $_SESSION['__cx_acces'] ?? null; unset( $_SESSION['__cx_acces'] ); return $a; }

	/** Refus commun : le service n'est pas ouvert pour cette société. */
	protected function refus() {
		http_response_code( 403 );
		return FKC_View::render( 'errors.denied', array( 'reason' => 'module', 'module' => 'connect' ) );
	}

	public function index() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		FKC_Connect::semerCanaux();
		/*
		 * Filet de sécurité du mutualisé : sur une installation où WP-Cron
		 * est coupé et où personne n'a posé de cron système, la file ne
		 * serait jamais traitée. Cette ligne ne purge rien — elle met en
		 * file, au plus une fois par jour, ce qui coûte une insertion.
		 */
		FKC_Connect_Retention::programmerSiUtile();
		// Un navigateur fermé brutalement ne prévient personne : sans ce
		// ménage, la conversation afficherait « appel en cours » pour toujours.
		FKC_Connect_Appel::menage();
		return $this->rendre( null );
	}

	public function conversation( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		return $this->rendre( (int) $id );
	}

	/** Écran unique : la liste à gauche, la conversation ouverte à droite. */
	protected function rendre( $convId ) {
		$uid = (int) FKC_Auth::id();
		$conv = null; $messages = array(); $carte = null; $membres = array(); $epingles = array(); $pieces = array();

		if ( $convId ) {
			$conv = FKC_Connect::find( $convId );
			if ( ! $conv || ! FKC_Connect_Droits::peutVoirConversation( $conv, $uid ) ) {
				http_response_code( 404 );
				return FKC_View::render( 'errors.404', array() );
			}
			// Lecture au titre de l'audit : elle laisse une trace. Voir
			// FKC_Connect_Droits, en-tête.
			if ( 'objet' !== $conv['type'] && 'canal' !== $conv['type']
				&& ! FKC_Connect_Droits::estMembre( (int) $conv['id'], $uid )
				&& FKC_Connect_Droits::peut( 'audit', $uid ) ) {
				FKC_Connect_Droits::tracerAudit( (int) $conv['id'], 'consultation de la conversation' );
			}
			$messages = FKC_Connect::messages( $convId );
			$pieces   = FKC_Connect_Piece::parMessage( $convId );
			$epingles = FKC_Connect::epingles( $convId );
			$membres  = FKC_Connect::membres( $convId );
			$lien = FKC_Connect_Objet::lien( $convId );
			if ( $lien ) { $carte = FKC_Connect_Objet::carte( $lien['entity_type'], (int) $lien['entity_id'], (int) $convId ); }
			FKC_Connect::marquerLu( $convId, $uid );
			$conv['titre_affiche'] = FKC_Connect::titreAffiche( $conv, $uid );
		}

		return FKC_View::render( 'connect::index', array(
			'title'         => 'FinaKop Connect',
			'conversations' => FKC_Connect::conversations( $uid ),
			'conv'          => $conv,
			'messages'      => $messages,
			'epingles'      => $epingles,
			'membres'       => $membres,
			'carte'         => $carte,
			'pieces'        => $pieces,
			'invites'       => $conv ? FKC_Connect_Invite::actifs( (int) $conv['id'] ) : array(),
			'peut_externe'  => FKC_Connect_Droits::peut( 'externe', $uid ),
			'maestro'       => FKC_Connect_Maestro::disponible(),
			'relais'        => FKC_Connect_Relais::actif(),
			'appel_audio'   => FKC_Connect_Appel::disponible( FKC_Connect_Appel::AUDIO ),
			'appel_video'   => FKC_Connect_Appel::disponible( FKC_Connect_Appel::VIDEO ),
			'appel_encours' => $conv ? FKC_Connect_Appel::enCours( (int) $conv['id'] ) : null,
			'presents'      => $conv ? FKC_Connect::presents( (int) $conv['id'] ) : array(),
			'accuses'       => $conv ? FKC_Connect::accuses( (int) $conv['id'], $uid ) : array(),
			'vapid'         => FKC_Connect_Push::clePublique(),
			'push_diag'     => FKC_Connect_Push::disponible(),
			'peut_fichier'  => FKC_Connect_Droits::peut( 'partager_document', $uid ),
			'peut_ecrire'   => $conv ? FKC_Connect_Droits::peutEcrireDans( $conv, $uid ) : false,
			'peut_canal'    => FKC_Connect_Droits::peut( 'creer_canal', $uid ),
			'peut_groupe'   => FKC_Connect_Droits::peut( 'creer_groupe', $uid ),
			'annuaire'      => FKC_Connect::annuaire(),
			'uid'           => $uid,
			'acces'         => $this->prendreAcces(),
			'flash'         => $this->prendreFlash(),
		) );
	}

	/** Création d'une conversation (groupe, canal ou message direct). */
	public function creer() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$type = (string) ( $_POST['type'] ?? 'groupe' );
		$uid = (int) FKC_Auth::id();

		if ( 'direct' === $type ) {
			list( $ok, $msg, $id ) = FKC_Connect::direct( $uid, (int) ( $_POST['destinataire'] ?? 0 ) );
		} else {
			$action = 'canal' === $type ? 'creer_canal' : 'creer_groupe';
			if ( ! FKC_Connect_Droits::peut( $action, $uid ) ) {
				$this->flash( 'Votre rôle ne permet pas de créer ' . ( 'canal' === $type ? 'un canal' : 'un groupe' ) . '.', 'err' );
				redirect( 'connect' );
			}
			$membres = array();
			foreach ( (array) ( $_POST['membres'] ?? array() ) as $m ) { $membres[] = (int) $m; }
			list( $ok, $msg, $id ) = FKC_Connect::creer( array(
				'type'      => $type,
				'titre'     => (string) ( $_POST['titre'] ?? '' ),
				'niveau'    => isset( $_POST['niveau'] ) ? (int) $_POST['niveau'] : null,
				'equipe_id' => (int) ( $_POST['equipe_id'] ?? 0 ) ?: null,
				'membres'   => $membres,
			) );
		}
		if ( $msg ) { $this->flash( $msg, $ok ? 'ok' : 'err' ); }
		redirect( $ok && $id ? 'connect/conversation/' . (int) $id : 'connect' );
	}

	/** Envoi d'un message. */
	public function envoyer( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		list( $ok, $msg ) = FKC_Connect::envoyer( (int) $id, (string) ( $_POST['texte'] ?? '' ), array(
			'parent_id'  => (int) ( $_POST['parent_id'] ?? 0 ) ?: null,
			'client_uid' => (string) ( $_POST['client_uid'] ?? '' ),
			'importance' => ! empty( $_POST['importance'] ),
		) );
		if ( ! $ok ) { $this->flash( $msg, 'err' ); }
		redirect( 'connect/conversation/' . (int) $id );
	}

	/** Dépôt d'un document ou d'un message vocal. */
	public function piece( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		list( $ok, $msg ) = FKC_Connect_Piece::deposer( (int) $id, $_FILES['piece'] ?? array(), array(
			'texte' => (string) ( $_POST['texte'] ?? '' ),
			'vocal' => ! empty( $_POST['vocal'] ),
			'duree' => (int) ( $_POST['duree'] ?? 0 ),
		) );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . (int) $id );
	}

	/**
	 * Sert une pièce. Aucun rendu de gabarit ici : on écrit le fichier, ou
	 * l'on répond en clair pourquoi on ne l'écrit pas. Un refus rendu dans la
	 * mise en page complète produirait une page HTML enregistrée sous le nom
	 * du document demandé.
	 */
	public function pieceServir( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) {
			http_response_code( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "Service indisponible.\n";
			return;
		}
		list( $ok, $msg ) = FKC_Connect_Piece::servir( (int) $id );
		if ( ! $ok ) {
			http_response_code( 'Pièce introuvable.' === $msg ? 404 : 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo $msg . "\n";
		}
	}

	public function pieceSupprimer( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$p = FKC_Connect_Piece::find( (int) $id );
		list( $ok, $msg ) = FKC_Connect_Piece::supprimer( (int) $id );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . (int) ( $p['conversation_id'] ?? 0 ) );
	}

	/** Action d'une carte de pièce (approuver, refuser…). */
	public function action( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		list( $ok, $msg ) = FKC_Connect_Objet::agir( (int) $id, (string) ( $_POST['code'] ?? '' ), array(
			'note' => (string) ( $_POST['note'] ?? '' ),
		) );
		$this->flash( $msg ?: ( $ok ? 'Action effectuée.' : 'Action non effectuée.' ), $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . (int) $id );
	}

	public function reaction( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$m = FKC_Connect::message( (int) $id );
		list( , $msg ) = FKC_Connect::reagir( (int) $id, (string) ( $_POST['emoji'] ?? '👍' ) );
		redirect( 'connect/conversation/' . (int) ( $m['conversation_id'] ?? 0 ) );
	}

	public function modifier( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$m = FKC_Connect::message( (int) $id );
		list( $ok, $msg ) = FKC_Connect::modifier( (int) $id, (string) ( $_POST['texte'] ?? '' ) );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . (int) ( $m['conversation_id'] ?? 0 ) );
	}

	public function supprimer( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$m = FKC_Connect::message( (int) $id );
		list( $ok, $msg ) = FKC_Connect::supprimer( (int) $id );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . (int) ( $m['conversation_id'] ?? 0 ) );
	}

	public function epingler( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$m = FKC_Connect::message( (int) $id );
		FKC_Connect::epingler( (int) $id );
		redirect( 'connect/conversation/' . (int) ( $m['conversation_id'] ?? 0 ) );
	}

	/** Ajout / retrait de membres. */
	public function membres( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$conv = FKC_Connect::find( (int) $id );
		$uid = (int) FKC_Auth::id();
		if ( ! $conv || ! FKC_Connect_Droits::peutVoirConversation( $conv, $uid ) ) { return $this->refus(); }
		if ( ! FKC_Connect_Droits::peut( 'inviter', $uid ) ) {
			$this->flash( 'Votre rôle ne permet pas d\'inviter.', 'err' );
			redirect( 'connect/conversation/' . (int) $id );
		}
		if ( ! empty( $_POST['retirer'] ) ) {
			list( , $msg ) = FKC_Connect::retirerMembre( (int) $id, (int) $_POST['retirer'] );
		} else {
			list( , $msg ) = FKC_Connect::ajouterMembre( (int) $id, (int) ( $_POST['ajouter'] ?? 0 ) );
		}
		$this->flash( $msg );
		redirect( 'connect/conversation/' . (int) $id );
	}

	/**
	 * Ouvre la conversation d'une pièce depuis un écran métier.
	 * C'est la porte d'entrée du bouton 💬 des factures et des commandes.
	 */
	public function objet( $type, $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		list( $ok, $msg, $convId ) = FKC_Connect_Objet::conversation( (string) $type, (int) $id );
		if ( ! $ok ) {
			$this->flash( $msg, 'err' );
			redirect( 'connect' );
		}
		redirect( 'connect/conversation/' . (int) $convId );
	}

	/**
	 * Flux JSON d'une conversation — c'est ce que le navigateur interroge
	 * toutes les quelques secondes en l'absence de relais temps réel.
	 *
	 * ON NE REND QUE CE QUI EST NOUVEAU (`apres`), et les droits sont
	 * revérifiés à chaque appel : un utilisateur retiré d'un groupe cesse de
	 * recevoir le fil à la seconde suivante, sans que rien n'ait à le
	 * « déconnecter ».
	 */
	public function flux( $id ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! FKC_Connect_Droits::serviceActif() ) {
			http_response_code( 403 );
			echo json_encode( array( 'ok' => false, 'message' => 'Service indisponible.' ) );
			return;
		}
		$uid = (int) FKC_Auth::id();
		$conv = FKC_Connect::find( (int) $id );
		if ( ! $conv || ! FKC_Connect_Droits::peutVoirConversation( $conv, $uid ) ) {
			http_response_code( 404 );
			echo json_encode( array( 'ok' => false, 'message' => 'Conversation introuvable.' ) );
			return;
		}
		$apres = (int) ( $_GET['apres'] ?? 0 );
		$out = array();
		foreach ( FKC_Connect::messages( (int) $id, array( 'apres_id' => $apres, 'limit' => 50 ) ) as $m ) {
			$out[] = array(
				'id' => (int) $m['id'], 'auteur' => $m['auteur_nom'], 'auteur_id' => (int) $m['auteur_id'],
				'texte' => (string) $m['texte'], 'type' => $m['type'], 'date' => $m['created_at'],
				'supprime' => (bool) $m['supprime_a'],
			);
		}
		/*
		 * LIVRÉ D'ABORD, LU ENSUITE — et seulement si l'onglet est visible.
		 * Le flux tourne aussi en arrière-plan : marquer « lu » à ce
		 * moment-là dirait que la personne a ouvert le message alors que
		 * seul son navigateur l'a reçu.
		 */
		if ( $out ) {
			$dernier = (int) end( $out )['id'];
			FKC_Connect::marquerLivre( (int) $id, $uid, $dernier );
			if ( ! empty( $_GET['visible'] ) ) { FKC_Connect::marquerLu( (int) $id, $uid ); }
		}
		FKC_Connect::battement( (int) $id, $uid );
		echo json_encode( array(
			'ok'       => true,
			'messages' => $out,
			'non_lus'  => FKC_Connect::nonLus( $uid ),
			'presents' => array_values( FKC_Connect::presents( (int) $id ) ),
			'accuses'  => FKC_Connect::accuses( (int) $id, $uid ),
		), JSON_UNESCAPED_UNICODE );
	}

	/** Recherche, en JSON — alimente aussi la barre transversale. */
	public function recherche() {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! FKC_Connect_Droits::serviceActif() ) { echo json_encode( array( 'ok' => false, 'resultats' => array() ) ); return; }
		$r = FKC_Connect::rechercher( (string) ( $_GET['q'] ?? '' ) );
		echo json_encode( array( 'ok' => true, 'resultats' => $r ), JSON_UNESCAPED_UNICODE );
	}

	/* ── Temps réel ───────────────────────────────────────────────────── */

	/** Jeton de connexion au relais. Court, et rendu à la demande. */
	public function relaisJeton() {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		if ( ! FKC_Connect_Droits::serviceActif() || ! FKC_Connect_Relais::actif() ) {
			echo json_encode( array( 'ok' => false ) );
			return;
		}
		$j = FKC_Connect_Relais::jeton();
		echo json_encode( array(
			'ok'     => (bool) $j,
			'jeton'  => $j,
			'url'    => FKC_Connect_Relais::url(),
			'expire' => FKC_Connect_Relais::TTL,
		), JSON_UNESCAPED_SLASHES );
	}

	/**
	 * « X est en train d'écrire ».
	 *
	 * RIEN N'EST ÉCRIT EN BASE. Un indicateur de saisie vit trois secondes ;
	 * le stocker ferait une écriture par frappe et par personne, pour une
	 * information périmée avant d'être relue. Sans relais, la fonction
	 * n'existe simplement pas — c'est le seul endroit du chantier où
	 * l'absence de relais retire quelque chose.
	 */
	public function frappe( $id ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! FKC_Connect_Droits::serviceActif() || ! FKC_Connect_Relais::actif() ) {
			echo json_encode( array( 'ok' => false ) );
			return;
		}
		$conv = FKC_Connect::find( (int) $id );
		$uid = (int) FKC_Auth::id();
		if ( ! $conv || ! FKC_Connect_Droits::peutEcrireDans( $conv, $uid ) ) {
			http_response_code( 403 );
			echo json_encode( array( 'ok' => false ) );
			return;
		}
		FKC_Connect_Relais::publier( (int) $id, 'frappe', array( 'qui' => FKC_Connect::nom( $uid ), 'uid' => $uid ) );
		echo json_encode( array( 'ok' => true ) );
	}

	public function relaisConfig() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		if ( ! FKC_Connect_Droits::peut( 'admin' ) ) { return $this->refus(); }
		list( $ok, $msg ) = FKC_Connect_Relais::configurer(
			(string) ( $_POST['url'] ?? '' ), (string) ( $_POST['secret'] ?? '' ) );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/administration' );
	}

	/* ── Appels ───────────────────────────────────────────────────────── */

	public function appelDemarrer( $id ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! FKC_Connect_Droits::serviceActif() ) { echo json_encode( array( 'ok' => false ) ); return; }
		list( $ok, $msg, $appel ) = FKC_Connect_Appel::demarrer( (int) $id, (string) ( $_POST['type'] ?? 'audio' ) );
		echo json_encode( array(
			'ok' => $ok, 'message' => $msg, 'appel' => $appel,
			'moi' => (int) FKC_Auth::id(),
			'participants' => $appel ? FKC_Connect_Appel::participants( (int) $appel ) : array(),
		), JSON_UNESCAPED_UNICODE );
	}

	public function appelRejoindre( $id ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! FKC_Connect_Droits::serviceActif() ) { echo json_encode( array( 'ok' => false ) ); return; }
		list( $ok, $msg ) = FKC_Connect_Appel::rejoindre( (int) $id );
		echo json_encode( array(
			'ok' => $ok, 'message' => $msg, 'moi' => (int) FKC_Auth::id(),
			'participants' => FKC_Connect_Appel::participants( (int) $id ),
		), JSON_UNESCAPED_UNICODE );
	}

	public function appelQuitter( $id ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		list( $ok, $msg ) = FKC_Connect_Appel::quitter( (int) $id );
		echo json_encode( array( 'ok' => $ok, 'message' => $msg ), JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Relaie un message de signalisation.
	 *
	 * FinaKop ne lit pas la charge — c'est du SDP ou un candidat ICE. Il
	 * vérifie que les deux bouts sont dans l'appel, et republie.
	 */
	public function appelSignal( $id ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! FKC_Connect_Droits::serviceActif() ) { echo json_encode( array( 'ok' => false ) ); return; }
		list( $ok, $msg ) = FKC_Connect_Appel::signaler(
			(int) $id, (int) ( $_POST['vers'] ?? 0 ), (string) ( $_POST['sdp'] ?? '' ) );
		echo json_encode( array( 'ok' => $ok, 'message' => $msg ), JSON_UNESCAPED_UNICODE );
	}

	/** Configuration ICE, avec identifiants TURN temporaires. */
	public function appelIce() {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		if ( ! FKC_Connect_Droits::serviceActif() || ! FKC_Connect_Appel::disponible() ) {
			echo json_encode( array( 'iceServers' => array() ) );
			return;
		}
		echo json_encode( FKC_Connect_Appel::ice(), JSON_UNESCAPED_SLASHES );
	}

	public function appelIceConfig() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		if ( ! FKC_Connect_Droits::peut( 'admin' ) ) { return $this->refus(); }
		list( $ok, $msg ) = FKC_Connect_Appel::configurerIce(
			(string) ( $_POST['stun'] ?? '' ), (string) ( $_POST['turn'] ?? '' ), (string) ( $_POST['secret'] ?? '' ) );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/administration' );
	}

	/* ── Journal d'audit ──────────────────────────────────────────────── */

	/**
	 * Consultation du journal.
	 *
	 * Réservée au droit `connect.audit`, et TRACÉE : consulter le journal
	 * est un acte qui laisse une ligne, sinon le droit d'audit serait un
	 * droit de lire en silence. On ne s'exempte pas soi-même de la règle
	 * qu'on applique aux autres.
	 */
	public function audit() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		if ( ! FKC_Connect_Droits::peut( 'audit' ) ) { return $this->refus(); }
		$q = trim( (string) ( $_GET['q'] ?? '' ) );
		FKC_Connect_Droits::tracerAudit( null, 'consultation du journal' . ( '' !== $q ? ' (filtre : ' . $q . ')' : '' ) );
		return FKC_View::render( 'connect::audit', array(
			'title'   => 'FinaKop Connect — journal d\'audit',
			'lignes'  => $this->lignesAudit( $q ),
			'q'       => $q,
		) );
	}

	/**
	 * Export CSV du journal.
	 *
	 * Un journal qu'on ne peut pas sortir n'est pas opposable : le
	 * commissaire aux comptes, l'avocat ou l'assureur qui le demande ne va
	 * pas faire défiler un écran.
	 */
	public function auditExport() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		if ( ! FKC_Connect_Droits::peut( 'audit' ) ) { return $this->refus(); }
		$q = trim( (string) ( $_GET['q'] ?? '' ) );
		FKC_Connect_Droits::tracerAudit( null, 'export du journal' . ( '' !== $q ? ' (filtre : ' . $q . ')' : '' ) );
		$lignes = $this->lignesAudit( $q, 5000 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="connect-audit-' . date( 'Ymd-His' ) . '.csv"' );
		}
		// BOM : sans lui, Excel en configuration française lit l'UTF-8 de
		// travers et les accents partent en caractères illisibles.
		echo "\xEF\xBB\xBF";
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_map( 'fkc_csv_cellule', array( 'Date', 'Action', 'Conversation', 'Utilisateur', 'Détail' ) ), ';' );
		foreach ( $lignes as $l ) {
			fputcsv( $out, array_map( 'fkc_csv_cellule', array( $l['created_at'], $l['action'], $l['conversation_id'], $l['qui'], $l['detail'] ) ), ';' );
		}
		fclose( $out );
	}

	protected function lignesAudit( $q = '', $limit = 300 ) {
		$lignes = FKC_Connect::audit( $limit );
		$out = array();
		foreach ( $lignes as $l ) {
			$l['qui'] = $l['user_id'] ? FKC_Connect::nom( (int) $l['user_id'] ) : '—';
			if ( '' !== $q ) {
				$hay = $l['action'] . ' ' . $l['detail'] . ' ' . $l['qui'];
				if ( false === stripos( $hay, $q ) ) { continue; }
			}
			$out[] = $l;
		}
		return $out;
	}

	/** Publie une réponse de Maestro restée privée. */
	public function partagerMaestro( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$m = FKC_Connect::message( (int) $id );
		list( $ok, $msg ) = FKC_Connect_Maestro::partager( (int) $id );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . (int) ( $m['conversation_id'] ?? 0 ) );
	}

	/* ── Invités externes ─────────────────────────────────────────────── */

	/** Crée une invitation et affiche UNE SEULE FOIS le lien et le code. */
	public function inviterExterne( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		list( $ok, $msg, $acces ) = FKC_Connect_Invite::creer( (int) $id,
			(string) ( $_POST['nom'] ?? '' ), (string) ( $_POST['contact'] ?? '' ) );
		if ( $ok && $acces ) {
			/*
			 * Le lien et le code transitent par la session, pas par l'URL :
			 * une redirection qui les porterait les écrirait dans les
			 * journaux du serveur et dans l'historique du navigateur.
			 */
			$_SESSION['__cx_acces'] = $acces;
		}
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . (int) $id );
	}

	public function revoquerExterne( $id ) {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		$conv = (int) ( $_POST['conversation'] ?? 0 );
		list( $ok, $msg ) = FKC_Connect_Invite::revoquer( (int) $id );
		$this->flash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'connect/conversation/' . $conv );
	}

	/* ── Notifications poussées ───────────────────────────────────────── */

	public function pushAbonner() {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! FKC_Connect_Droits::serviceActif() ) { echo json_encode( array( 'ok' => false ) ); return; }
		list( $ok, $msg ) = FKC_Connect_Push::abonner(
			(string) ( $_POST['endpoint'] ?? '' ),
			(string) ( $_POST['p256dh'] ?? '' ),
			(string) ( $_POST['auth'] ?? '' ),
			(string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' )
		);
		echo json_encode( array( 'ok' => $ok, 'message' => $msg ), JSON_UNESCAPED_UNICODE );
	}

	public function pushDesabonner() {
		header( 'Content-Type: application/json; charset=utf-8' );
		list( $ok, $msg ) = FKC_Connect_Push::desabonner( (string) ( $_POST['endpoint'] ?? '' ) );
		echo json_encode( array( 'ok' => $ok, 'message' => $msg ), JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Ce que le service worker vient chercher après une poussée.
	 *
	 * La poussée ne transporte AUCUN contenu : c'est ici que le texte de la
	 * notification est décidé, avec la session et donc les droits de
	 * l'utilisateur. Quelqu'un retiré d'un groupe entre l'envoi et le réveil
	 * ne verra pas le titre de la conversation.
	 */
	public function badge() {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		if ( ! FKC_Connect_Droits::serviceActif() ) { echo json_encode( array( 'ok' => false, 'n' => 0 ) ); return; }
		$uid = (int) FKC_Auth::id();
		$n = FKC_Connect::nonLus( $uid );
		$titre = ''; $fils = 0;
		foreach ( FKC_Connect::conversations( $uid, 30 ) as $c ) {
			if ( empty( $c['non_lus'] ) ) { continue; }
			$fils++;
			if ( '' === $titre ) { $titre = (string) $c['titre_affiche']; }
		}
		echo json_encode( array(
			'ok'    => true,
			'n'     => $n,
			'fils'  => $fils,
			'titre' => $titre,
			'url'   => url( 'connect' ),
		), JSON_UNESCAPED_UNICODE );
	}

	/* ── Administration (Mode Expert) ─────────────────────────────────── */

	public function administration() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		if ( ! FKC_Connect_Droits::peut( 'admin' ) ) { return $this->refus(); }
		$reglages = array();
		foreach ( FKC_Connect_Politique::catalogue() as $cle => $def ) {
			$def['valeur'] = FKC_Connect_Politique::get( $cle );
			$reglages[ $cle ] = $def;
		}
		return FKC_View::render( 'connect::administration', array(
			'title'     => 'FinaKop Connect — administration',
			'reglages'  => $reglages,
			'algo'      => FKC_Connect_Chiffre::algo(),
			'externe'   => FKC_Connect_Politique::externeActif(),
			'maestro'   => FKC_Connect_Politique::maestroActif(),
			'fonctions' => self::etatFonctions(),
			'push'      => FKC_Connect_Push::diagnostic(),
			'retention' => FKC_Connect_Retention::etat(),
			'relais'    => FKC_Connect_Relais::diagnostic(),
			'externe'   => FKC_Connect_Externe_Garde::etat(),
			'appels'    => FKC_Connect_Appel::diagnostic(),
			'cles'      => FKC_Connect_Chiffre::sante(),
			'volume'    => FKC_Connect_Piece::volume(),
			'audit'     => FKC_Connect::audit( 60 ),
			'flash'     => $this->prendreFlash(),
		) );
	}

	public function administrationSave() {
		if ( ! FKC_Connect_Droits::serviceActif() ) { return $this->refus(); }
		if ( ! FKC_Connect_Droits::peut( 'admin' ) ) { return $this->refus(); }
		$erreurs = array();
		foreach ( FKC_Connect_Politique::catalogue() as $cle => $def ) {
			if ( 'bool' === $def['type'] ) {
				list( $ok, $msg ) = FKC_Connect_Politique::set( $cle, ! empty( $_POST[ $cle ] ) );
			} else {
				if ( ! isset( $_POST[ $cle ] ) ) { continue; }
				list( $ok, $msg ) = FKC_Connect_Politique::set( $cle, $_POST[ $cle ] );
			}
			if ( ! $ok ) { $erreurs[] = $msg; }
		}
		FKC_Connect::tracer( 'politique_modifiee', null, 'administration' );
		$this->flash( $erreurs ? implode( ' · ', $erreurs ) : 'Politique enregistrée.', $erreurs ? 'err' : 'ok' );
		redirect( 'connect/administration' );
	}

	/** État des fonctions de licence du service, pour l'écran d'administration. */
	public static function etatFonctions() {
		$out = array();
		foreach ( FKC_Modules::featuresParModule()['connect'] ?? array() as $code => $def ) {
			$out[ $code ] = array( 'label' => $def['label'], 'ouverte' => FKC_Modules::featureEnabled( $code ) );
		}
		return $out;
	}
}
