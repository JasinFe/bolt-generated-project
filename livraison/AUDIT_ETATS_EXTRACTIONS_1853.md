# Audit — ÉTATS FINANCIERS, ÉTATS FINANCIERS DGI, COMPTABILITÉ · EXTRACTIONS — base 1.852.0

Périmètre :
- **États financiers** : `Controllers/EtatsFinanciersController.php`, `Views/etats_financiers/index.php`.
- **États financiers DGI (liasse)** : `Controllers/EtatsDgiController.php`, `Models/EtatsDgi.php`, `Views/etats_dgi/{index,print,refus}.php`, `Views/_etats_controle.php`.
- **Extractions** : `Controllers/ExtractionController.php`, `Models/Rapport.php`, `Views/extractions/index.php`.
- **Ponts** : Facturation (factures, avoirs, brouillons), Brouillard (journal temporaire), Clôture (écriture de détermination du résultat), Packs Métiers (Royalties, ONG, SYCEBNL), Centre de Pilotage / Analyse / Analytique (qui consomment `FKC_EtatsDgi::sigBruts()`).

Nouveau test : `tests/etats_extractions_1853.php` (34 contrôles). Il échoue sur 27 contrôles avec la version 1.852.0 et passe entièrement avec la 1.853.0 : chaque défaut ci-dessous a été reproduit avant d'être corrigé.
Non-régression : `tests/etats_financiers_1564.php` (151 contrôles) et la suite complète `tools/run-tests.php`.

---

## 1. Bugs corrigés

### Liasse DGI — calculs

| # | Défaut | Constaté (1.852.0) | Correction |
|---|--------|--------------------|-----------|
| L-1 | **Les comptes de gestion ouverts par les Packs Métiers sortaient du compte de résultat SN.** `postesResultat()` ne connaît que le plan de base ; Royalties ouvre 682000, 708000, 708100, 709000 ; ONG 689500 / 789500 ; SYCEBNL 792500, 796000. Leur montant manquait à XI. | Résultat du grand livre 4 700 000, **XI = 3 000 000**. Liasse déclarée **INCOHÉRENTE** : impression, CSV et EDI refusés ; comme ce contrôle bloque aussi la clôture, **l'exercice ne pouvait plus être clôturé**. | Routage **compte par compte** (`sigBruts()`, `resultat()` SMT) avec lignes de repli par classe (`replisResultat()` : 60→RE, 68/69→RL, 70→TC, 78→TI, 79→TJ, 6→RJ, 7→TH, 8→RP ; SMT : 7→KB, 6/8→JF). XI vaut toujours le résultat du grand livre. Comme Centre de Pilotage, Analyse (Marge, Metrics) et Analytique lisent `sigBruts()`, **ils sont corrigés du même coup**. |
| L-2 | Ces comptes étaient ignorés sans aucun message. | — | `gestionNonVentiles()` : `controle()` les **nomme** (« 709000 → TC ») en réserve, et le bandeau de contrôle les liste. |
| L-3 | **COMP-CHARGES vide après la détermination du résultat.** Le détail était relu par une requête qui incluait l'écriture de détermination (qui solde la classe 6), alors que les sous-totaux l'excluaient. | Après l'étape 4 de la clôture : détail = **0** et sous-totaux = 2 500 000. | Détail et sous-totaux lus sur la même carte de soldes (`soldesClasse()`). Le rattachement suit `ligneResultat()`, donc **la même ligne que le compte de résultat SN**. Les comptes rangés par repli sont marqués « repli » à l'écran et dans le CSV. |
| L-4 | Le bilan SMT filtrait sur la colonne `comptes.classe`, alors que le reste des états lit **le premier chiffre du numéro**. | Un 411 importé avec `classe = 9` : actif SMT **8 200 000**, actif SN **14 700 000** ; bilan SMT déséquilibré. | Le bilan SMT lit la même carte que le SN (`soldesClasses(1..5)`). `avecComplement()` et `detailAvecComplement()` supprimés (code mort). |
| L-5 | « Éditer quand même » un **fichier EDI** renvoyait vers `/export?quoi=edi`, que `csv()` ne connaît pas. | Le dossier qui forçait le XML recevait **le CSV du bilan**. | `barrage()` renvoie vers `etats-dgi/edi`. `csv()` refuse aussi un `quoi` inconnu au lieu de le traiter en silence comme « bilan ». |
| L-6 | Impression SMT : la dotation (G) était imprimée **avant** les corrections D, E, F. | — | Ordre du modèle : C, D, E, F, G, H. Code de ligne ajouté à KZ. |

### Liasse DGI — formulaires

