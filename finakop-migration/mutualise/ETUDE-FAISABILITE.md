# FinaKop autonome sur hébergement mutualisé « Business Web Hosting » + Cloudflare

Étude du 30/09/2026. Cible : FinaKop ERP Core 1.875.6, **sans WordPress**, sur un sous-domaine de **finakoperp.com** (par exemple `app.finakoperp.com`), derrière **Cloudflare**.

Hypothèse : « Business Web Hosting » désigne l'offre **Hostinger Business**, dont c'est le nom commercial. Les valeurs ci-dessous sont celles publiées pour cette offre en 2026 ; le script `diagnostic-hebergement.php` les mesure sur le compte réel.

## Verdict

**Faisable, sous conditions.** Aucun point du code n'exige un VPS :
- FinaKop n'utilise ni `exec`, ni `shell_exec`, ni processus permanent ;
- ses bases sont des fichiers SQLite ;
- ses tâches planifiées passent par `bin/finakop`, lançable par le cron de l'hébergeur.

Il faut en revanche :
1. valider 6 points sur le compte réel, avec le diagnostic ;
2. développer 5 adaptations (section 4) ;
3. réémettre la licence pour le nouveau domaine.

## 1. Ce que fournit l'offre (Hostinger Business, 2026)

| Ressource | Valeur publiée | Effet pour FinaKop |
| --- | --- | --- |
| Processeur | 2 cœurs, quota partagé | Environ 2 fois moins qu'un VPS-1 (4 vCPU dédiés) |
| Mémoire | 3 Go | Suffit : un processus FinaKop consomme 40 à 80 Mo |
| Processus d'entrée | 30 | **30 requêtes PHP simultanées au maximum**, tous utilisateurs confondus |
| Processus PHP | 60 | — |
| Stockage | 50 Go NVMe, 600 000 inodes | Largement suffisant (le code fait ~2 200 fichiers) |
| Débit disque | 2 Mo/s selon une source tierce | **À mesurer** : conditionne sauvegardes, imports et grosses clôtures |
| SSH, cron | Oui ; cron à la minute, sans limite de nombre | Tâches planifiées et déploiement possibles |
| Sauvegardes | Quotidiennes et à la demande | Filet de sécurité en plus des nôtres |
| Processus en arrière-plan | Non autorisés | **Pas de relais temps réel Connect** sur ce compte |

