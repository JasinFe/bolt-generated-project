<?php
/**
 * Fonctions utilitaires globales. @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

/** Échappe pour affichage HTML. */
function e( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }

/** Montant FCFA formaté (espace de milliers). */
function fkc_money( $amount, $decimals = 0 ) { return number_format( (float) $amount, $decimals, ',', ' ' ); }

/**
 * Nombre saisi à la française → float (1.864.0).
 *
 * « (float) "1 500" » vaut 1 et « (float) "2,5" » vaut 2 : une vente de
 * 1 500 F passait à 1 F, 2,5 m de câble à 2 m. Accepte espaces (y compris
 * insécables), virgule décimale, et le point des milliers « 1.500.000 »
 * quand il n'est pas décimal.
 */
function fkc_nombre( $v, $defaut = 0.0 ) {
	if ( is_int( $v ) || is_float( $v ) ) { return (float) $v; }
	$s = trim( (string) $v );
	if ( '' === $s ) { return (float) $defaut; }
	$s = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF", ' ', "'" ), '', $s );
	if ( preg_match( '/^-?\d{1,3}(\.\d{3}){2,}$/', $s ) || preg_match( '/^-?\d{1,3}(\.\d{3})+,\d+$/', $s ) ) { $s = str_replace( '.', '', $s ); }
	$s = str_replace( ',', '.', $s );
	return is_numeric( $s ) ? (float) $s : (float) $defaut;
}

/**
 * Montant converti en centimes ENTIERS (arrondi au centime).
 * Sert de base à toute comparaison de montants : deux valeurs comptables ne
 * doivent JAMAIS être comparées entre flottants (dust binaire). @return int
 */
function fkc_cents( $amount ) { return (int) round( (float) $amount * 100 ); }

/**
 * Deux montants sont-ils égaux au centime près ? (comparaison en entiers)
 * Remplace les comparaisons de flottants « round($a,2) === round($b,2) »,
 * fragiles par nature. Utilisé pour l'équilibre débit/crédit des écritures.
 */
function fkc_equilibre( $debit, $credit ) { return fkc_cents( $debit ) === fkc_cents( $credit ); }

/** URL absolue depuis la racine de l'application (intègre le chemin de base éventuel via FKC_BASE_URL). */
function url( $path = '' ) { return rtrim( FKC_BASE_URL, '/' ) . '/' . ltrim( (string) $path, '/' ); }

/**
 * URL ABSOLUE complète (scheme://host/chemin), déduite de la requête courante.
 * Indispensable pour ce qui doit être lu hors du navigateur : QR d'appairage,
 * liens partagés, callbacks. Retombe sur url() si l'hôte est inconnu (CLI).
 */
function abs_url( $path = '' ) {
	$rel = url( $path );
	// Si url() renvoie déjà une URL absolue (déploiement où FKC_BASE_URL est un
	// domaine complet, cas WordPress), ne rien préfixer : sinon on doublerait
	// le schéma et l'hôte (bug d'appairage du QR Smart Scan).
	if ( preg_match( '#^https?://#i', $rel ) ) { return $rel; }
	$host = '';
	if ( ! empty( $_SERVER['HTTP_HOST'] ) ) { $host = (string) $_SERVER['HTTP_HOST']; }
	elseif ( ! empty( $_SERVER['SERVER_NAME'] ) ) { $host = (string) $_SERVER['SERVER_NAME']; }
	if ( '' === $host ) { return $rel; }
	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( ( $_SERVER['SERVER_PORT'] ?? '' ) == 443 )
		|| ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' );
	$scheme = $https ? 'https' : 'http';
	return $scheme . '://' . $host . ( '/' === substr( $rel, 0, 1 ) ? $rel : '/' . $rel );
}

/** Chemin de la requête courante, relatif à la racine de l'application (retire le préfixe FKC_BASE_PATH en mode « chemin »). */
function fkc_rel_path() {
	$uri  = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
	$base = defined( 'FKC_BASE_PATH' ) ? (string) FKC_BASE_PATH : '';
	if ( '' !== $base && 0 === strpos( $uri, $base ) ) {
		$uri = substr( $uri, strlen( $base ) );
	}
	return trim( $uri, '/' );
}

