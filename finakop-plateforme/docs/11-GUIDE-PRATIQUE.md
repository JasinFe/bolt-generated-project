# FinaKop — Guide pratique : installation, astuces et commandes

Version 1.876.2 · 1er octobre 2026

## 1. Repères

Toutes les commandes se tapent en SSH, sous votre compte Hostinger, avec le PHP 8.4 (celui par défaut, 8.5, n'a pas sodium).

| Élément | Valeur |
| --- | --- |
| Connexion SSH | `ssh -p 65002 u581636075@195.179.239.165` |
| Site vitrine (public) | https://finakoperp.com, fichiers dans `~/domains/finakoperp.com/public_html/` |
| Portail plateforme | https://app.finakoperp.com |
| Espace d'un client | https://kophisgroup.finakoperp.com (un sous-domaine par client) |
| Dossier web de la plateforme | `~/domains/finakoperp.com/public_html/finakop-app` (ne rien y modifier à la main) |
| Code et configuration | `~/finakop` (version active : `~/finakop/current`, config : `~/finakop/config.php`) |
| Données des clients | `~/finakop-data` |
| PHP à utiliser | `/opt/alt/php84/usr/bin/php` |

Raccourci de la console, à mettre une fois pour toutes :

```bash
echo "alias fk='/opt/alt/php84/usr/bin/php ~/finakop/current/bin/finakop'" >> ~/.bashrc && source ~/.bashrc
fk aide
```

Règles d'or :

- Tapez `clear` avant toute capture d'écran du terminal : rien de secret ne doit apparaître.
- Ne déposez **jamais** les clés privées de l'outil LICENCES (`license_private.pem`, `license_depot_private.pem`) sur le serveur. Elles restent sur votre poste.
- Ne communiquez jamais un mot de passe, une clé de sauvegarde ou un jeton, même à un assistant.

## 2. Mettre la plateforme en 1.876.2

Cette mise à jour ajoute la double authentification et rend la plateforme invisible aux moteurs et aux IA. Elle ne demande aucune migration de base, et une sauvegarde de tous les clients est faite automatiquement avant la bascule.

1. Envoyez `finakop-plateforme-1.876.2.zip` dans votre **dossier personnel** (`~/`), pas dans `domains/`. Avec PowerShell depuis votre poste :

    ```powershell
    scp -P 65002 .\finakop-plateforme-1.876.2.zip u581636075@195.179.239.165:~/
    ```

2. En SSH, lancez le script **extrait de l'archive**. Pour cette version seulement, celui déjà installé (1.876.1) ne connaît pas encore les nouvelles règles :

    ```bash
    unzip -o -j ~/finakop-plateforme-1.876.2.zip finakop-plateforme/scripts/deployer.sh -d /tmp/fkdep \
      && PHP=/opt/alt/php84/usr/bin/php bash /tmp/fkdep/deployer.sh ~/finakop-plateforme-1.876.2.zip
    ```

3. Attendu en fin de sortie : `FinaKop Plateforme 1.876.2 en service.`
4. Contrôlez :

    ```bash
    fk plateforme:verifier
    curl -sI https://kophisgroup.finakoperp.com/login | grep -i x-robots
    ```

    La seconde ligne doit afficher `noindex, nofollow, … noai, noimageai`.

5. Connectez-vous en administrateur : FinaKop vous demande d'activer la double authentification (section 5). Ayez votre téléphone sous la main.

**Mises à jour suivantes** (à partir de 1.876.3), la commande habituelle suffit :

```bash
PHP=/opt/alt/php84/usr/bin/php bash ~/finakop/current/scripts/deployer.sh ~/finakop-plateforme-X.Y.Z.zip
```

**Revenir à la version précédente** en cas de souci :

```bash
PHP=/opt/alt/php84/usr/bin/php bash ~/finakop/current/scripts/deployer.sh --retour
```

Astuces :

- Chaque livraison porte un **nouveau numéro** : renvoyer le même numéro ne fait qu'une « réactivation », sans nouveau code.
- Le message `Usage : bash deployer.sh <finakop-plateforme-X.Y.Z.zip>` signifie que le chemin du ZIP est faux : vérifiez avec `ls ~/*.zip`.
- Une fois la mise à jour faite, supprimez le ZIP : `rm ~/finakop-plateforme-1.876.2.zip`.

## 3. Installer le site vitrine finakoperp.com

Le site vitrine est une page statique, publique et indexable. Il ne contient aucun lien vers la plateforme. Ses fichiers vont **directement** dans `public_html`, à côté du dossier `finakop-app`, **sans jamais toucher à ce dossier**.

Résultat attendu dans `domains/finakoperp.com/public_html/` :

```text
public_html/
├── .htaccess          ← celui de la vitrine
├── index.html         ← la page
├── robots.txt
├── sitemap.xml
├── assets/            ← logo, icône
└── finakop-app/       ← la plateforme : NE PAS TOUCHER
```

### Méthode A — Gestionnaire de fichiers hPanel (sans SSH)

1. hPanel → **Sites web** → finakoperp.com → **Gestionnaire de fichiers**.
2. Ouvrez `public_html`.
3. Si un fichier `index.html` ou `default.php` d'Hostinger s'y trouve (page d'attente), supprimez-le. Ne supprimez rien d'autre.
4. S'il existe déjà un `.htaccess` dans `public_html`, renommez-le `.htaccess.ancien` (copie de sécurité).
5. Cliquez **Téléverser** (icône flèche vers le haut) et envoyez `finakop-site-vitrine-1.876.2.zip` dans `public_html`.
6. Clic droit sur le ZIP → **Extraire** → laissez le dossier de destination vide ou mettez `.` pour extraire **dans `public_html` même**, pas dans un sous-dossier.
7. Vérifiez que `index.html`, `robots.txt`, `sitemap.xml` et le dossier `assets` sont bien directement dans `public_html`. S'ils sont dans un sous-dossier (par exemple `finakop-site-vitrine-1.876.2/`), déplacez-les d'un niveau vers le haut.
8. Affichez les fichiers cachés (Réglages du gestionnaire → *Afficher les fichiers cachés*) et vérifiez la présence de `.htaccess`.
9. Supprimez le ZIP de `public_html`.

### Méthode B — SSH (le plus rapide)

Envoyez d'abord le ZIP dans votre dossier personnel (PowerShell, depuis votre poste) :

```powershell
scp -P 65002 .\finakop-site-vitrine-1.876.2.zip u581636075@195.179.239.165:~/
```

Puis, en SSH :

```bash
cd ~/domains/finakoperp.com/public_html \
  && rm -f index.html default.php \
  && { [ -f .htaccess ] && cp .htaccess ~/htaccess-public_html.ancien || true; } \
  && unzip -o ~/finakop-site-vitrine-1.876.2.zip \
  && chmod 644 index.html robots.txt sitemap.xml .htaccess assets/* \
  && chmod 755 assets \
  && ls -la
```

### Adresse de contact

Le bouton « Demander une démo » écrit à `contact@finakoperp.com`. Créez cette boîte dans hPanel → **Emails**. Pour une autre adresse, remplacez-la partout dans `index.html` :

```bash
sed -i 's/contact@finakoperp.com/votre-adresse@finakoperp.com/g' ~/domains/finakoperp.com/public_html/index.html
```

### Contrôles

| Adresse | Attendu |
| --- | --- |
| https://finakoperp.com | La page s'affiche |
| https://www.finakoperp.com | Redirige vers https://finakoperp.com |
| https://finakoperp.com/finakop-app/ | Erreur 404 (la plateforme n'est pas atteignable par là) |
| https://kophisgroup.finakoperp.com | La connexion fonctionne comme avant |

Si la page ne change pas : videz le cache Cloudflare (Caching → Configuration → **Purge Everything**) et rechargez avec Ctrl+F5.

Pour le référencement, déclarez ensuite le site dans Google Search Console (https://search.google.com/search-console) et Bing Webmaster Tools (https://www.bing.com/webmasters), avec le plan du site `https://finakoperp.com/sitemap.xml`. Ne déclarez **jamais** les sous-domaines de la plateforme.

## 4. Cloudflare : bloquer les robots sur la plateforme seulement

Une règle WAF gratuite bloque les robots sur tous les sous-domaines de la plateforme. Elle laisse passer le site vitrine, ainsi que les appels d'API et les webhooks de paiement.

1. Cloudflare → finakoperp.com → **Security → WAF → Custom rules → Create rule**.
2. Nom : `Plateforme invisible`.
3. Cliquez *Edit expression* et collez :

    ```text
    (cf.client.bot or http.user_agent contains "GPTBot" or http.user_agent contains "ClaudeBot" or http.user_agent contains "PerplexityBot" or http.user_agent contains "CCBot" or http.user_agent contains "Bytespider") and not http.host in {"finakoperp.com" "www.finakoperp.com"} and not starts_with(http.request.uri.path, "/api/") and not starts_with(http.request.uri.path, "/webhook")
    ```

4. Action : **Block**, puis *Deploy*.

| Réglage Cloudflare | Valeur conseillée | Pourquoi |
| --- | --- | --- |
| Security → Bots → *Block AI bots* | Off | Il agirait aussi sur la vitrine. La règle ci-dessus protège déjà la plateforme. À activer seulement si vous ne voulez pas non plus que les IA lisent la vitrine |
| *Manage robots.txt* (si proposé) | Off | Chaque espace sert déjà son propre `robots.txt` |
| Bot Fight Mode | Off | En Free, il bloquerait l'API, les webhooks et le scanner mobile |
| SSL/TLS | Full (strict) | Déjà en place |

**Astuce discrétion** : chaque certificat SSL est inscrit dans des registres publics, et un curieux peut y lire les noms des sous-domaines (`kophisgroup.finakoperp.com`…). Le contenu reste protégé par la connexion. Pour ne plus publier chaque nom, utilisez un certificat **joker** `*.finakoperp.com`, par exemple un certificat d'origine Cloudflare.

## 5. Double authentification (2FA)

Avec la 2FA, un mot de passe volé ne suffit plus : il faut aussi le code à 6 chiffres affiché par votre téléphone, qui change toutes les 30 secondes. Elle est **obligatoire pour les administrateurs** et facultative pour les autres comptes.

**Activer (une seule fois)** :

1. Installez une application d'authentification sur votre téléphone : Google Authenticator, Microsoft Authenticator, Authy, 2FAS ou Aegis.
2. Connectez-vous à votre espace. Un administrateur est conduit d'office à l'écran *Double authentification*. Les autres comptes y vont par *Mot de passe → Double authentification*.
3. Dans l'application, choisissez « Ajouter » puis **scannez le QR code**. Sans appareil photo, saisissez la clé affichée sous le QR code.
4. Tapez le code à 6 chiffres affiché par l'application, puis validez.
5. FinaKop affiche **10 codes de secours** (8 caractères, par exemple 3F9A-0B7C). Ils ne seront plus jamais affichés : imprimez-les ou rangez-les dans un coffre de mots de passe. Chacun ne sert qu'une fois.

**Se connecter ensuite** : identifiant et mot de passe comme d'habitude, puis le code du téléphone. Vous avez 5 minutes et 5 essais.

| Situation | Que faire |
| --- | --- |
| Code refusé alors qu'il est juste | Réglez l'heure du téléphone en automatique : le code dépend de l'heure, à 30 secondes près |
| Le même code a déjà servi | Attendez le code suivant : un code accepté ne peut pas resservir |
| Téléphone oublié ou perdu | Tapez un **code de secours** à la place du code à 6 chiffres |
| Nouveau téléphone | Autre compte : connectez-vous, désactivez puis réactivez la 2FA (mot de passe + code) et scannez le nouveau QR code. Administrateur : la 2FA obligatoire ne se désactive pas depuis l'écran ; faites 2fa:desactiver en SSH, puis reconnectez-vous pour scanner le nouveau QR code |
| Téléphone ET codes de secours perdus | Le super administrateur lève la 2FA du compte en SSH (ci-dessous). Le compte la reconfigure à la connexion suivante |

Commandes du super administrateur (SSH) :

```bash
fk --tenant=kophisgroup 2fa:etat                       # qui a la 2FA active, combien de codes de secours restent
fk --tenant=kophisgroup 2fa:desactiver <identifiant>   # lève la 2FA d'un compte (action journalisée)
```

Astuce : enregistrez le même QR code sur **deux** téléphones (le vôtre et un téléphone de secours gardé au bureau). Les deux affichent le même code.

## 6. Commandes de la console

Toutes ces commandes s'utilisent avec l'alias `fk` (section 1). Celles qui concernent un client prennent `--tenant=<id>`, l'identifiant étant le sous-domaine (`kophisgroup`, `newloock`…). `fk aide` affiche la liste complète.

**Clients**

```bash
fk tenant:creer newclient "Nouveau Client SARL" --contact=dg@client.com   # crée newclient.finakoperp.com
fk tenant:lister                        # tous les clients et leur statut
fk tenant:suspendre newclient           # bloque l'accès (impayé…), données intactes
fk tenant:activer newclient             # rétablit l'accès
fk tenant:maintenance newclient         # affiche « maintenance » aux utilisateurs
```

Après `tenant:creer`, déclarez le sous-domaine dans hPanel (Domaines → Sous-domaines, dossier `public_html/finakop-app`), puis installez sa licence.

**Licence et comptes d'un client**

```bash
fk --tenant=newclient licence                          # état de la licence
fk --tenant=newclient licence:installer 'JETON'        # jeton émis avec l'outil LICENCES pour newclient.finakoperp.com
fk --tenant=newclient reemettre-mdp <identifiant>      # nouveau mot de passe provisoire (mot de passe oublié)
fk --tenant=newclient comptes-compromis                # comptes bloqués (mot de passe marqué compromis)
fk --tenant=newclient verifier                         # contrôle de santé de l'espace
fk --tenant=newclient mail:test vous@exemple.com       # test d'envoi de courriel
```

**Sauvegardes et restauration**

```bash
fk tenant:sauvegarder --tous                     # sauvegarde chiffrée de tous les clients
fk sauvegardes:lister kophisgroup                # archives disponibles
fk tenant:restaurer kophisgroup <archive> --vers=kophisgroup-verif   # restauration d'essai, sans risque
fk tenant:restaurer kophisgroup <archive> --confirmer                # restauration réelle (ancien état conservé à part)
fk plateforme:sauvegarder                        # registre des clients + configuration
```

Le cron sauvegarde déjà automatiquement. Gardez **la clé de sauvegarde** hors du serveur (coffre de mots de passe) : sans elle, aucune archive ne peut être relue.

Copie hors site, depuis votre poste (PowerShell) :

```powershell
scp -P 65002 -r u581636075@195.179.239.165:~/finakop-data/sauvegardes .\finakop-sauvegardes
```

**Exploitation**

```bash
fk plateforme:verifier                           # diagnostic complet (à lancer chaque semaine)
fk cloudflare:plages                             # met à jour les IP Cloudflare (chaque trimestre)
tail -5 ~/finakop-data/plateforme/logs/cron.log  # le cron tourne-t-il ?
```

## 7. Astuces au quotidien et bonnes pratiques

**Pour les utilisateurs**

- Chaque entreprise a **son adresse** (`kophisgroup.finakoperp.com`). Ajoutez-la aux favoris : le portail `app.finakoperp.com` ne fait que rediriger.
- Sur téléphone, ouvrez l'adresse dans le navigateur puis *Ajouter à l'écran d'accueil* : vous gardez un raccourci direct vers votre espace.
- Utilisez *Se déconnecter* sur un poste partagé. Sinon, la session expire d'elle-même après une période d'inactivité.
- Mot de passe oublié : demandez à l'administrateur (`reemettre-mdp`). Le nouveau mot de passe est provisoire et doit être changé à la première connexion.
- Plusieurs échecs de connexion de suite bloquent temporairement le compte et l'adresse IP. Attendez quelques minutes avant de réessayer.

**Pour l'administrateur**

- Donnez à chacun **son propre compte** et le rôle le plus faible qui suffit. Ne partagez jamais le compte administrateur.
- Activez la 2FA aussi pour les comptes sensibles (comptable, caissier principal), même si elle n'est pas obligatoire pour eux.
- Supprimez ou désactivez le compte d'une personne qui quitte l'entreprise le jour même.
- Une fois par mois, faites une restauration d'essai (`--vers=…-verif`) pour vérifier que les sauvegardes sont bonnes.
- Ne communiquez jamais les adresses des espaces clients publiquement (site, réseaux sociaux). Seule `finakoperp.com` est publique.

**Sécurité du compte Hostinger**

- Activez la 2FA sur hPanel et sur Cloudflare.
- Préférez une connexion SSH par clé plutôt que par mot de passe.
- Ne déposez rien d'autre dans `finakop-app` : ce dossier est réécrit à chaque mise à jour.
- Effacez les exports et les ZIP après usage (`rm -rf ~/export-*`) : un export contient la clé de chiffrement.

## 8. Dépannage

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| `sodium` ou `extension manquante` en SSH | Le `php` par défaut est la 8.5, sans sodium | Préfixez la commande par `PHP=/opt/alt/php84/usr/bin/php`, ou utilisez l'alias `fk` |
| `Usage : bash deployer.sh …` | Chemin du ZIP faux | `ls ~/*.zip`, puis relancez avec le bon chemin |
| « Version déjà présente : réactivation » | Même numéro que la version installée | Normal si voulu ; sinon, il faut une livraison au numéro supérieur |
| L'espace renvoie vers l'écran *Licence* | Licence absente, expirée ou émise pour une autre adresse | Émettez un jeton pour `<client>.finakoperp.com`, puis `fk --tenant=<client> licence:installer 'JETON'` |
| « Code incorrect » à la 2FA | Heure du téléphone décalée, ou code déjà utilisé | Heure automatique ; attendez le code suivant ; sinon un code de secours |
| Page blanche ou erreur 500 | Erreur PHP | `tail -20 ~/finakop-data/plateforme/logs/php-erreurs.log`, puis `fk plateforme:verifier` |
| Erreur 403 pour un vrai utilisateur | Navigateur ou extension qui se déclare comme robot | Essayez un autre navigateur. Si besoin, `fk config:set securite.bloquer_robots false` (déconseillé) |
| Erreur 503 ou 508 aux heures chargées | Limite de processus de l'offre Business | Surveillez ; au-delà, passez sur un VPS |
| Courriels non reçus | SMTP, SPF ou DKIM | `fk --tenant=<client> mail:test vous@exemple.com`, puis regardez les indésirables |
| La vitrine s'affiche mais pas la plateforme (ou l'inverse) | Fichiers mal placés | La vitrine va dans `public_html/`, la plateforme reste dans `public_html/finakop-app/` ; ne mélangez pas les `.htaccess` |
| La vitrine affiche encore l'ancienne page | Cache Cloudflare ou navigateur | Cloudflare → Caching → Purge Everything, puis Ctrl+F5 |
| Le cron ne tourne pas | Tâche absente ou mauvais PHP | hPanel → Tâches Cron : `~/finakop-cron.sh` toutes les 5 minutes ; vérifiez `cron.log` |

En cas de doute après une mise à jour, revenez en arrière (section 2, `--retour`), puis envoyez la sortie de `fk plateforme:verifier`, **après `clear`** et sans aucun secret visible.
