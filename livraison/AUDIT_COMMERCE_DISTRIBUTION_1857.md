# Audit — famille « Commerce & Distribution » — base 1.856.0

**Périmètre.** Les dix packs de la famille :

| Sous-famille | Packs |
|--------------|-------|
| Proximité | Supérette, Retail |
| Spécialisé | Bazar, Cosmétiques, Prêt-à-porter, Quincaillerie, Téléphonie |
| Équipement | Électroménager |
| Gros & distribution | Distribution, Station-service |

**Socle commun** (brique verticale `Modules/Vertical`) : stock au CUMP, ventes (comptant, crédit, retours), commandes et acomptes, dépôts, lots et péremption, séries, unités, tarifs, SAV, pièces du dossier, pilotage et tableau de bord. S'y ajoutent les modèles d'écritures (`FKC_ModelesEcritures`) et la feuille de style commune.

**Méthode.** Des sondes ont été jouées pack par pack sur une base réelle :

- achat → vente → retour ;
- commande → acompte → livraisons → factures ;
- crédit multi-dépôt, découpe, lots, IMEI, exonération, pièces du dossier, inventaire ;
- tournées ;
- rendu de tous les écrans, avec capture des avertissements PHP.

Après chaque geste, deux contrôles : la partie double, et l'égalité **compte 31 = stock physique au CUMP**.

**Nouveau test** : `tests/commerce_distribution_1857.php` (45 contrôles, neuf packs). Sur 1.856.0, 33 contrôles échouent : chaque défaut ci-dessous a été reproduit avant d'être corrigé.

**Non-régression** :

- les 21 tests commerce existants ;
- le contrat vues ↔ contrôleurs ;
- la carte des écritures ;
- la suite complète.

---

## 1. Le commun — bugs corrigés

| # | Défaut | Constaté (1.856.0) | Correction |
|---|--------|--------------------|-----------|
| C-1 | **Coût des ventes jamais constaté.** La réception porte la marchandise au 31 (D 31 / C 603). Mais aucune vente de la brique verticale ne l'en faisait sortir. Le schéma `{pack}_vente` n'a volontairement pas de coût, parce qu'il sert aussi la caisse, qui constate le coût par l'Inventaire. | Achat de 10 × 1 000, vente de 4 : **compte 31 à 10 000 pour 6 000 en rayon, marge = 100 % du CA**. Même chose sur les 8 packs à stock, pour la vente comptant, la vente à crédit, la livraison de commande, les pièces SAV et la chute de découpe. | `FKC_VpStock::constaterCout()` passe D 603 / C 31 au CUMP de la sortie. Les comptes sont lus dans le schéma de réception du pack (311550 carburant, 311570 terminaux…). Le coût est mémorisé sur le mouvement (`cout`, `cout_ecriture_id`). Un **retour** le reprend (D 31 / C 603) à son coût d'origine. La **chute** va en pertes sur stocks (658800 « Autres charges diverses », paramétrable). La **caisse** n'est pas touchée : elle constate déjà le coût. |
| C-2 | **Retour comptant.** | Une vente payée par Wave était remboursée **dans le tiroir-caisse** (571). Retail et Distribution contre-passaient une vente **jamais comptabilisée**. | Remboursement sur le compte du moyen d'origine (résolveur de trésorerie). Aucune écriture si la vente n'en avait pas. |
| C-3 | **Vente à crédit et multi-dépôt.** La closure capturait `$depotId`, qui n'existe pas dans sa portée. | Le dépôt n'était **jamais décrémenté** : le magasin affichait une marchandise déjà partie. | Le dépôt devient un paramètre, transmis jusqu'à l'écran de vente. |
| C-4 | **Acomptes sur commande.** | 1. Seul Électroménager avait un schéma : dans les 7 autres packs, **l'acompte n'était pas comptabilisé**. 2. Le plafond était calculé **au HT** : un acompte couvrant la commande TTC était refusé. 3. L'imputation créditait le 411 **sans le client**. 4. Un acompte partiellement imputé **l'était une seconde fois** : 110 000 imputés pour 100 000 reçus. | Schémas communs `vp_acompte` / `vp_acompte_imputation` (419100 ↔ 411100) ; le schéma du pack garde la priorité. Plafond TTC. Client et moyen transmis au moteur. Suivi `montant_impute`, imputation partielle FIFO. Le contrôle d'encours déduit l'avance déjà reçue. |
| C-5 | **Compte de vente exonéré.** `compteVenteFor()` comparait les conditions comme du texte. | Station, Téléphonie, Supérette (`regime == "" && tva == 0`) : un retour ou une vente à crédit de gaz subventionné partait en **701100** au lieu de 701112. | Conditions évaluées par `FKC_Posting::evalCondition()`. |
| C-6 | **Pilotage des ventes.** | Chutes, pièces sous garantie et annulations de réception comptées **dans le CA** ; retours **ignorés** ; marge calculée au CUMP du jour ; graphique « ventes journalières » **sans étiquettes** (série non indexée). | Une seule définition de la vente (`FKC_VpStockPilotage::HORS_VENTE`), CA net des retours, marge au coût mémorisé, série indexée par date. |
| C-8 | **Écarts d'inventaire passés en « Dons ».** Le compte par défaut des pertes sur stocks (`FKC_StockCompta`, paramètre `stock_compte_ecart`) était 658200. Or, dans le plan SYSCOHADA, 6582 est le compte des **dons**. | Casses, vols et erreurs de comptage (inventaire tournant, restaurant, bar) apparaissaient au compte de résultat comme des libéralités. | Défaut porté à **658800 « Autres charges diverses »**, pour les écarts, les chutes de découpe et les régularisations Retail. Un dossier qui a paramétré son compte garde son choix. |
| C-7 | **Pièces facturées sans client.** | Une facture de gros débitait le **411 général** : compte du revendeur vierge, encours à zéro, relance impossible. | Le client du dossier est transmis aux lignes « tiers » du schéma (`FKC_VpPiece::contexte`). |