/** URL d'un fichier statique du plugin (CSS, JS, images). Indépendant du mode d'accès (chemin ou sous-domaine). */
function fkc_asset( $rel = '' ) {
	$rel = ltrim( (string) $rel, '/' );
	if ( defined( 'FKC_ASSETS_URL' ) && FKC_ASSETS_URL ) {
		return rtrim( FKC_ASSETS_URL, '/' ) . ( '' !== $rel ? '/' . $rel : '' );
	}
	// Repli : racine du domaine (en retirant l'éventuel chemin de base) + dossier du plugin.
	$base = defined( 'FKC_BASE_URL' ) ? rtrim( FKC_BASE_URL, '/' ) : '';
	$bp   = defined( 'FKC_BASE_PATH' ) ? (string) FKC_BASE_PATH : '';
	if ( '' !== $bp && strlen( $base ) >= strlen( $bp ) && substr( $base, -strlen( $bp ) ) === $bp ) {
		$base = substr( $base, 0, -strlen( $bp ) );
	}
	return $base . '/wp-content/plugins/finakop-erp-core/app/assets/' . $rel;
}

/**
 * Retire les accents d'une chaîne UTF-8.
 *
 * Sert à fabriquer des clés stables (identifiants de volets d'accordéon, slugs)
 * à partir de libellés affichés. iconv() n'est pas toujours disponible et sa
 * translittération dépend de la locale : la table explicite ci-dessous donne le
 * même résultat partout.
 *
 * @param string $s Chaîne accentuée.
 * @return string
 */
function fkc_sans_accents( $s ) {
	return strtr( (string) $s, array(
		'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c',
		'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
		'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','œ'=>'oe',
		'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y',
		'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C',
		'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
		'Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Œ'=>'OE',
		'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y',
	) );
}

/**
 * Tronque une chaîne UTF-8 sans dépendre de mbstring.
 *
 * `contrepasser()` appelait `mb_substr()` sans garde : sur un hébergement sans
 * l'extension mbstring — non déclarée comme requise par l'extension — toute
 * annulation d'écriture s'arrêtait sur « Call to undefined function ». Vingt-deux
 * appels `mb_*` étaient dans ce cas, alors que le projet définit déjà des replis
 * `mb_*_safe` ailleurs : la précaution existait, elle n'était pas appliquée
 * partout.
 *
 * @param string $s   Chaîne à tronquer.
 * @param int    $max Longueur maximale en caractères.
 * @return string
 */
function fkc_tronquer( $s, $max ) {
	$s   = (string) $s;
	$max = (int) $max;
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $s, 0, $max, 'UTF-8' );
	}
	// Découpe sur les frontières de caractères UTF-8 : un substr() brut
	// couperait un caractère accentué en deux et produirait de l'illisible.
	if ( preg_match( '/^.{0,' . $max . '}/us', $s, $m ) ) { return $m[0]; }
	return substr( $s, 0, $max );
}

/*
 * Replis mbstring.
 *
 * Définis seulement si l'extension est absente, et strictement en UTF-8 —
 * l'encodage utilisé partout dans l'application. Ils couvrent les fonctions
 * réellement employées par le code, pas l'API mbstring entière.
 */
