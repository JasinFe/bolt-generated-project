<?php
/**
 * FKC_AidePlateforme — centre d'aide à jour (1.876.4).
 *
 * Le contenu historique de FKC_Aide a été écrit pour l'extension WordPress
 * (mot de passe « admin » par défaut, wp-config.php, dossier wp-content…).
 * Sur la plateforme FinaKop, ces passages sont faux. Cette classe :
 *   • ajoute les articles propres à la plateforme (connexion, double
 *     authentification, confidentialité, support) ;
 *   • remplace, sur la plateforme seulement, les textes devenus inexacts ;
 *   • fournit les vidéos d'aide, les nouveautés et les contacts du support.
 *
 * Hors plateforme (extension WordPress), rien n'est remplacé.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_AidePlateforme {

	const SUPPORT_TEL  = '+225 05 03 40 43 89';
	const SUPPORT_WA   = '2250503404389';
	const SUPPORT_MAIL = 'supports@finakoperp.com';

	public static function plateforme() { return defined( 'FKC_PLATEFORME' ); }

	/* ═══════════════ VIDÉOS ═══════════════ */

	/** Vidéos d'aide (écrans réels, données d'un espace de démonstration). */
	public static function videos() {
		return array(
			array( 'id' => 'prise-en-main', 'icon' => '🚀', 'titre' => 'Prise en main de FinaKop', 'duree' => '1 min 10',
				'resume' => "Se connecter, choisir son mot de passe, se repérer dans le menu, la recherche, le bouton « + Action » et le centre d'aide.",
				'fichier' => 'video/aide-prise-en-main.mp4', 'affiche' => 'video/aide-prise-en-main.jpg',
				'chapitres' => array( array( 0, 'Introduction' ), array( 5, 'Accéder à votre espace' ), array( 12, 'Se connecter' ), array( 19, 'Votre mot de passe' ), array( 33, "Se repérer dans l'écran" ), array( 52, "Trouver de l'aide" ) ) ),
			array( 'id' => 'double-authentification', 'icon' => '🔐', 'titre' => 'Sécuriser votre compte : la double authentification', 'duree' => '1 min',
				'resume' => "Installer l'application, scanner le QR code, conserver les codes de secours, se connecter avec le code du téléphone.",
				'fichier' => 'video/aide-double-authentification.mp4', 'affiche' => 'video/aide-double-authentification.jpg',
				'chapitres' => array( array( 0, 'Pourquoi' ), array( 5, "Installer l'application" ), array( 12, 'Scanner le QR code' ), array( 27, 'Codes de secours' ), array( 35, 'Se connecter' ), array( 45, 'En cas de problème' ) ) ),
			array( 'id' => 'facturer-encaisser', 'icon' => '🧾', 'titre' => 'Facturer et encaisser', 'duree' => '1 min',
				'resume' => "Créer un client, établir une facture, enregistrer un encaissement (espèces, Mobile Money…) et suivre les impayés.",
				'fichier' => 'video/aide-facturer-encaisser.mp4', 'affiche' => 'video/aide-facturer-encaisser.jpg',
				'chapitres' => array( array( 0, 'Introduction' ), array( 5, 'Le module Facturation' ), array( 12, 'Créer un client' ), array( 19, 'Établir une facture' ), array( 31, 'Encaisser' ), array( 45, 'Suivre les impayés' ) ) ),
			array( 'id' => 'comptabilite-caisse-equipe', 'icon' => '📒', 'titre' => 'Comptabilité, caisse, stock et équipe', 'duree' => '1 min',
				'resume' => "Saisir une écriture, ouvrir la caisse, suivre le stock et créer les comptes de vos collaborateurs.",
				'fichier' => 'video/aide-comptabilite-caisse-equipe.mp4', 'affiche' => 'video/aide-comptabilite-caisse-equipe.jpg',
				'chapitres' => array( array( 0, 'Introduction' ), array( 5, 'Comptabilité' ), array( 19, 'Caisse' ), array( 27, 'Stock' ), array( 35, 'Votre équipe' ) ) ),
		);
	}

	/** Nouveautés récentes, affichées en tête du centre d'aide. */
	public static function nouveautes() {
		return array(
			array( 'icon' => '🔐', 'titre' => 'Double authentification', 'texte' => "Code de l'application en plus du mot de passe. Obligatoire pour les administrateurs.", 'aide' => 'double-authentification' ),
			array( 'icon' => '🎬', 'titre' => "Vidéos d'aide", 'texte' => "Prise en main, sécurité, facturation, comptabilité : en une minute chacune.", 'route' => 'aide/videos' ),
			array( 'icon' => '🧭', 'titre' => 'Nouveau portail', 'texte' => "Une adresse par entreprise, un portail d'accès redessiné, plus clair.", 'aide' => 'acceder-espace' ),
			array( 'icon' => '🕶️', 'titre' => 'Espace privé', 'texte' => "Votre espace est invisible des moteurs de recherche et des robots d'IA.", 'aide' => 'confidentialite' ),
		);
	}

	public static function support() {
		return array( 'tel' => self::SUPPORT_TEL, 'wa' => self::SUPPORT_WA, 'mail' => self::SUPPORT_MAIL );
	}

	/* ═══════════════ ARTICLES ═══════════════ */

	/** Articles propres à la plateforme, puis remplacement des textes inexacts. */
	public static function adapterArticles( array $articles ) {
		if ( ! self::plateforme() ) { return $articles; }
		$remp = self::remplacements();
		foreach ( $articles as &$a ) {
			if ( isset( $remp[ $a['id'] ] ) ) { $a = array_merge( $a, $remp[ $a['id'] ] ); }
		}
		unset( $a );
		return array_merge( self::nouveauxArticles(), $articles );
	}

	protected static function nouveauxArticles() {
		$s = self::support();
		return array(

		array( 'id' => 'acceder-espace', 'cat' => 'navigation', 'niveau' => 'essentiel',
			'titre' => "Se connecter à votre espace FinaKop",
			'tags' => 'connexion adresse espace portail sous-domaine identifiant favori première connexion mot de passe oublié',
			'corps' =>
			"<p>Chaque entreprise a <strong>sa propre adresse</strong>, de la forme <code>votre-entreprise.finakoperp.com</code>. Elle vous est communiquée à l'ouverture de votre espace.</p>"
			. "<ol><li>Ouvrez l'adresse de votre espace (ou le portail <code>app.finakoperp.com</code>, puis saisissez l'identifiant de votre entreprise).</li>"
			. "<li>Saisissez votre <strong>identifiant</strong> et votre <strong>mot de passe</strong>.</li>"
			. "<li>Si la double authentification est active, tapez le code affiché par votre application.</li></ol>"
			. "<p><strong>Première connexion :</strong> le mot de passe reçu est provisoire et ne sert qu'une fois. FinaKop vous demande aussitôt d'en choisir un : 12 caractères au moins, 3 catégories sur 4 (minuscules, majuscules, chiffres, caractères spéciaux), sans votre identifiant ni mot courant.</p>"
			. "<p><strong>Astuce :</strong> ajoutez l'adresse de votre espace à vos favoris. Sur téléphone : menu du navigateur → <em>Ajouter à l'écran d'accueil</em>.</p>"
			. "<p><strong>Mot de passe oublié ?</strong> Demandez à votre administrateur : il vous en réémet un provisoire depuis l'écran <em>Utilisateurs</em>.</p>" ),

		array( 'id' => 'double-authentification', 'cat' => 'securite', 'niveau' => 'essentiel',
			'titre' => "Activer la double authentification (application)",
			'tags' => '2fa double authentification code application google authenticator microsoft authy qr code secours téléphone perdu',
			'corps' =>
			"<p>Avec la double authentification, un mot de passe volé ne suffit plus : il faut aussi le <strong>code à 6 chiffres</strong> affiché par une application sur votre téléphone. Ce code change toutes les 30 secondes et fonctionne même sans réseau. Elle est <strong>obligatoire pour les administrateurs</strong>, recommandée pour tous.</p>"
			. "<h3>L'activer (2 minutes)</h3>"
			. "<ol><li>Installez une application d'authentification : <em>Google Authenticator</em>, <em>Microsoft Authenticator</em>, <em>Authy</em>, <em>2FAS</em> ou <em>Aegis</em>.</li>"
			. "<li>Dans FinaKop : <em>Mot de passe → Double authentification</em> (les administrateurs y sont conduits d'office).</li>"
			. "<li>Dans l'application : <em>Ajouter</em> → <em>Scanner un QR code</em>, et visez le QR code. Sans appareil photo, saisissez la clé affichée dessous.</li>"
			. "<li>Tapez le code à 6 chiffres affiché, puis <em>Activer avec l'application</em>.</li>"
			. "<li><strong>Notez les 10 codes de secours</strong> qui s'affichent : ils ne seront plus jamais montrés. Imprimez-les ou rangez-les dans un coffre de mots de passe.</li></ol>"
			. "<h3>Se connecter ensuite</h3><p>Identifiant et mot de passe, puis le code affiché par l'application. 5 essais au plus.</p>"
			. "<h3>En cas de problème</h3><ul>"
			. "<li><strong>Code refusé</strong> : réglez l'heure du téléphone en automatique (le code dépend de l'heure, à 30 secondes près).</li>"
			. "<li><strong>Téléphone oublié ou perdu</strong> : tapez un code de secours à la place du code (chacun ne sert qu'une fois).</li>"
			. "<li><strong>Nouveau téléphone</strong> : désactivez puis réactivez la double authentification pour scanner un nouveau QR code. Administrateur : demandez au support de lever la protection, puis reconfigurez-la à la connexion suivante.</li>"
			. "<li><strong>Téléphone et codes de secours perdus</strong> : contactez le support (" . e( $s['tel'] ) . " ou " . e( $s['mail'] ) . ").</li></ul>"
			. "<p><strong>Astuce :</strong> enregistrez le même QR code sur deux téléphones (le vôtre et un téléphone de secours gardé au bureau).</p>" ),

		array( 'id' => 'confidentialite', 'cat' => 'securite',
			'titre' => "Votre espace est privé : ce que FinaKop protège",
			'tags' => 'confidentialité vie privée isolé chiffré sauvegarde moteurs recherche robots ia invisible',
			'corps' =>
			"<ul><li><strong>Isolé</strong> : les données de votre entreprise vivent dans des bases séparées de celles des autres entreprises.</li>"
			. "<li><strong>Chiffré</strong> : connexions HTTPS de bout en bout, informations sensibles chiffrées.</li>"
			. "<li><strong>Sauvegardé</strong> : sauvegardes chiffrées chaque nuit, contrôlées automatiquement.</li>"
			. "<li><strong>Invisible</strong> : votre espace n'est indexé par aucun moteur de recherche, et les robots (dont ceux des IA) sont refusés.</li>"
			. "<li><strong>Tracé</strong> : les actions sensibles sont inscrites au journal d'audit.</li></ul>"
			. "<p><strong>Votre part :</strong> ne communiquez jamais votre mot de passe ni vos codes (le support ne vous les demandera jamais), ne partagez pas l'adresse de votre espace publiquement, et déconnectez-vous sur un poste partagé.</p>" ),

		array( 'id' => 'support', 'cat' => 'demarrage', 'niveau' => 'essentiel',
			'titre' => "Contacter le support FinaKop",
			'tags' => 'support aide contact whatsapp téléphone email assistance problème',
			'corps' =>
			"<p>Notre équipe vous répond par :</p><ul>"
			. "<li><strong>WhatsApp</strong> et <strong>téléphone</strong> : " . e( $s['tel'] ) . "</li>"
			. "<li><strong>E-mail</strong> : " . e( $s['mail'] ) . "</li></ul>"
			. "<p>Pour aller vite, indiquez : le nom de votre entreprise, l'écran concerné, ce que vous avez fait et le message affiché. Une capture d'écran aide beaucoup.</p>"
			. "<p><strong>Ne communiquez jamais</strong> votre mot de passe, vos codes de double authentification ni vos codes de secours : personne chez FinaKop n'en a besoin.</p>" ),
		);
	}

	/** Textes historiques (extension WordPress) remplacés sur la plateforme. */
	protected static function remplacements() {
		return array(
			'changer-mot-de-passe' => array(
				'titre' => "⚠️ Votre mot de passe : à choisir dès la première connexion",
				'corps' =>
				"<p>Le mot de passe reçu à l'ouverture de votre compte est <strong>provisoire</strong> : il ne sert qu'une fois. À la première connexion, FinaKop vous demande d'en choisir un, et aucune autre page n'est accessible avant.</p>"
				. "<p><strong>Règles :</strong> 12 caractères au moins ; 3 catégories sur 4 (minuscules, majuscules, chiffres, caractères spéciaux) ; ni votre identifiant, ni « finakop », « azerty », « 123456 »…</p>"
				. "<p>Pour le changer ensuite : menu <em>Mot de passe</em>. Ensuite, activez la <a href=\"" . e( url( 'aide/article/double-authentification' ) ) . "\">double authentification</a>.</p>"
				. "<p>Créez un compte nominatif pour chaque personne : un compte partagé rend le journal d'audit inutile.</p>" ),
			'licence-installer' => array(
				'corps' =>
				"<p><strong>Sur la plateforme FinaKop, la licence est obligatoire</strong> : sans licence active (absente, expirée, révoquée, ou émise pour une autre adresse), seul l'écran <em>Licence</em> est accessible. Dès qu'une licence valide est installée, tout s'ouvre.</p>"
				. "<p>Seul un <strong>administrateur</strong> peut installer ou remplacer la licence : écran <em>Licence</em>, collez la clé fournie, validez. La signature est vérifiée localement.</p>"
				. "<p>La licence est liée à l'<strong>adresse de votre espace</strong> (<code>votre-entreprise.finakoperp.com</code>). Une clé émise pour une autre adresse est refusée.</p>"
				. "<p><strong>Une clé ne se modifie pas : elle se remplace.</strong> Ajouter un module ou un pack métier produit une nouvelle clé, émise en conservant votre date d'expiration.</p>" ),
			'sauvegarde' => array(
				'corps' =>
				"<p>Sur la plateforme FinaKop, <strong>vos données sont sauvegardées automatiquement chaque nuit</strong>, chiffrées, puis relues et contrôlées. Une sauvegarde est aussi faite avant chaque mise à jour de la plateforme.</p>"
				. "<p>Besoin de revenir à un état antérieur (erreur de manipulation, suppression) ? Contactez le support en indiquant la date souhaitée : la restauration est d'abord faite à part, pour vérification, avant de remplacer quoi que ce soit.</p>"
				. "<p>Les <strong>purges et réinitialisations restent irréversibles</strong> dans l'application : réfléchissez avant de lancer une opération de maintenance, et exportez au besoin vos états (balance, grand livre) au préalable.</p>" ),
			'bonnes-pratiques-securite' => array(
				'corps' =>
				"<p>Par ordre d'importance réelle :</p>"
				. "<ol><li><strong>Un compte par personne</strong>, avec un mot de passe propre (jamais partagé, jamais réutilisé ailleurs).</li>"
				. "<li><strong>La double authentification</strong> par application, au moins pour les administrateurs et la comptabilité.</li>"
				. "<li><strong>Ne communiquer aucun code</strong> : ni mot de passe, ni code de l'application, ni code de secours. Le support ne les demande jamais.</li>"
				. "<li><strong>Se déconnecter</strong> sur un poste partagé.</li>"
				. "<li><strong>Limiter le rôle administrateur</strong> : il donne accès aux purges irréversibles et à la gestion des comptes.</li></ol>"
				. "<p>HTTPS, chiffrement, isolement et sauvegardes sont assurés par la plateforme : vous n'avez rien à configurer.</p>" ),
			'chiffrement' => array(
				'tags' => 'chiffrement clé secret coffre sécurité repos',
				'corps' =>
				"<p>Les données sensibles (secrets de connexion, clés d'API de vos services, codes de double authentification) sont <strong>chiffrées au repos</strong> avec une clé propre à votre espace. Cette clé est gérée par la plateforme et conservée hors de vos données.</p>"
				. "<p>Les connexions sont chiffrées de bout en bout (HTTPS), et les sauvegardes sont elles aussi chiffrées.</p>" ),
		);
	}

	/* ═══════════════ PARCOURS, DÉPANNAGE, GLOSSAIRE ═══════════════ */

	public static function adapterParcours( array $parcours ) {
		if ( ! self::plateforme() ) { return $parcours; }
		foreach ( $parcours as &$p ) {
			if ( 'p1' === $p['id'] ) {
				$p['intro'] = "Deux minutes qui protègent tout le reste.";
				$p['duree'] = '10 minutes';
				$p['etapes'] = array(
					array( 'titre' => "Regarder la vidéo de prise en main", 'detail' => "Une minute pour se repérer : menu, recherche, « + Action », aide.", 'route' => 'aide/videos', 'aide' => 'acceder-espace' ),
					array( 'titre' => "Choisir votre mot de passe", 'detail' => "Le mot de passe reçu est provisoire : FinaKop vous fait choisir le vôtre à la première connexion.", 'route' => 'mot-de-passe', 'aide' => 'changer-mot-de-passe' ),
					array( 'titre' => "Activer la double authentification", 'detail' => "Obligatoire pour les administrateurs : application sur le téléphone + 10 codes de secours.", 'route' => 'securite/deux-facteurs', 'aide' => 'double-authentification' ),
					array( 'titre' => "Créer les comptes nominatifs", 'detail' => "Un compte par personne, avec les seuls modules dont elle a besoin.", 'route' => 'utilisateurs', 'aide' => 'utilisateurs' ),
				);
			}
			foreach ( $p['etapes'] as &$s ) {
				if ( 'licence' === $s['route'] ) { $s['detail'] = "Obligatoire : sans licence active, seul l'écran Licence est accessible. Réservé à l'administrateur."; }
			}
			unset( $s );
		}
		unset( $p );
		return $parcours;
	}

	public static function adapterDepannage( array $lignes ) {
		if ( ! self::plateforme() ) { return $lignes; }
		$s = self::support();
		foreach ( $lignes as &$l ) {
			if ( "Je ne peux pas me connecter" === $l['q'] ) {
				$l['r'] = "Vérifiez l'adresse de votre espace et votre identifiant. Après plusieurs échecs, l'accès est bloqué quelques minutes : patientez. Mot de passe perdu : votre administrateur vous en réémet un provisoire depuis l'écran Utilisateurs. Plus aucun administrateur disponible : contactez le support (" . $s['tel'] . ").";
			}
		}
		unset( $l );
		return array_merge( array(
			array( 'grp' => 'Connexion & sécurité', 'q' => "Je ne reçois pas le code de double authentification par e-mail",
				'r' => "La double authentification se fait désormais avec une application sur le téléphone (Google Authenticator, Microsoft Authenticator, Authy…), plus sûre et indépendante de la messagerie. Si votre compte est encore réglé sur l'e-mail : saisissez un code de secours, puis FinaKop vous propose de passer à l'application. Sans code de secours : demandez à votre administrateur ou au support." ),
			array( 'grp' => 'Connexion & sécurité', 'q' => "Le code de l'application est refusé",
				'r' => "Le code dépend de l'heure : réglez l'heure et le fuseau du téléphone en automatique. Tapez le code affiché pour FinaKop (pas celui d'un autre service), avant qu'il ne change. Un code déjà utilisé ne peut pas resservir : attendez le suivant." ),
			array( 'grp' => 'Connexion & sécurité', 'q' => "J'ai perdu ou changé de téléphone",
				'r' => "Connectez-vous avec un de vos codes de secours (format XXXX-XXXX), puis reconfigurez la double authentification sur le nouveau téléphone. Codes de secours perdus aussi : contactez le support (" . $s['tel'] . ")." ),
			array( 'grp' => 'Connexion & sécurité', 'q' => "« Espace introuvable » s'affiche",
				'r' => "L'adresse saisie ne correspond à aucun espace. Vérifiez l'orthographe de l'adresse reçue (votre-entreprise.finakoperp.com), ou passez par le portail app.finakoperp.com." ),
			array( 'grp' => 'Connexion & sécurité', 'q' => "Seul l'écran Licence est accessible",
				'r' => "La licence de l'espace est absente, expirée ou émise pour une autre adresse. Un administrateur installe la clé fournie dans l'écran Licence ; tout s'ouvre aussitôt. Pas de clé : contactez le support." ),
		), $lignes );
	}

	public static function adapterGlossaire( array $termes ) {
		if ( ! self::plateforme() ) { return $termes; }
		return array_merge( $termes, array(
			array( 'terme' => 'Double authentification (2FA)', 'grp' => 'Sécurité', 'def' => "Protection qui exige, en plus du mot de passe, un code à 6 chiffres affiché par une application sur votre téléphone." ),
			array( 'terme' => "Application d'authentification", 'grp' => 'Sécurité', 'def' => "Application de téléphone (Google Authenticator, Microsoft Authenticator, Authy…) qui affiche un code renouvelé toutes les 30 secondes." ),
			array( 'terme' => 'Code de secours', 'grp' => 'Sécurité', 'def' => "Code à usage unique (XXXX-XXXX) qui remplace le code de l'application si le téléphone est indisponible. Dix sont remis à l'activation." ),
			array( 'terme' => 'Espace', 'grp' => 'Sécurité', 'def' => "L'environnement privé de votre entreprise, à son adresse propre (votre-entreprise.finakoperp.com), isolé de ceux des autres entreprises." ),
			array( 'terme' => 'Portail', 'grp' => 'Sécurité', 'def' => "La page app.finakoperp.com, qui conduit à votre espace à partir de l'identifiant de votre entreprise." ),
		) );
	}
}
