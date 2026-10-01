<?php
/**
 * Plugin Name:  FinaKop ERP Core
 * Plugin URI:   https://kophisgroup.com/finakop-erp-core
 * Description:  ERP modulaire mono-produit (SYSCOHADA) à éditions sous licence — Starter, Business, Pro, Enterprise, Creative. Multi-sociétés (base SQLite isolée par société). Installable sur n'importe quel site WordPress : l'application se sert par chemin (/finakop) ou par sous-domaine, avec validation de licence et autorisation des accès API par le service KOPHI'S GROUP.
 * Version:      1.876.2
 * Author:       KOPHI'S GROUP SAS
 * Author URI:   https://kophisgroup.com
 * Text Domain:  finakop-erp-core
 * Requires PHP: 8.1
 * Extensions:   pdo_sqlite, openssl (requises) — mbstring recommandée, des replis UTF-8 la suppléent.
 * Requires at least: 5.9
 *
 * @package FinaKop_ERP_Core
 */

defined( 'ABSPATH' ) || exit;

/* ── Socle technique : PHP 8.1 minimum (8.4 recommandé) ──────────────────── */
define( 'FKC_MIN_PHP', '8.1' );
if ( version_compare( PHP_VERSION, FKC_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>FinaKop ERP Core</strong> nécessite PHP ' . esc_html( FKC_MIN_PHP )
			. '+ (PHP 8.4 recommandé). Version détectée : ' . esc_html( PHP_VERSION )
			. '. Demandez la mise à jour de PHP à votre hébergeur.</p></div>';
	} );
	return;
}

/* ── Constantes ──────────────────────────────────────────────────────────── */
define( 'FKC_VERSION', '1.876.2' );
define( 'FKC_PLUGIN_FILE', __FILE__ );
define( 'FKC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FKC_APP_DIR', FKC_PLUGIN_DIR . 'app/' );

/* Valeurs par défaut des réglages (modifiables dans Réglages → FinaKop ERP Core). */
define( 'FKC_DEFAULT_SUBDOMAIN', 'finakopcore' );
define( 'FKC_DEFAULT_SLUG', 'finakop' );
define( 'FKC_DEFAULT_LICENSE_SERVER', 'https://license.kophisgroup.com/api' );

/** Lecture d'un réglage avec valeur de repli. */
function fkc_opt( $key, $default = '' ) {
	$v = get_option( $key, null );
	return ( null === $v || '' === $v ) ? $default : $v;
}

/* ── Activation : réglages par défaut (rend l'extension utilisable partout) ── */
register_activation_hook( __FILE__, 'fkc_activate' );

/**
 * Retire la planification à la désactivation.
 *
 * Laisser un événement WP-Cron pointant vers un crochet dont plus personne
 * n'écoute est sans danger, mais sale : il resterait à tourner à vide dans la
 * table des tâches, à chaque heure, jusqu'à la fin des temps.
 */
function fkc_deactivate() {
	// Même raison qu'à la planification : le noyau n'est pas chargé en
	// wp-admin, donc on n'appelle que des fonctions WordPress.
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'fkc_balayage_temporisation' );
		wp_clear_scheduled_hook( 'fkc_bpe_worker' );
	}
}
register_deactivation_hook( __FILE__, 'fkc_deactivate' );

/**
 * Intervalle de cinq minutes, absent des fréquences livrées par WordPress.
 *
 * Les fréquences natives commencent à l'heure. Une tâche différée l'a été pour
 * ne pas retarder un encaissement : lui faire attendre l'heure suivante
 * reviendrait à annuler le bénéfice de la file.
 */
add_filter( 'cron_schedules', function ( $s ) {
	if ( ! isset( $s['fkc_cinq_minutes'] ) ) {
		$s['fkc_cinq_minutes'] = array( 'interval' => 300, 'display' => 'FinaKop — toutes les 5 minutes' );
	}
	return $s;
} );

/**
 * Pose les deux tâches planifiées, sans dépendre du noyau applicatif.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DÉFAUT CORRIGÉ EN 1.620.0 : LE BALAYAGE N'A JAMAIS ÉTÉ PLANIFIÉ.
 *
 * `fkc_activate()` appelait `FKC_Balayeur::planifier()` sous un
 * `class_exists()`. Or `app/index.php` — qui déclare cette classe — n'est
 * chargé QUE lorsque la requête est servie par l'application, et se termine
 * alors par un `exit`. L'activation, elle, a lieu dans wp-admin : la classe
 * n'y existe pas, la condition était donc toujours fausse et la planification
 * n'a jamais eu lieu sur aucune installation.
 *
 * Le balayage de la temporisation comptable, annoncé en 1.545.0 comme
 * corrigeant le fait que « sur un dossier laissé de côté une semaine, rien ne
 * se publiait », retombait ainsi exactement sur le défaut qu'il corrigeait.
 * Le worker BPE aurait connu le même sort à la ligne suivante.
 *
 * D'où deux changements : la planification n'appelle plus le noyau (elle
 * n'utilise que des fonctions WordPress), et elle est aussi vérifiée à chaque
 * `init` — une extension mise à jour par téléversement de fichiers ne
 * repasse pas par le crochet d'activation. Le coût est d'une lecture
 * d'option déjà en cache.
 */
function fkc_planifier_taches() {
	if ( ! function_exists( 'wp_next_scheduled' ) ) { return; }
	if ( ! wp_next_scheduled( 'fkc_balayage_temporisation' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'fkc_balayage_temporisation' );
	}
	if ( ! wp_next_scheduled( 'fkc_bpe_worker' ) ) {
		wp_schedule_event( time() + 60, 'fkc_cinq_minutes', 'fkc_bpe_worker' );
	}
}
add_action( 'init', 'fkc_planifier_taches', 20 );

