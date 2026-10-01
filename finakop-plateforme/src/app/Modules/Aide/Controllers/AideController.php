<?php
/**
 * Module Aide : centre d'aide, guide de prise en main, tutoriels, glossaire,
 * dépannage et visites interactives.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_AideController {

	public function index() {
		$q = trim( (string) ( $_GET['q'] ?? '' ) );
		FKC_View::render( 'aide::index', array(
			'title'      => 'Aide & documentation',
			'categories' => FKC_Aide::categories(),
			'comptes'    => FKC_Aide::compteParCategorie(),
			'tutoriels'  => FKC_Aide::tutoriels(),
			'tours'      => FKC_Aide::tours(),
			'essentiels' => FKC_Aide::essentiels(),
			'parcours'   => FKC_Aide::parcours(),
			'q'          => $q,
			'resultats'  => '' !== $q ? FKC_Aide::rechercher( $q ) : null,
			'videos'     => FKC_AidePlateforme::videos(),
			'nouveautes' => FKC_AidePlateforme::nouveautes(),
			'support'    => FKC_AidePlateforme::support(),
		) );
	}

	/** Vidéos d'aide : prise en main, sécurité, facturation, comptabilité (1.876.4). */
	public function videos() {
		FKC_View::render( 'aide::videos', array(
			'title'   => 'Aide · Vidéos',
			'videos'  => FKC_AidePlateforme::videos(),
			'support' => FKC_AidePlateforme::support(),
		) );
	}

	/** Guide de prise en main : le parcours linéaire, dans l'ordre. */
	/**
	 * Sommaire des packs métier, par famille.
	 *
	 * Cinquante packs ne se lisent pas en liste plate : on cherche le sien par
	 * son secteur, pas par ordre alphabétique.
	 */
	public function packs() {
		FKC_View::render( 'aide::packs', array(
			'title'    => 'Aide · Packs métier',
			'familles' => FKC_AidePacks::parFamille(),
			'actif'    => class_exists( 'FKC_Packs' ) ? FKC_Packs::activeCode() : '',
		) );
	}

	/** Fiche pratique d'un pack : vocabulaire, pièces, stock, démarrage. */
	public function pack( $code ) {
		$fiche = FKC_AidePacks::fiche( $code );
		if ( ! $fiche ) { return redirect( 'aide/packs' ); }
		FKC_View::render( 'aide::pack', array(
			'title' => 'Aide · ' . $fiche['label'],
			'f'     => $fiche,
			'actif' => class_exists( 'FKC_Packs' ) ? FKC_Packs::activeCode() : '',
		) );
	}

	public function guide() {
		FKC_View::render( 'aide::guide', array(
			'title'    => 'Guide de prise en main',
			'parcours' => FKC_Aide::parcours(),
		) );
	}

	public function categorie( $cat ) {
		$cats = FKC_Aide::categories();
		if ( ! isset( $cats[ $cat ] ) ) { redirect( 'aide' ); return; }
		FKC_View::render( 'aide::categorie', array(
			'title'    => 'Aide · ' . $cats[ $cat ]['titre'],
			'cat'      => $cat,
			'meta'     => $cats[ $cat ],
			'articles' => FKC_Aide::parCategorie( $cat ),
		) );
	}

	public function article( $id ) {
		$a = FKC_Aide::article( $id );
		if ( ! $a ) { redirect( 'aide' ); return; }
		$cats = FKC_Aide::categories();
		FKC_View::render( 'aide::article', array(
			'title'   => 'Aide · ' . $a['titre'],
			'article' => $a,
			'catMeta' => $cats[ $a['cat'] ] ?? null,
			'voisins' => array_values( array_filter(
				FKC_Aide::parCategorie( $a['cat'] ),
				function ( $x ) use ( $id ) { return $x['id'] !== $id; }
			) ),
		) );
	}

	public function tutoriels() {
		FKC_View::render( 'aide::tutoriels', array(
			'title'     => 'Tutoriels guidés',
			'tutoriels' => FKC_Aide::tutoriels(),
		) );
	}

	/** Glossaire, regroupé par domaine puis trié alphabétiquement. */
	public function glossaire() {
		$grp = array();
		foreach ( FKC_Aide::glossaire() as $g ) { $grp[ $g['grp'] ][] = $g; }
		ksort( $grp );
		foreach ( $grp as &$lot ) {
			usort( $lot, function ( $a, $b ) { return strcoll( $a['terme'], $b['terme'] ); } );
		}
		unset( $lot );
		FKC_View::render( 'aide::glossaire', array(
			'title'  => 'Glossaire',
			'groupes' => $grp,
		) );
	}

	/** Dépannage : les symptômes fréquents et ce qu'il faut regarder. */
	public function depannage() {
		$grp = array();
		foreach ( FKC_Aide::depannage() as $d ) { $grp[ $d['grp'] ][] = $d; }
		FKC_View::render( 'aide::depannage', array(
			'title'   => 'Dépannage',
			'groupes' => $grp,
		) );
	}
}
