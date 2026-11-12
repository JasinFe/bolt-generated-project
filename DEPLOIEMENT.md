# Mise en ligne sur Hostinger (Business Web Hosting)

Ce guide installe l'application sur votre domaine, à partir de l'archive
**`suivi-energie-cie-hostinger.zip`**. Comptez 30 à 45 minutes la première fois.

L'application est un site PHP classique : pas besoin de l'option « application Node.js » de Hostinger.

> Les noms des menus de hPanel changent de temps en temps. S'ils ne correspondent pas exactement,
> cherchez le mot-clé indiqué (PHP, Bases de données, Accès SSH, Tâches Cron…) dans la barre de recherche de hPanel.

Dans ce guide, remplacez :

- `votre-domaine.tld` par votre nom de domaine ;
- `u123456789` par votre identifiant Hostinger (affiché dans hPanel → **Avancé → Accès SSH**, et au début des noms de bases de données).

---

## 1. Choisir PHP 8.3 ou plus

hPanel → **Sites web** → *votre domaine* → **Gérer** → **Avancé → Configuration PHP** → choisissez **PHP 8.3** (ou 8.4) → Enregistrer.

## 2. Créer la base de données

hPanel → **Bases de données → Gestion** :

1. Nom de la base : par exemple `energie` (hPanel le transforme en `u123456789_energie`).
2. Nom d'utilisateur : par exemple `energie` (devient `u123456789_energie`).
3. Un mot de passe solide.

Notez les trois valeurs **complètes**, préfixe `u123456789_` compris.

## 3. Créer la boîte e-mail d'envoi

hPanel → **E-mails** → créez une adresse, par exemple `contact@votre-domaine.tld`, avec son mot de passe.
Elle sert aux liens « mot de passe oublié » et aux alertes par e-mail.

## 4. Envoyer les fichiers

hPanel → **Fichiers → Gestionnaire de fichiers** → ouvrez `domains/votre-domaine.tld/public_html` :

