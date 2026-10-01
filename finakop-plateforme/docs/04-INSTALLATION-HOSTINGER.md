# Installation sur Hostinger Business (pas à pas)

Durée : environ 1 h la première fois (hors propagation DNS). Aucune commande ne demande de droits root.

Conventions :
- `uXXXXXXXX` : votre identifiant Hostinger (en SSH : `echo $HOME`) ;
- `IP_HOSTINGER` : l'adresse IP du site (hPanel → Sites web → Gérer → tableau de bord → *Détails du site*).

Ne copiez jamais ces valeurs depuis un exemple : relevez-les dans **votre** hPanel.

## 0. Ce qu'il vous faut

| Élément | Où |
| --- | --- |
| `finakop-plateforme-1.876.0.zip` | Application à installer |
| `finakop-export-1.876.0.zip` | Outils pour l'ancien site WordPress (diagnostic, export) |
| `finakop-erp-core-1.876.0.zip` | Mise à jour de l'extension de l'ancien site, **avant** l'export (facultatif mais recommandé) |
| Votre outil LICENCES (poste local) | Pour émettre une licence par sous-domaine. **Les clés privées ne vont jamais sur le serveur.** |
| Un gestionnaire de mots de passe | Pour la clé de sauvegarde, le mot de passe SMTP et le secret Cloudflare |

## 1. Préparer l'hébergement (hPanel)

1. **Site web** : `finakoperp.com` doit être ajouté comme site (hPanel → Sites web). Son dossier est `~/domains/finakoperp.com/public_html`.
2. **PHP** : hPanel → Sites web → Gérer → Avancé → *Configuration PHP* :
   - **Version : 8.4** (8.1 minimum ; testé en 8.3 et 8.4) ;
   - **Extensions** à cocher si elles ne le sont pas : `pdo_sqlite`, `sodium`, `openssl`, `mbstring`, `fileinfo`, `zip`, `iconv`, `curl`, `phar`, `zlib` ;
   - **Options** : `memory_limit` 256M, `max_execution_time` 120, `upload_max_filesize` 32M, `post_max_size` 40M, `display_errors` Off.
3. **SSH** : hPanel → Avancé → *Accès SSH* → Activer. Notez l'hôte, le port (souvent 65002) et l'utilisateur. Ajoutez une clé SSH plutôt qu'un mot de passe si possible.
4. **CDN Hostinger** : si vous utilisez Cloudflare, **désactivez** le CDN de Hostinger (hPanel → Performance → CDN). Deux CDN en cascade compliquent le cache et l'IP réelle.

## 2. Diagnostic (5 minutes, recommandé)

Il vérifie sur **votre** compte ce qu'aucune fiche commerciale ne garantit : SQLite en mode WAL, débit disque, sorties réseau, fonctions désactivées.

1. Ouvrez `diagnostic-hebergement.php` (dans `finakop-export-1.876.0.zip`) et remplacez `CHANGEZ-MOI` par un jeton.
2. Déposez-le dans `public_html/`, puis ouvrez `https://finakoperp.com/diagnostic-hebergement.php?jeton=<jeton>`.
3. En SSH, lancez aussi `php diagnostic-hebergement.php`.
4. **Supprimez le fichier** ensuite.

## 3. Envoyer l'archive et installer

Depuis votre poste :

```bash
scp -P 65002 finakop-plateforme-1.876.0.zip uXXXXXXXX@IP_HOSTINGER:~/
ssh -p 65002 uXXXXXXXX@IP_HOSTINGER
```

Vous pouvez aussi passer par le gestionnaire de fichiers de hPanel. Déposez l'archive dans le dossier personnel, **pas** dans `public_html`.

Sur le serveur :

```bash
php -v                                   # doit afficher 8.4 (sinon : section 7, chemin de PHP)
unzip -q finakop-plateforme-1.876.0.zip -d ~/finakop-installation
bash ~/finakop-installation/finakop-plateforme/scripts/installer.sh \
     ~/finakop-plateforme-1.876.0.zip \
     --web=$HOME/domains/finakoperp.com/public_html/finakop-app
```

