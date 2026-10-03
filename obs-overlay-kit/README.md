# OBS Overlay Kit 7 — habillage TV pour OBS Studio

Un pack complet pour habiller vos directs comme à la télévision. Vous pilotez tout en direct
depuis une **régie** (un panneau de contrôle), et les changements apparaissent instantanément
dans OBS, avec des animations d'entrée et de sortie.

- **Aucun fond vert** : les overlays sont des pages web à fond transparent, qu'OBS affiche
  nativement par-dessus vos caméras.
- **Aucune installation compliquée** : il faut seulement [Node.js](https://nodejs.org), et
  aucun module supplémentaire. Tout fonctionne **hors ligne**, polices comprises.
- **Tout est modifiable** depuis la régie : textes, couleurs, logos, émissions.

## Ce que contient le pack

| Élément | Position par défaut | Pour quoi |
|---------|--------------|-----------|
| **Logo de chaîne** + « EN DIRECT » + horloge réelle | haut droite | Carte fond noir / contour blanc (verticale, pivotée ou horizontale) ou logo seul ; lumière sur le contour et reflet toutes les 7 s |
| **Bandeau nom** (lower third) | bas gauche | Nom et fonction d'un invité ou d'un présentateur, avec une liste d'invités enregistrés |
| **Sujet en cours** | haut gauche | « EN CE MOMENT : … », « FLASH : … » |
| **Bandeau défilant** | tout en bas | Infos, annonces, horaires ; plusieurs messages en boucle |
| **Titre en cours** | bas droite | Musique : « Titre - Artiste » avec égaliseur animé |
| **Citation** | bas centre | Phrase marquante, question du jour, slogan |
| **Bible** | bas centre | Verset ou passage, cherché directement dans 3 Bibles intégrées, avec 2e version en parallèle possible |
| **Message du public** | gauche | Commentaire d'un spectateur, saisi à la main ou **reçu en direct du chat YouTube / Facebook** |
| **Score** | haut centre | Sport, jeu, quiz : équipes, points (qui rebondissent), période, chrono |
| **Écrans pleins** | tout l'écran | **Début** avec compte à rebours, **Pause**, **Fin** avec vos réseaux sociaux |

**Chaque élément se place où vous voulez** (voir « Positionner les éléments »). Les éléments
s'écartent automatiquement du bandeau défilant quand il est affiché.

### Plusieurs émissions

La régie gère plusieurs **émissions**, chacune avec son nom, son type, ses 4 couleurs et son
logo. Un clic en haut de la régie change d'émission : tout l'habillage change de couleurs
instantanément. Le pack est livré avec 5 exemples, que vous pouvez modifier, supprimer ou
compléter (« + Nouvelle émission ») :

| Émission | Type |
|----------|------|
| Louange & Musique | Musique & louange |
| Le Grand Débat | Talk-show / débat |
| Le Journal | Information |
| Parole de Vie | Culte / enseignement |
| Sport Live | Sport |

## Licence d'utilisation

Le kit fonctionne **14 jours en essai complet** dès le premier démarrage (petit filigrane
« Version d'essai » en haut de l'écran). Ensuite, sans licence :

- les habillages de base restent utilisables (logo, bandeau nom, sujet, défilant, titre, citation,
  message manuel, écrans pleins), avec un filigrane « Version non activée » au centre de l'image ;
- les fonctions avancées sont verrouillées : **chat en direct**, **Bible**, **pilotage depuis un
  téléphone**, **raccourcis Stream Deck / API**, **émissions multiples**, **score**.

Avec une licence, le filigrane disparaît et les fonctions de votre offre sont débloquées.

**Activer** : bouton **Licence** en haut de la régie, collez la clé (elle commence par `OOK1-`) ou
importez le fichier `.lic` reçu, puis **Activer**. Le **code de ce poste** affiché dans la même
fenêtre est à transmettre au vendeur si votre licence doit être liée à un seul PC.

- La vérification se fait **hors ligne** (signature numérique) : aucune connexion n'est requise
  pendant le direct.
- Certaines licences utilisent l'**activation en ligne** : le kit se déclare au serveur du vendeur
  puis le reconsulte une fois par jour. Sans Internet, il continue de fonctionner 30 jours.
- **Changer de PC** : « Désactiver sur ce PC » libère la place, puis activez la même clé sur le
  nouveau poste.
- La licence est enregistrée dans `data/licence.json`. Le dossier `licence/` contient la clé
  publique du vendeur (`cle-publique.pem`, ne pas modifier) et ses coordonnées (`vendeur.json`).

## 1. Démarrer

1. Double-cliquez sur **`DEMARRER.bat`**. Une fenêtre noire s'ouvre (c'est le serveur des
   overlays) et la régie s'ouvre dans votre navigateur.
