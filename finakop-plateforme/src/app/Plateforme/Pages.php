<?php
/**
 * FKC_Plateforme_Pages — pages servies AVANT tout client (1.876.0).
 *
 * Portail (choix de l'espace) et réponses neutres. Aucune ne charge
 * l'application ni n'ouvre de base client ; aucune ne révèle de détail
 * technique. Même gabarit sobre pour toutes.
 *
 * @package FinaKop_Plateforme
 */
defined( 'FKC_PLATEFORME' ) || exit;

class FKC_Plateforme_Pages {

	protected static function e( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }

	protected static function entetes( $code ) {
		if ( headers_sent() ) { return; }
		http_response_code( $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );
		if ( FKC_Plateforme_Proxy::estHttps( $_SERVER ) ) { header( 'Strict-Transport-Security: max-age=31536000' ); }
		@header_remove( 'X-Powered-By' );
	}

	protected static function gabarit( $titre, $corps ) {
		return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow"><title>' . self::e( $titre ) . ' · FinaKop</title><style>'
			. 'body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f6f8;color:#1f2933}'
			. 'main{max-width:440px;margin:12vh auto;padding:32px;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
			. 'h1{font-size:22px;margin:0 0 12px}p{margin:0 0 16px;color:#52606d}label{display:block;font-weight:600;margin-bottom:6px}'
			. '.l{display:flex;align-items:center;border:1px solid #cbd2d9;border-radius:8px;overflow:hidden}'
			. 'input{flex:1;border:0;padding:12px;font:inherit;min-width:0}input:focus{outline:none}.s{padding:0 12px;color:#7b8794;white-space:nowrap}'
			. 'button{margin-top:16px;width:100%;padding:12px;border:0;border-radius:8px;background:#1f5eff;color:#fff;font:inherit;font-weight:600;cursor:pointer}'
			. '.err{color:#b42318;font-weight:600}footer{margin-top:24px;font-size:13px;color:#9aa5b1}'
			. '@media(prefers-color-scheme:dark){body{background:#12161b;color:#e4e7eb}main{background:#1c2127}p{color:#9aa5b1}.l{border-color:#3e4c59}input{background:#1c2127;color:#e4e7eb}}'
			. '</style></head><body><main>' . $corps . '<footer>FinaKop ERP</footer></main></body></html>';
	}

	public static function message( $code, $titre, $texte, array $entetes = array() ) {
		self::entetes( $code );
		foreach ( $entetes as $h ) { header( $h ); }
		echo self::gabarit( $titre, '<h1>' . self::e( $titre ) . '</h1><p>' . self::e( $texte ) . '</p>' );
	}

	public static function inconnu() {
		self::message( 404, 'Espace introuvable', 'Aucun espace FinaKop ne correspond à cette adresse. Vérifiez l\'adresse communiquée par votre administrateur.' );
	}
	public static function suspendu() {
		self::message( 403, 'Espace suspendu', 'Cet espace FinaKop est momentanément suspendu. Contactez votre administrateur.' );
	}
	public static function maintenance() {
		self::message( 503, 'Maintenance en cours', 'Cet espace FinaKop est en maintenance. Vos données sont en sécurité. Merci de réessayer dans quelques minutes.', array( 'Retry-After: 900' ) );
	}
	public static function indisponible() {
		self::message( 503, 'Service indisponible', 'FinaKop est momentanément indisponible. Merci de réessayer dans quelques minutes.', array( 'Retry-After: 300' ) );
	}
	public static function hoteRefuse() {
		self::message( 400, 'Adresse non reconnue', 'Cette adresse n\'est pas servie par FinaKop.' );
	}
	public static function origineRefusee() {
		self::message( 403, 'Accès refusé', 'Accès direct non autorisé.' );
	}

	/** Portail : saisie de l'identifiant d'espace, redirection vers l'espace s'il est actif. */
	public static function portail() {
		$saisi = strtolower( trim( (string) ( $_GET['espace'] ?? '' ) ) );
		$erreur = '';
		if ( '' !== $saisi ) {
			// Tolère une adresse collée entière (https://newloock.finakoperp.com/…).
			if ( preg_match( '#^(?:https?://)?([a-z0-9-]+)\.' . preg_quote( (string) FKC_Config::get( 'domaine_base' ), '#' ) . '#', $saisi, $m ) ) { $saisi = $m[1]; }
			$t = FKC_Plateforme_Registre::slugValide( $saisi ) ? FKC_Plateforme_Registre::parSlug( $saisi ) : null;
			if ( $t && 'actif' === $t['statut'] ) {
				$mode = (string) FKC_Config::get( 'mode_tenant', 'sous-domaine' );
				$url = 'chemin' === $mode
					? FKC_Plateforme_Amorcage::schema() . '://' . FKC_Config::get( 'hote_portail' ) . '/' . $t['slug'] . '/'
					: FKC_Plateforme_Amorcage::schema() . '://' . $t['slug'] . '.' . FKC_Config::get( 'domaine_base' ) . '/';
				header( 'Location: ' . $url, true, 303 );
				header( 'Cache-Control: no-store' );
				return;
			}
			$erreur = 'Aucun espace actif ne porte cet identifiant.';
		}
		self::entetes( '' !== $erreur ? 404 : 200 );
		$suffixe = '.' . FKC_Config::get( 'domaine_base' );
		echo self::gabarit( 'Accéder à votre espace', '<h1>Accéder à votre espace</h1>'
			. '<p>Saisissez l\'identifiant de votre entreprise, tel qu\'il figure dans l\'adresse de votre espace.</p>'
			. ( '' !== $erreur ? '<p class="err">' . self::e( $erreur ) . '</p>' : '' )
			. '<form method="get" action=""><label for="espace">Identifiant de l\'espace</label>'
			. '<div class="l"><input id="espace" name="espace" autocomplete="organization" autocapitalize="none" spellcheck="false" required maxlength="120" placeholder="votre-entreprise" value="' . self::e( $saisi ) . '"><span class="s">' . self::e( $suffixe ) . '</span></div>'
			. '<button type="submit">Continuer</button></form>' );
	}
}
