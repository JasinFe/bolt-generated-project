<?php
/**
 * FinaKop Plateforme — configuration SYSTÈME.
 *
 * À copier en ~/finakop/config.php (HORS de public_html), droits 0600 :
 *     chmod 600 ~/finakop/config.php
 *
 * Remplace wp-config.php et les réglages WordPress. Les réglages propres à
 * chaque entreprise (packs, référentiel, préférences) restent dans SES bases.
 * Remplacez uXXXXXXXX par votre identifiant Hostinger (commande : echo $HOME).
 */
return array(

	/* ── Domaines ─────────────────────────────────────────────────────────── */
	'domaine_base' => 'finakoperp.com',          // <client>.finakoperp.com
	'hote_portail' => 'app.finakoperp.com',      // page « Accéder à votre espace »
	'site_public'  => 'https://www.finakoperp.com/',
	'mode_tenant'  => 'sous-domaine',            // sous-domaine | chemin | les_deux (voir docs/02-ARCHITECTURE.md §4)

	/* ── Données : OBLIGATOIRE, hors de public_html ───────────────────────── */
	'donnees' => '/home/uXXXXXXXX/finakop-data',

	'https'  => true,     // toute requête http est redirigée vers https
	'fuseau' => 'UTC',    // heure de PHP ET de SQLite ; gardez celle de l'ancien serveur (l'import vous la signale)
	'environnement' => 'production',   // production | staging | development (ou variable FINAKOP_ENV)
	'debug'  => false,    // ignoré en production : les erreurs vont au journal, jamais à l'écran
	'admin_email' => 'admin@finakoperp.com',

	/* ── Courriels (boîte créée dans hPanel → E-mails) ────────────────────── */
	'smtp' => array(
		'transport'      => 'smtp',               // smtp | mail (secours) | fichier (recette)
		'hote'           => 'smtp.hostinger.com',
		'port'           => 465,
		'securite'       => 'ssl',                // ssl (465) | tls (587, STARTTLS)
		'utilisateur'    => 'no-reply@finakoperp.com',
		'mot_de_passe'   => '',                   // ou variable d'environnement FINAKOP_SMTP_MOT_DE_PASSE
		'expediteur'     => 'no-reply@finakoperp.com',
		'nom_expediteur' => 'FinaKop',            // devient « <Entreprise> via FinaKop »
		'verifier_certificat' => true,
	),

	/* ── Cloudflare ───────────────────────────────────────────────────────── */
	'cloudflare' => array(
		'actif'          => true,                 // IP réelle depuis les plages Cloudflare officielles
		// Secret exigé sur chaque requête (règle de transformation Cloudflare, voir docs/05-CLOUDFLARE-DNS.md).
		// Générer : php -r 'echo bin2hex(random_bytes(24)),"\n";'
		// Laisser VIDE tant que la règle n'est pas créée chez Cloudflare, sinon tout est refusé.
		'secret_origine' => '',
		'entete_secret'  => 'X-FinaKop-Origine',
	),

	/* ── Licences ─────────────────────────────────────────────────────────── */
	'licence' => array(
		'serveur'        => 'https://license.kophisgroup.com/api',
		'depot'          => '',                   // dépôt de révocation signé (ex. https://license.finakoperp.com/depot)
		'domaine_strict' => true,                 // NE PAS désactiver : isole les licences entre clients
	),

	/* ── API ──────────────────────────────────────────────────────────────── */
	'api' => array(
		'autorite' => 'service',                  // service (KOPHI'S GROUP) | local
		'cors'     => '',                         // origines autorisées, séparées par des virgules
	),

	/* ── Temps réel ───────────────────────────────────────────────────────── */
	// Afficheur client et scanner : interrogation courte (false) sur hébergement
	// mutualisé ; flux SSE (true) sur VPS, où les processus ne sont pas rationnés.
	'temps_reel' => array( 'sse' => false ),

	/* ── Tâches planifiées ────────────────────────────────────────────────── */
	'cron' => array(
		'intervalle'   => 300,                    // doit correspondre à la fréquence du cron hPanel
		'budget'       => 240,                    // secondes max par passage
		'delai_tenant' => 180,                    // secondes max par client
	),

	/* ── Sauvegardes ──────────────────────────────────────────────────────── */
	'sauvegarde' => array(
		'heure'           => 2,                   // heure UTC à partir de laquelle la sauvegarde du jour part
		'retention_jours' => 14,
		// Clé de chiffrement : php ~/finakop/current/bin/finakop sauvegarde:cle
		// Conservez-la AUSSI hors du serveur : sans elle, aucune restauration.
		'cle'             => '',
	),
);
