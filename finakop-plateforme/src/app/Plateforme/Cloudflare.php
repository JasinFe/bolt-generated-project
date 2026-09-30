<?php
/**
 * FKC_Plateforme_Proxy — requêtes arrivant par Cloudflare ou un relais inverse (1.876.0).
 *
 * FinaKop ne croit QUE $_SERVER['REMOTE_ADDR'] (FKC_Security::clientIp) :
 * c'est juste en accès direct, mais derrière Cloudflare tous les visiteurs
 * paraîtraient venir d'une poignée d'adresses Cloudflare — le limiteur de
 * connexion bloquerait tout le monde à la fois, et le journal de sécurité
 * ne vaudrait plus rien.
 *
 * Règle : l'en-tête CF-Connecting-IP (ou X-Forwarded-For) n'est lu QUE si la
 * connexion vient réellement d'une plage IP officielle de Cloudflare (ou d'un
 * relais déclaré). Venant de n'importe qui d'autre, il est ignoré : sinon un
 * attaquant choisirait lui-même son adresse et contournerait le limiteur.
 *
 * Si l'hébergeur (LiteSpeed) a déjà rétabli l'IP réelle, REMOTE_ADDR n'est plus
 * une adresse Cloudflare : rien n'est modifié.
 *
 * Garde de l'origine : si un secret est configuré, toute requête web sans
 * l'en-tête secret — ajouté par une règle de transformation Cloudflare — est
 * refusée. Un mutualisé ne peut pas filtrer par IP ; c'est ce qui empêche de
 * contourner Cloudflare en visant directement le serveur.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_Plateforme_Proxy {

	/** Plages officielles (https://www.cloudflare.com/ips/), mises à jour par « finakop cloudflare:plages ». */
	const PLAGES_CLOUDFLARE = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** Plages de confiance effectives : Cloudflare (si actif) + fichier rafraîchi + relais déclarés. */
	public static function plages() {
		$p = array();
		if ( FKC_Config::get( 'cloudflare.actif', false ) ) {
			$p = self::PLAGES_CLOUDFLARE;
			$f = FKC_Config::dossier( 'plateforme/cloudflare-ips.json' );
			if ( is_file( $f ) ) {
				$j = json_decode( (string) @file_get_contents( $f ), true );
				if ( is_array( $j ) && count( $j ) >= 10 ) { $p = $j; }
			}
			$p = array_merge( $p, (array) FKC_Config::get( 'cloudflare.plages_supplementaires', array() ) );
		}
		return array_values( array_unique( array_merge( $p, (array) FKC_Config::get( 'proxies_de_confiance', array() ) ) ) );
	}

	/** Une adresse appartient-elle à un bloc CIDR (IPv4 ou IPv6) ? */
	public static function dans( $ip, $cidr ) {
		$bin = @inet_pton( (string) $ip );
		if ( false === $bin ) { return false; }
		$parts = explode( '/', (string) $cidr, 2 );
		$reseau = @inet_pton( $parts[0] );
		if ( false === $reseau || strlen( $reseau ) !== strlen( $bin ) ) { return false; }
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $bin ) * 8;
		$octets = intdiv( $bits, 8 ); $reste = $bits % 8;
		if ( 0 !== strncmp( $bin, $reseau, $octets ) ) { return false; }
		if ( 0 === $reste ) { return true; }
		$masque = ( 0xFF << ( 8 - $reste ) ) & 0xFF;
		return ( ord( $bin[ $octets ] ) & $masque ) === ( ord( $reseau[ $octets ] ) & $masque );
	}

	public static function deConfiance( $ip ) {
		foreach ( self::plages() as $c ) { if ( self::dans( $ip, $c ) ) { return true; } }
		return false;
	}

	/**
	 * Normalise la requête : IP réelle et HTTPS, seulement depuis un relais de confiance.
	 * @return array{relais:bool, ip_origine:string}
	 */
	public static function appliquer( array &$srv ) {
		$ra = (string) ( $srv['REMOTE_ADDR'] ?? '' );
		$info = array( 'relais' => false, 'ip_origine' => $ra );
		if ( '' === $ra || ! self::deConfiance( $ra ) ) { return $info; }
		$info['relais'] = true;

		$client = '';
		$cf = trim( (string) ( $srv['HTTP_CF_CONNECTING_IP'] ?? '' ) );
		if ( '' !== $cf && false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
			$client = $cf;
		} elseif ( ! empty( $srv['HTTP_X_FORWARDED_FOR'] ) ) {
			// De droite à gauche : la première adresse qui n'est pas un relais de confiance.
			foreach ( array_reverse( array_map( 'trim', explode( ',', (string) $srv['HTTP_X_FORWARDED_FOR'] ) ) ) as $x ) {
				if ( false === filter_var( $x, FILTER_VALIDATE_IP ) ) { break; }
				if ( ! self::deConfiance( $x ) ) { $client = $x; break; }
			}
		}
		if ( '' !== $client ) {
			$srv['FKC_IP_RELAIS'] = $ra;
			$srv['REMOTE_ADDR']   = $client;
		}
		if ( 'https' === strtolower( (string) ( $srv['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) ) {
			$srv['HTTPS'] = 'on';
			$srv['SERVER_PORT'] = 443;
		}
		return $info;
	}

	/** L'en-tête secret de l'origine est-il présent et exact ? (true si aucun secret n'est configuré) */
	public static function origineAutorisee( array $srv ) {
		$secret = (string) FKC_Config::get( 'cloudflare.secret_origine', '' );
		if ( '' === $secret ) { return true; }
		$nom = 'HTTP_' . strtoupper( str_replace( '-', '_', (string) FKC_Config::get( 'cloudflare.entete_secret', 'X-FinaKop-Origine' ) ) );
		$recu = (string) ( $srv[ $nom ] ?? '' );
		return '' !== $recu && hash_equals( $secret, $recu );
	}

	public static function estHttps( array $srv ) {
		return ( ! empty( $srv['HTTPS'] ) && 'off' !== strtolower( (string) $srv['HTTPS'] ) ) || 443 === (int) ( $srv['SERVER_PORT'] ?? 0 );
	}
}
