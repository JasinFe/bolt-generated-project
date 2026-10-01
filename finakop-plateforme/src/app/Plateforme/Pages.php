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
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );
		if ( FKC_Plateforme_Proxy::estHttps( $_SERVER ) ) { header( 'Strict-Transport-Security: max-age=31536000' ); }
		@header_remove( 'X-Powered-By' );
	}

	/** Coordonnées du support, affichées sous les pages (sauf aux robots). */
	const SUPPORT_TEL  = '+225 05 03 40 43 89';
	const SUPPORT_WA   = '2250503404389';
	const SUPPORT_MAIL = 'supports@finakoperp.com';

	/**
	 * Gabarit commun (1.876.3) : sombre, aux couleurs FinaKop, sans aucune
	 * ressource extérieure (CSS en ligne, logo servi par la plateforme).
	 * $mode : 'portail' (deux colonnes), 'message' (carte centrée), 'nu' (robots).
	 */
	protected static function gabarit( $titre, $corps, $mode = 'message', $icone = '' ) {
		$logo = '/_fkc/img/logo-256.png';
		$css = ':root{--bg:#060A15;--carte:#0E1730;--ligne:rgba(148,170,230,.18);--txt:#E9EEF8;--mut:#9DA9C6;--or:#F7931E;--bleu:#4C7DFF}'
			. '*{box-sizing:border-box}html,body{height:100%}'
			. 'body{margin:0;font:16px/1.55 "Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--txt);background:var(--bg);overflow-x:hidden}'
			. '.fond{position:fixed;inset:0;z-index:-1;background:radial-gradient(900px 600px at 85% -10%,rgba(76,125,255,.28),transparent 60%),radial-gradient(700px 500px at -10% 110%,rgba(247,147,30,.22),transparent 60%),var(--bg)}'
			. '.fond::before{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(148,170,230,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(148,170,230,.05) 1px,transparent 1px);background-size:44px 44px;-webkit-mask-image:radial-gradient(circle at 50% 40%,#000,transparent 75%);mask-image:radial-gradient(circle at 50% 40%,#000,transparent 75%)}'
			. '.aura{position:fixed;width:520px;height:520px;border-radius:50%;filter:blur(110px);opacity:.35;z-index:-1;animation:flotte 18s ease-in-out infinite alternate}'
			. '.a1{background:#2448C9;top:-200px;left:-120px}.a2{background:#B9620E;bottom:-240px;right:-120px;animation-delay:-8s}'
			. '@keyframes flotte{to{transform:translate(70px,50px) scale(1.15)}}'
			. '.page{min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 16px}'
			. '.cadre{width:min(1020px,100%);display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--ligne);border-radius:26px;overflow:hidden;background:rgba(14,23,48,.72);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 40px 100px -30px rgba(0,0,0,.75);animation:entre .7s cubic-bezier(.2,.7,.2,1) both}'
			. '.cadre.seul{width:min(520px,100%);grid-template-columns:1fr}.cadre>*{min-width:0}'
			. '@keyframes entre{from{opacity:0;transform:translateY(24px) scale(.98)}}'
			. '.marque{position:relative;padding:44px 40px;background:linear-gradient(160deg,rgba(76,125,255,.22),rgba(247,147,30,.10) 70%,transparent);border-right:1px solid var(--ligne);display:flex;flex-direction:column;gap:22px;overflow:hidden}'
			. '.logo{display:flex;align-items:center;gap:14px}.logo img{width:66px;height:66px;border-radius:50%;box-shadow:0 14px 40px -12px rgba(247,147,30,.7)}'
			. '.logo b{display:block;font-size:24px;letter-spacing:-.02em}.logo small{display:block;color:var(--mut);font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}'
			. '.marque h2{margin:6px 0 0;font-size:30px;line-height:1.15;letter-spacing:-.02em}'
			. '.grad{background:linear-gradient(90deg,var(--or),#FBBF24 45%,#22D3EE);-webkit-background-clip:text;background-clip:text;color:transparent}'
			. '.atouts{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-direction:column;gap:12px}'
			. '.atouts li{display:flex;gap:12px;align-items:center;font-weight:600;font-size:15px}'
			. '.atouts i{font-style:normal;width:38px;height:38px;flex:none;border-radius:12px;display:grid;place-items:center;background:rgba(255,255,255,.06);border:1px solid var(--ligne)}'
			. '.carte{padding:44px 40px}'
			. '.ic{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;font-size:28px;background:rgba(255,255,255,.06);border:1px solid var(--ligne);margin-bottom:18px}'
			. 'h1{font-size:28px;line-height:1.2;margin:0 0 10px;letter-spacing:-.02em}p{margin:0 0 18px;color:var(--mut)}'
			. 'label{display:block;font-weight:700;font-size:14px;margin-bottom:8px;color:var(--mut)}'
			. '.champ{display:flex;align-items:center;border:1px solid var(--ligne);border-radius:14px;background:rgba(3,7,18,.6);transition:border-color .2s,box-shadow .2s;overflow:hidden}'
			. '.champ:focus-within{border-color:var(--or);box-shadow:0 0 0 4px rgba(247,147,30,.16)}'
			. '.champ .p{padding-left:14px;font-size:18px}'
			. 'input{flex:1;min-width:0;border:0;background:transparent;color:var(--txt);font:inherit;font-size:17px;padding:15px 10px}input:focus{outline:none}input::placeholder{color:#5D6A88}'
			. '.s{padding:0 14px;color:var(--mut);white-space:nowrap;font-weight:600;font-size:15px;border-left:1px solid var(--ligne);align-self:stretch;display:flex;align-items:center;background:rgba(255,255,255,.03)}'
			. 'button{margin-top:18px;width:100%;padding:15px;border:0;border-radius:14px;cursor:pointer;font:inherit;font-weight:800;font-size:16px;color:#1B1203;background:linear-gradient(135deg,var(--or),#FFB547);box-shadow:0 14px 34px -12px rgba(247,147,30,.8);transition:transform .2s,box-shadow .2s}'
			. 'button:hover{transform:translateY(-2px);box-shadow:0 20px 40px -12px rgba(247,147,30,.9)}button:focus-visible,a:focus-visible,summary:focus-visible{outline:2px solid var(--or);outline-offset:3px}'
			. '.err{display:flex;gap:10px;align-items:flex-start;color:#FDA4AF;background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.35);border-radius:12px;padding:10px 12px;font-weight:600;font-size:14.5px}'
			. 'details{margin-top:20px;border:1px solid var(--ligne);border-radius:14px;padding:0 16px;background:rgba(255,255,255,.03)}'
			. 'summary{cursor:pointer;padding:13px 0;font-weight:700;font-size:14.5px;list-style:none}summary::-webkit-details-marker{display:none}summary::after{content:" +";color:var(--or)}details[open] summary::after{content:" −"}'
			. 'details p{font-size:14.5px;margin:0 0 14px}code{background:rgba(255,255,255,.08);border-radius:6px;padding:1px 6px;color:var(--txt)}'
			. '.aide{margin-top:22px;display:flex;flex-wrap:wrap;gap:8px}'
			. '.aide a{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:11px;border:1px solid var(--ligne);color:var(--txt);text-decoration:none;font-size:13.5px;font-weight:600;background:rgba(255,255,255,.03);transition:border-color .2s}'
			. '.aide a:hover{border-color:var(--or)}'
			. 'footer{margin-top:22px;font-size:12.5px;color:#6F7C9C;text-align:center}footer a{color:inherit}'
			. '@media(max-width:820px){.cadre{grid-template-columns:1fr}.marque{border-right:0;border-bottom:1px solid var(--ligne);padding:28px 24px;gap:14px}.marque h2{font-size:23px}.atouts{display:none}.carte{padding:28px 22px}h1{font-size:24px}}'
			. '@media(max-width:420px){.s{font-size:13px;padding:0 10px}input{font-size:16px}}'
			. '@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}';
		$aide = 'nu' === $mode ? '' : '<div class="aide">'
			. '<a href="https://wa.me/' . self::SUPPORT_WA . '" rel="noopener noreferrer" target="_blank">💬 WhatsApp ' . self::e( self::SUPPORT_TEL ) . '</a>'
			. '<a href="mailto:' . self::SUPPORT_MAIL . '">✉️ ' . self::SUPPORT_MAIL . '</a></div>';
		$marque = 'portail' === $mode ? '<aside class="marque">'
			. '<div class="logo"><img src="' . $logo . '" alt="" width="62" height="62"><span><b>FinaKop ERP</b><small>Kophi\'s Group SAS</small></span></div>'
			. '<h2>Votre gestion, <span class="grad">en toute sécurité.</span></h2>'
			. '<ul class="atouts"><li><i>🔐</i>Connexion chiffrée de bout en bout</li><li><i>🏢</i>Un espace isolé pour chaque entreprise</li><li><i>📱</i>Double authentification</li><li><i>💾</i>Sauvegardes chiffrées quotidiennes</li></ul>'
			. '</aside>' : '';
		$tete = 'message' === $mode ? '<div class="logo" style="margin-bottom:22px"><img src="' . $logo . '" alt="" width="46" height="46" style="width:46px;height:46px;border-radius:14px"><span><b style="font-size:19px">FinaKop ERP</b><small>Kophi\'s Group SAS</small></span></div>'
			. ( '' !== $icone ? '<div class="ic">' . $icone . '</div>' : '' ) : '';
		return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><meta name="theme-color" content="#060A15">'
			. '<title>' . self::e( $titre ) . ' · FinaKop</title>' . ( 'nu' === $mode ? '' : '<link rel="icon" type="image/png" href="' . $logo . '">' )
			. '<style>' . $css . '</style></head><body><div class="fond"></div>' . ( 'nu' === $mode ? '' : '<div class="aura a1"></div><div class="aura a2"></div>' )
			. '<div class="page"><div class="cadre' . ( 'portail' === $mode ? '' : ' seul' ) . '">' . $marque . '<main class="carte">' . $tete . $corps . $aide . '</main></div>'
			. '<footer>' . ( 'nu' === $mode ? 'FinaKop' : '© ' . date( 'Y' ) . ' KOPHI\'S GROUP SAS · Accès privé · <a href="https://finakoperp.com/" rel="noopener">finakoperp.com</a>' ) . '</footer></div></body></html>';
	}

	public static function message( $code, $titre, $texte, array $entetes = array(), $icone = 'ℹ️', $mode = 'message' ) {
		self::entetes( $code );
		foreach ( $entetes as $h ) { header( $h ); }
		echo self::gabarit( $titre, '<h1>' . self::e( $titre ) . '</h1><p>' . self::e( $texte ) . '</p>', $mode, $icone );
	}

	public static function inconnu() {
		self::message( 404, 'Espace introuvable', 'Aucun espace FinaKop ne correspond à cette adresse. Vérifiez l\'adresse communiquée par votre administrateur.', array(), '🧭' );
	}
	public static function suspendu() {
		self::message( 403, 'Espace suspendu', 'Cet espace FinaKop est momentanément suspendu. Contactez votre administrateur.', array(), '⏸️' );
	}
	public static function maintenance() {
		self::message( 503, 'Maintenance en cours', 'Cet espace FinaKop est en maintenance. Vos données sont en sécurité. Merci de réessayer dans quelques minutes.', array( 'Retry-After: 900' ), '🛠️' );
	}
	public static function indisponible() {
		self::message( 503, 'Service indisponible', 'FinaKop est momentanément indisponible. Merci de réessayer dans quelques minutes.', array( 'Retry-After: 300' ), '⏳' );
	}
	public static function hoteRefuse() {
		self::message( 400, 'Adresse non reconnue', 'Cette adresse n\'est pas servie par FinaKop.', array(), '🚫' );
	}
	public static function robot() {
		self::message( 403, 'Accès refusé', 'Contenu privé.', array( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai' ), '', 'nu' );
	}
	public static function origineRefusee() {
		self::message( 403, 'Accès refusé', 'Accès direct non autorisé.', array(), '', 'nu' );
	}

	/** Portail : saisie de l'identifiant d'espace, redirection vers l'espace s'il est actif. */
	public static function portail() {
		$saisi = strtolower( trim( (string) ( $_GET['espace'] ?? '' ) ) );
		$erreur = '';
		if ( '' !== $saisi ) {
			// Tolère une adresse collée entière (https://newloock.finakoperp.com/…).
			if ( preg_match( '#^(?:https?://)?([a-z0-9-]+)\.' . preg_quote( (string) FKC_Config::get( 'domaine_base' ), '#' ) . '#', $saisi, $m ) ) { $saisi = $m[1]; }
			// 1.876.2 : redirection SANS consulter le registre. Le portail ne doit
			// pas servir à vérifier quels espaces existent (énumération).
			if ( FKC_Plateforme_Registre::slugValide( $saisi ) ) {
				$mode = (string) FKC_Config::get( 'mode_tenant', 'sous-domaine' );
				$url = 'chemin' === $mode
					? FKC_Plateforme_Amorcage::schema() . '://' . FKC_Config::get( 'hote_portail' ) . '/' . $saisi . '/'
					: FKC_Plateforme_Amorcage::schema() . '://' . $saisi . '.' . FKC_Config::get( 'domaine_base' ) . '/';
				header( 'Location: ' . $url, true, 303 );
				header( 'Cache-Control: no-store' );
				return;
			}
			$erreur = 'Identifiant invalide : lettres minuscules, chiffres et tirets.';
		}
		self::entetes( '' !== $erreur ? 404 : 200 );
		$suffixe = '.' . FKC_Config::get( 'domaine_base' );
		echo self::gabarit( 'Accéder à votre espace', '<h1>Accéder à votre espace</h1>'
			. '<p>Saisissez l\'identifiant de votre entreprise, tel qu\'il figure dans l\'adresse de votre espace.</p>'
			. ( '' !== $erreur ? '<p class="err">⚠️ ' . self::e( $erreur ) . '</p>' : '' )
			. '<form method="get" action=""><label for="espace">Identifiant de l\'espace</label>'
			. '<div class="champ"><span class="p" aria-hidden="true">🏢</span><input id="espace" name="espace" autocomplete="organization" autocapitalize="none" spellcheck="false" required maxlength="120" placeholder="votre-entreprise" value="' . self::e( $saisi ) . '" autofocus><span class="s">' . self::e( $suffixe ) . '</span></div>'
			. '<button type="submit">Accéder à mon espace →</button></form>'
			. '<details><summary>Où trouver mon identifiant ?</summary><p>C\'est le début de l\'adresse de votre espace, communiquée par votre administrateur. Pour <code>atlas' . self::e( $suffixe ) . '</code>, l\'identifiant est <code>atlas</code>. Ajoutez ensuite l\'adresse de votre espace à vos favoris.</p></details>',
			'portail' );
	}
}
