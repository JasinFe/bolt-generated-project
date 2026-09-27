# Audit — famille « Services professionnels » — base 1.865.0

**Périmètre.** Les cinq packs de la famille `services` :

| Pack | Objet central | Modèle propre |
|------|---------------|---------------|
| Cabinet comptable | Dossier client, missions, échéances | `FKC_Cabinet*` (8 modèles) |
| Cabinet juridique | Affaire | `FKC_JuridiqueAffaires` |
| Garage | Ordre de réparation | `FKC_GarageAtelier` |
| Immobilier | Bien, bail, quittance | `FKC_ImmoGerance` |
| Imprimerie | Travail d'impression | aucun (brique verticale seule) |

Le socle commun est la brique verticale : dossier, pièces, workflow de validation, schémas d'écritures `ecritures.php` et bibliothèque `FKC_ModelesEcritures`. Chaque pack y ajoute son métier.

**Point de départ.** La 1.865.0 applique déjà les neuf recommandations de l'audit Commerce (`AUDIT_COMMERCE_DISTRIBUTION_1864.md` § 6). Le présent lot part donc de cette base.

**Nouveau test.** `tests/services_1866.php` compte 76 contrôles sur les cinq packs, et chaque défaut ci-dessous y est reproduit.
- Sur la 1.865.0, il échoue sur 42 contrôles.
- Sur la 1.866.0, les 76 contrôles passent.
- Chaque scénario vérifie aussi deux invariants :
  - toutes les écritures sont équilibrées ;
  - aucune écriture n'est « sans pièce d'origine » (`FKC_Origine::sansPiece`).

---

## 1. Le commun — défauts corrigés

| # | Défaut | Conséquence | Correction |
|---|--------|-------------|------------|
| S-1 | Le montant d'une pièce était lu par `(float)` : « 100 000 » valait 100. | Une note d'honoraires de 100 000 F était comptabilisée 100 F. | `fkc_nombre()` dans `FKC_VpPiece::create()`, `FKC_VpDossier::create()/update()` (montant de référence). |
| S-2 | La ligne 411 des schémas n'avait pas `'tiers' => true` dans Juridique, Garage, Immobilier et Imprimerie. | La créance tombait au 411 général, sans client : aucun encours, aucune relance, aucun lettrage. | Drapeau ajouté sur les 11 lignes concernées. La créance porte le client du dossier. |
| S-3 | Débours : la TVA saisie était conservée alors que le schéma n'a pas de ligne de taxe. | La pièce affichait un TTC qui ne correspondait pas à l'écriture. | `FKC_VpPiece::schemaPorteTva()` : le taux est ramené à 0 quand l'événement ne porte pas de TVA. |
| S-4 | Le tiers d'un dossier était un nom libre, jamais relié à une fiche client, et la fiche ne se modifiait pas après création. | Pièces comptabilisées sans client (même après S-2). | Manifeste `vertical.tiers_client` : le nom crée ou retrouve la fiche client (sans doublon, via `FKC_RecouvrementSources::tiers`). Le sélecteur « Fiche client » apparaît aussi en modification. |
| S-5 | La bibliothèque `FKC_ModelesEcritures` divergeait des schémas des packs sur 8 événements (comptes de produit, 411170 Locataires…). | L'écran des modèles montrait des comptes différents de ceux réellement mouvementés. | Bibliothèque alignée. Le test compare les comptes de chaque événement, lignes conditionnelles comprises. |

Deux corrections transverses accompagnent ce lot.

La première est un **lien d'origine** sur chaque écriture de stock : `FKC_StockCompta::comptabiliserConsommation()` et la nouvelle `annulerConsommation()` acceptent un `lien`, table et ligne d'origine. Les écritures de consommation ne sont donc plus comptées parmi les écritures sans pièce.

La seconde est le **journal d'origine des packs** : quatre tables rejoignent `FKC_Brouillard::LIENS` et `FKC_EcritureLiens` :
- `ga_mouvements` ;
- `im_operations` ;
- `jr_debours` ;
- `jr_sequestre`.

---

## 2. Spécificités par pack

### 2.1 Garage — l'atelier ne facturait pas