Le script :
- crée `~/finakop` (code et configuration) et `~/finakop-data` (données, **hors** de `public_html`) ;
- écrit `~/finakop/config.php` (droits 0600) avec une **clé de sauvegarde générée**. Elle s'affiche **une seule fois** : copiez-la dans votre gestionnaire de mots de passe ;
- publie le dossier web `public_html/finakop-app/`, qui contient uniquement `index.php`, `.htaccess`, `robots.txt` et les fichiers statiques `_fkc/`.

Arborescence obtenue :

```text
~/finakop/config.php              configuration et secrets (0600)
~/finakop/releases/1.876.0/       code
~/finakop/current → releases/1.876.0
~/finakop-data/                   plateforme.db, clients, sessions, sauvegardes, journaux (0750)
~/domains/finakoperp.com/public_html/finakop-app/   seul dossier exposé au web
```

## 4. Configurer

```bash
nano ~/finakop/config.php
```

| Clé | À renseigner |
| --- | --- |
| `donnees` | Déjà rempli par l'installateur (`/home/uXXXXXXXX/finakop-data`) |
| `admin_email` | Votre adresse d'administration |
| `smtp.mot_de_passe` | Mot de passe de la boîte `no-reply@finakoperp.com` (section 6) |
| `fuseau` | `UTC` par défaut. Pour un import, gardez celui de l'ancien serveur si l'export le signale |
| `cloudflare.actif` | `true` si le domaine passe par Cloudflare, `false` sinon |
| `cloudflare.secret_origine` | **Laisser vide** jusqu'à l'étape Cloudflare (doc 05), sinon tout est refusé |
| `environnement` | `production` (toujours, sur ce site) |
| `temps_reel.sse` | `false` sur Hostinger (défaut). L'afficheur client et le scanner interrogent le serveur toutes les 0,7 s au lieu de garder un processus ouvert. `true` sur un VPS |

Les secrets peuvent aussi venir de variables d'environnement (`FINAKOP_SMTP_MOT_DE_PASSE`, `FINAKOP_CLOUDFLARE_SECRET`, `FINAKOP_SAUVEGARDE_CLE`), si vous préférez ne pas les écrire dans le fichier.

Contrôle :

```bash
php ~/finakop/current/bin/finakop plateforme:verifier
```

Tant qu'aucun client n'existe et que Cloudflare n'est pas configuré, deux points « [!!] » sont normaux.

## 5. Sous-domaines (hPanel)

Hostinger Business **ne gère pas de sous-domaine joker** côté site : chaque sous-domaine se déclare dans hPanel. Ils pointent tous vers le **même** dossier, c'est donc rapide.

hPanel → Sites web → Gérer → Domaines → *Sous-domaines* → Créer :

| Sous-domaine | Dossier personnalisé |
| --- | --- |
| `app` | `public_html/finakop-app` |
| `newloock` (puis un par client) | `public_html/finakop-app` |

Cochez « Dossier personnalisé » et saisissez exactement `public_html/finakop-app`. Si hPanel propose `public_html/newloock`, corrigez : chaque client a **son** sous-domaine mais **le même** dossier. FinaKop distingue les clients par le nom d'hôte.

**Certificats** : hPanel → Sécurité → SSL → installez le certificat gratuit pour `app` et pour chaque client. Il faut le faire même derrière Cloudflare, car le mode *Full (strict)* exige un certificat valide sur l'origine. Si l'émission échoue alors que Cloudflare proxifie déjà le nom, passez temporairement cet enregistrement en « DNS seulement » (nuage gris), émettez le certificat, puis reproxifiez.

## 6. Courriels

1. hPanel → E-mails → créez `no-reply@finakoperp.com`.
2. Reportez son mot de passe dans `smtp.mot_de_passe`. Les valeurs par défaut sont `smtp.hostinger.com`, port 465, SSL.
3. Publiez SPF, DKIM et DMARC : hPanel → E-mails → *Paramètres DNS / Authentification*. Si le DNS est chez Cloudflare, recopiez-y ces enregistrements en « DNS seulement » (doc 05).
4. Testez :

```bash
php ~/finakop/current/bin/finakop --tenant=newloock mail:test vous@exemple.com
```

FinaKop n'envoie **jamais** de mot de passe par courriel. Un mot de passe initial s'affiche une seule fois en console et doit être changé à la première connexion.

## 7. Tâche cron (une seule)

