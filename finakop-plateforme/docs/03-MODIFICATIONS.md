# Modifications : FinaKop ERP Core 1.875.5 → FinaKop Plateforme 1.876.0

Base : `finakop-erp-core-1_875_5.zip`. **Aucune fonction métier n'a été retirée ni réécrite** : les 1 637 routes, 63 packs et 30 modules sont servis par le même code. Les modifications d'`app/` sont ciblées : dépendances WordPress, sécurité, défauts trouvés en test. Le reste est une couche **ajoutée** (`app/Plateforme/`, scripts).

Le code source modifié et ajouté figure aussi, fichier par fichier, dans `finakop-plateforme/src/` du dépôt.

## 1. Fichiers existants modifiés

| Fichier | Modification | Raison |
| --- | --- | --- |
| `app/index.php` | Charge `Core/Mailer.php` ; nom de session `FKC_SESSION_NAME` ; arrêt avant l'API si `FKC_CLI_NOYAU` ; 7 inclusions de packs en `require_once` | Courriels sans WordPress ; sessions séparées par client ; tâches en ligne de commande ; **erreur fatale corrigée** (pack industrie/distribution) |
| `app/noyau.php` (1.875.6) | Charge le noyau hors requête HTTP ; création explicite d'un registre (`FKC_NOYAU_CREER`) | Cron, console, création d'un client |
| `app/Core/Courriel.php` | Utilise `FKC_Mailer` s'il est configuré, sinon `wp_mail` | **Seule dépendance WordPress bloquante** de l'audit |
| `app/Core/Crypto.php` (1.875.6) | Empreinte de la clé ; refus explicite si la clé manque ou diffère | Une clé oubliée au déménagement rendait les secrets illisibles, silencieusement |
| `app/Core/Router.php` | Échec CSRF → 403 (au lieu de 419) | 419 devenait une erreur 500 sous Apache/LiteSpeed |
| `app/Core/Security.php` | Chemin du cookie = `FKC_BASE_PATH` ; contrôle générique « données sous la racine web » | Mode chemin ; plus de supposition WordPress |
| `app/Core/BPEWorker.php`, `app/Core/Balayeur.php` | `FKC_CRON_EXTERNE` : planification par le cron système | Plus de WP-Cron |
| `app/Services/Connect/Push.php` | Liste blanche des services de notification, adresses publiques seulement, pas de redirection ; `FKC_ADMIN_EMAIL` | SSRF ; plus d'`get_option('admin_email')` |
| `app/Core/PlanAudit.php`, `app/Core/PlanPurge.php` | Classement du plan calculé une fois | Page « Purge du plan » : 28 s → 0,07 s, résultat identique |
| `app/Core/helpers.php` | `fkc_csv_cellule()` appliquée par `fkc_csv()` ; `fkc_sse_actif()` | **Faille corrigée** : injection de formule dans les exports CSV ; flux temps réel désactivables |
| `app/Services/Connect/Controllers/ConnectController.php` | Export du journal neutralisé | Même faille (injection CSV) |
| `app/Modules/Caisse/Controllers/PosController.php`, `app/Modules/Scan/Controllers/ScanController.php`, vues `afficheur.php` et `smart.php` | Flux SSE refusé (204) et repli sur l'interrogation quand `FKC_SSE` est faux | Un flux par écran ouvert occupait un processus PHP en continu : intenable sur un mutualisé |
| `Modules/Ged/Models/Document.php`, `Modules/Comptabilite/Models/PieceJointe.php`, `Modules/Societes/Models/Societe.php`, `Packs/cabinet/Models/Pieces.php` | Fichiers téléversés en 0640 (au lieu de 0644) | Illisibles des autres comptes d'un serveur mutualisé |
| `finakop-erp-core.php` (extension seulement) | Tâches WP-Cron/WP-CLI via `noyau.php` ; `wp finakop balayage` | Correctifs 1.875.6 pour les sites restés sous WordPress |
| `readme.txt`, `PATCH_NOTES.md`, en-tête de version | 1.876.0 | — |

## 2. Fichiers nouveaux

| Fichier | Rôle |
| --- | --- |
| `public/index.php` | Point d'entrée unique de tous les sous-domaines (chemin de la version inscrit au déploiement) |
| `public/.htaccess` | Règles LiteSpeed/Apache : seul `index.php` s'exécute, fichiers cachés refusés, statiques servis directement |
| `app/Plateforme/Config.php` | `FKC_Config` : configuration système hors web, variables `FINAKOP_*` |
| `app/Plateforme/Registre.php` | `plateforme.db` : clients, domaines, journal, état des tâches |
| `app/Plateforme/TenantResolver.php` | `FKC_TenantResolver` : hôte → client ; réservés, statuts, domaines personnalisés, mode chemin |
| `app/Plateforme/Cloudflare.php` | `FKC_Plateforme_Proxy` : IP réelle et HTTPS depuis Cloudflare ou un relais déclaré uniquement ; garde de l'origine |
| `app/Plateforme/Amorcage.php` | Démarrage sans WordPress ; contexte du client (constantes, sessions, clé) |
| `app/Plateforme/Pages.php` | Portail `app.` et pages neutres (404, 403, 503) |
| `app/Plateforme/Instantane.php` | Copie cohérente des bases (`VACUUM INTO`) et manifeste de contrôle |
| `app/Plateforme/Sauvegarde.php` | Sauvegardes chiffrées (libsodium), relues, rotation, restauration |
| `app/Plateforme/Console.php`, `bin/finakop` | Console d'administration (SSH) |
| `app/Plateforme/Cron.php`, `cron/worker.php`, `cron/worker.sh` | Tâche cron unique, un processus par client, verrous, délais |
| `app/Core/Mailer.php` | `FKC_Mailer` : SMTP SSL/STARTTLS, pièces jointes, texte + HTML |
| `config/config.exemple.php` | Modèle de `~/finakop/config.php` |
| `scripts/installer.sh`, `scripts/deployer.sh` | Installation, mise à jour, retour arrière, sans droits root |
| `scripts/migration-donnees.php` | Export contrôlé depuis l'ancien site, contrôle à l'arrivée |
| `scripts/diagnostic-hebergement.php` | Diagnostic de l'hébergement avant bascule |
| `tests/cle_chiffrement_noyau_1875_6.php`, `tests/plateforme_chargement_packs_1876.php`, `tests/export_csv_injection_1876.php`, `tests/plateforme_temps_reel_1876.php`, `tests/televersements_droits_1876.php` | Tests de non-régression (échouent sur 1.875.5) |
| `VERSION` | Numéro de version lu par la plateforme |

## 3. Éléments retirés du paquet autonome

| Élément | Pourquoi |
| --- | --- |
| `finakop-erp-core.php`, `uninstall.php`, `readme.txt` | Propres à l'extension WordPress, remplacés par `public/index.php` et la console |
| `tests/` | Outils de développement, inutiles en production |
| Appels WordPress (`get_option`, `wp_mail`, WP-Cron…) | **Non supprimés du code** : ils restent derrière `function_exists()` pour que le même `app/` serve aussi l'extension. Hors WordPress, les alternatives natives prennent le relais. |

Le paquet `finakop-erp-core-1.876.0.zip` reste une **extension WordPress** complète (mêmes correctifs), pour l'ancien site pendant la transition.