| # | Défaut | Correction |
|---|--------|------------|
| G-1 | Le bouton « Facturer » d'un OR ne faisait que changer un statut. Aucune facture n'était émise, et le chiffre d'affaires de l'atelier n'existait nulle part. | `FKC_GarageAtelier::facturer()` émet la facture client, la comptabilise et la valide : main-d'œuvre au 706140, pièces au 701117, TVA du dossier. Le client est une fiche, créée d'après le nom sans doublon. L'OR garde `facture_id`, le lien apparaît sur l'écran et dans la chaîne documentaire (`ordre_reparation`). Un OR facturé est verrouillé. |
| G-2 | Accepter un devis déstockait les pièces sans passer d'écriture. | L'acceptation pose les pièces comme un ajout sur OR accepté : sortie physique, charge D 6033 · C 331 et trace. `changerStatut('accepte')` passe par `accepterDevis()`, sans raccourci possible. |
| G-3 | Retirer une pièce posée la remettait en rayon sans reprendre la charge. | `annulerConsommation()` : D 331 · C 6033, au coût retenu à la pose. |
| G-4 | Aucun moyen de réceptionner une pièce : le stock ne se réapprovisionnait qu'à la main, et les poses créditaient un 331 jamais débité. | `receptionnerPiece()` : stock + coût moyen pondéré, puis écriture D 604/331 · C 6033/408 et registre des réceptions d'achat. |
| G-5 | `supprimerLigne()` acceptait un identifiant de ligne d'un autre OR. | Contrôle d'appartenance. |
| G-6 | Nombres « 1,5 » h, « 6 000 » F lus 1 et 6 ; numéro d'OR calculé par comptage, donc en collision au changement d'année. | `fkc_nombre()` ; numérotation par rang maximal. |

Chaque mouvement de pièce (pose, retour, réception) est journalisé dans `ga_mouvements`, avec son écriture.

### 2.2 Immobilier — la gérance ne tenait pas ses comptes

En 1.865, seul l'encaissement était comptabilisé (D trésorerie · C 411). Trois conséquences :
- la quittance n'avait jamais débité le locataire, qui finissait créditeur de tout ce qu'il avait payé ;
- aucun produit n'était constaté ;
- l'argent du propriétaire se confondait avec celui du gestionnaire.

| # | Opération | Écriture (1.866.0) |
|---|-----------|--------------------|
| I-1 | Appel de loyer (quittance émise) | D 411170 locataire · C 411111 propriétaire mandant (bien en mandat) ou C 706172 (bien en propre) |
| I-2 | Encaissement (Centre d'Encaissement) | D trésorerie · C 411170 locataire (la fiche neuve d'un locataire prend le compte 411170) |
| I-3 | Honoraires de gestion, à chaque encaissement d'un bien en mandat | D 411111 · C 706177 HT · C 443100 TVA |
| I-4 | Reversement au propriétaire (nouveau) | D 411111 · C trésorerie, plafonné au reste dû |
| I-5 | Dépôt de garantie | D trésorerie (selon le moyen) · C 165110 au nom du locataire |
| I-6 | Restitution du dépôt (nouveau) | D 165110 · C trésorerie, la retenue étant imputée au compte du locataire |

Autres corrections :
- « 350 000 » et « 700 000 » étaient lus 350 et 700 ;
- une quittance antérieure à 1.866 reçoit son appel au moment de l'encaissement : aucune reprise manuelle n'est nécessaire ;
- si le loyer du mois est déjà constaté par une pièce « Quittance de loyer » du dossier (voie verticale), la quittance s'y rattache au lieu de débiter une seconde fois le locataire. L'ordre ne compte pas : si la pièce arrive après, l'appel automatique est retiré ;
- la reddition affiche les honoraires TTC réellement prélevés, le reversé et le reste dû.

### 2.3 Cabinet juridique — débours et séquestre hors comptabilité

| # | Défaut | Correction |
|---|--------|------------|
| J-1 | L'avance d'un débours (huissier, greffe) n'était pas comptabilisée. La refacturation créditait donc un 467310 que rien n'avait débité. | `ajouterDebours( …, $moyen )` : D 467310 · C trésorerie. |
| J-2 | La refacturation lisait `client_nom`, une colonne inexistante : la créance était sans client. | L'affaire est rattachée à une fiche client dès l'ouverture (`clientAffaire()`), et la créance la porte. |
| J-3 | Le séquestre CARPA n'était qu'un compteur. | Entrée : D 521800 « Banque — compte séquestre » · C 467500 « Fonds clients en séquestre ». Sortie : l'inverse. Le solde ne peut jamais être négatif. |
| J-4 | Un débours pouvait être refacturé depuis une autre affaire, et les montants saisis avec un espace étaient mal lus. | Contrôle d'appartenance ; `fkc_nombre()` pour les honoraires convenus, les heures et les taux. |

### 2.4 Cabinet comptable

Pack le plus abouti de la famille : dossiers, missions, échéances, pièces, suspens, rentabilité. Deux défauts :