/**
 * Passage du worker BPE : consommation de la file des traitements différés.
 *
 * Comme le balayage de la temporisation, ce crochet n'est déclenché que par
 * WP-Cron, n'est atteignable par aucune URL et n'accepte aucun paramètre. Il
 * n'exécute que des traitements DÉCLARÉS dans le code de l'extension et
 * empilés par l'application elle-même : il n'ouvre aucune permission nouvelle.
 */
add_action( 'fkc_bpe_worker', function () {
	try {
		fkc_executer_hors_requete( function () { return FKC_BPEWorker::traiterToutesSocietes(); } );
	} catch ( \Throwable $e ) {
		if ( function_exists( 'fkc_log' ) ) { fkc_log( 'Worker BPE planifié : ' . $e->getMessage(), 'ERROR' ); }
	}
} );

/**
 * Commande WP-CLI — `wp finakop bpe [--max=25]`.
 *
 * POURQUOI ELLE COMPTE AUTANT QUE LE CRON. Sur un hébergement où WP-Cron est
 * coupé (`DISABLE_WP_CRON`, très répandu), le crochet ci-dessus ne part
 * jamais. Sans cette commande, il ne resterait que le bouton d'administration,
 * c'est-à-dire un traitement différé qui dépend d'un humain — ce qui n'est pas
 * un traitement différé. Elle est aussi le point d'accroche d'un cron système :
 *
 *     * / 5 * * * * cd /var/www && wp finakop bpe --quiet
 */
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'finakop bpe', function ( $args, $assoc ) {
		$max = isset( $assoc['max'] ) ? (int) $assoc['max'] : 25;
		$r   = fkc_executer_hors_requete( function () use ( $max ) { return FKC_BPEWorker::traiterToutesSocietes( $max ); } );
		if ( null === $r ) {
			WP_CLI::error( 'FinaKop n\'a pas pu être chargé : application jamais ouverte (aucun registre) ou pdo_sqlite absent.' );
			return;
		}
		foreach ( (array) $r['erreurs'] as $m ) { WP_CLI::warning( $m ); }
		WP_CLI::success( sprintf(
			'%d société(s) — %d tâche(s) : %d réussie(s), %d en échec, %d abandonnée(s), %d récupérée(s).',
			$r['societes'], $r['traitees'], $r['succes'], $r['echecs'], $r['abandons'], $r['recuperees']
		) );
	} );

	/* `wp finakop balayage [--forcer]` — publication des écritures temporisées (1.875.6). */
	WP_CLI::add_command( 'finakop balayage', function ( $args, $assoc ) {
		$forcer = ! empty( $assoc['forcer'] );
		$r = fkc_executer_hors_requete( function () use ( $forcer ) { return FKC_Balayeur::balayerTout( $forcer ); } );
		if ( null === $r ) {
			WP_CLI::error( 'FinaKop n\'a pas pu être chargé : application jamais ouverte (aucun registre) ou pdo_sqlite absent.' );
			return;
		}
		foreach ( (array) $r['erreurs'] as $m ) { WP_CLI::warning( $m ); }
		WP_CLI::success( sprintf( '%d société(s) — %d publiée(s), %d refusée(s), %d retenue(s).',
			$r['societes'], $r['publiees'], $r['refusees'], $r['retenues'] ) );
	} );
}

/**
 * Exécution du balayage planifié.
 *
 * Le crochet n'est déclenché que par WP-Cron : il n'est pas atteignable par
 * une URL, et n'accepte aucun paramètre. Il ne publie que ce qu'une société a
 * elle-même réglé en « temporisé », au terme du délai qu'elle a choisi, avec
 * les mêmes contrôles qu'une validation à la main — il n'ouvre donc aucune
 * permission que l'utilisateur n'ait déjà accordée.
 */
add_action( 'fkc_balayage_temporisation', function () {
	try {
		fkc_executer_hors_requete( function () { return FKC_Balayeur::balayerTout(); } );
	} catch ( \Throwable $e ) {
		if ( function_exists( 'fkc_log' ) ) { fkc_log( 'Balayage planifié : ' . $e->getMessage(), 'ERROR' ); }
	}
} );
function fkc_activate() {
	/*
	 * Balayage planifié de la temporisation comptable.
	 *
	 * Les écritures réglées en « temporisé » attendent leur délai au journal
	 * temporaire puis rejoignent les journaux. Sans planificateur, ce passage
	 * n'avait lieu qu'à l'occasion d'une visite : sur un dossier laissé de
	 * côté une semaine, le délai annoncé « six heures » pouvait en durer cent
	 * cinquante. La planification est posée ici, à l'activation, parce que
	 * c'est le seul moment où l'on est sûr que WordPress est chargé.
	 */
	fkc_planifier_taches();

	add_option( 'fkc_access_mode', 'auto' );          // auto | subdomain | path
	add_option( 'fkc_subdomain_prefix', FKC_DEFAULT_SUBDOMAIN );
	add_option( 'fkc_path_slug', FKC_DEFAULT_SLUG );
	add_option( 'fkc_license_server', '' );           // vide => serveur par défaut
	add_option( 'fkc_api_authority', 'service' );     // service | local
}

