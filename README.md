# Mister Preacher (MP)

**Plateforme d'étude biblique** pour prédicateurs, prophètes, pasteurs, lecteurs et artistes : recherche, références croisées,
mots originaux (hébreu / grec), comparaison des versions, études annotées, préparation de sermons, écriture de chants et
évangélisation.

## Démarrage rapide

Il faut [Node.js](https://nodejs.org) 18 ou plus récent. Aucune autre installation n'est nécessaire (aucune dépendance npm).

```bash
npm start
# puis ouvrir http://localhost:3000
```

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

L'analyse complète du projet (avis, architecture, licences, feuille de route) se trouve dans [docs/ANALYSE.md](docs/ANALYSE.md).

## Ajouter une version de la Bible

Les données sont déjà incluses dans `data/`. Pour les reconstruire ou ajouter une version, ajoutez une entrée dans `VERSIONS`
de `scripts/build-data.js` (formats acceptés : scrollmapper, Beblia XML, corpus eBible, OSIS), en vérifiant la licence, puis :

```bash
npm run data            # toutes les versions + références croisées + Strong
node scripts/build-data.js LSG   # une seule version
```

Les versions modernes protégées (Segond 21, NEG, Semeur, NBS…) passent par API.Bible : variables `API_BIBLE_KEY` et
`API_BIBLE_VERSIONS` (voir [docs/ANALYSE.md](docs/ANALYSE.md#7-analyse-des-sources-proposées-version-11)).

## Déploiement

- **Docker** : `docker build -t mister-preacher . && docker run -p 3000:3000 mister-preacher`
- **Azure App Service** : workflow fourni dans `.github/workflows/` (renseigner le nom de l'application et le profil de publication).
- Tout hébergeur Node.js : commande `npm start`, port via la variable `PORT`.

## Licences

Code : MIT. Textes et données : voir la page « Versions & sources » de l'application et [docs/ANALYSE.md](docs/ANALYSE.md#6-licences-des-données).
