# Mister Preacher (MP)

**Plateforme d'étude biblique** pour prédicateurs, prophètes, pasteurs, lecteurs et artistes : recherche, références croisées,
mots originaux (hébreu / grec), comparaison des versions, études annotées, préparation de sermons, écriture de chants et
évangélisation.

## Démarrage rapide

Il faut [Node.js](https://nodejs.org) 18 ou plus récent.

- **Windows** : double-cliquez sur **`Demarrer Mister Preacher.bat`**.
- **macOS / Linux** : lancez `demarrer-mister-preacher.command`.
- **Ou en ligne de commande** : `npm start`.

Le navigateur s'ouvre automatiquement sur http://localhost:3000 (désactivable avec `MP_NO_OPEN=1` dans `.env`).
Si l'application tourne déjà, relancer le fichier ouvre simplement la page.

Tests : `npm test` · Mode développement (rechargement auto) : `npm run dev`

## Ce que contient MP

- **31 versions en 16 langues** : 13 en français (Segond 1910, Darby avec numéros Strong, Ostervald, Martin, Crampon,
  Néo-Crampon Libre, Vigouroux, Septante avec apocryphes et Hénoch…), lingala, créole haïtien, éwé, twi, haoussa, igbo,
  anglais, espagnol, allemand, italien, russe, arabe, hébreu, grec et latin — soit 88 livres possibles.
- **Versions modernes protégées** (Segond 21, Semeur, NBS…) affichables légalement via le connecteur API.Bible.
- **Recherche** de mots, d'expressions exactes, de préfixes (`grâce*`), avec exclusions (`-mot`), par testament ou par livre.
- **Références** saisies librement : `Jean 3:16`, `Jn 3.16-18`, `1 Co 13`, `Rom 8:28; 12:1-2`.
- **341 385 références croisées** classées par pertinence et **30 groupes de passages parallèles**.
- **Mots originaux** : cliquez sur un mot pour voir le mot hébreu ou grec, son sens, son origine et toutes ses traductions.
- **Mes études** : surlignage, notes, collections de versets, export et impression.
- **Sermons** : 5 modèles de plan avec insertion automatique des versets.
- **Chants & textes** : versets d'inspiration, palette de mots, rimes bibliques.
- **Évangélisation** : parcours prêts à l'emploi et réponses aux objections.
- **Assistant IA** (Claude, facultatif) : expliquer un passage, plan de prédication, étude de groupe, illustrations, aide à
  l'écriture de chants — à partir du texte réel, des références croisées et des mots originaux.
- **Les 66 livres** : auteur, date, thème, verset clé et plan de chaque livre ; **42 personnages bibliques**.
- **Plans de lecture** (Bible en un an, NT en 90 jours, Évangiles en 40 jours, Psaumes et Proverbes en un mois) avec suivi.
- **Projection** plein écran (versets, paroles de chants, annonces) pour le culte.
- **Images de versets** à partager (carré, story, paysage), **mémorisation** des versets, **lecture audio** des chapitres.
- **Hors ligne** : l'application et les chapitres déjà consultés restent disponibles sans connexion.

L'analyse complète du projet (avis, architecture, licences, feuille de route) se trouve dans [docs/ANALYSE.md](docs/ANALYSE.md).

## Ajouter une version de la Bible

Les données sont déjà incluses dans `data/`. Pour les reconstruire ou ajouter une version, ajoutez une entrée dans `VERSIONS`
de `scripts/build-data.js` (formats acceptés : scrollmapper, Beblia XML, corpus eBible, OSIS), en vérifiant la licence, puis :

```bash
npm run data            # toutes les versions + références croisées + Strong
node scripts/build-data.js LSG   # une seule version
```

## Assistant IA (facultatif)

Créez une clé sur [console.anthropic.com](https://console.anthropic.com), ajoutez `ANTHROPIC_API_KEY=votre-cle` dans `.env`
et relancez. L'assistant utilise le modèle `claude-opus-5-5` (modifiable avec `ANTHROPIC_MODEL`). Chaque réponse est facturée par
Anthropic selon l'usage.

## Versions sous licence (API.Bible)

Les versions modernes protégées (Segond 21, Semeur, NBS…) passent par [API.Bible](https://scripture.api.bible) :

1. copiez `.env.example` en `.env` et renseignez `API_BIBLE_KEY` (ce fichier reste sur votre ordinateur) ;
2. `npm run apibible` pour vérifier la clé et voir les Bibles accessibles ;
3. `npm start` : elles sont ajoutées automatiquement avec le symbole ☁.

## Déploiement

- **Docker** : `docker build -t mister-preacher . && docker run -p 3000:3000 mister-preacher`
- **Azure App Service** : workflow fourni dans `.github/workflows/` (renseigner le nom de l'application et le profil de publication).
- Tout hébergeur Node.js : commande `npm start`, port via la variable `PORT`.

## Licences

Code : MIT. Textes et données : voir la page « Versions & sources » de l'application et [docs/ANALYSE.md](docs/ANALYSE.md#6-licences-des-données).