/* ── Détermine si la requête courante doit être servie par l'application ──── */
function fkc_resolve_serving() {
	static $cache = null;
	if ( null !== $cache ) { return $cache; }

	$mode   = fkc_opt( 'fkc_access_mode', 'auto' );
	$host   = strtolower( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' );
	$scheme = ( ( isset( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) ? 'https' : 'http';
	$reqPath = '/' . ltrim( (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ), '/' );

	$prefix = trim( (string) fkc_opt( 'fkc_subdomain_prefix', FKC_DEFAULT_SUBDOMAIN ) );
	$slug   = trim( (string) get_option( 'fkc_path_slug', FKC_DEFAULT_SLUG ), '/' ); // get_option : un slug vide (= racine) est respecté

	$homePath = function_exists( 'home_url' ) ? rtrim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) : '';

	$subMatch = ( '' !== $prefix ) && str_starts_with( $host, $prefix . '.' );

	// Sécurité : ne JAMAIS intercepter l'administration WordPress (anti-verrouillage).
	$isAdminCtx = function_exists( 'is_admin' ) && is_admin();

	if ( '' === $slug ) {
		// Mode RACINE : sert l'application à la racine de l'hôte, sauf chemins système WordPress.
		$basePrefix = $homePath; // '' (installation racine) ou '/blog' (sous-répertoire)
		$pathMatch  = ! $isAdminCtx && ! fkc_is_wp_system_path( $reqPath, $homePath );
	} else {
		$basePrefix = $homePath . '/' . $slug; // ex. /finakop ou /blog/finakop
		$pathMatch  = ( $reqPath === $basePrefix || str_starts_with( $reqPath, $basePrefix . '/' ) );
	}

	$serve = false; $kind = ''; $basePath = ''; $baseUrl = '';
	if ( ( 'subdomain' === $mode || 'auto' === $mode ) && $subMatch ) {
		$serve = true; $kind = 'subdomain'; $basePath = ''; $baseUrl = $scheme . '://' . $host . '/';
	} elseif ( ( 'path' === $mode || 'auto' === $mode ) && $pathMatch ) {
		$serve = true; $kind = 'path'; $basePath = $basePrefix; $baseUrl = $scheme . '://' . $host . ( $basePrefix ?: '' ) . '/';
	}
	return $cache = compact( 'serve', 'kind', 'basePath', 'baseUrl' );
}

/**
 * Pose les protections d'un dossier de données : refus HTTP (Apache, IIS),
 * absence d'index, permissions restreintes.
 *
 * @param string $dir Dossier à protéger.
 */
function fkc_protect_dir( $dir ) {
	$dir = trailingslashit( $dir );
	if ( ! is_dir( $dir ) ) { return; }
	@chmod( rtrim( $dir, '/' ), 0750 );

	$htaccess = $dir . '.htaccess';
	$contenu  = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
		. "Options -Indexes\n"
		// Ceinture supplémentaire : même si la directive de refus est ignorée,
		// aucun fichier de données ne doit être interprété ni téléchargé.
		. "<FilesMatch \"\\.(db|db-wal|db-shm|sqlite|key|log|txt|pem|php)$\">\n"
		. "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n"
		. "\t<IfModule !mod_authz_core.c>\n\t\tOrder deny,allow\n\t\tDeny from all\n\t</IfModule>\n"
		. "</FilesMatch>\n"
		. "<IfModule mod_php.c>\n\tphp_flag engine off\n</IfModule>\n";
	if ( ! is_file( $htaccess ) || trim( (string) @file_get_contents( $htaccess ) ) !== trim( $contenu ) ) {
		@file_put_contents( $htaccess, $contenu );
	}

	$webconfig = $dir . 'web.config';
	if ( ! is_file( $webconfig ) ) {
		@file_put_contents(
			$webconfig,
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization>"
			. "<deny users=\"*\" /></authorization></system.webServer></configuration>\n"
		);
	}
	if ( ! is_file( $dir . 'index.html' ) ) { @file_put_contents( $dir . 'index.html', '' ); }
}

/**
 * Le dossier de données est-il joignable en HTTP ?
 *
 * Dépose un fichier témoin dans le dossier, tente de le télécharger depuis
 * l'extérieur, puis le supprime. Un succès signifie que les bases comptables et
 * la clé de chiffrement sont téléchargeables : c'est la situation par défaut
 * sous nginx, où .htaccess n'est pas lu. Le résultat est mis en cache 12 h.
 *
 * @param bool $force Ignorer le cache.
 * @return array( 'expose' => bool|null, 'url' => string, 'testable' => bool )
 */
function fkc_data_dir_expose( $force = false ) {
	$cache = get_option( 'fkc_data_expose', null );
	if ( ! $force && is_array( $cache ) && isset( $cache['at'] ) && ( time() - (int) $cache['at'] ) < 43200 ) {
		return $cache;
	}

	$resultat = array( 'expose' => null, 'url' => '', 'testable' => false, 'at' => time() );

	// Le test n'a de sens que si le dossier est sous la racine web.
	$uploads = wp_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] );
	$dir     = trailingslashit( FKC_DATA_DIR );
	if ( 0 !== strpos( $dir, $base ) ) {
		// Hors du dossier uploads : soit hors racine web (souhaitable), soit à
		// un emplacement que nous ne savons pas cartographier en URL.
		$resultat['expose'] = false;
		update_option( 'fkc_data_expose', $resultat, false );
		return $resultat;
	}

	$relatif = substr( $dir, strlen( $base ) );
	$url     = trailingslashit( $uploads['baseurl'] ) . $relatif . '.fkc-canary.txt';
	$temoin  = $dir . '.fkc-canary.txt';
	$secret  = 'fkc-' . wp_generate_password( 20, false );

	@file_put_contents( $temoin, $secret );
	// sslverify laissé à true : ce test SERT à mesurer une exposition, il ne
	// doit pas lui-même accepter n'importe quel certificat. Si l'hôte présente
	// un certificat invalide, wp_remote_get échoue et le résultat est rapporté
	// « non vérifiable » (avec avertissement) plutôt que faussement rassurant.
	$rep = wp_remote_get( $url, array( 'timeout' => 8, 'sslverify' => true, 'redirection' => 0 ) );
	@unlink( $temoin );

	$resultat['url']      = $url;
	$resultat['testable'] = ! is_wp_error( $rep );
	if ( $resultat['testable'] ) {
		$code = (int) wp_remote_retrieve_response_code( $rep );
		$body = (string) wp_remote_retrieve_body( $rep );
		$resultat['expose'] = ( 200 === $code && false !== strpos( $body, $secret ) );
	}
	update_option( 'fkc_data_expose', $resultat, false );
	return $resultat;
}