if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $s, $enc = null ) {
		$n = preg_match_all( '/./us', (string) $s );
		return false === $n ? strlen( (string) $s ) : $n;
	}
}
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $length = null, $enc = null ) {
		$car = preg_split( '//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $car ) ) { return null === $length ? substr( (string) $s, $start ) : substr( (string) $s, $start, $length ); }
		$out = null === $length ? array_slice( $car, $start ) : array_slice( $car, $start, $length );
		return implode( '', $out );
	}
}
if ( ! function_exists( 'mb_strtolower' ) ) {
	function mb_strtolower( $s, $enc = null ) {
		// strtolower() ignore les accents : on traite explicitement les
		// majuscules accentuées du français et des langues voisines.
		$s = strtr( (string) $s, array(
			'À'=>'à','Á'=>'á','Â'=>'â','Ã'=>'ã','Ä'=>'ä','Å'=>'å','Æ'=>'æ','Ç'=>'ç',
			'È'=>'è','É'=>'é','Ê'=>'ê','Ë'=>'ë','Ì'=>'ì','Í'=>'í','Î'=>'î','Ï'=>'ï',
			'Ñ'=>'ñ','Ò'=>'ò','Ó'=>'ó','Ô'=>'ô','Õ'=>'õ','Ö'=>'ö','Ø'=>'ø',
			'Ù'=>'ù','Ú'=>'ú','Û'=>'û','Ü'=>'ü','Ý'=>'ý','Œ'=>'œ','Š'=>'š','Ž'=>'ž',
		) );
		return strtolower( $s );
	}
}
if ( ! function_exists( 'mb_strtoupper' ) ) {
	function mb_strtoupper( $s, $enc = null ) {
		$s = strtr( (string) $s, array(
			'à'=>'À','á'=>'Á','â'=>'Â','ã'=>'Ã','ä'=>'Ä','å'=>'Å','æ'=>'Æ','ç'=>'Ç',
			'è'=>'È','é'=>'É','ê'=>'Ê','ë'=>'Ë','ì'=>'Ì','í'=>'Í','î'=>'Î','ï'=>'Ï',
			'ñ'=>'Ñ','ò'=>'Ò','ó'=>'Ó','ô'=>'Ô','õ'=>'Õ','ö'=>'Ö','ø'=>'Ø',
			'ù'=>'Ù','ú'=>'Ú','û'=>'Û','ü'=>'Ü','ý'=>'Ý','œ'=>'Œ','š'=>'Š','ž'=>'Ž',
		) );
		return strtoupper( $s );
	}
}
if ( ! function_exists( 'mb_strpos' ) ) {
	function mb_strpos( $h, $n, $offset = 0, $enc = null ) {
		$pos = strpos( (string) $h, (string) $n, (int) $offset );
		if ( false === $pos ) { return false; }
		return mb_strlen( substr( (string) $h, 0, $pos ) );
	}
}
if ( ! function_exists( 'mb_strimwidth' ) ) {
	function mb_strimwidth( $s, $start, $width, $trim = '', $enc = null ) {
		$s = (string) $s;
		if ( mb_strlen( $s ) <= $start + $width ) { return mb_substr( $s, $start ); }
		$coupe = max( 0, $width - mb_strlen( (string) $trim ) );
		return mb_substr( $s, $start, $coupe ) . $trim;
	}
}
if ( ! function_exists( 'mb_check_encoding' ) ) {
	function mb_check_encoding( $s = null, $enc = null ) {
		return (bool) preg_match( '//u', (string) $s );
	}
}
if ( ! function_exists( 'mb_convert_encoding' ) ) {
	/**
	 * Repli sans mbstring (revu en 1.770.0).
	 *
	 * Deux défauts dans le même replide trois lignes :
	 *
	 *   — il appelait `mb_check_encoding()`, qui appartient à l'extension
	 *     ABSENTE. Le repli censé remplacer mbstring plantait donc dans le
	 *     seul cas où il sert.
	 *   — il finissait sur `utf8_encode()`, déprécié depuis PHP 8.2 : sous
	 *     8.5, l'avertissement s'écrit au milieu de la page ou de la sortie
	 *     d'un worker, et l'en-tête HTTP est déjà parti.
	 *
	 * La conversion latin1 → UTF-8 tient en quelques lignes et ne dépend de
	 * rien : elle est écrite ici plutôt qu'empruntée à une extension qui,
	 * par hypothèse, n'est pas là.
	 */
	function mb_convert_encoding( $s, $vers, $depuis = null ) {
		// Seul cas utilisé par l'application : ramener une chaîne en UTF-8 propre.
		$s = (string) $s;
		$deja = function_exists( 'mb_check_encoding' )
			? mb_check_encoding( $s, 'UTF-8' )
			: ( '' === $s || (bool) preg_match( '//u', $s ) );
		if ( $deja ) { return $s; }
		if ( function_exists( 'iconv' ) ) { return (string) @iconv( 'ISO-8859-1', 'UTF-8//IGNORE', $s ); }
		$out = '';
		$n = strlen( $s );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = ord( $s[ $i ] );
			if ( $c < 0x80 ) { $out .= $s[ $i ]; }
			else { $out .= chr( 0xC0 | ( $c >> 6 ) ) . chr( 0x80 | ( $c & 0x3F ) ); }
		}
		return $out;
	}
}

