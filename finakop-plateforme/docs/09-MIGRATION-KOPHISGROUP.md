# Reprise de KOPHI'S GROUP : `finakopcore.kophisgroup.com` (WordPress) → `kophisgroup.finakoperp.com` (plateforme)

Objectif : **toutes** les données (utilisateurs, sociétés, écritures, factures, stocks, documents, paramètres, secrets chiffrés), **sans perte**, avec contrôle chiffré à chaque étape.

L'espace `kophisgroup` existe déjà, vide. L'import le **remplace** (`--remplacer`) : son contenu actuel est mis de côté, pas effacé. En cas d'écart au contrôle, tout est remis en l'état.

## 0. Ce qu'il faut savoir avant de commencer
- Durée : 15 à 30 minutes. Pendant l'export, l'ancien site est **en maintenance** : prévenez les utilisateurs.
- Après l'import, l'espace demandera une **licence émise pour `kophisgroup.finakoperp.com`**, car l'ancienne est liée à l'ancien domaine. Préparez-la avec l'outil LICENCES.
- Les identifiants et mots de passe des utilisateurs restent ceux de l'ancien site.

## 0 bis. Mettre la plateforme en 1.876.1 (option --remplacer)
```bash
PHP=/opt/alt/php84/usr/bin/php bash ~/finakop/current/scripts/deployer.sh ~/finakop-plateforme-1.876.1.zip
```

## 1. Trouver les données de l'ancien site (SSH)
Si l'ancien site est **sur le même compte Hostinger** :
```bash
find ~ -name finakopcore-master.db -not -path "*/finakop-data/*" -not -path "*/finakop-installation/*" 2>/dev/null
find ~/domains -maxdepth 4 -name wp-config.php 2>/dev/null
```
- **Dossier des données** : celui qui contient `finakopcore-master.db`. Il s'agit souvent de `…/wp-content/uploads/finakop-erp-core-data/`, ou d'un dossier hors web si `FKC_DATA_DIR` est défini dans wp-config.php.
- **wp-config.php** : celui du site `finakopcore.kophisgroup.com` (ou `kophisgroup.com`).

S'il est chez **un autre hébergeur**, déposez-y `migration-donnees.php` et `maintenance-wp.php` (ZIP `finakop-export`) et faites les étapes 2 et 3 là-bas. Copiez ensuite le dossier d'export vers Hostinger (étape 4).

## 2. Mettre l'ancien site en maintenance
Copiez `maintenance-wp.php` (ZIP `finakop-export-1.876.0.zip`) dans `wp-content/mu-plugins/` du site WordPress. Créez le dossier `mu-plugins` s'il n'existe pas. FinaKop y répond alors « maintenance » et plus rien n'est écrit.

## 3. Exporter
```bash
/opt/alt/php84/usr/bin/php ~/finakop/current/scripts/migration-donnees.php exporter \
   "<DOSSIER DES DONNÉES>" ~/export-kophisgroup "<CHEMIN>/wp-config.php"
```
- Chaque base est copiée de façon cohérente (`VACUUM INTO`).
- Le manifeste enregistre le nombre de lignes de **chaque table** et l'empreinte SHA-256 de **chaque fichier**.
- La clé de chiffrement est emportée, qu'il s'agisse du fichier `.fkc-secret.key` ou de la constante `FKC_ENCRYPTION_KEY`.
- Un **décalage horaire** éventuel est signalé.

## 4. Importer dans l'espace existant
```bash
fk tenant:importer kophisgroup ~/export-kophisgroup --remplacer --nom="KOPHI'S GROUP SAS"
```
Attendu :
- « Export conforme » (contrôle au départ) ;
- « Importé et contrôlé à l'arrivée » (même contrôle après copie) ;
- le chemin où l'ancien contenu de l'espace a été mis de côté.

## 5. Licence, contrôle, nettoyage
```bash
fk --tenant=kophisgroup licence:installer 'JETON-POUR-kophisgroup.finakoperp.com'
fk --tenant=kophisgroup verifier
```
1. Ouvrez https://kophisgroup.finakoperp.com et connectez-vous avec vos **identifiants habituels** de l'ancien site.
2. Vérifiez quelques écrans : tiers, dernières factures et écritures, un document de la GED.
3. **Effacez** l'export, qui contient la clé de chiffrement :
   ```bash
   rm -rf ~/export-kophisgroup
   ```
4. Gardez l'ancien site en maintenance quelques jours, puis retirez-le.

## En cas de problème
- Si l'export ou l'import signale une anomalie, **rien n'est modifié** : l'ancien site reste intact (seulement en maintenance) et l'espace `kophisgroup` aussi.
- Pour lever la maintenance de l'ancien site : supprimez `wp-content/mu-plugins/maintenance-wp.php`.
