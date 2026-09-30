<?php
/**
 * FKC_Config — configuration SYSTÈME de la plateforme FinaKop (1.876.0).
 *
 * Remplace, pour l'installation autonome, get_option()/update_option() et
 * wp-config.php. Trois niveaux, du moins au plus prioritaire :
 *
 *   1. valeurs par défaut (ci-dessous) ;
 *   2. fichier PHP hors web (~/finakop/config.php, droits 0600) ;
 *   3. variables d'environnement FINAKOP_* pour les secrets (facultatif).
 *
 * Ce qui N'EST PAS ici :
 *   • la configuration d'un client (packs, référentiel, préférences) : elle
 *     reste dans SES bases, comme aujourd'hui (tables parametres, cab_parametres) ;
 *   • les licences : un jeton par client, dans son registre ;
 *   • les secrets d'un client (clés d'API, Mobile Money…) : chiffrés dans ses
 *     bases avec SA clé.
 *
 * Écriture (set) : réservée à la ligne de commande ; en web, la configuration
 * système est en lecture seule.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_Config {

	protected static $valeurs = null;
	protected static $fichier = '';

	/** Valeurs par défaut : une installation neuve doit être sûre SANS rien régler. */
	public static function defauts() {
		return array(
			'domaine_base'  => 'finakoperp.com',
			'hote_portail'  => 'app.finakoperp.com',
			'site_public'   => 'https://www.finakoperp.com/',
			'mode_tenant'   => 'sous-domaine',          // sous-domaine | chemin | les_deux
			'hotes_supplementaires' => array(),          // autres domaines de base acceptés (recette : finakoperp.test)
			'reserves'      => array( 'www', 'app', 'api', 'admin', 'mail', 'webmail', 'smtp', 'imap', 'pop', 'pop3',
				'ftp', 'sftp', 'cpanel', 'hpanel', 'whm', 'ns', 'ns1', 'ns2', 'dns', 'mx', 'license', 'licence', 'licences',
				'relais', 'static', 'cdn', 'assets', 'status', 'statut', 'support', 'aide', 'docs', 'doc', 'blog', 'portail',
				'autoconfig', 'autodiscover', 'plateforme', 'platform', 'root', 'localhost', 'staging', 'preprod' ),
			'donnees'       => '',                       // OBLIGATOIRE : dossier hors web
			'https'         => true,                     // redirige http → https
			'fuseau'        => 'UTC',                    // PHP ET SQLite (TZ) : même heure partout
			'debug'         => false,
			'admin_email'   => '',
			'cloudflare'    => array(
				'actif'           => false,              // rétablit l'IP réelle depuis les plages Cloudflare
				'secret_origine'  => '',                 // non vide : toute requête sans l'en-tête secret est refusée
				'entete_secret'   => 'X-FinaKop-Origine',
				'plages_supplementaires' => array(),
			),
			'proxies_de_confiance' => array(),           // CIDR de relais inverses propres (VPS), en plus de Cloudflare
			'licence'       => array(
				'serveur'        => 'https://license.kophisgroup.com/api',
				'depot'          => '',
				'domaine_strict' => true,               // indispensable en multi-clients (voir audit R10)
			),
			'api'           => array( 'autorite' => 'service', 'cors' => '' ),
			'smtp'          => array(
				'transport' => 'smtp', 'hote' => '', 'port' => 465, 'securite' => 'ssl',
				'utilisateur' => '', 'mot_de_passe' => '', 'expediteur' => '', 'nom_expediteur' => 'FinaKop',
				'repondre_a' => '', 'delai' => 15, 'verifier_certificat' => true, 'dossier' => '',
			),
			'cron'          => array( 'intervalle' => 300, 'budget' => 240, 'delai_tenant' => 180 ),
			'sauvegarde'    => array( 'heure' => 2, 'retention_jours' => 14, 'cle' => '', 'dossier' => '' ),
			'journaux'      => array( 'taille_max_mo' => 5, 'generations' => 5 ),
			'connect'       => array( 'push_hotes' => '' ),
		);
	}

	/** Variables d'environnement reconnues → clé de configuration. */
	protected static function environnement() {
		return array(
			'FINAKOP_DONNEES'            => 'donnees',
			'FINAKOP_DOMAINE_BASE'       => 'domaine_base',
			'FINAKOP_SMTP_MOT_DE_PASSE'  => 'smtp.mot_de_passe',
			'FINAKOP_CLOUDFLARE_SECRET'  => 'cloudflare.secret_origine',
			'FINAKOP_SAUVEGARDE_CLE'     => 'sauvegarde.cle',
		);
	}

	/**
	 * Charge la configuration. Échoue si le dossier de données n'est pas défini :
	 * sans lui, FinaKop créerait ses bases à un endroit imprévu.
	 */
	public static function charger( $fichier ) {
		self::$fichier = (string) $fichier;
		$lu = array();
		if ( '' !== self::$fichier && is_file( self::$fichier ) ) {
			$lu = require self::$fichier;
			if ( ! is_array( $lu ) ) { throw new \RuntimeException( 'Configuration illisible : le fichier doit retourner un tableau.' ); }
		} else {
			throw new \RuntimeException( 'Configuration introuvable : ' . self::$fichier );
		}
		$v = self::fusionner( self::defauts(), $lu );
		foreach ( self::environnement() as $var => $cle ) {
			$e = getenv( $var );
			if ( false !== $e && '' !== $e ) { self::poser( $v, $cle, $e ); }
		}
		$v['donnees'] = rtrim( (string) $v['donnees'], '/' );
		if ( '' === $v['donnees'] ) { throw new \RuntimeException( 'Paramètre « donnees » absent de la configuration.' ); }
		$v['domaine_base'] = strtolower( trim( (string) $v['domaine_base'], '. ' ) );
		$v['hote_portail'] = strtolower( trim( (string) $v['hote_portail'], '. ' ) );
		self::$valeurs = $v;
		return $v;
	}

	/** Pour les tests : configuration fournie directement. */
	public static function initialiser( array $v ) {
		self::$valeurs = self::fusionner( self::defauts(), $v );
		self::$valeurs['donnees'] = rtrim( (string) self::$valeurs['donnees'], '/' );
	}

	public static function charge() { return is_array( self::$valeurs ); }
	public static function fichier() { return self::$fichier; }

	/** Lecture par clé à points : FKC_Config::get( 'smtp.hote' ). */
	public static function get( $cle, $defaut = null ) {
		if ( ! is_array( self::$valeurs ) ) { return $defaut; }
		$v = self::$valeurs;
		foreach ( explode( '.', (string) $cle ) as $k ) {
			if ( ! is_array( $v ) || ! array_key_exists( $k, $v ) ) { return $defaut; }
			$v = $v[ $k ];
		}
		return $v;
	}

	/**
	 * Écriture (ligne de commande uniquement) : met à jour la valeur et réécrit
	 * le fichier de configuration, droits 0600.
	 */
	public static function set( $cle, $valeur ) {
		if ( 'cli' !== PHP_SAPI ) { throw new \LogicException( 'La configuration système ne se modifie qu\'en ligne de commande.' ); }
		$lu = is_file( self::$fichier ) ? ( require self::$fichier ) : array();
		self::poser( $lu, $cle, $valeur );
		$tmp = self::$fichier . '.tmp';
		file_put_contents( $tmp, "<?php\n// Configuration FinaKop — réécrite par « finakop config:set » le " . gmdate( 'c' ) . "\nreturn " . var_export( $lu, true ) . ";\n" );
		@chmod( $tmp, 0600 );
		rename( $tmp, self::$fichier );
		self::poser( self::$valeurs, $cle, $valeur );
	}

	/* Chemins dérivés du dossier de données. */
	public static function dossier( $sous = '' ) {
		return self::get( 'donnees' ) . '/' . ltrim( (string) $sous, '/' );
	}

	protected static function fusionner( array $a, array $b ) {
		foreach ( $b as $k => $v ) {
			if ( is_array( $v ) && isset( $a[ $k ] ) && is_array( $a[ $k ] ) && self::associatif( $a[ $k ] ) ) {
				$a[ $k ] = self::fusionner( $a[ $k ], $v );
			} else {
				$a[ $k ] = $v;
			}
		}
		return $a;
	}

	protected static function associatif( array $a ) { return array_keys( $a ) !== range( 0, count( $a ) - 1 ); }

	protected static function poser( array &$v, $cle, $valeur ) {
		$cles = explode( '.', (string) $cle );
		$dern = array_pop( $cles );
		$ref  = &$v;
		foreach ( $cles as $k ) {
			if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) { $ref[ $k ] = array(); }
			$ref = &$ref[ $k ];
		}
		$ref[ $dern ] = $valeur;
	}
}
