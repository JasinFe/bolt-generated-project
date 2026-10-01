# Rapport final : migration de FinaKop ERP vers une plateforme PHP autonome

Version livrée : **FinaKop Plateforme 1.876.0** (base : FinaKop ERP Core 1.875.5). Date : 01/10/2026.

## A. Audit initial (résumé ; détail : 01-AUDIT.md)

| Domaine | Constat |
| --- | --- |
| Architecture | Application déjà autonome à 95 % : routeur maison (1 637 routes), authentification, RBAC, CSRF sur tout POST, licences RSA, 30 modules, 63 packs. WordPress ne faisait qu'aiguiller la requête, stocker 6 réglages, déclencher les tâches et envoyer les courriels |
| WordPress | **Une seule dépendance bloquante** : `wp_mail`. Les autres (`get_option`, `wp_remote_*`, WP-Cron, `wp_upload_dir`…) avaient déjà un repli natif |
| Base de données | SQLite en mode WAL : un registre par installation et **une base par société**. SQL propre à SQLite, migrations intégrées |
| Multi-entreprises | Conçu pour **un** cabinet : administrateur voyant toutes les sociétés, licence commune. Inadapté tel quel à des clients étrangers les uns aux autres |
| Sécurité | Solide : `password_hash`, régénération de session, CSRF, RBAC côté serveur, transactions (`FKC_Tx`, 155 emplois), en-têtes CSP et HSTS. Défauts trouvés : section M et R14 à R19 de l'audit |

## B. Architecture finale

```text
                 Internet
                    │
          ┌─────────▼──────────┐
          │  Cloudflare Free   │  DNS (*.finakoperp.com), Universal SSL, DDoS,
          │                    │  Free Managed Ruleset, 1 limite de débit,
          │                    │  en-tête secret vers l'origine, cache des seuls statiques
          └─────────┬──────────┘
                    │ HTTPS (Full strict)
          ┌─────────▼──────────┐
          │ Hostinger Business │  LiteSpeed, PHP 8.4, sans root
          │ public_html/       │
          │   finakop-app/     │  index.php + .htaccess + _fkc/ (statiques) : rien d'autre
          └─────────┬──────────┘
                    │
   ~/finakop/current/app/Plateforme      (hors web)
     Config ─ Proxy (IP réelle, garde d'origine) ─ TenantResolver ─ Contexte
                    │
     newloock.finakoperp.com → client « newloock » (statut actif ?)
                    │
   app/index.php : FinaKop inchangé
     Router → Auth → changement de mot de passe imposé → société (accès revérifié)
            → module (licence) → RBAC → CSRF → contrôleur
                    │
   ~/finakop-data/tenants/newloock/      (hors web, 0750)
     finakopcore-master.db   utilisateurs, sociétés, accès, licence, clés d'API
     <société>.db            une base par société
     ged/ pieces/ logos/     documents (0640)
     .fkc-secret.key         clé de chiffrement du client (0600)
```

**Décision structurante** : un client = une **instance FinaKop isolée** (option C, comparée aux options A et B dans 02-ARCHITECTURE.md §7.1). Le code est commun ; les données, utilisateurs, licence, clé de chiffrement et sessions sont propres à chaque client.

## C. Fichiers modifiés, créés, retirés

Voir **03-MODIFICATIONS.md**, tableau complet. En résumé :
- **23 fichiers existants modifiés**, dont 20 dans `app/` (dépendances WordPress, sécurité, défauts) et 3 pour l'extension et les notes de version ;
- **28 fichiers créés** : `app/Plateforme/` (10 classes), `app/noyau.php`, `FKC_Mailer`, console, cron (2), point d'entrée (2), configuration modèle, scripts (4), `VERSION`, 5 harnais de test ;
- **retirés du paquet autonome** : `finakop-erp-core.php`, `uninstall.php`, `readme.txt`, `tests/`. Aucun code métier supprimé.

## D. Dépendances WordPress supprimées