/* ── Alerte d'administration : données exposées ──────────────────────────── */
add_action( 'admin_notices', 'fkc_notice_donnees_exposees' );
function fkc_notice_donnees_exposees() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	fkc_definir_data_dir();
	$etat = fkc_data_dir_expose();
	if ( empty( $etat['expose'] ) ) { return; }
	echo '<div class="notice notice-error"><p><strong>FinaKop ERP Core — données comptables exposées.</strong> '
		. 'Le dossier de données est téléchargeable depuis Internet : vos bases SQLite, vos pièces justificatives '
		. 'et la clé de chiffrement sont accessibles publiquement. Votre serveur ignore les fichiers <code>.htaccess</code> '
		. '(configuration nginx, LiteSpeed ou Caddy typique).</p>'
		. '<p><strong>Correctif :</strong> ajoutez dans <code>wp-config.php</code> une ligne '
		. '<code>define( \'FKC_DATA_DIR\', \'/chemin/hors/racine/web/finakop-data/\' );</code> puis déplacez-y le contenu du dossier actuel, '
		. 'ou faites ajouter par votre hébergeur une règle de refus sur ce dossier.</p></div>';
}

/**
 * Ouvre le registre cabinet en lecture/écriture directe, sans amorcer
 * l'application.
 *
 * La page de réglages tourne dans wp-admin : y charger les 60 fichiers du noyau
 * pour lire deux colonnes serait disproportionné, et l'interception de requête
 * n'a pas eu lieu (FKC_ROOT n'est pas défini). On ouvre donc le fichier SQLite
 * directement. Aucune migration n'est déclenchée : si le schéma n'existe pas
 * encore, on renvoie null et l'appelant n'affiche rien.
 *
 * @return PDO|null
 */
function fkc_master_pdo() {
	static $pdo = null;
	static $tente = false;
	if ( $tente ) { return $pdo; }
	$tente = true;
	if ( ! defined( 'FKC_DATA_DIR' ) ) { return null; }
	$fichier = FKC_DATA_DIR . 'finakopcore-master.db';
	if ( ! is_file( $fichier ) ) { return null; }
	try {
		$pdo = new PDO( 'sqlite:' . $fichier, null, null, array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		) );
		$pdo->exec( 'PRAGMA busy_timeout=5000;' );
		// Sonde : la colonne n'existe que sur un schéma à jour.
		$pdo->query( 'SELECT mdp_compromis FROM cabinet_users LIMIT 1' );
	} catch ( \Throwable $e ) {
		$pdo = null;
	}
	return $pdo;
}

/**
 * Comptes dont la connexion est bloquée pour mot de passe par défaut publié.
 *
 * @return array Lignes (id, login, nom_complet).
 */
function fkc_comptes_compromis() {
	$pdo = fkc_master_pdo();
	if ( ! $pdo ) { return array(); }
	try {
		return $pdo->query( 'SELECT id, login, nom_complet FROM cabinet_users WHERE mdp_compromis=1 ORDER BY login' )->fetchAll();
	} catch ( \Throwable $e ) { return array(); }
}

/**
 * Réémet un mot de passe à usage unique et débloque la connexion du compte.
 *
 * Réservé à un administrateur du site (capacité vérifiée par l'appelant) : celui
 * qui contrôle wp-admin contrôle déjà l'hébergement, donc la base et le code.
 * C'est ce qui permet de bloquer un compte au mot de passe publié sans
 * verrouiller définitivement un cabinet hors de ses propres livres.
 *
 * @param string $login Identifiant du compte.
 * @return string|null Mot de passe en clair, ou null en cas d'échec.
 */
function fkc_reemettre_mot_de_passe( $login ) {
	$pdo = fkc_master_pdo();
	if ( ! $pdo ) { return null; }
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789#@%+=?';
	$n = strlen( $alphabet ) - 1;
	$mdp = '';
	for ( $i = 0; $i < 20; $i++ ) { $mdp .= $alphabet[ random_int( 0, $n ) ]; }
	try {
		$st = $pdo->prepare(
			'UPDATE cabinet_users SET password_hash=?, doit_changer=1, mdp_compromis=0, pwd_changed_at=NULL WHERE login=?'
		);
		$st->execute( array( password_hash( $mdp, PASSWORD_DEFAULT ), (string) $login ) );
		if ( 0 === $st->rowCount() ) { return null; }
		$pdo->prepare( 'INSERT INTO security_events(user_id,ip,action,detail) VALUES(NULL,?,?,?)' )
			->execute( array( '', 'mdp_reemis_wp_admin', 'login=' . $login ) );
	} catch ( \Throwable $e ) { return null; }

	$chemin = FKC_DATA_DIR . '.fkc-mot-de-passe-initial.txt';
	@file_put_contents(
		$chemin,
		"FinaKop ERP Core — mot de passe réémis\n"
		. 'Réémis le : ' . date( 'Y-m-d H:i:s' ) . "\n\n"
		. 'Identifiant : ' . $login . "\n"
		. 'Mot de passe : ' . $mdp . "\n\n"
		. "Ce mot de passe est à usage unique : son changement est imposé à la\n"
		. "première connexion. Supprimez ce fichier ensuite.\n"
	);
	@chmod( $chemin, 0600 );
	return $mdp;
}

/* ── Alerte d'administration : compte bloqué pour mot de passe publié ─────── */
add_action( 'admin_notices', 'fkc_notice_comptes_compromis' );
function fkc_notice_comptes_compromis() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	fkc_definir_data_dir();
	$comptes = fkc_comptes_compromis();
	if ( ! $comptes ) { return; }
	$noms = array();
	foreach ( $comptes as $c ) { $noms[] = $c['login']; }
	echo '<div class="notice notice-error"><p><strong>FinaKop ERP Core — connexion bloquée.</strong> '
		. 'Le compte ' . esc_html( implode( ', ', $noms ) ) . ' utilise le mot de passe par défaut publié des versions antérieures. '
		. 'La connexion est refusée jusqu\'à réémission d\'un mot de passe.</p>'
		. '<p><a class="button button-primary" href="' . esc_url( admin_url( 'options-general.php?page=finakop-erp-core' ) ) . '">Réémettre un mot de passe</a></p></div>';
}

