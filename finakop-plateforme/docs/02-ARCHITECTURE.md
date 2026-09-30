# Architecture cible : FinaKop Plateforme 1.876.0

## 1. Décision principale : un client = une instance FinaKop isolée

```text
Internet
   │
Cloudflare  (DNS, TLS visiteurs, WAF, anti-DDoS, en-tête secret vers l'origine)
   │
Hostinger Business  (LiteSpeed, PHP 8.4)
   │
public/index.php  ─────────────────────────────── un seul point d'entrée pour tous les hôtes
   │
app/Plateforme/Amorcage
   ├─ Config            ~/finakop/config.php (+ variables d'environnement)
   ├─ Cloudflare        IP réelle (plages officielles seulement), garde d'origine
   ├─ Hôte              liste des domaines autorisés, refus des autres
   ├─ TenantResolver    newloock.finakoperp.com → tenant « newloock » (registre plateforme)
   │     ├─ app.finakoperp.com  → portail (choix de l'espace)
   │     ├─ réservé, inconnu    → page neutre (404)
   │     └─ suspendu, maintenance → 403 / 503
   ├─ Contexte tenant   FKC_DATA_DIR = ~/finakop-data/tenants/newloock/
   │                    sessions : ~/finakop-data/sessions/newloock/, cookie FKC_SESSION_newloock
   └─ app/index.php     FinaKop inchangé : auth → RBAC → société → modules → packs
                          │
         ~/finakop-data/tenants/newloock/
              finakopcore-master.db   utilisateurs, sociétés, licence, clés API
              <société>.db            une base par société du client
              pieces/ ged/ logos/ connect/   documents du client
              .fkc-secret.key         clé de chiffrement du client
              app.log                 journal du client
```

Chaque client reçoit un **FinaKop complet** : ses utilisateurs, sa licence, ses packs, sa clé de chiffrement, ses bases, ses documents, son journal. Le code est commun, lu depuis `~/finakop/current`.

**Pourquoi c'est l'option la plus sûre et la moins risquée :**
1. **Isolation physique.** Une requête sur `newloock.finakoperp.com` n'ouvre **que** les fichiers de New Loock. Même une faille dans les contrôles d'accès de FinaKop ne peut pas atteindre la société B : ses fichiers ne sont jamais ouverts dans ce processus.
2. **Aucune réécriture du métier.** Les 1 637 routes, 63 packs et 30 modules gardent leurs contrôles actuels.
3. **Le modèle de licence existant s'applique tel quel** : un jeton par client, lié à son sous-domaine.
4. **Sauvegarde, restauration, export et suppression par client**, sans toucher aux autres.
5. **Migration VPS triviale** : même code, mêmes dossiers.

Impact : la plateforme ne connaît pas les utilisateurs des clients. Une console d'administration globale (tous clients) passe par le registre plateforme et la ligne de commande, pas par l'interface d'un client.

## 2. Réponses aux 16 questions