/**
 * UN AVERTISSEMENT PHP NE S'IMPRIME PAS DANS UNE RÉPONSE (1.771.0).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT
 *
 * L'application n'avait aucune position sur `display_errors`. Elle héritait
 * donc de celle du site : sur un hébergement où l'affichage des erreurs est
 * resté actif — cas courant après une montée de version de PHP —, la moindre
 * dépréciation émise par WordPress, par un autre greffon ou par le thème
 * s'écrit AU MILIEU de la réponse de FinaKop. Les conséquences ne sont pas
 * cosmétiques :
 *
 *   — avant un `header()` : la redirection après enregistrement échoue, et
 *     l'écran reste bloqué sur une page blanche barrée d'un message ;
 *   — dans un export CSV ou un PDF : le fichier est corrompu, souvent sans
 *     que personne s'en aperçoive avant de l'ouvrir ;
 *   — dans une réponse JSON : l'appelant ne sait plus la lire.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI EST FAIT, ET CE QUI NE L'EST PAS
 *
 * Rien n'est étouffé : les diagnostics sont REDIRIGÉS vers le journal de
 * l'application (`app.log`), où ils restent lisibles avec leur fichier et
 * leur ligne. Seul l'AFFICHAGE est coupé, et seulement pour les requêtes
 * servies par FinaKop — le reste du site garde le réglage du site.
 *
 * Un développeur qui veut les voir à l'écran définit `FKC_DEBUG` à true dans
 * wp-config.php : la fonction ne touche alors à rien.
 */
function fkc_silence_diagnostics() {
	if ( defined( 'FKC_DEBUG' ) && FKC_DEBUG ) { return; }
	@ini_set( 'display_errors', '0' );
	@ini_set( 'display_startup_errors', '0' );
	// error_reporting n'est PAS abaissé : ce qui est signalé continue de
	// l'être, et de partir au journal — on ne perd pas l'information, on
	// l'envoie là où elle ne casse rien.
	set_error_handler(
		function ( $no, $str, $fichier = '', $ligne = 0 ) {
			if ( ! ( error_reporting() & $no ) ) { return false; }
			$niveaux = array(
				E_DEPRECATED => 'DEPRECATED', E_USER_DEPRECATED => 'DEPRECATED',
				E_NOTICE => 'NOTICE', E_USER_NOTICE => 'NOTICE',
				E_WARNING => 'WARNING', E_USER_WARNING => 'WARNING',
			);
			if ( ! isset( $niveaux[ $no ] ) ) { return false; } // erreurs fatales : au moteur
			fkc_log( $str . ' — ' . basename( (string) $fichier ) . ':' . (int) $ligne, $niveaux[ $no ] );
			return true; // traité : PHP ne l'affiche pas
		},
		E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING
	);
}

/**
 * ÉTAT DE L'ENVIRONNEMENT D'EXÉCUTION (1.772.0).
 *
 * Deux pannes de terrain viennent de montrer ce qui manquait : un envoi de
 * pièce jointe refusé par le serveur sans message, et une dépréciation PHP
 * imprimée au milieu d'une page parce que l'affichage des erreurs était resté
 * actif. Dans les deux cas, la cause vivait dans la configuration du serveur,
 * et rien dans l'application ne permettait de la lire.
 *
 * Cette fonction ne corrige rien : elle DIT. Chaque point porte son verdict
 * (ok, attention, alerte), sa valeur constatée et le geste à faire.
 *
 * @return array liste de points { cle, libelle, valeur, etat, conseil }
 */