2. **Laissez la fenêtre noire ouverte pendant tout le direct.** Si vous la fermez, les
   overlays ne se mettent plus à jour.

Sur macOS / Linux : double-cliquez sur `demarrer.command` (macOS) ou lancez `./demarrer.sh`.
En ligne de commande : `npm start` (ou `node server.mjs`) dans ce dossier.

Au premier démarrage, un **assistant** vous demande le nom de votre chaîne, votre logo et vos
couleurs. Tout reste modifiable ensuite.

## 2. Ajouter les overlays dans OBS (une seule fois)

1. Dans votre scène, cliquez sur **+** (Sources), puis **Navigateur**, et nommez la source
   « Habillage ».
2. Réglez :
   - **URL** : `http://localhost:3333/overlay.html`
   - **Largeur** : `1920`, **Hauteur** : `1080`
   - **décochez** « Arrêter la source quand elle n'est pas visible »
   - **décochez** « Actualiser le navigateur quand la scène devient active »
3. Placez cette source **au-dessus** de vos caméras dans la liste des sources.
4. Pour avoir l'habillage dans plusieurs scènes, faites un clic droit sur la source, puis
   **Copier**, puis dans l'autre scène **Coller (référence)**.

> Votre canevas OBS n'est pas en 1920 × 1080 (par exemple en 1280 × 720) ? Gardez 1920 × 1080
> dans la source, puis faites un clic droit, **Transformer**, **Adapter à l'écran**.

### Des sources séparées (optionnel)

Pour placer un élément dans certaines scènes seulement, ou le mettre derrière une autre
source, créez une source par élément avec `?elements=` :

```
http://localhost:3333/overlay.html?elements=bandeauNom
http://localhost:3333/overlay.html?elements=defilant,logo
http://localhost:3333/overlay.html?elements=ecran
```

La liste complète des liens, avec un bouton « Copier », se trouve en bas de la régie.

## 3. La régie dans OBS (recommandé)

Pour tout piloter sans quitter OBS : menu **Docks**, puis **Docks de navigateur personnalisés**.
Nom : `Régie`, URL : `http://localhost:3333/controle.html`. La régie s'adapte à un panneau étroit.
Le bouton **Aperçu** masque ou affiche la miniature pour gagner de la place.

## 4. Pendant le direct

- **Afficher / Affiché** : chaque carte a son bouton. La carte s'entoure de vert quand
  l'élément est à l'antenne.
- **Modifier un texte** : tapez simplement. Si l'élément est affiché, il sort et revient
  avec le nouveau texte, comme à la télé.
- **Invités** : remplissez le nom et la fonction, puis cliquez sur « + Enregistrer le nom
  actuel ». Pendant l'émission, **▶ Afficher** sur un invité le met à l'antenne en un clic.
- **Masquer après (s)** : l'élément disparaît tout seul après ce délai (0 = reste affiché).
- **Écran plein** : « Début » lance le compte à rebours ; il indique « C'est parti ! » à zéro.
  Pendant un écran plein, le petit logo du coin se retire.
- **Tout masquer** : retire d'un coup tous les habillages, sauf le logo de chaîne.

Les réglages sont **enregistrés automatiquement** dans `data/etat.json` et retrouvés au
prochain démarrage.

## Logo de chaîne

Dans la carte **Logo de chaîne** de la régie :

- **Style** : « Carte » (logo sur un fond, avec un contour) ou « Logo seul ».
- **Disposition** de la carte :
  - **Verticale** : le logo, « EN DIRECT » et l'heure empilés ;
  - **Pivotée 90°** : le logo tourné, qui se lit de bas en haut, dans une carte haute et
    étroite ;
  - **Horizontale** : tout sur une ligne.
- **Fond** : couleur et opacité (noir à 90 % par défaut ; 0 = sans fond).
- **Contour** : couleur (blanc par défaut), opacité, épaisseur (0 = sans contour), arrondi des
  angles.
- **Opacité générale**, pour un logo discret comme à la télévision.
- **Bandeau « EN DIRECT » et heure** :
  - **position** par rapport au logo : dessous, dessus, à droite ou à gauche ;
  - **disposition** : empilés ou côte à côte ;
  - **ordre** : bandeau puis heure, ou l'inverse ;
  - **taille** : de 50 à 150 %.

Animation d'entrée :

1. le contour se dessine ;
2. le fond se remplit ;
3. le logo s'ouvre en iris ;
4. un reflet traverse la carte ;
5. le bandeau et l'heure glissent en place.

Ensuite, toutes les 7 secondes, une lumière fait le tour du contour et un reflet passe sur le
logo. À la sortie, tout se retire dans l'ordre inverse.

## Positionner les éléments

Dans chaque carte de la régie, dépliez **Position et taille** :