| # | Question | Réponse |
| --- | --- | --- |
| 1 | Conserver SQLite ? | **Oui.** Le code (371 000 lignes, ~150 tables par société) est écrit pour SQLite : `ON CONFLICT`, `PRAGMA`, `datetime('now','localtime')`, migrations propres. SQLite en WAL sert sans difficulté plusieurs dizaines d'utilisateurs **par société** : les écritures sont sérialisées par fichier, donc par société. |
| 2 | Passer à MariaDB ? | **Pas maintenant.** Porter les requêtes et migrations coûterait des mois, avec un risque élevé sur l'intégrité comptable, pour un gain nul à cette échelle. À envisager seulement si une **seule société** dépasse ~50 écrivains simultanés, ou pour de l'analytique transverse. Même alors, on commencerait par une base d'agrégats alimentée par export. |
| 3 | Une base par société ? | **Oui, conservée.** |
| 4 | Base centrale + bases tenant ? | **Oui, à trois niveaux** : `plateforme.db` (registre des clients, domaines, journal des opérations, état des tâches) → registre du client (`finakopcore-master.db`) → une base par société. |
| 5 | DNS wildcard approprié ? | **Oui chez Cloudflare** (`*` proxifié), pour ne pas toucher au DNS à chaque client. **Mais Hostinger exige de créer chaque sous-domaine dans hPanel** (vers le même dossier) : le wildcard n'y est pas automatique. Sur VPS, il l'est entièrement. |
| 6 | `app.` ou sous-domaines clients ? | **Les deux.** Chaque client sur son sous-domaine (`newloock.finakoperp.com`) ; `app.finakoperp.com` sert de **portail** où l'on saisit l'identifiant de son espace. Pas d'`api.` : l'API de chaque client est déjà sur son hôte (`newloock.finakoperp.com/api/...`), ce qui garde clés et quotas isolés. Pas d'`admin.` web : l'administration technique passe par SSH (surface d'attaque nulle). |
| 7 | Fichiers des clients ? | Dans le dossier de données **du client**, hors du web, servis uniquement par les contrôleurs authentifiés de FinaKop. |
| 8 | Isolation entre clients ? | Instance isolée ; hôte validé ; slug strict ; clé de chiffrement par client ; sessions séparées ; licence liée à l'hôte en contrôle strict. Testée (tests 5, 6, 13). |
| 9 | Sessions entre sous-domaines ? | **Aucune session partagée**, volontairement : cookie propre à l'hôte, nom de cookie et dossier de sessions propres au client. Une session de New Loock présentée à la société B n'y existe pas : retour à la connexion. |
| 10 | Licences ? | Un jeton par client, **domaine = son sous-domaine**, contrôle strict forcé. Installation par le client (écran Licence) ou en ligne de commande. Dépôt de révocation inchangé, déplaçable sur `license.finakoperp.com`. |
| 11 | Cron ? | **Une seule tâche cron** (toutes les 5 minutes) : `cron/worker.php` parcourt les clients actifs. Chaque client tourne dans un processus séparé, avec verrou et délai maximal, en reprenant par les clients les moins récemment traités. Sauvegardes quotidiennes et nettoyage inclus. |
| 12 | Courriels ? | `FKC_Mailer` : SMTP (SSL 465, STARTTLS 587), repli `mail()`, pièces jointes, texte + HTML, journal. Boîte `no-reply@finakoperp.com` de l'hébergeur ; SPF, DKIM et DMARC à publier. |
| 13 | Sauvegardes ? | Par client : copie cohérente des bases, documents, clé et état de licence, dans une archive **chiffrée** (libsodium) avec manifeste (lignes par table, empreintes). Rotation, restauration testée, restauration possible **vers un autre slug** pour vérifier sans risque. Copie hors site : **rapatriement** par SSH depuis une machine à vous (le serveur ne détient aucun accès hors site), plus les sauvegardes quotidiennes de Hostinger. |
| 14 | Journaux ? | Par client : `app.log` (rotation) et `security_events` en base. Plateforme : `~/finakop-data/plateforme/logs/` (cron, courriels, sécurité de la plateforme), rotation automatique. |
| 15 | Protéger l'origine derrière Cloudflare ? | Un mutualisé ne peut pas filtrer les IP. Cloudflare ajoute un **en-tête secret** (règle de transformation, offre gratuite) ; la plateforme refuse toute requête sans lui (403). Mode SSL « Full (strict) ». |
| 16 | Migration future vers un VPS ? | Rien de spécifique à Hostinger dans le code : chemins, domaine et SMTP sont en configuration. Sur VPS : même archive, même `~/finakop-data`, nginx `server_name *.finakoperp.com`, PHP-FPM, cron identique. Redis et Supervisor deviennent **possibles** (sessions, worker permanent) sans être nécessaires. |

## 3. Stratégie DNS et TLS

| Enregistrement (Cloudflare) | Type | Cible | Proxy |
| --- | --- | --- | --- |
| `finakoperp.com` | A | IP Hostinger | Proxifié |
| `www` | CNAME | `finakoperp.com` | Proxifié |
| `app` | A | IP Hostinger | Proxifié |
| `*` | A | IP Hostinger | Proxifié |
| `mail`, MX, SPF (TXT), DKIM (TXT), DMARC (TXT) | selon Hostinger | — | **DNS seul** (gris) |

- **Côté visiteurs** : le certificat universel gratuit de Cloudflare couvre `finakoperp.com` et `*.finakoperp.com`.
- **Côté origine** : Hostinger émet un certificat gratuit **par sous-domaine** (le wildcard n'y est pas accepté). Mode Cloudflare **Full (strict)**.
- **Si un certificat Hostinger ne s'émet pas** derrière le proxy : repasser l'enregistrement en « DNS seul » le temps de l'émission, ou importer un certificat d'origine Cloudflare **nommé** (non wildcard) pour ce sous-domaine.
- **Domaines personnalisés** (`erp.newloock.com`) : prévus dans le registre (`tenant_domaines`) et dans le résolveur. En production, il faut Cloudflare for SaaS (hôtes personnalisés) et un domaine ajouté dans hPanel par client (limite de sites du plan). C'est plus naturel sur VPS. Hors périmètre de cette migration.

## 4. Mode chemin (secours)

Si la création manuelle des sous-domaines devenait un frein, la plateforme accepte aussi `app.finakoperp.com/newloock/` (`mode_tenant = "chemin"` ou `"les_deux"`). Un seul sous-domaine et un seul certificat suffisent alors. Le cookie de session est limité au chemin du client. Ce mode est **désactivé par défaut** : le sous-domaine reste la cible.

## 5. Arborescence de production (Hostinger)

```text
/home/uXXXX/
├── finakop/
│   ├── config.php                   ← configuration et secrets (0600), hors web
│   ├── releases/1.876.0/            ← contenu de l'archive (app/, bin/, cron/, public/, scripts/, docs/)
│   └── current → releases/1.876.0
├── finakop-data/                    ← jamais sous public_html
│   ├── plateforme/  plateforme.db, logs/, verrous/
│   ├── tenants/<client>/            ← FKC_DATA_DIR du client
│   ├── sessions/<client>/
│   └── sauvegardes/<client>/
└── domains/finakoperp.com/public_html/
    ├── (site public www)
    └── finakop-app/                 ← dossier de TOUS les sous-domaines FinaKop
        ├── index.php                ← généré au déploiement (chemin de ~/finakop/current)
        ├── .htaccess
        └── _fkc/                    ← fichiers statiques copiés depuis app/assets
```

## 6. Composants ajoutés

| Composant | Rôle |
| --- | --- |
| `app/Plateforme/Config.php` (`FKC_Config`) | Configuration système (fichier et variables `FINAKOP_*`), accès par clé à points, secrets hors web |
| `app/Plateforme/Registre.php` (`FKC_Plateforme_Registre`) | `plateforme.db` : clients, domaines, journal, état des tâches |
| `app/Plateforme/TenantResolver.php` (`FKC_TenantResolver`) | Hôte → client, slug strict, réservés, statut |
| `app/Plateforme/Cloudflare.php` | Plages IP officielles, IP réelle, HTTPS, garde d'origine |
| `app/Plateforme/Amorcage.php` | Point d'entrée web : ordonne tout, définit les constantes, sessions, pages neutres |
| `app/Plateforme/Instantane.php` | Copie cohérente et manifeste (export, sauvegarde, restauration) |
| `app/Plateforme/Sauvegarde.php` | Archive chiffrée, rotation, restauration |
| `app/Plateforme/Pages.php` | Portail, pages 404/403/503 neutres |
| `app/Core/Mailer.php` (`FKC_Mailer`) | Courriels SMTP (produit, utilisable aussi hors plateforme) |
| `bin/finakop` | Console : clients, licence, sauvegardes, diagnostic, courriel de test |
| `cron/worker.php` | Tâche cron unique |
| `public/index.php`, `public/.htaccess` | Contrôleur frontal et règles LiteSpeed/Apache |
