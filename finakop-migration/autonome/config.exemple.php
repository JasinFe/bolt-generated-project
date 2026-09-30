<?php
/**
 * FinaKop — configuration de l'installation AUTONOME.
 *
 * À copier en /etc/finakop/config.php
 *   propriétaire root:finakop, droits 0640 (lisible par le pool PHP, par personne d'autre).
 *
 * Ce fichier remplace wp-config.php ET la page « Réglages → FinaKop ERP Core ».
 */
return array(

	/* Code : lien symbolique vers la version déployée (voir scripts/deployer-version.sh). */
	'app_dir'   => '/srv/finakop/current/app/',

	/* Données : bases SQLite, pièces, logos, clé. HORS racine web. Doit exister. */
	'data_dir'  => '/var/lib/finakop/data/',

	/* Cache du chargeur CLI (bin/finakop). */
	'cache_dir' => '/var/cache/finakop/',

	/*
	 * Adresse publique. GARDEZ LE MÊME HÔTE qu'aujourd'hui : la licence est liée
	 * au domaine inscrit dans le jeton signé. Même hôte = aucune réémission.
	 */
	'base_url'   => 'https://finakopcore.kophisgroup.com/',

	/* Fichiers statiques de app/assets, servis directement par nginx. */
	'assets_url' => '/_fkc',

	/*
	 * ⚠ CLÉ DE CHIFFREMENT — LE POINT LE PLUS CRITIQUE DE LA MIGRATION.
	 *
	 * • Si wp-config.php contenait  define( 'FKC_ENCRYPTION_KEY', '...' );
	 *   recopiez ICI la valeur, AU CARACTÈRE PRÈS.
	 * • Sinon laissez vide : FinaKop utilise le fichier .fkc-secret.key du
	 *   dossier de données, qui DOIT avoir été copié avec les bases.
	 *
	 * Une valeur différente, ou un fichier oublié, et FinaKop génère une clé
	 * neuve SANS AVERTIR : les secrets chiffrés deviennent illisibles.
	 */
	'encryption_key' => '',

	/* Anciennes options WordPress (fkc_license_server, fkc_api_authority). */
	'license_server' => 'https://license.kophisgroup.com/api',
	'api_authority'  => 'service',   // service | local

	/* Courriels (wp_mail est remplacé par mail() → msmtp → SMTP). */
	'mail_from'   => 'FinaKop <no-reply@kophisgroup.com>',
	'admin_email' => 'support@kophisgroup.com',

	/* Constantes optionnelles reconnues par FinaKop (celles de wp-config.php). */
	'constantes' => array(
		// 'FKC_LICENSE_DEPOT'         => 'https://licences.kophisgroup.com/depot',
		// 'FKC_LICENSE_STRICT_DOMAIN' => true,
		// 'FKC_DEBUG'                 => false,
	),
);
