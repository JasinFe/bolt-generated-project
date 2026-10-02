# Mister Preacher (MP) — Analyse et mise en place

## 1. Avis sur l'idée

**L'idée est bonne, et elle a une vraie place.** Les outils existants (La Bible Online, YouVersion, Logos, BibleHub, TopBible…) sont soit
très complets mais chers et pensés pour l'anglais (Logos), soit gratuits mais limités à la lecture (YouVersion), soit vieillissants
(La Bible Online). Ce qui manque, surtout en français, c'est **un outil qui va de l'étude jusqu'à la production** :

| Besoin | Outils actuels | Ce que MP apporte |
|---|---|---|
| Lire, chercher, comparer | Bien couvert | Recherche avancée + 31 versions en 16 langues, gratuit |
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

## 2. Ce qui est livré (version 1.3)

Une application web complète, **sans aucune dépendance externe** (Node.js seul), qui fonctionne hors ligne une fois installée.

### Fonctions

- **Lecture** : 88 livres possibles, 31 versions en 16 langues, jusqu'à 4 colonnes en parallèle, sélecteur de livres en grille, titres de section (Martin), notes de Darby,
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
- **Comparer** : un passage dans les 31 versions, filtrées par langue.
- **Thèmes** : 32 thèmes (salut, foi, guérison, combat spirituel, prophétie, famille…) → étude, sermon ou chant en un clic.
- **Mes études** : surlignage en 5 couleurs, notes par verset, collections de passages annotées, export Markdown, impression,
  sauvegarde/restauration de toutes les données.
- **Sermons** : 5 modèles (expositif, thématique, narratif, évangélisation, exhortation/prophétique), idée principale,
  points avec références et aperçu automatique des versets, illustrations, application, appel, prière ; aperçu imprimable et export.
- **Chants & textes** : éditeur de paroles (couplets/refrain/pont), versets d'inspiration, **palette des mots** les plus présents,
  **dictionnaire de rimes** tiré du vocabulaire biblique, recherche d'images bibliques (« aigle », « rocher »…).
- **Évangélisation** : 4 parcours (Chemin des Romains, le Pont, Témoignage, 7 signes de Jean) et 8 objections fréquentes avec réponses bibliques.
- **Verset du jour**, historique de lecture, application installable (manifest PWA), responsive mobile, impression propre.

### Nouveautés de la version 1.3

- **Ouverture automatique** du navigateur au démarrage et lanceur Windows à double-cliquer (`Demarrer Mister Preacher.bat`).
- **Assistant d'étude IA** (Claude Opus 5.5, facultatif) : 8 modes (expliquer, plan de prédication, étude de groupe,
  illustrations, chant, évangélisation, personnages, question libre). Le modèle reçoit le passage dans la version choisie,
  la Darby littérale, les principales références croisées et les fiches Strong : il cite l'Écriture fournie au lieu
  d'inventer. Réponses en continu, questions de suivi, enregistrement en étude ou en sermon. En cas de refus par les filtres
  de sécurité, l'API relance automatiquement la demande sur un autre modèle (paramètre `fallbacks`).
- **Les 66 livres** : auteur, date (avec les débats signalés), thème, verset clé, plan — aussi accessible depuis le lecteur.
- **42 personnages bibliques** : résumé, passages clés, leçons.
- **5 plans de lecture** avec calendrier, progression et rattrapage.
- **Projection** plein écran (versets, chants, texte libre), 5 ambiances, clavier ou clic, écran noir.
- **Image de verset** (canvas) : 7 fonds, 3 formats, 3 polices, téléchargement ou partage.
- **Mémorisation** : 5 niveaux de masquage progressif, écoute du verset.
- **Lecture audio** des chapitres (synthèse vocale du navigateur) avec suivi du verset lu.
- **Mode hors ligne** (service worker) : l'application et les chapitres déjà consultés restent disponibles.

### Versions incluses (31, toutes libres de droits ou sous licence libre)

| Langue | Versions |
|---|---|
| **Français (13)** | **Segond 1910** (par défaut), Darby 1885 (numéros Strong + notes), Ostervald, Martin 1744, Perret-Gentil 1847, Crampon 1923 (deutérocanoniques), Néo-Crampon Libre (français moderne, deutérocanoniques), Vigouroux 1902, Septante Giguet (apocryphes, Hénoch), Synodale 1921 (NT + Psaumes), Oltramare 1874 (NT), Stapfer 1889 (NT), Genève 1669 (NT) |
| **Afrique & Caraïbes (6)** | Lingala, créole haïtien, éwé, twi, haoussa, igbo |
| **Autres langues (8)** | KJV + Apocrypha (Strong), Berean Standard Bible (Strong), Douay-Rheims, Reina-Valera 1909, Luther 1912, Riveduta 1927, Synodale russe, arabe Van Dyck |
| **Langues originales et anciennes (4)** | Hébreu (Codex de Leningrad), grec du NT (Strong), Textus Receptus, Vulgate |