---

## 2. Spécificités par pack

| Pack | Constats | Corrections |
|------|----------|-------------|
| **Station-service** | L'encaissement « lavage, services » débitait le 411 **sans client** (le tiers du dossier est le gérant). Gaz exonéré mal repris (C-5). | Encaissement en trésorerie (571, résolu par le moyen). Modèle d'écriture aligné (706144). |
| **Téléphonie** | Commissions mobile money et réparations au 411 sans client. Le pack facture des réparations mais n'offrait pas la capacité SAV. | Encaissement en trésorerie, journal CS. Capacité **SAV** ajoutée. Modèles alignés (706143, 706147). |
| **Supérette** | Pas de **péremption** pour du frais ; vente au poids impossible dans la brique stock. | Capacités socle + **péremption** (FEFO, lots périmés bloqués). Modes unité / quantité / **poids** / carton / lot. Modèle d'encaissement aligné (701140). |
| **Cosmétiques** | Pas de péremption (crèmes, laits, teintures). | Capacités socle + **péremption**. |
| **Quincaillerie** | Câble, tuyau et chaîne impossibles à vendre au mètre, clous au poids. Pas de tarif artisan / revendeur. | **Découpe** (chute tracée) ; modes **mètre / poids / carton / lot** ; niveaux de tarif détail / revendeur / gros / promo. |
| **Électroménager** | Son schéma d'acompte était déjà correct, mais recevait un acompte sans client (C-4). | Client transmis. Coût des ventes et dépôt corrigés par le socle. |
| **Bazar, Prêt-à-porter** | Défauts du socle uniquement (coût, retours, CA, chutes). | Corrigés par le socle. |
| **Distribution** | 1. **Tout ajout de stock passait pour un achat** : transfert inter-dépôts et retour de tournée créaient une **facture fournisseur à recevoir fantôme** et une entrée d'Inventaire. 2. **La livraison ne sortait rien du dépôt**, alors que les retours y étaient ajoutés : sur la sonde, 106 au lieu de 98. 3. L'écran promettait qu'une entrée sans fournisseur était une « régularisation sans dette » ; elle devenait une dette. 4. Création d'article sans prix : valorisation à 0, et « 1 200 » enregistré 1 F. 5. Facture de gros sans client (C-7). | `FKC_DistDepot::ajusterStock()` connaît la **nature** du mouvement (achat, transfert, livraison, retour, ajustement). Seul l'achat crée une dette ; le transfert est neutre pour l'Inventaire. La livraison sort la quantité remise ; le retour revient sans dette. Prix d'achat et de vente au formulaire, montants avec séparateurs acceptés. |
| **Retail** | Correction d'inventaire : un **excédent** passait par le schéma de *réception* (charge 601 et dette 401600 fantômes) ; un **manquant** émettait `retail_regularisation_sortie`, que **rien ne comptabilisait**. Sonde : 31 à 63 000 pour 57 000 en rayon. | Schémas `retail_regularisation_sortie` / `_entree` sur le compte des écarts d'inventaire (658800, comme `FKC_StockCompta`) ; l'excédent passe par la variation (603) : le 31 suit le stock réel, sans dette. |