function fkc_environnement() {
	$octets = function ( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return 0; }
		$u = strtolower( substr( $v, -1 ) );
		$n = (float) $v;
		if ( 'g' === $u ) { return (int) ( $n * 1073741824 ); }
		if ( 'm' === $u ) { return (int) ( $n * 1048576 ); }
		if ( 'k' === $u ) { return (int) ( $n * 1024 ); }
		return (int) $n;
	};
	$pts = array();

	/*
	 * PHP : 8.1 est le socle, 8.4 la version recommandée. 8.5 fonctionne,
	 * mais WordPress ne l'annonce pas encore comme prise en charge : ses
	 * propres dépréciations s'y affichent, ce qui n'est pas de notre ressort.
	 */
	$v = PHP_VERSION;
	$etat = 'ok'; $conseil = '';
	if ( version_compare( $v, '8.1', '<' ) ) {
		$etat = 'alerte'; $conseil = 'Version trop ancienne : le socle minimal est 8.1. Passez à 8.4 chez votre hébergeur.';
	} elseif ( version_compare( $v, '8.4', '<' ) ) {
		$etat = 'attention'; $conseil = 'Fonctionne, mais 8.4 est la version recommandée (plus rapide, et encore suivie en sécurité).';
	} elseif ( version_compare( $v, '8.5', '>=' ) ) {
		$etat = 'attention'; $conseil = 'FinaKop est compatible, mais WordPress ne prend pas encore officiellement en charge 8.5 : ses propres dépréciations peuvent apparaître au journal. 8.4 reste le choix sûr.';
	}
	$pts[] = array( 'cle' => 'php', 'libelle' => 'Version de PHP', 'valeur' => $v, 'etat' => $etat, 'conseil' => $conseil );

	/* Extensions : sans elles, ce sont des écrans entiers qui tombent. */
	$exts = array(
		'pdo_sqlite' => 'base de données — indispensable',
		'mbstring'   => 'textes accentués, imports',
		'intl'       => 'formats de dates et de nombres',
		'gd'         => 'images, codes-barres, étiquettes',
		'zip'        => 'exports et sauvegardes',
		'iconv'      => 'conversion des imports latin1',
	);
	foreach ( $exts as $ext => $quoi ) {
		$present = extension_loaded( $ext );
		$critique = in_array( $ext, array( 'pdo_sqlite' ), true );
		$pts[] = array(
			'cle' => 'ext_' . $ext, 'libelle' => 'Extension ' . $ext,
			'valeur' => $present ? 'activée' : 'absente',
			'etat' => $present ? 'ok' : ( $critique ? 'alerte' : 'attention' ),
			'conseil' => $present ? '' : 'Activez-la dans le sélecteur PHP de votre hébergement (' . $quoi . '). Un changement de version de PHP décoche souvent les extensions.',
		);
	}

	/*
	 * Affichage des erreurs : un message imprimé avant un en-tête casse une
	 * redirection, et corrompt un export. Depuis la 1.771.0, FinaKop le coupe
	 * pour ses propres pages — le dire reste utile pour le reste du site.
	 */
	$aff = (string) ini_get( 'display_errors' );
	$actif = ! ( '' === $aff || '0' === $aff || 'off' === strtolower( $aff ) );
	$pts[] = array(
		'cle' => 'display_errors', 'libelle' => 'Affichage des erreurs PHP',
		'valeur' => $actif ? 'activé' : 'coupé',
		'etat' => $actif ? 'attention' : 'ok',
		'conseil' => $actif ? 'FinaKop le coupe pour ses propres pages, mais le reste du site imprimera les avertissements de WordPress ou d\'un autre greffon. Mettez display_errors sur Off en production.' : '',
	);

	/* Envoi de fichiers : la limite réelle est la plus petite des trois. */
	$upload = $octets( ini_get( 'upload_max_filesize' ) );
	$post   = $octets( ini_get( 'post_max_size' ) );
	$plafond = min( array_filter( array( $upload, $post, 8388608 ) ) );
	$pts[] = array(
		'cle' => 'upload', 'libelle' => 'Pièces jointes — plafond réel',
		'valeur' => round( $plafond / 1048576, 1 ) . ' Mo'
			. ' (upload_max_filesize ' . ini_get( 'upload_max_filesize' ) . ', post_max_size ' . ini_get( 'post_max_size' ) . ')',
		'etat' => $plafond >= 4194304 ? 'ok' : 'attention',
		'conseil' => $plafond >= 4194304 ? '' : 'Un scan de facture dépasse souvent cette taille. Augmentez upload_max_filesize ET post_max_size chez votre hébergeur.',
	);

	/* Temps d'exécution : les clôtures et imports sont les plus longs. */
	$max = (int) ini_get( 'max_execution_time' );
	$pts[] = array(
		'cle' => 'max_execution_time', 'libelle' => 'Temps d\'exécution maximal',
		'valeur' => $max > 0 ? $max . ' s' : 'illimité',
		'etat' => ( 0 === $max || $max >= 60 ) ? 'ok' : 'attention',
		'conseil' => ( 0 === $max || $max >= 60 ) ? '' : 'Une clôture annuelle ou un gros import peut dépasser ' . $max . ' s. 120 s est un réglage confortable.',
	);

	/* Dossier de données : il doit être inscriptible, et hors de la racine web. */
	$dir = defined( 'FKC_DATA_DIR' ) ? FKC_DATA_DIR : '';
	$ecrit = $dir && is_dir( $dir ) && is_writable( $dir );
	$pts[] = array(
		'cle' => 'data_dir', 'libelle' => 'Dossier de données',
		'valeur' => $ecrit ? 'inscriptible' : 'problème d\'accès',
		'etat' => $ecrit ? 'ok' : 'alerte',
		'conseil' => $ecrit ? '' : 'FinaKop ne peut pas écrire dans ' . $dir . ' : vérifiez les droits du dossier.',
	);

	return $pts;
}