| # | Défaut | Correction |
|---|--------|-----------|
| F-1 | **Les trois formulaires de l'onglet « Notes annexes » n'avaient pas de jeton CSRF.** Le routeur rejette tout POST sans `_csrf` (réponse 419). L'annexe descriptive (notes 2 et 36, obligatoires au SN), le numéro de télédéclarant et la reprise des notes N-1 **ne pouvaient pas s'enregistrer du tout**. | `FKC_Csrf::field()` dans chaque formulaire (le test vérifie que tout `<form method="post">` en porte un). |

### États financiers (écran de synthèse)

| # | Défaut | Correction |
|---|--------|-----------|
| E-1 | L'écran n'affichait **que le SMT**. Un dossier au Système Normal y lisait un bilan à cinq lignes qui n'est pas le sien. | Affichage SMT **ou** SN, sélecteur SMT/SN, et **système enregistré pour le dossier** (paramètre `dgi_systeme`, route `POST comptabilite/etats-dgi/systeme`). La liasse DGI s'ouvre elle aussi sur ce système. |
| E-2 | Compte de résultat SMT affiché **sans les lignes D, E, F** (variations stocks, créances, dettes) : l'écran montrait C, G et H, et H ≠ C − G à la lecture. | Lignes VA / VB / VC affichées. |
| E-3 | Le bouton « ⬇ CSV » exportait **la balance générale**, pas les états. | « Bilan CSV » et « Résultat CSV » passent par l'export de la liasse (même mention PROVISOIRE / NON CONFORME, même barrage). Lien vers la liasse DGI complète. |
| E-4 | NCC et raison sociale vides affichés « — » sans explication ; NTD absent. | Champs vides signalés en rouge (« Non renseigné ») ; NTD affiché. |

### Extractions

| # | Défaut | Constaté (1.852.0) | Correction |
|---|--------|--------------------|-----------|
| X-1 | **La balance âgée « arrêtée au » ne s'arrêtait pas** : la date ne servait qu'au calcul de l'ancienneté ; factures et règlements postérieurs étaient comptés. | Arrêté au 31/12/2025 : facturé **2 500 000** au lieu de 1 300 000 (facture de 2026 et brouillon inclus). | Factures et règlements bornés à la date d'arrêté (`Rapport::baliseTiers`). |
| X-2 | Côté clients, l'ancienneté partait **toujours** de la date de facture : la colonne « Non échu » n'était jamais remplie. | — | L'échéance (`factures.echeance`) est utilisée quand elle est renseignée. |
| X-3 | **Factures au brouillon** comptées dans la balance auxiliaire, la balance âgée et le grand livre client, alors qu'elles n'ont pas d'écriture (`Facture::comptabiliser()` les fait passer à « validee »). La balance auxiliaire ne pouvait pas concorder avec le 411. | Débit 1 600 000 au lieu de 1 500 000. | `FKC_Rapport::FACTURES_COMPTABILISEES` : ni annulées, ni brouillons. |
| X-4 | Les **avoirs** (montant négatif) étaient déduits du débit au lieu d'être portés au crédit. | Crédit 0 au lieu de 200 000. | Avoirs au crédit du client. |
| X-5 | **Période par défaut = année civile**, pas l'exercice (le défaut déjà corrigé sur la liasse en 1.544.0). Une date invalide (`2025-02-31`) ou une période inversée donnaient une balance vide sans explication. | — | Défaut = bornes de l'exercice courant ; dates validées par `checkdate()` ; période inversée remise dans l'ordre. |
| X-6 | L'alerte « ouverture non fiable » était exportée dans le CSV mais **pas affichée à l'écran**. | — | Affichée ; les comptes concernés sont surlignés (« ouverture à vérifier »). |
| X-7 | Les **sous-totaux par classe** étaient annoncés par le code (« séparateur visuel ») mais jamais produits. | — | Sous-totaux par classe + contrôle Σ débit = Σ crédit. |
| X-8 | L'écran affirmait « le total doit concorder avec le compte collectif » sans le vérifier. | — | Rapprochement **balance auxiliaire ↔ collectif 411 / 401** du grand livre, avec l'écart et ses causes probables. |
| X-9 | Registre des immobilisations : une date d'acquisition vide s'affichait **01/01/1970**. | — | « — ». |
| X-10 | Noms de fichiers CSV : les accents étaient supprimés (« grand-livre-client-soci-t-.csv ») et un nom entièrement non latin donnait un nom vide. | — | Translittération, repli « tiers ». |
| X-11 | La barre de filtres était étirée sur toute la largeur par la grille automatique des formulaires d'`app.css` (`form:has(> .fld ~ .fld)`). | — | Barre compacte (exception locale, comme `fi-periode`). |

---

## 2. Ponts avec les autres modules et les Packs Métiers

