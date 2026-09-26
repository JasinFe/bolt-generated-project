# Audit — IMMOBILISATIONS (« Comptabilité · Immobilisations ») — base 1.854.0

Périmètre :
- **Module** : `Controllers/ImmoController.php`, `Models/Immobilisation.php`, les vues `immobilisations/{index,show,form,plan,amortir,cession,import}.php` et la barre `_immo_subnav.php`.
- **Ponts** : Clôture (`FKC_Cloture::dotationsManquantes`, `FKC_ClotureChecklist`), Extractions (registre et tableau d'amortissement, `FKC_Rapport`), liasse DGI (`FKC_EtatsDgi::controle`, note 3, tableau des flux), Achats (`FKC_FactureFournisseur`, événement `immo_acquisition_detectee`), Analytique (division de la dotation), Récolement, correspondances d'amortissement (`FKC_MappingAmortissement`).

Nouveau test : `tests/immobilisations_1855.php` (30 contrôles). Sur la version 1.854.0, 21 des 23 contrôles de départ échouaient. Chaque défaut ci-dessous a donc été reproduit avant d'être corrigé.
Non-régression : les 15 harnais du domaine (immobilisations, amortissement, clôture) et la suite complète.

---

## 1. Bugs corrigés

| # | Défaut | Constaté (1.854.0) | Correction |
|---|--------|--------------------|-----------|
| I-1 | **Montants saisis avec séparateurs.** « Valeur d'acquisition » est un champ texte, et `(float) '1 500 000'` vaut 1. | Bien saisi « 1 500 000 » → **enregistré 1 F**, plan d'amortissement à l'avenant. | `FKC_Immobilisation::nombre()` accepte espaces (y compris insécables), virgule décimale et points de milliers. Utilisé à la saisie, à l'import et à la cession. Contrôles communs dans `erreursFiche()` : date valide, valeur résiduelle inférieure à la valeur d'acquisition. |
| I-2 | **Bien non amortissable amorti quand même.** Le formulaire vidait les comptes d'amortissement d'un terrain ou de titres, mais `create()` remettait 284100 / 681300 par défaut. | Terrain de 20 M : compte 284100, **plan sur 2 ans, dotation acceptée**. | `estAmortissable()`, appuyé sur `FKC_MappingAmortissement::pour()`. Pas de compte d'amortissement ni de dotation, méthode `non_amortissable`, plan vide, dotation refusée. Signalé à l'écran (« non amortissable ») et au registre. La durée n'est plus exigée pour ces biens. |
| I-3 | **Dotation sur une fiche annulée ou cédée.** Le bouton était masqué pour un bien cédé, pas pour une fiche annulée, et la route ne vérifiait rien. | Fiche annulée : dotation 2024 de 166 667 **acceptée**. Bien cédé : dotation 2026 de 300 000 **acceptée**. | `comptabiliserDotation()` refuse la dotation d'une fiche annulée, d'un bien cédé ou d'un bien non amortissable. Les boutons sont masqués en conséquence. |
| I-4 | **Cession sans dotation complémentaire.** Le SYSCOHADA veut l'amortissement de l'exercice, au prorata jusqu'à la sortie, avant le calcul de la valeur comptable. La clôture ne le réclamait pas non plus, puisqu'elle écarte les biens cédés. | Véhicule 1,2 M sur 4 ans, cédé 1 M au 30/06 de la 2ᵉ année : VNC **900 000** au lieu de 750 000, plus-value **100 000** au lieu de 250 000. | `dotationComplementaire()` (base 360, jour de cession inclus, déduction des mensualités déjà passées) est comptabilisée d'abord par `passerDotation()`, qui écrit aussi en brouillard. Le montant apparaît dans le message et, avant cession, sur la fiche (VNC de sortie estimée). |
| I-5 | **Comptes de cession toujours 812/822.** Le tableau des flux sépare FI (incorporelles et corporelles) de FJ (financières) : la vente d'un titre était rangée avec celle d'un camion. | Logiciel (213) cédé → 812/822. | `numerosCession()` / `comptesCession()` : 811/821 incorporelles, 812/822 corporelles, 816/826 financières. Les comptes absents du plan (816, 826) sont ouverts au moment de l'écriture, jamais à l'affichage. |
| I-6 | **Mise au rebut impossible, lignes à zéro, fiche annulée cessible.** | Prix nul sans trésorerie : « Comptes de cession introuvables ». Fiche annulée : cession **acceptée**. | La mise au rebut est admise (prix nul, sans trésorerie) et l'écriture ne contient aucune ligne à zéro. Refus : fiche annulée, date invalide ou antérieure à l'acquisition, prix négatif, dotation encore au journal temporaire (la reprise débiterait le 28 d'une charge non comptabilisée). |
| I-7 | **Clôture en amortissement mensuel.** Le contrôle se demandait seulement si une dotation existait. | 1 mensualité sur 12 passée → **clôture autorisée** (11 mois d'amortissement perdus, définitivement). | `FKC_Cloture::dotationsManquantes()` compare le montant **comptabilisé** à l'annuité du plan. Le message distingue « restant à doter » de « au journal temporaire, à valider ». |
| I-8 | **Import depuis écriture pollué.** | L'écran proposait les **À-nouveaux** (tout l'actif réapparaissait chaque année) et la **reprise d'amortissement** (28) d'une cession, comme des acquisitions. | Filtres ajoutés : comptes 28/29, journal AN et références AN-, sources `anouveaux` / `immobilisations` / `reclassement` / `annulation`, contre-passations et écritures contre-passées. Les comptes d'amortissement et de dotation ne sont plus pré-remplis en 284100 / 681300 : ils se déduisent du compte d'immobilisation. |
| I-9 | **Plan d'un bien cédé.** | Véhicule cédé en 2025 : plan jusqu'en **2027** ; VNC affichée **600 000** au lieu de 0. | Le plan s'arrête à l'exercice de cession, avec la dotation réellement passée. `vnc()` d'un bien cédé ou annulé vaut 0 à l'écran, dans les totaux, au registre et dans le tableau des Extractions. |
| I-10 | Écrans « Lancer l'amortissement » et « Doter » : exercice proposé = **année civile**. | — | Exercice en cours (`FKC_Exercice::courant()`), comme la liasse depuis 1.544.0. |

---

## 2. Ponts avec les autres modules et les Packs

| Pont | Avant | Maintenant |
|------|-------|-----------|
| **Registre ↔ grand livre** | Rien ne confrontait les fiches aux comptes 2 et 28. Un achat sans fiche ne s'amortissait jamais ; une fiche sans écriture amortissait un bien absent du bilan. | `FKC_Immobilisation::rapprochement()`, compte par compte (valeur brute et amortissements), affiché sur l'écran principal, avec des liens vers le grand livre du compte. |
| **Registre ↔ liasse DGI** | — | `controle()` signale, pour information, un registre qui diverge du bilan à la clôture de l'exercice (note 3 et tableau des amortissements). |
| **Achats → Immobilisations** | L'événement `immo_acquisition_detectee` était émis à chaque facture d'achat en classe 2, et **personne ne l'écoutait**. | Bandeau « N acquisition(s) sans fiche — Créer les fiches → » sur l'écran principal, alimenté par le même filtre que l'import. |
| **Cession → Clôture / Tableau des flux** | Dotation de l'exercice de cession jamais passée ; 812/822 pour tout. | Dotation complémentaire passée ; 811/821/816/826 alimentent correctement FI / FJ. |
| **Extractions** | Registre avec la VNC d'avant la sortie ; tableau d'amortissement qui continuait après la cession. | VNC nulle pour un bien sorti, date de cession, mention « non amortissable » (écran et CSV). Le plan s'arrête à la cession. |
| **Événements** | `immo_cedee` sans la dotation complémentaire. | Champ `dotation_complementaire` ajouté (packs, notifications). |

---

## 3. Design

- **Écran principal refondu** avec la grammaire visuelle des états financiers (`_etats_style.php`) :
  - 4 indicateurs : valeur brute du parc, amortissements cumulés (en % du brut), VNC, dotations de l'exercice (passées / prévues, montant restant à doter) ;
  - filtres par statut avec compteurs (En service / Cédées / Annulées / Toutes) et ligne de totaux ;
  - pastille « non amortissable », « sorti » à la place d'une VNC fictive ;
  - carte de rapprochement avec le grand livre ; seuil d'immobilisation replié dans un panneau.
- **Fiche** : aperçu de la dotation complémentaire et de la VNC de sortie avant cession, mise au rebut explicite, état « au journal temporaire » dans le plan annuel, encart dédié aux biens non amortissables.
- **Cession** : la trésorerie est facultative (mise au rebut) ; les comptes réellement utilisés sont annoncés (811/812/816…).

---

## 4. Recommandations (non faites dans ce patch)

1. **Dépréciations (29x).** Les biens non amortissables se déprécient. Il manque l'écran pour constater, reprendre et suivre une dépréciation, avec la dotation 691/697 et la reprise 791/797.
2. **Modes dégressif et par unités d'œuvre.** Seul le linéaire existe ; le fiscal ivoirien admet le dégressif pour certains matériels.
3. **Composants.** Décomposer un bâtiment ou un véhicule en composants de durées différentes, comme le SYSCOHADA révisé le prévoit.
4. **Réévaluation et écarts (106).** Aucune prise en charge aujourd'hui.
5. **Immobilisations en cours (23x) → mise en service.** Un assistant de virement du 23 vers le 2x, qui ouvrirait la fiche au jour de mise en service. Le contrôle de clôture « 23 dormant » existe déjà.
6. **Écouteur d'événement** `immo_acquisition_detectee` : une notification à l'acheteur ou au comptable dès l'enregistrement de la facture, en plus du bandeau.
7. **Tableau des amortissements DGI (note 3C)** généré depuis le registre et rapproché de la note 3 issue du grand livre.

---

## 5. Mise en œuvre des recommandations (1.856.0)

Nouveau test : `tests/immobilisations_recommandations_1856.php` (50 contrôles). Sur 1.855.0, il échoue dès le premier appel (`FKC_Immobilisation::deprecier()` n'existe pas).

| # | Recommandation | Réalisation |
|---|----------------|-------------|
| 1 | **Dépréciations (29x)** | `deprecier( $id, $date, $montant, $motif )` : un montant positif constate (D 6913 incorporelles / 6914 corporelles / 6972 financières, C 29x déduit par `FKC_MappingAmortissement`), un montant négatif reprend (D 29x, C 7913 / 7914 / 7972). Plafonds : la VNC pour une constatation, le cumul pour une reprise. Suivi dans `immo_depreciations`, raccordé au journal temporaire. La VNC en tient compte ; la cession reprend la dépréciation restante dans la même écriture ; le rapprochement confronte le 29 au registre. Carte « Dépréciation » sur la fiche, avec historique. |
| 2 | **Dégressif et unités d'œuvre** | Méthode au formulaire. Dégressif : coefficient de la fiche ou barème (1,5 pour 3–4 ans, 2 pour 5–6 ans, 2,5 au-delà), premier exercice en mois, bascule en linéaire dès que le taux linéaire restant l'emporte, plan borné à la durée. Unités d'œuvre : unités totales prévues, saisie par exercice (`saisirUnites()`), refus au-delà du total prévu ou après la dotation. Dotation, mensualisation, clôture et cession suivent le plan, quel que soit le mode. |
| 3 | **Composants** | `ventilerComposant()` : avant la première dotation, un élément à durée propre devient une fiche rattachée (`parent_id`) ; la valeur de la fiche principale baisse d'autant ; une OD reclasse la valeur si le compte change (231 → 234). Un composant ne se décompose pas. La fiche liste ses composants. |
| 4 | **Réévaluation (106)** | `reevaluer()` : écart = valeur actuelle − VNC, positif uniquement (une baisse est une dépréciation). D 2x / C 106, valeur brute relevée, plan des exercices suivants rebasé sur la durée restante (`rebaser()`). Refusée si une dotation postérieure existe, ou si une dépréciation est encore constatée. |
| 5 | **Mise en service des en-cours** | `mettreEnService()` : D compte définitif / C 219·229·239·249, comptes d'amortissement et de dotation déduits, durée et méthode saisies. L'amortissement part de `date_mise_en_service` (prorata du premier exercice, mensualisation, dotation complémentaire de cession). Événement `immo_mise_en_service`. |
| 6 | **Écouteur `immo_acquisition_detectee`** | `FKC_Immobilisation::brancher()` au démarrage. Chaque facture d'achat imputée en classe 2 notifie les administrateurs et les utilisateurs du module Comptabilité (type « tâche », lien vers l'import). Une seule notification par facture, grâce à la clé de regroupement. |
| 7 | **Note 3C depuis le registre** | `FKC_EtatsDgi::tableauAmortissementsRegistre()` : par poste de la liasse (AE → AN), cumul à l'ouverture, dotations, sorties et clôture tirés des fiches, comparés aux comptes 28 du grand livre, avec l'écart. Affiché sous les notes annexes (badge concordant / écart) et exporté dans le CSV des notes. |

S'y ajoutent :
- une fiche dépréciée, réévaluée ou décomposée ne s'annule plus tant que ces opérations n'ont pas été défaites ;
- la note 2 proposée décrit les modes d'amortissement réellement employés et écarte les biens non amortissables.
