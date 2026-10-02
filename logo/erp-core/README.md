# Logo FinaKop ERP Core

Version raffinée du modèle multicolore (hexagones à liseré or, nom en italique, slogan « ERP CORE »).

## Contenu

| Dossier | Format | Usage |
|---|---|---|
| `png/` | PNG fond transparent (512 à 3000 px) | Web, réseaux sociaux, documents |
| `ai/` | Adobe Illustrator (.ai compatible PDF, vectoriel et modifiable) | Retouche dans Illustrator |
| `psd/` | Photoshop, un calque par élément (2000 px de large) | Retouche dans Photoshop |
| `svg/`, `pdf/`, `eps/` | Vectoriel | Web, imprimeurs |

Déclinaisons : `icone`, `logo-vertical`, `logo-horizontal`, versions `-blanc` (fond sombre) et `-marine` (icône une couleur).

## Charte

- Bleu marine `#0F2C86`, orange `#F7A21B`, or `#E3A73A`
- Hexagones : orange (croissance), turquoise `#1BA79E` (documents), rouge `#E8504F` (équipe), bleu `#2C64E0` (sécurité), violet `#9058E0` (analyse), bleu `#2F7BE8` (comptabilité)
- Typographie : Montserrat Black Italic (nom) et Montserrat Bold (slogan), licence OFL, texte vectorisé

## Régénérer

`python3 logo/erp-core/source/export.py` (requiert `cairosvg`, `fonttools`, `psd-tools`, `pillow`).