| Pont | Avant | Maintenant |
|------|-------|-----------|
| **Packs Métiers → Liasse** | Tout compte de gestion hors plan de base cassait la cohérence de la liasse, puis la clôture. | Rangé par nature dans la ligne la plus proche, nommé dans le contrôle, marqué « repli » dans COMP-CHARGES. Pour un rattachement définitif, un pack peut ajouter ses préfixes à `postesResultat()`. |
| **Clôture → COMP-CHARGES** | Détail vide après la détermination du résultat. | Détail intact à toutes les étapes. |
| **Brouillard → Extractions** | Les extractions ignoraient le journal temporaire sans le dire. | Bandeau à l'écran (avec lien vers le brouillard) et ligne « RÉSERVE » dans chaque CSV. |
| **Facturation → Extractions** | Brouillons comptés, avoirs au débit, balance âgée non bornée. | Balance auxiliaire rapprochée du 411/401 ; écarts expliqués. |
| **Centre de Pilotage / Analyse / Analytique** | Lisaient un `sigBruts()` qui perdait les comptes des packs. | Même correction, sans modification de leur code. |
| **Pack Cabinet** | Ses liens « États financiers » / « Liasse DGI » ouvraient toujours le SMT. | Ils ouvrent le système enregistré pour le dossier. |
| **Navigation** | Aucun lien entre balance et grand livre. | Clic sur un compte de la balance → son grand livre ; sur un tiers de la balance auxiliaire → son grand livre ; sur une pièce du grand livre → l'écriture. |

---

## 3. Design graphique

- **Feuille de style commune** `Views/_etats_style.php` pour « États financiers » et « Liasse DGI ». Les deux écrans avaient chacun leur copie, et ces copies avaient divergé.
- **Bande d'indicateurs** `Views/_etats_kpi.php` (chiffre d'affaires ou recettes, résultat net et marge nette, trésorerie nette, total du bilan), avec l'évolution sur N-1. Les chiffres viennent de `FKC_EtatsDgi::synthese()`, qui les lit sur les états eux-mêmes : un indicateur ne peut pas contredire le tableau affiché dessous.
- Chiffres en `tabular-nums` alignés à droite. Hiérarchie visible détail → sous-total → total → total général (filet orange sur les totaux). Lignes de correction SMT en italique.
- Onglets SMT/SN en contrôle segmenté ; onglets d'état soulignés ; sélecteur d'exercice en pastilles.
- Tableaux dans des conteneurs à défilement horizontal : sur mobile, plus de tableaux écrasés. Mise en page d'impression (`@media print`) qui masque la navigation.
- Extractions : raccourcis de période (Exercice, Mois en cours, Trimestre, Exercice N-1, Clôture de l'exercice pour la balance âgée), alertes homogènes, pastilles d'équilibre, journaux déséquilibrés ou vides signalés.

---

## 4. Recommandations (non faites dans ce patch)

1. **Table de correspondance paramétrable pour les packs.** Le repli évite la liasse fausse, mais « 709000 → TC » reste une déduction. Il faudrait que chaque pack déclare, dans son manifeste, la ligne SN et SMT de ses comptes (`FKC_Packs::comptesLiasse()`), et que `postesResultat()` les fusionne. Le contrôle ne signalerait plus alors que les comptes vraiment inconnus.
2. **709 « Rabais accordés » dans le plan SYSCOHADA.** Le pack Royalties l'utilise pour des revenus de branding, ce qui détourne un compte normé. Le renuméroter (par exemple 706xxx) est recommandé ; le repli le range en TC en attendant.
3. **Performance.** `controle()` recalcule bilan SMT et SN, résultats, TFT et notes des deux systèmes, soit 10 à 15 constructions d'états à chaque ouverture d'écran. Deux pistes : mémoriser `controle()` par exercice pendant la requête, ou le calculer en différé (cache invalidé par `oublierSoldes()`).
4. **Balance âgée par le lettrage réel.** Elle impute aujourd'hui les règlements en FIFO. Le module de lettrage (`Models/Lettrage.php`) permettrait d'imputer chaque règlement à la facture qu'il solde.
5. **Grand livre tiers depuis la comptabilité.** Le grand livre client/fournisseur est reconstitué depuis la Facturation. Proposer aussi la version « écritures du compte auxiliaire » rendrait le rapprochement direct.
6. **Export Excel natif (.xlsx)** des extractions, avec sous-totaux et mise en forme. Le CSV en `;` reste le format d'échange.
7. **Verrou de dépôt.** Après l'envoi du fichier EDI, figer une empreinte (hash) de la liasse déposée, puis signaler toute écriture postérieure qui la modifierait.
8. **Contrôle croisé Fiscalité ↔ liasse.** Rapprocher l'impôt sur le résultat (ligne RS / 89) de la déclaration d'IS du module Fiscalité, et le chiffre d'affaires (XB) du CA déclaré en TVA.