1. **Videz** le dossier (Hostinger y place un fichier `default.php` ; supprimez aussi tout ancien site).
2. **Téléversez** `suivi-energie-cie-hostinger.zip` dans `public_html`.
3. Clic droit sur le zip → **Extraire** → dans le dossier actuel (`public_html`, sans sous-dossier).
4. Vérifiez que `public_html` contient **directement** `app`, `public`, `vendor`, `artisan`, `.htaccess`…
   (si tout est dans un sous-dossier, déplacez son contenu d'un niveau vers le haut).
5. Supprimez le zip.

Le fichier `.htaccess` (fichier caché : activez l'affichage des fichiers cachés si vous ne le voyez pas)
renvoie toutes les visites vers le dossier `public/`. Le reste — `.env`, le code, les journaux — n'est jamais accessible depuis Internet.

## 5. Remplir la configuration

Toujours dans `public_html` : clic droit sur **`.env.production.example`** → **Copier** → nommez la copie **`.env`**,
puis ouvrez `.env` (clic droit → Modifier) et remplacez :

| Ligne | Valeur |
|---|---|
| `APP_URL` | `https://votre-domaine.tld` |
| `DB_DATABASE` | nom complet de la base (étape 2), ex. `u123456789_energie` |
| `DB_USERNAME` | nom complet de l'utilisateur (étape 2) |
| `DB_PASSWORD` | mot de passe de la base |
| `MAIL_USERNAME` et `MAIL_FROM_ADDRESS` | l'adresse de l'étape 3 |
| `MAIL_PASSWORD` | le mot de passe de cette boîte |

**Laissez vides** `APP_KEY=` et `ENERGIE_CODE_PEPPER=` : l'étape suivante les remplit. Enregistrez.

Les autres lignes sont déjà réglées pour Hostinger (`DB_HOST=localhost`, `smtp.hostinger.com`, port 465).

## 6. Lancer l'installation

### Avec SSH (recommandé)

hPanel → **Avancé → Accès SSH** → activez-le et notez l'adresse IP, le **port (65002)** et l'identifiant.
Depuis l'invite de commandes de votre ordinateur (Windows : « Terminal » ou « PowerShell ») :

```bash
ssh -p 65002 u123456789@ADRESSE_IP
```

Puis, une fois connecté :

```bash
cd domains/votre-domaine.tld/public_html
```

```bash
/opt/alt/php83/usr/bin/php artisan energie:installer
```

> On écrit le chemin complet de PHP, car la commande `php` seule peut lancer une version plus ancienne
> que celle choisie à l'étape 1. Pour voir les versions disponibles : `ls /opt/alt/ | grep php`
> (prenez `php84` si vous avez choisi PHP 8.4).

La commande vérifie `.env`, génère les clés, crée les tables, puis **affiche la commande cron à copier** pour l'étape 7.
Si un réglage est faux, elle le dit en français : corrigez `.env` et relancez-la.

### Sans SSH

hPanel → **Avancé → Tâches Cron** → type **Personnalisé**, toutes les minutes, avec cette commande :

```bash
/opt/alt/php83/usr/bin/php /home/u123456789/domains/votre-domaine.tld/public_html/artisan energie:installer > /home/u123456789/domains/votre-domaine.tld/public_html/storage/logs/installation.log 2>&1
```

Attendez deux minutes, **supprimez cette tâche**, puis ouvrez `storage/logs/installation.log` dans le gestionnaire de fichiers.
La fin doit être « Installation terminée. » suivie de la commande cron de l'étape 7.

## 7. Créer la tâche cron permanente

hPanel → **Avancé → Tâches Cron** → type **Personnalisé** → **toutes les minutes** (`* * * * *`),
avec la commande affichée par l'installation, de la forme :

```bash
/opt/alt/php83/usr/bin/php /home/u123456789/domains/votre-domaine.tld/public_html/artisan schedule:run >/dev/null 2>&1
```

Cette unique tâche envoie les notifications (chaque minute), vérifie les capteurs (chaque heure) et
recalcule les consommations et les alertes (chaque nuit à 01:15).

## 8. Activer HTTPS

hPanel → **Sécurité → SSL** : le certificat (gratuit) doit être **actif** pour votre domaine ; activez **Forcer HTTPS**.

## 9. Vérifier

1. `https://votre-domaine.tld` affiche l'écran de connexion : **créez votre compte**.
2. `https://votre-domaine.tld/.env` doit afficher **« Forbidden » (403)**, jamais le contenu du fichier.
3. En SSH, depuis `public_html`, envoyez-vous un e-mail de test :

   ```bash
   /opt/alt/php83/usr/bin/php artisan energie:tester-email votre-adresse@exemple.com
   ```

4. En SSH, la liste des tâches planifiées doit en afficher trois :

   ```bash
   /opt/alt/php83/usr/bin/php artisan schedule:list
   ```

5. Sur votre téléphone, ouvrez le site puis « Ajouter à l'écran d'accueil » : l'application a son icône.

## 10. Sauvegarder les clés

Ouvrez `.env` et recopiez dans un endroit sûr, **hors du serveur** (gestionnaire de mots de passe, clé USB…),
les lignes `APP_KEY=` et `ENERGIE_CODE_PEPPER=`.

Sans `APP_KEY`, les codes de recharge enregistrés deviennent **illisibles**, même avec une sauvegarde de la base.
Les sauvegardes automatiques de Hostinger couvrent les fichiers et la base, mais gardez quand même ces deux lignes à part.

---

## Mettre à jour l'application

1. Téléversez la nouvelle archive dans `public_html` et **extrayez-la par-dessus** l'ancienne version.
   L'archive ne contient ni `.env` ni journaux : votre configuration et vos données sont conservées.
2. Relancez l'installation (étape 6, SSH ou tâche cron ponctuelle) : elle met la base à jour
   et ne touche jamais aux clés existantes.

Après **toute** modification de `.env`, relancez aussi l'installation.

## En cas de problème

| Symptôme | Cause probable et solution |
|---|---|
| La page par défaut de Hostinger s'affiche | `default.php` est encore dans `public_html` : supprimez-le |
| « 403 Forbidden » sur toutes les pages | `.htaccess` manquant à la racine de `public_html` (fichier caché) : ré-extrayez l'archive |
| Page « Server Error » ou erreur 500 | PHP inférieur à 8.3 (étape 1), ou installation pas encore lancée (étape 6) |
| « Your Composer dependencies require a PHP version >= 8.3 » en SSH | la commande `php` seule lance une ancienne version : utilisez `/opt/alt/php83/usr/bin/php` |
| L'installation signale des réglages « à remplir » | une valeur de `.env` est restée vide ou à son exemple : corrigez-la (étape 5) |
| « Échec de php artisan migrate » | nom de base, utilisateur ou mot de passe incorrect : recopiez-les depuis hPanel, préfixe `u123456789_` compris |
| « L'e-mail n'a pas pu être envoyé » | réglages `MAIL_*` incorrects : testez avec `energie:tester-email` (étape 9), corrigez `.env`, relancez l'installation |
| Les alertes n'arrivent jamais | tâche cron absente ou mauvais chemin : comparez avec la commande affichée par l'installation |
| Détail d'une erreur | dernier fichier `storage/logs/laravel-AAAA-MM-JJ.log` (gestionnaire de fichiers) |

## Autre hébergeur

La même archive fonctionne chez tout hébergeur PHP 8.3+ avec MySQL : seuls changent les noms des menus,
le chemin de PHP en ligne de commande et les réglages SMTP de `.env`.
