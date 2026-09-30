<?php
/**
 * FINAKOP CONNECT — notifications poussées (Web Push).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POURQUOI C'EST *LA* RÉPONSE POUR L'HÉBERGEMENT MUTUALISÉ
 *
 * Un client sur mutualisé ne peut pas faire tourner de serveur WebSocket, et
 * l'interrogation régulière coûte cher : chaque appel réveille WordPress
 * entier. Multiplié par dix onglets ouverts toute la journée, c'est ce qui
 * fait écrire un hébergeur.
 *
 * Le Web Push renverse le sens du trafic. Le navigateur maintient UNE
 * connexion — vers le service de son fabricant, pas vers FinaKop. Le serveur
 * n'envoie quelque chose que lorsqu'il a quelque chose à dire. Onglet fermé,
 * navigateur fermé sur mobile : la notification arrive quand même, et le
 * mutualisé n'a rien fait entre-temps.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * AUCUNE CHARGE UTILE N'EST ENVOYÉE, ET C'EST UN CHOIX
 *
 * La norme permet de chiffrer un contenu dans la poussée (RFC 8291). On ne
 * le fait pas, pour deux raisons qui vont dans le même sens :
 *
 *   1. LE CONTENU PASSERAIT PAR LE SERVICE DU FABRICANT. Chiffré, certes —
 *      mais implémenter soi-même un échange de clés ECDH et un chiffrement
 *      aes128gcm pour faire transiter le texte d'une conversation
 *      d'entreprise par Google ou Apple est un risque qu'on prend rarement
 *      en connaissance de cause. Une conversation de niveau 2 n'a rien à
 *      faire là.
 *
 *   2. LA POUSSÉE NE PORTE DONC QU'UN SIGNAL. Le service worker, réveillé,
 *      redemande à FinaKop ce qu'il doit afficher — avec la session de
 *      l'utilisateur, et donc ses droits. Quelqu'un retiré d'un groupe cesse
 *      de voir les titres à la seconde suivante, sans qu'aucune poussée
 *      n'ait à être « rappelée ».
 *
 * Le prix à payer est réel et il faut le dire : la notification affiche un
 * texte générique quand le service worker ne parvient pas à joindre le
 * serveur (réseau coupé au moment du réveil).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ENVOI DIFFÉRÉ, JAMAIS DANS LA REQUÊTE DE L'UTILISATEUR
 *
 * Joindre un service de poussée prend de 200 ms à plusieurs secondes, et il
 * peut ne pas répondre. Le faire pendant l'envoi d'un message ferait attendre
 * celui qui écrit — pour prévenir quelqu'un d'autre. Les poussées passent
 * donc par la file différée (FKC_BPE), traitée par le worker.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Connect_Push {

	/** Tâche de la file différée. */
	const TACHE = 'connect_push';

	/** Clés VAPID, dans le coffre du cabinet (chiffrées au repos). */
	const CLE_PRIVEE = 'connect_vapid_priv';
	const CLE_PUBLIQUE = 'connect_vapid_pub';

	/** Au-delà, un abonnement est considéré mort et retiré. */
	const ECHECS_MAX = 5;

	/**
	 * La poussée est-elle possible sur cette installation ?
	 *
	 * HTTPS est une exigence de la norme, pas une précaution : un navigateur
	 * refuse purement et simplement d'enregistrer un service worker hors
	 * d'un contexte sûr. Le dire ici évite de chercher pourquoi « rien ne se
	 * passe » sur une installation en clair.
	 *
	 * @return array{ok:bool,motif:string}
	 */
	public static function disponible() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return array( 'ok' => false, 'motif' => 'OpenSSL est absent : les clés de signature ne peuvent pas être créées.' );
		}
		$courbes = function_exists( 'openssl_get_curve_names' ) ? (array) openssl_get_curve_names() : array();
		if ( $courbes && ! in_array( 'prime256v1', $courbes, true ) ) {
			return array( 'ok' => false, 'motif' => 'La courbe prime256v1 n\'est pas disponible sur cet hébergement.' );
		}
		if ( class_exists( 'FKC_Security' ) && ! FKC_Security::isHttps() ) {
			return array( 'ok' => false, 'motif' => 'Le site n\'est pas servi en HTTPS : les navigateurs refusent la poussée hors contexte sûr.' );
		}
		return array( 'ok' => true, 'motif' => '' );
	}

	/* ═══════════════ Clés VAPID ═══════════════ */

	/**
	 * Clé publique à donner au navigateur, créée au premier appel.
	 *
	 * La paire est propre à l'INSTALLATION, pas à la société : c'est le
	 * domaine qui s'identifie auprès du service de poussée. La regénérer
	 * invaliderait tous les abonnements existants — d'où le fait qu'on ne la
	 * crée qu'une fois, et qu'on ne propose nulle part de la refaire.
	 */
	public static function clePublique() {
		if ( ! class_exists( 'FKC_Vault' ) ) { return ''; }
		$pub = (string) FKC_Vault::get( self::CLE_PUBLIQUE, '' );
		$priv = (string) FKC_Vault::get( self::CLE_PRIVEE, '' );
		if ( '' !== $pub && '' !== $priv ) { return $pub; }

		$d = self::disponible();
		if ( ! $d['ok'] ) { return ''; }

		$res = @openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		if ( ! $res ) { return ''; }
		$det = openssl_pkey_get_details( $res );
		if ( empty( $det['ec']['x'] ) || empty( $det['ec']['y'] ) || empty( $det['ec']['d'] ) ) { return ''; }

		// Point non compressé : 0x04 || X || Y, chacun sur 32 octets.
		$pubBin = "\x04" . str_pad( $det['ec']['x'], 32, "\x00", STR_PAD_LEFT )
			. str_pad( $det['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		$pub = self::b64( $pubBin );

		// La clé privée part au coffre au format PEM : c'est ce qu'OpenSSL
		// sait relire, et FKC_Vault la chiffre au repos.
		$pem = '';
		openssl_pkey_export( $res, $pem );
		if ( '' === $pem ) { return ''; }
		FKC_Vault::set( self::CLE_PRIVEE, $pem );
		FKC_Vault::set( self::CLE_PUBLIQUE, $pub );
		return $pub;
	}

	/** Les clés existent-elles déjà ? (écran de diagnostic) */
	public static function clesPresentes() {
		if ( ! class_exists( 'FKC_Vault' ) ) { return false; }
		return '' !== (string) FKC_Vault::get( self::CLE_PUBLIQUE, '' )
			&& '' !== (string) FKC_Vault::get( self::CLE_PRIVEE, '' );
	}

	/* ═══════════════ Abonnements ═══════════════ */

	/**
	 * Enregistre l'abonnement d'un appareil.
	 *
	 * L'ENDPOINT EST LA CLÉ D'UNICITÉ, pas l'utilisateur : deux personnes
	 * peuvent se relayer sur la même tablette, et une même personne avoir
	 * trois appareils. Si l'endpoint change de titulaire, on le réattribue
	 * plutôt que d'empiler — sinon l'ancien continuerait de recevoir les
	 * notifications du nouveau.
	 */
	public static function abonner( $endpoint, $p256dh, $auth, $ua = '' ) {
		FKC_Connect_Schema::ensure();
		$endpoint = trim( (string) $endpoint );
		if ( '' === $endpoint || 0 !== strpos( $endpoint, 'https://' ) ) {
			return array( false, 'Point de poussée invalide.' );
		}
		if ( ! self::pointAutorise( $endpoint ) ) {
			return array( false, 'Point de poussée refusé : service de notification inconnu.' );
		}
		if ( strlen( $endpoint ) > 900 ) { return array( false, 'Point de poussée trop long.' ); }
		$uid = class_exists( 'FKC_Auth' ) ? (int) FKC_Auth::id() : 0;
		if ( $uid <= 0 ) { return array( false, 'Session requise.' ); }
		try {
			FKC_DB::q(
				"INSERT INTO connect_appareils(user_id,endpoint,p256dh,auth,ua,vu_at,echecs)
				 VALUES(?,?,?,?,?,datetime('now','localtime'),0)
				 ON CONFLICT(endpoint) DO UPDATE SET user_id=excluded.user_id, p256dh=excluded.p256dh,
				   auth=excluded.auth, ua=excluded.ua, vu_at=excluded.vu_at, echecs=0",
				array( $uid, $endpoint, (string) $p256dh, (string) $auth, substr( (string) $ua, 0, 190 ) )
			);
		} catch ( \Throwable $e ) { return array( false, $e->getMessage() ); }
		return array( true, 'Notifications activées sur cet appareil.' );
	}

	public static function desabonner( $endpoint ) {
		FKC_Connect_Schema::ensure();
		try { FKC_DB::q( 'DELETE FROM connect_appareils WHERE endpoint=?', array( (string) $endpoint ) ); }
		catch ( \Throwable $e ) { return array( false, $e->getMessage() ); }
		return array( true, 'Notifications désactivées sur cet appareil.' );
	}

	/** Appareils d'un utilisateur. */
	public static function appareils( $uid ) {
		FKC_Connect_Schema::ensure();
		try { return FKC_DB::q( 'SELECT * FROM connect_appareils WHERE user_id=? ORDER BY id', array( (int) $uid ) )->fetchAll(); }
		catch ( \Throwable $e ) { return array(); }
	}

	public static function compte() {
		FKC_Connect_Schema::ensure();
		try { return (int) FKC_DB::q( 'SELECT COUNT(*) FROM connect_appareils' )->fetchColumn(); }
		catch ( \Throwable $e ) { return 0; }
	}

	/* ═══════════════ Émission ═══════════════ */

	/**
	 * Met en file une poussée pour un utilisateur.
	 *
	 * Rien n'est envoyé ici : voir l'en-tête du fichier. La file porte
	 * l'identifiant de l'utilisateur et RIEN D'AUTRE — pas le texte, pas le
	 * titre. Ce qui n'entre pas dans la file ne peut pas fuir par elle.
	 */
	public static function enfiler( $uid ) {
		if ( ! class_exists( 'FKC_BPE' ) ) { return; }
		$uid = (int) $uid;
		if ( $uid <= 0 ) { return; }
		try {
			// Rien à pousser si l'appareil n'existe pas : on évite d'empiler
			// une tâche qui ne fera que constater son inutilité.
			$n = (int) FKC_DB::q( 'SELECT COUNT(*) FROM connect_appareils WHERE user_id=?', array( $uid ) )->fetchColumn();
			if ( $n <= 0 ) { return; }
			FKC_BPE::empiler( self::TACHE, array( 'user_id' => $uid ), 5 );
		} catch ( \Throwable $e ) { /* la poussée est un confort, jamais une condition */ }
	}

	/** Branche le traitement de la file. Appelé à l'amorçage. */
	public static function enregistrerTraitement() {
		if ( ! class_exists( 'FKC_BPEWorker' ) ) { return; }
		FKC_BPEWorker::traitement( self::TACHE, function ( $charge ) {
			return self::pousserPour( (int) ( $charge['user_id'] ?? 0 ) );
		} );
	}

	/**
	 * Envoie la poussée à tous les appareils d'un utilisateur.
	 *
	 * @return array compte-rendu, pour le journal de la file.
	 */
	public static function pousserPour( $uid ) {
		$d = self::disponible();
		if ( ! $d['ok'] ) { return array( 'ignore' => $d['motif'] ); }
		$pub = self::clePublique();
		if ( '' === $pub ) { return array( 'ignore' => 'clés VAPID indisponibles' ); }

		$envoyes = 0; $retires = 0;
		foreach ( self::appareils( $uid ) as $a ) {
			list( $code, $err ) = self::envoyer( (string) $a['endpoint'] );
			if ( $code >= 200 && $code < 300 ) {
				$envoyes++;
				try { FKC_DB::q( "UPDATE connect_appareils SET echecs=0, vu_at=datetime('now','localtime') WHERE id=?", array( (int) $a['id'] ) ); }
				catch ( \Throwable $e ) {}
				continue;
			}
			/*
			 * 404 et 410 sont DÉFINITIFS : le service de poussée dit que cet
			 * abonnement n'existe plus (navigateur désinstallé, données
			 * effacées). Le garder ferait réessayer indéfiniment vers une
			 * adresse morte, à chaque message.
			 */
			if ( 404 === $code || 410 === $code ) {
				try { FKC_DB::q( 'DELETE FROM connect_appareils WHERE id=?', array( (int) $a['id'] ) ); } catch ( \Throwable $e ) {}
				$retires++;
				continue;
			}
			try {
				FKC_DB::q( 'UPDATE connect_appareils SET echecs=echecs+1 WHERE id=?', array( (int) $a['id'] ) );
				FKC_DB::q( 'DELETE FROM connect_appareils WHERE id=? AND echecs>=?', array( (int) $a['id'], self::ECHECS_MAX ) );
			} catch ( \Throwable $e ) {}
		}
		return array( 'envoyes' => $envoyes, 'retires' => $retires );
	}

	/**
	 * Une poussée, vers un point donné. Sans corps.
	 *
	 * @return array{0:int,1:string} code HTTP, message d'erreur éventuel.
	 */
	protected static function envoyer( $endpoint ) {
		if ( ! self::pointAutorise( $endpoint ) ) { return array( 0, 'point de poussée refusé' ); }
		$jwt = self::jwt( $endpoint );
		if ( '' === $jwt ) { return array( 0, 'signature impossible' ); }
		$entetes = array(
			'Authorization' => 'vapid t=' . $jwt . ', k=' . self::clePublique(),
			'TTL'           => '86400',
			'Content-Length' => '0',
			// Urgency « normal » : le service peut regrouper les envois vers
			// un appareil en veille profonde plutôt que le réveiller.
			'Urgency'       => 'normal',
		);

		if ( function_exists( 'wp_remote_post' ) ) {
			$r = wp_remote_post( $endpoint, array( 'headers' => $entetes, 'body' => '', 'timeout' => 8 ) );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $r ) ) { return array( 0, $r->get_error_message() ); }
			return array( (int) wp_remote_retrieve_response_code( $r ), '' );
		}

		// Hors WordPress (tests, CLI) : flux natif, sans dépendance à cURL.
		$lignes = '';
		foreach ( $entetes as $k => $v ) { $lignes .= $k . ': ' . $v . "\r\n"; }
		$ctx = stream_context_create( array( 'http' => array(
			'method' => 'POST', 'header' => $lignes, 'content' => '',
			'timeout' => 8, 'ignore_errors' => true,
			'follow_location' => 0, // une redirection ne doit pas mener ailleurs (SSRF)
		) ) );
		$reponse = @file_get_contents( $endpoint, false, $ctx );
		$code = 0;
		if ( isset( $http_response_header[0] ) && preg_match( '#\s(\d{3})\s#', (string) $http_response_header[0], $m ) ) {
			$code = (int) $m[1];
		}
		return array( $code, false === $reponse ? 'appel impossible' : '' );
	}

	/**
	 * Le point de poussée désigne-t-il un VRAI service de notification ? (1.876.0)
	 *
	 * L'adresse est fournie par le navigateur, donc par l'utilisateur : sans
	 * contrôle, le serveur émettait une requête vers n'importe quelle adresse
	 * https — y compris le réseau interne de l'hébergeur (SSRF). On n'accepte
	 * que les services de poussée des navigateurs (liste extensible par la
	 * constante FKC_PUSH_HOTES), et seulement s'ils résolvent vers des
	 * adresses publiques.
	 */
	public static function pointAutorise( $endpoint ) {
		$p = parse_url( (string) $endpoint );
		if ( ! $p || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || empty( $p['host'] ) ) { return false; }
		if ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) { return false; }
		$hote = strtolower( rtrim( (string) $p['host'], '.' ) );
		$suffixes = array( 'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com',
			'notify.windows.com', 'push.apple.com' );
		if ( defined( 'FKC_PUSH_HOTES' ) ) {
			foreach ( explode( ',', (string) FKC_PUSH_HOTES ) as $h ) { if ( '' !== trim( $h ) ) { $suffixes[] = strtolower( trim( $h ) ); } }
		}
		$connu = false;
		foreach ( $suffixes as $x ) {
			if ( $hote === $x || str_ends_with( $hote, '.' . $x ) ) { $connu = true; break; }
		}
		if ( ! $connu ) { return false; }
		$ips = @gethostbynamel( $hote ) ?: array();
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; }
		}
		return true;
	}

	/**
	 * Jeton VAPID (JWT signé ES256) pour un service de poussée.
	 *
	 * L'audience est l'ORIGINE du point de poussée, pas son URL entière :
	 * c'est ce que la norme exige, et un jeton signé pour la mauvaise
	 * audience est refusé sans explication utile.
	 */
	protected static function jwt( $endpoint ) {
		$parts = parse_url( $endpoint );
		if ( empty( $parts['host'] ) ) { return ''; }
		$aud = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

		$sujet = 'mailto:support@kophisgroup.com';
		if ( defined( 'FKC_ADMIN_EMAIL' ) && '' !== (string) FKC_ADMIN_EMAIL ) {
			$sujet = 'mailto:' . FKC_ADMIN_EMAIL; // plateforme autonome (1.876.0)
		} elseif ( function_exists( 'get_option' ) ) {
			$mail = (string) get_option( 'admin_email', '' );
			if ( '' !== $mail ) { $sujet = 'mailto:' . $mail; }
		}

		$entete = self::b64( json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$corps  = self::b64( json_encode( array(
			'aud' => $aud,
			'exp' => time() + 43200,   // 12 h : au-delà, les services refusent
			'sub' => $sujet,
		), JSON_UNESCAPED_SLASHES ) );
		$aSigner = $entete . '.' . $corps;

		$pem = class_exists( 'FKC_Vault' ) ? (string) FKC_Vault::get( self::CLE_PRIVEE, '' ) : '';
		if ( '' === $pem ) { return ''; }
		$cle = @openssl_pkey_get_private( $pem );
		if ( ! $cle ) { return ''; }
		$der = '';
		if ( ! @openssl_sign( $aSigner, $der, $cle, OPENSSL_ALGO_SHA256 ) ) { return ''; }
		$brut = self::derVersBrut( $der );
		if ( '' === $brut ) { return ''; }
		return $aSigner . '.' . self::b64( $brut );
	}

	/**
	 * Signature ECDSA : de l'ASN.1 d'OpenSSL aux 64 octets bruts du JWT.
	 *
	 * OpenSSL rend une SEQUENCE de deux INTEGER, de longueur variable, avec
	 * un octet nul de tête quand le premier bit est à 1. Le JWT veut R et S
	 * bruts, sur exactement 32 octets chacun. Sans cette conversion, la
	 * signature est syntaxiquement valide et systématiquement refusée — et
	 * le service de poussée répond « 401 » sans dire pourquoi.
	 */
	protected static function derVersBrut( $der ) {
		$i = 0;
		if ( ! isset( $der[ $i ] ) || "\x30" !== $der[ $i ] ) { return ''; }
		$i++;
		$len = ord( $der[ $i ] ); $i++;
		if ( $len > 0x80 ) { $i += ( $len - 0x80 ); }   // longueur sur plusieurs octets
		$lire = function () use ( $der, &$i ) {
			if ( ! isset( $der[ $i ] ) || "\x02" !== $der[ $i ] ) { return null; }
			$i++;
			$n = ord( $der[ $i ] ); $i++;
			$v = substr( $der, $i, $n ); $i += $n;
			$v = ltrim( $v, "\x00" );
			return str_pad( $v, 32, "\x00", STR_PAD_LEFT );
		};
		$r = $lire(); $s = $lire();
		if ( null === $r || null === $s ) { return ''; }
		return $r . $s;
	}

	protected static function b64( $b ) { return rtrim( strtr( base64_encode( (string) $b ), '+/', '-_' ), '=' ); }

	/* ═══════════════ Diagnostic ═══════════════ */

	/**
	 * État de la chaîne de notification, pour l'écran d'administration.
	 *
	 * CET ÉCRAN EXISTE PARCE QUE LA PANNE EST SILENCIEUSE. Sans HTTPS, sans
	 * clés, ou sans tâche planifiée, tout continue de « fonctionner » : les
	 * messages partent, les compteurs montent, et personne ne reçoit rien.
	 * On préfère l'écrire noir sur blanc.
	 */
	public static function diagnostic() {
		$d = self::disponible();
		$cron = class_exists( 'FKC_BPEWorker' ) ? ! FKC_BPEWorker::cronDesactive() : false;
		$prochain = class_exists( 'FKC_BPEWorker' ) ? FKC_BPEWorker::prochainPassage() : null;
		return array(
			'https'      => class_exists( 'FKC_Security' ) ? FKC_Security::isHttps() : false,
			'openssl'    => function_exists( 'openssl_pkey_new' ),
			'disponible' => $d['ok'],
			'motif'      => $d['motif'],
			'cles'       => self::clesPresentes(),
			'abonnes'    => self::compte(),
			'wpcron'     => $cron,
			'prochain'   => $prochain,
			'en_file'    => self::enFile(),
		);
	}

	/** Poussées en attente dans la file différée. */
	public static function enFile() {
		try {
			return (int) FKC_DB::q(
				'SELECT COUNT(*) FROM erp_sync_queue WHERE tache=? AND statut=?',
				array( self::TACHE, class_exists( 'FKC_BPE' ) ? FKC_BPE::T_ATTENTE : 'attente' )
			)->fetchColumn();
		} catch ( \Throwable $e ) { return 0; }
	}
}