/**
 * Redirection HTTP puis arrêt — toujours à l'intérieur de l'application.
 *
 * L'ancienne implémentation traitait comme absolue toute chaîne commençant par
 * « http », ce qui ouvrait une redirection arbitraire dès qu'un appelant
 * passait une valeur postée (cas de RecouvrementController). Désormais tout ce
 * qui porte un schéma, un double slash initial ou un caractère de contrôle est
 * réduit à son chemin, puis préfixé par la base de l'application.
 *
 * @param string $path Chemin relatif à la racine de l'application.
 */
/**
 * Redirection interne.
 *
 * `exit` termine le processus, ce qui est juste en production et
 * catastrophique pour un harnais d'intégration : le premier contrôleur qui
 * redirige tuerait le scénario au milieu, et l'on ne saurait jamais si la
 * suite fonctionne. Sous FKC_TEST_REDIRECT, la redirection est donc LEVÉE
 * plutôt qu'exécutée — le harnais l'attrape, lit la destination, et
 * continue.
 *
 * La constante n'est jamais définie en production : elle l'est par le
 * harnais, dans son propre processus.
 */
function redirect( $path ) {
	if ( defined( 'FKC_TEST_REDIRECT' ) && FKC_TEST_REDIRECT ) {
		throw new FKC_Redirection( fkc_url_interne( $path ), $path );
	}
	header( 'Location: ' . fkc_url_interne( $path ) );
	exit;
}

/** Redirection interceptée par un harnais. Jamais levée en production. */
/**
 * Réponse JSON en mode test (1.844.1) : le pendant de FKC_Redirection pour les
 * contrôleurs qui répondent en JSON puis s'arrêtent (exit). Sous
 * FKC_TEST_REDIRECT, la réponse est levée au lieu d'être émise : un harnais
 * peut enchaîner plusieurs requêtes réelles dans le même processus.
 */
class FKC_ReponseJson extends \RuntimeException {
	public $donnees;
	public function __construct( $donnees ) {
		parent::__construct( 'Réponse JSON' );
		$this->donnees = $donnees;
	}
}

class FKC_Redirection extends \RuntimeException {
	public $url;
	public $chemin;
	public function __construct( $url, $chemin ) {
		parent::__construct( 'Redirection vers ' . $chemin );
		$this->url = $url;
		$this->chemin = $chemin;
	}
}

/**
 * Normalise une cible de redirection en URL interne sûre.
 *
 * Conserve le chemin et la chaîne de requête, écarte le schéma, l'hôte et le
 * fragment. Une cible vide ou externe retombe sur l'accueil.
 *
 * @param string $cible Chemin ou URL proposée (éventuellement d'origine utilisateur).
 * @return string URL absolue appartenant à l'application.
 */
function fkc_url_interne( $cible ) {
	$cible = (string) $cible;
	// Coupe net sur tout caractère de contrôle : ceinture et bretelles face à
	// une tentative d'injection d'en-tête (PHP filtre déjà les CRLF).
	$cible = preg_replace( '/[\x00-\x1F\x7F]/', '', $cible );

	// « //evil.example » est protocol-relative : le navigateur y verrait un
	// hôte externe. On le traite comme une URL absolue.
	$absolue = preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $cible ) || 0 === strpos( $cible, '//' );

	$chemin = (string) parse_url( $cible, PHP_URL_PATH );
	$query  = (string) parse_url( $cible, PHP_URL_QUERY );

	if ( $absolue ) {
		// On ne suit une URL absolue que si elle désigne cette application.
		$baseHost = defined( 'FKC_BASE_URL' ) ? (string) parse_url( FKC_BASE_URL, PHP_URL_HOST ) : '';
		$cibleHost = (string) parse_url( $cible, PHP_URL_HOST );
		if ( '' === $baseHost || strtolower( $cibleHost ) !== strtolower( $baseHost ) ) {
			return url( '' );
		}
	}

	// Retire le préfixe de base éventuel : url() le rajoutera.
	$base = defined( 'FKC_BASE_PATH' ) ? (string) FKC_BASE_PATH : '';
	if ( '' !== $base && 0 === strpos( $chemin, $base ) ) {
		$chemin = substr( $chemin, strlen( $base ) );
	}
	$chemin = ltrim( (string) $chemin, '/' );

	// « ../ » ne doit pas permettre de remonter hors de l'application.
	if ( false !== strpos( $chemin, '..' ) ) { return url( '' ); }

	return url( $chemin . ( '' !== $query ? '?' . $query : '' ) );
}