| Dépendance | Remplacement dans la plateforme |
| --- | --- |
| `fkc_intercept_request` (crochet `plugins_loaded`), `wp-load.php` | `public/index.php` → `FKC_Plateforme_Amorcage::web()` |
| `wp_mail` | `FKC_Mailer` (SMTP SSL/STARTTLS, pièces jointes, texte + HTML) |
| WP-Cron (`wp_schedule_event`, `wp_next_scheduled`) | `cron/worker.php` (cron système) + `FKC_CRON_EXTERNE` |
| WP-CLI | `bin/finakop` |
| `get_option` / `update_option` / `wp-config.php` | `FKC_Config` (`~/finakop/config.php`, 0600, variables `FINAKOP_*`) |
| `get_option('admin_email')` | `admin_email` de la configuration (`FKC_ADMIN_EMAIL`) |
| `wp_upload_dir` | Dossier de données du client (`FKC_DATA_DIR`) |
| `wp_remote_get/post/request` | Repli natif déjà présent (`FKC_Http`, flux PHP) |
| `site_url`, `home_url`, `plugins_url` | `FKC_BASE_URL`, `FKC_ASSETS_URL`, `adresseClient()` |
| `FKC_ENCRYPTION_KEY` (wp-config) | `.fkc-secret.key` du client, ou `.fkc-encryption-key` repris à l'import |

**Second audit** (recherche globale dans `app/`) : **0 dépendance WordPress obligatoire**. Il reste 30 appels WordPress, tous derrière `function_exists()`. Ils ne servent qu'à l'extension WordPress construite à partir du **même** `app/`, conservée pendant la transition. Tous les tests de la plateforme tournent sans WordPress chargé. Ces appels pourront être retirés quand l'extension ne sera plus maintenue.

## E. Comment `newloock.finakoperp.com` devient « New Loock »

1. `public/index.php` charge `FKC_Plateforme_Amorcage::web()`.
2. **Relais** : si la connexion vient d'une plage Cloudflare officielle, l'IP réelle est lue dans `CF-Connecting-IP`. Sinon, les en-têtes de relais sont ignorés.
3. **Garde d'origine** : sans l'en-tête secret ajouté par Cloudflare → 403.
4. **`FKC_TenantResolver`** :
   - normalise l'hôte : minuscules, port, point final ; refus des caractères invalides, des ports anormaux et des IP ;
   - n'accepte que `finakoperp.com` et ses sous-domaines **d'un seul niveau** ;
   - extrait `newloock`, valide sa syntaxe et l'écarte s'il est réservé ;
   - le cherche dans `plateforme.db` (`tenants`) : inconnu ou archivé → 404 ; suspendu → 403 ; maintenance → 503 ; actif → suite.
5. **Contexte** : `FKC_DATA_DIR = ~/finakop-data/tenants/newloock/`, sessions dans `~/finakop-data/sessions/newloock/` sous un nom de cookie propre au client, constantes (licence en contrôle strict, SMTP, URL).
6. **FinaKop** s'exécute. Le sous-domaine **n'autorise rien** : il faut ensuite une session **de ce client** (un compte d'un autre client n'existe pas dans ce registre), l'accès à la société (`societe_acces`, revérifié à chaque requête), le module couvert par la licence et le droit RBAC.

## F. Base de données

SQLite conservé (comparaison complète : 02-ARCHITECTURE.md §7.2), à trois niveaux :

```text
plateforme.db                          clients, domaines, journal des opérations, état des tâches
└── tenants/<client>/finakopcore-master.db   utilisateurs, sociétés, accès, licence, sécurité
    └── tenants/<client>/<société>.db        données métier d'une société
```

Écritures sérialisées par fichier, donc par société, avec WAL et reprises automatiques. Copies cohérentes à chaud (`VACUUM INTO`), `integrity_check` à chaque export et sauvegarde. Passage à MariaDB à reconsidérer seulement au-delà d'environ 50 utilisateurs écrivant en même temps **dans une même société**.

## G. Sécurité