Toute version protégée peut s'y ajouter **légalement** via API.Bible (voir § 7).

## 3. Architecture technique

```
mister-preacher/
├── server/
│   ├── index.js          Serveur HTTP + API JSON (aucune dépendance)
│   └── lib/
│       ├── refs.js       Livres, abréviations FR/EN, analyse des références
│       ├── text.js       Normalisation (accents), moteur de requêtes, surlignage
│       ├── bible.js      Passages, recherche, index Strong, références croisées, rimes, numérotations
│       └── apibible.js   Connecteur facultatif API.Bible (versions sous licence)
├── public/               Interface (HTML/CSS/JS modules, sans compilation)
│   ├── index.html
│   ├── css/style.css     Thème clair/sombre, mobile, impression
│   └── js/
│       ├── app.js        Routeur
│       ├── core.js       API, stockage local, rendu des versets, panneau d'outils
│       └── views/        home, read, search, lexicon, themes, study, sermon, song, evangel, about
├── data/
│   ├── bibles/<VERSION>/<Livre>.json.gz   Un fichier compressé par livre et par version
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

**Choix** : zéro dépendance (sécurité, maintenance, déploiement en une commande) ; **un fichier compressé par livre** :
lire un passage ne charge que ce livre (comparer 29 versions ≈ 50 ms), seule la recherche plein texte charge une version
entière (cache limité à 8 versions, ≈ 260 Mo de mémoire au total) ; données personnelles dans le navigateur (aucun compte
nécessaire, rien n'est envoyé au serveur).

**Numérotation** : les références saisies, les thèmes et les références croisées suivent la numérotation usuelle (Segond, KJV).
Pour les versions à numérotation hébraïque (Darby, Crampon, Néo-Crampon, Lingala, Luther, hébreu), MP convertit
automatiquement Joël 2:28-32 → 3:1-5, Joël 3 → 4 et Malachie 4 → 3:19-24.

## 4. Feuille de route proposée

**Phase 2 — Contenu (1 à 3 mois)**
- ✅ Fait en 1.1 : Segond 1910, Ostervald, 11 autres versions françaises et 6 langues africaines/caribéennes ; connecteur API.Bible.
- Obtenir les accords (via API.Bible) pour Segond 21, Semeur, NBS, Parole de Vie ; ajouter swahili, kinyarwanda, kirundi, malgache, kikongo, tshiluba dès qu'une édition libre complète est disponible.
- Traduire les définitions Strong en français (traduction assistée puis relue).
- Dictionnaire biblique (personnages, lieux, objets), cartes, chronologie.
- Table de correspondance des numérotations.
- Plus de thèmes, de plans de sermon et de parcours d'évangélisation (contenu éditorial modifiable dans `data/`).

**Phase 3 — Comptes et partage (3 à 6 mois)**
- Comptes utilisateurs et synchronisation (PostgreSQL), études et sermons partagés avec une équipe ou une église.
- Mode présentation (projeter versets et paroles pendant le culte).
- Export Word / PDF / diaporama.

**Phase 4 — Assistant intelligent** (✅ première version livrée en 1.3)
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
- Mémoire conseillée : 512 Mo (variables `MP_MAX_VERSIONS` et `MP_MAX_BOOKS` pour ajuster le cache).

## 6. Licences des données

| Ressource | Licence |
|---|---|
| Segond 1910, Darby, Martin, Perret-Gentil, Crampon, Vigouroux, Septante Giguet, Synodale, Oltramare, Stapfer, Genève 1669, Douay-Rheims, Reina-Valera 1909, Luther 1912, Riveduta, Synodale russe, Van Dyck, WLC, Textus Receptus, Vulgate | Domaine public |
| Ostervald (révision 1996) | Déclarée domaine public par open-bibles / bible-api.com |
| Créole haïtien 1985 | Déclarée domaine public par eBible.org (Beblia indique « Société Biblique Haïtienne » : à confirmer avant usage commercial) |
| Néo-Crampon Libre | CC BY-SA 4.0 — Fraternité de Tibériade |
| Lingala, éwé, twi, haoussa, igbo | CC BY-SA 4.0 — Biblica, Inc. (textes identiques mot pour mot à ceux publiés par eBible.org sous cette licence) |
| Berean Standard Bible | Domaine public (CC0) |
| KJV + Apocrypha | Texte domaine public ; balisage Strong CrossWire (GPL) |
| NT grec Statistical Restoration | CC BY 4.0 — Alan Bunning, Center for New Testament Restoration |
| Références croisées | CC BY — OpenBible.info |
| Dictionnaires Strong | CC BY-SA — Open Scriptures |

Les mentions sont affichées dans la page **Versions & sources** de l'application.

## 7. Analyse des sources proposées (version 1.1)

| Source | Ce qu'elle contient | Verdict |
|---|---|---|
| **faith.tools — Free Use Bible API** (bible.helloao.org) | API gratuite qui republie ~1 000 traductions d'eBible.org. Son dépôt GitHub ne contient que 6 Bibles en anglais/arabe/hindi ; le français vient d'eBible. | ✅ Utilisé à la source : le **corpus eBible** (BibleNLP/ebible). Mais ce corpus **réaligne tout sur la numérotation hébraïque et perd des versets** : ~110 pour la Segond (dont **Joël 2:28-32** et **Malachie 4**), ~200 pour le swahili, l'éwé, le yoruba, l'Ostervald… MP ne prend dans eBible que les textes complets (Lingala, Néo-Crampon, Textus Receptus) et va chercher les autres là où ils sont complets. |
| **bible-api.com** | API qui sert les fichiers du dépôt *open-bibles* (≈ 45 Bibles libres). | ✅ Utilisé : Ostervald, Luther 1912, Riveduta 1927. |
| **api.bible** (API.Bible, American Bible Society) | Plus de 2 500 Bibles, dont les versions **protégées** (Segond 21, Semeur, NBS, Parole de Vie…) quand l'éditeur l'autorise. Clé requise, texte non stockable, copyright à afficher, suivi FUMS. | ✅ **Connecteur intégré** (désactivé par défaut) : c'est la seule voie légale pour les versions modernes. Voir ci-dessous. |
| **thiagobodruk/bible** | 90 Bibles en JSON, récupérées par aspiration de sites. | ⚠️ **Mélange de versions libres et protégées** (NIV, ESV, NVI, Reina-Valera 1960, NLT, Schlachter 1951…), présentées comme utilisables. Une seule française : la Bible de l'Épée (licence non vérifiable). Les versions libres qu'il contient existent déjà ailleurs avec une source plus sûre → **non utilisé**. |
| **churchstudio-org/openbible** | Traductions **automatiques** de la KJV par intelligence artificielle (MarianMT), sous licence MIT. | ❌ **Non utilisé** : pas de version française, et une traduction automatique non relue n'est pas fiable pour la prédication ou l'étude. |

**Source complémentaire utilisée** : *Beblia/Holy-Bible-XML-Format*, qui contient la Segond 1910 **complète** avec sa numérotation
française. Ce dépôt contient aussi des versions protégées (Segond 21, NEG, NBS, Semeur, Parole de Vie, Jérusalem…) qui **n'ont
pas été reprises**. Pour les langues africaines, seules les éditions dont le texte est identique, mot pour mot, à l'édition
publiée sous licence libre CC BY-SA par eBible.org ont été retenues (vérification automatique sur plusieurs versets).

### Activer API.Bible (versions sous licence)

1. Créez un compte sur <https://scripture.api.bible> et une clé d'application.
2. À la racine du projet, copiez `.env.example` en `.env` et indiquez la clé :

```
API_BIBLE_KEY=votre-cle
API_BIBLE_URL=https://rest.api.bible
API_BIBLE_LANGS=fra
```

3. Vérifiez l'accès : `npm run apibible` (liste les Bibles françaises accessibles et lit Jean 3:16).
4. Relancez `npm start` : toutes les Bibles des langues choisies sont **ajoutées automatiquement**, avec le symbole ☁
   (lecture, passages, comparaison et recherche via API.Bible). Pour n'en garder que certaines, utilisez
   `API_BIBLE_VERSIONS="S21=<bibleId>|Segond 21|Bible Segond 21|fr"`.

Le fichier `.env` n'est jamais envoyé sur GitHub (il est dans `.gitignore`). Le texte des versions protégées n'est jamais
stocké (cache mémoire de 10 minutes) et le copyright est affiché sous chaque chapitre. Pour un site public, ajoutez le
script de suivi FUMS demandé par API.Bible.