Sources :
- [Hostinger — paramètres et limites des offres](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/)
- [Limites LVE Hostinger 2026 (thatmy.com)](https://thatmy.com/hostinger-resource-limits-explained)
- [Tarifs Hostinger 2026 (bloggerspassion.com)](https://bloggerspassion.com/best-web-hosting/hostinger-pricing-plans-explained/)
- [Nombre de tâches cron](https://www.hostinger.com/support/1583765-how-many-cron-jobs-can-you-set-up-in-hostinger/)
- [Processus en arrière-plan par SSH](https://www.hostinger.com/support/1583713-can-background-processes-be-executed-via-ssh-in-hostinger/)

## 2. Performances : ce qu'on peut attendre

Mesure réelle, FinaKop 1.875.6 derrière nginx + PHP-FPM, 588 pages parcourues :
- médiane **59 ms** par page ;
- 90 % des pages sous **92 ms** ;
- la plus lente (hors deux cas ci-dessous) : **215 ms**.

Sur 2 cœurs partagés, compter environ le double. Avec 30 requêtes simultanées, cela représente plusieurs dizaines d'utilisateurs actifs en même temps.

Par rapport à l'installation WordPress actuelle, le mode autonome ne charge plus WordPress à chaque requête : **pas de perte de performance** si l'hébergement actuel est lui aussi mutualisé. Par rapport au VPS-1 étudié avant, on perd de la marge, pas de fonctionnalités.

Deux points consomment beaucoup de processus sur un mutualisé :
- **Afficheur client de caisse** (`caisse/afficheur/stream`) : chaque écran ouvert occupe un processus en continu (flux de 25 s renouvelé). Trois écrans = 3 des 30 processus.
- **Purge du plan comptable** : 23 s par affichage, à cause d'un défaut du produit (le classement du plan est recalculé pour chacun des 1 058 comptes). À corriger avant la bascule : sur un mutualisé, ce n'est pas qu'une lenteur.

## 3. Points à valider sur le compte réel (diagnostic)

Déposer `diagnostic-hebergement.php` sur le sous-domaine, l'ouvrir une fois **avant** et une fois **après** Cloudflare, et le lancer aussi en SSH.

| # | Point | Pourquoi c'est bloquant |
| --- | --- | --- |
| 1 | PHP 8.1 ou plus, avec `pdo_sqlite` | Sans lui, FinaKop ne démarre pas |
| 2 | Un dossier **hors de `public_html`**, inscriptible par PHP | Sinon les bases et la clé sont téléchargeables |
| 3 | SQLite en mode **WAL** et lecture concurrente | Sur un stockage réseau, WAL échoue et corrompt sous charge |
| 4 | Débit et latence disque | Au-dessous de 5 Mo/s, sauvegardes et imports deviennent risqués |
| 5 | OPcache actif côté web | Sans lui, pages 3 à 5 fois plus lentes |
| 6 | Vraie IP visible derrière Cloudflare, et HTTPS vu par PHP | Sinon le limiteur de connexion bloque tout le monde (section 5) |

## 4. Adaptations à développer (kit « mutualisé »)

1. **Arborescence sans droits root.** La racine web du sous-domaine ne contient que `index.php`, `.htaccess` et les fichiers statiques `_fkc/`, recopiés à chaque déploiement : un `.htaccess` ne sait pas faire d'alias comme nginx. Le code va dans `~/finakop/releases/<version>` avec un lien `current`, la configuration dans `~/finakop/config.php`, les données dans `~/finakop-data`.
2. **`.htaccess` pour LiteSpeed** : tout vers le contrôleur frontal, refus des fichiers cachés et des fichiers PHP autres que `index.php`, cache long sur `_fkc/`, HSTS.
3. **Courriel par SMTP natif**, avec la boîte de l'hébergeur (`smtp.hostinger.com:465`). msmtp n'est pas installable sans root ; `mail()` reste en secours.
4. **Vraie IP derrière Cloudflare.** FinaKop ne lit que `REMOTE_ADDR` (`FKC_Security::clientIp()`). Si LiteSpeed ne la rétablit pas, tous les visiteurs paraissent venir de quelques IP Cloudflare : le limiteur de connexion et le journal de sécurité les confondraient. Le point d'entrée remplacera `REMOTE_ADDR` par `CF-Connecting-IP`, **seulement si la requête vient d'une plage IP officielle de Cloudflare**, pour qu'un tiers ne puisse pas falsifier l'en-tête.
5. **Sauvegardes sans restic.** Script PHP par cron : copie cohérente des bases (`VACUUM INTO`), archive, rétention locale, puis envoi hors site chiffré vers Cloudflare R2 (API S3, 10 Go gratuits). S'y ajoutent les sauvegardes quotidiennes de l'hébergeur.

Tâches cron à déclarer dans le panneau :

| Fréquence | Commande |
| --- | --- |
| Toutes les 5 min | `php ~/finakop/autonome/bin/finakop bpe --quiet` |
| Toutes les heures | `php ~/finakop/autonome/bin/finakop balayage --quiet` |
| Chaque nuit | `php ~/finakop/scripts/sauvegarder.php` |
| Chaque semaine | `php ~/finakop/autonome/bin/finakop verifier` |

## 5. Cloudflare : configuration recommandée (offre gratuite)

| Réglage | Valeur | Raison |
| --- | --- | --- |
| DNS du sous-domaine | Proxifié (nuage orange) | Masque l'hébergement, active WAF et cache |
| Enregistrements MX et courriel | DNS seulement (gris) | Le courriel ne passe pas par le proxy |
| SSL/TLS | **Full (strict)**, TLS 1.2 minimum, « Always Use HTTPS » | Chiffré de bout en bout ; certificat de l'hébergeur ou certificat d'origine Cloudflare |
| HSTS | Activé après la recette | Un HSTS mal posé bloque le site des mois durant |
| Cache | Tout contourner, **sauf `/_fkc/*`** (cache long) | Ne jamais mettre en cache une page de session ; `sw.js` et `manifest.webmanifest` restent servis par l'application |
| Rocket Loader, Email Obfuscation | **Désactivés** | Ils réécrivent le HTML et le JavaScript et cassent la CSP de FinaKop |
| Limitation de débit | `POST /login` : 10 requêtes / 10 s par IP | Seconde barrière devant le limiteur de FinaKop |
| Bot Fight Mode | **Désactivé** | Impossible de l'exclure par chemin en gratuit : il bloquerait `/api/*` et les webhooks Mobile Money (`/webhook/*`) |
| Règle WAF | Challenge géré sauf `/api/*`, `/webhook/*`, pays d'usage autorisés | Protège l'interface sans casser les intégrations |
| Protection de l'origine | En-tête secret ajouté par une règle de transformation Cloudflare, exigé par le point d'entrée | Un mutualisé ne peut pas filtrer par IP : c'est ce qui empêche de contourner Cloudflare |

Limites Cloudflare à connaître :
- **Délai de 100 s** par requête (offres gratuite et Pro). Une opération plus longue (grosse clôture, gros import) répond « 524 », même si le serveur la termine. La répétition à blanc doit chronométrer les opérations lourdes des vrais dossiers.
- **Envoi de 100 Mo maximum** : sans effet, FinaKop limite à 64 Mo.
- Les flux `text/event-stream` (afficheur client) passent.

## 5 bis. Sans Cloudflare

**Possible et stable.** Cloudflare ajoute une couche de protection, il ne conditionne pas la stabilité. Celle-ci dépend de l'hébergement : SQLite en mode WAL, débit disque et limite de 30 requêtes simultanées. Ces points sont vérifiés par le diagnostic dans les deux cas.

| | Sans Cloudflare | Avec Cloudflare |
| --- | --- | --- |
| Vraie IP des visiteurs | Directe : rien à adapter | À rétablir (adaptation 4) |
| Durée maximale d'une requête | Limite de l'hébergeur (`max_execution_time`, réglable) | 100 s |
| Règles de cache à tenir | Aucune | Contourner tout sauf `/_fkc/*` |
| Protection réseau | Pare-feu et anti-DDoS de base de l'hébergeur | WAF, anti-DDoS avancé, origine masquée |
| Adaptations du kit | 4 | 5 |

Protections qui restent actives sans Cloudflare :
- FinaKop : limiteur de tentatives de connexion par IP, jetons CSRF, cookies de session durcis, en-têtes de sécurité, blocage des comptes au mot de passe publié ;
- `.htaccess` : HTTPS forcé, HSTS, fichiers cachés refusés ;
- données hors de la racine web ;
- certificat SSL gratuit de l'hébergeur sur le sous-domaine.

Cloudflare pourra être ajouté plus tard sans modifier le code : le point d'entrée ne rétablit l'IP que pour les requêtes venant des plages officielles de Cloudflare, et ne fait rien sinon.

**PHP 8.4** (disponible sur l'offre) : c'est la version sur laquelle FinaKop 1.875.6 a été testé ici. Choisissez-la pour le site ET vérifiez celle du PHP en SSH, utilisé par les tâches cron : le diagnostic lancé en SSH l'affiche. Si elle diffère, les commandes cron utiliseront le chemin complet du PHP 8.4.

## 6. Licence et nouveau domaine

Le jeton actuel est lié à l'hôte de l'ancien site. Sur `app.finakoperp.com`, il serait **refusé**.

À faire avec le toolkit, depuis le poste hors ligne : émettre un jeton dont le domaine est **`finakoperp.com`**. Il couvre alors tous ses sous-domaines, en contrôle strict comme historique.

Les serveurs de licence et de dépôt peuvent rester sur `kophisgroup.com`. Le dépôt, statique, peut aussi être hébergé sur finakoperp.com.

## 7. Ce qui change par rapport au plan VPS

| | VPS-1 OVH | Business mutualisé + Cloudflare |
| --- | --- | --- |
| Administration système | À votre charge (mises à jour, pare-feu) | Assurée par l'hébergeur |
| Puissance | 4 vCPU, 8 Go dédiés | 2 cœurs, 3 Go partagés, 30 requêtes simultanées |
| Relais temps réel Connect | Oui | Non : interrogation automatique à intervalles (déjà prévue par FinaKop) |
| Protection réseau | Pare-feu, fail2ban | Cloudflare (WAF, anti-DDoS, masquage de l'origine) |
| Sauvegardes | restic hors site | Hébergeur quotidien + script PHP + Cloudflare R2 |
| Limite de durée d'une requête | 300 s | 100 s (Cloudflare) |

## 8. Suite proposée

1. Lancer le diagnostic sur le compte, avant puis après Cloudflare, et transmettre les deux résultats.
2. Développer les 5 adaptations du kit « mutualisé », et corriger la lenteur de la purge du plan comptable.
3. Réémettre la licence pour `finakoperp.com`.
4. Répétition à blanc sur le sous-domaine avec une copie des données, puis bascule (même méthode d'export et de contrôle que le plan VPS).
