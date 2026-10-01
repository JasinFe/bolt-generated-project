<?php
/**
 * FKC_TenantResolver — de l'hôte de la requête au client (1.876.0).
 *
 *   newloock.finakoperp.com  →  slug « newloock »  →  registre  →  client New Loock
 *
 * Le résultat désigne QUEL dossier de données ouvrir. Il ne vaut JAMAIS
 * autorisation : l'utilisateur, sa session et ses droits sont ensuite
 * vérifiés par FinaKop dans le registre de CE client uniquement — un compte de
 * la société B n'y existe pas.
 *
 * Tout ce qui n'est pas explicitement reconnu est refusé : un hôte hors des
 * domaines configurés (injection d'en-tête Host), un sous-domaine à plusieurs
 * niveaux, un identifiant mal formé, un nom réservé, un client inconnu ou archivé.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_TenantResolver {

	/** Types de résultat. */
	const CLIENT = 'client', PORTAIL = 'portail', SITE = 'site', INCONNU = 'inconnu',
		SUSPENDU = 'suspendu', MAINTENANCE = 'maintenance', HOTE_REFUSE = 'hote_refuse';

	/** Hôte normalisé (minuscules, sans port ni point final), ou '' s'il est invalide. */
	public static function normaliserHote( $hote ) {
		$h = strtolower( trim( (string) $hote ) );
		if ( '' === $h || strlen( $h ) > 253 ) { return ''; }
		if ( '[' === $h[0] ) { return ''; } // littéral IPv6 : jamais un espace client
		$h = preg_replace( '/:\d{1,5}$/', '', $h );
		$h = rtrim( $h, '.' );
		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $h ) && false === strpos( $h, '..' ) ? $h : '';
	}

	/** Domaines de base acceptés (production + recette éventuelle). */
	public static function domainesBase() {
		$d = array( (string) FKC_Config::get( 'domaine_base' ) );
		foreach ( (array) FKC_Config::get( 'hotes_supplementaires', array() ) as $x ) {
			$x = strtolower( trim( (string) $x, '. ' ) );
			if ( '' !== $x ) { $d[] = $x; }
		}
		return array_values( array_unique( array_filter( $d ) ) );
	}

	/**
	 * @param string $hote  $_SERVER['HTTP_HOST']
	 * @param string $uri   $_SERVER['REQUEST_URI']
	 * @return array{type:string, client?:array, base_path?:string, hote?:string, slug?:string}
	 */
	public static function resoudre( $hote, $uri = '/' ) {
		$h = self::normaliserHote( $hote );
		if ( '' === $h ) { return array( 'type' => self::HOTE_REFUSE ); }
		$mode = (string) FKC_Config::get( 'mode_tenant', 'sous-domaine' );
		$portail = (string) FKC_Config::get( 'hote_portail' );

		// 1. Portail (et, en mode chemin, /<slug>/ sous le portail).
		if ( $h === $portail ) {
			if ( in_array( $mode, array( 'chemin', 'les_deux' ), true ) ) {
				$chemin = (string) parse_url( '/' . ltrim( (string) $uri, '/' ), PHP_URL_PATH );
				$seg = explode( '/', trim( $chemin, '/' ) )[0] ?? '';
				// Le portail ne répond qu'à « / » ; tout autre chemin désigne un espace,
				// existant ou non (404 neutre, comme en mode sous-domaine).
				if ( '' !== $seg ) {
					if ( ! FKC_Plateforme_Registre::slugValide( $seg ) ) { return array( 'type' => self::INCONNU, 'hote' => $h ); }
					return self::parClient( FKC_Plateforme_Registre::parSlug( $seg ), $h, '/' . $seg );
				}
			}
			return array( 'type' => self::PORTAIL, 'hote' => $h );
		}

		// 2. Sous-domaine d'un domaine de base.
		foreach ( self::domainesBase() as $base ) {
			if ( $h === $base || $h === 'www.' . $base ) { return array( 'type' => self::SITE, 'hote' => $h ); }
			$suffixe = '.' . $base;
			if ( strlen( $h ) > strlen( $suffixe ) && substr( $h, -strlen( $suffixe ) ) === $suffixe ) {
				$slug = substr( $h, 0, -strlen( $suffixe ) );
				if ( false !== strpos( $slug, '.' ) ) { return array( 'type' => self::INCONNU, 'hote' => $h ); }   // a.b.finakoperp.com
				if ( ! FKC_Plateforme_Registre::slugValide( $slug ) ) { return array( 'type' => self::INCONNU, 'hote' => $h ); }
				if ( in_array( $slug, (array) FKC_Config::get( 'reserves', array() ), true ) ) { return array( 'type' => self::INCONNU, 'hote' => $h ); }
				if ( 'chemin' === $mode ) { return array( 'type' => self::INCONNU, 'hote' => $h ); }
				return self::parClient( FKC_Plateforme_Registre::parSlug( $slug ), $h, '' );
			}
		}

		// 3. Domaine personnalisé déclaré (erp.newloock.com).
		$t = FKC_Plateforme_Registre::parDomaine( $h );
		if ( $t ) { return self::parClient( $t, $h, '' ); }

		return array( 'type' => self::HOTE_REFUSE, 'hote' => $h );
	}

	protected static function parClient( $t, $hote, $basePath ) {
		if ( ! $t || 'archive' === $t['statut'] ) { return array( 'type' => self::INCONNU, 'hote' => $hote ); }
		$type = 'actif' === $t['statut'] ? self::CLIENT : ( 'suspendu' === $t['statut'] ? self::SUSPENDU : self::MAINTENANCE );
		return array( 'type' => $type, 'client' => $t, 'hote' => $hote, 'base_path' => $basePath, 'slug' => $t['slug'] );
	}
}
