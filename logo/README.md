# Logo FinaKop

## Contenu

| Dossier | Format | Usage |
|---|---|---|
| `png/` | PNG fond transparent (512 à 3000 px) | Web, réseaux sociaux, documents |
| `svg/` | Vectoriel SVG | Web, master vectoriel |
| `ai/` | Adobe Illustrator (.ai compatible PDF, entièrement vectoriel et éditable) | Retouche dans Illustrator |
| `pdf/`, `eps/` | Vectoriel | Imprimeurs, Illustrator/InDesign/CorelDRAW |
| `psd/` | Photoshop, calques séparés (icône 2000 px, logo horizontal 3000 px) | Retouche dans Photoshop |

Déclinaisons : `icone` (emblème seul), `logo-horizontal`, `logo-vertical`, et leurs versions `-blanc` (sur fond sombre) ou `-marine` (une couleur).

## Charte

- Bleu marine `#0F2C86` (dégradé `#1E50C8` → `#0A1C5C`)
- Orange `#F7A21B` (dégradé `#FFB938` → `#EE8A00`)
- Typographie : Montserrat ExtraBold (licence OFL, texte vectorisé dans les fichiers)

## Concept

- Monogramme **F + K** : un F blanc dont la jambe orange forme le K de Fina**K**op.
- Six hexagones symétriques reliés par un anneau-réseau : croissance, documents, équipe, sécurité, analyse, comptabilité.
- Mot-symbole « Fina » en marine et « Kop » en orange, repris du monogramme.

## Régénérer

`python3 logo/source/export.py` (requiert `cairosvg`, `fonttools`, `psd-tools`, `pillow`).