- **K-1.** Honoraire, budget d'heures et temps pointé étaient lus par `(float)` : « 150 000 » valait 150 et « 1,5 » h valait 1 h. La rentabilité était fausse à la source. Corrigé.
- **K-2.** La situation client ne comptait que les règlements du module Règlements. Un paiement reçu au Centre d'Encaissement laissait la note « impayée », alors que le grand livre la montrait soldée. Les reçus « paiement de facture » sont désormais pris en compte.

### 2.5 Imprimerie

Aucun défaut propre : le pack repose entièrement sur la brique verticale, que corrigent S-1 à S-5. Ajouts :
- les champs **BAT (bon à tirer)**, avec son état ;
- le champ **support / papier**.

---

## 3. Ponts avec les autres modules

| Pont | État en 1.865 | En 1.866 |
|------|---------------|----------|
| Garage → Facturation | absent | facture client comptabilisée, lien OR ↔ facture |
| Garage → Stock / Achats | consommation seule | réception (registre des réceptions d'achat, 408), pose, retour |
| Immobilier → Encaissement / Recouvrement | encaissement seul | appel, encaissement lettrable sur le même compte locataire |
| Immobilier → Comptabilité mandant | absent | compte 411111 par propriétaire, honoraires, reversement |
| Juridique → Trésorerie | absent | avance de débours, séquestre sur compte dédié |
| Tous → Référentiel clients | nom libre | fiche client unique (dossier, OR, affaire, bail) |
| Tous → Contrôle d'intégrité | écritures sans pièce | chaque écriture rattachée à sa ligne d'origine |
| Cabinet → Centre d'Encaissement | ignoré | reçus comptés dans la situation client |

---

## 4. Design

- **OR du garage** :
  - frise d'avancement Devis → Accepté → En cours → Terminé → Facturé ;
  - bandeau « Facturé » avec lien vers la facture ;
  - confirmation avant facturation.
- **Atelier** :
  - bandeau de 5 indicateurs : OR ouverts, devis à faire accepter, terminés à facturer, montant en cours, stock valorisé ;
  - formulaire de réception fournisseur ;
  - journal des mouvements avec pastilles de sens.
- **Gérance** :
  - indicateur « Dû aux propriétaires » ;
  - reddition sur trois montants (honoraires TTC, reversé, reste dû) ;
  - bouton « Reverser » ;
  - dernières opérations, marquées ⚠ si l'écriture manque.
- **Fiche d'un bien** :
  - mandat ou bien en propre indiqué clairement ;
  - encaissement partiel avec choix du moyen de paiement ;
  - carte « Dépôts des baux clos », avec restitution et retenue.
- **Affaire juridique** :
  - moyen de paiement du débours ;
  - comptes du séquestre rappelés à l'écran ;
  - case « délai critique » correctement alignée.
- **Mobile** : grilles `minmax(0,1fr)`, qui passent sur une colonne sous 820–900 px (atelier, OR, gérance, bien, affaire).
- **Global** : un bouton placé dans un bandeau d'alerte héritait du style des liens (texte bleu souligné). Sur le bouton primaire bleu « Déclarer le régime », il devenait illisible. Correction : `.alert a:not(.btn)`.

---

## 5. Recommandations (non faites dans ce lot)

1. **Garage — nature du stock.** Les pièces revendues sont stockées en « fournitures » (331/6033) mais vendues au 701117 (marchandises). La nature est volontaire et fixée par test : l'arbitrage appartient au cabinet. Le paramètre `stock_nature_garage = marchandise` existe déjà pour basculer en 311/6031.
2. **Garage — historique véhicule.** Relier les OR à une fiche véhicule (immatriculation, kilométrage, entretiens), ce qui permettrait aussi les rappels d'entretien par SMS.
3. **Immobilier — révision de loyer et charges.** Indexation annuelle, provisions sur charges et régularisation, et émission automatique des quittances le 1er du mois (tâche planifiée).
4. **Immobilier — état de reddition imprimable** par propriétaire et par période, avec détail des honoraires et reversements.
5. **Juridique — facturation au temps passé.** Transformer les diligences non facturées en note d'honoraires (pièce du dossier), et comparer honoraires convenus et facturés.
6. **Juridique — provisions sur honoraires** (419450), à imputer sur la note finale.
7. **Cabinet comptable — lettrage automatique** des encaissements sur les notes, en remplacement de l'imputation FIFO de `situation()`.
8. **Imprimerie — devis et fabrication** : calcul du prix au tirage (papier, plaques, façonnage), acompte à la commande, stock papier en matières (MATIERE), BAT bloquant la mise en production.
9. **Stock d'ouverture** (Garage) : le stock saisi à la création d'une pièce n'est pas comptabilisé. Prévoir une écriture d'à-nouveau ou forcer le passage par la réception.
