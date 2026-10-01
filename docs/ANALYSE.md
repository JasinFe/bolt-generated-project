# Mister Preacher (MP) — Analyse et mise en place

## 1. Avis sur l'idée

**L'idée est bonne, et elle a une vraie place.** Les outils existants (La Bible Online, YouVersion, Logos, BibleHub, TopBible…) sont soit
très complets mais chers et pensés pour l'anglais (Logos), soit gratuits mais limités à la lecture (YouVersion), soit vieillissants
(La Bible Online). Ce qui manque, surtout en français, c'est **un outil qui va de l'étude jusqu'à la production** :

| Besoin | Outils actuels | Ce que MP apporte |
|---|---|---|
| Lire, chercher, comparer | Bien couvert | Recherche avancée + 8 versions en parallèle, gratuit |
| Comprendre les mots originaux | Logos (payant), BibleHub (anglais) | Strong en français : on voit **comment chaque mot hébreu/grec est traduit dans la Darby** |
| Relier les passages | Notes de bas de page | 340 000 références croisées classées par pertinence + passages parallèles |
| **Préparer un sermon** | Word / papier | Éditeur de plan guidé avec les versets insérés automatiquement |
| **Écrire un chant** | Rien | Versets d'inspiration, palette de mots bibliques, rimes tirées de la Bible |
| **Évangéliser** | Brochures | Parcours prêts à l'emploi + réponses aux objections, imprimables |
| Apocryphes | Rare | Deutérocanoniques, Hénoch, 3–4 Maccabées, Psaumes de Salomon… |

Le positionnement « **de l'étude à la chaire, au studio et à la rue** » est le point fort : c'est ce qui distingue MP.

### Points d'attention