/** Définit FKC_DATA_DIR dans un contexte admin où l'interception n'a pas eu lieu. */
function fkc_definir_data_dir() {
	if ( defined( 'FKC_DATA_DIR' ) ) { return; }
	$uploads = wp_upload_dir();
	define( 'FKC_DATA_DIR', trailingslashit( $uploads['basedir'] ) . 'finakop-erp-core-data/' );
}

/**
 * Hôte sous lequel l'application est servie, pour un traitement hors requête.
 *
 * Un passage de WP-Cron arrive sur l'hôte du SITE (kophisgroup.com), pas sur
 * celui de l'application (finakopcore.kophisgroup.com). Or FKC_License vérifie
 * que l'hôte de la requête correspond au domaine du jeton : sans ce réglage,
 * le worker verrait une licence « liée à un autre domaine » et les modules
 * sous licence seraient fermés pendant tout le traitement.
 *
 * Priorité au domaine inscrit dans le jeton installé (lu sans l'amorcer : sa
 * signature est vérifiée plus tard par FKC_License, comme toujours), sinon
 * l'hôte déduit des réglages d'accès.
 *
 * @return string
 */
function fkc_hote_application() {
	$pdo = fkc_master_pdo();
	if ( $pdo ) {
		try {
			$jeton = (string) $pdo->query( "SELECT valeur FROM cab_parametres WHERE cle='license_token'" )->fetchColumn();
			if ( '' !== $jeton ) {
				$charge = json_decode( (string) base64_decode( strtr( strtok( $jeton, '.' ), '-_', '+/' ) ), true );
				$d      = is_array( $charge ) ? strtolower( trim( (string) ( $charge['domain'] ?? '' ) ) ) : '';
				if ( '' !== $d && preg_match( '/^[a-z0-9.-]+(:\d+)?$/', $d ) ) { return $d; }
			}
		} catch ( \Throwable $e ) {} // registre ancien ou illisible : repli ci-dessous
	}
	$site = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( 'path' === fkc_opt( 'fkc_access_mode', 'auto' ) ) { return $site; }
	$prefix = trim( (string) fkc_opt( 'fkc_subdomain_prefix', FKC_DEFAULT_SUBDOMAIN ) );
	return '' === $prefix ? $site : $prefix . '.' . preg_replace( '/^www\./', '', $site );
}

/**
 * Exécute un traitement planifié avec le noyau chargé (1.875.6).
 *
 * Les crochets WP-Cron et les commandes WP-CLI testaient class_exists() sur
 * des classes que seul app/index.php déclare — donc jamais présentes hors
 * d'une page servie : ils sortaient sans rien faire. Le noyau est désormais
 * chargé par app/noyau.php, sans session ni routes.
 *
 * @param callable $travail
 * @return mixed|null Résultat du travail ; null si FinaKop n'a pas pu être chargé.
 */
function fkc_executer_hors_requete( $travail ) {
	if ( ! extension_loaded( 'pdo_sqlite' ) ) { return null; }
	fkc_definir_data_dir();
	$hoteAvant = $_SERVER['HTTP_HOST'] ?? null;
	$hote      = fkc_hote_application();
	$_SERVER['HTTP_HOST'] = $hote;
	try {
		if ( ! defined( 'FKC_NOYAU_CHARGE' ) ) {
			$scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) ?: 'https';
			if ( 'path' === fkc_opt( 'fkc_access_mode', 'auto' ) ) {
				$homePath = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
				$slug     = trim( (string) get_option( 'fkc_path_slug', FKC_DEFAULT_SLUG ), '/' );
				$base     = $homePath . ( '' !== $slug ? '/' . $slug : '' );
			} else {
				$base = '';
			}
			defined( 'FKC_ROOT' )           || define( 'FKC_ROOT', FKC_APP_DIR );
			defined( 'FKC_BASE_PATH' )      || define( 'FKC_BASE_PATH', $base );
			defined( 'FKC_BASE_URL' )       || define( 'FKC_BASE_URL', $scheme . '://' . $hote . $base . '/' );
			defined( 'FKC_ASSETS_URL' )     || define( 'FKC_ASSETS_URL', plugins_url( 'app/assets', __FILE__ ) );
			defined( 'FKC_LICENSE_SERVER' ) || define( 'FKC_LICENSE_SERVER', rtrim( (string) fkc_opt( 'fkc_license_server', FKC_DEFAULT_LICENSE_SERVER ), '/' ) );
			defined( 'FKC_API_AUTHORITY' )  || define( 'FKC_API_AUTHORITY', fkc_opt( 'fkc_api_authority', 'service' ) );
			if ( true !== ( require FKC_APP_DIR . 'noyau.php' ) ) { return null; }
		}
		return $travail();
	} finally {
		if ( null === $hoteAvant ) { unset( $_SERVER['HTTP_HOST'] ); } else { $_SERVER['HTTP_HOST'] = $hoteAvant; }
	}
}

/** Chemins réservés à WordPress : jamais interceptés (admin, login, cron, REST, contenus…). */
function fkc_is_wp_system_path( $reqPath, $homePath = '' ) {
	$p = $reqPath;
	if ( '' !== $homePath && 0 === strpos( $p, $homePath ) ) { $p = substr( $p, strlen( $homePath ) ); }
	$p = '/' . ltrim( (string) $p, '/' );
	$sys = array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-content', '/wp-includes',
		'/wp-cron.php', '/xmlrpc.php', '/wp-comments-post.php', '/wp-trackback.php',
		'/wp-signup.php', '/wp-activate.php', '/wp-links-opml.php' );
	foreach ( $sys as $s ) {
		if ( $p === $s || 0 === strpos( $p, $s . '/' ) || 0 === strpos( $p, $s . '?' ) ) { return true; }
	}
	return false;
}