/** Anciennes valeurs de formulaire (flash). */
function old( $key, $default = '' ) {
	return isset( $_SESSION['__old'][ $key ] ) ? $_SESSION['__old'][ $key ] : $default;
}

/**
 * Journalisation simple dans le dossier de données, avec rotation.
 *
 * Sans plafond, app.log croissait indéfiniment — et était décompté du quota de
 * stockage de la licence via FKC_Storage::usedBytes(). On conserve deux
 * générations de 2 Mo.
 */
function fkc_log( $msg, $level = 'INFO' ) {
	if ( ! defined( 'FKC_DATA_DIR' ) ) { return; }
	$fichier = FKC_DATA_DIR . 'app.log';
	if ( is_file( $fichier ) && @filesize( $fichier ) > 2097152 ) {
		@rename( $fichier, $fichier . '.1' ); // écrase la génération précédente
	}
	// Une entrée de journal reste sur une ligne : un message multiligne
	// casserait la lecture et permettrait d'y injecter de fausses entrées.
	$msg = str_replace( array( "\r", "\n" ), ' ', (string) $msg );
	@file_put_contents( $fichier, '[' . date( 'Y-m-d H:i:s' ) . "][$level] $msg\n", FILE_APPEND );
}

/**
 * Numéro de version affiché sur les pages PUBLIQUES (connexion…), 1.876.2.
 * Sur la plateforme (FKC_MASQUER_VERSION), il n'est pas affiché : il indiquerait
 * à un attaquant quelles failles connues essayer.
 */
function fkc_version_publique() {
	if ( defined( 'FKC_MASQUER_VERSION' ) && FKC_MASQUER_VERSION ) { return ''; }
	return ' v' . htmlspecialchars( (string) FKC_VERSION, ENT_QUOTES, 'UTF-8' );
}

/**
 * Flux temps réel (SSE) autorisés ? (1.876.0)
 *
 * Un flux SSE (afficheur client, scanner) garde un processus PHP occupé en
 * permanence tant que l'écran est ouvert. Sur un hébergement mutualisé, où
 * les processus simultanés sont comptés (≈ 30 chez Hostinger Business, pour
 * tous les clients), dix écrans en prendraient le tiers. FKC_SSE = false fait
 * basculer ces écrans sur leur interrogation courte, déjà existante
 * (requêtes de quelques millisecondes). Par défaut : SSE actif (comportement
 * historique de l'extension).
 */
function fkc_sse_actif() {
	return ! defined( 'FKC_SSE' ) || (bool) FKC_SSE;
}

/** Fin immédiate d'un flux SSE désactivé : 204 indique au navigateur de ne pas se reconnecter. */
function fkc_sse_refuser_si_inactif() {
	if ( fkc_sse_actif() ) { return; }
	if ( PHP_SESSION_ACTIVE === session_status() ) { session_write_close(); }
	http_response_code( 204 );
	exit;
}

/**
 * Cellule CSV sans danger à l'ouverture dans un tableur (1.876.0).
 *
 * Excel, LibreOffice et Google Sheets EXÉCUTENT une cellule qui commence par
 * « = », « + », « - », « @ », une tabulation ou un retour chariot : un libellé
 * saisi par un utilisateur (« =HYPERLINK("http://…";"Cliquez") ») devenait
 * une formule chez le comptable qui ouvre l'export (injection CSV).
 * La cellule est alors préfixée d'une apostrophe, qui la force en texte.
 * Les NOMBRES ne sont pas touchés : « -1500 », « -1 500,00 » restent des
 * montants négatifs.
 */
function fkc_csv_cellule( $v ) {
	if ( ! is_string( $v ) || '' === $v ) { return $v; }
	if ( false === strpos( "=+-@\t\r", $v[0] ) ) { return $v; }
	$n = str_replace( array( ' ', "\xC2\xA0", "\xE2\x80\xAF", ',' ), array( '', '', '', '.' ), $v );
	if ( is_numeric( $n ) ) { return $v; }
	return "'" . $v;
}