- **La grille 3 × 3** choisit le point d'ancrage : haut, milieu ou bas, puis gauche, centre ou
  droite.
- **Décalage horizontal / vertical** : ajustement fin en pixels à partir de ce point.
- **Taille (%)** : agrandir ou réduire l'élément (de 40 à 200 %).
- **Remettre à zéro** : annule les décalages et la taille.

Quand vous survolez une carte, l'élément correspondant est **entouré dans l'aperçu** pour le
repérer facilement. Les animations s'adaptent au côté choisi : un message placé à droite entre
par la droite, un bandeau nom à droite s'aligne à droite, etc.

Le **bandeau défilant** occupe toute la largeur : choisissez simplement **en haut** ou **en bas**.
Le logo de chaîne se règle aussi ici (coin, taille), en plus de sa hauteur.

## Bible

La carte **Bible** de la régie cherche directement dans des Bibles installées sur votre
ordinateur (aucune connexion Internet nécessaire pendant le direct).

**Versions incluses** (domaine public) : **Louis Segond 1910**, **Darby 1885**, **Martin 1744**.

1. Choisissez la **version**, et éventuellement une **2e version** affichée en dessous, en
   parallèle.
2. Tapez dans la recherche puis appuyez sur Entrée :
   - une **référence** : `Jean 3:16`, `Jn 3.16-18`, `1 Co 13:4-7`, `Ps 23`, `Philippiens 4 13`,
     `II Rois 2:11`… Les noms complets et les abréviations usuelles sont reconnus, avec ou sans
     accents ;
   - ou des **mots** : `lumière du monde`, `berger`, `ne crains pas`… Tous les versets
     contenant ces mots s'affichent, avec les mots surlignés et l'expression exacte en premier.
