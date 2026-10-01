# Rapport d'audit : FinaKop ERP Core 1.875.5 → plateforme autonome

Audit réalisé le 30/09/2026 sur le paquet `finakop-erp-core-1_875_5.zip` (dernière version fournie ; aucune version plus récente n'a été transmise). Il intègre les correctifs 1.875.6 préparés pendant l'étude VPS.

## 1. Résumé

Le cœur applicatif de FinaKop est **déjà presque indépendant de WordPress**. WordPress n'y sert qu'à trois choses :
- aiguiller les requêtes vers l'application ;
- stocker six réglages ;
- déclencher les tâches planifiées et envoyer les courriels.

L'application a ses propres routeur, authentification, sessions, bases, licences, API et chiffrement.

Parmi les dépendances WordPress, **une seule casse réellement une fonction hors WordPress : l'envoi de courriels** (`wp_mail`, sans alternative). Toutes les autres ont déjà une solution de secours native.

Le point structurant n'est pas WordPress, c'est le **modèle multi-sociétés**. Il a été conçu pour **un cabinet** qui gère plusieurs sociétés, pas pour une plateforme où chaque société est un client étranger aux autres :
- les utilisateurs sont communs à tout le cabinet ;
- un administrateur voit toutes les sociétés ;
- la licence, les clés d'API et les quotas sont ceux du cabinet.

Il ne faut donc **pas** faire d'un client une « société » d'une installation partagée (section 6).

## 2. Architecture actuelle

| Élément | Constat |
| --- | --- |
| Taille | 2 103 fichiers PHP, 371 716 lignes dans `app/` ; 402 fichiers de test |
| Point d'entrée | `finakop-erp-core.php` (extension) intercepte la requête sur `plugins_loaded`, définit 7 constantes (`FKC_ROOT`, `FKC_DATA_DIR`, `FKC_BASE_URL`, `FKC_BASE_PATH`, `FKC_ASSETS_URL`, `FKC_LICENSE_SERVER`, `FKC_API_AUTHORITY`), puis charge `app/index.php` et s'arrête |
| Amorçage | `app/index.php` : ~700 `require` du noyau, API, session, registre, licence, société, packs, puis déclaration de **1 637 routes** et `dispatch()` |
| Routeur | `FKC_Router` maison. Intermédiaires par route : `auth`, changement de mot de passe imposé, `societe`, `admin`, `module` (licence), autorisation RBAC, `cap`, **CSRF sur tout POST** sauf routes `public` |
| Noyau | `app/Core` : 199 fichiers + `Economie`, `Creative`, `Fie`, `Maestro` |
| Modules | 30 (comptabilité, facturation, inventaire, caisse, paie, RH, fiscalité, analytique, GED, API…) |
| Packs métier | 63 (`app/Packs`), activés **uniquement** par le jeton de licence |
| Services | `Connect` : messagerie ; relais WebSocket externe optionnel, sinon interrogation régulière |
| Authentification | `FKC_Auth` : `password_hash`, régénération d'identifiant de session à la connexion et à la déconnexion, contrôle d'inactivité et de durée absolue, changement imposé du mot de passe initial, blocage des mots de passe publiés |
| Autorisation | RBAC (`FKC_RoleEngine`, `FKC_Auth::can`) par module, rôle admin, accès par société (`societe_acces`) |
| Multi-sociétés | Registre `finakopcore-master.db` (utilisateurs, sociétés, accès, paramètres, clés d'API, sécurité) + **une base SQLite par société** |
| Base de données | SQLite via PDO, mode WAL, `busy_timeout`, migrations intégrées et idempotentes (`schema_version`), SQL propre à SQLite (`ON CONFLICT`, `datetime('now','localtime')`, `PRAGMA`) |
| Fichiers | `FKC_DATA_DIR/pieces`, `ged`, `logos`, `cabinet_pieces`, `connect`… servis **uniquement** par des contrôleurs authentifiés |
| Chiffrement | `FKC_Crypto` (libsodium, repli AES-256-GCM) ; clé dans `.fkc-secret.key` ou `FKC_ENCRYPTION_KEY` |
| Licence | Jeton signé RSA-SHA256, vérifié hors ligne ; domaine, palier, modules, packs, services, fonctions, quotas ; dépôt de révocation signé (seconde paire de clés) ; vérification distante optionnelle |
| Tâches différées | File `erp_sync_queue` (BPE) consommée par `FKC_BPEWorker` ; temporisation comptable (`FKC_Balayeur`) |
| Courriels | `FKC_Courriel` → `wp_mail`, journalisés |
| API | REST `/api/*`, en-tête `Authorization: Bearer`, portées, limite par clé, quota mensuel, CORS configurable |
| Webhooks | Mobile Money `/webhook/momo/{societe}/{fournisseur}` (routes publiques, signature vérifiée) |
| Journaux | `FKC_DATA_DIR/app.log` (rotation à 2 Mo), `security_events` en base |
| Actifs | `app/assets` (CSS, JS, images, terminal), 2 Mo ; aucune dépendance Composer ni npm |

## 3. Dépendances WordPress (inventaire exhaustif)

| Fichier | Appels | Nature | Sans WordPress |
| --- | --- | --- | --- |
| `finakop-erp-core.php` | 110 | Amorçage de l'extension, page de réglages, WP-Cron, WP-CLI | **Remplacé** par `public/index.php` + `app/Plateforme` |
| `uninstall.php` | 9 | Désinstallation de l'extension | Inutile hors WordPress |
| `app/Core/Courriel.php` | `wp_mail`, `add_action('wp_mail_failed')` | **Seul envoi de courriels** | **Cassé** → remplacé par `FKC_Mailer` (SMTP) |
| `app/Core/BPEWorker.php`, `Balayeur.php` | `wp_schedule_event`, `wp_next_scheduled`, `wp_clear_scheduled_hook` | Planification | Protégé par `function_exists` → remplacé par le cron système |
| `app/Core/Http.php` | `wp_remote_request` | Client HTTP | Repli cURL déjà présent |
| `app/Core/ApiKey.php`, `LicenseDepot.php` | `wp_remote_post`, `wp_remote_get` | Appels au service de licence | Repli natif déjà présent |
| `app/Services/Connect/Push.php`, `Relais.php` | `wp_remote_post`, `get_option('admin_email')` | Notifications push, relais | Repli natif ; `admin_email` → configuration |
| `app/Core/Security.php` | `wp_upload_dir`, `get_option` | Diagnostic d'exposition | Protégé → contrôle générique ajouté |
| `app/Core/ScanResolvers.php` | `do_action` | Ligne morte (`if … {}`) | Sans effet |

Faux positifs relevés et écartés : `wp_json_safe` et `wp_strip_all_tags_safe` sont des fonctions **de FinaKop** au préfixe trompeur, définies localement ; `$is_admin` dans les vues est une variable.

**Défaut réel trouvé pendant l'audit (corrigé en 1.875.6)** : sous WordPress, les crochets WP-Cron et `wp finakop bpe` ne trouvaient jamais les classes. La file différée, dont la consultation du dépôt de licences, ne se vidait qu'au bouton. Un test le prouve : il échoue sur 1.875.5 et passe sur 1.875.6.

## 4. Dépendances système

| Extension | Usage réel | Statut |
| --- | --- | --- |
| PDO + pdo_sqlite | Toutes les données | **Obligatoire** |
| openssl | Vérification des jetons de licence, repli de chiffrement | **Obligatoire** |
| json | Partout (133 fichiers) | **Obligatoire** (natif en PHP 8) |
| sodium | Chiffrement au repos (préféré) | Recommandée (repli OpenSSL) |
| mbstring | 113 fichiers | Recommandée (replis dans `helpers.php`) |
| ctype | 13 fichiers | Standard |
| zip | Export d'archives du pack Cabinet (contrôlé) | Optionnelle |
| curl | Repli HTTP | Optionnelle (repli par flux) |
| gd, imagick, intl, fileinfo, xml, bcmath | **Aucun usage** | Non requises |

**Versions de PHP** : minimum déclaré 8.1. Validé ici en **PHP 8.4.19** (suite complète : 402 fichiers de test, tous verts) et en **8.3** (service web PHP-FPM). PHP 8.4 est donc la cible sur Hostinger.

## 5. Sécurité : constats

| Domaine | Constat | Niveau |
| --- | --- | --- |
| Injection SQL | Requêtes préparées partout ; aucune concaténation de `$_GET`/`$_POST` dans du SQL | Bon |
| XSS | Échappement `e()` dans les vues ; aucun `echo $_GET` brut | Bon |
| CSP | Présente, mais `script-src 'unsafe-inline'` : la CSP n'arrête pas un XSS injecté | **À améliorer** (chantier produit : nonces) |
| CSRF | Jeton de session vérifié sur **tout POST** par le routeur, comparaison en temps constant | Bon |
| Sessions | HttpOnly, Secure (si HTTPS), SameSite=Lax, identifiant régénéré à la connexion, `use_strict_mode` | Bon |
| Force brute | Blocage par couple IP + identifiant (`security_throttle`) | Bon, **mais faussé derrière un proxy** si l'IP réelle n'est pas rétablie |
| IP du client | Seul `REMOTE_ADDR` est cru (volontairement) | Correct en direct ; **à adapter derrière Cloudflare** |
| Récupération de mot de passe | Pas de « mot de passe oublié » : l'administrateur réémet un mot de passe à usage unique | Acceptable (pas de surface d'attaque) |
| Envoi de fichiers | Liste blanche d'extensions, contrôle du contenu réel, nom aléatoire, 8 Mo, 20 par lot, quota | Bon |
| Téléchargement | Par identifiant en base, via contrôleur authentifié ; aucun chemin fourni par l'utilisateur | Bon |
| Inclusion de fichiers | Aucune inclusion dynamique depuis une entrée utilisateur | Bon |
| SSRF | Les notifications push partent vers une adresse `https://` **fournie par le navigateur**, sans filtrage des adresses internes | **Moyen** : un utilisateur peut faire émettre une requête vers le réseau interne de l'hébergeur |
| En-têtes | CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP/CORP, HSTS | Bon |
| CORS API | Refusé par défaut, liste blanche configurable | Bon |
| Routes publiques | Reçus, portail scolarité, appairage de scan, terminal, webhooks : protégés par jeton ou signature, avec limiteur | Bon |
| Clé de chiffrement | Clé neuve **générée en silence** si elle manque | **Corrigé en 1.875.6** (empreinte, refus explicite) |
| Hôte de la requête | `HTTP_HOST` non validé en mode extension | **À encadrer** : liste de domaines autorisés dans la plateforme |

## 6. Multi-tenant : pourquoi le modèle actuel ne suffit pas tel quel

| Élément | Portée actuelle | Conséquence si « client = société » dans une installation partagée |
| --- | --- | --- |
| Utilisateurs (`cabinet_users`) | Tout le cabinet | Identifiants partagés entre clients ; un « admin » d'un client est admin de tous |
| `FKC_Tenant::userCanAccess` | L'admin voit **toutes** les sociétés | **Fuite inter-clients** dès qu'un client a un administrateur |
| Licence | Une par installation | Impossible de vendre des paliers ou des packs différents à chaque client |
| Clés d'API, quotas | Cabinet | Quota partagé entre clients |
| Pièces jointes | `FKC_DATA_DIR/pieces` **commun** à toutes les sociétés | Pas d'isolation physique |
| Sauvegarde ou restauration d'un client | Impossible sans toucher aux autres | Pas de restauration ciblée, pas de suppression propre (RGPD) |

**Conclusion** : un client (tenant) doit être une **instance FinaKop complète et isolée** : son propre registre, ses utilisateurs, sa licence, sa clé de chiffrement, ses fichiers. Tous les clients partagent le même code. À l'intérieur de son instance, un client peut toujours avoir plusieurs sociétés (un groupe).

## 7. Hébergement cible : contraintes vérifiées (Hostinger Business)

| Contrainte | Constat | Effet |
| --- | --- | --- |
| Sous-domaines wildcard | Un enregistrement DNS `*` ne suffit pas : **chaque sous-domaine doit être créé dans hPanel** (il peut pointer vers le même dossier) | Ouvrir un client = une commande + une création de sous-domaine (1 minute). Le wildcard complet exige un VPS |
| Certificats wildcard | **Non acceptés** sur l'hébergement web (VPS uniquement) | SSL par sous-domaine (gratuit, automatique) ; Cloudflare couvre `*.finakoperp.com` côté visiteurs |
| Processus d'entrée | 30 simultanés **pour toute la plateforme** | Adapté au lancement (quelques dizaines de clients peu actifs) ; VPS au-delà |
| Processus en arrière-plan | Interdits | Pas de relais Connect ni de worker permanent : cron toutes les minutes à 5 minutes |
| SSH, cron | Disponibles, cron à la minute | Déploiement et tâches possibles |
| PHP | 8.4 disponible (confirmé par vous) | Cible validée |

Sources :
- [Sous-domaines Hostinger](https://www.hostinger.com/support/4469008-how-to-create-and-delete-subdomains-in-cpanel-at-hostinger/)
- [Certificats wildcard et multi-domaines](https://www.hostinger.com/support/1583432-are-wildcard-or-multi-domain-ssl-certificates-supported-at-hostinger/)
- [Échecs d'installation d'un SSL personnalisé](https://www.hostinger.com/support/6064886-how-to-fix-custom-ssl-installation-issues-at-hostinger/)
- [Paramètres et limites des offres](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/)
- [Processus en arrière-plan](https://www.hostinger.com/support/1583713-can-background-processes-be-executed-via-ssh-in-hostinger/)

## 8. Performance

Mesuré sur 588 pages, derrière nginx + PHP-FPM :
- médiane 59 ms ;
- 90 % des pages sous 92 ms ;
- page la plus lente 215 ms, hors deux cas.

Ces deux cas :
- **Purge du plan comptable : 23 s.** `FKC_PlanAudit::cibleDe()` refait le classement complet du plan pour chacun des 1 058 comptes, deux fois par affichage. Corrigé : un seul calcul par analyse, même résultat.
- **Afficheur client de caisse** : flux de 25 s par écran ouvert. C'est voulu, mais chaque écran occupe un des 30 processus du mutualisé.

## 9. Risques et recommandations

| # | Risque | Recommandation |
| --- | --- | --- |
| R1 | Fuite inter-clients avec un modèle « société partagée » | Instance isolée par client (section 6) |
| R2 | Courriels impossibles sans WordPress | `FKC_Mailer` (SMTP), branché dans `FKC_Courriel` |
| R3 | Tâches planifiées jamais exécutées | `app/noyau.php` + worker cron (fait) |
| R4 | Perte silencieuse des secrets au déménagement | Empreinte de clé et refus explicite (fait) |
| R5 | IP de Cloudflare prise pour celle des visiteurs | Rétablissement de `CF-Connecting-IP` **seulement** depuis les plages officielles de Cloudflare |
| R6 | Contournement de Cloudflare vers l'origine | En-tête secret ajouté par Cloudflare, exigé par la plateforme |
| R7 | Injection d'en-tête `Host` | Liste de domaines autorisés, refus des autres |
| R8 | SSRF par les notifications push | Refus des adresses privées, de bouclage et locales |
| R9 | Session d'un client présentée à un autre | Dossier de sessions et nom de cookie **propres à chaque client** |
| R10 | Jeton de licence d'un client installé chez un autre | Jeton lié au sous-domaine du client + contrôle **strict** du domaine forcé en plateforme (le mode historique accepterait `xnewloock` pour `newloock`) |
| R11 | Capacité du mutualisé (30 processus) | Surveiller ; migration VPS prévue sans réécriture |
| R12 | `script-src 'unsafe-inline'` | Chantier produit ultérieur (nonces) : hors périmètre de cette migration |
| R13 | Horodatages SQLite en « heure locale » du serveur | Fuseau forcé et identique pour PHP et SQLite (`TZ`), relevé du fuseau de l'ancien serveur à l'export |
| R14 | **Défaut trouvé pendant les tests** : erreur fatale « Cannot declare class FKC_IndMrp » sur toutes les pages quand le pack principal est industrie ou distribution (7 fichiers de pack inclus deux fois) | `require_once` (corrigé, test `plateforme_chargement_packs_1876.php`) |
| R15 | **Défaut trouvé pendant les tests** : jeton CSRF expiré → code 419, transformé en erreur 500 par Apache/LiteSpeed | Réponse 403 (corrigé) |
| R16 | Site WordPress dont la clé est la constante `FKC_ENCRYPTION_KEY` : clé perdue au déménagement, secrets illisibles | L'export l'emporte (`.fkc-encryption-key`, 0600) ; import refusé sans elle (corrigé, test 15) |
| R17 | **Faille trouvée au second audit** : injection de formule dans les exports CSV (`=HYPERLINK(…)` saisi par un utilisateur, exécuté par le tableur du comptable) | `fkc_csv_cellule()` (corrigé, test `export_csv_injection_1876.php`) |
| R18 | Flux SSE (afficheur client, scanner) : un processus PHP occupé en continu par écran ouvert, sur un quota d'environ 30 partagé par tous les clients | SSE désactivable, désactivé par défaut sur la plateforme, repli sur l'interrogation existante (test 19) |
| R20 | **Faille trouvée en production** : tout utilisateur connecté pouvait installer ou remplacer la licence de la société (et la bloquer avec un mauvais jeton) | Réservé à l'administrateur (corrigé, test `licence_obligatoire_1876.php`) |
| R19 | Fichiers téléversés (GED, pièces, logos) forcés en 0644, donc lisibles par tout compte d'un serveur mutualisé (atténué par les dossiers en 0750) | 0640 partout (corrigé, test `televersements_droits_1876.php`) |

## 10. Ce qui manquait pour décider

- **Aucune version plus récente que 1.875.5 n'a été fournie.** L'audit porte sur 1.875.5 + 1.875.6.
- **L'état de votre installation actuelle** (hébergeur, fuseau du serveur, taille des données, présence de `FKC_ENCRYPTION_KEY`) : l'outil d'export le relève.
- **La limite du nombre de sous-domaines** de votre offre : à lire dans hPanel.