| Sujet | Mesure | Vérifié |
| --- | --- | --- |
| CSRF | Jeton sur tout POST (routeur) ; échec → 403 | Tests 3, 5, 8, 20 |
| XSS | Échappement des vues ; CSP ; portail échappé | Test 13 |
| Injection SQL | Requêtes préparées (PDO) | Test 13 |
| IDOR | Entre clients : impossible par construction (bases séparées). Dans un client : identifiants résolus dans la seule société active, accès revérifié à chaque requête | Tests 5, 8, 20 |
| Isolation des clients | Instance, sessions, cookie, clé de chiffrement, licence par client | Tests 5, 6 |
| Sessions | `HttpOnly`, `Secure`, `SameSite=Lax`, cookie limité à l'hôte (jamais au domaine parent), mode strict, régénération, expiration, invalidation à la restauration | Tests 3, 5, 12, 13 |
| Authentification | `password_hash`/`password_verify`, changement imposé du mot de passe initial, blocage progressif par IP **réelle** et par compte, comptes compromis bloqués | Tests 3, 13 |
| Téléversements | Contrôle du contenu réel, extensions dangereuses refusées, stockage hors web, 0640, service par contrôleur avec `nosniff` | Tests 8, 8b |
| En-tête Host | Liste blanche, refus des variantes forgées | Test 13 |
| Parcours de répertoire | `.htaccess` : seul `index.php` s'exécute ; fichiers cachés refusés ; slugs stricts | Test 13 |
| Fichiers sensibles | Configuration, bases, clés et sauvegardes hors de `public_html` | Tests 1, 13 |
| Exports CSV | Neutralisation des formules (**corrigé**) | Harnais dédié |
| SSRF | Notifications push : services connus, IP publiques, sans redirection | Audit |
| En-têtes HTTP | CSP, HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noai, noimageai`, `Cache-Control: no-store` | Tests 12, 23 |
| Double authentification (1.876.2) | TOTP (RFC 6238), obligatoire pour les administrateurs, secret chiffré, anti-rejeu, 10 codes de secours hachés, levée par la console | Test 24 |
| Invisibilité (1.876.2) | Robots et IA refusés (403), `robots.txt` nominatif, portail sans oracle, version masquée, cookie `__Host-`, dossier web inaccessible par le domaine principal | Test 23 ; voir 10-SECURITE-ET-VITRINE.md |

Aucune application n'est « 100 % sécurisée ». La section M liste ce qui reste.

## H. Cloudflare Free : réglages exacts

Voir **05-CLOUDFLARE-DNS.md** :
- SSL Full (strict) ;
- règle de transformation pour l'en-tête secret ;
- 2 règles de cache, dont le contournement de tout le dynamique ;
- 1 à 2 règles personnalisées ;
- 1 limite de débit sur `POST /login` ;
- Rocket Loader et Bot Fight Mode désactivés.

Aucune fonction payante n'est requise.

## I. Hostinger

Voir **04-INSTALLATION-HOSTINGER.md**.

| Élément | Valeur |
| --- | --- |
| PHP | 8.4 ; extensions `pdo_sqlite`, `sodium`, `openssl`, `mbstring`, `fileinfo`, `zip`, `iconv`, `curl`, `phar`, `zlib` |
| Arborescence | `~/finakop` (code et configuration), `~/finakop-data` (données), `public_html/finakop-app` (seul dossier web) |
| Droits | Configuration 0600 ; données 0750 / 0640 ; clés 0600 ; archives 0600 |
| Cron | Une tâche toutes les 5 minutes : `php ~/finakop/current/cron/worker.php` |
| Déploiement | `installer.sh`, puis `deployer.sh` (versions côte à côte, bascule atomique, sauvegarde préalable, `--retour`) |

## J. DNS

| Type | Nom | Cible | Proxy |
| --- | --- | --- | --- |
| A | `@` | `IP_HOSTINGER` | Proxifié |
| CNAME | `www` | `finakoperp.com` | Proxifié |
| A | `app` | `IP_HOSTINGER` | Proxifié |
| A | `*` | `IP_HOSTINGER` | Proxifié |
| MX, SPF, DKIM, DMARC | Valeurs données par hPanel | — | DNS seul |

`IP_HOSTINGER` est à relever dans hPanel. Côté Hostinger, chaque sous-domaine (`app`, chaque client) doit être déclaré vers `public_html/finakop-app` : l'offre Business ne gère pas de sous-domaine joker côté site.

## K. Installation

1. hPanel : PHP 8.4 et ses extensions, SSH activé, CDN Hostinger désactivé si Cloudflare.
2. Envoyer `finakop-plateforme-1.876.0.zip` dans le dossier personnel, puis lancer `scripts/installer.sh … --web=$HOME/domains/finakoperp.com/public_html/finakop-app`.
3. Noter la clé de sauvegarde affichée ; compléter `~/finakop/config.php` (SMTP, `admin_email`).
4. Créer les sous-domaines `app` et `<client>` vers `public_html/finakop-app` ; SSL gratuit pour chacun.
5. Créer la boîte `no-reply@` ; SPF, DKIM et DMARC ; `mail:test`.
6. Tâche cron toutes les 5 minutes.
7. `tenant:creer` ou `tenant:importer` (reprise WordPress), puis licence par sous-domaine.
8. `plateforme:verifier`.
9. Cloudflare : DNS, Full (strict), en-tête secret, puis `config:set cloudflare.secret_origine`.
10. Copie hors site des sauvegardes depuis votre poste (`rsync`).

## L. Tests

Voir **07-TESTS.md** :
- **212 vérifications de plateforme, 0 échec** (21 tests, dont injection de panne et restaurations réelles) ;
- suite de non-régression FinaKop : **411 harnais sur 411** (1.876.4) ;
- parcours authentifié de 588 routes sur deux clients : aucune erreur 500, aucun message PHP.

## M. Problèmes restants et limites (honnêtement)

| # | Point | Statut / recommandation |
| --- | --- | --- |
| 1 | **Pas d'accès à vos comptes Hostinger et Cloudflare** : les réglages n'ont pas été appliqués ni observés en réel | Suivre docs 04 et 05 ; lancer `diagnostic-hebergement.php` puis `plateforme:verifier` |
| 2 | Tests faits sous **Apache** et PHP 8.3 côté web, pas sous LiteSpeed avec PHP 8.4 | Le `.htaccess` n'emploie que des directives que LiteSpeed lit. Vérifier en recette les tests 12 et 13 (accès aux fichiers cachés, hôte forgé) |
| 3 | Protection de l'origine **partielle** sur mutualisé : en-tête secret, pas de filtrage IP | Limite de l'hébergement ; filtrage complet possible sur VPS |
| 4 | Un sous-domaine **à déclarer dans hPanel** par client | Limite de Hostinger Business ; automatique sur VPS (joker nginx). Mode chemin disponible en secours |
| 5 | Capacité : environ 30 processus simultanés pour **tous** les clients | SSE désactivé par défaut ; surveiller les 503 ; passer sur VPS au-delà (06-EXPLOITATION §8) |
| 6 | Domaines personnalisés des clients | Prévus (registre, résolveur) ; nécessitent Cloudflare for SaaS ou un VPS : hors V1 |
| 7 | `script-src 'unsafe-inline'` dans la CSP | Chantier produit (nonces), hors migration |
| 8 | Pas de « mot de passe oublié » en libre-service | Choix de sécurité existant : réémission par l'administrateur (`reemettre-mdp`) |
| 9 | Le super administrateur (accès SSH) peut techniquement lire les fichiers des clients | Inhérent à l'hébergement ; toute action de la console est journalisée ; chiffrement par clé du client hors V1 |
| 10 | Les 63 packs ont été vérifiés par la suite de non-régression et le parcours des routes, pas par un essai fonctionnel manuel de chacun | Recette métier recommandée sur la recette (staging) avant la bascule de chaque client |
| 11 | Clés privées de l'outil LICENCES présentes dans l'archive transmise au départ | Ne jamais les déposer sur le serveur ; envisager leur rotation si l'archive a circulé |
| 12 | Invisibilité : `robots.txt` et en-têtes sont des demandes ; un robot déguisé en navigateur n'est pas reconnu (il ne voit que la page de connexion) ; les noms de sous-domaine figurent dans les registres publics de certificats | Règle Cloudflare de 10 §4 ; certificat joker pour ne plus publier chaque nom |