3. Cliquez sur un verset pour le **préparer** (il s'affiche en pointillés, sans passer à
   l'antenne), ou sur **▶** pour l'afficher tout de suite. Pour une référence, **▶ Afficher**
   montre tout le passage.
4. **◀ Précédent / Suivant ▶** avance dans le texte (verset par verset, ou passage par passage),
   en changeant de chapitre automatiquement : idéal pour suivre une lecture.

**Style** : « Carte » (bas de l'écran) ou « Grand texte » (gros caractères centrés, façon
projection d'église). La taille du texte s'adapte automatiquement à la longueur du passage.

### Ajouter d'autres Bibles

```
node scripts/importer-bibles.mjs --liste              (versions disponibles)
node scripts/importer-bibles.mjs crampon kjv          (télécharge et installe)
```

Versions proposées en plus : Crampon 1923 (catholique), King James (anglais). Redémarrez ensuite
le serveur. Les Bibles sont des fichiers JSON dans `data/bibles/`. Les traductions récentes
(Segond 21, Semeur, NEG, Parole de Vie…) sont protégées par le droit d'auteur et ne sont donc
pas fournies.

## Chat en direct YouTube et Facebook

La carte **Chat en direct** de la régie reçoit les commentaires de vos directs en temps réel.
Un clic sur **▶ Afficher** met un commentaire à l'antenne dans « Message du public », avec la
photo de profil, la plateforme et le montant des Super Chats.

### YouTube

Il faut une **clé API YouTube** (gratuite). Le guide pas à pas est dans la régie (« Obtenir une
clé API YouTube ») :

1. Sur **console.cloud.google.com**, créez un projet.
2. Activez **YouTube Data API v3**.
3. Allez dans **Identifiants**, puis **Créer des identifiants**, puis **Clé API**.
4. Dans la régie, collez la clé et l'adresse du direct (`https://www.youtube.com/watch?v=…` ou
   `https://youtube.com/live/…`), puis cliquez sur **Connecter**.

Le direct doit être **en cours**, public ou non répertorié, avec le chat activé. Le quota
gratuit de 10 000 unités par jour permet environ 5 à 6 heures de direct par jour (lecture
toutes les 5 secondes).

### Facebook

L'API de Facebook ne donne accès qu'aux directs publiés depuis une **Page** (pas depuis un
profil personnel). Il faut un **jeton d'accès de la Page**, avec les autorisations
`pages_read_engagement` et `pages_read_user_content`. Il se génère avec l'Explorateur de l'API
Graph de developers.facebook.com, et le guide détaillé est dans la régie. Collez-le avec
l'adresse de la vidéo en direct, puis cliquez sur **Connecter**.

Le jeton de base expire au bout de quelques heures. Le guide explique comment obtenir un jeton
durable.

### Mode automatique et modération

- **Mode automatique** : chaque nouveau commentaire passe à l'antenne à tour de rôle, pendant
  la durée choisie.
- **Mots interdits** et **Ignorer les messages contenant des liens** : les commentaires
  concernés restent visibles (grisés) dans la régie, mais ne passent **jamais** à l'antenne
  automatiquement.
- Sans le mode automatique, rien ne s'affiche sans votre clic.

### Confidentialité

La clé et le jeton sont enregistrés **uniquement sur ce PC**, dans `data/connexions.json`. Ils
ne sont jamais envoyés à l'overlay ni affichés dans la régie. Le bouton « Oublier la clé » /
« Oublier le jeton » les efface. Ne partagez pas ce fichier.

## Piloter depuis un téléphone ou une tablette

1. **Une seule fois** : double-cliquez sur **`AUTORISER-TELEPHONE.bat`** et acceptez la demande
   d'autorisation de Windows. Ce script ouvre le port de la régie dans le pare-feu, et
   uniquement pour votre réseau local (pas depuis Internet).
2. Lancez **`DEMARRER-avec-telephone.bat`** à la place de `DEMARRER.bat`.
3. **Scannez le QR code** avec l'appareil photo du téléphone. Il s'affiche dans la fenêtre
   noire, et aussi dans la régie avec le bouton **📱 Téléphone**. Touchez le lien : la régie
   s'ouvre sur le téléphone.

Le téléphone doit être connecté au **même Wi-Fi** que le PC.

**Ça ne marche toujours pas ?**

| Vérification | Pourquoi |
|--------------|----------|
| Relancer `AUTORISER-TELEPHONE.bat` | Si la fenêtre « Autoriser Node.js » de Windows a été refusée ou fermée, Windows bloque la régie. Le script supprime ce blocage |
| Téléphone sur le **même Wi-Fi**, données mobiles coupées | En 4G/5G, le téléphone ne voit pas le PC |
| Pas de Wi-Fi « invités » | Les réseaux invités isolent les appareils entre eux |
| VPN coupé (PC et téléphone) | Un VPN fait sortir le trafic hors du réseau local |
| Essayer une autre adresse (bouton 📱 Téléphone) | Si le PC a plusieurs cartes réseau, la bonne adresse commence en général par `192.168.` |

> Dans ce mode, toute personne connectée à votre réseau local peut ouvrir la régie. Utilisez-le
> sur un réseau de confiance.

## Stream Deck, Touch Portal, raccourcis

Chaque action a une adresse. Utilisez l'action « Site web » / « Ouvrir une URL »
(en arrière-plan si possible) :

```
http://localhost:3333/api/afficher/bandeauNom
http://localhost:3333/api/masquer/bandeauNom
http://localhost:3333/api/basculer/defilant
http://localhost:3333/api/ecran/debut        (ou pause, fin, aucun)
http://localhost:3333/api/emission/debat     (change d'émission)
http://localhost:3333/api/bible/suivant      (verset / passage suivant)
http://localhost:3333/api/bible/precedent
```

Ces raccourcis nécessitent une licence incluant « Raccourcis Stream Deck / API ».

Éléments : `logo`, `bandeauNom`, `sujet`, `defilant`, `titreEnCours`, `citation`, `bible`,
`message`, `score`.

## Personnaliser

- **Logos** : dans les cartes « Chaîne » et « Émission active », utilisez « Importer un logo ».
  Les fichiers vont dans `public/uploads/`. Préférez des PNG à fond transparent.
- **Couleurs** : 4 couleurs par émission : principale, secondaire, sombre (fonds) et claire.
- **Réglages de départ** : `data/etat-par-defaut.json` (utilisé par « Réinitialiser »).
- **Aller plus loin** : l'apparence de chaque élément est dans `public/overlay.css`, en
  sections commentées (tailles, positions, animations).
- **Sauvegarder / transférer** : copiez `data/etat.json` et `public/uploads/`.

## Dépannage

| Problème | Solution |
|----------|----------|
| Rien ne s'affiche dans OBS | La fenêtre noire est-elle ouverte ? Clic droit sur la source, **Actualiser** |
| La régie indique « serveur non joignable » | Relancez `DEMARRER.bat` |
| 🔒 « fonction non incluse dans votre licence » | Bouton **Licence** : vérifiez votre offre, ou activez une clé qui inclut cette fonction |
| « Licence liée à un autre poste » | Le PC a changé (réinstallation, nouvelle carte mère) : envoyez le nouveau code de poste au vendeur |
| « Clé publique du vendeur absente » | Le fichier `licence/cle-publique.pem` a été supprimé : réinstallez le kit |
| « Adresse déjà utilisée » au démarrage | Le serveur tourne déjà (autre fenêtre) ; sinon changez de port : `set PORT=4000` puis `node server.mjs`, et adaptez les URL |
| L'overlay est trop grand ou trop petit | Largeur et hauteur de la source : 1920 × 1080, puis **Adapter à l'écran** |
| Le défilant saccade | Dans OBS, **Paramètres**, **Vidéo** : 30 ou 60 i/s ; baissez un peu la vitesse |