/* ── Interception (avant le rendu du thème) ──────────────────────────────── */
add_action( 'plugins_loaded', 'fkc_intercept_request', 1 );
add_action( 'init', 'fkc_intercept_request', 1 );
add_action( 'template_redirect', 'fkc_intercept_request', 0 );
$GLOBALS['_fkc_served'] = false;

function fkc_intercept_request() {
	if ( ! empty( $GLOBALS['_fkc_served'] ) ) { return; }
	$srv = fkc_resolve_serving();
	if ( empty( $srv['serve'] ) ) { return; }
	$GLOBALS['_fkc_served'] = true;

	defined( 'FKC_ROOT' ) || define( 'FKC_ROOT', FKC_APP_DIR );
	defined( 'FKC_BASE_URL' ) || define( 'FKC_BASE_URL', $srv['baseUrl'] );
	defined( 'FKC_BASE_PATH' ) || define( 'FKC_BASE_PATH', $srv['basePath'] ); // '' (sous-domaine) ou '/finakop' (chemin)
	defined( 'FKC_ASSETS_URL' ) || define( 'FKC_ASSETS_URL', plugins_url( 'app/assets', __FILE__ ) ); // fichiers statiques : toujours la racine du plugin
	defined( 'FKC_LICENSE_SERVER' ) || define( 'FKC_LICENSE_SERVER', rtrim( (string) fkc_opt( 'fkc_license_server', FKC_DEFAULT_LICENSE_SERVER ), '/' ) );
	defined( 'FKC_API_AUTHORITY' ) || define( 'FKC_API_AUTHORITY', fkc_opt( 'fkc_api_authority', 'service' ) );

	/*
	 * Emplacement des données (bases SQLite des sociétés, clé de chiffrement,
	 * pièces justificatives).
	 *
	 * Par défaut wp-content/uploads/, qui est SERVI PAR LE SERVEUR WEB : la
	 * seule protection y est un .htaccess, inopérant sous nginx, LiteSpeed sans
	 * compatibilité htaccess ou Caddy. En production, définir dans wp-config.php :
	 *
	 *     define( 'FKC_DATA_DIR', '/home/compte/finakop-data/' );  // hors racine web
	 *
	 * fkc_data_dir_expose() vérifie ensuite que le dossier n'est pas joignable
	 * en HTTP et alerte l'administrateur dans le cas contraire.
	 */
	if ( ! defined( 'FKC_DATA_DIR' ) ) {
		$uploads = wp_upload_dir();
		define( 'FKC_DATA_DIR', trailingslashit( $uploads['basedir'] ) . 'finakop-erp-core-data/' );
	}
	if ( ! is_dir( FKC_DATA_DIR ) ) { wp_mkdir_p( FKC_DATA_DIR ); }
	fkc_protect_dir( FKC_DATA_DIR );

	if ( ! extension_loaded( 'pdo_sqlite' ) ) {
		fkc_fatal_html( 'Extension PHP manquante', 'FinaKop ERP Core nécessite <code>pdo_sqlite</code>. Activez-la dans la configuration PHP (cPanel/hPanel → Sélectionner une version PHP → Extensions → pdo_sqlite).' );
	}
	$app = FKC_APP_DIR . 'index.php';
	if ( ! file_exists( $app ) ) {
		fkc_fatal_html( 'Fichiers manquants', 'Le fichier <code>app/index.php</code> est introuvable. Réinstallez l\'extension.' );
	}
	if ( ob_get_level() ) { ob_end_clean(); }
	require $app;
	exit;
}

/* ── Invisibilité moteurs : robots.txt (sous-domaine dédié uniquement) ────── */
add_filter( 'robots_txt', 'fkc_robots_txt', 99, 2 );
function fkc_robots_txt( $output, $public ) {
	$srv = fkc_resolve_serving();
	if ( ! empty( $srv['serve'] ) && 'subdomain' === $srv['kind'] ) {
		return "User-agent: *\nDisallow: /\n";
	}
	return $output;
}
add_action( 'send_headers', 'fkc_noindex_header', 1 );
function fkc_noindex_header() {
	$srv = fkc_resolve_serving();
	if ( ! empty( $srv['serve'] ) && ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true );
	}
}

/* ── Page de réglages (Réglages → FinaKop ERP Core) ──────────────────────── */
add_action( 'admin_menu', 'fkc_admin_menu' );
function fkc_admin_menu() {
	add_options_page( 'FinaKop ERP Core', 'FinaKop ERP Core', 'manage_options', 'finakop-erp-core', 'fkc_settings_page' );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'fkc_action_links' );
