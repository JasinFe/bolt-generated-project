# Gestionnaire de licences — OBS Overlay Kit

Générez et suivez les licences d'utilisation d'OBS Overlay Kit : clés signées, clients, offres
et tarifs, tableau de bord avec graphiques, activation en ligne et révocation à distance.

- **Aucune dépendance** : il faut seulement [Node.js](https://nodejs.org) 18 ou plus récent.
- Fonctionne **hors ligne** sur votre PC ; peut aussi être hébergé sur un serveur pour
  l'activation en ligne.
- Protégé par un **mot de passe administrateur**.

## Démarrer

1. Double-cliquez sur **`DEMARRER.bat`** (Windows) ou `demarrer.command` (macOS) / `./demarrer.sh`
   (Linux). Le tableau de bord s'ouvre sur `http://localhost:4444`.
2. Au premier lancement : choisissez votre **mot de passe**, votre nom de vendeur et votre devise.
3. Allez dans **Paramètres › Clé de signature** et vérifiez que l'empreinte affichée est la même
   que celle du kit que vous distribuez (voir « Clés » ci-dessous).

## Comment fonctionnent les licences

Chaque licence est une clé du type `OOK1-…` qui contient, en clair, le titulaire, l'offre, les
fonctions, le nombre de postes et la date d'expiration, **signés** avec votre clé privée
(Ed25519). Le kit vérifie la signature avec la clé publique : impossible de modifier une date
ou d'ajouter une fonction sans invalider la clé, et impossible de fabriquer une clé sans votre
clé privée.

| Mode | Fonctionnement | Avantages |
|------|----------------|-----------|
| **Hors ligne** (par défaut) | Le kit vérifie seulement la signature et les dates | Rien à héberger, fonctionne sans Internet |
| **En ligne** (case « Activation en ligne ») | Le kit se déclare au serveur, puis le reconsulte chaque jour | Suivi des postes installés, limite de PC respectée, **révocation à distance**, statistiques systèmes / versions |

Une clé hors ligne ne peut pas être révoquée à distance : elle reste valable jusqu'à son
expiration. Pour les clients à risque, préférez des licences annuelles ou l'activation en ligne.

## Le tableau de bord

- **Chiffres clés** : chiffre d'affaires (avec évolution vs période précédente), nouvelles
  licences, licences en cours, postes actifs, licences à renouveler, panier moyen, paiements en
  attente.
- **Graphiques** : histogramme des ventes par offre, courbe du chiffre d'affaires, répartition
  par offre (anneau), statuts, échéances des 12 prochains mois, meilleurs clients, systèmes et
  versions installés, pays, fonctions les plus vendues, carte d'activité quotidienne.
- Filtres par **période** (30 j, 90 j, 12 mois, tout) et par **offre** ; chaque graphique a une
  **vue tableau** et des info-bulles. Thème clair / sombre, utilisable sur téléphone.

## Les autres écrans

- **Licences** : recherche, filtres (statut, offre, paiement), tri, pagination, actions groupées
  (prolonger, suspendre, révoquer, exporter).
- **Fiche licence** : copier la clé, fichier `.lic`, e-mail pré-rempli, **certificat imprimable**
  (PDF), prolonger / renouveler (avec montant encaissé), modifier (nouvelle clé émise),
  dupliquer, suspendre, révoquer, postes activés (libérer un poste), historique.
- **Nouvelle licence** : choix de l'offre, client (auto-complétion des clients existants), date
  d'expiration, postes, liaison à un PC (code machine), fonctions, paiement (Mobile Money,
  virement…), étiquettes, notes, **génération en lot** (jusqu'à 500 clés).
- **Clients** : regroupés par e-mail, total encaissé, licences en cours, création rapide.
- **Offres et tarifs** : prix, durée, postes, fonctions incluses ; ordre et visibilité.
- **Vérifier une clé** : authenticité et contenu d'une clé envoyée par un client.
- **Journal** : toutes les opérations (création, activation, révocation, connexions…).
- **Paramètres** : vendeur, devise, modèle d'e-mail, serveur d'activation, jeton d'API, clés,
  sauvegardes, import / export CSV et JSON, mot de passe.

## Clés de signature (important)

- `data/cle-privee.pem` : **votre secret**. Ne la partagez jamais, ne la mettez pas dans le kit,
  ne la publiez pas sur GitHub. Sauvegardez-la (clé USB, coffre-fort de mots de passe) : sans
  elle, impossible d'émettre ou de renouveler des licences.
- `data/cle-publique.pem` : à placer dans le kit, dans `obs-overlay-kit/licence/cle-publique.pem`
  (bouton « Télécharger cle-publique.pem » dans Paramètres). Faites de même avec `vendeur.json`
  pour afficher vos coordonnées dans la régie du kit.

Le pack livré contient déjà une paire de clés : le kit fourni avec est configuré avec la clé
publique correspondante. Si vous supprimez `data/cle-privee.pem`, une nouvelle paire est créée
au démarrage et il faut alors redistribuer le kit avec la nouvelle clé publique.

## Activation en ligne (facultatif)

1. Installez ce dossier sur un serveur accessible depuis Internet (VPS, Raspberry Pi…).
2. Lancez `node server.mjs --public` derrière un reverse proxy **HTTPS** (Caddy, Nginx). Avec un
   proxy, ajoutez `TRUST_PROXY=1` pour que les adresses des clients soient correctes.
3. Dans **Paramètres › Activation en ligne**, indiquez l'adresse publique
   (ex. `https://licences.mondomaine.com`).
4. Cochez « Activation en ligne » sur les licences concernées.

Seules les routes `/api/public/activer`, `/api/public/verifier` et `/api/public/desactiver`
sont accessibles sans mot de passe (limitées à 60 requêtes par minute et par adresse).

## Automatiser (boutique en ligne, paiement)

Générez un **jeton d'API** dans Paramètres, puis :

```
set OLM_JETON=olm_…          (Windows)        export OLM_JETON=olm_…   (macOS / Linux)
node scripts/creer-licence.mjs --offre createur --nom "Église Bethel" --email contact@bethel.org --pays "RD Congo"
```

Options : `--jours`, `--postes`, `--machine`, `--prix`, `--paiement paye|en-attente|offert`,
`--reference`, `--quantite`, `--en-ligne`, `--lic <dossier>` (écrit les fichiers .lic), `--json`.

Ou directement en HTTP : `POST /api/licences` avec l'en-tête `Authorization: Bearer <jeton>` et
un corps JSON `{"offre": "createur", "client": {"nom": "…", "email": "…"}}`. Le jeton ne permet
que de créer et lire des licences.

## Données et sauvegardes

- Tout est dans `data/base.json`. Une copie est faite chaque jour dans `data/sauvegardes/`
  (30 jours conservés).
- Paramètres › Données : export **CSV** (Excel), sauvegarde **JSON** complète, import.
- Pour changer de PC : copiez tout le dossier `data/`.

## Limites à connaître

Comme tout logiciel distribué avec son code source (JavaScript), le kit peut être modifié par
une personne compétente pour contourner la vérification. La licence décourage la copie et le
partage de clés, et l'activation en ligne permet de repérer et bloquer les abus ; elle ne rend
pas le logiciel inviolable. Pour aller plus loin : distribuer le kit sous forme d'exécutable
(Node.js SEA / `pkg`) et obscurcir le code.
