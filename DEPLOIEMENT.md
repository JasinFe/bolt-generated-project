# Mise en ligne sur un hébergement web mutualisé

Ce guide vise une offre de type « Business Web Hosting » : PHP, base MySQL, tâches cron, accès SSH,
sans serveur à administrer. Les noms des menus varient d'un hébergeur à l'autre.

L'application n'a besoin d'aucun programme permanent : **une seule tâche cron** fait tourner le calcul
de nuit, les alertes et l'envoi des notifications.

## Ce qu'il faut côté hébergeur

| Élément | Valeur |
|---|---|
| Version de PHP | **8.3 ou plus** (à choisir dans le panneau) |
| Extensions PHP | `pdo_mysql`, `mbstring`, `openssl`, `ctype`, `fileinfo`, `tokenizer`, `xml`, `curl` — en général déjà actives |
| Base de données | une base MySQL ou MariaDB vide, avec son utilisateur |
| HTTPS | certificat SSL activé sur le domaine (les jetons et les codes ne doivent jamais circuler en clair) |

## 1. Envoyer les fichiers

Utilisez l'archive **`suivi-energie-cie-deploiement.zip`** : elle contient déjà les dépendances (`vendor/`),
Composer n'est donc pas nécessaire sur le serveur.

Décompressez-la, puis envoyez **le contenu** du dossier `suivi-energie-cie/` dans le dossier du domaine
(souvent `public_html`), par le gestionnaire de fichiers ou en SFTP. Vérifiez que les fichiers cachés
`.htaccess` et `.env.example` sont bien arrivés.

Le fichier `.htaccess` placé à la racine renvoie toutes les visites vers `public/` : le reste du projet
(`.env`, `storage/`, `vendor/`) n'est pas accessible depuis Internet.
Si votre panneau permet de choisir le dossier racine du domaine, pointez-le plutôt directement sur `public/` : c'est encore mieux.

## 2. Créer la base de données

Dans le panneau : créez une base MySQL et un utilisateur, et notez le nom de la base, l'utilisateur,
le mot de passe et l'hôte (souvent `localhost`).

## 3. Configurer `.env`

Copiez `.env.example` en `.env` et modifiez ces lignes :

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-domaine.tld

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=nom_de_la_base
DB_USERNAME=utilisateur
DB_PASSWORD=mot_de_passe

MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.votre-hebergeur.tld
MAIL_PORT=465
MAIL_USERNAME=adresse@votre-domaine.tld
MAIL_PASSWORD=mot_de_passe_de_la_boite
MAIL_FROM_ADDRESS="adresse@votre-domaine.tld"

ENERGIE_CANAUX_ALERTES=database,mail
```

Laissez `APP_KEY=` et `ENERGIE_CODE_PEPPER=` vides : l'étape 4 les remplit.

Les réglages `MAIL_*` sont ceux de la boîte e-mail créée chez l'hébergeur ; ils servent au lien
« mot de passe oublié » et aux alertes par e-mail. Laissez `ENERGIE_CANAUX_ALERTES=database` si vous ne voulez pas d'e-mails d'alerte.

## 4. Initialiser

Une seule commande prépare tout : elle génère les deux clés secrètes dans `.env` (`APP_KEY` et
`ENERGIE_CODE_PEPPER`), crée les tables et met la configuration en cache.

**Avec un accès SSH** — placez-vous dans le dossier du projet, puis :

```bash
php artisan energie:installer
```

**Sans accès SSH** — dans le menu « Tâches cron » du panneau, créez une tâche avec la commande ci-dessous,
laissez-la s'exécuter une fois, puis supprimez-la :

```bash
cd /chemin/complet/vers/le/projet && php artisan energie:installer > storage/logs/installation.log 2>&1
```

Le résultat se lit dans `storage/logs/installation.log` (dernière ligne attendue : « Installation terminée. »).

La commande peut être relancée sans risque : une clé déjà présente n'est jamais remplacée.

Si la commande `php` est une version plus ancienne que 8.3, l'hébergeur fournit en général un chemin par
version (ex. `/usr/bin/php8.3` ou `/opt/alt/php83/usr/bin/php`) : utilisez-le à la place de `php`,
ici et dans la tâche cron de l'étape 5.

**Sauvegardez hors du serveur** les lignes `APP_KEY` et `ENERGIE_CODE_PEPPER` de `.env`. Sans `APP_KEY`,
les codes de recharge enregistrés deviennent illisibles ; si le pepper change, les doublons ne sont plus détectés.

Après toute modification de `.env`, relancez `php artisan energie:installer` pour qu'elle soit prise en compte.

## 5. Tâche cron

Dans le panneau, menu « Tâches cron », créez une tâche **toutes les minutes** (`* * * * *`) :

```bash
cd /chemin/complet/vers/le/projet && php artisan schedule:run >> /dev/null 2>&1
```

Elle lance : la file d'attente (notifications) chaque minute, la vérification des capteurs chaque heure,
le recalcul des consommations et les alertes chaque nuit à 01:15.

Si votre offre n'autorise pas une tâche par minute, prenez l'intervalle le plus court proposé : les calculs
se font quand même, les notifications partent seulement avec ce délai.

## 6. Vérifier

1. Ouvrez `https://votre-domaine.tld` : l'écran de connexion s'affiche. Créez votre compte.
2. `https://votre-domaine.tld/.env` doit répondre « introuvable » (404), jamais afficher le fichier.
3. `https://votre-domaine.tld/up` répond 200 : l'application démarre correctement.
4. Demandez un « mot de passe oublié » : l'e-mail doit arriver.
5. En SSH : `php artisan schedule:list` affiche les trois tâches planifiées.

## Mettre à jour plus tard

Remplacez les fichiers par ceux de la nouvelle archive **sans écraser `.env` ni le dossier `storage/`**,
puis relancez `php artisan energie:installer` (SSH ou tâche cron ponctuelle, comme à l'étape 4).

## Pourquoi pas une « application Node.js » ?

Certains hébergeurs proposent aussi d'héberger des applications Node.js. Cette application est écrite en PHP
(Laravel) : elle s'installe comme un site PHP classique, ce que ce guide décrit. La faire tourner sous Node.js
demanderait de la réécrire entièrement, sans bénéfice pour l'utilisateur.

## En cas de problème

| Symptôme | Cause probable |
|---|---|
| Page blanche ou erreur 500 | version de PHP inférieure à 8.3, ou dossiers `storage/` et `bootstrap/cache/` non inscriptibles (droits 755 ou 775) |
| « No application encryption key » | `php artisan energie:installer` n'a pas été lancé |
| Erreur de connexion à la base | hôte, nom, utilisateur ou mot de passe incorrects dans `.env` ; relancer `php artisan energie:installer` après toute modification de `.env` |
| Les alertes n'arrivent jamais | la tâche cron n'est pas active ou pointe sur le mauvais dossier |
| Détail d'une erreur | dernier message dans `storage/logs/laravel.log` |