1. **Les droits des traductions.** « Toutes les Bibles » n'est pas possible librement : la Louis Segond 1910 et les anciennes versions
   sont libres, mais **Segond 21, NEG 1979, Semeur, NBS, Parole de Vie, Colombe, TOB, Bible de Jérusalem** appartiennent à des
   éditeurs (Société Biblique de Genève, Biblica, Alliance Biblique Universelle, Cerf…). Il faut une licence ou passer par
   [API.Bible](https://scripture.api.bible) (gratuit pour un usage non commercial, avec conditions d'affichage). L'architecture de MP
   le permet : chaque version est un fichier indépendant.
2. **Les apocryphes** doivent être clairement étiquetés (MP les classe à part : *Deutérocanoniques* et *Apocryphes*), pour que
   l'outil serve tout le monde, protestants comme catholiques et orthodoxes, sans confusion sur le canon.
3. **Les définitions Strong sont en anglais** (domaine public, 1890). MP compense en montrant les traductions françaises réelles
   de chaque mot ; une traduction française des définitions est une étape de la feuille de route.
4. **La numérotation des versets** varie (Joël 3 FR = Joël 2:28 EN ; Psaumes en numérotation grecque dans la Septante/Vulgate).
   Une table de correspondance (« versification mapping ») est prévue en phase 2.

## 2. Ce qui est livré (version 1.0)

Une application web complète, **sans aucune dépendance externe** (Node.js seul), qui fonctionne hors ligne une fois installée.

### Fonctions

- **Lecture** : 88 livres possibles, 8 versions, jusqu'à 4 colonnes en parallèle, titres de section (Martin), notes de Darby,
  taille du texte, thème clair/sombre, navigation au clavier (← →).
- **Recherche** : tous les mots / un des mots / expression exacte / mots partiels / expression régulière ; préfixe `berger*` ;
  exclusion `-mot` ; accents facultatifs ; filtre AT/NT/canon/deutérocanoniques ; répartition par livre cliquable ;
  recherche par numéro Strong (`G26`, `H2617`).
- **Références** : saisie libre en français ou en anglais (`Jn 3.16-18`, `1 Co 13`, `Rom 8:28; 12:1-2`, `premier Pierre 2:9`).
- **Références croisées** : 341 385 liens OpenBible.info, triés par votes, avec le texte affiché.
- **Passages parallèles** : 30 groupes (synoptiques, Samuel/Rois/Chroniques, accomplissements), affichage côte à côte.
- **Mots originaux** : fiche Strong (mot hébreu/grec, translittération, définition, étymologie, mots apparentés) +
  **toutes les façons dont le mot est traduit** dans la Darby et la KJV, avec leurs fréquences ; recherche inverse
  (« quels mots grecs sont traduits par *grâce* ? »).
- **Comparer** : un passage dans les 8 versions (français, anglais, hébreu, grec, latin).
- **Thèmes** : 32 thèmes (salut, foi, guérison, combat spirituel, prophétie, famille…) → étude, sermon ou chant en un clic.
- **Mes études** : surlignage en 5 couleurs, notes par verset, collections de passages annotées, export Markdown, impression,
  sauvegarde/restauration de toutes les données.
- **Sermons** : 5 modèles (expositif, thématique, narratif, évangélisation, exhortation/prophétique), idée principale,
  points avec références et aperçu automatique des versets, illustrations, application, appel, prière ; aperçu imprimable et export.
- **Chants & textes** : éditeur de paroles (couplets/refrain/pont), versets d'inspiration, **palette des mots** les plus présents,
  **dictionnaire de rimes** tiré du vocabulaire biblique, recherche d'images bibliques (« aigle », « rocher »…).
- **Évangélisation** : 4 parcours (Chemin des Romains, le Pont, Témoignage, 7 signes de Jean) et 8 objections fréquentes avec réponses bibliques.
- **Verset du jour**, historique de lecture, application installable (manifest PWA), responsive mobile, impression propre.

### Versions incluses (libres de droits)

| Code | Version | Langue | Particularités |
|---|---|---|---|
| JND | Darby 1885 | FR | **Numéros Strong**, notes du traducteur |
| MAR | David Martin 1744 | FR | Titres de section |
| CRA | Augustin Crampon 1923 | FR | **Deutérocanoniques** (Tobie, Judith, Sagesse, Siracide, Maccabées…) |
| LXX | Septante, trad. Giguet 1872 | FR | **Apocryphes** : Hénoch, 3–4 Maccabées, Psaumes de Salomon, Psaume 151… |
| KJVA | King James 1769 + Apocrypha | EN | Numéros Strong, apocryphes |
| WLC | Codex de Leningrad | Hébreu | Texte massorétique |
| SRGNT | NT grec (Statistical Restoration) | Grec | Numéros Strong, CC BY 4.0 |
| VUL | Vulgate clémentine | Latin | Deutérocanoniques |

## 3. Architecture technique

```
mister-preacher/
├── server/
│   ├── index.js          Serveur HTTP + API JSON (aucune dépendance)
│   └── lib/
│       ├── refs.js       Livres, abréviations FR/EN, analyse des références
│       ├── text.js       Normalisation (accents), moteur de requêtes, surlignage
│       └── bible.js      Passages, recherche, index Strong, références croisées, rimes
├── public/               Interface (HTML/CSS/JS modules, sans compilation)
│   ├── index.html
│   ├── css/style.css     Thème clair/sombre, mobile, impression
│   └── js/
│       ├── app.js        Routeur
│       ├── core.js       API, stockage local, rendu des versets, panneau d'outils
│       └── views/        home, read, search, lexicon, themes, study, sermon, song, evangel, about
├── data/
│   ├── bibles/*.json     Une version par fichier (format compact)
│   ├── books.json        88 livres : codes OSIS, noms FR/EN, abréviations, catégorie
│   ├── crossrefs.json    Références croisées
│   ├── strongs-*.json    Lexiques hébreu et grec
│   ├── themes.json, parallels.json, evangelisation.json   Contenus éditoriaux (modifiables)
│   └── versions.json     Catalogue des versions
├── scripts/build-data.js Import / reconstruction des données
├── test/                 Tests automatisés (node --test)
└── docs/ANALYSE.md
```

**Format compact d'un verset** : `{aimé|G25} le {monde|G2889}` — les accolades portent le numéro Strong, les crochets les mots
ajoutés par le traducteur (italiques). Cela garde les fichiers légers tout en permettant le clic sur chaque mot.

**API** (toutes en `GET` sauf `palette`) :

| Route | Rôle |
|---|---|
| `/api/versions`, `/api/books?v=` | Catalogue |
| `/api/chapter?v=&b=&c=` | Chapitre avec titres et notes |
| `/api/passage?v=&ref=` | Passage(s) depuis une référence libre |
| `/api/lookup?q=` | Point d'entrée unique : référence → passage, sinon recherche |
| `/api/search?q=&v=&mode=&scope=&books=&limit=&offset=` | Recherche |
| `/api/strong?n=G26`, `/api/strong/lookup?w=amour` | Mots originaux |
| `/api/xref?key=John.3.16&v=` | Références croisées |
| `/api/compare?ref=` | Toutes les versions |
| `/api/themes`, `/api/theme?id=`, `/api/parallels?ref=`, `/api/evangelisation`, `/api/votd` | Contenus |
| `/api/rhymes?w=`, `POST /api/palette` | Aide à l'écriture |

**Choix** : zéro dépendance (sécurité, maintenance, déploiement en une commande) ; données en JSON chargées à la demande
(une version ≈ 30 Mo de mémoire) ; recherche en mémoire (≈ 10–20 ms sur 31 000 versets) ; données personnelles dans le
navigateur (aucun compte nécessaire, rien n'est envoyé au serveur).

## 4. Feuille de route proposée

**Phase 2 — Contenu (1 à 3 mois)**
- Ajouter Louis Segond 1910 et Ostervald (libres) ; négocier ou brancher API.Bible pour Segond 21 / Semeur / NBS / Parole de Vie.
- Traduire les définitions Strong en français (traduction assistée puis relue).
- Dictionnaire biblique (personnages, lieux, objets), cartes, chronologie.
- Table de correspondance des numérotations.
- Plus de thèmes, de plans de sermon et de parcours d'évangélisation (contenu éditorial modifiable dans `data/`).

**Phase 3 — Comptes et partage (3 à 6 mois)**
- Comptes utilisateurs et synchronisation (PostgreSQL), études et sermons partagés avec une équipe ou une église.
- Mode présentation (projeter versets et paroles pendant le culte).
- Export Word / PDF / diaporama.

**Phase 4 — Assistant intelligent**
- Assistant IA (ex. Claude d'Anthropic) **ancré dans les textes** : « résume le contexte de Romains 8 », « propose trois
  illustrations pour ce point », « trouve des versets sur la persévérance » — en citant toujours les références réelles de MP.
- Application mobile (l'interface actuelle est déjà installable comme PWA).

**Modèle** : gratuit pour l'essentiel (lecture, recherche, études), éventuellement une offre « Équipe / Église » (partage,
présentation, assistant) pour financer l'hébergement et les licences de traductions.

## 5. Mise en ligne

- **Local** : `npm start` → http://localhost:3000
- **Docker** : `docker build -t mister-preacher . && docker run -p 3000:3000 mister-preacher`
- **Azure App Service** : le dépôt contient déjà `.github/workflows/azure-webapps-node.yml` ; renseigner `AZURE_WEBAPP_NAME` et le
  secret `AZURE_WEBAPP_PUBLISH_PROFILE`, puis pousser sur `main`.
- Tout hébergeur Node.js (Render, Railway, Fly.io, VPS) : commande de démarrage `npm start`, variable `PORT` respectée.
- Mémoire conseillée : 1 Go (toutes les versions chargées).

## 6. Licences des données

| Ressource | Licence |
|---|---|
| Darby, Martin, Crampon, Septante Giguet, WLC, Vulgate | Domaine public |
| KJV + Apocrypha | Texte domaine public ; balisage Strong CrossWire (GPL) |
| NT grec Statistical Restoration | CC BY 4.0 — Alan Bunning, Center for New Testament Restoration |
| Références croisées | CC BY — OpenBible.info |
| Dictionnaires Strong | CC BY-SA — Open Scriptures |
| Conversion des textes | projet scrollmapper/bible_databases |

Les mentions sont affichées dans la page **Versions & sources** de l'application.