/**
 * Exporte un tableau en CSV (séparateur ';' compatible Excel FR, UTF-8 BOM) puis termine.
 * @param string $filename nom du fichier
 * @param array  $rows     lignes (chaque ligne = tableau de cellules)
 */
function fkc_csv( $filename, array $rows ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	}
	echo "\xEF\xBB\xBF"; // BOM UTF-8
	$out = fopen( 'php://output', 'w' );
	foreach ( $rows as $r ) { fputcsv( $out, array_map( 'fkc_csv_cellule', (array) $r ), ';' ); }
	fclose( $out );
	exit;
}

/** Tranche d'ancienneté (jours) → libellé de bucket pour balances âgées. */
function fkc_age_bucket( $jours ) {
	if ( $jours <= 0 )  { return 'non_echu'; }
	if ( $jours <= 30 ) { return 'b30'; }
	if ( $jours <= 60 ) { return 'b60'; }
	if ( $jours <= 90 ) { return 'b90'; }
	return 'b90p';
}

if ( ! function_exists( 'fkc_lc' ) ) {
	/** Minuscule sûre (accents) avec repli si mbstring absent. */
	function fkc_lc( $s ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s ); }
}

if ( ! function_exists( 'fkc_options_moyens' ) ) {
	/**
	 * Options d'un sélecteur de MOYEN DE PAIEMENT, lues du référentiel.
	 *
	 * POURQUOI CE HELPER EXISTE (1.681.0)
	 *
	 * Vingt et un écrans, répartis sur dix-neuf packs, écrivaient leur propre
	 * liste à la main — invariablement la même :
	 *
	 *     <option value="espece">Espèces</option>
	 *     <option value="carte">Carte</option>
	 *     <option value="mobile_money">Mobile Money</option>
	 *
	 * Deux conséquences. La première : « Mobile Money » y est une FAMILLE et
	 * non un moyen. Tout encaissement passé par ces écrans atterrissait sur
	 * 552700 « opérateur non identifié » — le total du groupe restait juste,
	 * mais aucun relevé Wave, Orange, MTN ou Moov ne pouvait être rapproché,
	 * et les commissions d'opérateur n'étaient rattachables à rien. L'éditeur
	 * a posé la règle : « mobile » est une catégorie, jamais un moyen final.
	 *
	 * La seconde : ni le chèque ni le virement n'étaient proposés nulle part,
	 * alors que le référentiel les gère depuis 1.589.0, avec leurs comptes
	 * d'attente (513, 515). Une liste recopiée vingt et une fois ne suit
	 * jamais le référentiel qu'elle copie ; c'est la leçon déjà tirée du
	 * catalogue de packs du générateur de licences.
	 *
	 * Le tri par NATURE regroupe les quatre opérateurs sous un `optgroup`, ce
	 * qui rend la liste plus lisible qu'avant malgré ses huit entrées.
	 *
	 * @param string $selectionne code actuellement retenu (résolu par le
	 *                            référentiel : « especes », « om », « mtn »…
	 *                            restent reconnus sur les données anciennes).
	 * @param bool   $vide        ajoute une première option vide.
	 * @return string HTML des <option> / <optgroup>, déjà échappé.
	 */
	function fkc_options_moyens( $selectionne = '', $vide = false ) {
		if ( ! class_exists( 'FKC_MoyenPaiement' ) ) {
			return '<option value="espece">Espèces</option>';
		}
		$sel = FKC_MoyenPaiement::normaliser( $selectionne );
		$natures = array(
			'espece'   => 'Espèces',
			'mobile'   => 'Mobile Money',
			'carte'    => 'Carte',
			'virement' => 'Banque',
			'cheque'   => 'Chèque',
		);
		$out = $vide ? '<option value="">— à choisir —</option>' : '';
		foreach ( FKC_MoyenPaiement::parNature() as $nature => $moyens ) {
			$groupe = count( $moyens ) > 1;
			if ( $groupe ) { $out .= '<optgroup label="' . e( $natures[ $nature ] ?? ucfirst( $nature ) ) . '">'; }
			foreach ( $moyens as $code => $d ) {
				$out .= '<option value="' . e( $code ) . '"' . ( $sel === $code ? ' selected' : '' ) . '>'
					. e( $d['icone'] . ' ' . $d['label'] ) . '</option>';
			}
			if ( $groupe ) { $out .= '</optgroup>'; }
		}
		return $out;
	}
}