function fkc_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=finakop-erp-core' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Réglages</a>' );
	return $links;
}
function fkc_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	if ( isset( $_POST['fkc_save'] ) && check_admin_referer( 'fkc_settings' ) ) {
		update_option( 'fkc_access_mode', in_array( $_POST['fkc_access_mode'] ?? '', array( 'auto', 'subdomain', 'path' ), true ) ? $_POST['fkc_access_mode'] : 'auto' );
		update_option( 'fkc_subdomain_prefix', sanitize_text_field( $_POST['fkc_subdomain_prefix'] ?? FKC_DEFAULT_SUBDOMAIN ) );
		update_option( 'fkc_path_slug', sanitize_title( $_POST['fkc_path_slug'] ?? FKC_DEFAULT_SLUG ) );
		update_option( 'fkc_license_server', esc_url_raw( trim( $_POST['fkc_license_server'] ?? '' ) ) );
		update_option( 'fkc_api_authority', in_array( $_POST['fkc_api_authority'] ?? '', array( 'service', 'local' ), true ) ? $_POST['fkc_api_authority'] : 'service' );
		update_option( 'fkc_purge_on_uninstall', ! empty( $_POST['fkc_purge_on_uninstall'] ) ? 1 : 0 );
		if ( ! empty( $_POST['fkc_retest_expose'] ) ) { fkc_data_dir_expose( true ); }
		if ( ! empty( $_POST['fkc_effacer_secret'] ) ) {
			$p = FKC_DATA_DIR . '.fkc-mot-de-passe-initial.txt';
			if ( is_file( $p ) ) { @unlink( $p ); }
		}
		echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>';
	}
	// Réémission d'un mot de passe : action distincte du formulaire de réglages,
	// avec son propre nonce.
	if ( isset( $_POST['fkc_reemettre'] ) && check_admin_referer( 'fkc_reemettre' ) ) {
		fkc_definir_data_dir();
		$login = sanitize_text_field( wp_unslash( $_POST['fkc_reemettre_login'] ?? '' ) );
		$mdp   = '' !== $login ? fkc_reemettre_mot_de_passe( $login ) : null;
		if ( $mdp ) {
			echo '<div class="notice notice-success"><p><strong>Mot de passe réémis pour « ' . esc_html( $login ) . ' ».</strong></p>'
				. '<p>Identifiant : <code>' . esc_html( $login ) . '</code><br>Mot de passe : <code style="font-size:15px">' . esc_html( $mdp ) . '</code></p>'
				. '<p>Notez-le maintenant : il ne sera plus affiché. Son changement est imposé à la première connexion.</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>Réémission impossible : compte introuvable ou base de données inaccessible.</p></div>';
		}
	}
	$mode = fkc_opt( 'fkc_access_mode', 'auto' );
	$prefix = fkc_opt( 'fkc_subdomain_prefix', FKC_DEFAULT_SUBDOMAIN );
	$slug = get_option( 'fkc_path_slug', FKC_DEFAULT_SLUG );
	$srv = fkc_opt( 'fkc_license_server', '' );
	$auth = fkc_opt( 'fkc_api_authority', 'service' );
	$home = home_url( '/' );
	$slugTrim = trim( (string) $slug, '/' );
	$pathUrl = ( '' === $slugTrim ) ? $home : rtrim( $home, '/' ) . '/' . $slugTrim . '/';
	$host = wp_parse_url( $home, PHP_URL_HOST );
	$subUrl = 'https://' . $prefix . '.' . preg_replace( '/^www\./', '', (string) $host ) . '/';
	?>
	<div class="wrap">
		<h1>FinaKop ERP Core</h1>
		<p>Choisissez comment l'application est servie sur ce site. Le mode <strong>Automatique</strong> répond aux deux (chemin et sous-domaine).</p>
		<form method="post">
			<?php wp_nonce_field( 'fkc_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Mode d'accès</th><td>
					<label><input type="radio" name="fkc_access_mode" value="auto" <?php checked( $mode, 'auto' ); ?>> Automatique (chemin + sous-domaine)</label><br>
					<label><input type="radio" name="fkc_access_mode" value="path" <?php checked( $mode, 'path' ); ?>> Chemin uniquement</label><br>
					<label><input type="radio" name="fkc_access_mode" value="subdomain" <?php checked( $mode, 'subdomain' ); ?>> Sous-domaine uniquement</label>
				</td></tr>
				<tr><th scope="row">Slug du chemin</th><td>
					<input type="text" name="fkc_path_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="(vide = racine du site)">
					<p class="description">Accès : <code><?php echo esc_html( $pathUrl ); ?></code> — fonctionne sur n'importe quel domaine, sans configuration DNS.<br>
					<strong>Laissez ce champ vide</strong> pour servir l'application directement à la racine (ex. <code><?php echo esc_html( rtrim( $home, '/' ) ); ?>/login</code>). Dans ce cas, cet hôte est <strong>entièrement dédié</strong> à l'ERP (l'accueil WordPress public n'est plus affiché) ; l'administration <code>/wp-admin</code> reste toujours accessible.</p>
				</td></tr>
				<tr><th scope="row">Préfixe du sous-domaine</th><td>
					<input type="text" name="fkc_subdomain_prefix" value="<?php echo esc_attr( $prefix ); ?>" class="regular-text">
					<p class="description">Accès : <code><?php echo esc_html( $subUrl ); ?></code> — nécessite un sous-domaine pointant vers ce site.</p>
				</td></tr>
				<tr><th scope="row">Serveur de licence</th><td>
					<input type="url" name="fkc_license_server" value="<?php echo esc_attr( $srv ); ?>" class="regular-text" placeholder="<?php echo esc_attr( FKC_DEFAULT_LICENSE_SERVER ); ?>">
					<p class="description">Service de validation KOPHI'S GROUP. Laissez vide pour utiliser le serveur par défaut.</p>
				</td></tr>
				<tr><th scope="row">Autorité des clés API</th><td>
					<label><input type="radio" name="fkc_api_authority" value="service" <?php checked( $auth, 'service' ); ?>> Autorisation par le service KOPHI'S GROUP (recommandé)</label><br>
					<label><input type="radio" name="fkc_api_authority" value="local" <?php checked( $auth, 'local' ); ?>> Locale (administrateur du cabinet)</label>
					<p class="description">En mode « service », chaque clé API doit être autorisée à distance avant de fonctionner.</p>
				</td></tr>
			</table>
			<h2>Sécurité et données</h2>
			<?php
			fkc_definir_data_dir();
			$expose  = fkc_data_dir_expose();
			$secret  = FKC_DATA_DIR . '.fkc-mot-de-passe-initial.txt';
			$aSecret = is_file( $secret );
			?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Dossier de données</th><td>
					<code><?php echo esc_html( FKC_DATA_DIR ); ?></code>
					<p class="description">
					<?php if ( null === $expose['expose'] ) : ?>
						<span style="color:#996800">⚠ Exposition non vérifiable</span> — le test HTTP n'a pas abouti (pare-feu sortant ou boucle locale bloquée). Vérifiez manuellement que ce dossier n'est pas téléchargeable.
					<?php elseif ( $expose['expose'] ) : ?>
						<strong style="color:#b32d2e">⛔ Dossier accessible depuis Internet.</strong> Vos bases comptables, vos pièces justificatives et la clé de chiffrement sont téléchargeables. Votre serveur ignore les <code>.htaccess</code>.<br>
						Correctif : ajoutez dans <code>wp-config.php</code><br>
						<code>define( 'FKC_DATA_DIR', '/chemin/hors/racine/web/finakop-data/' );</code><br>
						puis déplacez-y le contenu du dossier actuel.
					<?php else : ?>
						<span style="color:#008a20">✔ Non accessible en HTTP.</span>
					<?php endif; ?>
					</p>
					<p><label><input type="checkbox" name="fkc_retest_expose" value="1"> Refaire le test d'exposition à l'enregistrement</label></p>
				</td></tr>

				<tr><th scope="row">Chiffrement au repos</th><td>
					<?php if ( defined( 'FKC_ENCRYPTION_KEY' ) && '' !== (string) FKC_ENCRYPTION_KEY ) : ?>
						<span style="color:#008a20">✔ Clé fournie par <code>wp-config.php</code></span>
					<?php else : ?>
						<span style="color:#996800">⚠ Clé auto-générée dans le dossier de données</span>
						<p class="description">La clé étant stockée à côté des données qu'elle protège, le chiffrement n'offre pas de protection réelle en cas de lecture du système de fichiers. Définissez dans <code>wp-config.php</code> :<br>
						<code>define( 'FKC_ENCRYPTION_KEY', '<?php echo esc_html( wp_generate_password( 48, true, true ) ); ?>' );</code><br>
						<em>Conservez cette valeur : la perdre rend les secrets chiffrés illisibles.</em></p>
					<?php endif; ?>
				</td></tr>

				<?php if ( $aSecret ) : ?>
				<tr><th scope="row">Mot de passe d'installation</th><td>
					<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;max-width:520px;white-space:pre-wrap"><?php echo esc_html( (string) @file_get_contents( $secret ) ); ?></pre>
					<p class="description">Ce mot de passe est à usage unique : son changement est imposé à la première connexion. Effacez ce fichier une fois la connexion faite.</p>
					<p><label><input type="checkbox" name="fkc_effacer_secret" value="1"> Effacer ce fichier maintenant</label></p>
				</td></tr>
				<?php endif; ?>

				<tr><th scope="row">Désinstallation</th><td>
					<label><input type="checkbox" name="fkc_purge_on_uninstall" value="1" <?php checked( (bool) get_option( 'fkc_purge_on_uninstall', false ) ); ?>>
					Purger les données à la désinstallation</label>
					<p class="description"><strong>Décoché par défaut, et c'est volontaire.</strong> Coché, la suppression de l'extension efface définitivement les livres comptables de toutes les sociétés et les pièces justificatives — documents soumis à une obligation légale de conservation. Une archive ZIP est déposée dans <code>uploads/</code> avant l'effacement, mais elle ne remplace pas une véritable sauvegarde.</p>
					<?php $sauv = get_option( 'fkc_derniere_sauvegarde', '' ); ?>
					<?php if ( $sauv ) : ?><p class="description">Dernière archive de purge : <code><?php echo esc_html( $sauv ); ?></code></p><?php endif; ?>
				</td></tr>
			</table>
			<p class="submit"><button type="submit" name="fkc_save" class="button button-primary">Enregistrer</button></p>
		</form>
		<?php
		$compromis = fkc_comptes_compromis();
		if ( $compromis ) : ?>
		<hr>
		<h2 style="color:#b32d2e">⛔ Connexion bloquée — mot de passe par défaut publié</h2>
		<p>Les versions ≤ 1.220.0 créaient un compte avec un mot de passe documenté publiquement dans <code>readme.txt</code>.
		Les comptes ci-dessous le portent encore : leur connexion est <strong>refusée</strong> jusqu'à réémission.</p>
		<p>Le blocage plutôt que le simple avertissement est délibéré : quiconque connaît ce mot de passe pourrait, sinon,
		atteindre l'écran de changement et s'approprier le compte avant son propriétaire légitime.</p>
		<?php foreach ( $compromis as $c ) : ?>
			<form method="post" style="margin:10px 0;padding:12px;border:1px solid #dcdcde;background:#fff;max-width:620px">
				<?php wp_nonce_field( 'fkc_reemettre' ); ?>
				<input type="hidden" name="fkc_reemettre_login" value="<?php echo esc_attr( $c['login'] ); ?>">
				<strong><?php echo esc_html( $c['login'] ); ?></strong>
				<?php if ( ! empty( $c['nom_complet'] ) ) : ?><span class="description">— <?php echo esc_html( $c['nom_complet'] ); ?></span><?php endif; ?>
				<p class="submit" style="margin:8px 0 0">
					<button type="submit" name="fkc_reemettre" value="1" class="button button-primary">Réémettre un mot de passe à usage unique</button>
				</p>
			</form>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}

function fkc_fatal_html( $title, $detail ) {
	http_response_code( 500 );
	echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FinaKop ERP Core</title>'
		. '<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:system-ui,sans-serif;background:#0E1B1A;color:#EAF2F1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}'
		. '.b{background:#16302E;border:1px solid #E8A33D;border-radius:16px;padding:2.5rem 3rem;max-width:520px;text-align:center}'
		. 'h2{color:#E8A33D;margin:1rem 0 .6rem;font-size:1.3rem}p{color:#9DB3B0;font-size:.92rem;line-height:1.7}code{background:#0E1B1A;color:#E8A33D;padding:2px 7px;border-radius:5px}</style>'
		. '</head><body><div class="b"><div style="font-size:2.6rem">⚙️</div><h2>' . htmlspecialchars( $title, ENT_QUOTES ) . '</h2><p>' . $detail . '</p></div></body></html>';
	exit;
}

/* ── Compat PHP < 8 : str_starts_with ────────────────────────────────────── */
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) {
		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}
