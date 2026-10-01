# FinaKop Plateforme 1.876.0

FinaKop ERP en **application PHP autonome**, sans WordPress, multi-entreprises par sous-domaine :

```text
https://newloock.finakoperp.com      → espace de New Loock
https://entreprise2.finakoperp.com   → espace d'Entreprise 2
https://app.finakoperp.com           → portail « Accéder à votre espace »
```

Cible : **Hostinger Business** (PHP 8.4, sans root) derrière **Cloudflare Free**. Le même paquet fonctionne sur un VPS.

## Contenu de l'archive

```text
finakop-plateforme/
├── app/            FinaKop (modules, packs, noyau) + app/Plateforme/ (couche autonome)
├── bin/finakop     console d'administration (SSH)
├── cron/           tâche planifiée unique (worker.php ; worker.sh en secours)
├── config/         config.exemple.php (modèle de ~/finakop/config.php)
├── public/         index.php et .htaccess (seuls fichiers exposés au web, avec les statiques)
├── scripts/        installer.sh, deployer.sh, migration-donnees.php, diagnostic-hebergement.php
├── assets/         ressources graphiques
└── docs/           documentation (ci-dessous)
```

## Documentation

| Document | Pour |
| --- | --- |
| [docs/04-INSTALLATION-HOSTINGER.md](docs/04-INSTALLATION-HOSTINGER.md) | **Installer** (pas à pas), créer un client, émettre sa licence, reprendre l'ancien site WordPress |
| [docs/05-CLOUDFLARE-DNS.md](docs/05-CLOUDFLARE-DNS.md) | DNS, SSL Full (strict), protection de l'origine, cache, WAF : réglages exacts de l'offre Free |
| [docs/06-EXPLOITATION.md](docs/06-EXPLOITATION.md) | Clients, sauvegardes, restauration, journaux, passage sur VPS |
| [docs/01-AUDIT.md](docs/01-AUDIT.md) | Audit de l'existant et risques |
| [docs/02-ARCHITECTURE.md](docs/02-ARCHITECTURE.md) | Architecture cible, décisions comparées, réponses aux questions |
| [docs/03-MODIFICATIONS.md](docs/03-MODIFICATIONS.md) | Fichiers modifiés, créés, retirés |
| [docs/07-TESTS.md](docs/07-TESTS.md) | Résultats des tests (plateforme et non-régression) |
| [docs/08-RAPPORT-FINAL.md](docs/08-RAPPORT-FINAL.md) | Rapport de migration et problèmes restants |

## Installation en bref

```bash
# sur le serveur, en SSH, archive déposée dans le dossier personnel
unzip -q finakop-plateforme-1.876.0.zip -d ~/finakop-installation
bash ~/finakop-installation/finakop-plateforme/scripts/installer.sh ~/finakop-plateforme-1.876.0.zip \
     --web=$HOME/domains/finakoperp.com/public_html/finakop-app
nano ~/finakop/config.php                                       # SMTP, admin_email, Cloudflare
php ~/finakop/current/bin/finakop plateforme:verifier
php ~/finakop/current/bin/finakop tenant:creer newloock "New Loock"
```

Ensuite :
- hPanel : sous-domaines `app` et `newloock` vers `public_html/finakop-app`, SSL gratuit pour chacun ;
- tâche cron toutes les 5 minutes : `php ~/finakop/current/cron/worker.php` ;
- Cloudflare : voir le doc 05.

## Sécurité : à ne jamais faire

- Ne déposez **jamais** les clés privées de l'outil LICENCES (`license_private.pem`, `license_depot_private.pem`) sur le serveur. Elles restent sur votre poste.
- Ne placez **jamais** `~/finakop-data` ou `config.php` sous `public_html`. L'installateur le refuse.
- Conservez la **clé de sauvegarde** hors du serveur : sans elle, aucune sauvegarde ne se restaure.