---

## 3. Ponts avec les autres modules

| Pont | Avant | Maintenant |
|------|-------|-----------|
| **Stock ↔ Comptabilité** | Aucun rapprochement ; l'écart grandissait à chaque vente. | `FKC_VpStock::rapprochementComptable()` : carte « Stock rapproché de la comptabilité » (écran Stock) et indicateur du tableau de bord, avec la marche à suivre en cas d'écart hérité. |
| **Ventes ↔ Trésorerie** | Remboursements en caisse quel que soit le moyen ; acomptes en caisse seulement. | Moyen de paiement respecté pour la vente, le retour et l'acompte (Wave 552100, Orange Money 552200…). |
| **Commandes ↔ Facturation ↔ Recouvrement** | Acompte hors grand livre (7 packs), imputation sans client, double imputation. | Avance au 419 du client, imputée une seule fois sur les factures successives ; 411 et 419 soldés. |
| **Pièces ↔ Tiers** | Créances au 411 général. | Créances au compte du client du dossier (Distribution, et tout pack à pièce facturée). |
| **Distribution ↔ Achats / Inventaire** | Fausses factures à recevoir, Inventaire gonflé. | Seuls les achats alimentent le registre des réceptions ; les livraisons sont reportées à l'Inventaire. |
| **Modèles d'écritures** | Cinq événements affichaient d'autres comptes que ceux réellement passés. | Registre aligné sur les packs (Distribution 706127, Station 706144, Supérette 701140, Téléphonie 706143 / 706147). |
| **Écarts d'inventaire** | Retail sans écriture de manquant. | Même compte d'écarts (658800) pour le socle, Retail et les chutes. |

---

## 4. Design

- **Alignement des grilles** : une règle globale (`[style*="display:grid"] > .card`) supprime le décalage de 18 px qui touchait toutes les rangées d'indicateurs écrites en ligne (brique verticale, SAV, scénarios, rapports). Même correction pour le cockpit Retail (`.rt-ckp-2col`).
- **Tableau de bord des packs de commerce** : bande « Ventes & stock » (ventes du jour et du mois avec marge, valeur du stock rapprochée de la comptabilité, commandes à livrer, SAV, lots périmés) et barre d'actions directes (Vendre, Réceptionner, Commandes, Stock, SAV). La carte des pièces est renommée pour lever l'ambiguïté avec les ventes au comptoir.
- **Écran Stock** : carte de rapprochement avec liseré d'état (vert concordant, orange écart).
- **Distribution · Dépôts** : case « Dépôt central » accolée à son libellé, boutons à leur taille, actions secondaires en style discret, prix au formulaire d'article.

---

## 5. Recommandations (non faites dans ce patch)

1. **Régulariser l'historique** : un assistant qui propose, pack par pack, l'écriture d'inventaire D 603 / C 31 soldant l'écart hérité des ventes antérieures à 1.857.0 (la carte de rapprochement le mesure déjà).
2. **Coût des ventes par lot** : valoriser la sortie au coût du lot consommé (FEFO) plutôt qu'au CUMP global, pour les packs à péremption (Supérette, Cosmétiques).
3. **Distribution — facturation depuis la tournée** : générer la facture du revendeur à partir des quantités livrées et signées, avec les retours en avoir.
4. **Station-service — jauges de cuve** : relevé quotidien des cuves et des index de pompe, écart de dépotage et de température (le cockpit « Écarts de cuve » n'a pas encore de saisie dédiée).
5. **Téléphonie — mobile money** : suivre le float par opérateur (compte 585 / 552x) et les commissions retenues à la source, plutôt qu'un encaissement manuel.
6. **Quincaillerie — devis chantier** : transformer un devis multi-lignes en commande avec acompte, livraisons partielles par étape de chantier.
7. **Supérette et Retail** : unifier la brique verticale et le catalogue Retail (deux stocks pour un même métier de proximité), à défaut rapprocher `vp_articles` et `rt_articles` dans un même tableau de bord.
8. **Électroménager — extension de garantie** : produit vendu séparément, constaté en produits constatés d'avance (477) et étalé sur la durée.