hPanel → Avancé → *Tâches cron* → Personnalisé, **toutes les 5 minutes** (`*/5 * * * *`) :

```text
/usr/bin/php /home/uXXXXXXXX/finakop/current/cron/worker.php
```

- **Chemin de PHP** : la version de PHP des tâches cron peut différer de celle du site. En SSH, `command -v php` et `php -v` donnent le bon binaire. S'il n'est pas en 8.4, utilisez le chemin complet du PHP 8.4 indiqué par Hostinger (souvent de la forme `/opt/alt/php84/usr/bin/php`). Vérifiez-le dans votre compte.
- **Ce que fait la tâche** : un sous-processus par client actif, avec un délai maximal par client (180 s) et un budget par passage (240 s). Un verrou empêche deux passages simultanés. Elle traite la file différée et le balayage, purge les sessions et lance la **sauvegarde quotidienne** de chaque client et de la plateforme.
- **Contrôle** : `tail ~/finakop-data/plateforme/logs/cron.log`.
- **Si `proc_open` est désactivé** (c'est le cas constaté chez Hostinger Business) : utilisez `worker.sh`, qui traite les clients l'un après l'autre avec un délai maximal par client. Créez un petit lanceur qui fixe le PHP 8.4 :

```bash
cat > ~/finakop-cron.sh <<'EOF2'
#!/bin/sh
export PHP=/opt/alt/php84/usr/bin/php
sh /home/uXXXXXXXX/finakop/current/cron/worker.sh >> /home/uXXXXXXXX/finakop-data/plateforme/logs/cron-shell.log 2>&1
EOF2
chmod 700 ~/finakop-cron.sh
sh ~/finakop-cron.sh && tail ~/finakop-data/plateforme/logs/cron.log
```

  Puis, dans hPanel, déclarez **deux** tâches cron :
  - toutes les 5 minutes : `/bin/sh /home/uXXXXXXXX/finakop-cron.sh` ;
  - une fois par jour, par exemple à 2 h 30 : `/opt/alt/php84/usr/bin/php /home/uXXXXXXXX/finakop/current/bin/finakop plateforme:sauvegarder`. Dans ce mode, la sauvegarde de la plateforme ne passe pas par `worker.php`.
- **PHP de la ligne de commande** : chez Hostinger, `php` peut être une autre version que celle du site (8.5 constaté). Utilisez explicitement `/opt/alt/php84/usr/bin/php`. Pour la console : `alias fk='/opt/alt/php84/usr/bin/php ~/finakop/current/bin/finakop'` dans `~/.bashrc`.

## 8. Créer un client

```bash
php ~/finakop/current/bin/finakop tenant:creer newloock "New Loock" --contact=contact@newloock.com
```

La commande affiche l'adresse, l'identifiant `admin` et un **mot de passe à usage unique**, dont le changement est imposé à la première connexion. Transmettez-le par un canal sûr.

Ce qu'elle fait :
- crée l'espace isolé (registre, base de la société, clé de chiffrement, sessions) ;
- crée le compte administrateur.

Les packs sont activés par la licence (section 9) ; les paramètres se règlent dans l'espace.

Règles d'identifiant :
- 3 à 40 caractères `a-z`, `0-9` et tiret intérieur ;
- pas de `--`, pas d'accent ;
- noms réservés refusés : www, app, api, admin, license, mail, support, status, cdn, static, staging…

## 9. Licence de chaque client

1. Sur **votre poste**, avec l'outil LICENCES (générateur), émettez un jeton dont le **domaine** est le sous-domaine exact du client (`newloock.finakoperp.com`), avec l'édition et les packs souscrits.
2. Installez-le :

```bash
php ~/finakop/current/bin/finakop --tenant=newloock licence:installer '<jeton>'
php ~/finakop/current/bin/finakop --tenant=newloock licence        # contrôle
```

L'administrateur du client peut aussi le coller dans l'écran *Licence* de son espace.

Le contrôle **strict** du domaine est forcé : un jeton émis pour `newloock` est refusé sur `xnewloock` comme sur tout autre client.

## 10. Reprendre un client depuis l'ancien site WordPress

Objectif : **aucune perte**. Chaque étape est contrôlée par comptage des lignes de chaque table et par empreinte SHA-256 de chaque fichier.

Sur l'**ancien** site :

1. *(Recommandé)* Mettez à jour l'extension avec `finakop-erp-core-1.876.0.zip`, puis ouvrez FinaKop une fois. L'extension enregistre l'empreinte de la clé de chiffrement.
2. Mettez FinaKop en maintenance : copiez `maintenance-wp.php` dans `wp-content/mu-plugins/`. Plus aucune écriture n'est possible.
3. Exportez, en indiquant `wp-config.php` pour que la clé `FKC_ENCRYPTION_KEY` soit reprise si elle y est définie :

```bash
php migration-donnees.php exporter <dossier-de-données-FinaKop> ~/export-newloock <chemin>/wp-config.php
```

Le dossier de données est en général `wp-content/uploads/finakop-erp-core-data`.

Transfert vers Hostinger :

```bash
tar czf export-newloock.tgz -C ~ export-newloock
scp -P 65002 export-newloock.tgz uXXXXXXXX@IP_HOSTINGER:~/
```

Sur Hostinger :

```bash
tar xzf export-newloock.tgz
php ~/finakop/current/bin/finakop tenant:importer newloock ~/export-newloock --nom="New Loock"
```

L'import :
- contrôle l'export **avant** de copier, puis recontrôle **après** ;
- refuse tout écart, y compris une clé de chiffrement manquante ;
- crée le client, toujours sur un identifiant non utilisé.

Ensuite :
1. Émettez la licence du nouveau domaine (section 9). L'ancien jeton reste lié à l'ancien domaine.
2. Ouvrez `https://newloock.finakoperp.com`, connectez-vous avec vos identifiants habituels et vérifiez quelques écrans et documents.
3. **Effacez** `~/export-newloock`, l'archive et le fichier `A-REPORTER-DANS-config.php.txt` : ils contiennent la clé.
4. Gardez l'ancien site en maintenance quelques jours, puis retirez-le.

## 11. Vérifier

```bash
php ~/finakop/current/bin/finakop plateforme:verifier
php ~/finakop/current/bin/finakop --tenant=newloock verifier
curl -sI https://newloock.finakoperp.com/login | grep -iE 'HTTP/|strict-transport|x-robots|cache-control'
```

Attendu :
- tous les points `[OK]` ;
- `HTTP/2 200` ;
- `Strict-Transport-Security` ;
- `X-Robots-Tag: noindex…` ;
- `Cache-Control: no-store`.

## 12. Cloudflare

Suivez **docs/05-CLOUDFLARE-DNS.md**. L'application fonctionne aussi **sans** Cloudflare : laissez `cloudflare.actif = false`.

## 13. Mettre à jour, revenir en arrière

```bash
scp -P 65002 finakop-plateforme-X.Y.Z.zip uXXXXXXXX@IP_HOSTINGER:~/
bash ~/finakop/current/scripts/deployer.sh ~/finakop-plateforme-X.Y.Z.zip
```

Le déploiement :
- sauvegarde tous les clients **avant** de basculer ;
- installe la nouvelle version à côté de l'ancienne, puis bascule d'un coup ;
- garde les 5 dernières versions.

Retour arrière : `bash ~/finakop/current/scripts/deployer.sh --retour`. Si la version abandonnée avait déjà migré des bases, restaurez la sauvegarde prise avant son déploiement.

## 14. Recette (staging), facultatif

Voir docs/02-ARCHITECTURE.md §7.5 :

```bash
FINAKOP_RACINE=$HOME/finakop-staging FINAKOP_DONNEES=$HOME/finakop-staging-data \
  bash ~/finakop-installation/finakop-plateforme/scripts/installer.sh ~/finakop-plateforme-1.876.0.zip \
  --web=$HOME/domains/finakoperp.com/public_html/finakop-staging
php ~/finakop-staging/current/bin/finakop config:set environnement staging
php ~/finakop-staging/current/bin/finakop config:set mode_tenant chemin
php ~/finakop-staging/current/bin/finakop config:set hote_portail staging.finakoperp.com
```

Créez ensuite le sous-domaine `staging` vers `public_html/finakop-staging`, ajoutez une seconde tâche cron (`~/finakop-staging/current/cron/worker.php`) et protégez l'accès par Cloudflare Access.
