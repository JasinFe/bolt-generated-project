<?php
/**
 * Terminal de vente (POS) — contrôleur.
 *
 * Écran tactile de caisse : catalogue, panier, encaissement mixte, tickets en
 * attente, plan de salle, retours, rapports X/Z, configuration par métier.
 * Les actions du terminal répondent en JSON : la caisse doit être instantanée.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_PosController {

	/* ═══════════════ Terminal ═══════════════ */

	public function terminal() {
		$sess = $this->session();
		if ( ! $sess ) {
			$_SESSION['__flash_caisse'] = array( 'type' => 'err', 'msg' => 'Ouvrez d\'abord une session de caisse.' );
			return redirect( 'caisse' );
		}
		$cfg = FKC_PosConfig::all();
		$ticketId = FKC_Pos::ouvrir( (int) $sess['id'], (int) $sess['caisse_id'] );

		FKC_View::render( 'caisse::pos', array(
			'title'   => 'Caisse — Terminal de vente', 'tab' => 'pos',
			'session' => $sess, 'cfg' => $cfg,
			'ticket'  => FKC_Pos::ticket( $ticketId ),
			'lignes'  => FKC_Pos::lignes( $ticketId ),
			'rayons'  => FKC_Pos::rayons(),
			'favoris' => FKC_Pos::favoris(),
			'articles'=> FKC_Pos::articles( null, '', 60 ),
			'attente' => FKC_Pos::enAttente(),
			'tables'  => ! empty( $cfg['tables'] ) ? FKC_Pos::tables() : array(),
			'modes'   => FKC_PosConfig::modesLabels(),
			'clients' => $this->clients(),
			/*
			 * LOT 2.5 — report sur la note d'une chambre. La liste est vide
			 * quand le pack Hôtel n'est pas là OU qu'aucun client n'est en
			 * séjour : le bouton ne s'affiche alors pas du tout, plutôt que
			 * d'ouvrir une modale sans rien à choisir.
			 */
			'chambres' => FKC_Pos::chambresOccupees(),
			'naturesChambre' => $this->naturesChambre(),
		), 'layout_pos' );
	}

	/* ═══════════════ Actions AJAX du terminal ═══════════════ */

	/** Ajoute un article (touche rapide, recherche ou scan). */
	public function ajouter() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session ouverte.' ) ); }
		$qte = (float) ( $_POST['quantite'] ?? 1 );
		if ( $qte <= 0 ) { $qte = 1; }

		// Scan : on passe par le moteur d'identification (contexte vente).
		if ( ! empty( $_POST['code'] ) && class_exists( 'FKC_Scan' ) ) {
			$code = (string) $_POST['code'];
			/*
			 * Code-barres de balance (prix/poids) — 1.829.0 : SEULEMENT si le
			 * code n'est pas au registre. Décodé « en priorité », il captait
			 * tout code commençant par 2 : les EAN-13 internes du magasin
			 * (plage 20…) devenaient des « articles balance inconnus » et ne
			 * passaient plus en caisse dès que la pesée était activée. Le
			 * moteur central applique déjà cette règle ; la caisse s'y aligne.
			 */
			$auRegistre = method_exists( 'FKC_Scan', 'identifier' ) && FKC_Scan::identifier( $code );
			if ( ! $auRegistre && class_exists( 'FKC_PosPesee' ) && FKC_PosPesee::actif() ) {
				$pese = FKC_PosPesee::resoudre( $code );
				if ( is_array( $pese ) ) {
					if ( ! empty( $pese['erreur'] ) ) { return $this->json( array( 'ok' => false, 'message' => $pese['erreur'] ) ); }
					list( $ok, $msg ) = FKC_Pos::ajouterArticle( (int) $t['id'], (int) $pese['article_id'], (float) $pese['quantite'],
						array( 'pu' => (float) $pese['pu'], 'tva' => (float) $pese['tva'] ) );
					return $this->etat( $t['id'], $ok, $msg );
				}
			}
			$res = FKC_Scan::resoudre( $code, 'vente', array( 'quantite' => $qte, 'module' => 'caisse', 'canal' => 'clavier' ) );
			if ( ! $res['ok'] || empty( $res['entite_id'] ) || 'article' !== $res['entite_type'] ) {
				// 1.829.0 : un code RECONNU mais qui n'est pas un article vendable
				// affichait son libellé comme message d'erreur (« Huile 1L »).
				/*
				 * 1.841.0 — un article du magasin Retail se vend ICI : il est
				 * relié (ou créé) au catalogue de la caisse par le pont
				 * d'articles, et la vente redescend dans le stock du magasin.
				 * Le message renvoyait vers la caisse Retail — que la
				 * convergence ferme.
				 */
				if ( $res['ok'] && 'rt_article' === $res['entite_type'] && class_exists( 'FKC_ArticleBridge' ) ) {
					$invRt = FKC_ArticleBridge::versInventaire( 'retail', (int) $res['entite_id'] );
					if ( $invRt ) {
						list( $ok, $msg ) = FKC_Pos::ajouterArticle( (int) $t['id'], (int) $invRt, (float) ( $res['quantite'] ?? $qte ),
							array( 'series' => $_POST['series'] ?? null ) );
						return $this->etatSeries( $t['id'], $ok, $msg );
					}
				}
				if ( $res['ok'] && 'rt_article' === $res['entite_type'] ) {
					$m = 'Article du magasin Retail (« ' . ( $res['libelle'] ?: $res['code'] ) . ' ») non relié au catalogue de la caisse : lancez le rapprochement (Caisse → Reprise Retail).';
				} elseif ( $res['ok'] ) {
					$m = 'Ce code désigne « ' . ( $res['libelle'] ?: $res['entite_type'] ) . ' » (' . $res['entite_type'] . ') : il ne se vend pas à la caisse.';
				} else {
					$m = $res['message'] ?: 'Code inconnu.';
				}
				return $this->json( array( 'ok' => false, 'message' => $m ) );
			}
			list( $ok, $msg ) = FKC_Pos::ajouterArticle( (int) $t['id'], (int) $res['entite_id'], (float) $res['quantite'],
				array( 'series' => $_POST['series'] ?? null ) );
			return $this->etatSeries( $t['id'], $ok, $msg );
		}

		// Pesée manuelle : article au poids + poids saisi.
		if ( ! empty( $_POST['pesee'] ) && ! empty( $_POST['article_id'] ) && class_exists( 'FKC_PosPesee' ) ) {
			list( $ok, $msg ) = FKC_PosPesee::ajouterPese( (int) $t['id'], (int) $_POST['article_id'], (float) ( $_POST['poids'] ?? 0 ) );
			return $this->etat( $t['id'], $ok, $msg );
		}

		if ( ! empty( $_POST['libre'] ) ) {
			list( $ok, $msg ) = FKC_Pos::ajouterLibre( (int) $t['id'], $_POST['libelle'] ?? '', (float) ( $_POST['montant'] ?? 0 ), null, $qte );
			return $this->etat( $t['id'], $ok, $msg );
		}

		$articleId = (int) ( $_POST['article_id'] ?? 0 );
		if ( ! $articleId ) { return $this->json( array( 'ok' => false, 'message' => 'Article manquant.' ) ); }
		list( $ok, $msg ) = FKC_Pos::ajouterArticle( (int) $t['id'], $articleId, $qte, array(
			'cours' => (int) ( $_POST['cours'] ?? 1 ), 'note' => $_POST['note'] ?? '',
			'series' => $_POST['series'] ?? null,
		) );
		return $this->etatSeries( $t['id'], $ok, $msg );
	}

	public function majLigne() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		$d = array();
		foreach ( array( 'quantite', 'pu', 'remise', 'note' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) { $d[ $k ] = $_POST[ $k ]; }
		}
		if ( ! $this->ligneDuTicket( (int) ( $_POST['ligne_id'] ?? 0 ), $t ) ) {
			return $this->json( array( 'ok' => false, 'message' => 'Cette ligne n\'appartient pas au ticket en cours.' ) );
		}
		if ( ! $this->codeSiFourni( 'remise', $t ) ) { return; }
		list( $ok, $msg ) = FKC_Pos::majLigne( (int) ( $_POST['ligne_id'] ?? 0 ), $d );
		return $this->etatAutorisation( $t['id'], $ok, $msg, 'remise' );
	}

	public function supprimerLigne() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		// Le motif, quand le poste le fournit, rejoint l'archive des lignes
		// retirées (C8) : « erreur de saisie » et « client a changé d'avis »
		// ne se contrôlent pas de la même façon.
		if ( ! $this->ligneDuTicket( (int) ( $_POST['ligne_id'] ?? 0 ), $t ) ) {
			return $this->json( array( 'ok' => false, 'message' => 'Cette ligne n\'appartient pas au ticket en cours.' ) );
		}
		/*
		 * 1.835.0 — une ligne DÉJÀ ENVOYÉE en préparation ne se retire que
		 * sous supervision : c'est le vecteur de démarque que l'archive C8
		 * trace, et qu'elle ne suffisait pas à empêcher.
		 */
		$l = FKC_Pos::ligne( (int) ( $_POST['ligne_id'] ?? 0 ) );
		if ( $l && (int) $l['envoye'] === 1 && ! $this->exigerJson( 'retrait', array(
			'ticket_id' => (int) $t['id'], 'montant' => (float) $l['total_ttc'], 'motif' => (string) ( $_POST['motif'] ?? $l['libelle'] ),
		) ) ) { return; }
		list( $ok, $msg ) = FKC_Pos::supprimerLigne( (int) ( $_POST['ligne_id'] ?? 0 ), (string) ( $_POST['motif'] ?? '' ) );
		return $this->etat( $t['id'], $ok, $msg );
	}

	public function remise() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		if ( ! $this->codeSiFourni( 'remise', $t ) ) { return; }
		list( $ok, $msg ) = FKC_Pos::remiseGlobale( (int) $t['id'], (float) ( $_POST['taux'] ?? 0 ) );
		return $this->etatAutorisation( $t['id'], $ok, $msg, 'remise' );
	}

	/** Client, couverts, note, pourboire, table. */
	public function majTicket() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		$d = array();
		foreach ( array( 'client_id', 'client_nom', 'couverts', 'note', 'pourboire', 'table_id' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) { $d[ $k ] = $_POST[ $k ]; }
		}
		FKC_Pos::majTicket( (int) $t['id'], $d );
		return $this->etat( $t['id'], true, 'Ticket mis à jour.' );
	}

	/** Encaissement (paiement simple ou mixte). */
	/**
	 * Reporte l'addition en cours sur la note d'une chambre (LOT 2.5).
	 *
	 * L'action est refusée quand aucune chambre n'est occupée : proposer un
	 * report vers un hôtel vide n'aurait aucun sens et masquerait le vrai
	 * problème (le pack Hôtel n'est pas installé, ou personne n'a fait de
	 * check-in).
	 */
	public function chambre() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		$chambreId = (int) ( $_POST['chambre_id'] ?? 0 );
		if ( $chambreId <= 0 ) {
			return $this->json( array( 'ok' => false, 'message' => 'Choisissez la chambre à débiter.' ) );
		}
		/*
		 * La nature choisie est VALIDÉE contre le référentiel du Folio : une
		 * nature inventée retomberait sur le compte de l'hébergement, et le
		 * chiffre d'affaires serait juste au total mais faux par département.
		 * Non fournie, le modèle déduit la nature du pack qui vend.
		 */
		$opts = array();
		$nature = (string) ( $_POST['nature'] ?? '' );
		if ( '' !== $nature && class_exists( 'FKC_HotelFolio' )
			&& array_key_exists( $nature, FKC_HotelFolio::natures() ) ) {
			$opts['nature'] = $nature;
		}
		list( $ok, $msg ) = FKC_Pos::facturerChambre( (int) $t['id'], $chambreId, $opts );
		if ( ! $ok ) { return $this->json( array( 'ok' => false, 'message' => $msg ) ); }
		return $this->json( array( 'ok' => true, 'message' => $msg, 'ticket_id' => (int) $t['id'] ) );
	}

	/**
	 * Natures de folio proposées au report, restreintes à ce qu'un terminal
	 * de vente peut légitimement produire.
	 *
	 * On ne propose pas les 17 natures du Folio : « hébergement », « caution »
	 * ou « frais d'annulation » ne sortent jamais d'une caisse, et les offrir
	 * inviterait à ranger une addition de restaurant sous une nature qui
	 * fausserait le chiffre d'affaires par département.
	 */
	protected function naturesChambre() {
		if ( ! class_exists( 'FKC_HotelFolio' ) ) { return array(); }
		$toutes = FKC_HotelFolio::natures();
		$out = array();
		foreach ( array( 'restaurant', 'bar', 'minibar', 'room_service', 'boutique', 'spa', 'piscine' ) as $k ) {
			if ( isset( $toutes[ $k ] ) ) { $out[ $k ] = $toutes[ $k ]; }
		}
		return $out;
	}

	/**
	 * Vérifie un numéro d'avoir présenté au comptoir et renvoie son solde.
	 *
	 * Contrôler AVANT l'encaissement plutôt qu'au moment de valider : un
	 * caissier qui découvre à la validation que le bon est déjà soldé doit
	 * défaire son règlement devant le client. Ici il le sait à la saisie.
	 */
	public function avoir() {
		$num = trim( (string) ( $_POST['numero'] ?? '' ) );
		if ( '' === $num ) { return $this->json( array( 'ok' => false, 'message' => 'Numéro d\'avoir vide.' ) ); }
		$a = FKC_Pos::avoirParNumero( $num );
		if ( ! $a ) { return $this->json( array( 'ok' => false, 'message' => 'Avoir introuvable ou déjà soldé.' ) ); }
		return $this->json( array(
			'ok' => true,
			'numero' => $a['numero'],
			'tiers' => $a['tiers'],
			'restant' => round( (float) $a['montant'] - (float) $a['consomme'], 2 ),
		) );
	}

	/** Chambres occupées proposables au report — vide si l'Hôtel n'est pas là. */
	public function chambres() {
		return $this->json( array( 'ok' => true, 'chambres' => FKC_Pos::chambresOccupees() ) );
	}

	/**
	 * Encaisse UNE part d'une addition partagée (LOT 4).
	 *
	 * L'addition reste ouverte tant qu'elle n'est pas soldée, et le terminal
	 * renvoie le reste dû : c'est ce chiffre que le serveur annonce au payeur
	 * suivant.
	 */
	public function part() {
		$sess = $this->session();
		$t = $this->ticketActif();
		if ( ! $t || ! $sess ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }

		$montant = round( (float) ( $_POST['montant'] ?? 0 ), 2 );
		$mode = (string) ( $_POST['mode'] ?? 'espece' );
		if ( $montant <= 0 ) { return $this->json( array( 'ok' => false, 'message' => 'Montant de la part invalide.' ) ); }

		list( $ok, $msg, $reste ) = FKC_Pos::payerPart( (int) $t['id'], array(
			array( 'mode' => $mode, 'montant' => $montant, 'encaisse' => (float) ( $_POST['encaisse'] ?? $montant ) ),
		), (int) $sess['id'] );

		return $this->json( array(
			'ok' => $ok, 'message' => $msg,
			'reste' => $reste,
			// « soldee » dit au terminal s'il doit repartir sur une addition
			// vierge ou rester sur celle-ci pour le payeur suivant.
			'soldee' => ( $ok && $reste <= 0.009 ),
		) );
	}

	/**
	 * Calcule la part à demander, sans rien encaisser.
	 *
	 * Deux façons de partager : en parts égales, ou par sélection de lignes
	 * (« chacun paie ce qu'il a pris »). Le calcul vit dans le modèle ; cette
	 * action ne fait que l'exposer au terminal.
	 */
	public function calculerPart() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		$reste = FKC_Pos::resteAPayer( (int) $t['id'] );

		$lignes = (array) ( $_POST['lignes'] ?? array() );
		if ( $lignes ) {
			$m = FKC_Pos::partDesLignes( (int) $t['id'], $lignes );
		} else {
			$m = FKC_Pos::partEnParts( (int) $t['id'], (int) ( $_POST['parts'] ?? 2 ) );
		}
		return $this->json( array( 'ok' => true, 'montant' => $m, 'reste' => $reste ) );
	}

	public function encaisser() {
		$sess = $this->session();
		$t = $this->ticketActif();
		if ( ! $t || ! $sess ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		// 1.844.0 — une vacation ouverte AVANT la règle ne vend pas non plus
		// tant que le régime n'est pas déclaré.
		if ( class_exists( 'FKC_FiscalConfig' ) ) {
			list( $okR, $msgR ) = FKC_FiscalConfig::exigerRegime( 'Encaissement impossible' );
			if ( ! $okR ) { return $this->json( array( 'ok' => false, 'message' => $msgR ) ); }
		}

		$paiements = array();
		$raw = $_POST['paiements'] ?? '';
		if ( is_string( $raw ) && '' !== $raw ) { $paiements = json_decode( $raw, true ) ?: array(); }
		elseif ( is_array( $raw ) ) { $paiements = $raw; }
		if ( ! $paiements ) {
			$paiements = array( array(
				'mode' => (string) ( $_POST['mode'] ?? 'espece' ),
				'montant' => (float) ( $_POST['montant'] ?? 0 ),
				'encaisse' => (float) ( $_POST['encaisse'] ?? 0 ),
			) );
		}

		$res = FKC_Pos::encaisser( (int) $t['id'], $paiements, (int) $sess['id'] );
		if ( ! $res[0] ) { return $this->json( array( 'ok' => false, 'message' => $res[1] ) ); }

		$info = $res[2];
		// Ouvre immédiatement le ticket suivant : la caisse ne s'arrête jamais.
		$suivant = FKC_Pos::ouvrir( (int) $sess['id'], (int) $sess['caisse_id'] );
		return $this->json( array(
			'ok' => true, 'message' => 'Encaissé.', 'rendu' => $info['rendu'],
			'ticket_id' => $info['ticket_id'], 'numero' => $info['numero'], 'total' => $info['total'],
			'url_ticket' => url( 'caisse/ticket/' . $info['ticket_id'] ),
			'etat' => $this->etatTicket( $suivant ),
		) );
	}

	public function attendre() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		list( $ok, $msg ) = FKC_Pos::mettreEnAttente( (int) $t['id'] );
		$sess = $this->session();
		$suivant = $ok ? FKC_Pos::ouvrir( (int) $sess['id'], (int) $sess['caisse_id'] ) : (int) $t['id'];
		return $this->json( array( 'ok' => $ok, 'message' => $msg, 'etat' => $this->etatTicket( $suivant ) ) );
	}

	public function reprendre( $id ) {
		$sess = $this->session();
		if ( ! $sess ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		list( $ok, $msg ) = FKC_Pos::reprendre( (int) $id, (int) $sess['id'] );
		$t = FKC_Pos::ticketCourant( (int) $sess['id'] );
		return $this->json( array( 'ok' => $ok, 'message' => $msg, 'etat' => $t ? $this->etatTicket( (int) $t['id'] ) : null ) );
	}

	public function annuler() {
		$sess = $this->session();
		$t = $this->ticketActif();
		if ( ! $t || ! $sess ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		// 1.835.0 — une addition dont une ligne est partie en préparation ne
		// s'annule que sous supervision (les plats sont faits, le stock sorti).
		$envoyees = array_filter( FKC_Pos::lignes( (int) $t['id'] ), function ( $l ) { return 1 === (int) $l['envoye']; } );
		if ( $envoyees && ! $this->exigerJson( 'annulation', array(
			'ticket_id' => (int) $t['id'], 'montant' => (float) $t['total_ttc'], 'motif' => (string) ( $_POST['motif'] ?? '' ),
		) ) ) { return; }
		list( $ok, $msg ) = FKC_Pos::annuler( (int) $t['id'], (string) ( $_POST['motif'] ?? '' ) );
		$suivant = FKC_Pos::ouvrir( (int) $sess['id'], (int) $sess['caisse_id'] );
		return $this->json( array( 'ok' => $ok, 'message' => $msg, 'etat' => $this->etatTicket( $suivant ) ) );
	}

	/** Recherche d'articles (catalogue du terminal). */
	public function chercher() {
		$q = (string) ( $_GET['q'] ?? '' );
		$rayon = ! empty( $_GET['rayon'] ) ? (int) $_GET['rayon'] : null;
		$this->json( array( 'ok' => true, 'articles' => FKC_Pos::articles( $rayon, $q, 60 ) ) );
	}

	/** Envoi en cuisine (restauration). */
	public function cuisine() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		$res = FKC_Pos::envoyerCuisine( (int) $t['id'] );
		return $this->json( array( 'ok' => $res[0], 'message' => $res[1],
			'url_bon' => $res[0] ? url( 'caisse/bon/' . (int) $t['id'] ) : null,
			'etat' => $this->etatTicket( (int) $t['id'] ) ) );
	}

	/* ═══════════════ Tickets, bons, rapports ═══════════════ */

	/** Ticket de caisse imprimable. */
	public function ticket( $id ) {
		$t = FKC_Pos::ticket( (int) $id );
		if ( ! $t ) { return redirect( 'caisse' ); }
		FKC_View::render( 'caisse::ticket', array(
			'title' => 'Ticket ' . $t['numero'], 'ticket' => $t,
			'lignes' => FKC_Pos::lignes( (int) $id ), 'paiements' => FKC_Pos::paiements( (int) $id ),
			'modes' => FKC_PosConfig::modesLabels(), 'societe' => $this->societe(),
		), null );
	}

	/** Bon de préparation (cuisine / bar). */
	public function bon( $id ) {
		$t = FKC_Pos::ticket( (int) $id );
		if ( ! $t ) { return redirect( 'caisse' ); }
		FKC_View::render( 'caisse::bon', array(
			'title' => 'Bon ' . $t['numero'], 'ticket' => $t, 'lignes' => FKC_Pos::lignes( (int) $id ),
		), null );
	}

	/** Journal des tickets encaissés (recherche, réimpression, retour). */
	public function tickets() {
		FKC_View::render( 'caisse::tickets', array(
			'title' => 'Caisse · Tickets', 'tab' => 'tickets',
			'tickets' => FKC_Pos::ticketsRecents( 60 ),
			'cfg' => FKC_PosConfig::all(), 'flash' => $this->flash(),
		) );
	}

	/**
	 * Prépare un avoir à partir d'un ticket encaissé.
	 *
	 * 1.830.0 — route passée en POST (elle était en GET malgré son effet :
	 * un lien piégé suffisait à ouvrir un avoir au nom du caissier).
	 */
	public function retour( $id ) {
		$sess = $this->session();
		if ( ! $sess ) { $this->setFlash( 'Ouvrez une session de caisse.', 'err' ); return redirect( 'caisse' ); }
		$o = FKC_Pos::ticket( (int) $id );
		list( $okD, $msgD ) = FKC_PosDroits::exiger( 'avoir', array( 'ticket_id' => (int) $id, 'montant' => $o ? (float) $o['total_ttc'] : null ) );
		if ( ! $okD ) { $this->setFlash( $msgD, 'err' ); return redirect( 'caisse/tickets' ); }
		$res = FKC_Pos::creerRetour( (int) $id, (int) $sess['id'], (int) $sess['caisse_id'] );
		if ( ! $res[0] ) { $this->setFlash( $res[1], 'err' ); return redirect( 'caisse/tickets' ); }
		$this->setFlash( 'Avoir préparé : vérifiez les lignes puis encaissez le remboursement.' );
		redirect( 'caisse/pos' );
	}

	/** Rapport X (situation) ou Z (arrêté de vacation). */
	public function rapport() {
		$sess = $this->session();
		$sessionId = (int) ( $_GET['session'] ?? ( $sess['id'] ?? 0 ) );
		if ( ! $sessionId ) { $this->setFlash( 'Aucune session à analyser.', 'err' ); return redirect( 'caisse' ); }
		$s = FKC_Caisse::session( $sessionId );
		if ( ! $s ) { $this->setFlash( 'Session introuvable.', 'err' ); return redirect( 'caisse/journal' ); }
		// 1.835.0 — un caissier lit le rapport de SA vacation ; celui d'une
		// autre relève de la supervision.
		$uid = class_exists( 'FKC_Auth' ) ? (int) FKC_Auth::id() : 0;
		if ( (int) $s['user_id'] !== $uid && ! FKC_PosDroits::peut( FKC_PosDroits::SUPERVISER ) ) {
			$this->setFlash( 'Le rapport d\'une autre vacation est réservé au chef de caisse ou au responsable.', 'err' );
			return redirect( 'caisse/journal' );
		}
		$type = 'z' === strtolower( (string) ( $_GET['type'] ?? 'x' ) ) ? 'Z' : 'X';
		FKC_View::render( 'caisse::rapport', array(
			'title' => "Rapport {$type}", 'tab' => 'journal', 'type' => $type,
			'session' => $s, 'r' => FKC_Pos::rapport( $sessionId ),
			'solde' => FKC_Caisse::soldeTheorique( $s ),
			'modes' => FKC_PosConfig::modesLabels(), 'societe' => $this->societe(),
		) );
	}

	/* ═══════════════ Plan de salle ═══════════════ */

	public function salle() {
		if ( ! FKC_PosConfig::get( 'tables' ) ) { $this->setFlash( 'Le plan de salle n\'est pas activé pour ce métier.', 'err' ); return redirect( 'caisse/configuration' ); }
		$tables = FKC_Pos::tables();
		$occup = array();
		foreach ( $tables as $t ) {
			$tk = FKC_Pos::ticketDeTable( (int) $t['id'] );
			if ( $tk ) { $occup[ (int) $t['id'] ] = $tk; }
		}
		FKC_View::render( 'caisse::salle', array(
			'title' => 'Caisse · Plan de salle', 'tab' => 'salle',
			'tables' => $tables, 'occupation' => $occup, 'flash' => $this->flash(),
		) );
	}

	public function salleStore() {
		if ( ! $this->configurer() ) { return; }
		if ( ! empty( $_POST['supprimer'] ) ) {
			FKC_Pos::supprimerTable( (int) $_POST['supprimer'] );
			$this->setFlash( 'Table retirée.' );
			return redirect( 'caisse/salle' );
		}
		$num = trim( (string) ( $_POST['numero'] ?? '' ) );
		if ( '' === $num ) { $this->setFlash( 'Numéro de table requis.', 'err' ); return redirect( 'caisse/salle' ); }
		FKC_Pos::creerTable( $num, $_POST['zone'] ?? '', (int) ( $_POST['places'] ?? 4 ) );
		$this->setFlash( 'Table ajoutée.' );
		redirect( 'caisse/salle' );
	}

	/** Ouvre (ou reprend) le compte d'une table. */
	public function table( $id ) {
		$sess = $this->session();
		if ( ! $sess ) { $this->setFlash( 'Ouvrez une session de caisse.', 'err' ); return redirect( 'caisse' ); }
		$existant = FKC_Pos::ticketDeTable( (int) $id );
		if ( $existant ) {
			if ( 'en_attente' === $existant['statut'] ) { FKC_Pos::reprendre( (int) $existant['id'], (int) $sess['id'] ); }
		} else {
			$courant = FKC_Pos::ticketCourant( (int) $sess['id'] );
			if ( $courant && FKC_Pos::lignes( $courant['id'] ) ) { FKC_Pos::mettreEnAttente( (int) $courant['id'] ); }
			elseif ( $courant ) { FKC_Pos::annuler( (int) $courant['id'], 'table' ); }
			$tid = FKC_Pos::ouvrir( (int) $sess['id'], (int) $sess['caisse_id'], array( 'table_id' => (int) $id ) );
			FKC_Pos::majTicket( $tid, array( 'table_id' => (int) $id, 'couverts' => (int) ( $_POST['couverts'] ?? $_GET['couverts'] ?? 0 ) ) );
		}
		redirect( 'caisse/pos' );
	}

	/* ═══════════════ Configuration ═══════════════ */

	public function configuration() {
		if ( ! $this->configurer() ) { return; }
		FKC_PosConfig::poste( 0 ); // l'écran règle la SOCIÉTÉ, pas un poste
		$tout = ! empty( $_GET['tout'] );
		FKC_View::render( 'caisse::configuration', array(
			'title' => 'Caisse · Configuration', 'tab' => 'config',
			'cfg' => FKC_PosConfig::all(), 'preset' => FKC_PosConfig::presetDuPack(),
			'champs' => FKC_PosConfig::champs( $tout ), 'modes' => FKC_PosConfig::modesLabels(),
			'tout' => $tout, 'masques' => FKC_PosConfig::nbMasques(),
			'pack' => class_exists( 'FKC_Packs' ) ? FKC_Packs::activeCode() : '',
			'flash' => $this->flash(),
		) );
	}

	public function configurationSave() {
		if ( ! $this->configurer() ) { return; }
		FKC_PosConfig::poste( 0 );
		if ( ! empty( $_POST['reinit'] ) ) {
			FKC_PosConfig::reinitialiser();
			$this->setFlash( 'Configuration réinitialisée aux réglages du métier.' );
			return redirect( 'caisse/configuration' );
		}
		// On n'enregistre QUE les réglages affichés : un champ masqué (hors métier)
		// ne doit jamais être écrasé parce qu'il n'était pas dans le formulaire.
		$tout = ! empty( $_POST['tout'] );
		foreach ( FKC_PosConfig::champs( $tout ) as $groupe ) {
			foreach ( $groupe as $cle => $def ) {
				switch ( $def[1] ) {
					case 'bool':
						FKC_PosConfig::set( $cle, ! empty( $_POST[ $cle ] ) );
						break;
					case 'pct':
					case 'nombre':
						if ( isset( $_POST[ $cle ] ) && '' !== trim( (string) $_POST[ $cle ] ) ) {
							FKC_PosConfig::set( $cle, (float) str_replace( ',', '.', (string) $_POST[ $cle ] ) );
						}
						break;
					case 'modes':
						$m = array_values( array_intersect( (array) ( $_POST[ $cle ] ?? array() ), array_keys( FKC_PosConfig::modesLabels() ) ) );
						if ( $m ) { FKC_PosConfig::set( $cle, $m ); }
						break;
					case 'texte':
						if ( isset( $_POST[ $cle ] ) ) {
							$v = trim( (string) $_POST[ $cle ] );
							// fidelite_mode restreint aux valeurs reconnues.
							if ( 'fidelite_mode' === $cle ) { $v = in_array( $v, array( 'cagnotte', 'points' ), true ) ? $v : 'cagnotte'; }
							FKC_PosConfig::set( $cle, $v );
						}
						break;
				}
			}
		}
		$this->setFlash( 'Configuration de la caisse enregistrée.' );
		redirect( 'caisse/configuration' . ( $tout ? '?tout=1' : '' ) );
	}

	/* ═══════════════ Fidélité (CRM) ═══════════════ */

	public function fidelite() {
		if ( ! FKC_PosFidelite::actif() ) { $this->setFlash( 'La fidélité n\'est pas activée pour ce métier.', 'warn' ); return redirect( 'caisse/configuration' ); }
		FKC_View::render( 'caisse::fidelite', array(
			'title' => 'Caisse · Fidélité & CRM', 'tab' => 'fidelite',
			'mode' => FKC_PosFidelite::mode(), 'synthese' => FKC_PosFidelite::synthese(),
			'top' => FKC_PosFidelite::topClients( 40 ), 'paliers' => FKC_PosFidelite::paliers(),
			'flash' => $this->flash(),
		) );
	}

	/** Solde fidélité d'un client (JSON, appelé depuis le terminal). */
	public function fideliteClient( $id ) { $this->json( FKC_PosFidelite::solde( (int) $id ) ); }

	/** Applique la fidélité au ticket courant (remise cagnotte / points). */
	public function fideliteAppliquer() {
		$sess = $this->session();
		if ( ! $sess ) { $this->json( array( 'ok' => false, 'message' => 'Session fermée.' ) ); }
		$t = FKC_Pos::ticketCourant( (int) $sess['id'] );
		if ( ! $t || empty( $t['client_id'] ) ) { $this->json( array( 'ok' => false, 'message' => 'Associez d\'abord un client au ticket.' ) ); }
		list( $ok, $msg ) = FKC_PosFidelite::utiliser( (int) $t['id'], (int) $t['client_id'] );
		$this->etat( (int) $t['id'], $ok, $msg );
	}

	/** 1.865.0 — applique un bon d'achat / coupon (code) au ticket courant. */
	public function bonAppliquer() {
		$sess = $this->session();
		if ( ! $sess ) { $this->json( array( 'ok' => false, 'message' => 'Session fermée.' ) ); }
		$t = FKC_Pos::ticketCourant( (int) $sess['id'] );
		if ( ! $t ) { $this->json( array( 'ok' => false, 'message' => 'Aucun ticket en cours.' ) ); }
		list( $ok, $msg ) = FKC_PosBons::appliquer( (int) $t['id'], (string) ( $_POST['code'] ?? '' ) );
		$this->etat( (int) $t['id'], $ok, $msg );
	}

	/* ═══════════════ Promotions ═══════════════ */

	public function promotions() {
		FKC_View::render( 'caisse::promotions', array(
			'title' => 'Caisse · Promotions', 'tab' => 'promotions',
			'promos' => FKC_PosPromotions::all(), 'actif' => FKC_PosPromotions::actif(),
			'flash' => $this->flash(),
		) );
	}

	public function promotionStore() {
		if ( ! $this->configurer() ) { return; }
		FKC_PosPromotions::create( array(
			'code' => $_POST['code'] ?? '', 'libelle' => $_POST['libelle'] ?? '',
			'type' => $_POST['type'] ?? 'remise_seuil', 'valeur' => $_POST['valeur'] ?? 0,
			'est_pourcent' => ( ( $_POST['unite'] ?? 'pct' ) === 'pct' ) ? 1 : 0,
			'seuil' => $_POST['seuil'] ?? 0, 'cible' => $_POST['cible'] ?? '', 'offerts' => $_POST['offerts'] ?? 0,
			'jours' => $_POST['jours'] ?? '', 'heure_debut' => $_POST['heure_debut'] ?? '', 'heure_fin' => $_POST['heure_fin'] ?? '',
			'date_debut' => $_POST['date_debut'] ?? '', 'date_fin' => $_POST['date_fin'] ?? '', 'actif' => 1,
		) );
		$this->setFlash( 'Promotion créée.' );
		redirect( 'caisse/promotions' );
	}

	public function promotionToggle( $id ) { if ( ! $this->configurer() ) { return; } FKC_PosPromotions::toggle( (int) $id ); redirect( 'caisse/promotions' ); }
	public function promotionDelete( $id ) { if ( ! $this->configurer() ) { return; } FKC_PosPromotions::delete( (int) $id ); $this->setFlash( 'Promotion supprimée.' ); redirect( 'caisse/promotions' ); }

	/* ═══════════════ Journal fiscal ═══════════════ */

	public function fiscal() {
		FKC_View::render( 'caisse::fiscal', array(
			'title' => 'Caisse · Journal fiscal', 'tab' => 'fiscal',
			'synthese' => FKC_PosFiscal::synthese(), 'verif' => FKC_PosFiscal::verifier(),
			'actif' => FKC_PosFiscal::actif(), 'flash' => $this->flash(),
		) );
	}

	/* ═══════════════ Variantes matricielles ═══════════════ */

	public function variantes() {
		$parentId = (int) ( $_GET['modele'] ?? 0 );
		FKC_View::render( 'caisse::variantes', array(
			'title' => 'Caisse · Variantes', 'tab' => 'variantes',
			'actif' => FKC_PosVariantes::actif(), 'modeles' => FKC_PosVariantes::modeles(),
			'articles' => $this->articlesSansVariante(),
			'matrice' => $parentId ? FKC_PosVariantes::matrice( $parentId ) : null,
			'parentId' => $parentId,
			'parent' => $parentId && class_exists( 'FKC_InvArticle' ) ? FKC_InvArticle::find( $parentId ) : null,
			'flash' => $this->flash(),
		) );
	}

	public function variantesGenerer() {
		if ( ! $this->configurer() ) { return; }
		$parentId = (int) ( $_POST['parent_id'] ?? 0 );
		$v1 = array_filter( array_map( 'trim', explode( ',', (string) ( $_POST['valeurs1'] ?? '' ) ) ), 'strlen' );
		$v2 = array_filter( array_map( 'trim', explode( ',', (string) ( $_POST['valeurs2'] ?? '' ) ) ), 'strlen' );
		list( $ok, $msg ) = FKC_PosVariantes::generer(
			$parentId, (string) ( $_POST['axe1_nom'] ?? 'Taille' ), (string) ( $_POST['axe2_nom'] ?? 'Couleur' ), $v1, $v2
		);
		$this->setFlash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'caisse/variantes?modele=' . $parentId );
	}

	protected function articlesSansVariante() {
		if ( ! class_exists( 'FKC_InvArticle' ) ) { return array(); }
		try {
			return FKC_DB::q(
				"SELECT a.id, a.code, a.designation FROM inv_articles a
				 WHERE a.actif=1 AND a.id NOT IN(SELECT article_id FROM inv_variantes)
				 ORDER BY a.designation COLLATE NOCASE LIMIT 300"
			)->fetchAll();
		} catch ( \Throwable $e ) { return array(); }
	}

	/* ═══════════════ Commandes / Click & Collect ═══════════════ */

	public function commandes() {
		if ( ! FKC_PosCommande::actif() ) { $this->setFlash( 'Les commandes ne sont pas activées pour ce métier.', 'warn' ); return redirect( 'caisse/configuration' ); }
		$filtre = $_GET['statut'] ?? null;
		FKC_View::render( 'caisse::commandes', array(
			'title' => 'Caisse · Commandes', 'tab' => 'commandes',
			'commandes' => FKC_PosCommande::all( $filtre ), 'compteurs' => FKC_PosCommande::compteurs(),
			'statuts' => FKC_PosCommande::statuts(), 'filtre' => $filtre,
			'lignesFn' => array( 'FKC_PosCommande', 'lignes' ),
			'articles' => $this->articlesSansVariante(), 'clients' => $this->clients(),
			'flash' => $this->flash(),
		) );
	}

	public function commandeStore() {
		$lignes = array();
		$ids = (array) ( $_POST['ligne_article'] ?? array() );
		$qtes = (array) ( $_POST['ligne_qte'] ?? array() );
		$pus = (array) ( $_POST['ligne_pu'] ?? array() );
		$libs = (array) ( $_POST['ligne_libelle'] ?? array() );
		foreach ( $ids as $i => $aid ) {
			$q = (float) ( $qtes[ $i ] ?? 0 );
			if ( $q <= 0 ) { continue; }
			$art = ( $aid && class_exists( 'FKC_InvArticle' ) ) ? FKC_InvArticle::find( (int) $aid ) : null;
			$lignes[] = array(
				'article_id' => $aid ? (int) $aid : null,
				'libelle' => $art ? $art['designation'] : ( $libs[ $i ] ?? 'Article' ),
				'quantite' => $q,
				'pu' => $art ? (float) $art['prix_vente'] : (float) ( $pus[ $i ] ?? 0 ),
				'tva' => $art ? (float) $art['tva'] : (float) FKC_PosConfig::get( 'tva_defaut', 18 ),
			);
		}
		list( $ok, $msg ) = FKC_PosCommande::creer( array(
			'canal' => $_POST['canal'] ?? 'comptoir', 'client_id' => $_POST['client_id'] ?? null,
			'client_nom' => $_POST['client_nom'] ?? '', 'client_tel' => $_POST['client_tel'] ?? '',
			'retrait_prevu' => $_POST['retrait_prevu'] ?? '', 'note' => $_POST['note'] ?? '',
		), $lignes );
		$this->setFlash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'caisse/commandes' );
	}

	public function commandeStatut( $id ) {
		list( $ok, $msg ) = FKC_PosCommande::changerStatut( (int) $id, (string) ( $_POST['s'] ?? $_GET['s'] ?? '' ) );
		$this->setFlash( $msg, $ok ? 'ok' : 'err' );
		redirect( 'caisse/commandes' );
	}

	/** Retrait : convertit la commande en ticket et bascule vers le terminal. */
	public function commandeRetirer( $id ) {
		$sess = $this->session();
		if ( ! $sess ) { $this->setFlash( 'Ouvrez une session de caisse.', 'err' ); return redirect( 'caisse' ); }
		list( $ok, $msg ) = FKC_PosCommande::retirer( (int) $id, (int) $sess['id'], (int) $sess['caisse_id'] );
		$this->setFlash( $msg, $ok ? 'ok' : 'err' );
		return redirect( $ok ? 'caisse/pos' : 'caisse/commandes' );
	}

	/* ═══════════════ Écran client (afficheur secondaire) ═══════════════ */

	/**
	 * Page de l'afficheur client (second écran). S'ouvre pour une caisse donnée
	 * (?caisse=ID) ou pour la caisse de la session courante du caissier. Conçue
	 * pour un navigateur plein écran sur un moniteur face au client.
	 */
	public function afficheur() {
		$caisseId = (int) ( $_GET['caisse'] ?? 0 );
		if ( ! $caisseId ) { $s = $this->session(); $caisseId = $s ? (int) $s['caisse_id'] : 0; }
		$caisse = $caisseId && class_exists( 'FKC_Caisse' ) ? FKC_Caisse::find( $caisseId ) : null;
		FKC_View::render( 'caisse::afficheur', array(
			'caisse_id' => $caisseId, 'caisse' => $caisse, 'societe' => $this->societe(),
			'message' => (string) FKC_PosConfig::get( 'afficheur_message', 'Merci de votre visite' ),
		), null ); // gabarit nu : plein écran, sans chrome ERP
	}

	/** État JSON de l'afficheur (polling léger, repli du SSE). */
	public function afficheurEtat() {
		// Lecture seule : on libère le verrou de session tout de suite.
		if ( PHP_SESSION_ACTIVE === session_status() ) { session_write_close(); }
		$caisseId = (int) ( $_GET['caisse'] ?? 0 );
		$this->json( array( 'ok' => true ) + FKC_Pos::afficheurEtat( $caisseId ) );
	}

	/** Flux SSE de l'afficheur : pousse l'état dès qu'il change (empreinte 'rev'). */
	public function afficheurStream() {
		/*
		 * 1.830.0 — LIBÈRE LE VERROU DE SESSION. PHP verrouille le fichier de
		 * session pendant toute la requête ; ce flux dure 25 s et se
		 * reconnecte aussitôt. Ouvert depuis le terminal (même navigateur,
		 * même cookie), il faisait attendre CHAQUE geste de caisse jusqu'à
		 * 25 s. Le flux ne lit rien de la session : on la ferme avant la
		 * boucle.
		 */
		fkc_sse_refuser_si_inactif();
		if ( PHP_SESSION_ACTIVE === session_status() ) { session_write_close(); }
		$caisseId = (int) ( $_GET['caisse'] ?? 0 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );
		}
		@set_time_limit( 0 );
		while ( ob_get_level() > 0 ) { @ob_end_flush(); }
		$rev = (string) ( $_GET['rev'] ?? '' );
		$fin = time() + 25;
		while ( time() < $fin ) {
			$etat = FKC_Pos::afficheurEtat( $caisseId );
			if ( $etat['rev'] !== $rev ) {
				$rev = $etat['rev'];
				echo 'data: ' . json_encode( $etat, JSON_UNESCAPED_UNICODE ) . "\n\n";
			} else {
				echo ": keep-alive\n\n";
			}
			@flush();
			if ( connection_aborted() ) { break; }
			usleep( 500000 ); // 0,5 s
		}
		exit;
	}

	/* ═══════════════ Impression ESC/POS & tiroir ═══════════════ */

	/** Flux ESC/POS brut d'un ticket (pour agent d'impression / impression raw). */
	public function ticketEscpos( $id ) {
		$t = FKC_Pos::ticket( (int) $id );
		if ( ! $t || ! class_exists( 'FKC_PosImpression' ) ) { http_response_code( 404 ); echo 'Ticket introuvable.'; return; }
		$fid = null;
		if ( ! empty( $t['client_id'] ) && class_exists( 'FKC_PosFidelite' ) && FKC_PosFidelite::actif() ) {
			$fid = FKC_PosFidelite::solde( (int) $t['client_id'] );
		}
		$flux = FKC_PosImpression::ticket(
			$t, FKC_Pos::lignes( (int) $id ), FKC_Pos::paiements( (int) $id ), $this->societe(),
			array( 'tiroir' => ! empty( $_GET['tiroir'] ), 'fidelite' => $fid )
		);
		// Base64 (défaut, pour un agent JS) ou binaire brut (?raw=1).
		if ( ! empty( $_GET['raw'] ) ) {
			if ( ! headers_sent() ) { header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="ticket-' . (int) $id . '.bin"' ); }
			echo $flux; exit;
		}
		$this->json( array( 'ok' => true, 'format' => 'escpos-base64', 'ticket' => $t['numero'], 'data' => FKC_PosImpression::base64( $flux ) ) );
	}

	/** Ouvre le tiroir-caisse (impulsion ESC/POS). */
	public function tiroir() {
		if ( ! class_exists( 'FKC_PosImpression' ) ) { $this->json( array( 'ok' => false, 'message' => 'Indisponible.' ) ); }
		// 1.835.0 — ouvrir le tiroir SANS vente est le geste le plus surveillé
		// d'une caisse : supervision exigée, autorisation tracée.
		if ( ! $this->exigerJson( 'tiroir', array( 'motif' => (string) ( $_POST['motif'] ?? '' ) ) ) ) { return; }
		$this->json( array( 'ok' => true, 'format' => 'escpos-base64', 'data' => FKC_PosImpression::base64( FKC_PosImpression::tiroir() ) ) );
	}

	/* ═══════════════ Helpers ═══════════════ */

	protected function session() {
		$uid = class_exists( 'FKC_Auth' ) ? FKC_Auth::id() : null;
		$s = $uid ? FKC_Caisse::sessionOuverteDe( $uid ) : null;
		// 1.840.0 — le terminal travaille avec le profil de SON poste.
		if ( $s ) { FKC_PosConfig::poste( (int) $s['caisse_id'] ); }
		return $s;
	}
	protected function ticketActif() {
		$s = $this->session();
		if ( ! $s ) { return null; }
		$t = FKC_Pos::ticketCourant( (int) $s['id'] );
		if ( ! $t ) { $id = FKC_Pos::ouvrir( (int) $s['id'], (int) $s['caisse_id'] ); $t = FKC_Pos::ticket( $id ); }
		return $t;
	}

	/** État complet du ticket : c'est ce que le terminal redessine après chaque geste. */
	protected function etatTicket( $ticketId ) {
		$t = FKC_Pos::ticket( $ticketId );
		if ( ! $t ) { return null; }
		return array(
			'id' => (int) $t['id'], 'numero' => $t['numero'], 'type' => $t['type'],
			'lignes' => FKC_Pos::lignes( $ticketId ),
			'total_ttc' => (float) $t['total_ttc'], 'total_ht' => (float) $t['total_ht'],
			'total_tva' => (float) $t['total_tva'], 'remise_taux' => (float) $t['remise_taux'],
			'remise_montant' => (float) $t['remise_montant'], 'service' => (float) $t['service'],
			'client_nom' => $t['client_nom'], 'client_id' => $t['client_id'],
			'couverts' => (int) $t['couverts'], 'table_id' => $t['table_id'],
			/* 1.830.0 — le terminal demandait le TOTAL même après des parts
			   déjà réglées : il doit afficher et encaisser le reste. */
			'deja_regle' => FKC_Pos::dejaRegle( (int) $ticketId ),
			'reste' => FKC_Pos::resteAPayer( (int) $ticketId ),
			'attente' => FKC_Pos::enAttente(),
		);
	}
	/* ═══════════════ Mobile Money par demande et QZ Tray (1.843.0) ═══════════════ */

	/** Envoie la demande de paiement au client (ou ouvre une transaction manuelle). */
	public function momoInitier() {
		$t = $this->ticketActif();
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Aucune session.' ) ); }
		$mode = preg_replace( '/[^a-z_]/', '', (string) ( $_POST['operateur'] ?? '' ) );
		$montant = (float) ( $_POST['montant'] ?? 0 );
		$reste = FKC_Pos::resteAPayer( (int) $t['id'] );
		if ( $montant > $reste + 0.01 ) {
			return $this->json( array( 'ok' => false, 'message' => 'La demande ne peut pas dépasser le reste dû (' . fkc_money( $reste ) . ' F).' ) );
		}
		list( $ok, $msg, $id, $ref, $lien, $adapt ) = FKC_PaiementMobile::initier( $mode, $montant, (string) ( $_POST['telephone'] ?? '' ), (int) $t['id'] );
		return $this->json( array( 'ok' => $ok, 'message' => $msg, 'transaction_id' => $id, 'reference' => $ref,
			'lien' => $lien, 'manuel' => 'manuel' === $adapt, 'montant' => $montant ) );
	}

	public function momoVerifier() {
		$tx = FKC_PaiementMobile::transaction( (int) ( $_POST['transaction_id'] ?? 0 ) );
		$t = $this->ticketActif();
		if ( ! $tx || ! $t || (int) $tx['ticket_id'] !== (int) $t['id'] ) { return $this->json( array( 'ok' => false, 'statut' => 'inconnu', 'message' => 'Transaction introuvable pour ce ticket.' ) ); }
		list( $statut, $msg ) = FKC_PaiementMobile::verifierStatut( (int) $tx['id'] );
		return $this->json( array( 'ok' => true, 'statut' => $statut, 'message' => $msg ) );
	}

	public function momoConfirmer() {
		$tx = FKC_PaiementMobile::transaction( (int) ( $_POST['transaction_id'] ?? 0 ) );
		$t = $this->ticketActif();
		if ( ! $tx || ! $t || (int) $tx['ticket_id'] !== (int) $t['id'] ) { return $this->json( array( 'ok' => false, 'message' => 'Transaction introuvable pour ce ticket.' ) ); }
		list( $ok, $msg ) = FKC_PaiementMobile::confirmerManuel( (int) $tx['id'], (string) ( $_POST['reference'] ?? '' ) );
		return $this->json( array( 'ok' => $ok, 'message' => $msg, 'statut' => $ok ? 'confirme' : $tx['statut'] ) );
	}

	/** Certificat public présenté à QZ Tray (approuvé une fois sur le poste). */
	public function qzCertificat() {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo FKC_PosQz::certificat();
	}

	/** Signature d'un défi QZ Tray (SHA512withRSA) — la clé privée ne quitte pas le serveur. */
	public function qzSigner() {
		header( 'Content-Type: text/plain; charset=utf-8' );
		list( $ok, $resultat ) = FKC_PosQz::signer( (string) ( $_POST['request'] ?? '' ) );
		if ( ! $ok ) { http_response_code( 500 ); }
		echo $resultat;
	}

	/* ═══════════════ Caisse mobile du serveur (1.842.0) ═══════════════ */

	/** Écran du serveur : les tables, puis l'addition de la table choisie. */
	public function mobile() {
		FKC_View::render( 'caisse::mobile', array(
			'title' => 'Prise de commande',
			'postes' => FKC_PosMobile::postesOuverts(),
			'salle' => FKC_PosMobile::salle(),
			'utilisateur' => class_exists( 'FKC_Auth' ) ? FKC_Auth::user() : null,
		), null );
	}

	public function mobileSalle() {
		return $this->json( array( 'ok' => true, 'salle' => FKC_PosMobile::salle(), 'postes' => FKC_PosMobile::postesOuverts() ) );
	}

	public function mobileTable( $id ) {
		list( $ok, $msg, $ticketId ) = FKC_PosMobile::additionDeTable( (int) $id, (int) ( $_GET['poste'] ?? 0 ) );
		if ( ! $ok ) { return $this->json( array( 'ok' => false, 'message' => $msg ) ); }
		return $this->json( array( 'ok' => true, 'addition' => FKC_PosMobile::etat( $ticketId ) ) );
	}

	public function mobileArticles() {
		$q = trim( (string) ( $_GET['q'] ?? '' ) );
		$out = array();
		foreach ( FKC_Pos::articles( null, $q, 40 ) as $a ) {
			$out[] = array( 'id' => (int) $a['id'], 'libelle' => (string) ( $a['designation'] ?? $a['libelle'] ?? '' ), 'prix' => (float) ( $a['prix_vente'] ?? 0 ) );
		}
		return $this->json( array( 'ok' => true, 'articles' => $out ) );
	}

	public function mobileAjouter() {
		$t = FKC_PosMobile::additionValide( (int) ( $_POST['ticket_id'] ?? 0 ) );
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Addition introuvable ou déjà encaissée.' ) ); }
		$qte = max( 1, (float) ( $_POST['quantite'] ?? 1 ) );
		list( $ok, $msg ) = FKC_Pos::ajouterArticle( (int) $t['id'], (int) ( $_POST['article_id'] ?? 0 ), $qte, array(
			'note' => (string) ( $_POST['note'] ?? '' ), 'cours' => (int) ( $_POST['cours'] ?? 1 ),
		) );
		return $this->json( array( 'ok' => (bool) $ok, 'message' => $ok ? '' : $msg, 'addition' => FKC_PosMobile::etat( (int) $t['id'] ) ) );
	}

	public function mobileRetirer() {
		$l = FKC_Pos::ligne( (int) ( $_POST['ligne_id'] ?? 0 ) );
		$t = $l ? FKC_PosMobile::additionValide( (int) $l['ticket_id'] ) : null;
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Ligne introuvable.' ) ); }
		// Déjà partie en préparation : geste supervisé, comme au terminal.
		if ( 1 === (int) $l['envoye'] && ! $this->exigerJson( 'retrait', array(
			'ticket_id' => (int) $t['id'], 'montant' => (float) $l['total_ttc'], 'motif' => 'Retrait par le serveur : ' . $l['libelle'],
		) ) ) { return; }
		list( $ok, $msg ) = FKC_Pos::supprimerLigne( (int) $l['id'], 'Retrait par le serveur' );
		return $this->json( array( 'ok' => (bool) $ok, 'message' => $ok ? '' : $msg, 'addition' => FKC_PosMobile::etat( (int) $t['id'] ) ) );
	}

	public function mobileEnvoyer() {
		$t = FKC_PosMobile::additionValide( (int) ( $_POST['ticket_id'] ?? 0 ) );
		if ( ! $t ) { return $this->json( array( 'ok' => false, 'message' => 'Addition introuvable ou déjà encaissée.' ) ); }
		list( $ok, $msg ) = FKC_PosMobile::envoyer( (int) $t['id'] );
		return $this->json( array( 'ok' => $ok, 'message' => $msg, 'addition' => FKC_PosMobile::etat( (int) $t['id'] ) ) );
	}

	/* ═══════════════ Droits (1.835.0) ═══════════════ */

	/** Réponse JSON qui invite le terminal à demander le code superviseur. */
	protected function refusAutorisation( $geste, $msg ) {
		$this->json( array( 'ok' => false, 'message' => $msg, 'autorisation' => $geste,
			'geste' => FKC_PosDroits::gestes()[ $geste ] ?? $geste ) );
	}

	/** Exige la supervision d'un geste ; répond au terminal et rend false si elle manque. */
	protected function exigerJson( $geste, array $ctx ) {
		list( $ok, $msg ) = FKC_PosDroits::exiger( $geste, $ctx );
		if ( ! $ok ) { $this->refusAutorisation( $geste, $msg ); return false; }
		return true;
	}

	/**
	 * Un code superviseur accompagne la requête : on le vérifie AVANT d'appeler
	 * le moteur, qui verra alors le geste couvert. Sans code, rien à faire.
	 */
	protected function codeSiFourni( $geste, $t ) {
		if ( '' === trim( (string) ( $_POST['pin_superviseur'] ?? '' ) ) ) { return true; }
		return $this->exigerJson( $geste, array( 'ticket_id' => (int) $t['id'], 'motif' => 'plafond de remise' ) );
	}

	/**
	 * Comme etat(), en demandant au terminal les numéros de série quand
	 * l'article en exige (Commerce Core, 1.839.0) : le terminal les saisit
	 * et REJOUE la même requête.
	 */
	protected function etatSeries( $ticketId, $ok, $msg ) {
		if ( ! $ok && FKC_Pos::$seriesRequises > 0 ) {
			$this->json( array( 'ok' => false, 'message' => $msg, 'series_requises' => FKC_Pos::$seriesRequises,
				'etat' => $this->etatTicket( $ticketId ) ) );
		}
		return $this->etat( $ticketId, $ok, $msg );
	}

	/** Comme etat(), en signalant au terminal un refus dû au plafond. */
	protected function etatAutorisation( $ticketId, $ok, $msg, $geste ) {
		if ( ! $ok && FKC_Pos::$refusPlafond ) {
			$this->json( array( 'ok' => false, 'message' => $msg, 'autorisation' => $geste,
				'geste' => FKC_PosDroits::gestes()[ $geste ] ?? $geste, 'etat' => $this->etatTicket( $ticketId ) ) );
		}
		return $this->etat( $ticketId, $ok, $msg );
	}

	/** Gestes de paramétrage : réservés au responsable de caisse et à l'administrateur. */
	protected function configurer() {
		if ( FKC_PosDroits::peut( FKC_PosDroits::CONFIGURER ) ) { return true; }
		$this->setFlash( 'Paramétrage réservé au responsable de caisse ou à l\'administrateur.', 'err' );
		redirect( 'caisse' );
		return false;
	}

	/** La ligne visée appartient-elle bien au ticket actif du caissier ? */
	protected function ligneDuTicket( $ligneId, $t ) {
		$l = FKC_Pos::ligne( (int) $ligneId );
		return $l && $t && (int) $l['ticket_id'] === (int) $t['id'];
	}

	protected function etat( $ticketId, $ok, $msg ) {
		$this->json( array( 'ok' => (bool) $ok, 'message' => $msg, 'etat' => $this->etatTicket( $ticketId ) ) );
	}

	protected function clients() {
		try { return FKC_DB::q( 'SELECT id, nom, telephone FROM clients ORDER BY nom LIMIT 300' )->fetchAll(); }
		catch ( \Throwable $e ) { return array(); }
	}
	protected function societe() {
		try { return FKC_DB::q( 'SELECT * FROM parametres LIMIT 1' )->fetch() ?: array(); }
		catch ( \Throwable $e ) { return array(); }
	}
	protected function json( $d ) {
		if ( defined( 'FKC_TEST_REDIRECT' ) && FKC_TEST_REDIRECT ) { throw new FKC_ReponseJson( $d ); }
		if ( ! headers_sent() ) { header( 'Content-Type: application/json; charset=utf-8' ); header( 'Cache-Control: no-store' ); }
		echo json_encode( $d, JSON_UNESCAPED_UNICODE );
		exit;
	}
	protected function setFlash( $m, $t = 'ok' ) { $_SESSION['__flash_caisse'] = array( 'type' => $t, 'msg' => $m ); }
	protected function flash() { $m = $_SESSION['__flash_caisse'] ?? null; unset( $_SESSION['__flash_caisse'] ); return $m; }
}
