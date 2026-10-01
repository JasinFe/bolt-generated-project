<?php
/**
 * FinaKop ERP Core — contrôleur frontal de l'application plein écran.
 * Chargé par l'interception du sous-domaine (finakop-erp-core.php) après
 * définition de FKC_ROOT, FKC_BASE_URL, FKC_DATA_DIR.
 *
 * @package FinaKop_ERP_Core
 */

/*
 * Ce fichier n'est PAS un point d'entrée autonome.
 *
 * Il était auparavant auto-amorçable : en l'absence de FKC_ROOT il définissait
 * lui-même ses constantes, dont FKC_DATA_DIR = __DIR__.'/.data/'. Comme le
 * dossier app/ est servi par le serveur web (wp-content/plugins/…), une simple
 * requête sur ce fichier démarrait une instance complète de l'ERP hors de
 * WordPress, créait une base SQLite DANS la racine web et y semait un compte
 * administrateur. On exige désormais que l'amorçage vienne du plugin, qui seul
 * sait où placer les données et comment authentifier la requête.
 *
 * Pour un amorçage de test, définir FKC_ROOT, FKC_DATA_DIR (hors racine web) et
 * FKC_BASE_URL depuis un script appelant — voir tests/bootstrap.php.
 */
if ( ! defined( 'FKC_ROOT' ) ) {
	http_response_code( 403 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	exit( "Accès direct interdit.\n" );
}
if ( ! defined( 'FKC_DATA_DIR' ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	exit( "Amorçage incomplet : FKC_DATA_DIR non défini.\n" );
}
defined( 'FKC_VERSION' )  || define( 'FKC_VERSION', '0.0.0-dev' );
defined( 'FKC_BASE_URL' ) || define( 'FKC_BASE_URL', '/' );
if ( ! is_dir( FKC_DATA_DIR ) ) { @mkdir( FKC_DATA_DIR, 0750, true ); }

/* ── Chargement du noyau ─────────────────────────────────────────────────── */
require FKC_ROOT . 'Core/helpers.php';
fkc_silence_diagnostics();

require FKC_ROOT . 'Core/Database.php';
require FKC_ROOT . 'Core/Tx.php';       // Gestionnaire transactionnel unique (imbrication par points de reprise)
require FKC_ROOT . 'Core/Master.php';
require FKC_ROOT . 'Core/Plans.php';
require FKC_ROOT . 'Core/Modules.php';
require FKC_ROOT . 'Core/License.php';
require FKC_ROOT . 'Core/Packs.php';    // Registre des Packs Métier (Vertical Packs)
require FKC_ROOT . 'Core/Posting.php';  // Moteur d'événements comptables
require FKC_ROOT . 'Core/EcritureNumero.php';  // numerotation AAnnnnnn par journal et par exercice
require FKC_ROOT . 'Core/Devise.php';  // Taux de change, conversion, comptes de change SYSCOHADA
require FKC_ROOT . 'Core/DeviseRevalorisation.php';
require FKC_ROOT . 'Core/DeviseSource.php';  // recuperation automatique des cours (Xe API, source ouverte)  // Revalorisation de clôture (478/479 latent, 676/776 réalisé)
require FKC_ROOT . 'Core/EcritureLiens.php';  // Registre des dépendances d'une écriture (annulation, purge, rattrapage)
require FKC_ROOT . 'Core/BPE.php';      // Business Process Engine : journal d'evenements, chaine documentaire, audit, file
require FKC_ROOT . 'Core/Events.php';   // Bus d'événements métier unifié (fan-out vers tous les moteurs)
require FKC_ROOT . 'Core/Mailer.php';    // Client SMTP autonome (1.876.0)
require FKC_ROOT . 'Core/Courriel.php';  // Envoi de courriels tracé (FKC_Mailer, ou wp_mail en extension), 1.838.0
require FKC_ROOT . 'Core/BPEWorker.php';// Consommateur central de la file différée (la file existait sans consommateur)
require FKC_ROOT . 'Core/ArticleBridge.php';
require FKC_ROOT . 'Core/TiersBridge.php';   // Référentiel fournisseurs unique (comptabilité ⇄ packs)
require FKC_ROOT . 'Core/AchatReception.php'; // Réceptions en attente de facture (comptes 408)
require FKC_ROOT . 'Core/BudgetConso.php';   // Pont achats -> budget : le réalisé vient des faits, non d'une saisie
require FKC_ROOT . 'Core/Integrite.php';    // Centre d'intégrité : rassemble les contrôles, n'en calcule aucun
require FKC_ROOT . 'Core/CaisseMigration.php'; // Convergence des caisses (pack Retail → noyau)
require FKC_ROOT . 'Core/CaisseProjection.php'; // Projection des ventes du pack sur la caisse du noyau
require FKC_ROOT . 'Core/ImmoRecolement.php';  // Récolement des immobilisations (inventaire physique des actifs) // Pont catalogue Inventaire <-> pack Retail (identité seule, jamais le stock)
require FKC_ROOT . 'Core/PackFamilles.php';    // Familles de packs metier (regroupement des 50 verticaux)
require_once FKC_ROOT . 'Core/Referentiel.php';     // Referentiel comptable applicable au dossier (SYSCOHADA / SYCEBNL)
require_once FKC_ROOT . 'Core/Economie/Fait.php';   // Catalogue des faits economiques (declaratif)
require_once FKC_ROOT . 'Core/Economie/Resolveur.php'; // Resolveur central de comptes par role
require_once FKC_ROOT . 'Core/Economie/RegimeFiscal.php'; // Moteur fiscal date : regimes et bareme
require_once FKC_ROOT . 'Core/Economie/SommeDetenue.php'; // Registre des sommes detenues pour autrui
require_once FKC_ROOT . 'Core/Economie/Provision.php';    // Registre des provisions et engagements
require_once FKC_ROOT . 'Core/Economie/ClotureControle.php'; // Conservation : la cloture agrege, elle ne recalcule pas
require_once FKC_ROOT . 'Core/Economie/ClotureJournal.php';  // Journal d'arrete des comptes : qui, quand, quoi, pourquoi (ajout seul)
require_once FKC_ROOT . 'Core/Economie/ClotureProcessus.php';// Les sept etapes de la cloture, lues dans les ecritures
require_once FKC_ROOT . 'Core/Economie/ClotureExplication.php'; // Phase 5 : explication des ecarts, suggestions, faits Maestro de cloture
require_once FKC_ROOT . 'Core/Economie/ClotureRapport.php';   // Phase 5 : rapports imprimables de cloture
require_once FKC_ROOT . 'Core/Economie/TvaRapprochement.php'; // 1.784.0 : TVA collectee du grand livre contre declarations deposees
require_once FKC_ROOT . 'Core/Economie/AcompteIs.php';        // 1.784.0 : registre des acomptes d'impot sur le resultat
require_once FKC_ROOT . 'Core/Economie/ClotureChecklist.php';
require_once FKC_ROOT . 'Core/Economie/Inventaire.php';    // Cockpit des travaux d'inventaire : neuf domaines, aucun calcul propre
require_once FKC_ROOT . 'Core/Economie/Regularisation.php'; // Rattachement des charges et produits (FNP, FAE, CCA, PCA) et extourne
require_once FKC_ROOT . 'Core/Economie/ResultatFiscal.php'; // Tableau de passage resultat comptable -> resultat fiscal -> base imposable
require_once FKC_ROOT . 'Core/Economie/Numerotation.php';    // Numerotation ininterrompue des pieces
require_once FKC_ROOT . 'Core/Economie/ChaineFne.php';      // Chaine facture -> certification -> ecriture
require_once FKC_ROOT . 'Core/Economie/Reclassement.php';   // OD de reclassement des imputations heritees
require_once FKC_ROOT . 'Core/Economie/Divergence.php';     // Divergences sur sept dimensions
require_once FKC_ROOT . 'Core/Economie/Conservation.php';   // Conservation economique d'une operation
require_once FKC_ROOT . 'Core/Economie/Trace.php';  // Provenance d'une ecriture : pourquoi existe-t-elle ?
require_once FKC_ROOT . 'Core/Economie/ChaineDoc.php'; // Vocabulaire de la chaine documentaire
require_once FKC_ROOT . 'Core/Economie/Origine.php'; // Pont ecriture -> piece d'origine + chaine documentaire
FKC_Origine::enregistrerTraitement();               // Premier producteur reel de la file differee
require_once FKC_ROOT . 'Core/PlanSycebnl.php';     // Plan de comptes SYCEBNL et correspondances (lot 7)
require_once FKC_ROOT . 'Core/PlanCima.php';
require_once FKC_ROOT . 'Core/PlanSfd.php';         // Référentiel comptable des SFD de l'UMOA (1.875.5)
require_once FKC_ROOT . 'Core/Subdivision.php';     // Subdivisions hiérarchiques de 7 à 10 chiffres (1.875.4)        // Plan comptable CIMA des organismes d'assurance (1.875.3)
require FKC_ROOT . 'Core/EtatsSycebnl.php';    // Etats specifiques SYCEBNL (ressources-emplois, execution, fonds)
require FKC_ROOT . 'Core/CategorieFiscale.php'; // Référentiel des catégories fiscales (taux de TVA par nature d'opération)
require FKC_ROOT . 'Core/RecetteNature.php';   // Ventilation des recettes de caisse par nature (comptes SYSCOHADA par nature)
require FKC_ROOT . 'Core/MoyenPaiement.php';   // Referentiel des moyens de paiement (especes, mobile money par operateur, carte, cheque, virement)
require FKC_ROOT . 'Core/Encaissement.php';    // Pont encaissement metier -> Centre d'Encaissement (pièce numerotee + ecriture)
require FKC_ROOT . 'Core/Orphelins.php';       // Detection des lignes designant un parent disparu (relations sans cle etrangere)
require FKC_ROOT . 'Core/BPESante.php';        // Battement du moteur d'evenements : savoir s'il tourne, pas seulement ce qu'il y a dans la file
require FKC_ROOT . 'Core/NumeroBloc.php';      // Blocs de numeros pre-alloues par poste : saisie hors ligne sans collision
require FKC_ROOT . 'Core/HorsLigne.php';       // Rejeu des operations saisies sans reseau (verdict par operation)
require FKC_ROOT . 'Core/FneFile.php';         // File de certification FNE differee (reprise apres coupure)
require FKC_ROOT . 'Core/HospitalityNumero.php'; // Sequences documentaires atomiques (FAC, CMD, PAY, FOL, AVO, INV...)
require FKC_ROOT . 'Core/HospitalityAnnulation.php'; // Moteur commun annulation / avoir / remboursement
/*
 * SERVICE CORE (1.634.0) — catalogue de prestations présentable et commande
 * de services. Chargés ICI, inconditionnellement : les hooks des packs
 * (KPI, alertes) sont évalués avant tout contrôleur, et une métrique gardée
 * par `class_exists( 'FKC_ServiceOrder' )` resterait muette si la classe
 * n'arrivait qu'avec le contrôleur du pack.
 */
require FKC_ROOT . 'Core/ServiceCatalog.php'; // Catalogue de prestations : présentation, fourchettes de prix, parcours
require FKC_ROOT . 'Core/ServiceOrder.php';   // Commande de services : client → lignes → paiement → reçu → facture
require FKC_ROOT . 'Core/Terminal.php';        // Registre des appareils clients et appairage (Terminal FinaKop)
require FKC_ROOT . 'Core/TerminalAdapter.php'; // Expérience client par pack : le moteur au Core, le vocabulaire au métier
require FKC_ROOT . 'Core/TerminalTheme.php';   // Theme Engine du Terminal : Core → pack → établissement (1.806.0)
require FKC_ROOT . 'Core/TerminalSuivi.php';   // Suivi public par lien signé (QR du ticket) (1.807.0)
require_once FKC_ROOT . 'Core/TerminalMedia.php';   // Photos d'articles du Terminal, tous métiers (1.812.0)
require FKC_ROOT . 'Core/TerminalPromo.php';   // Contenus promotionnels des bornes — Phase 3 (1.814.0)
require FKC_ROOT . 'Core/TerminalMarque.php';  // Apparence des bornes de l'établissement — Phase 3 (1.815.0)
require FKC_ROOT . 'Core/ServiceBrief.php';   // Brief de prestation : le questionnaire déclaré par le métier, avant chiffrage
require FKC_ROOT . 'Core/ServiceItems.php';   // Catalogue à l'unité : exemplaires nommés, licences, exclusivité
require FKC_ROOT . 'Core/ResourcePlanning.php'; // Planning : grille d'occupation et recherche de créneaux libres
require FKC_ROOT . 'Core/ServiceRelance.php';  // Relance des soldes de commandes — se branche sur le journal du Recouvrement
require FKC_ROOT . 'Core/IntegrationAudit.php'; // Audit : regles comptables des packs sans declencheur
require FKC_ROOT . 'Core/EcritureAssistee.php'; // Ecritures metier assistees (le comptable declenche et valide)
require FKC_ROOT . 'Core/Fie/Facts.php';      // FIE : Fact Engine (faits normalisés déclarés par les modules)
require FKC_ROOT . 'Core/Fie/Knowledge.php';  // FIE : Knowledge Base (patterns, seuils, playbooks)
require FKC_ROOT . 'Core/Fie/Inference.php';  // FIE : Business Rules + Inference Engine
require FKC_ROOT . 'Core/Fie/Engines.php';    // FIE : Decision, Explanation, Workflow + façade FKC_Fie
require FKC_ROOT . 'Core/Fie/Producers.php';  // FIE : producteurs de faits réels du noyau
/*
 * FINAKOP MAESTRO (1.574.0) — le FIE devient un cerveau qui se souvient.
 *
 * L'ordre suit la dépendance : le schéma d'abord (les autres l'appellent),
 * puis les moteurs sans dépendance croisée, la façade en dernier — elle les
 * compose tous. Les classes FKC_Fie_* restent en place, INCHANGÉES : Maestro
 * les enveloppe, il ne les remplace pas.
 */
require FKC_ROOT . 'Core/Maestro/Schema.php';      // Maestro : tables de mémoire (compteur de version propre)
require FKC_ROOT . 'Core/Maestro/Context.php';
require FKC_ROOT . 'Core/Maestro/Acces.php';       // Maestro : qui a le droit d'entendre quoi (1.837.0)     // Maestro : qui, où, quel rôle, quelle période
require FKC_ROOT . 'Core/Maestro/Memory.php';      // Maestro : les quatre mémoires
require FKC_ROOT . 'Core/Maestro/Confidence.php';  // Maestro : sources et indice de confiance
require FKC_ROOT . 'Core/Maestro/Profile.php';     // Maestro : ADN d'entreprise + profil d'interlocuteur
require FKC_ROOT . 'Core/Maestro/Modes.php';       // Maestro : Observation/Conseil/Action + Silence→Signal→Alerte→Action
require FKC_ROOT . 'Core/Maestro/Feedback.php';    // Maestro : retours utilisateur + apprentissage à trois niveaux
require FKC_ROOT . 'Core/Maestro/Explanation.php'; // Maestro : « sur quoi te bases-tu ? »
require FKC_ROOT . 'Core/Maestro/Language.php';    // Maestro : rendu par profil + faculté LLM optionnelle
require FKC_ROOT . 'Core/Maestro/Causality.php';   // Maestro : graphe causal DÉCLARÉ (jamais découvert)
require FKC_ROOT . 'Core/Maestro/Anomaly.php';     // Maestro : détection robuste (médiane/MAD), filtrée par la causalité
require FKC_ROOT . 'Core/Maestro/Forecast.php';    // Maestro : 9 méthodes de prévision départagées par backtest
require FKC_ROOT . 'Core/Maestro/Experts.php';     // Maestro : registre des Experts (indexé par Expert, pas par pack)
require FKC_ROOT . 'Core/Maestro/Catalogue.php';   // Maestro : les 29 Experts — identité seule, la connaissance vient par contributions
require FKC_ROOT . 'Core/Maestro/Recouvrement.php'; // Maestro : comportement de paiement des clients (délais réels, promesses, canaux)
require FKC_ROOT . 'Core/Maestro/Comptable.php';   // Maestro : habitudes de saisie apprises sur le grand livre
require FKC_ROOT . 'Core/Maestro/Paie.php';        // Maestro : habitudes de rémunération et contrôle croisé paie/comptabilité
require FKC_ROOT . 'Core/Maestro/Acquittement.php'; // Maestro : clore un écart expliqué, avec son motif
require FKC_ROOT . 'Core/Maestro/Audit.php';       // Maestro : rapprochements intermodules, à deux origines toujours
require FKC_ROOT . 'Core/Maestro/Affaire.php';     // Maestro : pilotage à l'affaire, socle commun BTP et Services
require FKC_ROOT . 'Core/Maestro/Bus.php';         // Maestro : chaîne causale nommée par Expert, bornée et déterministe
require FKC_ROOT . 'Core/Maestro/Dg.php';          // Maestro : la vue dirigeant — une réponse, pas dix tableaux de bord
foreach ( glob( FKC_ROOT . 'Core/Maestro/Experts/*.php' ) as $fkcExpert ) { require $fkcExpert; } // Connaissance des Experts, lot par lot
unset( $fkcExpert );
require FKC_ROOT . 'Core/Maestro/Simulation.php';   // Maestro : what-if propagé, chiffré seulement là où c'est mesurable
require FKC_ROOT . 'Core/Maestro/Opportunity.php';  // Maestro : opportunités chiffrées, actionnables, subordonnées aux risques
require FKC_ROOT . 'Core/Maestro/Learning.php';    // Maestro : apprentissage mesuré, jamais au détriment d'une alerte de danger
require FKC_ROOT . 'Core/Maestro/Conversation.php'; // Maestro : compréhension et fil de conversation, sans LLM
require FKC_ROOT . 'Core/Maestro/Lexique.php';      // Maestro : vocabulaire des familles métier + expressions apprises (1.846.0)
require FKC_ROOT . 'Core/Maestro/Action.php';       // Maestro : prépare, l'humain valide, l'exécution revérifie
require FKC_ROOT . 'Core/Maestro/Signaux.php';      // Maestro : les faits d'alerte des 61 packs (briefing) deviennent des décisions (1.847.0)
require FKC_ROOT . 'Core/Maestro/Questions.php';    // Maestro : bilan, comparaison, classement, client nommé (1.848.0)
require FKC_ROOT . 'Core/Maestro/Maestro.php';     // Maestro : la façade (ask, analyser, prevoir, conseiller, simuler, expliquer, priorites)
require FKC_ROOT . 'Core/Stock.php';    // Façade de stock unifiée (fédère brique verticale + module Inventaire)
require FKC_ROOT . 'Core/PosBons.php';       // Caisse : bons d'achat et coupons au terminal (1.865.0)
require FKC_ROOT . 'Core/PosFidelite.php';   // Caisse : moteur de fidélité & CRM (cagnotte / points, paliers VIP)
require FKC_ROOT . 'Core/PosPromotions.php'; // Caisse : moteur de promotions automatiques (seuil, happy hour, N+M offerts)
require FKC_ROOT . 'Core/PosFiscal.php';     // Caisse : journal fiscal inaltérable (chaînage d'empreintes, type NF525)
require FKC_ROOT . 'Core/PosVariantes.php';   // Caisse/Inventaire : variantes matricielles taille × couleur (prêt-à-porter)
require FKC_ROOT . 'Core/PosPesee.php';       // Caisse : pesage + codes-barres balance (supérette, boulangerie, vrac)
require FKC_ROOT . 'Core/PosCommande.php';    // Caisse : commandes & click-and-collect (réservation → retrait → encaissement)
require FKC_ROOT . 'Core/PosImpression.php';  // Caisse : pilote ESC/POS (ticket thermique + tiroir-caisse)
require FKC_ROOT . 'Core/PosPonts.php';       // Caisse : ponts entrepôt, lots FEFO, catégories, vente en compte (1.836.0)
require FKC_ROOT . 'Core/MomoProviders.php';  // Mobile Money : fournisseurs Wave / CinetPay / PayDunya (déplacés du pack Retail, 1.843.0)
require FKC_ROOT . 'Core/PaiementMobile.php';  // Caisse : paiement Mobile Money par demande au client (1.843.0)
require FKC_ROOT . 'Core/PosQz.php';          // Caisse : impression directe QZ Tray (1.843.0)
require FKC_ROOT . 'Core/PosConsigne.php';    // Caisse : consignes d'emballages (1.842.0)
require FKC_ROOT . 'Core/PosMobile.php';      // Caisse : prise de commande mobile du serveur (1.842.0)
require FKC_ROOT . 'Core/PosDroits.php';      // Caisse : droits (rôles de caisse) et code superviseur (1.835.0)
require FKC_ROOT . 'Core/Storage.php';
require FKC_ROOT . 'Core/Auth.php';
require FKC_ROOT . 'Core/DeuxFacteurs.php';
require FKC_ROOT . 'Core/Tenant.php';
require FKC_ROOT . 'Core/Csrf.php';
require FKC_ROOT . 'Core/View.php';
require FKC_ROOT . 'Core/Periode.php';  // Bornes d'exercice : source unique de « sur l'année »
require FKC_ROOT . 'Core/PiloteLecture.php'; // lecture comptable commune du Cockpit : flux hors détermination du résultat, soldes à date (1.800.0)
require FKC_ROOT . 'Core/Layout.php';   // Moteur de mise en page : largeur des sections de tableau de bord
require FKC_ROOT . 'Core/Router.php';
require FKC_ROOT . 'Core/Launcher.php';
require FKC_ROOT . 'Core/Secteur.php';
require FKC_ROOT . 'Core/Chart.php';
require FKC_ROOT . 'Core/ChartX.php';
require FKC_ROOT . 'Core/Http.php';           // client HTTP (intégrations externes)
require FKC_ROOT . 'Core/Analyse.php';
require FKC_ROOT . 'Core/Pilotage.php';
require FKC_ROOT . 'Core/Pilotage2.php';
require FKC_ROOT . 'Core/PilotagePrefs.php';
require FKC_ROOT . 'Core/SupplyChain.php';      // Achats : demande → validation → commande → réception
require FKC_ROOT . 'Core/ReglesStock.php';      // Règles par article : seuil, rupture, péremption
require FKC_ROOT . 'Core/ApproPrevisionnel.php'; // Règle prévisionnelle : Forecast → point de commande
require FKC_ROOT . 'Core/PlanSyscohada.php';    // Niveau 1 : référentiel OHADA officiel (intouchable)
require FKC_ROOT . 'Core/PlanMetier.php';
require FKC_ROOT . 'Core/PlanCanonique.php'; // un numéro = un sens, sur toute l'installation       // Niveau 2 : subdivisions métier FinaKop
require FKC_ROOT . 'Core/PlanAudit.php';        // Conformité du plan d'une société
require FKC_ROOT . 'Core/PlanPurge.php';        // Analyse et purge définitive du plan
require_once FKC_ROOT . 'Core/CodeAuto.php';         // Codes automatiques quand le champ est laissé vide
require FKC_ROOT . 'Core/MappingAmortissement.php'; // Immobilisation → amortissement / dépréciation / dotation
require FKC_ROOT . 'Core/ModelesEcritures.php'; // Modèles d'écritures par métier (schémas FKC_Posting)
require FKC_ROOT . 'Core/GenerateurPlan.php';   // Générateur de dossier comptable par pack
require FKC_ROOT . 'Core/AidePacks.php';        // Fiches pratiques des packs, générées depuis les manifestes
/*
 * RIGHTS CORE — répertoire canonique des œuvres, titularité historisée et
 * barèmes versionnés.
 *
 * Chargé dans le NOYAU et non dans le pack Gestion Collective : un label, une
 * maison d'édition, un producteur audiovisuel et un organisme de gestion ont
 * besoin du même répertoire et des mêmes barèmes. Le pack les utilise, il ne
 * les possède pas.
 *
 * ⚠️ Ne pas confondre FKC_Registry (côté TITULAIRE : qui possède quoi, argent
 * entrant) avec FKC_RightsEngine plus bas (côté UTILISATEUR de droits : une
 * production qui doit dégager une musique, argent sortant). Les deux moteurs
 * cohabitent volontairement : ce sont les deux bouts de la même chaîne.
 */
require FKC_ROOT . 'Core/Territoire.php';      // Hiérarchie territoriale : MONDE ⊃ AFRIQUE ⊃ CEDEAO ⊃ UEMOA ⊃ CI
require FKC_ROOT . 'Core/Historisation.php';    // Versionnement SCD2 + journal des changements de valeur
require FKC_ROOT . 'Core/Registry.php';         // Œuvre → fixation → support → prestation
require FKC_ROOT . 'Core/Recueil.php';          // Album / EP → pistes : le produit qui porte les stickers
require FKC_ROOT . 'Core/Titularite.php';       // Titulaires, adhérents, droits historisés
require FKC_ROOT . 'Core/RegistryBridge.php';   // Pont catalogue label ⇄ répertoire canonique
require FKC_ROOT . 'Core/TariffEngine.php';     // Barèmes versionnés, résolus à la date du fait générateur
require FKC_ROOT . 'Core/StickerInventory.php'; // Stickers DRM : plages, affectations, jetons signés
require FKC_ROOT . 'Core/RevenuType.php';       // Nomenclature des recettes, extensible par l'administrateur
require FKC_ROOT . 'Core/Revenu.php';           // Registre universel des revenus (toutes sources, tous packs)
require FKC_ROOT . 'Core/RevenuReglement.php';  // Encaissements, rapprochement, ecarts de change
/*
 * MOTEUR COMMUN DES REVENUS NUMÉRIQUES (1.779.0) — streaming, plateformes,
 * distributeurs, pour TOUS les packs qui le déclarent : période économique,
 * ventilation par exercice, produits à recevoir, contrepassation,
 * rapprochement, rapports attendus, contrôle de cut-off à la clôture.
 * Chargé AVANT les modules : Royalty et Revenu s'y adossent.
 */
require FKC_ROOT . 'Core/Creative/Programmation.php';   // Socle Programmation & diffusion (lot 2)
require FKC_ROOT . 'Modules/Creative/Controllers/ProgrammationController.php';
require FKC_ROOT . 'Core/Creative/ProductionAv.php';    // Socle Production audiovisuelle (lot 3)
require FKC_ROOT . 'Modules/Creative/Controllers/ProductionAvController.php';
require FKC_ROOT . 'Core/Creative/Metier.php';        // Inventaire metier des 17 secteurs creatifs (lot 1)
require FKC_ROOT . 'Modules/Creative/Controllers/CreativeMetierController.php';
require FKC_ROOT . 'Core/RevenusNumeriques/Periode.php';
require FKC_ROOT . 'Core/RevenusNumeriques/Source.php';
require FKC_ROOT . 'Core/RevenusNumeriques/Comptable.php';
require FKC_ROOT . 'Core/RevenusNumeriques/Registre.php';
require FKC_ROOT . 'Core/RevenusNumeriques/Estimation.php';
require FKC_ROOT . 'Core/RevenusNumeriques/Controle.php';
require FKC_ROOT . 'Modules/RevenusNumeriques/Controllers/RevenusNumeriquesController.php';
require FKC_ROOT . 'Core/ImportProfil.php';     // Formats de rapports reconnus par signature d'en-tete
require FKC_ROOT . 'Core/ImportRun.php';        // Import : staging, analyse, previsualisation, empreinte
require FKC_ROOT . 'Core/Obligation.php';       // Calendrier des obligations declaratives
require FKC_ROOT . 'Core/ARecevoir.php';        // Vue consolidee du a recevoir (factures + revenus)
require FKC_ROOT . 'Core/PolitiqueCompta.php'; // Quand une écriture rejoint les journaux : par origine
require FKC_ROOT . 'Core/Balayeur.php';        // Balayage planifié de la temporisation, toutes sociétés
require FKC_ROOT . 'Core/Valorisation.php';  // Stocks : moteur de valorisation unique (CUMP, coûts d'entrée et de sortie)
require FKC_ROOT . 'Core/StockCompta.php';
require FKC_ROOT . 'Core/InventaireTournant.php'; // Contrôle du stock théorique par comptage physique
require FKC_ROOT . 'Core/Fabrication.php';       // Cycle de production générique, ouvert à tous les packs
require FKC_ROOT . 'Core/ProductionCompta.php'; // Fabrication : matières, en-cours, produits finis, rebuts   // Stocks : trois flux, comptes par nature, méthode paramétrable
require FKC_ROOT . 'Core/Consolidation.php'; // Consolidation multi-sociétés avec éliminations intra-groupe
require FKC_ROOT . 'Core/CockpitData.php'; // Centre de Pilotage : données des cockpits par profil
require FKC_ROOT . 'Core/RoleEngine.php';  // Rôle Engine : rôles génériques, profils de permission, périmètres, seuils
require FKC_ROOT . 'Core/ModeUI.php';      // Mode UI : Simple / Professionnel / Expert + mode déduit du rôle (présentation seule)
require FKC_ROOT . 'Core/Onboarding.php';  // Demarrage : facon de travailler, modules retenus, role (presentation seule)
/*
 * MOTEUR UX (1.530.0) — six couches, un seul moteur, soixante-deux profils.
 *
 * L'ordre de chargement suit la dépendance : les registres déclaratifs
 * d'abord (intentions, familles, packs, vocabulaire, KPI), le contexte
 * ensuite, le moteur en dernier — il les compose tous.
 */
require FKC_ROOT . 'Core/UxIntentions.php';  // Catalogue des intentions : nom stable + écrans candidats
require FKC_ROOT . 'Core/UxFamilles.php';    // Personnalité UX des 11 familles metier
require FKC_ROOT . 'Core/UxPacks.php';       // Profil metier par pack : la difference, jamais le menu entier
require FKC_ROOT . 'Core/UxVocabulaire.php'; // Business Vocabulary Engine : eleves, beneficiaires, producteurs...
require FKC_ROOT . 'Core/UxWorkflows.php';   // Workflow metier : le chemin que suit une affaire (guide, n'autorise pas)
require FKC_ROOT . 'Core/UxKpi.php';         // Registre des KPI metier (declaration, jamais calcul)
require FKC_ROOT . 'Core/UxKpiSocle.php';
require FKC_ROOT . 'Core/UxAlertes.php';      // Complete les alertes metier jusqu'a trois par pack, sur des metriques reelles
require FKC_ROOT . 'Core/UxContexte.php';    // 6e couche : heure, poste, activite en cours, frequence
require FKC_ROOT . 'Core/Ux.php';            // Le moteur : composition et resolution honnete des entrees
require FKC_ROOT . 'Core/Cockpit.php';     // Centre de Pilotage : registre des cockpits (DG, Finance, Commercial, Stock, RH + packs)
require FKC_ROOT . 'Core/CockpitTemplates.php'; // Micro-ERP : cockpits générés par CAPACITÉS déclarées dans le manifest du pack
require FKC_ROOT . 'Core/CockpitPrefs.php';    // Centre de Pilotage : sections masquées, par utilisateur et par cockpit (1.851.0)
require FKC_ROOT . 'Core/Actions.php';          // Action Engine : boucle VOIR → COMPRENDRE → AGIR (un clic depuis le cockpit)
require FKC_ROOT . 'Core/QrMatrix.php';    // Moteur d'Identification : générateur de matrice QR
require FKC_ROOT . 'Core/Barcode.php';     // Moteur d'Identification : rendu SVG codes-barres & QR
require FKC_ROOT . 'Core/PieceVerif.php';  // Vérification publique des pièces remises au client (QR du reçu)
require FKC_ROOT . 'Core/Scan.php';        // Moteur d'Identification & Scan (transversal)
require FKC_ROOT . 'Core/ScanResult.php';         // Résultat structuré : article + lot + expiration + série…
require FKC_ROOT . 'Core/ScanIntent.php';         // Couche Intent : « Ce scan va… » et prochaine action (1.817.0)
require FKC_ROOT . 'Core/ScanWorkspace.php';    // Scan Workspace : écran unique (1.828.0)
require FKC_ROOT . 'Core/ScanIdentification.php'; // Assistant d'identification des codes inconnus + étiquettes métier (1.827.0)
require FKC_ROOT . 'Core/ScanContexte.php';  // Scan Context Engine : recherche texte + contexte deviné
require FKC_ROOT . 'Core/ScanNavigation.php'; // Le code comme porte d'entrée vers les écrans
require FKC_ROOT . 'Core/ScanWorkflow.php';  // Enchaînement de processus pilotés au scan
require FKC_ROOT . 'Core/ScanResolvers.php'; // Branchement des modules sur le moteur de scan
require FKC_ROOT . 'Core/ScanAI.php';        // Smart Scan : IA (alertes péremption, rupture, anomalie)
require FKC_ROOT . 'Core/ScanActions.php';   // Smart Scan : actions contextuelles après scan
require FKC_ROOT . 'Core/ScanSync.php';      // Smart Scan : Device Manager + synchronisation temps réel
require FKC_ROOT . 'Core/Automation.php';
require FKC_ROOT . 'Core/AutomationRules.php'; // Automation Engine : règles métier configurables SANS CODE + planificateur
require FKC_ROOT . 'Core/Insights.php';   // Helpers analytiques génériques : variation, record, streak, anomalie
require FKC_ROOT . 'Core/Assistant.php';
require FKC_ROOT . 'Core/Reports.php';
require FKC_ROOT . 'Core/Forecast.php';
require FKC_ROOT . 'Core/Scenario.php';
require FKC_ROOT . 'Core/Crypto.php';
require FKC_ROOT . 'Core/Security.php';
require FKC_ROOT . 'Core/Vault.php';
require FKC_ROOT . 'Core/ApiKey.php';
require FKC_ROOT . 'Core/LicenseDepot.php';
require FKC_ROOT . 'Core/Api.php';

/*
 * ── SERVICE : FINAKOP CONNECT (1.700.0) ─────────────────────────────────
 *
 * Chargé INCONDITIONNELLEMENT, comme le Service Core des prestations et pour
 * la même raison : le gabarit interroge la pastille de non-lus et les écrans
 * métier proposent le bouton 💬 ; une classe qui n'arriverait qu'avec son
 * contrôleur laisserait ces appels muets sans que rien ne le dise.
 *
 * Le code vit dans app/Services/ et NON dans app/Modules/ : un test
 * d'invariant vérifie qu'aucun module métier ne lit une table connect_*.
 * C'est ce qui permettra, le jour venu, de sortir Connect de l'ERP sans
 * toucher à la comptabilité.
 *
 * L'ordre suit la dépendance : le schéma, la politique, le chiffrement, les
 * droits, le moteur, puis le pont métier qui les compose tous.
 */
require FKC_ROOT . 'Services/Connect/Schema.php';
require FKC_ROOT . 'Services/Connect/Chiffre.php';
require FKC_ROOT . 'Services/Connect/Politique.php';
require FKC_ROOT . 'Services/Connect/Droits.php';
require FKC_ROOT . 'Services/Connect/Connect.php';
require FKC_ROOT . 'Services/Connect/Piece.php';
require FKC_ROOT . 'Services/Connect/Push.php';
require FKC_ROOT . 'Services/Connect/ExterneGarde.php';
require FKC_ROOT . 'Services/Connect/Invite.php';
require FKC_ROOT . 'Services/Connect/Maestro.php';
require FKC_ROOT . 'Services/Connect/Relais.php';
require FKC_ROOT . 'Services/Connect/Appel.php';
require FKC_ROOT . 'Services/Connect/Retention.php';
FKC_Connect_Retention::enregistrerTraitement();
require FKC_ROOT . 'Services/Connect/Controllers/ExterneController.php';
FKC_Connect_Push::enregistrerTraitement();  // producteur de la file différée
require FKC_ROOT . 'Services/Connect/Objet.php';
require FKC_ROOT . 'Services/Connect/Controllers/ConnectController.php';

/* ── Module Comptabilité ─────────────────────────────────────────────────── */
require FKC_ROOT . 'Modules/Comptabilite/Models/Compte.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/PlanComptable.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Journal.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Ecriture.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Migration.php'; // reprise de comptabilité depuis un autre ERP
require FKC_ROOT . 'Modules/Comptabilite/Models/Brouillard.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/EtatCompta.php'; // état de comptabilisation lu en lot pour les listes (1.742.0)
require FKC_ROOT . 'Modules/Comptabilite/Models/EcritureImport.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/PieceJointe.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Rapport.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Etats.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Tresorerie.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Fournisseur.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Reglement.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/ReglementDetail.php';  // Lignes de détail d'un mouvement (1.754.0)
require FKC_ROOT . 'Modules/Comptabilite/Models/Imputation.php'; // imputation d'un encaissement sur les factures qu'il solde
require FKC_ROOT . 'Modules/Comptabilite/Models/Cloture.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/SoldeOuverture.php'; // gestionnaire central des A-nouveaux / solde d'ouverture (1.798.0)
require FKC_ROOT . 'Modules/Comptabilite/Models/ANouveaux.php';   // report des soldes de bilan : previsualisation, unicite, rapprochement N/N+1
require FKC_ROOT . 'Modules/Comptabilite/Models/AffectationResultat.php'; // decision d'affectation du resultat (reserves, RAN, dividendes)
require FKC_ROOT . 'Modules/Comptabilite/Models/Immobilisation.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/FactureFournisseur.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/AvoirFournisseur.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/GrandLivreTiers.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/Lettrage.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/EcritureAnnulation.php'; // annulation d'une ecriture designee (contre-passation / suppression)
require FKC_ROOT . 'Modules/Comptabilite/Models/BonCommande.php';
require FKC_ROOT . 'Modules/Comptabilite/Models/EtatsDgi.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/CompteController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/DeviseController.php'; // cours de change et revalorisation de cloture
require FKC_ROOT . 'Modules/Comptabilite/Controllers/EcritureController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/FaitEcoController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/MigrationController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/RapportController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/DashboardController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/DeuxFacteursController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/TresorerieController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/ClientCptaController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/FournisseurController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/GlController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/ClotureController.php'; // assistant de cloture : etapes, controles, affectation, a-nouveaux
require FKC_ROOT . 'Modules/Comptabilite/Controllers/ImmoController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/AchatController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/DemandeAchatController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/AvoirFournisseurController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/ReceptionController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/GrandLivreController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/BonCommandeController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/EtatsDgiController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/ExtractionController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/EtatsFinanciersController.php';
require FKC_ROOT . 'Modules/Comptabilite/Controllers/BrouillardController.php';

/* ── Module Analytique (contrôle de gestion par division) ────────────────── */
require FKC_ROOT . 'Modules/Analytique/Models/Division.php';
require FKC_ROOT . 'Modules/Analytique/Models/Axe.php';
require FKC_ROOT . 'Modules/Analytique/Models/Budget.php';
require FKC_ROOT . 'Modules/Analytique/Models/Valeur.php';
require FKC_ROOT . 'Modules/Analytique/Models/AnalytiqueLink.php';
require FKC_ROOT . 'Modules/Analytique/Models/AnaExercice.php';
require FKC_ROOT . 'Modules/Analytique/Models/Affectation.php';
require FKC_ROOT . 'Modules/Analytique/Models/Repartition.php';
require FKC_ROOT . 'Modules/Analytique/Models/AnaAuxiliaire.php';
require FKC_ROOT . 'Modules/Analytique/Models/Ventilation.php';
require FKC_ROOT . 'Modules/Analytique/Models/AnaRegle.php';
require FKC_ROOT . 'Modules/Analytique/Models/AnaDetail.php';
require FKC_ROOT . 'Modules/Analytique/Models/AnaCroise.php';
require FKC_ROOT . 'Modules/Analytique/Models/BudgetMensuel.php';
require FKC_ROOT . 'Modules/Analytique/Models/AnaKpi.php';
require FKC_ROOT . 'Modules/Analytique/Controllers/AnalytiqueController.php';

/* ── Hub Paramètres (réglages & personnalisations) ───────────────────────── */
require FKC_ROOT . 'Modules/Parametres/Models/Maintenance.php';
require FKC_ROOT . 'Modules/Parametres/Models/TresorerieCompte.php';
require FKC_ROOT . 'Modules/Parametres/Models/Exercice.php';
require FKC_ROOT . 'Modules/Parametres/Models/Etablissement.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/ParametresController.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/PackController.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/ModeController.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/UxController.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/HorsLigneController.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/TerminalController.php';
require FKC_ROOT . 'Modules/Terminal/Controllers/TerminalClientController.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/DemarrageController.php';
require FKC_ROOT . 'Modules/Parametres/Controllers/AdminController.php';

/* ── Module Facturation (+ FNE) ──────────────────────────────────────────── */
require FKC_ROOT . 'Modules/Facturation/Models/Client.php';
require FKC_ROOT . 'Modules/Facturation/Models/Facture.php';
require FKC_ROOT . 'Modules/Facturation/Models/ItemFacturation.php';
require FKC_ROOT . 'Modules/Facturation/Services/Fne.php';
require FKC_ROOT . 'Modules/Facturation/Services/FneSpecimens.php';
require FKC_ROOT . 'Modules/Facturation/Controllers/FactureController.php';
require FKC_ROOT . 'Modules/Facturation/Controllers/ClientController.php';
require FKC_ROOT . 'Modules/Facturation/Controllers/ItemController.php';
require FKC_ROOT . 'Modules/Facturation/Controllers/FneController.php';

/* ── Module Paie (LF 2026 CI) ────────────────────────────────────────────── */
require FKC_ROOT . 'Modules/Paie/Models/PaieParams.php';
require FKC_ROOT . 'Modules/Paie/Models/Employe.php';
require FKC_ROOT . 'Modules/Paie/Models/EmployeRubrique.php';
require FKC_ROOT . 'Modules/Paie/Models/Absence.php';
require FKC_ROOT . 'Modules/Paie/Models/Conge.php';
require FKC_ROOT . 'Modules/Notifications/Models/Notification.php';
require FKC_ROOT . 'Modules/Workflow/Models/Validation.php';
require FKC_ROOT . 'Modules/Workflow/Models/Engine.php';
require FKC_ROOT . 'Modules/Workflow/Controllers/ProcessusController.php';
require FKC_ROOT . 'Modules/Vertical/Models/Dossier.php';      // moteur vertical générique : objet central des packs déclaratifs
require FKC_ROOT . 'Modules/Vertical/Models/Piece.php';        // pièces facturables → workflow → écriture automatique
require FKC_ROOT . 'Modules/Vertical/Models/Stock.php';        // brique stock optionnelle (commerce de détail : CUMP)
require FKC_ROOT . 'Modules/Vertical/Models/Categorie.php';    // catalogue libre : catégories du commerçant (BAZ-001)
require FKC_ROOT . 'Modules/Vertical/Models/Attribut.php';     // caractéristiques produit dynamiques (BAZ-002)
require FKC_ROOT . 'Modules/Vertical/Models/Capacite.php';     // capacités activables par article (ELM-001)
require FKC_ROOT . 'Modules/Vertical/Models/Tarif.php';        // prix multi-niveaux et modes de vente (BAZ-003)
require FKC_ROOT . 'Modules/Vertical/Models/ApresVente.php';   // garantie, SAV, livraison, installation (ELM-002)
require FKC_ROOT . 'Modules/Vertical/Models/Marque.php';       // marques et modèles (ELM-004)
require FKC_ROOT . 'Modules/Vertical/Models/ArticleLie.php';   // accessoires et articles liés (ELM-005)
require FKC_ROOT . 'Modules/Vertical/Models/Document.php';     // documents imprimables (COM-050)
require FKC_ROOT . 'Modules/Vertical/Models/Lot.php';          // lots, péremption, découpe (COM-060)
require FKC_ROOT . 'Modules/Vertical/Models/Depot.php';        // multi-magasins et multi-dépôts (COM-070)
require FKC_ROOT . 'Modules/Vertical/Models/Serie.php';        // suivi individuel IMEI/numéros de série (COM-010)
require FKC_ROOT . 'Modules/Vertical/Models/Unite.php';        // unités de vente multiples, carton vs pièce (COM-023)
require FKC_ROOT . 'Modules/Vertical/Models/Commande.php';     // devis→commande→livraison→facture (COM-045)
require FKC_ROOT . 'Modules/Vertical/Models/Pilotage.php';     // pilotage BI générique des packs déclaratifs
require FKC_ROOT . 'Modules/Vertical/Models/StockPilotage.php'; // pilotage BI des packs à stock (vp_mouvements)
require FKC_ROOT . 'Modules/Vertical/ScanIntegration.php';     // le dossier vertical devient scannable (43 packs sans brique stock)
require FKC_ROOT . 'Modules/Sante/Models/Praticien.php';       // module Santé (clinique & hôpital)
require FKC_ROOT . 'Modules/Sante/Models/Planning.php';
require FKC_ROOT . 'Modules/Sante/Models/Hospit.php';
require FKC_ROOT . 'Modules/Sante/Models/Dossier.php';   // Dossier médical électronique (DME)
require FKC_ROOT . 'Modules/Sante/Models/Facturation.php'; // créance patient, tiers payant, rétrocessions (1.858.0)
require FKC_ROOT . 'Modules/Sante/Models/TiersPayant.php'; // conventions, bordereaux et rejets de prise en charge (1.859.0)
require FKC_ROOT . 'Modules/Sante/Controllers/SanteController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VerticalController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpStockController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpCommandeController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpCategorieController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpAttributController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpTarifController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpApresVenteController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpMarqueController.php';
require FKC_ROOT . 'Modules/Vertical/Controllers/VpDocumentController.php';
/*
 * BUG CORRIGÉ (audit 1.520.47) — la route 'royalties/production' vise
 * FKC_IndustrieController depuis longtemps (Creative Suite réutilise
 * volontairement son écran de fabrication, voir plus bas), mais la classe
 * n'était require qu'à l'intérieur du bloc conditionnel « pack industrie
 * actif ». Toute société sous licence Creative (pack ≠ industrie) qui
 * cliquait sur ce lien tombait sur une erreur 500 : « classe absente ».
 * Chargée ici, inconditionnellement, comme le reste des contrôleurs
 * partagés — la classe elle-même n'a aucune dépendance au pack actif
 * (voir son commentaire moduleBase()).
 */
require_once FKC_ROOT . 'Packs/industrie/Controllers/IndustrieController.php';
require FKC_ROOT . 'Modules/Ged/Models/Document.php';
require FKC_ROOT . 'Modules/Encaissement/Models/Recu.php';
require FKC_ROOT . 'Modules/Paie/Models/Bulletin.php';
require FKC_ROOT . 'Modules/Paie/Models/OdPaie.php';
require FKC_ROOT . 'Modules/Paie/Models/Declaration.php';
require FKC_ROOT . 'Modules/Paie/Models/Paiement.php';
require FKC_ROOT . 'Modules/Paie/Controllers/EmployeController.php';
require FKC_ROOT . 'Modules/Paie/Controllers/BulletinController.php';
require FKC_ROOT . 'Modules/Paie/Controllers/ParamController.php';
require FKC_ROOT . 'Modules/Paie/Controllers/OdPaieController.php';
require FKC_ROOT . 'Modules/Paie/Controllers/DeclarationController.php';
require FKC_ROOT . 'Modules/Paie/Controllers/AbsenceController.php';
require FKC_ROOT . 'Modules/Paie/Controllers/PaiementController.php';
require FKC_ROOT . 'Modules/Paie/Controllers/AnnuelController.php';
require FKC_ROOT . 'Modules/Notifications/Controllers/NotificationController.php';
require FKC_ROOT . 'Modules/Workflow/Controllers/ValidationController.php';
require FKC_ROOT . 'Modules/Ged/Controllers/GedController.php';
require FKC_ROOT . 'Modules/Encaissement/Controllers/RecuController.php';

/* ── Module Fiscalité (DGI CI, LF 2026) ──────────────────────────────────── */
require FKC_ROOT . 'Modules/Fiscalite/Models/FiscalConfig.php';
require FKC_ROOT . 'Modules/Fiscalite/Models/Fiscalite.php';
require FKC_ROOT . 'Modules/Fiscalite/Models/CaPrevisionnel.php';   // CA prévisionnel, base de la taxe forfaitaire (1.749.0)
require FKC_ROOT . 'Modules/Fiscalite/Models/DeclarationFiscale.php';
require FKC_ROOT . 'Modules/Fiscalite/Models/Declaration.php';
require FKC_ROOT . 'Modules/Fiscalite/Models/Reprise.php';   // Reprise des comptes fiscaux hérités (1.558.0)
require FKC_ROOT . 'Modules/Fiscalite/Controllers/FiscaliteController.php';

/* ── Référentiels tiers (clients & fournisseurs, SYSCOHADA/DGI) ───────────── */
require FKC_ROOT . 'Modules/Social/Models/Membre.php';        // Socle Membres & Adhesions (famille Economie Sociale)
require FKC_ROOT . 'Modules/Social/Models/Adhesion.php';
require FKC_ROOT . 'Modules/Social/Models/Cotisation.php';
require FKC_ROOT . 'Modules/Social/Models/Carte.php';
require FKC_ROOT . 'Modules/Social/Models/Gouvernance.php';
require FKC_ROOT . 'Modules/Social/Models/Don.php';
require FKC_ROOT . 'Modules/Social/Controllers/SocialController.php';
require FKC_ROOT . 'Modules/Referentiels/Controllers/RefClientController.php';
require FKC_ROOT . 'Modules/Referentiels/Controllers/RefFournisseurController.php';

/* ── Module Recouvrement (gestion du poste client / créances) ─────────────── */
require FKC_ROOT . 'Modules/Recouvrement/Models/Politiques.php'; // relances et validation : la politique interne de CHAQUE société
require FKC_ROOT . 'Modules/Recouvrement/Models/Recouvrement.php';
require FKC_ROOT . 'Modules/Recouvrement/Models/Sources.php';
require FKC_ROOT . 'Modules/Recouvrement/Models/Pilotage.php'; // photos mensuelles, DSO 90 j, CEI, promesses, agents (1.841.5) // registre des sources de créances : cotisations, pièces, scolarité, loyers (1.841.2)
require FKC_ROOT . 'Modules/Depreciation/Models/CreditManagement.php'; // limites, garanties, exposition nette, perte attendue
require FKC_ROOT . 'Modules/Depreciation/Models/Depreciation.php'; // créances douteuses et litigieuses : analyse, dotation, reprise, relevé DGI
require FKC_ROOT . 'Modules/Recouvrement/Models/RecouvrementSuivi.php';
require FKC_ROOT . 'Modules/Recouvrement/Models/Relances.php'; // file de relance + envoi, partagés écran/Maestro (1.847.0)
require FKC_ROOT . 'Core/Maestro/Ponts.php';        // Maestro : plans d'action réels branchés sur les modules (1.847.0)
require FKC_ROOT . 'Modules/Recouvrement/Controllers/RecouvrementController.php';
require FKC_ROOT . 'Modules/Recouvrement/Controllers/PolitiqueController.php';
require FKC_ROOT . 'Modules/Depreciation/Controllers/DepreciationController.php';
require FKC_ROOT . 'Modules/Depreciation/Controllers/CreditController.php';

/* ── Module RH / SIRH (s'appuie sur les employés de paie) ─────────────────── */
require FKC_ROOT . 'Modules/Rh/Models/Departement.php';
require FKC_ROOT . 'Modules/Rh/Models/Conge.php';
require FKC_ROOT . 'Modules/Rh/Models/Avance.php';
require FKC_ROOT . 'Modules/Rh/Models/Mission.php';
require FKC_ROOT . 'Modules/Rh/Models/Recrutement.php';
require FKC_ROOT . 'Modules/Rh/Models/Document.php';
require FKC_ROOT . 'Modules/Rh/Models/Historique.php';
require FKC_ROOT . 'Modules/Rh/Models/Pointage.php';
require FKC_ROOT . 'Modules/Rh/Models/Dashboard.php';
require FKC_ROOT . 'Modules/Rh/Models/Reporting.php';
require FKC_ROOT . 'Modules/Rh/Controllers/RhController.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Categorie.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Entrepot.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Article.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Stock.php';
require FKC_ROOT . 'Modules/Inventaire/Models/InvComptable.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Mouvement.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Valorisation.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Achat.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Reception.php';
require FKC_ROOT . 'Modules/Inventaire/Models/FournisseurEval.php';
require FKC_ROOT . 'Modules/Inventaire/Models/Reappro.php';
require FKC_ROOT . 'Modules/Inventaire/Models/InvDashboard.php';
require FKC_ROOT . 'Modules/Inventaire/Models/InvReporting.php';
require FKC_ROOT . 'Modules/Inventaire/Controllers/InventaireController.php';
require FKC_ROOT . 'Modules/Scan/Controllers/ScanController.php';
require FKC_ROOT . 'Modules/Caisse/Models/Caisse.php';
require FKC_ROOT . 'Modules/Caisse/Models/PosConfig.php';
require FKC_ROOT . 'Modules/Caisse/Models/Pos.php';
require FKC_ROOT . 'Modules/Caisse/Controllers/CaisseController.php';
require FKC_ROOT . 'Modules/Caisse/Controllers/PosController.php';
require FKC_ROOT . 'Core/PosEcritures.php'; // schémas comptables de la caisse (socle)
require FKC_ROOT . 'Core/StockProviders.php'; // fédère les 2 moteurs de stock (VpStock + Inventaire) dans FKC_Stock

/* ── Label musical (édition Creative) ─────────────────────────────────────── */
require FKC_ROOT . 'Modules/Royalties/Models/LabelComptable.php';
require FKC_ROOT . 'Modules/Royalties/Models/DistributionComptable.php';
require FKC_ROOT . 'Modules/Royalties/Models/Artiste.php';
require FKC_ROOT . 'Modules/Royalties/Models/Oeuvre.php';
require FKC_ROOT . 'Modules/Royalties/Models/Projet.php';
require FKC_ROOT . 'Modules/Royalties/Models/Royalty.php';
require FKC_ROOT . 'Modules/Royalties/Models/RoyaltyRevision.php'; // Etat de controle des attributions passees (lecture seule)
require FKC_ROOT . 'Modules/Royalties/Models/Paiement.php';
require FKC_ROOT . 'Modules/Royalties/Models/RoyaltyEncaissement.php';
require FKC_ROOT . 'Modules/Royalties/Models/Concert.php';
require FKC_ROOT . 'Modules/Royalties/Models/Catalogues.php';
require FKC_ROOT . 'Modules/Royalties/Models/Portail.php';
require FKC_ROOT . 'Modules/Royalties/Models/LabelDashboard.php';
require FKC_ROOT . 'Modules/Royalties/Models/Contrat.php';
/*
 * SPLITS PAR TITRE — chargés avec le module Royalties (1.744.0).
 * Ils n'étaient chargés que lorsque le pack Creative était le pack PRINCIPAL :
 * sous un autre pack (Studio, Distribution musicale…), un featuring ou une
 * part propre à un titre ne pouvait tout simplement pas être saisi.
 */
require_once FKC_ROOT . 'Packs/creative/Models/Splits.php';
require_once FKC_ROOT . 'Packs/creative/Models/Bareme.php';
require FKC_ROOT . 'Modules/Royalties/Controllers/RoyaltiesController.php';

/* ── Analyse (Insights) — toutes éditions, fonctions selon l'édition ───────── */
require FKC_ROOT . 'Modules/Analyse/Models/Metrics.php';
require FKC_ROOT . 'Modules/Analyse/Models/Marge.php';
require FKC_ROOT . 'Modules/Analyse/Models/Rapports.php';
require FKC_ROOT . 'Modules/Analyse/Models/Warehouse.php';
require FKC_ROOT . 'Modules/Analyse/Models/Kpi.php';
require FKC_ROOT . 'Modules/Analyse/Models/Alerte.php';
require FKC_ROOT . 'Modules/Analyse/Controllers/AnalyseController.php';
require FKC_ROOT . 'Modules/Analyse/Controllers/MaestroController.php'; // Cockpit Maestro + API interne partagée

/* ── Sécurité (administration) ─────────────────────────────────────────────── */
require FKC_ROOT . 'Modules/Securite/Controllers/SecuriteController.php';
require FKC_ROOT . 'Modules/Securite/Controllers/RolesController.php';

/* ── Aide & documentation utilisateur ──────────────────────────────────────── */
require FKC_ROOT . 'Modules/Aide/Models/Aide.php';
require FKC_ROOT . 'Modules/Aide/Models/AidePlateforme.php';
require FKC_ROOT . 'Modules/Aide/Controllers/AideController.php';

/* ── Console API (écran du module API) ─────────────────────────────────────── */
require FKC_ROOT . 'Modules/Api/Controllers/ApiConsoleController.php';

/* ── Gestion des utilisateurs (admin) ────────────────────────────────────── */
require FKC_ROOT . 'Modules/Utilisateurs/Models/User.php';
require FKC_ROOT . 'Modules/Utilisateurs/Controllers/UserController.php';

/* ── Module Multi-sociétés (registre cabinet) ────────────────────────────── */
require FKC_ROOT . 'Modules/Societes/Models/Societe.php';
require FKC_ROOT . 'Modules/Societes/Controllers/SocieteController.php';
require FKC_ROOT . 'Modules/Societes/Controllers/CabinetRetourController.php'; // retour depuis le dossier d'un client (1.803.0)

/*
 * Chargement HORS REQUÊTE (1.875.6) : WP-Cron, WP-CLI, cron système du mode
 * autonome. Toutes les classes sont déclarées ci-dessus ; on s'arrête avant
 * l'API, la session et les routes, qui supposent une requête HTTP. Voir
 * app/noyau.php, seul appelant légitime.
 */
if ( defined( 'FKC_CLI_NOYAU' ) ) { return; }

/* ── API REST (JSON) : court-circuite la pile HTML/session ─────────────────── */
$fkc_req_path = fkc_rel_path();
if ( 'api' === $fkc_req_path || 0 === strpos( $fkc_req_path, 'api/' ) ) {
	FKC_Master::conn();
	FKC_Api::handle( $fkc_req_path );
	exit;
}

/* ── Session ─────────────────────────────────────────────────────────────── */
if ( PHP_SESSION_NONE === session_status() ) {
	FKC_Security::cookieParams(); // cookies HttpOnly/Secure/SameSite AVANT démarrage
	session_name( defined( 'FKC_SESSION_NAME' ) ? (string) FKC_SESSION_NAME : 'FKC_SESSION' ); // un nom par client en plateforme (1.876.0)
	session_start();
}

/* ── Registre cabinet + licence ──────────────────────────────────────────── */
FKC_Master::conn();       // registre central : migrations + adoption d'une install existante
// Contrôles de session (inactivité, durée absolue, sceau de mot de passe) :
// une seule fois par requête, avant toute décision d'autorisation.
FKC_Auth::enforceSession();
FKC_License::bootstrap(); // compat. : lit une éventuelle clé locale héritée ; sinon Starter local via resolve()

/*
 * La société de la session est branchée ICI, avant le pack et avant les routes.
 *
 * Les routes des packs métiers sont enregistrées sous condition
 * (« if ( 'retail' === FKC_Packs::activeCode() ) »), et le pack est un paramètre
 * de la société. Tant que sa base n'était branchée que par le middleware — donc
 * pendant le dispatch — ce test lisait « generique » et les routes du pack
 * n'étaient jamais enregistrées : /retail/menu répondait 404 alors que le pack
 * était bien actif. Aucune re-résolution ultérieure ne pouvait y remédier : une
 * route ne s'enregistre plus une fois le dispatch commencé.
 *
 * Le contrôle d'autorisation n'est pas contourné : prebind() ne branche que pour
 * un utilisateur authentifié, et passe par FKC_Tenant::current(), qui vérifie
 * l'accès à chaque appel. Le middleware « societe » reste en place et conserve
 * la charge de rediriger vers le sélecteur.
 */
FKC_Tenant::prebind();

// Pont Achats → Immobilisations (1.856.0) : notification dès qu'une facture
// d'achat impute un bien en classe 2.
FKC_Immobilisation::brancher();
FKC_Packs::boot();        // pack métier actif : code du pack + terminologie + événements comptables
FKC_Security::boot();     // en-têtes de sécurité + invisibilité moteurs + protection des données
FKC_License_Depot::enregistrerTraitement();
/*
 * Consultation du dépôt de licences : AUCUN APPEL RÉSEAU ICI — cette ligne
 * met en file, au plus une fois par jour. C'est la correction de fond du
 * défaut de l'ancienne vérification distante, qui appelait un serveur au
 * milieu de la requête d'un utilisateur.
 */
FKC_License_Depot::programmerSiUtile();
FKC_Assistant::brancherForecast(); // pont Forecast → Assistant : les prévisions des packs alimentent le briefing
/*
 * Les relations de Connect entrent au registre des orphelins : une
 * conversation dont la facture a disparu doit apparaître au Centre
 * d'intégrité, pas se taire.
 *
 * La méthode ne déclare RIEN tant que les tables n'existent pas — voir son
 * corps. Un registre qui nomme une table absente n'est pas un registre
 * prudent, c'est un détecteur faux : il compte des relations qu'il ne peut
 * pas contrôler, et le rapport d'intégrité perd sa valeur d'affirmation.
 */
if ( class_exists( 'FKC_Connect_Objet' ) ) { FKC_Connect_Objet::declarerOrphelins(); }

/*
 * TEMPORISATION COMPTABLE — balayage opportuniste.
 *
 * Les écritures réglées en mode « temporisé » attendent leur délai au journal
 * temporaire, puis rejoignent les journaux. Il faut bien que quelqu'un
 * regarde l'horloge : faute de tâche planifiée (voir la note dans
 * FKC_PolitiqueCompta::balayer(), qui explique pourquoi le multi-sociétés
 * l'interdit ici), on regarde à l'occasion d'une requête, sur le dossier déjà
 * ouvert et déjà autorisé — au plus une fois par quart d'heure.
 *
 * Enveloppé et silencieux : un incident de publication ne doit pas empêcher
 * l'utilisateur d'ouvrir son application. Ce qui n'a pas pu être publié reste
 * au journal temporaire, avec son motif, et sera repris au passage suivant.
 */
if ( class_exists( 'FKC_PolitiqueCompta' ) && FKC_Tenant::id() ) {
	try { FKC_PolitiqueCompta::balayer(); } catch ( \Throwable $e ) { fkc_log( 'temporisation : ' . $e->getMessage(), 'WARN' ); }
}

/* ── Routes ──────────────────────────────────────────────────────────────── */
$router = new FKC_Router();

// Authentification.
$router->get( 'login',  'FKC_DashboardController@showLogin' );
$router->post( 'login', 'FKC_DashboardController@login' );
// Double authentification (1.876.2) : second facteur, puis gestion par l'utilisateur.
$router->get(  'verification', 'FKC_DeuxFacteursController@showVerification' );
$router->post( 'verification', 'FKC_DeuxFacteursController@verifier' );
$router->post( 'verification/renvoyer', 'FKC_DeuxFacteursController@renvoyer' );
$router->get(  'securite/deux-facteurs',            'FKC_DeuxFacteursController@show',       array( 'auth' => true, 'sans_licence' => true, 'sans_2fa' => true ) );
$router->post( 'securite/deux-facteurs/activer',    'FKC_DeuxFacteursController@activer',    array( 'auth' => true, 'sans_licence' => true, 'sans_2fa' => true ) );
$router->post( 'securite/deux-facteurs/desactiver', 'FKC_DeuxFacteursController@desactiver', array( 'auth' => true, 'sans_licence' => true, 'sans_2fa' => true ) );
$router->post( 'securite/deux-facteurs/email/envoyer', 'FKC_DeuxFacteursController@emailEnvoyer', array( 'auth' => true, 'sans_licence' => true, 'sans_2fa' => true ) );
$router->post( 'securite/deux-facteurs/email/activer', 'FKC_DeuxFacteursController@emailActiver', array( 'auth' => true, 'sans_licence' => true, 'sans_2fa' => true ) );
$router->post( 'logout','FKC_DashboardController@logout', array( 'auth' => true, 'sans_mdp' => true, 'sans_licence' => true, 'sans_2fa' => true ) );

// Mot de passe du compte. « sans_mdp » : ces deux routes restent servies quand
// le changement de mot de passe est imposé — ce sont les seules, sinon le
// verrou serait inéchappable.
$router->get(  'mot-de-passe', 'FKC_DashboardController@showMotDePasse', array( 'auth' => true, 'sans_mdp' => true, 'sans_licence' => true, 'sans_2fa' => true ) );
$router->post( 'mot-de-passe', 'FKC_DashboardController@changerMotDePasse', array( 'auth' => true, 'sans_mdp' => true, 'sans_licence' => true, 'sans_2fa' => true ) );

// Accueil + licence.
$router->get( '',        'FKC_DashboardController@home', array( 'auth' => true, 'societe' => true ) );
/*
 * Sections différées du Centre de Pilotage : rendues côté serveur, servies
 * après l'affichage de la page. Mêmes garanties que le cockpit lui-même —
 * authentification, société branchée — et le gestionnaire revérifie que le
 * cockpit demandé est bien accessible au profil.
 */
$router->get( 'cockpit/section/{cockpit}/{id}', 'FKC_DashboardController@section', array( 'auth' => true, 'societe' => true ) );
/* Dossier de conseil : version imprimable du cockpit, sections différées comprises. */
$router->get( 'cockpit/dossier', 'FKC_DashboardController@dossier', array( 'auth' => true, 'societe' => true ) );
$router->post( 'cockpit/preferences', 'FKC_DashboardController@cockpitPreferences', array( 'auth' => true, 'societe' => true ) ); // sections masquées (1.851.0)
$router->post( 'cockpit/action', 'FKC_DashboardController@cockpitAction', array( 'auth' => true, 'societe' => true ) ); // boucle « agir » : actions déclenchées depuis un cockpit
/* ── Mode d'affichage (Simple / Professionnel / Expert) ──────────────────
   La bascule est en POST : changer de mode écrit une préférence en base ; un
   GET l'aurait rendue déclenchable par un simple lien, y compris préchargé.
   L'écran de réglage et « Mon activité » sont volontairement hors de tout
   module — ils doivent rester atteignables quel que soit le mode. */
$router->post( 'mode',            'FKC_ModeController@basculer', array( 'auth' => true ) );
$router->get(  'parametres/mode', 'FKC_ModeController@reglage',  array( 'auth' => true ) );
$router->get(  'mon-activite',    'FKC_ModeController@activite', array( 'auth' => true, 'societe' => true ) );

/* ── Barre transversale (lot UX-4 : + ACTION · RECHERCHE · ALERTES · COPILOT) ──
   Quatre points d'entrée en LECTURE SEULE, servis en JSON. Aucun n'écrit :
   les actions d'écriture restent servies par « cockpit/action », qui porte
   déjà CSRF, contrôle de rôle et journalisation. Dupliquer ce chemin ici
   aurait dupliqué ses gardes — donc créé l'occasion d'en oublier une. */
$router->get( 'ux/actions',   'FKC_UxController@actions',   array( 'auth' => true, 'societe' => true ) );
$router->get( 'ux/recherche', 'FKC_UxController@recherche', array( 'auth' => true, 'societe' => true ) );
$router->get( 'ux/alertes',   'FKC_UxController@alertes',   array( 'auth' => true, 'societe' => true ) );
$router->get( 'ux/copilot',   'FKC_UxController@copilot',   array( 'auth' => true, 'societe' => true ) );
/* Aperçu du profil UX résolu : diagnostic avant d'être démonstration. */
$router->get( 'parametres/ux', 'FKC_UxController@apercu',   array( 'auth' => true, 'societe' => true ) );

/* ── Démarrage (parcours de réglage, métier fixé par la licence) ─────────
   Les trois réponses arrivent d'un bloc : un assistant à trois pages aurait
   exigé de conserver l'état entre les requêtes — donc un état à purger, donc
   un état qui se désynchronise. */
$router->get(  'demarrage',          'FKC_DemarrageController@index',       array( 'auth' => true, 'societe' => true ) );
$router->post( 'demarrage',          'FKC_DemarrageController@enregistrer', array( 'auth' => true, 'societe' => true ) );
$router->get(  'demarrage/pret',     'FKC_DemarrageController@pret',        array( 'auth' => true, 'societe' => true ) );
$router->post( 'demarrage/ecarter',  'FKC_DemarrageController@ecarter',     array( 'auth' => true, 'societe' => true ) );
$router->get( 'licence', 'FKC_DashboardController@license', array( 'auth' => true, 'sans_licence' => true ) );
$router->post( 'licence','FKC_DashboardController@activateLicense', array( 'auth' => true, 'sans_licence' => true ) );

// Lanceurs de module (accueil en accordéons de boutons CTA). Enregistrés tôt : littéraux « module/menu ».
$router->get( 'comptabilite/menu', 'FKC_LauncherController@comptabilite', array( 'auth' => true, 'societe' => true ) );
$router->get( 'comptabilite/menu/{sec}', 'FKC_LauncherController@comptabiliteSection', array( 'auth' => true, 'societe' => true ) );
$router->get( 'analyse/menu',       'FKC_LauncherController@analyse',      array( 'auth' => true, 'societe' => true, 'module' => 'analyse' ) );
$router->get( 'facturation/menu',  'FKC_LauncherController@facturation',  array( 'auth' => true, 'societe' => true, 'module' => 'facturation' ) );
$router->get( 'analytique/menu',   'FKC_LauncherController@analytique',   array( 'auth' => true, 'societe' => true, 'module' => 'analytique' ) );
$router->get( 'fiscalite/menu',    'FKC_LauncherController@fiscalite',    array( 'auth' => true, 'societe' => true, 'module' => 'fiscalite' ) );
$router->get( 'recouvrement/menu', 'FKC_LauncherController@recouvrement', array( 'auth' => true, 'societe' => true, 'module' => 'recouvrement' ) );
$router->get( 'inventaire/menu',   'FKC_LauncherController@inventaire',   array( 'auth' => true, 'societe' => true, 'module' => 'inventaire' ) );
$router->get( 'scan/menu',         'FKC_LauncherController@scan',         array( 'auth' => true, 'societe' => true, 'module' => 'scan' ) );
$router->get( 'caisse/menu',       'FKC_LauncherController@caisse',       array( 'auth' => true, 'societe' => true, 'module' => 'caisse' ) );
$router->get( 'rh/menu',           'FKC_LauncherController@rh',           array( 'auth' => true, 'societe' => true, 'module' => 'rh' ) );
$router->get( 'paie/menu',         'FKC_LauncherController@paie',         array( 'auth' => true, 'societe' => true, 'module' => 'paie' ) );
$router->get( 'royalties/menu',    'FKC_LauncherController@royalties',    array( 'auth' => true, 'societe' => true, 'module' => 'royalties' ) );
// Lanceurs des modules apportés par le pack métier actif (générique : « {module}/menu »).
foreach ( array_keys( FKC_Packs::navRoutes() ) as $fkc_pack_mod ) {
	$router->get( $fkc_pack_mod . '/menu', function () use ( $fkc_pack_mod ) {
		$c = new FKC_LauncherController(); return $c->pack( $fkc_pack_mod );
	}, array( 'auth' => true, 'societe' => true, 'module' => $fkc_pack_mod ) );
}

/* ── Packs déclaratifs (moteur vertical générique) : dossiers & pièces ──────
 * Le principal (verticalModule(), inchangé) PUIS, pour le vrai multi-pack,
 * chaque pack COMPLÉMENTAIRE qui déclare lui aussi un bloc 'vertical' — sous
 * SON PROPRE code de module, jamais celui du principal. Vide par défaut :
 * aucune route en plus pour une société qui n'a jamais activé de
 * complémentaire. FKC_Router::dispatch() positionne FKC_Packs::requestModule()
 * avant d'appeler FKC_VerticalController, qui sert alors le BON pack sans
 * avoir lui-même besoin de savoir qu'il en existe plusieurs. */
$fkc_vp_modules = array();
$fkc_vp = FKC_Packs::verticalModule();
if ( $fkc_vp ) { $fkc_vp_modules[ $fkc_vp ] = FKC_Packs::active(); }
foreach ( FKC_Packs::complementaires() as $fkc_pc ) {
	$fkc_pcm = FKC_Packs::get( $fkc_pc );
	if ( empty( $fkc_pcm['vertical'] ) ) { continue; }
	$fkc_pcmod = (string) ( $fkc_pcm['vertical']['module'] ?? $fkc_pc );
	if ( $fkc_pcmod && ! isset( $fkc_vp_modules[ $fkc_pcmod ] ) ) { $fkc_vp_modules[ $fkc_pcmod ] = $fkc_pcm; }
}
/*
 * PRIORITÉ AU DOSSIER MÉDICAL (1.530.0).
 *
 * Défaut trouvé à l'audit : pour la clinique et l'hôpital, deux routes
 * déclaraient le MÊME chemin — « <pack>/dossiers ». Le moteur vertical, déclaré
 * bien plus haut dans ce fichier, gagnait ; le dossier médical électronique
 * (antécédents, allergies, consultations, prescriptions) était donc
 * inatteignable, et personne ne pouvait le savoir en lisant le code : il
 * fallait connaître l'ordre des lignes.
 *
 * Le pack santé sait mieux que le moteur générique ce qu'est un dossier chez
 * lui. On retire donc « /dossiers » du moteur vertical pour ces packs, plutôt
 * que de compter sur un ordre d'enregistrement — une priorité qui dépend de la
 * position d'un bloc dans un fichier de deux mille lignes est une priorité qui
 * se cassera au premier déplacement.
 */
/*
 * 1.858.0 — CE RETRAIT NE SUFFISAIT PAS, ET IL CASSAIT DEUX CHOSES.
 *
 * Seul le GET avait été arbitré. Le POST « /dossiers/{id} » restait au moteur
 * vertical, déclaré le premier : chaque antécédent, allergie, consultation
 * ou prescription saisi dans le DME partait vers la MISE À JOUR DU DOSSIER DE
 * FACTURATION portant le même numéro — libellé vidé, champs effacés, statut
 * remis à « ouvert » — et le soin, lui, n'était jamais enregistré. La
 * création d'un patient (« /dossiers/creer ») subissait le même sort.
 *
 * Dans l'autre sens, le lien vers un dossier de facturation (liste des
 * pièces, recouvrement) ouvrait le DOSSIER MÉDICAL du patient qui portait le
 * même numéro : une fuite de secret médical à chaque clic.
 *
 * Deux objets différents méritent deux chemins. Le DME vit désormais sous
 * « {pack}/dme » ; « {pack}/dossiers » redevient, pour tous les packs, le
 * dossier de facturation du moteur vertical.
 */
$fkc_vp_sans_dossiers = array();
foreach ( $fkc_vp_modules as $fkc_vp => $fkc_vpman ) {
	$vpo = array( 'auth' => true, 'societe' => true, 'module' => $fkc_vp );
	$fkc_dme = isset( $fkc_vp_sans_dossiers[ $fkc_vp ] );
	$router->get(  $fkc_vp,                                   'FKC_VerticalController@dashboard', $vpo );
	$router->get(  $fkc_vp . '/pilotage',                     'FKC_VerticalController@pilotage', $vpo );
	$router->get(  $fkc_vp . '/assistant',                    'FKC_VerticalController@assistant', $vpo );
	$router->get(  $fkc_vp . '/rapports',                     'FKC_VerticalController@rapports', $vpo );
	$router->get(  $fkc_vp . '/previsions',                   'FKC_VerticalController@previsions', $vpo );
	$router->get(  $fkc_vp . '/scenarios',                    'FKC_VerticalController@scenarios', $vpo );
	$router->get(  $fkc_vp . '/automatisations',              'FKC_VerticalController@automatisations', $vpo );
	$router->post( $fkc_vp . '/automatisations',              'FKC_VerticalController@automatisationsSave', $vpo );
	$router->get(  $fkc_vp . '/personnaliser',                'FKC_VerticalController@personnaliser', $vpo );
	$router->post( $fkc_vp . '/personnaliser',                'FKC_VerticalController@personnaliserSave', $vpo );
	if ( ! $fkc_dme ) { $router->get(  $fkc_vp . '/dossiers',                 'FKC_VerticalController@dossiers', $vpo ); }
	$router->get(  $fkc_vp . '/dossiers/nouveau',             'FKC_VerticalController@create', $vpo );
	$router->post( $fkc_vp . '/dossiers',                     'FKC_VerticalController@store', $vpo );
	$router->get(  $fkc_vp . '/pieces',                       'FKC_VerticalController@pieces', $vpo );
	$router->post( $fkc_vp . '/pieces/{id}/action',           'FKC_VerticalController@pieceAction', $vpo );
	$router->get(  $fkc_vp . '/dossiers/{id}/pieces/nouvelle', 'FKC_VerticalController@pieceCreate', $vpo );
	$router->post( $fkc_vp . '/dossiers/{id}/pieces',         'FKC_VerticalController@pieceStore', $vpo );
	$router->get(  $fkc_vp . '/dossiers/{id}/modifier',       'FKC_VerticalController@edit', $vpo );
	if ( ! $fkc_dme ) { $router->get(  $fkc_vp . '/dossiers/{id}',            'FKC_VerticalController@show', $vpo ); }
	$router->post( $fkc_vp . '/dossiers/{id}',                'FKC_VerticalController@update', $vpo );

	// Brique stock (commerce de détail : articles, entrées, ventes au CUMP).
	if ( ! empty( $fkc_vpman['stock'] ) && is_array( $fkc_vpman['stock'] ) ) {
		$router->get(  $fkc_vp . '/stock',                       'FKC_VpStockController@index', $vpo );
		$router->get(  $fkc_vp . '/stock/article/nouveau',       'FKC_VpStockController@articleCreate', $vpo );
		$router->post( $fkc_vp . '/stock/article',               'FKC_VpStockController@articleStore', $vpo );
		$router->get(  $fkc_vp . '/stock/article/{id}/modifier', 'FKC_VpStockController@articleEdit', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}',          'FKC_VpStockController@articleUpdate', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/supprimer', 'FKC_VpStockController@articleDelete', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/restaurer', 'FKC_VpStockController@articleRestore', $vpo );
		$router->get(  $fkc_vp . '/stock/vendre',                'FKC_VpStockController@sellForm', $vpo );
		$router->post( $fkc_vp . '/stock/vendre',                'FKC_VpStockController@sell', $vpo );
		$router->get(  $fkc_vp . '/stock/entrer',                'FKC_VpStockController@entryForm', $vpo );
		$router->post( $fkc_vp . '/stock/entrer',                'FKC_VpStockController@entry', $vpo );
		$router->get(  $fkc_vp . '/stock/mouvements',            'FKC_VpStockController@movements', $vpo );
		$router->get(  $fkc_vp . '/stock/mouvements/{id}/retour', 'FKC_VpStockController@returnForm', $vpo );
		$router->post( $fkc_vp . '/stock/mouvements/{id}/retour', 'FKC_VpStockController@returnStore', $vpo );
		$router->post( $fkc_vp . '/stock/regulariser',           'FKC_VpStockController@regulariser', $vpo );
		$router->post( $fkc_vp . '/stock/prix-ttc',              'FKC_VpStockController@prixTtc', $vpo );
		$router->post( $fkc_vp . '/stock/inventaire',            'FKC_VpStockController@inventaire', $vpo );
		$router->post( $fkc_vp . '/stock/pca-extensions',        'FKC_VpStockController@pcaExtensions', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/unites',              'FKC_VpStockController@uniteStore', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/unites/{uniteId}/supprimer', 'FKC_VpStockController@uniteDelete', $vpo );

		/* Catalogue libre (BAZ-001) : le commerçant crée et range ses rayons. */
		$router->get(  $fkc_vp . '/categories',                  'FKC_VpCategorieController@index', $vpo );
		$router->post( $fkc_vp . '/categories',                  'FKC_VpCategorieController@store', $vpo );
		$router->post( $fkc_vp . '/categories/{id}',             'FKC_VpCategorieController@update', $vpo );
		$router->post( $fkc_vp . '/categories/{id}/basculer',    'FKC_VpCategorieController@toggle', $vpo );
		$router->post( $fkc_vp . '/categories/{id}/supprimer',   'FKC_VpCategorieController@destroy', $vpo );

		/* Magasins et dépôts (COM-070). */
		$router->get(  $fkc_vp . '/stock/depots',    'FKC_VpStockController@depots', $vpo );
		$router->post( $fkc_vp . '/stock/depots',    'FKC_VpStockController@depotStore', $vpo );
		$router->post( $fkc_vp . '/stock/transfert', 'FKC_VpStockController@transfert', $vpo );

		/* Lots, péremption et découpe (COM-060). */
		$router->get(  $fkc_vp . '/stock/lots',  'FKC_VpStockController@lots', $vpo );
		$router->post( $fkc_vp . '/stock/chute', 'FKC_VpStockController@chute', $vpo );

		/* Documents imprimables (COM-050) : reçu, devis, BL, acompte, SAV, garantie. */
		$router->get( $fkc_vp . '/document/{type}/{id}',        'FKC_VpDocumentController@afficher', $vpo );
		$router->get( $fkc_vp . '/document/{type}/{id}/escpos', 'FKC_VpDocumentController@escpos', $vpo );

		/* Marques (ELM-004) et associations d'articles (ELM-005). */
		$router->get(  $fkc_vp . '/marques',                       'FKC_VpMarqueController@index', $vpo );
		$router->post( $fkc_vp . '/marques',                       'FKC_VpMarqueController@store', $vpo );
		$router->post( $fkc_vp . '/marques/{id}',                  'FKC_VpMarqueController@update', $vpo );
		$router->post( $fkc_vp . '/marques/{id}/basculer',         'FKC_VpMarqueController@toggle', $vpo );
		$router->post( $fkc_vp . '/marques/{id}/supprimer',        'FKC_VpMarqueController@destroy', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/lier',       'FKC_VpMarqueController@lier', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/lier/{lid}/retirer', 'FKC_VpMarqueController@delier', $vpo );

		/* Après-vente (ELM-002) : garanties, SAV, livraison, installation. */
		$router->get(  $fkc_vp . '/sav',                     'FKC_VpApresVenteController@index', $vpo );
		$router->post( $fkc_vp . '/sav',                     'FKC_VpApresVenteController@store', $vpo );
		$router->post( $fkc_vp . '/sav/garantie',            'FKC_VpApresVenteController@garantie', $vpo );
		$router->post( $fkc_vp . '/sav/{id}/statut',         'FKC_VpApresVenteController@statut', $vpo );
		$router->post( $fkc_vp . '/sav/{id}/piece',          'FKC_VpApresVenteController@piece', $vpo );
		$router->post( $fkc_vp . '/sav/{id}/cloturer',       'FKC_VpApresVenteController@cloturer', $vpo );
		$router->post( $fkc_vp . '/sav/{id}/annuler',        'FKC_VpApresVenteController@annuler', $vpo );

		/* Tarifs et modes de vente (BAZ-003) : grille multi-niveaux par article. */
		$router->get(  $fkc_vp . '/stock/article/{id}/tarifs',                  'FKC_VpTarifController@index', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/tarifs',                  'FKC_VpTarifController@store', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/tarifs/{tid}/supprimer',  'FKC_VpTarifController@destroy', $vpo );
		$router->post( $fkc_vp . '/stock/article/{id}/modes',                   'FKC_VpTarifController@modes', $vpo );

		/* Caractéristiques produit (BAZ-002) : attributs dynamiques par rayon. */
		$router->get(  $fkc_vp . '/attributs',                   'FKC_VpAttributController@index', $vpo );
		$router->post( $fkc_vp . '/attributs',                   'FKC_VpAttributController@store', $vpo );
		$router->post( $fkc_vp . '/attributs/{id}',              'FKC_VpAttributController@update', $vpo );
		$router->post( $fkc_vp . '/attributs/{id}/basculer',     'FKC_VpAttributController@toggle', $vpo );
		$router->post( $fkc_vp . '/attributs/{id}/supprimer',    'FKC_VpAttributController@destroy', $vpo );

		/* Commandes clients (COM-045) : devis → commande → livraison → facture. */
		$router->get(  $fkc_vp . '/commandes',                   'FKC_VpCommandeController@index', $vpo );
		$router->get(  $fkc_vp . '/commandes/nouveau',           'FKC_VpCommandeController@createForm', $vpo );
		$router->post( $fkc_vp . '/commandes',                   'FKC_VpCommandeController@store', $vpo );
		$router->get(  $fkc_vp . '/commandes/{id}',              'FKC_VpCommandeController@show', $vpo );
		$router->post( $fkc_vp . '/commandes/{id}/confirmer',    'FKC_VpCommandeController@confirmer', $vpo );
		$router->post( $fkc_vp . '/commandes/{id}/annuler',      'FKC_VpCommandeController@annuler', $vpo );
		$router->post( $fkc_vp . '/commandes/{id}/livrer',       'FKC_VpCommandeController@livrer', $vpo );
		$router->post( $fkc_vp . '/commandes/{id}/livrer-etape', 'FKC_VpCommandeController@livrerEtape', $vpo );
		$router->post( $fkc_vp . '/commandes/{id}/facturer',     'FKC_VpCommandeController@facturer', $vpo );
		$router->post( $fkc_vp . '/commandes/{id}/acompte',      'FKC_VpCommandeController@acompte', $vpo );
	}
}

/*
 * MULTI-PACK (chantier P1, 1.520.52) : chaque pack à contrôleur propre
 * (24 blocs ci-dessous, jusqu'à FKC_Packs::activeCode() en ligne ~390 pour
 * le détail du branchement société-avant-routes) enregistrait ses routes
 * uniquement si CE pack était le PRINCIPAL — jamais s'il n'était qu'un
 * complémentaire (voir FKC_Packs::complementaires(), 1.520.42). Seuls les
 * packs déclaratifs (moteur vertical générique) en bénéficiaient déjà.
 * `activeCodes()` (principal + complémentaires) remplace `activeCode()`
 * (principal seul) : zéro effet quand un pack tourne seul (activeCodes()
 * ne contient alors que lui), et chaque pack à contrôleur propre devient
 * activable comme complémentaire, au même titre que les packs déclaratifs.
 */
/* ── Pack Retail : cockpit, POS, stocks, décisionnel ──────────────────────── */
if ( in_array( 'retail', FKC_Packs::activeCodes(), true ) ) {
	$rto = array( 'auth' => true, 'societe' => true, 'module' => 'retail' );
	// Cockpit & pilotage
	$router->get(  'retail',                        'FKC_RetailController@cockpit', $rto );
	$router->get(  'retail/assistant',              'FKC_RetailController@assistant', $rto );
	$router->get(  'retail/pilotage',               'FKC_RetailController@pilotage', $rto );
	$router->get(  'retail/rapports',               'FKC_RetailController@rapports', $rto );
	$router->get(  'retail/previsions',             'FKC_RetailController@previsions', $rto );
	$router->get(  'retail/scenarios',              'FKC_RetailController@scenarios', $rto );
	$router->get(  'retail/personnaliser',          'FKC_RetailController@personnaliser', $rto );
	$router->post( 'retail/personnaliser',          'FKC_RetailController@personnaliserSave', $rto );
	$router->get(  'retail/automatisations',        'FKC_RetailController@automatisations', $rto );
	$router->post( 'retail/automatisations/executer','FKC_RetailController@automatisationsRun', $rto );
	$router->get(  'retail/decision',               'FKC_RetailController@decision', $rto );
	$router->get(  'retail/analyse',                'FKC_RetailController@analyse', $rto );
	// POS
	$router->get(  'retail/pos',                    'FKC_RetailController@pos', $rto );
	$router->get(  'retail/pos/search',             'FKC_RetailController@posSearch', $rto );
	$router->post( 'retail/pos/ouvrir',             'FKC_RetailController@ouvrirSession', $rto );
	$router->post( 'retail/pos/vendre',             'FKC_RetailController@vendre', $rto );
	$router->post( 'retail/pos/annuler',            'FKC_RetailController@annulerVente', $rto );
	$router->post( 'retail/pos/momo/initier',       'FKC_RetailController@momoInitier', $rto );
	$router->post( 'retail/pos/momo/verifier',      'FKC_RetailController@momoVerifier', $rto );
	$router->get(  'retail/pos/qz/certificat',      'FKC_RetailController@qzCertificat', $rto );
	$router->post( 'retail/pos/qz/signer',          'FKC_RetailController@qzSigner', $rto );
	$router->post( 'retail/reglages/qz/generer',    'FKC_RetailController@qzGenerer', $rto );
	// Sessions
	$router->get(  'retail/sessions',               'FKC_RetailController@sessions', $rto );
	$router->get(  'retail/sessions/{id}',          'FKC_RetailController@sessionShow', $rto );
	$router->post( 'retail/sessions/{id}/cloturer', 'FKC_RetailController@cloturer', $rto );
	// Articles & stocks
	$router->get(  'retail/articles',               'FKC_RetailController@articles', $rto );
	$router->get(  'retail/articles/nouveau',       'FKC_RetailController@articleForm', $rto );
	$router->post( 'retail/articles',               'FKC_RetailController@articleSave', $rto );
	$router->get(  'retail/articles/{id}/modifier', 'FKC_RetailController@articleForm', $rto );
	$router->post( 'retail/articles/{id}',          'FKC_RetailController@articleSave', $rto );
	$router->post( 'retail/articles/{id}/stock',     'FKC_RetailController@articleStockFix', $rto );
	$router->post( 'retail/articles/{id}/supprimer', 'FKC_RetailController@articleDelete', $rto );
	$router->post( 'retail/articles/{id}/restaurer', 'FKC_RetailController@articleRestore', $rto );
	$router->get(  'retail/reception',              'FKC_RetailController@reception', $rto );
	$router->post( 'retail/reception/recevoir',     'FKC_RetailController@receptionner', $rto );
	$router->get(  'retail/reception/resoudre',     'FKC_RetailController@receptionResoudre', $rto );
	$router->get(  'retail/articles/{id}/unites',   'FKC_RetailController@articleUnites', $rto );
	$router->post( 'retail/articles/{id}/unites/ajouter', 'FKC_RetailController@articleUniteAdd', $rto );
	$router->post( 'retail/articles/{id}/unites/retirer', 'FKC_RetailController@articleUniteDel', $rto );
	$router->get(  'retail/reappro',                'FKC_RetailController@reappro', $rto );
	$router->post( 'retail/reappro/commander',      'FKC_RetailController@reapproCommander', $rto ); // proposition IA → bon de commande
	// Retail Premium : politique commerciale, pertes, analyse avancée.
	$router->get(  'retail/promotions',             'FKC_RetailController@promotions', $rto );
	$router->post( 'retail/promotions',             'FKC_RetailController@promotionsSave', $rto );
	$router->get(  'retail/pertes',                 'FKC_RetailController@pertes', $rto );
	$router->get(  'retail/analyse-premium',        'FKC_RetailController@analysePremium', $rto );
	// Inventaire mobile : comptage par scan, écarts, validation.
	$router->get(  'retail/inventaires',            'FKC_RetailController@inventaires', $rto );
	$router->post( 'retail/inventaires',            'FKC_RetailController@inventaireOuvrir', $rto );
	$router->get(  'retail/inventaires/{id}',       'FKC_RetailController@inventaireSession', $rto );
	$router->post( 'retail/inventaires/{id}/compter',    'FKC_RetailController@inventaireCompter', $rto );
	$router->post( 'retail/inventaires/{id}/valider',    'FKC_RetailController@inventaireValider', $rto );
	$router->post( 'retail/inventaires/{id}/abandonner', 'FKC_RetailController@inventaireAbandonner', $rto );
	// Merchandising : planogramme, têtes de gondole, optimisation des linéaires.
	$router->get(  'retail/merchandising',          'FKC_RetailController@merchandising', $rto );
	$router->post( 'retail/merchandising',          'FKC_RetailController@merchandisingAction', $rto );
	$router->get(  'retail/rayons',                 'FKC_RetailController@rayons', $rto );
	$router->post( 'retail/rayons',                 'FKC_RetailController@rayonSave', $rto );
	$router->get(  'retail/fournisseurs',           'FKC_RetailController@fournisseurs', $rto );
	$router->post( 'retail/fournisseurs',           'FKC_RetailController@fournisseurSave', $rto );
	// Fidélité & promotions
	$router->get(  'retail/fidelite',               'FKC_RetailController@fidelite', $rto );
	$router->post( 'retail/fidelite',               'FKC_RetailController@fideliteSave', $rto );
	$router->get(  'retail/bons',                   'FKC_RetailController@bons', $rto );
	$router->post( 'retail/bons',                   'FKC_RetailController@bonSave', $rto );
	// Tickets (impression thermique / A4)
	$router->get(  'retail/ticket/{id}',            'FKC_RetailController@ticket', $rto );
	// Magasins (multi-magasins)
	$router->get(  'retail/magasins',               'FKC_RetailController@magasins', $rto );
	$router->get(  'retail/magasins/nouveau',       'FKC_RetailController@magasinForm', $rto );
	$router->post( 'retail/magasins',               'FKC_RetailController@magasinSave', $rto );
	$router->post( 'retail/magasins/changer',       'FKC_RetailController@changerMagasin', $rto );
	$router->get(  'retail/magasins/{id}/modifier', 'FKC_RetailController@magasinForm', $rto );
	$router->post( 'retail/magasins/{id}',          'FKC_RetailController@magasinSave', $rto );
	// Centrale d'achat
	$router->get(  'retail/commandes',              'FKC_RetailController@commandes', $rto );
	$router->get(  'retail/commandes/nouvelle',     'FKC_RetailController@commandeForm', $rto );
	$router->get(  'retail/commandes/groupee',      'FKC_RetailController@commandeGroupee', $rto );
	$router->post( 'retail/commandes',              'FKC_RetailController@commandeSave', $rto );
	$router->get(  'retail/commandes/{id}',         'FKC_RetailController@commandeShow', $rto );
	$router->post( 'retail/commandes/{id}/envoyer', 'FKC_RetailController@commandeEnvoyer', $rto );
	$router->post( 'retail/commandes/{id}/recevoir','FKC_RetailController@commandeRecevoir', $rto );
	// Transferts inter-magasins
	$router->get(  'retail/transferts',             'FKC_RetailController@transferts', $rto );
	$router->get(  'retail/transferts/nouveau',     'FKC_RetailController@transfertForm', $rto );
	$router->post( 'retail/transferts',             'FKC_RetailController@transfertSave', $rto );
	$router->post( 'retail/transferts/{id}/recevoir','FKC_RetailController@transfertRecevoir', $rto );
	$router->post( 'retail/transferts/{id}/annuler','FKC_RetailController@transfertAnnuler', $rto );
	// Impression directe ESC/POS (QZ Tray)
	$router->get(  'retail/ticket/{id}/escpos',     'FKC_RetailController@ticketEscpos', $rto );
	// Réglages (enseigne, ticket, imprimante, Mobile Money)
	$router->get(  'retail/reglages',               'FKC_RetailController@reglages', $rto );
	$router->post( 'retail/reglages/{section}',     'FKC_RetailController@reglagesSave', $rto );
}

/* ── Espace membre : route PUBLIQUE, authentifiée par le seul jeton ──────
   Pas de middleware `auth` (le membre n'a pas de compte) ni `societe` (le
   jeton porte le dossier et le branche via FKC_Tenant::bindById). Lecture
   seule : aucune route POST n'est ouverte ici. */
$router->get( 'espace-membre/{jeton}', 'FKC_SocialController@espace' );

/* ── Socle MEMBRES & ADHÉSIONS (famille Organisations & Économie Sociale) ──
   Module TRANSVERSAL : il n'appartient à aucun pack. Association, ONG,
   Coopérative et Église s'appuient dessus — d'où sa place dans Modules/ et
   non dans un Packs/. Les packs le déclarent simplement dans leur catalogue. */
if ( FKC_Packs::providesModule( 'social' ) ) {
	$soc = array( 'auth' => true, 'societe' => true, 'module' => 'social' );
	$router->get(  'social',                          'FKC_SocialController@index', $soc );
	$router->get(  'social/membres',                  'FKC_SocialController@membres', $soc );
	$router->get(  'social/membres/nouveau',          'FKC_SocialController@membreNouveau', $soc );
	$router->post( 'social/membres/nouveau',          'FKC_SocialController@membreEnregistrer', $soc );
	$router->get(  'social/membres/{id}',             'FKC_SocialController@membre', $soc );
	$router->get(  'social/membres/{id}/modifier',    'FKC_SocialController@membreEditer', $soc );
	$router->post( 'social/membres/{id}/modifier',    'FKC_SocialController@membreEnregistrer', $soc );
	$router->post( 'social/membres/{id}/adhesion',    'FKC_SocialController@adhesionAction', $soc );
	$router->get(  'social/categories',               'FKC_SocialController@categories', $soc );
	$router->post( 'social/categories',               'FKC_SocialController@categorieEnregistrer', $soc );
	$router->get(  'social/sections',                 'FKC_SocialController@sections', $soc );
	$router->post( 'social/sections',                 'FKC_SocialController@sectionEnregistrer', $soc );
	$router->get(  'social/cotisations',               'FKC_SocialController@cotisations', $soc );
	$router->post( 'social/cotisations/type',          'FKC_SocialController@typeEnregistrer', $soc );
	$router->get(  'social/cotisations/bareme/{code}', 'FKC_SocialController@bareme', $soc );
	$router->post( 'social/cotisations/bareme',        'FKC_SocialController@baremeEnregistrer', $soc );
	$router->post( 'social/appels',                    'FKC_SocialController@appelPreparer', $soc );
	$router->get(  'social/appels/{id}',               'FKC_SocialController@appel', $soc );
	$router->post( 'social/appels/{id}',               'FKC_SocialController@appelAction', $soc );
	$router->get(  'social/recus/{numero}',            'FKC_SocialController@recu', $soc );
	$router->get(  'social/impayes',                   'FKC_SocialController@impayes', $soc );
	$router->post( 'social/impayes/relancer',          'FKC_SocialController@relancer', $soc );
	$router->get(  'social/membres/{id}/carte',        'FKC_SocialController@carte', $soc );
	$router->post( 'social/membres/{id}/carte',        'FKC_SocialController@carteAction', $soc );
	$router->get(  'social/controle',                  'FKC_SocialController@controle', $soc );
	$router->post( 'social/controle',                  'FKC_SocialController@controle', $soc );
	$router->get(  'social/gouvernance',               'FKC_SocialController@gouvernance', $soc );
	$router->post( 'social/gouvernance/instance',      'FKC_SocialController@instanceEnregistrer', $soc );
	$router->post( 'social/gouvernance/mandat',        'FKC_SocialController@mandatAction', $soc );
	$router->get(  'social/reunions',                  'FKC_SocialController@reunions', $soc );
	$router->post( 'social/reunions',                  'FKC_SocialController@reunionCreer', $soc );
	$router->get(  'social/reunions/{id}',             'FKC_SocialController@reunion', $soc );
	$router->post( 'social/reunions/{id}',             'FKC_SocialController@reunionAction', $soc );
	$router->get(  'social/dons',                      'FKC_SocialController@dons', $soc );
	$router->post( 'social/dons',                      'FKC_SocialController@donAction', $soc );
}

/* ── Pack ONG : conventions, lignes budgétaires, dépenses, suivi ────────── */
if ( in_array( 'ong', FKC_Packs::activeCodes(), true ) ) {
	$ono = array( 'auth' => true, 'societe' => true, 'module' => 'ong' );
	$router->get(  'ong/conventions',        'FKC_OngController@conventions', $ono );
	$router->post( 'ong/conventions',        'FKC_OngController@conventionsAction', $ono );
	$router->get(  'ong/conventions/{id}',   'FKC_OngController@convention', $ono );
	$router->post( 'ong/conventions/{id}',   'FKC_OngController@conventionAction', $ono );
	$router->get(  'ong/conventions/{id}/rapport', 'FKC_OngController@rapport', $ono );
	$router->get(  'ong/bailleurs',          'FKC_OngController@bailleurs', $ono );
	$router->post( 'ong/bailleurs',          'FKC_OngController@bailleursAction', $ono );
	$router->get(  'ong/beneficiaires',      'FKC_OngController@beneficiaires', $ono );
	$router->post( 'ong/beneficiaires',      'FKC_OngController@beneficiairesAction', $ono );
}

/* ── Pack Agriculture : exploitation, itinéraire cultural, marge/ha ─────── */
if ( in_array( 'agriculture', FKC_Packs::activeCodes(), true ) ) {
	$ago = array( 'auth' => true, 'societe' => true, 'module' => 'agriculture' );
	$router->get(  'agriculture/exploitation',   'FKC_AgricultureController@exploitation', $ago );
	$router->post( 'agriculture/exploitation',   'FKC_AgricultureController@exploitationAction', $ago );
	$router->get(  'agriculture/campagnes/{id}', 'FKC_AgricultureController@campagne', $ago );
	$router->post( 'agriculture/campagnes/{id}', 'FKC_AgricultureController@campagneAction', $ago );
}

/* ── Pack Garage : atelier, ordres de réparation, pièces, marge ─────────── */
if ( in_array( 'garage', FKC_Packs::activeCodes(), true ) ) {
	$gao = array( 'auth' => true, 'societe' => true, 'module' => 'garage' );
	$router->get(  'garage/atelier',        'FKC_GarageController@atelier', $gao );
	$router->post( 'garage/atelier',        'FKC_GarageController@atelierAction', $gao );
	$router->get(  'garage/ordres/{id}',    'FKC_GarageController@ordre', $gao );
	$router->post( 'garage/ordres/{id}',    'FKC_GarageController@ordreAction', $gao );
	// 1.867.0 — fiches véhicules et rappels d'entretien.
	$router->get(  'garage/vehicules',      'FKC_GarageController@vehicules', $gao );
	$router->get(  'garage/vehicules/{id}', 'FKC_GarageController@vehicule', $gao );
	$router->post( 'garage/vehicules/{id}', 'FKC_GarageController@vehiculeAction', $gao );
}

/* ── Pack Imprimerie : devis au tirage, BAT, fabrication, papier (1.867.0) ── */
if ( in_array( 'imprimerie', FKC_Packs::activeCodes(), true ) ) {
	$ipo = array( 'auth' => true, 'societe' => true, 'module' => 'imprimerie' );
	$router->get(  'imprimerie/travaux',      'FKC_ImprimerieController@travaux', $ipo );
	$router->post( 'imprimerie/travaux',      'FKC_ImprimerieController@travauxAction', $ipo );
	$router->get(  'imprimerie/travaux/{id}', 'FKC_ImprimerieController@travail', $ipo );
	$router->post( 'imprimerie/travaux/{id}', 'FKC_ImprimerieController@travailAction', $ipo );
}

/* ── Pack Immobilier : gérance, baux, quittances, impayés ───────────────── */
if ( in_array( 'immobilier', FKC_Packs::activeCodes(), true ) ) {
	$imo = array( 'auth' => true, 'societe' => true, 'module' => 'immobilier' );
	$router->get(  'immobilier/gerance',       'FKC_ImmobilierController@gerance', $imo );
	$router->post( 'immobilier/gerance',       'FKC_ImmobilierController@geranceAction', $imo );
	$router->get(  'immobilier/biens/{id}',    'FKC_ImmobilierController@bien', $imo );
	$router->post( 'immobilier/biens/{id}',    'FKC_ImmobilierController@bienAction', $imo );
	$router->get(  'immobilier/reddition',     'FKC_ImmobilierController@reddition', $imo ); // 1.867.0 — état imprimable
}

/* ── Pack Transport : flotte, entretien, carburant, rentabilité ─────────── */
if ( in_array( 'transport', FKC_Packs::activeCodes(), true ) ) {
	$tro = array( 'auth' => true, 'societe' => true, 'module' => 'transport' );
	$router->get(  'transport/flotte',           'FKC_TransportController@flotte', $tro );
	$router->post( 'transport/flotte',           'FKC_TransportController@flotteAction', $tro );
	$router->get(  'transport/vehicules/{id}',   'FKC_TransportController@vehicule', $tro );
	$router->post( 'transport/vehicules/{id}',   'FKC_TransportController@vehiculeAction', $tro );
	$router->get(  'transport/missions',         'FKC_TransportController@missions', $tro ); // 1.871.0
	$router->post( 'transport/missions',         'FKC_TransportController@missionsAction', $tro );
}

/* ── Pack Transit : suivi douanier (1.871.0) ──────────────────────────── */
if ( in_array( 'transit', FKC_Packs::activeCodes(), true ) ) {
	$tni = array( 'auth' => true, 'societe' => true, 'module' => 'transit' );
	$router->get(  'transit/suivi', 'FKC_TransitController@suivi', $tni );
	$router->post( 'transit/suivi', 'FKC_TransitController@suiviAction', $tni );
}

/* ── Pack Logistique : entrepôt des clients (1.871.0) ──────────────────── */
if ( in_array( 'logistique', FKC_Packs::activeCodes(), true ) ) {
	$lgi = array( 'auth' => true, 'societe' => true, 'module' => 'logistique' );
	$router->get(  'logistique/entrepot',      'FKC_LogistiqueController@entrepot', $lgi );
	$router->get(  'logistique/entrepot/{id}', 'FKC_LogistiqueController@client', $lgi );
	$router->post( 'logistique/entrepot/{id}', 'FKC_LogistiqueController@clientAction', $lgi );
}

/* ── Pack Import-Export : négoce, devises, crédoc, stock (1.871.0) ─────── */
if ( in_array( 'importexport', FKC_Packs::activeCodes(), true ) ) {
	$iei = array( 'auth' => true, 'societe' => true, 'module' => 'importexport' );
	$router->get(  'importexport/negoce', 'FKC_ImportexportController@negoce', $iei );
	$router->post( 'importexport/negoce', 'FKC_ImportexportController@negoceAction', $iei );
}

/* ── Pack Coopérative : collecte, apports, ventes, ristourne ────────────── */
if ( in_array( 'cooperative', FKC_Packs::activeCodes(), true ) ) {
	$coo = array( 'auth' => true, 'societe' => true, 'module' => 'cooperative' );
	$router->get(  'cooperative/collecte',        'FKC_CooperativeController@collecte', $coo );
	$router->post( 'cooperative/collecte',        'FKC_CooperativeController@collecteAction', $coo );
	$router->get(  'cooperative/campagnes/{id}',  'FKC_CooperativeController@campagne', $coo );
	$router->post( 'cooperative/campagnes/{id}',  'FKC_CooperativeController@campagneAction', $coo );
	$router->get(  'cooperative/producteurs',     'FKC_CooperativeController@producteurs', $coo );
	$router->post( 'cooperative/producteurs',     'FKC_CooperativeController@producteursAction', $coo );
	$router->get(  'cooperative/pesee',           'FKC_CooperativeController@pesee', $coo );
	$router->post( 'cooperative/pesee',           'FKC_CooperativeController@peseeAction', $coo );
}

/* ── Pack Microfinance : portefeuille, épargne, prêts, PAR ──────────────── */
if ( in_array( 'microfinance', FKC_Packs::activeCodes(), true ) ) {
	$mfo = array( 'auth' => true, 'societe' => true, 'module' => 'microfinance' );
	$router->get(  'microfinance/portefeuille',   'FKC_MicrofinanceController@portefeuille', $mfo );
	$router->post( 'microfinance/portefeuille',   'FKC_MicrofinanceController@portefeuilleAction', $mfo );
	$router->get(  'microfinance/provisionnement', 'FKC_MicrofinanceController@provisionnement', $mfo );
	$router->post( 'microfinance/provisionnement', 'FKC_MicrofinanceController@provisionnementAction', $mfo );
	$router->get(  'microfinance/membres/{id}',   'FKC_MicrofinanceController@membre', $mfo );
	$router->post( 'microfinance/membres/{id}',   'FKC_MicrofinanceController@membreAction', $mfo );
	$router->get(  'microfinance/prets/{id}',     'FKC_MicrofinanceController@pret', $mfo );
	$router->post( 'microfinance/prets/{id}',     'FKC_MicrofinanceController@pretAction', $mfo );
}

/* ── Pack Cabinet juridique : affaires, délais, débours, séquestre ──────── */
if ( in_array( 'cabinet_juridique', FKC_Packs::activeCodes(), true ) ) {
	$jro = array( 'auth' => true, 'societe' => true, 'module' => 'cabinet_juridique' );
	$router->get(  'cabinet_juridique/affaires',        'FKC_JuridiqueController@affaires', $jro );
	$router->post( 'cabinet_juridique/affaires',        'FKC_JuridiqueController@affairesAction', $jro );
	$router->get(  'cabinet_juridique/affaires/{id}',   'FKC_JuridiqueController@affaire', $jro );
	$router->post( 'cabinet_juridique/affaires/{id}',   'FKC_JuridiqueController@affaireAction', $jro );
}

/* ── Pack Cabinet comptable : missions, échéances, rentabilité ──────────── */
/*
 * RETOUR AU CABINET (1.803.0) — hors du bloc du pack, et c'est voulu : on
 * l'emprunte DEPUIS la société du client, dont le pack n'est pas « cabinet ».
 * Enregistrée sous la condition du pack, la route n'existerait justement pas
 * à l'endroit où l'on en a besoin. Ni « societe », ni « module » : seule
 * l'authentification est exigée, le contrôle d'accès à la société du cabinet
 * étant refait par FKC_Tenant::set().
 */
$router->post( 'cabinet-retour', 'FKC_CabinetRetourController@retour', array( 'auth' => true ) );

if ( in_array( 'cabinet', FKC_Packs::activeCodes(), true ) ) {
	$cbo = array( 'auth' => true, 'societe' => true, 'module' => 'cabinet' );
	$router->get(  'cabinet/missions',        'FKC_CabinetController@missions', $cbo );
	$router->post( 'cabinet/missions',        'FKC_CabinetController@missionsAction', $cbo );
	$router->get(  'cabinet/missions/{id}',   'FKC_CabinetController@mission', $cbo );
	$router->post( 'cabinet/missions/{id}',   'FKC_CabinetController@missionAction', $cbo );
	/* Dossier comptable client (1.803.0) : portefeuille, fiche, ouverture de la comptabilité. */
	$router->get(  'cabinet/clients',             'FKC_CabinetController@clients', $cbo );
	$router->post( 'cabinet/clients',             'FKC_CabinetController@clientsAction', $cbo );
	$router->get(  'cabinet/clients/{id}',        'FKC_CabinetController@client', $cbo );
	$router->post( 'cabinet/clients/{id}',        'FKC_CabinetController@clientAction', $cbo );
	$router->post( 'cabinet/clients/{id}/ouvrir', 'FKC_CabinetController@ouvrir', $cbo );
	/* Production du dossier (1.804.0) : pièces, circuit, suspens, checklists. */
	$router->get(  'cabinet/clients/{id}/production',              'FKC_CabinetController@production', $cbo );
	$router->post( 'cabinet/clients/{id}/production',              'FKC_CabinetController@productionAction', $cbo );
	$router->get(  'cabinet/clients/{id}/pieces/{pid}/fichier',    'FKC_CabinetController@pieceFichier', $cbo );
	$router->get(  'cabinet/checklists/{id}',                      'FKC_CabinetController@checklist', $cbo );
	$router->post( 'cabinet/checklists/{id}',                      'FKC_CabinetController@checklistAction', $cbo );
	$router->get(  'cabinet/production/reglages',                  'FKC_CabinetController@reglages', $cbo );
	$router->post( 'cabinet/production/reglages',                  'FKC_CabinetController@reglagesAction', $cbo );
	/* Échéances, fiscalité du dossier, radar (1.805.0). */
	$router->get(  'cabinet/clients/{id}/fiscalite', 'FKC_CabinetController@fiscalite', $cbo );
	$router->post( 'cabinet/clients/{id}/fiscalite', 'FKC_CabinetController@fiscaliteAction', $cbo );
	$router->get(  'cabinet/echeances',              'FKC_CabinetController@radar', $cbo );
	/* Honoraires, recouvrement, rentabilité, cockpit, export (1.809.0). */
	$router->get(  'cabinet/clients/{id}/honoraires', 'FKC_CabinetController@honoraires', $cbo );
	$router->post( 'cabinet/clients/{id}/honoraires', 'FKC_CabinetController@honorairesAction', $cbo );
	$router->post( 'cabinet/clients/{id}/export',     'FKC_CabinetController@exporter', $cbo );
	$router->get(  'cabinet/recouvrement',            'FKC_CabinetController@recouvrement', $cbo );
	$router->get(  'cabinet/rentabilite',             'FKC_CabinetController@rentabilite', $cbo );
	$router->get(  'cabinet/cockpit',                 'FKC_CabinetController@cockpit', $cbo );
}

/* ── Packs École & Université : scolarité (frais, recouvrement) & vie scolaire (bulletins) ──
 * 1.862.0 — l'Université sert les mêmes écrans sous « universite/… » (vocabulaire et comptes du pack). */
foreach ( array( 'ecole', 'universite' ) as $edu ) {
	if ( ! in_array( $edu, FKC_Packs::activeCodes(), true ) || ! class_exists( 'FKC_EcoleController' ) ) { continue; }
	$eco = array( 'auth' => true, 'societe' => true, 'module' => $edu );
	$router->get(  $edu . '/scolarite',      'FKC_EcoleController@scolarite', $eco );
	$router->post( $edu . '/scolarite',      'FKC_EcoleController@scolariteAction', $eco );
	$router->get(  $edu . '/eleves',         'FKC_EcoleController@eleves', $eco );
	$router->post( $edu . '/eleves',         'FKC_EcoleController@elevesAction', $eco );
	$router->get(  $edu . '/eleves/{id}',    'FKC_EcoleController@eleve', $eco );
	$router->post( $edu . '/eleves/{id}',    'FKC_EcoleController@eleveAction', $eco );
	$router->get(  $edu . '/classes/{id}',   'FKC_EcoleController@classe', $eco );
	$router->post( $edu . '/classes/{id}',   'FKC_EcoleController@classeAction', $eco );
}

/* ── 1.865.0 — Station-service : cuves & pompes ; Téléphonie : float mobile money ── */
if ( in_array( 'stationservice', FKC_Packs::activeCodes(), true ) && class_exists( 'FKC_StationController' ) ) {
	$sto = array( 'auth' => true, 'societe' => true, 'module' => 'stationservice' );
	$router->get(  'stationservice/cuves', 'FKC_StationController@cuves', $sto );
	$router->post( 'stationservice/cuves', 'FKC_StationController@cuvesAction', $sto );
}
if ( in_array( 'telephonie', FKC_Packs::activeCodes(), true ) && class_exists( 'FKC_TelephonieController' ) ) {
	$tlo = array( 'auth' => true, 'societe' => true, 'module' => 'telephonie' );
	$router->get(  'telephonie/float', 'FKC_TelephonieController@float', $tlo );
	$router->post( 'telephonie/float', 'FKC_TelephonieController@floatAction', $tlo );
}

/* ── Pack Restaurant : cuisine (food cost) & salle (service) ─────────────── */
if ( in_array( 'restaurant', FKC_Packs::activeCodes(), true ) ) {
	$rso = array( 'auth' => true, 'societe' => true, 'module' => 'restaurant' );
	$router->get(  'restaurant/cuisine',  'FKC_RestaurantController@cuisine', $rso );
	$router->post( 'restaurant/cuisine',  'FKC_RestaurantController@cuisineAction', $rso );
	// Réservation de table (1.520.108) — s'appuie sur FKC_HospitalityBooking.
	$router->get(  'restaurant/reservations', 'FKC_RestaurantController@reservations', $rso );
	$router->post( 'restaurant/reservations', 'FKC_RestaurantController@reservationsAction', $rso );
	$router->get(  'restaurant/salle',    'FKC_RestaurantController@salle', $rso );
	$router->post( 'restaurant/salle',    'FKC_RestaurantController@salleAction', $rso );
	$router->get(  'restaurant/borne/compte', 'FKC_RestaurantController@borneCompte', $rso ); // 1.808.0
	$router->get(  'restaurant/cuisine/plat/{id}/image', 'FKC_RestaurantController@platImage', $rso ); // 1.812.0 : aperçu de la photo
	$router->get(  'restaurant/kds',      'FKC_RestaurantController@kds', $rso );
	$router->post( 'restaurant/kds',      'FKC_RestaurantController@kdsAction', $rso );
	$router->get(  'restaurant/bon/{id}', 'FKC_RestaurantController@bon', $rso );
}

/* ── Pack Bar : carte, salle & service (fusionné Caisse), inventaire ─────── */
if ( in_array( 'bar', FKC_Packs::activeCodes(), true ) ) {
	$bro = array( 'auth' => true, 'societe' => true, 'module' => 'bar' );
	$router->get(  'bar/catalogue',   'FKC_BarController@catalogue', $bro );
	$router->post( 'bar/catalogue',   'FKC_BarController@catalogueAction', $bro );
	$router->get(  'bar/salle',       'FKC_BarController@salle', $bro );
	$router->post( 'bar/salle',       'FKC_BarController@salleAction', $bro );
	$router->get(  'bar/inventaire',  'FKC_BarController@inventaire', $bro );
	$router->post( 'bar/inventaire',  'FKC_BarController@inventaireAction', $bro );
	$router->get(  'bar/happy-hour',  'FKC_BarController@happyHour', $bro );
	$router->post( 'bar/happy-hour',  'FKC_BarController@happyHourAction', $bro );
}

/* ── Pack Fast-food : commande rapide + livraison (moteur partagé) ──────── */
if ( in_array( 'fastfood', FKC_Packs::activeCodes(), true ) ) {
	$ffo = array( 'auth' => true, 'societe' => true, 'module' => 'fastfood' );
	$router->get(  'fastfood/catalogue', 'FKC_FastFoodController@catalogue', $ffo );
	$router->post( 'fastfood/catalogue', 'FKC_FastFoodController@catalogueAction', $ffo );
	$router->get(  'fastfood/commande',  'FKC_FastFoodController@commande', $ffo );
	$router->post( 'fastfood/commande',  'FKC_FastFoodController@commandeAction', $ffo );
	$router->get(  'fastfood/kds',       'FKC_FastFoodController@kds', $ffo );
	$router->post( 'fastfood/kds',       'FKC_FastFoodController@kdsAction', $ffo );
	$router->get(  'fastfood/livraison', 'FKC_FastFoodController@livraison', $ffo );
	$router->post( 'fastfood/livraison', 'FKC_FastFoodController@livraisonAction', $ffo );
}

/* ── Pack Loisirs : espaces réservables, vente comptoir & réservations ──── */
if ( in_array( 'loisirs', FKC_Packs::activeCodes(), true ) ) {
	$lso = array( 'auth' => true, 'societe' => true, 'module' => 'loisirs' );
	$router->get(  'loisirs/espaces',      'FKC_LoisirsController@espaces', $lso );
	$router->post( 'loisirs/espaces',      'FKC_LoisirsController@espacesAction', $lso );
	$router->get(  'loisirs/vente',        'FKC_LoisirsController@vente', $lso );
	$router->post( 'loisirs/vente',        'FKC_LoisirsController@venteAction', $lso );
	$router->get(  'loisirs/reservations', 'FKC_LoisirsController@reservations', $lso );
	$router->post( 'loisirs/reservations', 'FKC_LoisirsController@reservationsAction', $lso );
	$router->get(  'loisirs/abonnements',  'FKC_LoisirsController@abonnements', $lso );
	$router->post( 'loisirs/abonnements',  'FKC_LoisirsController@abonnementsAction', $lso );
	$router->get(  'loisirs/fiscalite',    'FKC_LoisirsController@fiscalite', $lso );
	$router->post( 'loisirs/fiscalite',    'FKC_LoisirsController@fiscaliteAction', $lso );
}

/* ── Pack Événementiel : événement, budget six étages, billetterie & sponsoring ── */
if ( in_array( 'evenementiel', FKC_Packs::activeCodes(), true ) ) {
	$evo = array( 'auth' => true, 'societe' => true, 'module' => 'evenementiel' );
	$router->get(  'evenementiel/evenements',      'FKC_EvenementielController@evenements', $evo );
	$router->post( 'evenementiel/evenements',      'FKC_EvenementielController@evenementsAction', $evo );
	$router->get(  'evenementiel/evenement/{id}',  'FKC_EvenementielController@evenement', $evo );
	$router->post( 'evenementiel/evenement/{id}',  'FKC_EvenementielController@evenementAction', $evo );
	$router->get(  'evenementiel/billetterie',     'FKC_EvenementielController@billetterie', $evo );
	$router->post( 'evenementiel/billetterie',     'FKC_EvenementielController@billetterieAction', $evo );
	$router->get(  'evenementiel/fiscalite',       'FKC_EvenementielController@fiscalite', $evo );
	$router->post( 'evenementiel/fiscalite',       'FKC_EvenementielController@fiscaliteAction', $evo );
}

/* ── Pack Audiovisuel & Cinéma : production centrale, budget six étages, contrats & livrables (P1, 1.520.50) ── */
if ( in_array( 'audiovisuel', FKC_Packs::activeCodes(), true ) ) {
	$pro = array( 'auth' => true, 'societe' => true, 'module' => 'audiovisuel' );
	$router->get(  'audiovisuel',                  'FKC_AudiovisuelController@productions', $pro );
	$router->get(  'audiovisuel/productions',      'FKC_AudiovisuelController@productions', $pro );
	$router->post( 'audiovisuel/productions',      'FKC_AudiovisuelController@productionsAction', $pro );
	$router->get(  'audiovisuel/production/{id}',  'FKC_AudiovisuelController@production', $pro );
	$router->post( 'audiovisuel/production/{id}',  'FKC_AudiovisuelController@productionAction', $pro );
}

/* ── Pack Médias : campagne centrale, brief CRM, étapes de projet, droits, plan de diffusion (P1, 1.520.51) ── */
if ( in_array( 'medias', FKC_Packs::activeCodes(), true ) ) {
	$med = array( 'auth' => true, 'societe' => true, 'module' => 'medias' );
	$router->get(  'medias',                  'FKC_MediasController@campagnes', $med );
	$router->get(  'medias/campagnes',        'FKC_MediasController@campagnes', $med );
	$router->post( 'medias/campagnes',        'FKC_MediasController@campagnesAction', $med );
	$router->get(  'medias/campagne/{id}',    'FKC_MediasController@campagne', $med );
	$router->post( 'medias/campagne/{id}',    'FKC_MediasController@campagneAction', $med );
	$router->get(  'medias/briefs',           'FKC_MediasController@briefs', $med );
	$router->post( 'medias/briefs',           'FKC_MediasController@briefsAction', $med );
	$router->get(  'medias/grille',           'FKC_MediasController@grille', $med );
	$router->post( 'medias/grille',           'FKC_MediasController@grilleAction', $med );
}

/* ── Pack Communication (agence_com) : mission client centrale, entité FKC_Mission partagée avec le groupe Agences (1.520.60) ── */
if ( in_array( 'agence_com', FKC_Packs::activeCodes(), true ) ) {
	$aco = array( 'auth' => true, 'societe' => true, 'module' => 'agence_com' );
	$router->get(  'agence_com',                  'FKC_AgenceComController@missions', $aco );
	$router->get(  'agence_com/missions',         'FKC_AgenceComController@missions', $aco );
	$router->post( 'agence_com/missions',         'FKC_AgenceComController@missionsAction', $aco );
	$router->get(  'agence_com/mission/{id}',     'FKC_AgenceComController@mission', $aco );
	$router->post( 'agence_com/mission/{id}',     'FKC_AgenceComController@missionAction', $aco );
	$router->get(  'agence_com/briefs',           'FKC_AgenceComController@briefs', $aco );
	$router->post( 'agence_com/briefs',           'FKC_AgenceComController@briefsAction', $aco );
	$router->get(  'agence_com/audit',            'FKC_AgenceComController@audit', $aco );
}

/* ── Pack Digital (agence_digitale) : mission client centrale, entité FKC_Mission partagée avec le groupe Agences (1.520.61) ── */
if ( in_array( 'agence_digitale', FKC_Packs::activeCodes(), true ) ) {
	$adi = array( 'auth' => true, 'societe' => true, 'module' => 'agence_digitale' );
	$router->get(  'agence_digitale',                  'FKC_AgenceDigitaleController@missions', $adi );
	$router->get(  'agence_digitale/missions',         'FKC_AgenceDigitaleController@missions', $adi );
	$router->post( 'agence_digitale/missions',         'FKC_AgenceDigitaleController@missionsAction', $adi );
	$router->get(  'agence_digitale/mission/{id}',     'FKC_AgenceDigitaleController@mission', $adi );
	$router->post( 'agence_digitale/mission/{id}',     'FKC_AgenceDigitaleController@missionAction', $adi );
	$router->get(  'agence_digitale/briefs',           'FKC_AgenceDigitaleController@briefs', $adi );
	$router->post( 'agence_digitale/briefs',           'FKC_AgenceDigitaleController@briefsAction', $adi );
}

/* ── Pack Studio graphique : mission client centrale, entité FKC_Mission partagée avec le groupe Agences (1.520.61) ── */
if ( in_array( 'studio_graphique', FKC_Packs::activeCodes(), true ) ) {
	$sgr = array( 'auth' => true, 'societe' => true, 'module' => 'studio_graphique' );
	$router->get(  'studio_graphique',                  'FKC_StudioGraphiqueController@missions', $sgr );
	$router->get(  'studio_graphique/missions',         'FKC_StudioGraphiqueController@missions', $sgr );
	$router->post( 'studio_graphique/missions',         'FKC_StudioGraphiqueController@missionsAction', $sgr );
	$router->get(  'studio_graphique/mission/{id}',     'FKC_StudioGraphiqueController@mission', $sgr );
	$router->post( 'studio_graphique/mission/{id}',     'FKC_StudioGraphiqueController@missionAction', $sgr );
	$router->get(  'studio_graphique/briefs',           'FKC_StudioGraphiqueController@briefs', $sgr );
	$router->post( 'studio_graphique/briefs',           'FKC_StudioGraphiqueController@briefsAction', $sgr );
}

/* ── Pack Créateur (createur_contenu) : mission client centrale, entité FKC_Mission partagée (1.520.62) ── */
if ( in_array( 'createur_contenu', FKC_Packs::activeCodes(), true ) ) {
	$crc = array( 'auth' => true, 'societe' => true, 'module' => 'createur_contenu' );
	$router->get(  'createur_contenu',                  'FKC_CreateurContenuController@missions', $crc );
	$router->get(  'createur_contenu/missions',         'FKC_CreateurContenuController@missions', $crc );
	$router->post( 'createur_contenu/missions',         'FKC_CreateurContenuController@missionsAction', $crc );
	$router->get(  'createur_contenu/mission/{id}',     'FKC_CreateurContenuController@mission', $crc );
	$router->post( 'createur_contenu/mission/{id}',     'FKC_CreateurContenuController@missionAction', $crc );
	$router->get(  'createur_contenu/briefs',           'FKC_CreateurContenuController@briefs', $crc );
	$router->post( 'createur_contenu/briefs',           'FKC_CreateurContenuController@briefsAction', $crc );
}

/* ── Pack Influence : mission client centrale, entité FKC_Mission partagée (1.520.62) ── */
if ( in_array( 'influenceur', FKC_Packs::activeCodes(), true ) ) {
	$inf = array( 'auth' => true, 'societe' => true, 'module' => 'influenceur' );
	$router->get(  'influenceur',                  'FKC_InfluenceurController@missions', $inf );
	$router->get(  'influenceur/missions',         'FKC_InfluenceurController@missions', $inf );
	$router->post( 'influenceur/missions',         'FKC_InfluenceurController@missionsAction', $inf );
	$router->get(  'influenceur/mission/{id}',     'FKC_InfluenceurController@mission', $inf );
	$router->post( 'influenceur/mission/{id}',     'FKC_InfluenceurController@missionAction', $inf );
	$router->get(  'influenceur/briefs',           'FKC_InfluenceurController@briefs', $inf );
	$router->post( 'influenceur/briefs',           'FKC_InfluenceurController@briefsAction', $inf );
}

/* ── Pack Studio (studio) : mission client centrale, entité FKC_Mission partagée (1.520.63) ── */
if ( in_array( 'studio', FKC_Packs::activeCodes(), true ) ) {
	$stu = array( 'auth' => true, 'societe' => true, 'module' => 'studio' );
	/*
	 * TERMINAL STUDIO (1.634.0) — l'accueil devient le point d'entrée du
	 * pack, à la place de la liste des missions. Le geste quotidien d'un
	 * studio est de vendre quatre heures de cabine, pas d'ouvrir un
	 * dossier de projet ; les missions restent à un clic.
	 */
	$router->get(  'studio',                  'FKC_StudioController@accueil', $stu );
	$router->post( 'studio',                  'FKC_StudioController@accueilAction', $stu );
	$router->get(  'studio/commandes',        'FKC_StudioController@commandes', $stu );
	$router->get(  'studio/commande/{id}',    'FKC_StudioController@commande', $stu );
	$router->post( 'studio/commande/{id}',    'FKC_StudioController@commandeAction', $stu );
	$router->get(  'studio/client/{id}',      'FKC_StudioController@client', $stu );
	$router->get(  'studio/planning',         'FKC_StudioController@planning', $stu );
	$router->get(  'studio/soldes',           'FKC_StudioController@soldes', $stu );
	$router->post( 'studio/soldes',           'FKC_StudioController@soldesAction', $stu );
	$router->get(  'studio/articles',         'FKC_StudioController@articles', $stu );
	$router->post( 'studio/articles',         'FKC_StudioController@articlesAction', $stu );
	$router->get(  'studio/catalogue',        'FKC_StudioController@catalogue', $stu );
	$router->post( 'studio/catalogue',        'FKC_StudioController@catalogueAction', $stu );
	$router->get(  'studio/catalogue/{id}/image', 'FKC_StudioController@catalogueImage', $stu ); // 1.807.0 : aperçu de la photo
	$router->get(  'studio/missions',         'FKC_StudioController@missions', $stu );
	$router->post( 'studio/missions',         'FKC_StudioController@missionsAction', $stu );
	$router->get(  'studio/mission/{id}',     'FKC_StudioController@mission', $stu );
	$router->post( 'studio/mission/{id}',     'FKC_StudioController@missionAction', $stu );
	$router->get(  'studio/briefs',           'FKC_StudioController@briefs', $stu );
	$router->post( 'studio/briefs',           'FKC_StudioController@briefsAction', $stu );
	$router->get(  'studio/sessions',         'FKC_StudioController@sessions', $stu );
	$router->post( 'studio/sessions',         'FKC_StudioController@sessionsAction', $stu );
}

/* ── Pack Culture (structure_culturelle) : programmation centrale, entité FKC_Mission partagée (1.520.65) ── */
if ( in_array( 'structure_culturelle', FKC_Packs::activeCodes(), true ) ) {
	$cul = array( 'auth' => true, 'societe' => true, 'module' => 'structure_culturelle' );
	$router->get(  'structure_culturelle',                  'FKC_StructureCulturelleController@missions', $cul );
	$router->get(  'structure_culturelle/missions',         'FKC_StructureCulturelleController@missions', $cul );
	$router->post( 'structure_culturelle/missions',         'FKC_StructureCulturelleController@missionsAction', $cul );
	$router->get(  'structure_culturelle/mission/{id}',     'FKC_StructureCulturelleController@mission', $cul );
	$router->post( 'structure_culturelle/mission/{id}',     'FKC_StructureCulturelleController@missionAction', $cul );
	$router->get(  'structure_culturelle/briefs',           'FKC_StructureCulturelleController@briefs', $cul );
	$router->post( 'structure_culturelle/briefs',           'FKC_StructureCulturelleController@briefsAction', $cul );
}

/* ── Pack Édition (maison_edition) : ouvrage central, ventes avec droits d'auteur automatiques (chantier « Édition », 1.520.67) ── */
if ( in_array( 'maison_edition', FKC_Packs::activeCodes(), true ) ) {
	$edi = array( 'auth' => true, 'societe' => true, 'module' => 'maison_edition' );
	$router->get(  'maison_edition',                  'FKC_EditionController@ouvrages', $edi );
	$router->get(  'maison_edition/ouvrages',         'FKC_EditionController@ouvrages', $edi );
	$router->post( 'maison_edition/ouvrages',         'FKC_EditionController@ouvragesAction', $edi );
	$router->get(  'maison_edition/ouvrage/{id}/releve', 'FKC_EditionController@releve', $edi ); // 1.869.0
	$router->get(  'maison_edition/ouvrage/{id}',     'FKC_EditionController@ouvrage', $edi );
	$router->post( 'maison_edition/ouvrage/{id}',     'FKC_EditionController@ouvrageAction', $edi );
}

/* ── Pack Boulangerie : pilote FKC_Fabrication (fournil), boutique & fiscalité ── */
if ( in_array( 'boulangerie', FKC_Packs::activeCodes(), true ) ) {
	$blo = array( 'auth' => true, 'societe' => true, 'module' => 'boulangerie' );
	$router->get(  'boulangerie/fournil',  'FKC_BoulangerieController@fournil', $blo );
	$router->post( 'boulangerie/fournil',  'FKC_BoulangerieController@fournilAction', $blo );
	$router->get(  'boulangerie/tarifs',   'FKC_BoulangerieController@tarifs', $blo );
	$router->post( 'boulangerie/tarifs',   'FKC_BoulangerieController@tarifsAction', $blo );
	$router->get(  'boulangerie/boutique', 'FKC_BoulangerieController@boutique', $blo );
	$router->post( 'boulangerie/boutique', 'FKC_BoulangerieController@boutiqueAction', $blo );
	$router->get(  'boulangerie/invendus', 'FKC_BoulangerieController@invendus', $blo );
	$router->post( 'boulangerie/invendus', 'FKC_BoulangerieController@invendusAction', $blo );
}

/* ── Pack Maquis : pilote directement le moteur Restaurant (Cuisine/Salle) ── */
if ( in_array( 'maquis', FKC_Packs::activeCodes(), true ) ) {
	$mqo = array( 'auth' => true, 'societe' => true, 'module' => 'maquis' );
	$router->get(  'maquis/cuisine',  'FKC_MaquisController@cuisine', $mqo );
	$router->post( 'maquis/cuisine',  'FKC_MaquisController@cuisineAction', $mqo );
	$router->get(  'maquis/salle',    'FKC_MaquisController@salle', $mqo );
	$router->post( 'maquis/salle',    'FKC_MaquisController@salleAction', $mqo );
	$router->get(  'maquis/kds',      'FKC_MaquisController@kds', $mqo );
	$router->post( 'maquis/kds',      'FKC_MaquisController@kdsAction', $mqo );
	$router->get(  'maquis/bon/{id}', 'FKC_MaquisController@bon', $mqo );
}

/* ── Pack Hôtel : réservations & planning d'occupation ──────────────────── */
if ( in_array( 'hotel', FKC_Packs::activeCodes(), true ) ) {
	$hto = array( 'auth' => true, 'societe' => true, 'module' => 'hotel' );
	$router->get(  'hotel/reservations',  'FKC_HotelController@reservations', $hto );
	$router->post( 'hotel/reservations',  'FKC_HotelController@reservationsAction', $hto );
	$router->get(  'hotel/planning',      'FKC_HotelController@planning', $hto );
	$router->get(  'hotel/sejour/{id}',   'FKC_HotelController@sejour', $hto );
	$router->get(  'hotel/housekeeping',  'FKC_HotelController@housekeeping', $hto );
	$router->post( 'hotel/housekeeping',  'FKC_HotelController@housekeepingAction', $hto );
	$router->get(  'hotel/tarifs',        'FKC_HotelController@tarifs', $hto );
	$router->post( 'hotel/tarifs',        'FKC_HotelController@tarifsAction', $hto );
	$router->get(  'hotel/groupes',       'FKC_HotelController@groupes', $hto );
	$router->post( 'hotel/groupes',       'FKC_HotelController@groupesAction', $hto );
	$router->get(  'hotel/services',      'FKC_HotelController@services', $hto );
	$router->post( 'hotel/services',      'FKC_HotelController@servicesAction', $hto );
}

/* ── Pack Pharmacie : officine experte (médicaments, lots, comptoir, registre) ── */
if ( in_array( 'pharmacie', FKC_Packs::activeCodes(), true ) ) {
	$pho = array( 'auth' => true, 'societe' => true, 'module' => 'pharmacie' );
	$router->get(  'pharmacie/officine',      'FKC_PharmacieController@officine', $pho );
	$router->post( 'pharmacie/officine',      'FKC_PharmacieController@officineAction', $pho );
	$router->get(  'pharmacie/comptoir',      'FKC_PharmacieController@comptoir', $pho );
	$router->post( 'pharmacie/comptoir',      'FKC_PharmacieController@comptoirAction', $pho );
	$router->get(  'pharmacie/stupefiants',   'FKC_PharmacieController@stupefiants', $pho );
}

/* ── Pack BTP : chantiers & situations de travaux ─────────────────────────── */
if ( FKC_Packs::providesModule( 'btp' ) ) {
	$btp = array( 'auth' => true, 'societe' => true, 'module' => 'btp' );
	$router->get(  'btp',                               'FKC_BtpController@dashboard', $btp );
	$router->get(  'btp/pilotage',                      'FKC_BtpController@pilotage', $btp );
	$router->get(  'btp/assistant',                     'FKC_BtpController@assistant', $btp );
	$router->get(  'btp/rapports',                      'FKC_BtpController@rapports', $btp );
	$router->get(  'btp/previsions',                    'FKC_BtpController@previsions', $btp );
	$router->get(  'btp/scenarios',                     'FKC_BtpController@scenarios', $btp );
	$router->get(  'btp/personnaliser',                 'FKC_BtpController@personnaliser', $btp );
	$router->post( 'btp/personnaliser',                 'FKC_BtpController@personnaliserSave', $btp );
	$router->get(  'btp/chantiers',                     'FKC_BtpController@chantiers', $btp );
	$router->get(  'btp/chantiers/nouveau',             'FKC_BtpController@create', $btp );
	$router->post( 'btp/chantiers',                     'FKC_BtpController@store', $btp );
	$router->get(  'btp/situations',                    'FKC_BtpController@situations', $btp );
	$router->post( 'btp/situations/{id}/action',        'FKC_BtpController@situationAction', $btp );
	$router->post( 'btp/situations/{id}/liberer-retenue', 'FKC_BtpController@situationLibererRetenue', array( 'auth' => true, 'societe' => true, 'module' => 'btp', 'admin' => true ) );
	$router->get(  'btp/chantiers/{id}/situations/nouvelle', 'FKC_BtpController@situationCreate', $btp );
	$router->post( 'btp/chantiers/{id}/situations',     'FKC_BtpController@situationStore', $btp );
	$router->get(  'btp/chantiers/{id}/modifier',       'FKC_BtpController@edit', $btp );
	$router->get(  'btp/chantiers/{id}',                'FKC_BtpController@show', $btp );
	$router->post( 'btp/chantiers/{id}',                'FKC_BtpController@update', $btp );
	// Terrain expert : planning Gantt, pointages, engins, documents, réceptions.
	$router->get(  'btp/planning',                      'FKC_BtpTerrainController@planning', $btp );
	$router->post( 'btp/planning',                      'FKC_BtpTerrainController@planningAction', $btp );
	$router->get(  'btp/terrain',                       'FKC_BtpTerrainController@terrain', $btp );
	$router->post( 'btp/terrain',                       'FKC_BtpTerrainController@terrainAction', $btp );
}

/* ── Moteur de processus (Workflow Engine) : définitions & instances ─────── */
$fkc_wf = array( 'auth' => true, 'societe' => true );
$router->get(  'workflow/processus',                    'FKC_ProcessusController@index', $fkc_wf );
$router->get(  'workflow/processus/instances/{id}',     'FKC_ProcessusController@instance', $fkc_wf );
$router->post( 'workflow/processus/instances/{id}/action', 'FKC_ProcessusController@action', $fkc_wf );
$router->get(  'workflow/processus/{id}/modifier',      'FKC_ProcessusController@edit', array( 'auth' => true, 'societe' => true, 'admin' => true ) );
$router->post( 'workflow/processus/{id}/basculer',      'FKC_ProcessusController@toggle', array( 'auth' => true, 'societe' => true, 'admin' => true ) );
$router->post( 'workflow/processus/{id}',               'FKC_ProcessusController@update', array( 'auth' => true, 'societe' => true, 'admin' => true ) );
$router->get( 'api-console',        'FKC_ApiConsoleController@index',       array( 'auth' => true, 'societe' => true, 'module' => 'api' ) );

// Multi-sociétés (sélecteur pour tous ; création/gestion réservées à l'admin dans le contrôleur).
$router->get( 'societes',                    'FKC_SocieteController@index',   array( 'auth' => true ) );
$router->get( 'societes/nouvelle',           'FKC_SocieteController@create',  array( 'auth' => true ) );
$router->post( 'societes',                   'FKC_SocieteController@store',   array( 'auth' => true ) );
$router->get( 'societes/{id}/modifier',      'FKC_SocieteController@edit',    array( 'auth' => true ) );
$router->get( 'societes/{id}/logo',          'FKC_SocieteController@logo',    array( 'auth' => true ) );
$router->post( 'societes/{id}',              'FKC_SocieteController@update',  array( 'auth' => true ) );
$router->post( 'societes/{id}/activer',      'FKC_SocieteController@activer', array( 'auth' => true ) );
$router->post( 'societes/{id}/supprimer',    'FKC_SocieteController@destroy', array( 'auth' => true ) );

// Comptabilité.
$mod = array( 'auth' => true, 'societe' => true, 'module' => 'comptabilite' );
// Sous-modules de la Comptabilité (granularité de rôles ; licence = parent 'comptabilite').
$cClient = array( 'auth' => true, 'societe' => true, 'module' => 'compta_clients' );
$cFrn    = array( 'auth' => true, 'societe' => true, 'module' => 'compta_fournisseurs' );
$cAch    = array( 'auth' => true, 'societe' => true, 'module' => 'compta_achats' );
$cTreso  = array( 'auth' => true, 'societe' => true, 'module' => 'compta_tresorerie' );
$router->get( 'comptabilite',                    'FKC_EcritureController@index', $mod );
$router->get( 'comptabilite/ecritures',          'FKC_EcritureController@index', $mod );
$router->get( 'comptabilite/faits-economiques',   'FKC_FaitEcoController@index', $mod );
$router->post( 'comptabilite/faits-economiques/reclasser', 'FKC_FaitEcoController@reclasser', $mod );
$router->post( 'comptabilite/faits-economiques/rattacher-origines', 'FKC_FaitEcoController@rattacherOrigines', $mod );
// Annulation d'écriture, accessible depuis la comptabilité elle-même.
$router->post( 'comptabilite/ecritures/{id}/contrepasser',  'FKC_EcritureController@contrepasser', $mod );
$router->post( 'comptabilite/ecritures/{id}/reactiver',     'FKC_EcritureController@annulerContrepassation', $mod );
$router->post( 'comptabilite/ecritures/{id}/supprimer',     'FKC_EcritureController@supprimerEcriture', $mod );
$router->get( 'comptabilite/ecritures/nouvelle', 'FKC_EcritureController@create', $mod );
$router->post( 'comptabilite/ecritures',         'FKC_EcritureController@store', $mod );
$router->get( 'comptabilite/ecritures/import',          'FKC_EcritureController@importIndex', $mod );
$router->get( 'comptabilite/ecritures/import/modele',   'FKC_EcritureController@importModele', $mod );
$router->post( 'comptabilite/ecritures/import',         'FKC_EcritureController@importStore', $mod );
	$router->get(  'comptabilite/migration',            'FKC_MigrationController@index', $mod );
	$router->post( 'comptabilite/migration/apercu',     'FKC_MigrationController@apercu', $mod );
	$router->post( 'comptabilite/migration/confirmer',  'FKC_MigrationController@confirmer', $mod );
$router->get( 'comptabilite/ecritures/piece/{id}',          'FKC_EcritureController@pieceDownload', $mod );
$router->post( 'comptabilite/ecritures/piece/{id}/supprimer', 'FKC_EcritureController@pieceDelete', $mod );
$router->post( 'comptabilite/ecritures/{id}/pieces',        'FKC_EcritureController@pieceUpload', $mod );
$router->get( 'comptabilite/ecritures/{id}/trace', 'FKC_EcritureController@trace', $mod );
$router->get( 'comptabilite/ecritures/{id}',     'FKC_EcritureController@show', $mod );

// Brouillard (vérification des écritures avant comptabilisation).
$router->get( 'comptabilite/brouillard',                   'FKC_BrouillardController@index', $mod );
$router->post( 'comptabilite/brouillard/parametre',        'FKC_BrouillardController@toggle', $mod );
$router->post( 'comptabilite/brouillard/valider-tout',     'FKC_BrouillardController@validerTout', $mod );
/* Politique de comptabilisation et clôture par lot (journal temporaire). */
$router->post( 'comptabilite/brouillard/politique',        'FKC_BrouillardController@politiqueSave', $mod );
$router->post( 'comptabilite/brouillard/cloturer/{declencheur}', 'FKC_BrouillardController@cloturerLot', $mod );
// Temporisation : passage à la main (le balayage automatique n'a lieu qu'au
// fil des requêtes) et frein à main sur une écriture précise.
$router->post( 'comptabilite/brouillard/publier-echues',   'FKC_BrouillardController@publierEchues', $mod );
$router->get( 'comptabilite/brouillard/piece/{id}',           'FKC_BrouillardController@pieceDownload', $mod );
$router->post( 'comptabilite/brouillard/piece/{id}/supprimer', 'FKC_BrouillardController@pieceDelete', $mod );
$router->post( 'comptabilite/brouillard/{id}/pieces',         'FKC_BrouillardController@pieceUpload', $mod );
$router->get( 'comptabilite/brouillard/{id}',              'FKC_BrouillardController@edit', $mod );
$router->post( 'comptabilite/brouillard/{id}',             'FKC_BrouillardController@update', $mod );
$router->post( 'comptabilite/brouillard/{id}/valider',     'FKC_BrouillardController@valider', $mod );
$router->post( 'comptabilite/brouillard/{id}/supprimer',   'FKC_BrouillardController@delete', $mod );
$router->post( 'comptabilite/brouillard/{id}/retenir',     'FKC_BrouillardController@retenir', $mod );

// Devises : cours de change et revalorisation de clôture (SYSCOHADA 478/479 et 676/776).
$router->get(  'comptabilite/devises',                          'FKC_DeviseController@index', $mod );
$router->post( 'comptabilite/devises/enregistrer',              'FKC_DeviseController@enregistrer', $mod );
$router->post( 'comptabilite/devises/source',                   'FKC_DeviseController@definirSource', $mod );
$router->post( 'comptabilite/devises/identifiants',             'FKC_DeviseController@identifiantsXe', $mod );
$router->post( 'comptabilite/devises/recuperer',                'FKC_DeviseController@recuperer', $mod );
$router->get(  'comptabilite/devises/revalorisation',           'FKC_DeviseController@revalorisation', $mod );
$router->post( 'comptabilite/devises/passer-revalorisation',    'FKC_DeviseController@passerRevalorisation', $mod );
$router->post( 'comptabilite/devises/extourner',                'FKC_DeviseController@extourner', $mod );



$router->get( 'comptabilite/comptes',                  'FKC_CompteController@index', $mod );
$router->get( 'comptabilite/comptes/export',           'FKC_CompteController@export', $mod );
$router->get( 'comptabilite/comptes/nouveau',          'FKC_CompteController@create', $mod );
$router->post( 'comptabilite/comptes',                 'FKC_CompteController@store', $mod );
$router->get( 'comptabilite/comptes/{id}/modifier',    'FKC_CompteController@edit', $mod );
$router->post( 'comptabilite/comptes/{id}',            'FKC_CompteController@update', $mod );
$router->post( 'comptabilite/comptes/{id}/supprimer',  'FKC_CompteController@destroy', $mod );
$router->get(  'comptabilite/plan/generateur',         'FKC_CompteController@generateur', $mod );
$router->post( 'comptabilite/plan/generer',            'FKC_CompteController@generer', $mod );
$router->post( 'comptabilite/plan/corriger',           'FKC_CompteController@corriger', $mod );
$router->get(  'comptabilite/plan/purge',              'FKC_CompteController@purge', $mod );
$router->post( 'comptabilite/plan/purge/executer',     'FKC_CompteController@purgeExecuter', $mod );
$router->post( 'comptabilite/plan/purge/desactiver',   'FKC_CompteController@purgeDesactiver', $mod );
$router->post( 'comptabilite/plan/virer',              'FKC_CompteController@virer', $mod );

$router->get( 'comptabilite/balance',     'FKC_RapportController@balance', $mod );
$router->get( 'comptabilite/grand-livre', 'FKC_RapportController@grandLivre', $mod );
$router->get( 'comptabilite/compte-resultat', 'FKC_RapportController@compteResultat', $mod );
$router->get( 'comptabilite/bilan',           'FKC_RapportController@bilan', $mod );

// Comptabilité Client
$router->get( 'comptabilite/clients',                 'FKC_ClientCptaController@index', $cClient );
$router->get( 'comptabilite/clients/creances',        'FKC_ClientCptaController@creances', $cClient );
$router->post( 'comptabilite/clients',                'FKC_ClientCptaController@store', $cClient );
$router->get( 'comptabilite/clients/{id}/encaisser',  'FKC_ClientCptaController@encaisserForm', $cClient );
$router->post( 'comptabilite/clients/{id}/encaisser', 'FKC_ClientCptaController@encaisser', $cClient );

// Comptabilité Fournisseur
$router->get( 'comptabilite/fournisseurs',                'FKC_FournisseurController@index', $cFrn );
$router->get( 'comptabilite/fournisseurs/dettes',         'FKC_FournisseurController@dettes', $cFrn );
$router->get( 'comptabilite/fournisseurs/nouveau',        'FKC_FournisseurController@create', $cFrn );
$router->post( 'comptabilite/fournisseurs',               'FKC_FournisseurController@store', $cFrn );
$router->get( 'comptabilite/fournisseurs/{id}/modifier',  'FKC_FournisseurController@edit', $cFrn );
$router->post( 'comptabilite/fournisseurs/{id}',          'FKC_FournisseurController@update', $cFrn );
$router->get( 'comptabilite/fournisseurs/{id}/payer',     'FKC_FournisseurController@payerForm', $cFrn );
$router->post( 'comptabilite/fournisseurs/{id}/payer',    'FKC_FournisseurController@payer', $cFrn );

// Trésorerie
$router->get( 'comptabilite/tresorerie',                'FKC_TresorerieController@index', $cTreso );
$router->post( 'comptabilite/tresorerie/comptes',       'FKC_TresorerieController@storeCompte', $cTreso );
$router->post( 'comptabilite/tresorerie/declarer-manquants', 'FKC_TresorerieController@declarerManquants', $cTreso );
$router->get( 'comptabilite/tresorerie/mouvement',      'FKC_TresorerieController@mouvementForm', $cTreso );
$router->post( 'comptabilite/tresorerie/mouvement',     'FKC_TresorerieController@store', $cTreso );
$router->get( 'comptabilite/tresorerie/rapprochements', 'FKC_TresorerieController@rapprochements', $cTreso );
$router->get( 'comptabilite/tresorerie/rapprochement/{id}', 'FKC_TresorerieController@rapprochement', $cTreso );
$router->post( 'comptabilite/tresorerie/pointe/{id}',   'FKC_TresorerieController@pointe', $cTreso );
$router->post( 'comptabilite/reglements/{id}/annuler', 'FKC_TresorerieController@annulerReglement', $cTreso );
$router->post( 'comptabilite/reglements/{id}/corriger', 'FKC_TresorerieController@corrigerReglement', $cTreso );

// Comptabilité GL (centralisation, clôtures)
$router->get( 'comptabilite/gl',                'FKC_GlController@centralisation', $mod );

/*
 * ASSISTANT DE CLÔTURE (1.778.0).
 *
 * L'arrêté des comptes vivait dans Paramètres → Exercices, réservé aux
 * administrateurs — c'est-à-dire hors de portée du comptable qui fait le
 * travail. Il rejoint la Comptabilité, où on le cherche. Les gestes
 * irréversibles (réouverture, verrouillage définitif, annulation d'une
 * affectation ou des à-nouveaux) restent réservés à l'administrateur, mais
 * par un contrôle DANS les moteurs et non par l'inaccessibilité de l'écran :
 * un comptable doit pouvoir voir ce qui bloque sa clôture, même s'il ne peut
 * pas lever lui-même une dérogation.
 */
$router->get(  'comptabilite/cloture',                    'FKC_ClotureController@index', $mod );
$router->get(  'comptabilite/cloture/journal',            'FKC_ClotureController@journal', $mod );
$router->get(  'comptabilite/cloture/imprimer',           'FKC_ClotureController@imprimer', $mod );
$router->get(  'comptabilite/cloture/tva',                'FKC_ClotureController@tva', $mod );
$router->get(  'comptabilite/cloture/acomptes',           'FKC_ClotureController@acomptes', $mod );
$router->post( 'comptabilite/cloture/acomptes/planifier', 'FKC_ClotureController@acomptesPlanifier', $mod );
$router->post( 'comptabilite/cloture/acomptes/creer',     'FKC_ClotureController@acompteCreer', $mod );
$router->post( 'comptabilite/cloture/acomptes/imputer',   'FKC_ClotureController@acomptesImputer', $mod );
$router->post( 'comptabilite/cloture/acomptes/{id}/payer',   'FKC_ClotureController@acomptePayer', $mod );
$router->post( 'comptabilite/cloture/acomptes/{id}/annuler', 'FKC_ClotureController@acompteAnnuler', $mod );
$router->get(  'comptabilite/cloture/inventaire',         'FKC_ClotureController@inventaire', $mod );
$router->get(  'comptabilite/cloture/fiscal',             'FKC_ClotureController@fiscal', $mod );
$router->post( 'comptabilite/cloture/fiscal/ajouter',     'FKC_ClotureController@retraitementAjouter', $mod );
$router->post( 'comptabilite/cloture/fiscal/accepter',    'FKC_ClotureController@retraitementAccepter', $mod );
$router->post( 'comptabilite/cloture/fiscal/figer',       'FKC_ClotureController@figerFiscal', $mod );
$router->post( 'comptabilite/cloture/fiscal/defiger',     'FKC_ClotureController@defigerFiscal', $mod + array( 'admin' => true ) );
$router->post( 'comptabilite/cloture/fiscal/{id}/retirer','FKC_ClotureController@retraitementRetirer', $mod );
$router->post( 'comptabilite/cloture/etape',              'FKC_ClotureController@validerEtape', $mod );
$router->post( 'comptabilite/cloture/justifier',          'FKC_ClotureController@justifier', $mod );
$router->post( 'comptabilite/cloture/justification/retirer', 'FKC_ClotureController@retirerJustification', $mod );
$router->post( 'comptabilite/cloture/resultat',           'FKC_ClotureController@determinerResultat', $mod );
$router->post( 'comptabilite/cloture/cloturer',           'FKC_ClotureController@cloturer', $mod );
$router->post( 'comptabilite/cloture/{id}/rouvrir',       'FKC_ClotureController@rouvrir', $mod + array( 'admin' => true ) );
$router->post( 'comptabilite/cloture/{id}/verrouiller',   'FKC_ClotureController@verrouiller', $mod + array( 'admin' => true ) );
$router->get(  'comptabilite/cloture/affectation',        'FKC_ClotureController@affectation', $mod );
$router->post( 'comptabilite/cloture/affectation',        'FKC_ClotureController@affecter', $mod );
$router->post( 'comptabilite/cloture/affectation/annuler','FKC_ClotureController@annulerAffectation', $mod + array( 'admin' => true ) );
$router->get(  'comptabilite/cloture/anouveaux',          'FKC_ClotureController@anouveaux', $mod );
$router->post( 'comptabilite/cloture/anouveaux/generer',  'FKC_ClotureController@genererAnouveaux', $mod );
$router->post( 'comptabilite/cloture/anouveaux/annuler',  'FKC_ClotureController@annulerAnouveaux', $mod + array( 'admin' => true ) );
$router->post( 'comptabilite/cloture/ouvrir',             'FKC_ClotureController@ouvrirExercice', $mod );
$router->get(  'comptabilite/cloture/audit-anouveaux',    'FKC_ClotureController@auditAnouveaux', $mod );
$router->post( 'comptabilite/cloture/audit-anouveaux/resynchroniser', 'FKC_ClotureController@resynchroniserAnouveaux', $mod + array( 'admin' => true ) );
$router->post( 'comptabilite/cloture/audit-anouveaux/reparer', 'FKC_ClotureController@reparerOuverture', $mod + array( 'admin' => true ) );
$router->post( 'comptabilite/cloture/audit-anouveaux/qualifier', 'FKC_ClotureController@qualifierOuverture', $mod + array( 'admin' => true ) );

/*
 * RATTACHEMENT DES CHARGES ET DES PRODUITS (1.780.0).
 *
 * L'écran vit dans l'arrêté des comptes : une facture non parvenue n'est pas
 * une écriture ordinaire passée au fil de l'eau, c'est un geste d'inventaire
 * daté du dernier jour de l'exercice, qui appelle son extourne au premier jour
 * du suivant. Séparer les deux moitiés d'une même opération serait le moyen
 * le plus sûr d'en oublier une.
 */
$router->get(  'comptabilite/regularisations',              'FKC_ClotureController@regularisations', $mod );
$router->post( 'comptabilite/regularisations/creer',        'FKC_ClotureController@regularisationCreer', $mod );
$router->post( 'comptabilite/regularisations/passer-tout',  'FKC_ClotureController@regularisationPasserTout', $mod );
$router->post( 'comptabilite/regularisations/extourner',    'FKC_ClotureController@regularisationExtourner', $mod );
$router->post( 'comptabilite/regularisations/{id}/passer',  'FKC_ClotureController@regularisationPasser', $mod );
$router->post( 'comptabilite/regularisations/{id}/annuler', 'FKC_ClotureController@regularisationAnnuler', $mod );

// Grand livre auxiliaire des tiers + lettrage (sous-modules Clients / Fournisseurs).
$router->get(  'comptabilite/grand-livre-clients',            'FKC_GrandLivreController@clients', $cClient );
$router->post( 'comptabilite/grand-livre-clients/lettrer',    'FKC_GrandLivreController@lettrerClients', $cClient );
$router->post( 'comptabilite/grand-livre-clients/delettrer',  'FKC_GrandLivreController@delettrerClients', $cClient );
$router->post( 'comptabilite/grand-livre-clients/auto',       'FKC_GrandLivreController@autoClients', $cClient );
$router->get(  'comptabilite/grand-livre-fournisseurs',           'FKC_GrandLivreController@fournisseurs', $cFrn );
$router->post( 'comptabilite/grand-livre-fournisseurs/lettrer',   'FKC_GrandLivreController@lettrerFournisseurs', $cFrn );
$router->post( 'comptabilite/grand-livre-fournisseurs/delettrer', 'FKC_GrandLivreController@delettrerFournisseurs', $cFrn );
$router->post( 'comptabilite/grand-livre-fournisseurs/auto',      'FKC_GrandLivreController@autoFournisseurs', $cFrn );
$router->post( 'comptabilite/gl/cloturer',      'FKC_GlController@cloturer', $mod );
$router->post( 'comptabilite/gl/rouvrir/{id}',  'FKC_GlController@rouvrir', $mod );

// Immobilisations
$router->get( 'comptabilite/immobilisations',              'FKC_ImmoController@index', $mod );
$router->get( 'comptabilite/immobilisations/recolement',   'FKC_ImmoController@recolement', $mod );
$router->post( 'comptabilite/immobilisations/recolement',  'FKC_ImmoController@recolementAction', $mod );
$router->get( 'comptabilite/immobilisations/nouvelle',     'FKC_ImmoController@create', $mod );
$router->post( 'comptabilite/immobilisations',             'FKC_ImmoController@store', $mod );
$router->post( 'comptabilite/immobilisations/seuil',       'FKC_ImmoController@seuilSave', $mod );
$router->get( 'comptabilite/immobilisations/plan',         'FKC_ImmoController@plan', $mod );
$router->get( 'comptabilite/immobilisations/comptes',      'FKC_ImmoController@mapping', $mod );
$router->post( 'comptabilite/immobilisations/comptes',     'FKC_ImmoController@mappingSave', $mod );
$router->get( 'comptabilite/immobilisations/import',       'FKC_ImmoController@importIndex', $mod );
$router->post( 'comptabilite/immobilisations/import',      'FKC_ImmoController@importStore', $mod );
$router->get( 'comptabilite/immobilisations/amortir',      'FKC_ImmoController@amortirForm', $mod );
$router->post( 'comptabilite/immobilisations/periodicite', 'FKC_ImmoController@periodiciteSave', $mod );
$router->post( 'comptabilite/immobilisations/amortir',     'FKC_ImmoController@amortirRun', $mod );
$router->get( 'comptabilite/immobilisations/cession',      'FKC_ImmoController@cessionForm', $mod );
$router->post( 'comptabilite/immobilisations/ceder',       'FKC_ImmoController@cederSelection', $mod );
$router->get( 'comptabilite/immobilisations/piece/{id}',           'FKC_ImmoController@pieceDownload', $mod );
$router->post( 'comptabilite/immobilisations/piece/{id}/supprimer', 'FKC_ImmoController@pieceDelete', $mod );
$router->post( 'comptabilite/immobilisations/{id}/pieces',         'FKC_ImmoController@pieceUpload', $mod );
$router->post( 'comptabilite/immobilisations/{id}/annuler',   'FKC_ImmoController@annuler', $mod );
$router->post( 'comptabilite/immobilisations/{id}/supprimer', 'FKC_ImmoController@supprimer', $mod );
$router->get( 'comptabilite/immobilisations/{id}',         'FKC_ImmoController@show', $mod );
$router->post( 'comptabilite/immobilisations/{id}/comptes', 'FKC_ImmoController@comptesSave', $mod );
$router->post( 'comptabilite/immobilisations/{id}/doter',  'FKC_ImmoController@doter', $mod );
$router->post( 'comptabilite/immobilisations/{id}/ceder',  'FKC_ImmoController@ceder', $mod );
// 1.856.0 — dépréciations, unités d'œuvre, composants, réévaluation, mise en service.
$router->post( 'comptabilite/immobilisations/{id}/deprecier',       'FKC_ImmoController@deprecier', $mod );
$router->post( 'comptabilite/immobilisations/{id}/unites',          'FKC_ImmoController@unites', $mod );
$router->post( 'comptabilite/immobilisations/{id}/composant',       'FKC_ImmoController@composant', $mod );
$router->post( 'comptabilite/immobilisations/{id}/reevaluer',       'FKC_ImmoController@reevaluer', $mod );
$router->post( 'comptabilite/immobilisations/{id}/mise-en-service', 'FKC_ImmoController@miseEnService', $mod );

// Factures fournisseurs (achats)
$router->get( 'comptabilite/achats',              'FKC_AchatController@index', $cAch );
$router->get( 'comptabilite/achats/nouvelle',     'FKC_AchatController@create', $cAch );
// Attribution d'un fournisseur à une réception qui n'en avait pas (dette anonyme).
$router->post( 'comptabilite/achats/reception-attribuer', 'FKC_AchatController@receptionAttribuer', $cAch );
$router->post( 'comptabilite/achats',             'FKC_AchatController@store', $cAch );
$router->get( 'comptabilite/achats/import-fne',   'FKC_AchatController@importFne', $cAch );
$router->post( 'comptabilite/achats/import-fne',  'FKC_AchatController@importFneStore', $cAch );
/*
 * Demandes d'achat — déclarées AVANT la route « achats/{id} ».
 * Le routeur retient la première correspondance : placée après, « demandes »
 * serait capturée comme un identifiant de facture et l'écran resterait
 * inaccessible.
 */
/* Bons de réception — déclarés avant « achats/{id} », comme les demandes. */
$router->get(  'comptabilite/achats/receptions',          'FKC_ReceptionController@index', $cAch );
$router->post( 'comptabilite/achats/receptions/{id}/attribuer', 'FKC_ReceptionController@attribuer', $cAch );

/* Avoirs fournisseurs — déclarés avant « achats/{id} », comme les demandes. */
$router->get(  'comptabilite/achats/avoirs',              'FKC_AvoirFournisseurController@index', $cAch );
$router->post( 'comptabilite/achats/avoirs',              'FKC_AvoirFournisseurController@store', $cAch );
$router->get(  'comptabilite/achats/demandes',            'FKC_DemandeAchatController@index', $cAch );
$router->post( 'comptabilite/achats/demandes',            'FKC_DemandeAchatController@store', $cAch );
$router->post( 'comptabilite/achats/demandes/evaluer',    'FKC_DemandeAchatController@evaluer', $cAch );
$router->post( 'comptabilite/achats/demandes/{id}/decider',   'FKC_DemandeAchatController@decider', $cAch );
$router->post( 'comptabilite/achats/demandes/{id}/commander', 'FKC_DemandeAchatController@commander', $cAch );

/* Pièces jointes. Déclarées avant les routes à paramètre par simple hygiène :
   les segments littéraux d'abord, les jokers ensuite — c'est l'ordre qui rend
   une table de routage relisible, et celui qui évite les surprises le jour où
   un segment littéral et un identifiant prennent la même forme. */
$router->get(  'comptabilite/achats/piece/{id}',           'FKC_AchatController@pieceDownload', $cAch );
$router->post( 'comptabilite/achats/piece/{id}/supprimer', 'FKC_AchatController@pieceDelete', $cAch );
$router->get( 'comptabilite/achats/{id}',         'FKC_AchatController@show', $cAch );
$router->post( 'comptabilite/achats/{id}/pieces', 'FKC_AchatController@pieceUpload', $cAch );
$router->post( 'comptabilite/achats/{id}/supprimer', 'FKC_AchatController@supprimer', $cAch );
$router->get(  'comptabilite/achats/{id}/corriger',  'FKC_AchatController@corrigerForm', $cAch );
$router->get( 'comptabilite/achats/{id}/imprimer','FKC_AchatController@imprimer', $cAch );
$router->post( 'comptabilite/achats/{id}/annuler','FKC_AchatController@annuler', $cAch );

// Bons de commande (achats)
$router->get( 'comptabilite/bons-commande',                 'FKC_BonCommandeController@index', $cAch );
$router->get( 'comptabilite/bons-commande/nouveau',         'FKC_BonCommandeController@create', $cAch );
$router->post( 'comptabilite/bons-commande',                'FKC_BonCommandeController@store', $cAch );
$router->post( 'comptabilite/bons-commande/{id}/rattacher', 'FKC_BonCommandeController@rattacher', $cAch );
$router->post( 'comptabilite/bons-commande/{id}/detacher',  'FKC_BonCommandeController@detacher', $cAch );
$router->post( 'comptabilite/bons-commande/{id}/annuler',   'FKC_BonCommandeController@annuler', $cAch );

// États financiers DGI (liasses SMT et Système Normal)
$router->get( 'comptabilite/etats-dgi',          'FKC_EtatsDgiController@index', $mod );
$router->get( 'comptabilite/etats-dgi/imprimer', 'FKC_EtatsDgiController@imprimer', $mod );
$router->get( 'comptabilite/etats-dgi/export',   'FKC_EtatsDgiController@csv', $mod );
// Fichier EDI XML importable dans le module Téléliasse d'e-impots.
$router->get( 'comptabilite/etats-dgi/edi',      'FKC_EtatsDgiController@edi', $mod );
// Annexe descriptive : la seule partie de la liasse que le dossier écrit lui-même.
$router->post( 'comptabilite/etats-dgi/notes',   'FKC_EtatsDgiController@enregistrerNotes', $mod );
$router->post( 'comptabilite/etats-dgi/notes-reprise', 'FKC_EtatsDgiController@reprendreNotes', $mod );
$router->post( 'comptabilite/etats-dgi/ntd',     'FKC_EtatsDgiController@ntd', $mod );
// Système comptable du dossier (SMT / SN) : l'écran s'ouvre ensuite dessus.
$router->post( 'comptabilite/etats-dgi/systeme', 'FKC_EtatsDgiController@definirSysteme', $mod );
// Dépôt de la liasse : empreinte figée, divergences signalées ensuite (1.854.0).
$router->post( 'comptabilite/etats-dgi/depot', 'FKC_EtatsDgiController@deposer', $mod );
$router->get( 'comptabilite/grand-livre/export', 'FKC_RapportController@grandLivreCsv', $mod );

// Extractions comptables (balance générale, balances âgées)
$router->get( 'comptabilite/extractions',        'FKC_ExtractionController@index', $mod );
$router->get( 'comptabilite/extractions/export', 'FKC_ExtractionController@csv', $mod );
$router->get( 'comptabilite/etats-financiers',   'FKC_EtatsFinanciersController@index', $mod );

// Facturation (+ FNE) — disponible dans TOUTES les éditions.
$fac = array( 'auth' => true, 'societe' => true, 'module' => 'facturation' );
$router->get( 'facturation',                        'FKC_FactureController@index', $fac );
$router->get( 'facturation/factures',               'FKC_FactureController@index', $fac );
$router->get( 'facturation/factures/proforma',      'FKC_FactureController@proforma', $fac );
$router->get( 'facturation/factures/nouvelle',      'FKC_FactureController@create', $fac );
$router->post( 'facturation/factures',              'FKC_FactureController@store', $fac );
$router->get( 'facturation/factures/piece/{id}',           'FKC_FactureController@pieceDownload', $fac );
$router->post( 'facturation/factures/piece/{id}/supprimer', 'FKC_FactureController@pieceDelete', $fac );
$router->post( 'facturation/factures/{id}/pieces',         'FKC_FactureController@pieceUpload', $fac );
$router->get( 'facturation/factures/{id}',          'FKC_FactureController@show', $fac );
$router->post( 'facturation/factures/{id}/renumeroter', 'FKC_FactureController@renumeroter', $fac );
$router->get( 'facturation/factures/{id}/modifier', 'FKC_FactureController@edit', $fac );
$router->post( 'facturation/factures/{id}/modifier','FKC_FactureController@update', $fac );
$router->get( 'facturation/factures/{id}/imprimer', 'FKC_FactureController@imprimer', $fac );
$router->post( 'facturation/factures/{id}/certifier','FKC_FactureController@certify', $fac );
$router->post( 'facturation/factures/{id}/comptabiliser','FKC_FactureController@comptabiliser', $fac );
$router->post( 'facturation/factures/{id}/demander-validation','FKC_FactureController@demanderValidation', $fac );
$router->post( 'facturation/factures/{id}/avoir',   'FKC_FactureController@avoir', $fac );
$router->post( 'facturation/factures/{id}/avoir-note', 'FKC_FactureController@avoirNote', $fac );
$router->post( 'facturation/factures/{id}/annuler',  'FKC_FactureController@annuler', $fac );   // 1.850.0 : facture jamais comptabilisée
$router->post( 'facturation/factures/{id}/corriger', 'FKC_FactureController@corriger', $fac );  // 1.850.0 : avoir + ressaisie
$router->get(  'facturation/factures/{id}/ressaisir','FKC_FactureController@ressaisir', $fac );

$router->get( 'facturation/clients',                'FKC_ClientController@index', $fac );
$router->get( 'facturation/clients/nouveau',        'FKC_ClientController@create', $fac );
$router->post( 'facturation/clients',               'FKC_ClientController@store', $fac );
$router->get( 'facturation/clients/{id}/modifier',  'FKC_ClientController@edit', $fac );
$router->post( 'facturation/clients/{id}',          'FKC_ClientController@update', $fac );

$router->get( 'facturation/fne',                    'FKC_FneController@config', $fac );
$router->post( 'facturation/fne',                   'FKC_FneController@save', $fac );
$router->post( 'facturation/fne/test',              'FKC_FneController@test', $fac );
$router->get( 'facturation/fne/specimens',           'FKC_FneController@specimens', $fac );
$router->post( 'facturation/fne/specimens/ventes',  'FKC_FneController@specimensVentes', $fac );
$router->post( 'facturation/fne/specimens/avoirs',  'FKC_FneController@specimensAvoirs', $fac );
$router->post( 'facturation/fne/specimens/supprimer', 'FKC_FneController@specimensSupprimer', $fac );
$router->get( 'facturation/fne/specimens/dossier',  'FKC_FneController@specimensDossier', $fac );
$router->get( 'facturation/fne/suivi',              'FKC_FneController@supervision', $fac );
$router->post( 'facturation/fne/relancer',          'FKC_FneController@relancer', $fac );

// Fiscalité (éditions Business, Pro, Enterprise, Creative).
$fisc = array( 'auth' => true, 'societe' => true, 'module' => 'fiscalite' );
$router->get( 'fiscalite',          'FKC_FiscaliteController@dashboard', $fisc );
$router->get( 'fiscalite/declarations',          'FKC_FiscaliteController@declarations', $fisc );
$router->post( 'fiscalite/declarations/passer',  'FKC_FiscaliteController@passer', $fisc );
$router->get( 'fiscalite/declarations/journal',  'FKC_FiscaliteController@declarationsJournal', $fisc );
$router->get( 'fiscalite/declarations/{id}',     'FKC_FiscaliteController@declarationShow', $fisc );
$router->post( 'fiscalite/declarations/{id}/action', 'FKC_FiscaliteController@declarationAction', $fisc );
$router->get( 'fiscalite/regime',   'FKC_FiscaliteController@regime', $fisc );
$router->post( 'fiscalite/regime',  'FKC_FiscaliteController@save', $fisc );
$router->get( 'fiscalite/regimes',  'FKC_FiscaliteController@regimes', $fisc );
$router->get( 'fiscalite/ca-previsionnel',            'FKC_FiscaliteController@caPrevisionnel', $fisc );
$router->post( 'fiscalite/ca-previsionnel',           'FKC_FiscaliteController@caPrevisionnelSave', $fisc );
$router->post( 'fiscalite/ca-previsionnel/supprimer', 'FKC_FiscaliteController@caPrevisionnelSupprimer', $fisc );
$router->post( 'fiscalite/regimes/taux',      'FKC_FiscaliteController@tauxOuvrir', $fisc );
$router->post( 'fiscalite/regimes/categorie', 'FKC_FiscaliteController@categorieQualifier', $fisc );
$router->get( 'fiscalite/reprise',  'FKC_FiscaliteController@reprise', $fisc );
$router->post( 'fiscalite/reprise', 'FKC_FiscaliteController@repriseTransfert', $fisc );

// Analytique — contrôle de gestion par division (éditions Pro, Enterprise, Creative).
$ana = array( 'auth' => true, 'societe' => true, 'module' => 'analytique' );
$router->get( 'analytique',                          'FKC_AnalytiqueController@index', $ana );
$router->get( 'analytique/centres-couts',            'FKC_AnalytiqueController@centresCouts', $ana );
$router->get( 'analytique/centres-profit',           'FKC_AnalytiqueController@centresProfit', $ana );
$router->get( 'analytique/budgets',                  'FKC_AnalytiqueController@budgets', $ana );
$router->post( 'analytique/budgets',                 'FKC_AnalytiqueController@budgetSave', $ana );
$router->get( 'analytique/controle-budgetaire',      'FKC_AnalytiqueController@controle', $ana );
$router->get( 'analytique/budgets-comparatifs',      'FKC_AnalytiqueController@comparatif', $ana );
// Budget mensuel par nature (1.831.0)
$router->get( 'analytique/budget-mensuel',           'FKC_AnalytiqueController@budgetMensuel', $ana );
$router->post( 'analytique/budget-mensuel',          'FKC_AnalytiqueController@budgetMensuelSave', $ana );
$router->get( 'analytique/budget-mensuel/suivi',     'FKC_AnalytiqueController@budgetMensuelSuivi', $ana );
$router->get( 'analytique/budget-mensuel/export',    'FKC_AnalytiqueController@budgetMensuelExport', $ana );
$router->get( 'analytique/multi-axes',               'FKC_AnalytiqueController@multiAxes', $ana );
$router->post( 'analytique/multi-axes/axes',         'FKC_AnalytiqueController@axeStore', $ana );
$router->post( 'analytique/multi-axes/axes/{id}',           'FKC_AnalytiqueController@axeUpdate', $ana );
$router->post( 'analytique/multi-axes/axes/{id}/actif',     'FKC_AnalytiqueController@axeToggle', $ana );
$router->post( 'analytique/multi-axes/axes/{id}/supprimer', 'FKC_AnalytiqueController@axeDelete', $ana );
$router->get( 'analytique/bi',                       'FKC_AnalytiqueController@bi', $ana );
// Paramétrage général
$router->get( 'analytique/parametrage',              'FKC_AnalytiqueController@parametrage', $ana );
$router->post( 'analytique/parametrage/parametres',  'FKC_AnalytiqueController@parametresSave', $ana );
$router->post( 'analytique/parametrage/exercices',   'FKC_AnalytiqueController@exerciceStore', $ana );
$router->post( 'analytique/parametrage/exercices/{id}/supprimer', 'FKC_AnalytiqueController@exerciceDelete', $ana );
// Valeurs analytiques
$router->get( 'analytique/valeurs',                  'FKC_AnalytiqueController@valeurs', $ana );
$router->post( 'analytique/valeurs',                 'FKC_AnalytiqueController@valeurStore', $ana );
$router->post( 'analytique/valeurs/{id}/supprimer',  'FKC_AnalytiqueController@valeurDelete', $ana );
$router->post( 'analytique/valeurs/{id}/basculer',   'FKC_AnalytiqueController@valeurToggle', $ana );
// Suppression FORCÉE : réservée à l'administrateur (voir le contrôleur).
$router->post( 'analytique/valeurs/{id}/supprimer-quand-meme', 'FKC_AnalytiqueController@valeurDeleteForce', $ana + array( 'admin' => true ) );
// Affectations multi-axes
$router->get( 'analytique/affectations',             'FKC_AnalytiqueController@affectations', $ana );
$router->post( 'analytique/affectations',            'FKC_AnalytiqueController@affectationsSave', $ana );
// Ventilation d'une ligne en pourcentages, application de clés (1.823.0)
$router->get( 'analytique/affectations/{id}/ventiler',  'FKC_AnalytiqueController@ventiler', $ana );
$router->post( 'analytique/affectations/{id}/ventiler', 'FKC_AnalytiqueController@ventilerSave', $ana );
$router->post( 'analytique/affectations/appliquer-cle', 'FKC_AnalytiqueController@appliquerCle', $ana );
$router->post( 'analytique/repartitions/{id}/reappliquer', 'FKC_AnalytiqueController@reappliquerCle', $ana );
// Règles d'imputation automatique (1.824.0)
$router->get( 'analytique/regles',                   'FKC_AnalytiqueController@regles', $ana );
$router->post( 'analytique/regles',                  'FKC_AnalytiqueController@regleStore', $ana );
$router->post( 'analytique/regles/appliquer',        'FKC_AnalytiqueController@reglesAppliquer', $ana );
$router->post( 'analytique/regles/{id}/basculer',    'FKC_AnalytiqueController@regleToggle', $ana );
$router->post( 'analytique/regles/{id}/supprimer',   'FKC_AnalytiqueController@regleDelete', $ana );
// Résultats analytiques par axe
$router->get( 'analytique/resultats-analytiques',    'FKC_AnalytiqueController@resultatsAnalytiques', $ana );
// Détail d'un montant et exports CSV (1.825.0)
$router->get( 'analytique/detail',                   'FKC_AnalytiqueController@detail', $ana );
$router->get( 'analytique/detail/export',            'FKC_AnalytiqueController@detailExport', $ana );
$router->get( 'analytique/export',                   'FKC_AnalytiqueController@resultatsExport', $ana );
// Tableau croisé sur deux axes (1.826.0)
$router->get( 'analytique/croise',                   'FKC_AnalytiqueController@croise', $ana );
$router->get( 'analytique/croise/export',            'FKC_AnalytiqueController@croiseExport', $ana );
// Répartitions
$router->get(  'analytique/auxiliaires',                  'FKC_AnalytiqueController@auxiliaires', $ana );
$router->post( 'analytique/auxiliaires/creer',            'FKC_AnalytiqueController@auxiliaireCreer', $ana );
$router->post( 'analytique/auxiliaires/{id}/part',        'FKC_AnalytiqueController@auxiliairePart', $ana );
$router->post( 'analytique/auxiliaires/{id}/retirer',     'FKC_AnalytiqueController@auxiliaireRetirer', $ana );
$router->post( 'analytique/auxiliaires/{id}/basculer',    'FKC_AnalytiqueController@auxiliaireBasculer', $ana );
$router->post( 'analytique/auxiliaires/{id}/supprimer',   'FKC_AnalytiqueController@auxiliaireSupprimer', $ana );
$router->get( 'analytique/repartitions',             'FKC_AnalytiqueController@repartitions', $ana );
$router->post( 'analytique/repartitions',            'FKC_AnalytiqueController@repartitionStore', $ana );
$router->post( 'analytique/repartitions/cle',        'FKC_AnalytiqueController@repartitionCle', $ana );
$router->post( 'analytique/repartitions/cle/retirer','FKC_AnalytiqueController@repartitionCleDelete', $ana );
$router->post( 'analytique/repartitions/{id}/supprimer', 'FKC_AnalytiqueController@repartitionDelete', $ana );
// KPI
$router->get( 'analytique/kpi',                      'FKC_AnalytiqueController@kpi', $ana );
// Budgets / contrôle par axe
$router->get( 'analytique/budgets-axe',              'FKC_AnalytiqueController@budgetsAxe', $ana );
$router->post( 'analytique/budgets-axe',             'FKC_AnalytiqueController@budgetAxeSave', $ana );
$router->get( 'analytique/controle-axe',             'FKC_AnalytiqueController@controleAxe', $ana );
$router->get( 'analytique/divisions',                'FKC_AnalytiqueController@divisions', $ana );
$router->get( 'analytique/divisions/nouvelle',       'FKC_AnalytiqueController@create', $ana );
$router->post( 'analytique/divisions',               'FKC_AnalytiqueController@store', $ana );
$router->get( 'analytique/divisions/{id}/modifier',  'FKC_AnalytiqueController@edit', $ana );
$router->post( 'analytique/divisions/{id}',          'FKC_AnalytiqueController@update', $ana );
$router->post( 'analytique/divisions/{id}/supprimer','FKC_AnalytiqueController@destroy', $ana );
$router->post( 'analytique/divisions/{id}/basculer', 'FKC_AnalytiqueController@toggleDivision', $ana );
$router->post( 'analytique/divisions/{id}/supprimer-quand-meme', 'FKC_AnalytiqueController@destroyForce', $ana + array( 'admin' => true ) );

// Paramètres — hub des réglages et personnalisations.
$router->get( 'parametres', 'FKC_ParametresController@index', array( 'auth' => true, 'societe' => true ) );

// Paramètres — choix du pack métier (Vertical Pack).
$router->get(  'parametres/pack', 'FKC_PackController@index',  array( 'auth' => true, 'societe' => true ) );
$router->post( 'parametres/pack', 'FKC_PackController@update', array( 'auth' => true, 'admin' => true, 'societe' => true ) );
$router->post( 'parametres/pack/complementaires', 'FKC_PackController@complementairesUpdate', array( 'auth' => true, 'admin' => true, 'societe' => true ) );

// Paramètres — outils d'administration (admin uniquement).
$padm = array( 'auth' => true, 'admin' => true, 'societe' => true );
$router->get( 'parametres/exercices',              'FKC_ParamAdminController@exercices', $padm );
$router->post( 'parametres/exercices/ouvrir',      'FKC_ParamAdminController@ouvrirExercice', $padm );
$router->post( 'parametres/exercices/{id}/annuler-ouverture', 'FKC_ParamAdminController@annulerOuverture', $padm );
$router->post( 'parametres/exercices/cloturer',    'FKC_ParamAdminController@cloturer', $padm );
$router->post( 'parametres/exercices/{id}/rouvrir','FKC_ParamAdminController@rouvrir', $padm );
$router->get( 'parametres/tresorerie',             'FKC_ParamAdminController@tresorerie', $padm );
$router->post( 'parametres/tresorerie/ajouter',    'FKC_ParamAdminController@tresorerieAdd', $padm );
$router->post( 'parametres/tresorerie/{id}/basculer','FKC_ParamAdminController@tresorerieToggle', $padm );
$router->get(  'parametres/fiscalite',              'FKC_ParamAdminController@fiscalite', $padm );
$router->post( 'parametres/fiscalite/defauts',     'FKC_ParamAdminController@fiscaliteDefauts', $padm );
$router->post( 'parametres/fiscalite/categorie',   'FKC_ParamAdminController@fiscaliteCategorieAdd', $padm );
$router->post( 'parametres/fiscalite/taux',        'FKC_ParamAdminController@fiscaliteTaux', $padm );
$router->post( 'parametres/fiscalite/{id}/supprimer','FKC_ParamAdminController@fiscaliteCategorieDel', $padm );
$router->post( 'parametres/fiscalite/natures',     'FKC_ParamAdminController@fiscaliteNatures', $padm );
$router->post( 'parametres/fiscalite/bareme',      'FKC_ParamAdminController@fiscaliteBareme', $padm );
$router->get( 'parametres/integrite',              'FKC_ParamAdminController@integrite', $padm );
$router->get( 'parametres/processus',              'FKC_ParamAdminController@processus', $padm );

/*
 * MODE HORS LIGNE.
 *
 * « sw.js » et le manifeste sont servis À LA RACINE DE L'APPLICATION, et sans
 * authentification. Ce n'est pas une facilité : la portée d'un service worker
 * est limitée au chemin d'où il est servi, et les assets du plugin vivent sur
 * un chemin sans rapport avec celui de l'application. Livré comme un simple
 * fichier, le worker s'installerait sans erreur et n'intercepterait jamais
 * rien — une panne invisible. Le navigateur va par ailleurs le rechercher
 * périodiquement, y compris sans session ouverte : exiger une authentification
 * bloquerait ses mises à jour en silence.
 */
$router->get( 'sw.js',                             'FKC_HorsLigneController@sw' );
$router->get( 'manifest.webmanifest',              'FKC_HorsLigneController@manifeste' );
$router->get( 'parametres/hors-ligne',             'FKC_HorsLigneController@etat', $padm );
$router->post( 'parametres/hors-ligne/reserver',   'FKC_HorsLigneController@reserver', $padm );

/*
 * ── TERMINAUX (1.724.0) ───────────────────────────────────────────────────
 * Déclarer un appareil ouvre une porte publique sur une partie des données :
 * c'est un geste d'administration, pas un réglage d'affichage.
 */
$router->get(  'parametres/terminaux',                  'FKC_TerminalController@index', $padm );
$router->post( 'parametres/terminaux',                  'FKC_TerminalController@store', $padm );
$router->post( 'parametres/terminaux/{id}/code',        'FKC_TerminalController@code', $padm );
$router->post( 'parametres/terminaux/{id}/code-auto',   'FKC_TerminalController@codeAuto', $padm );
$router->post( 'parametres/terminaux/{id}/appareils',    'FKC_TerminalController@appareils', $padm );
$router->post( 'parametres/terminaux/appareil/{id}/revoquer', 'FKC_TerminalController@revoquerAppareil', $padm );
$router->post( 'parametres/terminaux/{id}/revoquer',    'FKC_TerminalController@revoquer', $padm );
$router->post( 'parametres/terminaux/{id}/reactiver',   'FKC_TerminalController@reactiver', $padm );
$router->post( 'parametres/terminaux/{id}/reglages',    'FKC_TerminalController@reglages', $padm );   // 1.806.0
$router->get(  'parametres/terminaux/promos',           'FKC_TerminalController@promos', $padm );     // 1.814.0 : contenus promotionnels
$router->get(  'parametres/terminaux/apparence',        'FKC_TerminalController@apparence', $padm );  // 1.815.0 : apparence des bornes
$router->get(  'parametres/terminaux/{id}/configurer',  'FKC_TerminalController@configurer', $padm ); // 1.820.0 : horaires, rubriques
$router->post( 'parametres/terminaux/{id}/configurer',  'FKC_TerminalController@configurerAction', $padm );
$router->get(  'parametres/terminaux/{id}/apercu',      'FKC_TerminalController@apercu', $padm );     // 1.820.0 : aperçu sans jeton
$router->post( 'parametres/terminaux/apparence',        'FKC_TerminalController@apparenceAction', $padm );
$router->get(  'parametres/terminaux/apparence/fond',   'FKC_TerminalController@apparenceFond', $padm );
$router->post( 'parametres/terminaux/promos',           'FKC_TerminalController@promosAction', $padm );
$router->get(  'parametres/terminaux/promos/{id}/image', 'FKC_TerminalController@promoImage', $padm );
/* Supervision BPE : la file différée a enfin un consommateur (1.620.0). */
$router->post( 'parametres/processus/traiter',           'FKC_ParamAdminController@bpeTraiter', $padm );
$router->post( 'parametres/processus/recuperer',         'FKC_ParamAdminController@bpeRecuperer', $padm );
$router->post( 'parametres/processus/{id}/rejouer',      'FKC_ParamAdminController@bpeRejouer', $padm );
$router->get( 'parametres/integrations',           'FKC_ParamAdminController@integrations', $padm );
$router->get(  'parametres/referentiel',            'FKC_ParamAdminController@referentiel', $padm );
$router->post( 'parametres/referentiel',            'FKC_ParamAdminController@referentielDefinir', $padm );
$router->post( 'parametres/referentiel/oublier',    'FKC_ParamAdminController@referentielOublier', $padm );
$router->post( 'parametres/referentiel/plan',       'FKC_ParamAdminController@referentielPlan', $padm );
$router->post( 'parametres/referentiel/type',       'FKC_ParamAdminController@referentielType', $padm );
$router->get(  'parametres/referentiel/etats',      'FKC_ParamAdminController@referentielEtats', $padm );
$router->get(  'parametres/ecritures-assistees',    'FKC_ParamAdminController@ecrituresAssistees', $padm );
$router->post( 'parametres/ecritures-assistees/generer', 'FKC_ParamAdminController@ecritureAssisteeGenerer', $padm );
$router->get( 'parametres/maintenance',            'FKC_ParamAdminController@maintenance', $padm );
$router->post( 'parametres/maintenance/ecritures', 'FKC_ParamAdminController@purgeEcritures', $padm );
$router->post( 'parametres/maintenance/factures',  'FKC_ParamAdminController@purgeFactures', $padm );
$router->post( 'parametres/maintenance/achats',    'FKC_ParamAdminController@purgeAchats', $padm );
$router->post( 'parametres/maintenance/reset-exercice', 'FKC_ParamAdminController@resetExercice', $padm );
$router->post( 'parametres/maintenance/purge-tout','FKC_ParamAdminController@purgeTout', $padm );
$router->post( 'parametres/maintenance/coherence', 'FKC_ParamAdminController@coherenceEcritures', $padm );
// Numérotation des écritures : reprise de l'existant et recalage des compteurs.
$router->post( 'parametres/maintenance/numeroter', 'FKC_ParamAdminController@numeroterEcritures', $padm );
$router->post( 'parametres/maintenance/recaler-sequences', 'FKC_ParamAdminController@recalerSequences', $padm );
$router->post( 'parametres/maintenance/ecriture/rechercher',   'FKC_ParamAdminController@ecritureRechercher', $padm );
$router->post( 'parametres/maintenance/ecriture/contrepasser', 'FKC_ParamAdminController@ecritureContrepasser', $padm );
// Revenir sur une contre-passation passée par erreur (voir EcritureAnnulation::annulerContrepassation).
$router->post( 'parametres/maintenance/ecriture/annuler-contrepassation', 'FKC_ParamAdminController@ecritureAnnulerContrepassation', $padm );
$router->post( 'parametres/maintenance/ecriture/supprimer',    'FKC_ParamAdminController@ecritureSupprimer', $padm );
// Établissements de l'entité active.
$router->get(  'parametres/etablissements',                'FKC_ParametresController@etablissements', $padm );
$router->post( 'parametres/etablissements',                'FKC_ParametresController@etablissementStore', $padm );
$router->post( 'parametres/etablissements/{id}/supprimer', 'FKC_ParametresController@etablissementDelete', $padm );

// Utilisateurs (réservé à l'administrateur).
$admin = array( 'auth' => true, 'admin' => true );
$router->get( 'utilisateurs',                 'FKC_UserController@index', $admin );
$router->get( 'utilisateurs/nouveau',         'FKC_UserController@create', $admin );
$router->post( 'utilisateurs',                'FKC_UserController@store', $admin );
$router->get( 'utilisateurs/{id}/modifier',   'FKC_UserController@edit', $admin );
$router->post( 'utilisateurs/{id}',           'FKC_UserController@update', $admin );

// Sécurité (réservé à l'administrateur).
$router->get(  'securite',        'FKC_SecuriteController@index', $admin );
$router->post( 'securite/purge',  'FKC_SecuriteController@purge', $admin );
$router->get(  'securite/cles-api',                 'FKC_SecuriteController@clesApi', $admin );
$router->post( 'securite/cles-api/creer',           'FKC_SecuriteController@cleApiCreate', array( 'auth' => true, 'admin' => true, 'cap' => 'api_keys' ) );
$router->post( 'securite/cles-api/{id}/revoquer',   'FKC_SecuriteController@cleApiRevoke', $admin );
$router->post( 'securite/cles-api/{id}/verifier',   'FKC_SecuriteController@cleApiVerifier', $admin );
$router->post( 'securite/cles-api/{id}/supprimer',  'FKC_SecuriteController@cleApiDelete', $admin );

// Rôles (Rôle Engine) — réservé à l'administrateur, dans une société active :
// user_roles vit dans la base société (périmètre = agence/entrepôt/caisse…).
$radmin = array( 'auth' => true, 'admin' => true, 'societe' => true );
$router->get(  'securite/roles',               'FKC_RolesController@index', $radmin );
$router->post( 'securite/roles/attribuer',     'FKC_RolesController@attribuer', $radmin );
$router->post( 'securite/roles/{id}/retirer',  'FKC_RolesController@retirer', $radmin );
$router->get(  'securite/roles/perimetre/{type}', 'FKC_RolesController@perimetreOptions', $radmin );

// Aide & documentation (tous les utilisateurs authentifiés).
$aide = array( 'auth' => true, 'societe' => true );
$router->get( 'aide',                  'FKC_AideController@index', $aide );
$router->get( 'aide/tutoriels',        'FKC_AideController@tutoriels', $aide );
$router->get( 'aide/videos',           'FKC_AideController@videos', $aide );
$router->get( 'aide/guide',            'FKC_AideController@guide', $aide );
/* Packs métier : sommaire par famille, puis fiche pratique générée du manifeste. */
$router->get( 'aide/packs',            'FKC_AideController@packs', $aide );
$router->get( 'aide/pack/{code}',      'FKC_AideController@pack', $aide );
$router->get( 'aide/glossaire',        'FKC_AideController@glossaire', $aide );
$router->get( 'aide/depannage',        'FKC_AideController@depannage', $aide );
$router->get( 'aide/categorie/{cat}',  'FKC_AideController@categorie', $aide );
$router->get( 'aide/article/{id}',     'FKC_AideController@article', $aide );
$router->post( 'utilisateurs/{id}/supprimer', 'FKC_UserController@delete', $admin );

// Paie (éditions Enterprise & Creative).
$paie = array( 'auth' => true, 'societe' => true, 'module' => 'paie' );
$router->get( 'paie',                       'FKC_BulletinController@index', $paie );
$router->get( 'paie/od',                     'FKC_OdPaieController@index', $paie );
$router->post( 'paie/od',                    'FKC_OdPaieController@comptabiliser', $paie );
$router->post( 'paie/od/reglages',           'FKC_OdPaieController@reglages', $paie );
$router->get( 'paie/paiements',              'FKC_PaiePaiementController@index', $paie );
$router->post( 'paie/paiements',             'FKC_PaiePaiementController@payer', $paie );
$router->get( 'paie/paiements/virement',     'FKC_PaiePaiementController@virement', $paie );
$router->get( 'paie/paiements/csv',          'FKC_PaiePaiementController@csv', $paie );
$router->get( 'paie/annuel',                 'FKC_PaieAnnuelController@index', $paie );
$router->post( 'paie/annuel/budget',         'FKC_PaieAnnuelController@budget', $paie );
$router->get( 'paie/annuel/csv',             'FKC_PaieAnnuelController@csv', $paie );
$router->get( 'paie/annuel/imprimer',        'FKC_PaieAnnuelController@imprimer', $paie );
$router->get( 'paie/declarations',           'FKC_PaieDeclarationController@index', $paie );
$router->get( 'paie/declarations/imprimer','FKC_PaieDeclarationController@imprimer', $paie );
$router->get( 'paie/declarations/csv',     'FKC_PaieDeclarationController@csv', $paie );
$router->get( 'paie/declarations/nominatif','FKC_PaieDeclarationController@nominatif', $paie );
// Notifications.
$notif = array( 'auth' => true, 'societe' => true );
$router->get(  'notifications',                    'FKC_NotificationController@index', $notif );
$router->post( 'notifications/lu-tout',            'FKC_NotificationController@markAllRead', $notif );
$router->get(  'notifications/{id}/ouvrir',        'FKC_NotificationController@open', $notif );
$router->post( 'notifications/{id}/lu',            'FKC_NotificationController@markRead', $notif );
$router->post( 'notifications/{id}/supprimer',     'FKC_NotificationController@delete', $notif );
/* ── Espace des invités externes ────────────────────────────────────────
   ROUTES PUBLIQUES, ET C'EST DÉLIBÉRÉ : un invité n'a pas de compte, donc
   pas de session d'utilisateur, donc rien à quoi le middleware `auth`
   pourrait s'appliquer. La société est branchée depuis le jeton, et c'est
   FKC_ConnectExterneController — qui n'appelle JAMAIS FKC_Auth — qui décide
   seul de ce que l'invité voit.

   Volontairement déclarées AVANT le bloc authentifié : un lecteur qui se
   demande « par où entre un externe ? » doit tomber dessus tout de suite. */
$router->get(  'connect/invitation/{jeton}',              'FKC_ConnectExterneController@accueil' );
$router->post( 'connect/invitation/{jeton}',              'FKC_ConnectExterneController@entrer' );
$router->get(  'connect/externe',                         'FKC_ConnectExterneController@espace' );
$router->post( 'connect/externe/message',                 'FKC_ConnectExterneController@ecrire' );
$router->post( 'connect/externe/sortie',                  'FKC_ConnectExterneController@sortir' );

/* ── FinaKop Connect (service optionnel) ────────────────────────────────
   Le middleware 'module' => 'connect' fait le premier refus : sans le
   service dans la charge signée, aucune de ces routes n'est servie. Le
   contrôleur revérifie malgré tout (FKC_Connect_Droits::serviceActif), parce
   qu'une route atteinte par un autre chemin — une redirection interne, un
   test — ne doit pas court-circuiter la licence.

   Les POST portent tous le jeton anti-CSRF, posé par le routeur. */
$cx = array( 'auth' => true, 'societe' => true, 'module' => 'connect' );
$router->get(  'connect',                                  'FKC_ConnectController@index', $cx );
$router->get(  'connect/conversation/{id}',                'FKC_ConnectController@conversation', $cx );
$router->post( 'connect/conversations',                    'FKC_ConnectController@creer', $cx );
$router->post( 'connect/conversation/{id}/message',        'FKC_ConnectController@envoyer', $cx );
$router->post( 'connect/conversation/{id}/action',         'FKC_ConnectController@action', $cx );
$router->post( 'connect/conversation/{id}/membres',        'FKC_ConnectController@membres', $cx );
$router->post( 'connect/message/{id}/reaction',            'FKC_ConnectController@reaction', $cx );
$router->post( 'connect/message/{id}/modifier',            'FKC_ConnectController@modifier', $cx );
$router->post( 'connect/message/{id}/supprimer',           'FKC_ConnectController@supprimer', $cx );
$router->post( 'connect/message/{id}/epingler',            'FKC_ConnectController@epingler', $cx );
$router->post( 'connect/conversation/{id}/piece',          'FKC_ConnectController@piece', $cx );
/* Notifications poussées : l'abonnement d'un appareil, et le compteur que le
   service worker vient chercher après avoir été réveillé — c'est LUI qui
   décide du texte affiché, avec les droits de l'utilisateur, et non la
   poussée qui l'aurait transporté. */
$router->post( 'connect/message/{id}/partager',            'FKC_ConnectController@partagerMaestro', $cx );
$router->post( 'connect/conversation/{id}/inviter',        'FKC_ConnectController@inviterExterne', $cx );
$router->post( 'connect/invite/{id}/revoquer',            'FKC_ConnectController@revoquerExterne', $cx );
$router->post( 'connect/push/abonner',                    'FKC_ConnectController@pushAbonner', $cx );
$router->post( 'connect/push/desabonner',                 'FKC_ConnectController@pushDesabonner', $cx );
$router->get(  'connect/badge',                           'FKC_ConnectController@badge', $cx );
$router->post( 'connect/piece/{id}/supprimer',            'FKC_ConnectController@pieceSupprimer', $cx );
/* Téléchargement d'une pièce : GET, mais le droit est relu à chaque appel —
   un lien collé dans un courriel ne donne rien à qui n'a pas le fil. */
$router->get(  'connect/piece/{id}',                      'FKC_ConnectController@pieceServir', $cx );
$router->get(  'connect/objet/{type}/{id}',                'FKC_ConnectController@objet', $cx );
$router->get(  'connect/flux/{id}',                        'FKC_ConnectController@flux', $cx );
/* Temps réel : le jeton de connexion au relais, et le signal de saisie.
   Le jeton est court (15 min) et énumère les conversations autorisées :
   le relais n'a aucune décision à prendre. */
$router->get(  'connect/relais/jeton',                    'FKC_ConnectController@relaisJeton', $cx );
/* Appels. La SIGNALISATION passe par FinaKop et non par le relais : le
   relais ne connaît aucun droit, et lui confier qui peut appeler qui
   reviendrait à lui donner le pouvoir qu'on lui refuse depuis le début.
   Le flux média, lui, ne touche jamais le serveur. */
$router->post( 'connect/conversation/{id}/appel',         'FKC_ConnectController@appelDemarrer', $cx );
$router->post( 'connect/appel/{id}/rejoindre',            'FKC_ConnectController@appelRejoindre', $cx );
$router->post( 'connect/appel/{id}/quitter',              'FKC_ConnectController@appelQuitter', $cx );
$router->post( 'connect/appel/{id}/signal',               'FKC_ConnectController@appelSignal', $cx );
$router->get(  'connect/appel/ice',                       'FKC_ConnectController@appelIce', $cx );
$router->post( 'connect/appel/ice',                       'FKC_ConnectController@appelIceConfig', array( 'auth' => true, 'societe' => true, 'module' => 'connect', 'admin' => true ) );
$router->post( 'connect/conversation/{id}/frappe',        'FKC_ConnectController@frappe', $cx );
$router->post( 'connect/relais',                          'FKC_ConnectController@relaisConfig', array( 'auth' => true, 'societe' => true, 'module' => 'connect', 'admin' => true ) );
$router->get(  'connect/recherche',                        'FKC_ConnectController@recherche', $cx );
$router->get(  'connect/audit',                            'FKC_ConnectController@audit', array( 'auth' => true, 'societe' => true, 'module' => 'connect' ) );
$router->get(  'connect/audit/export',                    'FKC_ConnectController@auditExport', array( 'auth' => true, 'societe' => true, 'module' => 'connect' ) );
$router->get(  'connect/administration',                   'FKC_ConnectController@administration', array( 'auth' => true, 'societe' => true, 'module' => 'connect', 'admin' => true ) );
$router->post( 'connect/administration',                   'FKC_ConnectController@administrationSave', array( 'auth' => true, 'societe' => true, 'module' => 'connect', 'admin' => true ) );

// Workflow de validation (administrateurs).
$router->get( 'validations',        'FKC_ValidationController@index', $padm );
$router->get( 'validations/{id}',   'FKC_ValidationController@show', $padm );
$router->post( 'validations/{id}/decider', 'FKC_ValidationController@decide', $padm );
$router->post( 'validations/parametres', 'FKC_ValidationController@settings', $padm );
// GED — Bibliothèque de documents.
$ged = array( 'auth' => true, 'societe' => true );
$router->get(  'ged',                       'FKC_GedController@index', $ged );
$router->post( 'ged/televerser',            'FKC_GedController@upload', $ged );
$router->get(  'ged/{id}/telecharger',      'FKC_GedController@download', $ged );
$router->post( 'ged/promouvoir-piece/{id}','FKC_GedController@promotePiece', $ged );
$router->post( 'ged/{id}/supprimer',        'FKC_GedController@delete', $ged );
// Centre d'Encaissement (sous-module Comptabilité Client).
$router->get(  'encaissement',                  'FKC_RecuController@index', $cClient );
$router->get(  'encaissement/nouveau',          'FKC_RecuController@create', $cClient );
$router->post( 'encaissement',                  'FKC_RecuController@store', $cClient );
$router->get(  'encaissement/journal',          'FKC_RecuController@journal', $cClient );
$router->get(  'encaissement/journal/imprimer', 'FKC_RecuController@journalImprimer', $cClient );
$router->get(  'encaissement/{id}',             'FKC_RecuController@show', $cClient );
$router->get(  'encaissement/{id}/modifier',    'FKC_RecuController@edit', $cClient );
$router->post( 'encaissement/{id}',             'FKC_RecuController@update', $cClient );
$router->post( 'encaissement/{id}/soumettre',   'FKC_RecuController@soumettre', $cClient );
$router->post( 'encaissement/{id}/valider',     'FKC_RecuController@valider', $cClient );
$router->post( 'encaissement/{id}/comptabiliser','FKC_RecuController@comptabiliser', $cClient );
$router->post( 'encaissement/{id}/transformer','FKC_RecuController@transformer', $cClient );
$router->get(  'encaissement/{id}/imprimer','FKC_RecuController@imprimer', $cClient );
$router->get(  'encaissement/{id}/ticket',  'FKC_RecuController@ticket', $cClient );
$router->post( 'encaissement/{id}/rapprocher','FKC_RecuController@rapprocher', $cClient );
$router->post( 'encaissement/{id}/imputer',  'FKC_RecuController@imputer', $cClient );
$router->post( 'encaissement/{id}/rembourser','FKC_RecuController@rembourser', $cClient );
$router->post( 'encaissement/{id}/justificatif','FKC_RecuController@justificatif', $cClient );
$router->post( 'encaissement/{id}/renvoyer',    'FKC_RecuController@renvoyer', $cClient );
$router->post( 'encaissement/{id}/annuler',     'FKC_RecuController@annuler', $cClient );
$router->post( 'encaissement/{id}/supprimer',   'FKC_RecuController@delete', $cClient );

/*
 * REÇU NUMÉRIQUE PUBLIC (1.638.0) — cible du QR imprimé.
 *
 * Ni `auth`, ni `societe` : c'est le CLIENT qui scanne, depuis son
 * téléphone, sans compte. La société est portée par l'URL (chaque dossier
 * a sa base) et le contrôleur la branche lui-même. Le jeton du QR est la
 * seule clé : le numéro seul, devinable, n'ouvre rien.
 *
 * `verifier/...` EST CONSERVÉE et sert la même page. Les reçus imprimés
 * entre 1.634.0 et 1.637.0 portent un QR qui vise cette adresse, et un
 * papier déjà remis à un client ne se rappelle pas. Une route d'alias coûte
 * une ligne ; un QR mort coûte la confiance qu'il devait construire.
 */
$router->get( 'recu/{soc}/{numero}/{jeton}',     'FKC_RecuController@recuPublic', array( 'public' => true ) );
$router->get( 'verifier/{soc}/{numero}/{jeton}', 'FKC_RecuController@recuPublic', array( 'public' => true ) );
/* Portail parents / étudiants (1.863.0) : lecture seule, le jeton de l'élève est la clé. */
require_once FKC_ROOT . 'Packs/ecole/Controllers/EcolePortailController.php';
$router->get( 'portail-scolarite/{soc}/{jeton}', 'FKC_EcolePortailController@portail', array( 'public' => true ) );
$router->get( 'paie/absences',               'FKC_AbsenceController@index', $paie );
$router->post( 'paie/absences',              'FKC_AbsenceController@store', $paie );
$router->post( 'paie/absences/{id}/supprimer','FKC_AbsenceController@delete', $paie );
$router->get( 'paie/bulletins',             'FKC_BulletinController@index', $paie );
$router->get( 'paie/bulletins/nouveau',     'FKC_BulletinController@create', $paie );
$router->post( 'paie/bulletins',            'FKC_BulletinController@store', $paie );
$router->post( 'paie/bulletins/masse',      'FKC_BulletinController@masse', $paie );
$router->post( 'paie/bulletins/{id}/supprimer','FKC_BulletinController@delete', $paie );
$router->get( 'paie/bulletins/{id}/imprimer','FKC_BulletinController@imprimer', $paie );
$router->get( 'paie/bulletins/{id}',        'FKC_BulletinController@show', $paie );
$router->get( 'paie/employes',              'FKC_EmployeController@index', $paie );
$router->get( 'paie/employes/nouveau',      'FKC_EmployeController@create', $paie );
$router->post( 'paie/employes',             'FKC_EmployeController@store', $paie );
$router->get( 'paie/employes/{id}/modifier','FKC_EmployeController@edit', $paie );
$router->post( 'paie/employes/{id}',        'FKC_EmployeController@update', $paie );
$router->get( 'paie/parametres',            'FKC_PaieParamController@index', $paie );
$router->post( 'paie/parametres',           'FKC_PaieParamController@save', $paie );

/* ── Module RH / SIRH ─────────────────────────────────────────────────────── */
$rh = array( 'auth' => true, 'societe' => true, 'module' => 'rh' );
$router->get(  'rh',                'FKC_RhController@index', $rh );
$router->get(  'rh/employes',       'FKC_RhController@employes', $rh );
$router->get(  'rh/organisation',   'FKC_RhController@organisation', $rh );
$router->post( 'rh/organisation',   'FKC_RhController@deptStore', $rh );
$router->post( 'rh/organisation/{id}/supprimer', 'FKC_RhController@deptDelete', $rh );
$router->get(  'rh/conges',         'FKC_RhController@conges', $rh );
$router->post( 'rh/conges',         'FKC_RhController@congeStore', $rh );
$router->post( 'rh/conges/{id}/action', 'FKC_RhController@congeAction', $rh );
$router->get(  'rh/avances',        'FKC_RhController@avances', $rh );
$router->post( 'rh/avances',        'FKC_RhController@avanceStore', $rh );
$router->post( 'rh/avances/{id}/action', 'FKC_RhController@avanceAction', $rh );
$router->get(  'rh/missions',       'FKC_RhController@missions', $rh );
$router->post( 'rh/missions',       'FKC_RhController@missionStore', $rh );
$router->post( 'rh/missions/{id}/action', 'FKC_RhController@missionAction', $rh );
$router->get(  'rh/recrutement',    'FKC_RhController@recrutement', $rh );
$router->post( 'rh/recrutement',    'FKC_RhController@recrutementStore', $rh );
$router->post( 'rh/recrutement/{id}/action', 'FKC_RhController@recrutementAction', $rh );
$router->post( 'rh/recrutement/{id}/candidatures', 'FKC_RhController@candidatureStore', $rh );
$router->post( 'rh/recrutement/{id}/candidatures/{candId}', 'FKC_RhController@candidatureAction', $rh );
$router->get(  'rh/pointage',       'FKC_RhController@pointage', $rh );
$router->post( 'rh/pointage',       'FKC_RhController@pointageStore', $rh );
$router->post( 'rh/pointage/{id}/supprimer', 'FKC_RhController@pointageDelete', $rh );
$router->get(  'rh/portail',        'FKC_RhController@portail', $rh );
$router->get(  'rh/reporting',      'FKC_RhController@reporting', $rh );
$router->get(  'rh/employes/{id}',  'FKC_RhController@fiche', $rh );
$router->post( 'rh/employes/{id}',  'FKC_RhController@ficheSave', $rh );
$router->post( 'rh/employes/{id}/documents', 'FKC_RhController@documentStore', $rh );
$router->post( 'rh/employes/{id}/documents/{docId}/supprimer', 'FKC_RhController@documentDelete', $rh );
$router->post( 'rh/employes/{id}/historique', 'FKC_RhController@historiqueStore', $rh );
$router->post( 'rh/employes/{id}/historique/{histId}/supprimer', 'FKC_RhController@historiqueDelete', $rh );

// ── Module Scan & Identification (transversal) ──
$scan = array( 'auth' => true, 'societe' => true, 'module' => 'scan' );
$router->get(  'scan',                    'FKC_ScanController@index', $scan );
$router->get(  'scan/workspace',          'FKC_ScanController@workspace', $scan );           // 1.828.0 — Scan Workspace
$router->post( 'scan/workspace/scan',     'FKC_ScanController@workspaceScan', $scan );
$router->post( 'scan/workspace/processus','FKC_ScanController@workspaceProcessus', $scan );
/*
 * 1.811.0 — scan/chercher et scan/resolve en POST SEULEMENT. Leur variante GET
 * échappait au contrôle CSRF alors qu'un scan est un geste qui AGIT (actions
 * des abonnés : ticket, fidélité, traçabilité) et qui écrit au journal : un
 * simple lien ou une image piégée pouvait le déclencher au nom de l'utilisateur.
 */
$router->post( 'scan/chercher',           'FKC_ScanController@chercher', $scan );
$router->post( 'scan/resolve',            'FKC_ScanController@resolve', $scan );
$router->get(  'scan/apercu',             'FKC_ScanController@apercu', $scan );   // 1.817.0 — sans effet : lecture seule
$router->post( 'scan/lot',                'FKC_ScanController@lot', $scan );      // 1.817.0 — créer/retrouver le lot lu dans un code GS1
$router->get(  'scan/assistant',          'FKC_ScanController@assistant', $scan );         // 1.827.0 — lecture seule
$router->get(  'scan/etiquette-article',  'FKC_ScanController@etiquetteArticle', $scan );  // 1.827.0 — étiquette métier imprimable
$router->get(  'scan/generateur',         'FKC_ScanController@generateur', $scan );
$router->get(  'scan/image',              'FKC_ScanController@image', $scan );
$router->get(  'scan/generer-interne',    'FKC_ScanController@genererInterne', $scan );
$router->get(  'scan/verifier',            'FKC_ScanController@verifier', $scan );
$router->get(  'scan/etiquettes',         'FKC_ScanController@etiquettes', $scan );
$router->get(  'scan/planche',            'FKC_ScanController@planche', $scan );
$router->post( 'scan/planche',            'FKC_ScanController@planche', $scan );
$router->post( 'scan/affecter',           'FKC_ScanController@affecter', $scan );
$router->get(  'scan/processus',          'FKC_ScanController@processus', $scan );
$router->post( 'scan/processus',          'FKC_ScanController@processusAction', $scan );
$router->get(  'scan/journal',            'FKC_ScanController@journal', $scan );
// ── FinaKop Smart Scan (temps réel multi-appareils) ──
$router->get(  'scan/smart',              'FKC_ScanController@smart', $scan );
$router->get(  'scan/smart/pull',         'FKC_ScanController@smartPull', $scan );
$router->get(  'scan/smart/stream',       'FKC_ScanController@smartStream', $scan );
$router->post( 'scan/smart/appliquer',    'FKC_ScanController@smartAppliquer', $scan );
$router->get(  'scan/smart/agregat',      'FKC_ScanController@smartAgregat', $scan );
$router->get(  'scan/smart/ecarts',       'FKC_ScanController@smartEcarts', $scan );   // 1.818.0
$router->post( 'scan/smart/valider',      'FKC_ScanController@smartValider', $scan );  // 1.818.0
$router->get(  'scan/smart/trace',        'FKC_ScanController@smartTrace', $scan );    // 1.818.0
$router->post( 'scan/smart/fermer',       'FKC_ScanController@smartFermer', $scan );
$router->post( 'scan/smart/device',       'FKC_ScanController@smartDevice', $scan );
$scanMobile = array( 'public' => true ); // authentifié par le jeton d'appairage, pas par login
$router->get(  'scan/mobile',             'FKC_ScanController@mobile', $scanMobile );
$router->post( 'scan/mobile/join',        'FKC_ScanController@mobileJoin', $scanMobile );
$router->post( 'scan/mobile/push',        'FKC_ScanController@mobilePush', $scanMobile );
$router->get(  'scan/mobile/articles',    'FKC_ScanController@mobileArticles', $scanMobile );
$router->post( 'scan/mobile/affecter',    'FKC_ScanController@mobileAffecter', $scanMobile );
$router->post( 'scan/mobile/creer',       'FKC_ScanController@mobileCreerArticle', $scanMobile );

/*
 * ── TERMINAL CLIENT — ROUTES PUBLIQUES (1.724.0) ──────────────────────────
 *
 * Aucune session, aucun rôle : l'appareil s'authentifie par le jeton reçu à
 * l'appairage, qui porte le numéro de l'établissement. Même procédé que le
 * Scan mobile ci-dessus, et pour la même raison — une tablette de comptoir
 * n'a pas de compte utilisateur, et ne doit surtout pas en avoir un.
 *
 * Ce qui protège ces routes n'est pas leur discrétion : c'est que le jeton
 * n'ouvre QUE les capacités du type d'appareil (FKC_Terminal::peut), et
 * qu'aucune d'elles n'écrit en comptabilité.
 */
$fkcTerm = array( 'public' => true );
$router->get(  'terminal',                'FKC_TerminalClientController@index', $fkcTerm );
$router->post( 'terminal/appairer',       'FKC_TerminalClientController@appairer', $fkcTerm );
$router->get(  'terminal/catalogue',      'FKC_TerminalClientController@catalogue', $fkcTerm );
$router->post( 'terminal/commander',      'FKC_TerminalClientController@commander', $fkcTerm );
$router->get(  'terminal/suivi/{ref}',    'FKC_TerminalClientController@statut', $fkcTerm );
$router->get(  'terminal/file',           'FKC_TerminalClientController@file', $fkcTerm );
$router->post( 'terminal/avancer',        'FKC_TerminalClientController@avancer', $fkcTerm );
$router->get(  'suivre/{code}/logo',      'FKC_TerminalClientController@suivreLogo', $fkcTerm ); // 1.811.0 : logo de la société
$router->get(  'terminal/tableau',        'FKC_TerminalClientController@tableau', $fkcTerm );
$router->get(  'terminal/ping',           'FKC_TerminalClientController@ping', $fkcTerm );     // 1.806.0 : état réseau + révocation
$router->get(  'terminal/logo',           'FKC_TerminalClientController@logo', $fkcTerm );     // 1.806.0 : marque de l'établissement
$router->get(  'terminal/image/{id}',     'FKC_TerminalClientController@image', $fkcTerm );    // 1.807.0 : photo d'une prestation
$router->get(  'terminal/lieux',          'FKC_TerminalClientController@lieux', $fkcTerm );    // 1.808.0 : tables libres (restaurant)
$router->get(  'terminal/promo/{id}/image', 'FKC_TerminalClientController@promoImage', $fkcTerm ); // 1.814.0 : photo d'un contenu promotionnel
$router->get(  'terminal/fond',           'FKC_TerminalClientController@fond', $fkcTerm );
$router->get(  'terminal/afficheur',      'FKC_TerminalClientController@afficheur', $fkcTerm ); // 1.840.0 : écran client de caisse appairé
$router->get(  'terminal/prix',           'FKC_TerminalClientController@prix', $fkcTerm );      // 1.840.0 : vérificateur de prix     // 1.815.0 : fond de l'écran d'attente
/* Suivi public (1.807.0) : ni session ni jeton — le lien SIGNÉ du QR est la clé. */
$router->get(  'suivre/{code}',           'FKC_TerminalClientController@suivre', $fkcTerm );

// ── Module Caisse (postes, sessions, opérations) ──
$cx = array( 'auth' => true, 'societe' => true, 'module' => 'caisse' );
$router->get(  'caisse',                  'FKC_CaisseController@index', $cx );
$router->post( 'caisse/ouvrir',           'FKC_CaisseController@ouvrir', $cx );
$router->post( 'caisse/operation',        'FKC_CaisseController@operation', $cx );
$router->get(  'caisse/cloture',          'FKC_CaisseController@cloture', $cx );
$router->post( 'caisse/cloturer',         'FKC_CaisseController@cloturer', $cx );
$router->post( 'caisse/pourboires',       'FKC_CaisseController@pourboires', $cx ); // 1.830.0 : reversement au personnel
$router->get(  'caisse/postes/{id}/profil', 'FKC_CaisseController@posteProfil', $cx );     // 1.840.0 : profil par poste
$router->post( 'caisse/postes/{id}/profil', 'FKC_CaisseController@posteProfilSave', $cx ); // 1.840.0
$router->post( 'caisse/pos/momo/initier',  'FKC_PosController@momoInitier', $cx );   // 1.843.0 : Mobile Money par demande
$router->post( 'caisse/pos/momo/verifier', 'FKC_PosController@momoVerifier', $cx );
$router->post( 'caisse/pos/momo/confirmer','FKC_PosController@momoConfirmer', $cx );
$router->get(  'caisse/pos/qz/certificat', 'FKC_PosController@qzCertificat', $cx );  // 1.843.0 : QZ Tray
$router->post( 'caisse/pos/qz/signer',     'FKC_PosController@qzSigner', $cx );
$router->get(  'caisse/encaissement-mobile', 'FKC_CaisseController@encaissementMobile', $cx );
$router->post( 'caisse/encaissement-mobile', 'FKC_CaisseController@encaissementMobileSave', $cx );
$router->get(  'caisse/consignes',         'FKC_CaisseController@consignes', $cx );        // 1.842.0
$router->post( 'caisse/consignes',         'FKC_CaisseController@consignesSave', $cx );
$router->post( 'caisse/consignes/rendre',  'FKC_CaisseController@consignesRendre', $cx );
$router->get(  'caisse/mobile',            'FKC_PosController@mobile', $cx );         // 1.842.0 : prise de commande du serveur
$router->get(  'caisse/mobile/salle',      'FKC_PosController@mobileSalle', $cx );
$router->get(  'caisse/mobile/table/{id}', 'FKC_PosController@mobileTable', $cx );
$router->get(  'caisse/mobile/articles',   'FKC_PosController@mobileArticles', $cx );
$router->post( 'caisse/mobile/ajouter',    'FKC_PosController@mobileAjouter', $cx );
$router->post( 'caisse/mobile/retirer',    'FKC_PosController@mobileRetirer', $cx );
$router->post( 'caisse/mobile/envoyer',    'FKC_PosController@mobileEnvoyer', $cx );
$router->get(  'caisse/code',             'FKC_CaisseController@code', $cx );     // 1.835.0 : code superviseur
$router->post( 'caisse/code',             'FKC_CaisseController@codeSave', $cx ); // 1.835.0
$router->get(  'caisse/postes',           'FKC_CaisseController@postes', $cx );
$router->post( 'caisse/postes',           'FKC_CaisseController@posteStore', $cx );
$router->get(  'caisse/journal',          'FKC_CaisseController@journal', $cx );
$router->get(  'caisse/reprise',          'FKC_CaisseController@reprise', $cx );
$router->post( 'caisse/reprise',          'FKC_CaisseController@repriseExecuter', $cx );
$router->post( 'caisse/reprise/converger', 'FKC_CaisseController@repriseConverger', $cx ); // 1.841.0
$router->post( 'caisse/projection',       'FKC_CaisseController@projection', $cx );
$router->get(  'caisse/sessions/{id}',    'FKC_CaisseController@session', $cx );

/*
 * Actions à effet de bord : POST obligatoire.
 *
 * Ces cinq routes modifiaient des données en GET. Le contrôle anti-CSRF du
 * routeur ne s'applique qu'aux requêtes POST, et le cookie de session est en
 * SameSite=Lax — qui laisse passer une navigation de premier niveau. Un simple
 * lien piégé suffisait donc à supprimer une promotion ou à annuler une
 * commande au nom d'un caissier connecté. Les vues correspondantes ont été
 * converties en formulaires portant FKC_Csrf::field().
 */
// ── Terminal de vente (POS) ──
$router->get(  'caisse/pos',              'FKC_PosController@terminal', $cx );
$router->post( 'caisse/pos/ajouter',      'FKC_PosController@ajouter', $cx );
$router->post( 'caisse/pos/ligne',        'FKC_PosController@majLigne', $cx );
$router->post( 'caisse/pos/ligne/supprimer', 'FKC_PosController@supprimerLigne', $cx );
$router->post( 'caisse/pos/remise',       'FKC_PosController@remise', $cx );
$router->post( 'caisse/pos/ticket',       'FKC_PosController@majTicket', $cx );
$router->post( 'caisse/pos/encaisser',    'FKC_PosController@encaisser', $cx );
// LOT 2.5 — report d'une addition sur la note d'une chambre d'hôtel.
$router->post( 'caisse/pos/chambre',      'FKC_PosController@chambre', $cx );
$router->get(  'caisse/pos/chambres',     'FKC_PosController@chambres', $cx );
// Vérification d'un avoir présenté au comptoir (1.520.92).
$router->post( 'caisse/pos/avoir',        'FKC_PosController@avoir', $cx );
// Addition partagée (LOT 4) : une part à la fois, l'addition reste ouverte.
$router->post( 'caisse/pos/part',         'FKC_PosController@part', $cx );
$router->post( 'caisse/pos/part/calcul',  'FKC_PosController@calculerPart', $cx );
// Contrôle & pilotage (1.520.110) : restitution des registres d'audit.
$router->get(  'caisse/controle',         'FKC_CaisseController@controle', $cx );
$router->post( 'caisse/pos/attendre',     'FKC_PosController@attendre', $cx );
$router->post( 'caisse/pos/reprendre/{id}', 'FKC_PosController@reprendre', $cx );
$router->post( 'caisse/pos/annuler',      'FKC_PosController@annuler', $cx );
$router->get(  'caisse/pos/chercher',     'FKC_PosController@chercher', $cx );
$router->post( 'caisse/pos/cuisine',      'FKC_PosController@cuisine', $cx );
$router->get(  'caisse/ticket/{id}',      'FKC_PosController@ticket', $cx );
$router->get(  'caisse/bon/{id}',         'FKC_PosController@bon', $cx );
$router->get(  'caisse/tickets',          'FKC_PosController@tickets', $cx );
$router->post( 'caisse/retour/{id}',      'FKC_PosController@retour', $cx ); // 1.830.0 : POST (effet de bord)
$router->get(  'caisse/rapport',          'FKC_PosController@rapport', $cx );
$router->get(  'caisse/salle',            'FKC_PosController@salle', $cx );
$router->post( 'caisse/salle',            'FKC_PosController@salleStore', $cx );
$router->post( 'caisse/table/{id}',       'FKC_PosController@table', $cx );
$router->get(  'caisse/configuration',    'FKC_PosController@configuration', $cx );
$router->post( 'caisse/configuration',    'FKC_PosController@configurationSave', $cx );
$router->get(  'caisse/fidelite',         'FKC_PosController@fidelite', $cx );
$router->get(  'caisse/fidelite/client/{id}', 'FKC_PosController@fideliteClient', $cx );
$router->post( 'caisse/fidelite/appliquer',   'FKC_PosController@fideliteAppliquer', $cx );
$router->post( 'caisse/bon/appliquer',        'FKC_PosController@bonAppliquer', $cx );
$router->get(  'caisse/promotions',       'FKC_PosController@promotions', $cx );
$router->post( 'caisse/promotion',        'FKC_PosController@promotionStore', $cx );
$router->post( 'caisse/promotion/{id}/toggle',    'FKC_PosController@promotionToggle', $cx );
$router->post( 'caisse/promotion/{id}/supprimer', 'FKC_PosController@promotionDelete', $cx );
$router->get(  'caisse/fiscal',           'FKC_PosController@fiscal', $cx );
$router->get(  'caisse/variantes',        'FKC_PosController@variantes', $cx );
$router->post( 'caisse/variantes/generer', 'FKC_PosController@variantesGenerer', $cx );
$router->get(  'caisse/commandes',        'FKC_PosController@commandes', $cx );
$router->post( 'caisse/commande',         'FKC_PosController@commandeStore', $cx );
$router->post( 'caisse/commande/{id}/statut',  'FKC_PosController@commandeStatut', $cx );
$router->post( 'caisse/commande/{id}/retirer', 'FKC_PosController@commandeRetirer', $cx );
$router->get(  'caisse/ticket/{id}/escpos', 'FKC_PosController@ticketEscpos', $cx );
$router->post( 'caisse/tiroir',           'FKC_PosController@tiroir', $cx );
$router->get(  'caisse/afficheur',        'FKC_PosController@afficheur', $cx );
$router->get(  'caisse/afficheur/etat',   'FKC_PosController@afficheurEtat', $cx );
$router->get(  'caisse/afficheur/stream', 'FKC_PosController@afficheurStream', $cx );

// ── Module Inventaire ──
$inv = array( 'auth' => true, 'societe' => true, 'module' => 'inventaire' );
$router->get(  'inventaire',                         'FKC_InventaireController@index', $inv );
$router->get(  'inventaire/articles',                'FKC_InventaireController@articles', $inv );
$router->get(  'inventaire/articles/nouveau',        'FKC_InventaireController@articleNew', $inv );
$router->post( 'inventaire/articles',                'FKC_InventaireController@articleSave', $inv );
$router->get(  'inventaire/articles/{id}/modifier',  'FKC_InventaireController@articleEdit', $inv );
$router->post( 'inventaire/articles/{id}/supprimer', 'FKC_InventaireController@articleDelete', $inv );
$router->get(  'inventaire/articles/{id}/codes',     'FKC_InventaireController@articleCodes', $inv );
$router->post( 'inventaire/articles/resync',    'FKC_InventaireController@articlesResync', $inv );
$router->post( 'inventaire/articles/masquage',  'FKC_InventaireController@articlesMasquage', $inv );
$router->post( 'inventaire/articles/{id}/codes/ajouter', 'FKC_InventaireController@articleCodeAdd', $inv );
$router->post( 'inventaire/articles/{id}/codes/retirer', 'FKC_InventaireController@articleCodeDel', $inv );
$router->post( 'inventaire/articles/{id}',           'FKC_InventaireController@articleSave', $inv );
$router->get(  'inventaire/categories',              'FKC_InventaireController@categories', $inv );
$router->post( 'inventaire/categories',              'FKC_InventaireController@categorieStore', $inv );
$router->post( 'inventaire/categories/{id}/supprimer','FKC_InventaireController@categorieDelete', $inv );
$router->get(  'inventaire/entrepots',               'FKC_InventaireController@entrepots', $inv );
$router->post( 'inventaire/entrepots',               'FKC_InventaireController@entrepotStore', $inv );
$router->post( 'inventaire/entrepots/{id}/supprimer','FKC_InventaireController@entrepotDelete', $inv );
$router->post( 'inventaire/emplacements',            'FKC_InventaireController@emplacementStore', $inv );
$router->post( 'inventaire/emplacements/{id}/supprimer','FKC_InventaireController@emplacementDelete', $inv );
$router->get(  'inventaire/stocks',                  'FKC_InventaireController@stocks', $inv );
$router->get(  'inventaire/sorties',                 'FKC_InventaireController@sorties', $inv );
$router->post( 'inventaire/sorties',                 'FKC_InventaireController@sortieStore', $inv );
$router->get(  'inventaire/transferts',              'FKC_InventaireController@transferts', $inv );
$router->post( 'inventaire/transferts',              'FKC_InventaireController@transfertStore', $inv );
$router->get(  'inventaire/inventaires',             'FKC_InventaireController@inventaires', $inv );
$router->post( 'inventaire/inventaires',             'FKC_InventaireController@inventaireStore', $inv );
$router->get(  'inventaire/achats',                  'FKC_InventaireController@achats', $inv );
$router->post( 'inventaire/achats',                  'FKC_InventaireController@achatStore', $inv );
$router->post( 'inventaire/achats/{id}/action',      'FKC_InventaireController@achatAction', $inv );
$router->get(  'inventaire/receptions',              'FKC_InventaireController@receptions', $inv );
$router->post( 'inventaire/receptions',              'FKC_InventaireController@receptionStore', $inv );
$router->get(  'inventaire/fournisseurs',            'FKC_InventaireController@fournisseurs', $inv );
$router->post( 'inventaire/fournisseurs/evaluation', 'FKC_InventaireController@fournisseurEvalStore', $inv );
$router->get(  'inventaire/reappro',                 'FKC_InventaireController@reappro', $inv );
$router->post( 'inventaire/reappro/demander', 'FKC_InventaireController@reapproDemander', $inv );
$router->get(  'inventaire/previsionnel',           'FKC_InventaireController@previsionnel', $inv );
$router->post( 'inventaire/previsionnel/demander',  'FKC_InventaireController@previsionnelDemander', $inv );
$router->get(  'inventaire/valorisation',            'FKC_InventaireController@valorisation', $inv );
$router->get(  'inventaire/reporting',               'FKC_InventaireController@reporting', $inv );

/* ── Pack Gestion Collective des Droits (Lots 1-2 : répertoire, titularité, barèmes) ──
 *
 * Routes conditionnées à l'activation du pack, comme Audiovisuel et Médias :
 * FKC_GcController n'est chargé que par FKC_Packs::boot() quand le pack est
 * actif. Les enregistrer inconditionnellement laisserait des routes pointant
 * une classe absente — un 500 au premier clic, invisible jusque-là.
 */
if ( in_array( 'gestion_collective', FKC_Packs::activeCodes(), true ) ) {
$gc = array( 'auth' => true, 'societe' => true, 'module' => 'gestion_collective' );
$router->get(  'gestion_collective',                        'FKC_GcController@index', $gc );
$router->get(  'gestion_collective/acte/{type}/{id}',        'FKC_GcController@acte', $gc );
$router->get(  'gestion_collective/reprise',                 'FKC_GcController@reprise', $gc );
$router->post( 'gestion_collective/reprise',                 'FKC_GcController@repriseAction', $gc );
$router->get(  'gestion_collective/depots',                  'FKC_GcController@depots', $gc );
$router->post( 'gestion_collective/depots',                  'FKC_GcController@depotsAction', $gc );
$router->get(  'gestion_collective/repertoire',             'FKC_GcController@repertoire', $gc );
$router->get(  'gestion_collective/repertoire/nouvelle',    'FKC_GcController@oeuvreNouvelle', $gc );
$router->post( 'gestion_collective/repertoire',             'FKC_GcController@oeuvreStore', $gc );
$router->get(  'gestion_collective/oeuvre/{id}',            'FKC_GcController@oeuvre', $gc );
$router->post( 'gestion_collective/oeuvre/{id}',            'FKC_GcController@oeuvreAction', $gc );
$router->get(  'gestion_collective/titulaires',             'FKC_GcController@titulaires', $gc );
$router->get(  'gestion_collective/titulaires/nouveau',     'FKC_GcController@titulaireNouveau', $gc );
$router->post( 'gestion_collective/titulaires',             'FKC_GcController@titulaireStore', $gc );
$router->get(  'gestion_collective/titulaire/{id}',         'FKC_GcController@titulaire', $gc );
$router->get(  'gestion_collective/tarifs',                 'FKC_GcController@tarifs', $gc );
$router->post( 'gestion_collective/tarifs',                 'FKC_GcController@tarifsAction', $gc );
$router->get(  'gestion_collective/tarif/{id}',             'FKC_GcController@tarif', $gc );
$router->post( 'gestion_collective/tarif/{id}',             'FKC_GcController@tarifAction', $gc );
$router->get(  'gestion_collective/cles',                    'FKC_GcController@cles', $gc );
$router->post( 'gestion_collective/cles',                    'FKC_GcController@clesAction', $gc );
$router->get(  'gestion_collective/repartition',             'FKC_GcController@repartition', $gc );
$router->post( 'gestion_collective/repartition',             'FKC_GcController@repartitionAction', $gc );
$router->get(  'gestion_collective/decomptes',               'FKC_GcController@decomptes', $gc );
$router->post( 'gestion_collective/decomptes',               'FKC_GcController@decomptesAction', $gc );
$router->get(  'gestion_collective/exploitations',           'FKC_GcController@exploitations', $gc );
$router->post( 'gestion_collective/exploitations',           'FKC_GcController@exploitationsAction', $gc );
$router->get(  'gestion_collective/perception',              'FKC_GcController@perception', $gc );
$router->post( 'gestion_collective/perception',              'FKC_GcController@perceptionAction', $gc );
$router->get(  'gestion_collective/fonds',                   'FKC_GcController@fonds', $gc );
$router->post( 'gestion_collective/fonds',                   'FKC_GcController@fondsAction', $gc );
$router->get(  'gestion_collective/autorisations',           'FKC_GcController@autorisations', $gc );
$router->post( 'gestion_collective/autorisations',           'FKC_GcController@autorisationStore', $gc );
$router->get(  'gestion_collective/autorisation/{id}',       'FKC_GcController@autorisation', $gc );
$router->post( 'gestion_collective/autorisation/{id}',       'FKC_GcController@autorisationAction', $gc );
$router->get(  'gestion_collective/stickers',                'FKC_GcController@stickers', $gc );
$router->post( 'gestion_collective/stickers',                'FKC_GcController@stickersAction', $gc );
$router->get(  'gestion_collective/legales',                 'FKC_GcController@legales', $gc );
$router->post( 'gestion_collective/legales',                 'FKC_GcController@legalesAction', $gc );
$router->get(  'gestion_collective/controles',               'FKC_GcController@controles', $gc );
$router->post( 'gestion_collective/controles',               'FKC_GcController@controlesAction', $gc );
$router->get(  'gestion_collective/reclamations',            'FKC_GcController@reclamations', $gc );
$router->post( 'gestion_collective/reclamations',            'FKC_GcController@reclamationsAction', $gc );
$router->get(  'gestion_collective/audit',                   'FKC_GcController@audit', $gc );
$router->get(  'gestion_collective/anomalies',              'FKC_GcController@anomalies', $gc );
$router->get(  'gestion_collective/profil',                 'FKC_GcController@profil', $gc );
$router->post( 'gestion_collective/profil',                 'FKC_GcController@profilAction', $gc );
}

/* ── Pack Artiste, Producteur & Revenus (Lot 1 : profil, registre, saisie) ──
 *
 * Mêmes précautions que ci-dessus : FKC_ArtisteController n'est chargé que
 * par FKC_Packs::boot() quand le pack est actif. Enregistrer ces routes
 * inconditionnellement laisserait des chemins pointant une classe absente.
 *
 * Cinq écrans, pas un de plus. Les imports (Lot 3), les déclarations (Lot 4)
 * et les dépenses (Lot 5) n'ont pas de route ici tant qu'ils n'ont pas
 * d'écran : une route déclarée sans destination est un 500 en attente.
 */
if ( in_array( 'artiste', FKC_Packs::activeCodes(), true ) ) {
$art = array( 'auth' => true, 'societe' => true, 'module' => 'artiste' );
$router->get(  'artiste',               'FKC_ArtisteController@index', $art );
$router->get(  'artiste/revenus',       'FKC_ArtisteController@revenus', $art );
$router->post( 'artiste/revenus',       'FKC_ArtisteController@revenusAction', $art );
$router->get(  'artiste/revenu/{id}',   'FKC_ArtisteController@revenu', $art );
$router->get(  'artiste/depenses',      'FKC_ArtisteController@depenses', $art );
$router->post( 'artiste/depenses',      'FKC_ArtisteController@depensesAction', $art );
$router->get(  'artiste/imports',       'FKC_ArtisteController@imports', $art );
$router->post( 'artiste/imports',       'FKC_ArtisteController@importsAction', $art );
$router->get(  'artiste/import/{id}',   'FKC_ArtisteController@import', $art );
$router->get(  'artiste/tresorerie',    'FKC_ArtisteController@tresorerie', $art );
$router->get(  'artiste/declarations',  'FKC_ArtisteController@declarations', $art );
$router->post( 'artiste/declarations',  'FKC_ArtisteController@declarationsAction', $art );
$router->get(  'artiste/declaration/{id}', 'FKC_ArtisteController@declaration', $art );
$router->get(  'artiste/profil',        'FKC_ArtisteController@profil', $art );
$router->post( 'artiste/profil',        'FKC_ArtisteController@profilAction', $art );
$router->get(  'artiste/types',         'FKC_ArtisteController@types', $art );
$router->post( 'artiste/types',         'FKC_ArtisteController@typesAction', $art );
$router->get(  'artiste/configuration', 'FKC_ArtisteController@configuration', $art );
$router->post( 'artiste/configuration', 'FKC_ArtisteController@configurationAction', $art );
}

// Label musical (édition Creative).
$lbl = array( 'auth' => true, 'societe' => true, 'module' => 'royalties' );
/*
 * Route « nue » du pack Distribution musicale — ce pack n'a pas d'écran
 * propre (il réutilise entièrement royalties/*, voir
 * app/Packs/distribution_musicale/pack.php), mais chaque pack a besoin
 * d'un point d'entrée sous son propre code (même correction que pour
 * Audiovisuel/Médias/Agences, 1.520.50 et suivants).
 */
$router->get( 'distribution_musicale', 'FKC_RoyaltiesController@catalogue',
	array( 'auth' => true, 'societe' => true, 'module' => 'distribution_musicale' ) );
/*
 * REVENUS NUMÉRIQUES & ROYALTIES (1.779.0) — écran du moteur commun. Gardé par
 * la comptabilité et non par le module « royalties » (exclusif à l'édition
 * Creative) : le Créateur, la Web TV ou la production audiovisuelle y ont
 * droit au même titre que le Label.
 */
$rnm = array( 'auth' => true, 'societe' => true, 'module' => 'comptabilite' );
$router->get(  'creative/metiers',                                    'FKC_CreativeMetierController@index', array( 'auth' => true, 'societe' => true ) );
$crea = array( 'auth' => true, 'societe' => true );
$router->get(  'creative/programmation',                              'FKC_ProgrammationController@index', $crea );
$router->get(  'creative/production',                                 'FKC_ProductionAvController@index', $crea );
$router->post( 'creative/production/creer',                           'FKC_ProductionAvController@creer', $crea );
$router->post( 'creative/production/financement',                     'FKC_ProductionAvController@financement', $crea );
$router->post( 'creative/production/coproducteur',                    'FKC_ProductionAvController@coproducteur', $crea );
$router->post( 'creative/production/fenetre',                         'FKC_ProductionAvController@fenetre', $crea );
$router->post( 'creative/production/remontee',                        'FKC_ProductionAvController@remontee', $crea );
$router->post( 'creative/production/livrer',                          'FKC_ProductionAvController@livrer', $crea );
$router->post( 'creative/production/reverser',                        'FKC_ProductionAvController@reverser', $crea );
$router->get(  'creative/programmation/diffusions',                   'FKC_ProgrammationController@diffusions', $crea );
$router->post( 'creative/programmation/programme',                    'FKC_ProgrammationController@creerProgramme', $crea );
$router->post( 'creative/programmation/grille',                       'FKC_ProgrammationController@creerGrille', $crea );
$router->post( 'creative/programmation/case',                         'FKC_ProgrammationController@ajouterCase', $crea );
$router->post( 'creative/programmation/conducteur',                   'FKC_ProgrammationController@genererConducteur', $crea );
$router->post( 'creative/programmation/constater',                    'FKC_ProgrammationController@constater', $crea );
$router->post( 'creative/programmation/reporter',                     'FKC_ProgrammationController@reporter', $crea );
$router->get(  'revenus-numeriques',                                  'FKC_RevenusNumeriquesController@index', $rnm );
$router->get(  'revenus-numeriques/rapports',                         'FKC_RevenusNumeriquesController@rapports', $rnm );
$router->post( 'revenus-numeriques/rapports',                         'FKC_RevenusNumeriquesController@rapportStore', $rnm );
$router->get(  'revenus-numeriques/rapports/{id}',                    'FKC_RevenusNumeriquesController@rapport', $rnm );
$router->post( 'revenus-numeriques/rapports/{id}/comptabiliser',      'FKC_RevenusNumeriquesController@rapportComptabiliser', $rnm );
$router->post( 'revenus-numeriques/rapports/{id}/encaisser',          'FKC_RevenusNumeriquesController@rapportEncaisser', $rnm );
$router->post( 'revenus-numeriques/rapports/{id}/annuler',            'FKC_RevenusNumeriquesController@rapportAnnuler', $rnm );
$router->get(  'revenus-numeriques/estimations',                      'FKC_RevenusNumeriquesController@estimations', $rnm );
$router->post( 'revenus-numeriques/estimations',                      'FKC_RevenusNumeriquesController@estimationStore', $rnm );
$router->post( 'revenus-numeriques/estimations/contrepasser-exercice', 'FKC_RevenusNumeriquesController@contrepasserExercice', $rnm );
$router->post( 'revenus-numeriques/estimations/{id}/comptabiliser',   'FKC_RevenusNumeriquesController@estimationComptabiliser', $rnm );
$router->post( 'revenus-numeriques/estimations/{id}/contrepasser',    'FKC_RevenusNumeriquesController@estimationContrepasser', $rnm );
$router->post( 'revenus-numeriques/estimations/{id}/annuler',         'FKC_RevenusNumeriquesController@estimationAnnuler', $rnm );
$router->get(  'revenus-numeriques/attendus',                         'FKC_RevenusNumeriquesController@attendus', $rnm );
$router->get(  'revenus-numeriques/cloture',                          'FKC_RevenusNumeriquesController@cloture', $rnm );
$router->get(  'revenus-numeriques/audit',                            'FKC_RevenusNumeriquesController@audit', $rnm );
$router->get(  'revenus-numeriques/ecarts',                            'FKC_RevenusNumeriquesController@ecarts', $rnm );
$router->get(  'revenus-numeriques/distributeurs',                     'FKC_RevenusNumeriquesController@distributeurs', $rnm );
$router->post( 'revenus-numeriques/distributeurs/encaisser',           'FKC_RevenusNumeriquesController@encaisserSource', $rnm );
$router->post( 'revenus-numeriques/sources/valider-regime',            'FKC_RevenusNumeriquesController@validerRegime', $rnm );
$router->get(  'revenus-numeriques/sources',                          'FKC_RevenusNumeriquesController@sources', $rnm );
$router->post( 'revenus-numeriques/sources',                          'FKC_RevenusNumeriquesController@sourceStore', $rnm );
$router->get(  'royalties',                           'FKC_RoyaltiesController@index', $lbl );
/*
 * Fabrication du label (pressage CD, clés USB, merchandising) : PAS un
 * second moteur — le pack Creative Suite manifeste explicitement vouloir
 * « le même moteur que l'industrie » (voir app/Packs/creative/pack.php,
 * capacité de cockpit 'production'). Avant ce correctif, cette capacité
 * s'affichait mais ne menait nulle part : le seul écran existant
 * (industrie/production) est verrouillé au module 'industrie', absent
 * d'une licence Creative Suite — le cockpit restait donc en permanence
 * vide. FKC_IndustrieController::moduleBase() adapte déjà la vue et la
 * redirection ; ces deux routes ne font qu'ouvrir la même porte au module
 * 'royalties'.
 */
$router->get(  'royalties/production',                'FKC_IndustrieController@production', $lbl );
$router->post( 'royalties/production',                'FKC_IndustrieController@productionAction', $lbl );
$router->get(  'royalties/artistes',                  'FKC_RoyaltiesController@artistes', $lbl );
$router->get(  'royalties/artistes/nouveau',          'FKC_RoyaltiesController@artisteNew', $lbl );
$router->post( 'royalties/artistes',                  'FKC_RoyaltiesController@artisteSave', $lbl );
$router->get(  'royalties/artistes/{id}/modifier',    'FKC_RoyaltiesController@artisteEdit', $lbl );
$router->post( 'royalties/artistes/{id}/supprimer',   'FKC_RoyaltiesController@artisteDelete', $lbl );
$router->get(  'royalties/contrats',                  'FKC_RoyaltiesController@contrats', $lbl );
$router->post( 'royalties/contrats',                  'FKC_RoyaltiesController@contratStore', $lbl );
$router->post( 'royalties/contrats/{id}/supprimer',   'FKC_RoyaltiesController@contratDelete', $lbl );
$router->post( 'royalties/artistes/{id}',             'FKC_RoyaltiesController@artisteSave', $lbl );
$router->get(  'royalties/catalogue',                 'FKC_RoyaltiesController@catalogue', $lbl );
$router->post( 'royalties/catalogue',                 'FKC_RoyaltiesController@catalogueStore', $lbl );
$router->post( 'royalties/catalogue/{id}/supprimer',  'FKC_RoyaltiesController@catalogueDelete', $lbl );
$router->get(  'royalties/catalogue/{id}/splits',              'FKC_RoyaltiesController@oeuvreSplits', $lbl );
$router->post( 'royalties/catalogue/{id}/splits',              'FKC_RoyaltiesController@oeuvreSplitAdd', $lbl );
$router->post( 'royalties/catalogue/{id}/splits/{splitId}/supprimer', 'FKC_RoyaltiesController@oeuvreSplitDelete', $lbl );
$router->post( 'royalties/catalogue/{id}/splits/amorcer',           'FKC_RoyaltiesController@oeuvreSplitAmorcer', $lbl );
$router->post( 'royalties/catalogue/{id}/splits/equilibrer',        'FKC_RoyaltiesController@oeuvreSplitEquilibrer', $lbl );
$router->post( 'royalties/catalogue/{id}/splits/{splitId}/part',    'FKC_RoyaltiesController@oeuvreSplitPart', $lbl );
$router->get(  'royalties/projets',                   'FKC_RoyaltiesController@projets', $lbl );
$router->post( 'royalties/projets',                   'FKC_RoyaltiesController@projetStore', $lbl );
$router->post( 'royalties/projets/{id}/supprimer',    'FKC_RoyaltiesController@projetDelete', $lbl );
$router->get(  'royalties/royalties',                 'FKC_RoyaltiesController@royalties', $lbl );
$router->get(  'royalties/baremes',                   'FKC_RoyaltiesController@baremes', $lbl );
$router->post( 'royalties/baremes/nom',               'FKC_RoyaltiesController@baremeNom', $lbl );
$router->post( 'royalties/baremes/enregistrer',       'FKC_RoyaltiesController@baremeStore', $lbl );
$router->post( 'royalties/baremes/{id}/supprimer',    'FKC_RoyaltiesController@baremeDelete', $lbl );
$router->get(  'royalties/decompte',                  'FKC_RoyaltiesController@decompte', $lbl );
$router->get(  'royalties/decompte/ayant-droit',       'FKC_RoyaltiesController@decompteIndividuel', $lbl );
$router->post( 'royalties/royalties/import',          'FKC_RoyaltiesController@royaltyImport', $lbl );
$router->post( 'royalties/royalties/{id}/ligne',      'FKC_RoyaltiesController@royaltyLigne', $lbl );
$router->post( 'royalties/royalties/{id}/reventiler',   'FKC_RoyaltiesController@royaltyReventiler', $lbl );
$router->post( 'royalties/royalties/{id}/comptabiliser', 'FKC_RoyaltiesController@royaltyComptabiliser', $lbl );
$router->post( 'royalties/royalties/{id}/encaisser',    'FKC_RoyaltiesController@royaltyEncaisser', $lbl );
$router->post( 'royalties/encaissements/{id}/supprimer', 'FKC_RoyaltiesController@encaissementDelete', $lbl );
$router->get(  'royalties/revision',                   'FKC_RoyaltiesController@royaltyRevision', $lbl );
$router->post( 'royalties/revision/{id}/confirmer',    'FKC_RoyaltiesController@royaltyConfirmer', $lbl );
$router->post( 'royalties/revision/{id}/ecarter',      'FKC_RoyaltiesController@royaltyEcarter', $lbl );
$router->post( 'royalties/royalties/{id}/cours',       'FKC_RoyaltiesController@royaltyCours', $lbl );
$router->post( 'royalties/royalties/{id}/attribuer',   'FKC_RoyaltiesController@royaltyAttribuer', $lbl );
$router->post( 'royalties/royalties/{id}/supprimer',  'FKC_RoyaltiesController@royaltyDelete', $lbl );
$router->get(  'royalties/paiements',                 'FKC_RoyaltiesController@paiements', $lbl );
$router->post( 'royalties/paiements',                 'FKC_RoyaltiesController@paiementStore', $lbl );
$router->post( 'royalties/paiements/{id}/payer',      'FKC_RoyaltiesController@paiementPayer', $lbl );
$router->post( 'royalties/paiements/{id}/supprimer',  'FKC_RoyaltiesController@paiementDelete', $lbl );
$router->get(  'royalties/releases',                  'FKC_RoyaltiesController@releases', $lbl );
$router->post( 'royalties/releases',                  'FKC_RoyaltiesController@releaseStore', $lbl );
$router->post( 'royalties/releases/{id}/statut',      'FKC_RoyaltiesController@releaseStatut', $lbl );
$router->post( 'royalties/releases/{id}/supprimer',   'FKC_RoyaltiesController@releaseDelete', $lbl );
$router->get(  'royalties/concerts',                  'FKC_RoyaltiesController@concerts', $lbl );
$router->post( 'royalties/concerts',                  'FKC_RoyaltiesController@concertStore', $lbl );
$router->post( 'royalties/concerts/{id}/supprimer',   'FKC_RoyaltiesController@concertDelete', $lbl );
$router->get(  'royalties/merch',                     'FKC_RoyaltiesController@merch', $lbl );
$router->post( 'royalties/merch',                     'FKC_RoyaltiesController@merchStore', $lbl );
$router->post( 'royalties/merch/{id}/vente',          'FKC_RoyaltiesController@merchVente', $lbl );
$router->post( 'royalties/merch/{id}/supprimer',      'FKC_RoyaltiesController@merchDelete', $lbl );
$router->get(  'royalties/studio',                    'FKC_RoyaltiesController@studio', $lbl );
$router->post( 'royalties/studio',                    'FKC_RoyaltiesController@studioStore', $lbl );
$router->post( 'royalties/studio/{id}/supprimer',     'FKC_RoyaltiesController@studioDelete', $lbl );
$router->get(  'royalties/publishing',                'FKC_RoyaltiesController@publishing', $lbl );
$router->post( 'royalties/publishing',                'FKC_RoyaltiesController@publishingStore', $lbl );
$router->post( 'royalties/publishing/{id}/supprimer', 'FKC_RoyaltiesController@publishingDelete', $lbl );
$router->get(  'royalties/marketing',                 'FKC_RoyaltiesController@marketing', $lbl );
$router->post( 'royalties/marketing',                 'FKC_RoyaltiesController@marketingStore', $lbl );
$router->post( 'royalties/marketing/{id}/supprimer',  'FKC_RoyaltiesController@marketingDelete', $lbl );
$router->get(  'royalties/crm',                       'FKC_RoyaltiesController@crm', $lbl );
$router->post( 'royalties/crm',                       'FKC_RoyaltiesController@crmStore', $lbl );
$router->post( 'royalties/crm/{id}/supprimer',        'FKC_RoyaltiesController@crmDelete', $lbl );
$router->get(  'royalties/plan-comptable',            'FKC_RoyaltiesController@planComptable', $lbl );
$router->post( 'royalties/plan-comptable/installer',  'FKC_RoyaltiesController@planComptableSeed', $lbl );
$router->get(  'royalties/secteur',                   'FKC_RoyaltiesController@secteur', $lbl );
$router->post( 'royalties/secteur',                   'FKC_RoyaltiesController@secteurSave', $lbl );
$router->get(  'royalties/portail',                   'FKC_RoyaltiesController@portail', $lbl );

/* ── Module Analyse (Insights) ────────────────────────────────────────────── */
$an = array( 'auth' => true, 'societe' => true, 'module' => 'analyse' );
$router->get(  'analyse',                  'FKC_AnalyseController@index', $an );
$router->get(  'analyse/financiere',       'FKC_AnalyseController@financiere', $an );
$router->get(  'analyse/commerciale',      'FKC_AnalyseController@commerciale', $an );
$router->get(  'analyse/clients',          'FKC_AnalyseController@clients', $an );
$router->get(  'analyse/recouvrement',     'FKC_AnalyseController@recouvrement', $an );
$router->get(  'analyse/rh',               'FKC_AnalyseController@rh', $an );
$router->get(  'analyse/inventaire',       'FKC_AnalyseController@inventaire', $an );
$router->get(  'analyse/budgetaire',       'FKC_AnalyseController@budgetaire', $an );
$router->get(  'analyse/rentabilite',      'FKC_AnalyseController@rentabilite', $an );
$router->get(  'analyse/previsionnelle',   'FKC_AnalyseController@previsionnelle', $an );
$router->get(  'analyse/sectorielle',      'FKC_AnalyseController@sectorielle', $an );
$router->get(  'analyse/kpi',              'FKC_AnalyseController@kpi', $an );
$router->post( 'analyse/kpi',              'FKC_AnalyseController@kpiStore', $an );
$router->post( 'analyse/kpi/{id}/supprimer', 'FKC_AnalyseController@kpiDelete', $an );
$router->get(  'analyse/alertes',          'FKC_AnalyseController@alertes', $an );
$router->post( 'analyse/alertes',          'FKC_AnalyseController@alerteStore', $an );
$router->post( 'analyse/alertes/reglages', 'FKC_AnalyseController@alertesReglages', $an );
$router->post( 'analyse/alertes/test',    'FKC_AnalyseController@alertesTest', $an );
$router->post( 'analyse/alertes/{id}/supprimer', 'FKC_AnalyseController@alerteDelete', $an );
$router->get(  'analyse/rapports',         'FKC_AnalyseController@rapports', $an );
$router->get(  'analyse/rapports/voir',    'FKC_AnalyseController@rapportVoir', $an );
$router->get(  'analyse/rapports/export',  'FKC_AnalyseController@rapportExport', $an );
$router->post( 'analyse/rapports/enregistrer', 'FKC_AnalyseController@rapportEnregistrer', $an );
$router->get(  'analyse/rapports/{id}/ouvrir', 'FKC_AnalyseController@rapportOuvrir', $an );
$router->post( 'analyse/rapports/{id}/supprimer', 'FKC_AnalyseController@rapportSupprimer', $an );
$router->get(  'analyse/warehouse',        'FKC_AnalyseController@warehouse', $an );
$router->post( 'analyse/warehouse/refresh', 'FKC_AnalyseController@warehouseRefresh', $an );
$router->get(  'analyse/fie',              'FKC_AnalyseController@fie', $an );
$router->post( 'analyse/fie/playbook',     'FKC_AnalyseController@fiePlaybook', $an );

/* ── FinaKop Maestro ────────────────────────────────────────────────────
 * Le cockpit est sous le module « analyse » (il en est la forme aboutie).
 * L'API, elle, est HORS module : le tableau de bord, la comptabilité, le
 * stock, le scan et le mobile doivent pouvoir interroger le même cerveau
 * sans dépendre de la licence du module Analyse — c'est tout l'intérêt
 * d'avoir un cerveau unique plutôt qu'un moteur par écran.
 * Les POST écrivent (retour utilisateur, observation) : le routeur en
 * vérifie le jeton anti-CSRF, comme pour toute route POST non publique. */
$router->get(  'analyse/maestro',        'FKC_MaestroController@index', $an );
$mst = array( 'auth' => true, 'societe' => true );
$router->get(  'maestro/ask',            'FKC_MaestroController@ask', $mst );
$router->post( 'maestro/ask',            'FKC_MaestroController@ask', $mst ); // 1.845.0 : une question écrit (fil, journal)
$router->get(  'maestro/langage',          'FKC_MaestroController@langage', $an );          // 1.846.0 : questions incomprises, expressions apprises
$router->post( 'maestro/langage/apprendre','FKC_MaestroController@langageApprendre', $an );
$router->post( 'maestro/langage/retirer',  'FKC_MaestroController@langageRetirer', $an );
$router->post( 'maestro/nouveau-fil',    'FKC_MaestroController@nouveauFil', $mst );
$router->get(  'maestro/priorites',      'FKC_MaestroController@priorites', $mst );
$router->get(  'maestro/conseiller',     'FKC_MaestroController@conseiller', $mst );
$router->get(  'maestro/opportunites',   'FKC_MaestroController@opportunites', $mst );
$router->post( 'maestro/opportunites/conclure', 'FKC_MaestroController@conclureOpportunite', $mst );
$router->get(  'maestro/prevoir',        'FKC_MaestroController@prevoir', $mst );
$router->get(  'maestro/backtest',       'FKC_MaestroController@backtest', $mst );
$router->get(  'maestro/pourquoi',       'FKC_MaestroController@pourquoi', $mst );
$router->get(  'maestro/causalite',      'FKC_MaestroController@causalite', $mst );
$router->get(  'maestro/expliquer',      'FKC_MaestroController@expliquer', $mst );
$router->get(  'maestro/simuler',        'FKC_MaestroController@simuler', $mst );
$router->post( 'maestro/simuler',        'FKC_MaestroController@simuler', $mst );
$router->get(  'maestro/leviers',        'FKC_MaestroController@leviers', $mst );
$router->get(  'maestro/action/preparer', 'FKC_MaestroController@preparerAction', $mst );
$router->post( 'maestro/action/preparer', 'FKC_MaestroController@preparerAction', $mst );
$router->post( 'maestro/action/executer', 'FKC_MaestroController@executerAction', $mst );
$router->post( 'maestro/action/abandonner', 'FKC_MaestroController@abandonnerAction', $mst );
$router->get(  'maestro/action/dossier', 'FKC_MaestroController@dossierAction', $mst );
$router->post( 'maestro/feedback',       'FKC_MaestroController@feedback', $mst );
$router->post( 'maestro/observer',       'FKC_MaestroController@observer', $mst );
$router->get(  'maestro/apprentissages', 'FKC_MaestroController@apprentissages', array( 'auth' => true, 'societe' => true, 'admin' => true ) );
$router->get(  'maestro/appris',         'FKC_MaestroController@apprisEnVigueur', $mst );
$router->post( 'maestro/apprendre',      'FKC_MaestroController@apprendre', array( 'auth' => true, 'societe' => true, 'admin' => true ) );
$router->post( 'maestro/revoquer',       'FKC_MaestroController@revoquer', array( 'auth' => true, 'societe' => true, 'admin' => true ) );
$router->post( 'maestro/apprentissages/valider', 'FKC_MaestroController@validerApprentissage', array( 'auth' => true, 'societe' => true, 'admin' => true ) );


// Référentiels tiers (clients & fournisseurs).
$ref = array( 'auth' => true, 'societe' => true );
$router->get( 'referentiels/clients',                    'FKC_RefClientController@index', $ref );
$router->get( 'referentiels/clients/nouveau',            'FKC_RefClientController@create', $ref );
$router->post( 'referentiels/clients',                   'FKC_RefClientController@store', $ref );
$router->get( 'referentiels/clients/{id}/modifier',      'FKC_RefClientController@edit', $ref );
$router->post( 'referentiels/clients/{id}',              'FKC_RefClientController@update', $ref );
$router->post( 'referentiels/clients/{id}/supprimer',    'FKC_RefClientController@destroy', $ref );
$router->get( 'referentiels/fournisseurs',                 'FKC_RefFournisseurController@index', $ref );
$router->get( 'referentiels/fournisseurs/nouveau',         'FKC_RefFournisseurController@create', $ref );
$router->post( 'referentiels/fournisseurs',                'FKC_RefFournisseurController@store', $ref );
$router->get( 'referentiels/fournisseurs/{id}/modifier',   'FKC_RefFournisseurController@edit', $ref );
$router->post( 'referentiels/fournisseurs/{id}',           'FKC_RefFournisseurController@update', $ref );
$router->post( 'referentiels/fournisseurs/{id}/supprimer', 'FKC_RefFournisseurController@destroy', $ref );

// Items de facturation (vente/achat ↔ comptes)
$router->get(  'referentiels/items',                'FKC_ItemController@index',  $ref );
$router->get(  'referentiels/items/nouveau',        'FKC_ItemController@create',  $ref );
$router->post( 'referentiels/items',                'FKC_ItemController@store',   $ref );
$router->get(  'referentiels/items/{id}/modifier',  'FKC_ItemController@edit',    $ref );
$router->post( 'referentiels/items/{id}',           'FKC_ItemController@update',  $ref );
$router->post( 'referentiels/items/{id}/supprimer', 'FKC_ItemController@delete',  $ref );

// Recouvrement (poste client / créances) — toutes éditions sauf Starter.
$recouv = array( 'auth' => true, 'societe' => true, 'module' => 'recouvrement' );
/*
 * Dépréciation des créances — rattachée au module Recouvrement : c'est lui qui
 * identifie le risque, et séparer les deux obligerait à ouvrir deux droits pour
 * une seule chaîne de travail.
 */
/*
 * POLITIQUES : LECTURE OUVERTE, MODIFICATION RÉSERVÉE (1.841.1).
 * Les seuils de validation décident QUI peut valider une dotation
 * (FKC_WorkflowValidation::peutValider). Modifiables par tout utilisateur
 * du module, ils permettaient à un agent de se désigner valideur de ses
 * propres dotations. La cadence des relances engage de même la relation
 * client de toute la société : décision de direction, pas d'opérateur.
 */
$recouvAdmin = $recouv + array( 'admin' => true );
$router->get(  'recouvrement/politiques',           'FKC_PolitiqueController@index', $recouv );
$router->post( 'recouvrement/politiques/relances',  'FKC_PolitiqueController@relances', $recouvAdmin );
$router->post( 'recouvrement/politiques/workflow',  'FKC_PolitiqueController@workflow', $recouvAdmin );
$router->post( 'recouvrement/politiques/reinit',    'FKC_PolitiqueController@reinit', $recouvAdmin );
$router->post( 'recouvrement/politiques/paiement',  'FKC_PolitiqueController@paiement', $recouvAdmin );
$router->post( 'recouvrement/politiques/penalites', 'FKC_PolitiqueController@penalites', $recouvAdmin );
$router->get(  'depreciation',               'FKC_DepreciationController@index', $recouv );
$router->post( 'depreciation/ouvrir',        'FKC_DepreciationController@ouvrir', $recouv );
$router->get(  'depreciation/fiche',         'FKC_DepreciationController@fiche', $recouv );
$router->post( 'depreciation/justifier',     'FKC_DepreciationController@justifier', $recouv );
$router->post( 'depreciation/qualifier',     'FKC_DepreciationController@qualifier', $recouv );
$router->post( 'depreciation/valider',       'FKC_DepreciationController@valider', $recouv );
$router->post( 'depreciation/comptabiliser', 'FKC_DepreciationController@comptabiliser', $recouv );
$router->post( 'depreciation/reprendre',     'FKC_DepreciationController@reprendre', $recouv );
$router->post( 'depreciation/perte',         'FKC_DepreciationController@perte', $recouv );
$router->post( 'depreciation/encaissement',  'FKC_DepreciationController@encaissement', $recouv );
$router->post( 'depreciation/abandonner',    'FKC_DepreciationController@abandonner', $recouv );
$router->get(  'depreciation/releve',        'FKC_DepreciationController@releve', $recouv );
$router->get(  'credit',                     'FKC_CreditController@portefeuille', $recouv );
$router->get(  'credit/client',              'FKC_CreditController@client', $recouv );
$router->post( 'credit/limite',              'FKC_CreditController@limite', $recouv );
$router->post( 'credit/garantie',            'FKC_CreditController@garantie', $recouv );
$router->get(  'credit/avis',                'FKC_CreditController@avis', $recouv );
$router->post( 'credit/avis',                'FKC_CreditController@avis', $recouv );
$router->get( 'recouvrement',                       'FKC_RecouvrementController@dashboard', $recouv );
$router->get( 'recouvrement/balance-agee',          'FKC_RecouvrementController@agee', $recouv );
$router->get( 'recouvrement/centre-appels',         'FKC_RecouvrementController@centre', $recouv );
$router->get( 'recouvrement/relances',              'FKC_RecouvrementController@relances', $recouv );
$router->post( 'recouvrement/relances/log',         'FKC_RecouvrementController@relanceLog', $recouv );
$router->post( 'recouvrement/relances/envoyer',     'FKC_RecouvrementController@relanceEnvoyer', $recouv );
$router->post( 'recouvrement/relances/envoyer-tout','FKC_RecouvrementController@relanceEnvoyerTout', $recouv );
$router->get( 'recouvrement/promesses',             'FKC_RecouvrementController@promesses', $recouv );
$router->post( 'recouvrement/promesses/{id}/statut','FKC_RecouvrementController@promesseStatut', $recouv );
$router->get( 'recouvrement/plans',                 'FKC_RecouvrementController@plans', $recouv );
$router->post( 'recouvrement/plans/creer',          'FKC_RecouvrementController@planCreer', $recouv );
$router->post( 'recouvrement/plans/echeance/{id}',  'FKC_RecouvrementController@planEcheance', $recouv );
$router->get( 'recouvrement/dossiers',              'FKC_RecouvrementController@dossiers', $recouv );
$router->post( 'recouvrement/dossiers/basculer',    'FKC_RecouvrementController@dossierBasculer', $recouv );
$router->post( 'recouvrement/dossiers/{id}/statut', 'FKC_RecouvrementController@dossierStatut', $recouv );
$router->get( 'recouvrement/anomalies',             'FKC_RecouvrementController@anomalies', $recouv );
$router->get(  'recouvrement/sources',              'FKC_RecouvrementController@sources', $recouv );
$router->get(  'recouvrement/pilotage',             'FKC_RecouvrementController@pilotage', $recouv );
$router->post( 'recouvrement/sources/rattacher',    'FKC_RecouvrementController@sourcesRattacher', $recouv );
$router->get( 'recouvrement/clients',               'FKC_RecouvrementController@clients', $recouv );
$router->get( 'recouvrement/clients/{id}',          'FKC_RecouvrementController@fiche', $recouv );
$router->post( 'recouvrement/clients/{id}/action',  'FKC_RecouvrementController@action', $recouv );
$router->post( 'recouvrement/clients/{id}/promesse','FKC_RecouvrementController@promesse', $recouv );
$router->get( 'recouvrement/clients/{id}/mise-en-demeure', 'FKC_RecouvrementController@miseEnDemeure', $recouv );

/* ── Répartition ─────────────────────────────────────────────────────────── */
/* ── Module Santé : rendez-vous, praticiens, hospitalisation, dossier médical ──
 *
 * QUI REÇOIT QUOI, ET POURQUOI PAS TOUT LE MONDE PAREIL (1.530.0)
 *
 * Le bloc ne servait que la clinique et l'hôpital. Le cabinet médical, le
 * laboratoire et l'optique — trois métiers dont la journée EST un planning de
 * rendez-vous — n'avaient donc aucun écran d'agenda. Le défaut s'est vu au
 * moment où le moteur UX a voulu composer leur menu : il refuse d'afficher une
 * entrée qui n'ouvre rien, et « Rendez-vous » disparaissait silencieusement.
 *
 * On n'ouvre pas pour autant les mêmes écrans à tous. L'HOSPITALISATION
 * suppose des lits, des admissions et des sorties : la proposer à un cabinet
 * ou à un opticien afficherait un écran que personne ne remplira jamais, et
 * ferait douter du reste. Chaque pack reçoit donc ce que son métier pratique.
 *
 * Le DOSSIER médical reste servi par le moteur vertical pour les métiers
 * ambulatoires, qui l'ont déjà : le redéclarer ici créerait deux routes pour
 * un même chemin, et la première enregistrée gagnerait — un comportement qui
 * dépendrait de l'ordre des lignes de ce fichier. */
$fkc_sante_ecrans = array(
	'clinique'        => array( 'planning', 'praticiens', 'hospitalisation', 'dossiers', 'assurances' ),
	'hopital'         => array( 'planning', 'praticiens', 'hospitalisation', 'dossiers', 'assurances' ),
	'cabinet_medical' => array( 'planning', 'praticiens', 'dossiers', 'assurances' ),
	'laboratoire'     => array( 'planning', 'praticiens', 'assurances' ),
	'optique'         => array( 'planning', 'assurances' ),
);
$fkc_sante_actif = FKC_Packs::activeCode();
if ( isset( $fkc_sante_ecrans[ $fkc_sante_actif ] ) ) {
	$sm  = $fkc_sante_actif;
	/*
	 * 1.858.0 — AUTHENTIFICATION ET SOCIÉTÉ EXIGÉES. Ces routes ne portaient
	 * que le contrôle de licence : planning, praticiens, lits et DOSSIERS
	 * MÉDICAUX étaient servis sans session, et sans société active la base
	 * interrogée était celle du cabinet. Même garde que tout écran métier.
	 */
	$sto = array( 'auth' => true, 'societe' => true, 'module' => $sm );
	$ecr = $fkc_sante_ecrans[ $sm ];

	if ( in_array( 'planning', $ecr, true ) ) {
		$router->get(  $sm . '/planning',                        'FKC_SanteController@planning', $sto );
		$router->post( $sm . '/planning/rdv',                    'FKC_SanteController@rdvCreer', $sto );
		$router->post( $sm . '/planning/rdv/{id}/facturer',      'FKC_SanteController@rdvFacturer', $sto );
		$router->post( $sm . '/planning/rdv/{id}/annuler',       'FKC_SanteController@rdvAnnuler', $sto );
		$router->post( $sm . '/planning/rdv/{id}/absent',        'FKC_SanteController@rdvAbsent', $sto );
		$router->post( $sm . '/planning/rdv/{id}/rappel',        'FKC_SanteController@rdvRappel', $sto );
	}
	if ( in_array( 'praticiens', $ecr, true ) ) {
		$router->get(  $sm . '/praticiens',                      'FKC_SanteController@praticiens', $sto );
		$router->post( $sm . '/praticiens/creer',                'FKC_SanteController@praticienCreer', $sto );
		$router->post( $sm . '/praticiens/retrocessions',        'FKC_SanteController@retrocessionsComptabiliser', $sto );
		$router->post( $sm . '/praticiens/{id}',                 'FKC_SanteController@praticienMaj', $sto );
	}
	if ( in_array( 'hospitalisation', $ecr, true ) ) {
		$router->get(  $sm . '/hospitalisation',                 'FKC_SanteController@hospitalisation', $sto );
		$router->post( $sm . '/hospitalisation/lit',             'FKC_SanteController@litCreer', $sto );
		$router->post( $sm . '/hospitalisation/admettre',        'FKC_SanteController@admettre', $sto );
		$router->post( $sm . '/hospitalisation/sejour/{id}/sortir', 'FKC_SanteController@sortir', $sto );
		$router->post( $sm . '/hospitalisation/sejour/{id}/facturer', 'FKC_SanteController@sejourFacturer', $sto );
		$router->post( $sm . '/hospitalisation/sejour/{id}/transferer', 'FKC_SanteController@sejourTransferer', $sto );
		$router->post( $sm . '/hospitalisation/forfait',          'FKC_SanteController@forfaitService', $sto );
	}
	if ( in_array( 'assurances', $ecr, true ) ) {
		// Tiers payant (1.859.0) : conventions, bordereaux mensuels, rejets de prise en charge.
		$router->get(  $sm . '/assurances',                      'FKC_SanteController@assurances', $sto );
		$router->post( $sm . '/assurances',                      'FKC_SanteController@assurancesAction', $sto );
	}
	if ( in_array( 'dossiers', $ecr, true ) ) {
		// Dossier médical électronique — chemin propre (1.858.0), distinct du dossier de facturation.
		$router->get(  $sm . '/dme',                             'FKC_SanteController@dossiers', $sto );
		$router->post( $sm . '/dme/creer',                       'FKC_SanteController@patientCreer', $sto );
		$router->get(  $sm . '/dme/{id}',                        'FKC_SanteController@dossier', $sto );
		$router->post( $sm . '/dme/{id}',                        'FKC_SanteController@dossierAction', $sto );
		$router->post( $sm . '/dme/{id}/facturation',            'FKC_SanteController@dossierFacturation', $sto );
		$router->get(  $sm . '/dme/{id}/ordonnance/{cid}',       'FKC_SanteController@ordonnance', $sto );
	}
}

/* ── Pack Distribution : logistique (dépôts, tournées, palettes, livraisons) ── */
if ( in_array( 'distribution', FKC_Packs::activeCodes(), true ) ) {
	require_once FKC_ROOT . 'Packs/distribution/Models/Depot.php';
	require_once FKC_ROOT . 'Packs/distribution/Models/Tournee.php';
	require_once FKC_ROOT . 'Packs/distribution/Controllers/DistributionController.php';
	$dto = array( 'auth' => true, 'societe' => true, 'module' => 'distribution' );
	$router->get(  'distribution/depots',          'FKC_DistributionController@depots', $dto );
	$router->post( 'distribution/depots',          'FKC_DistributionController@depotsAction', $dto );
	$router->get(  'distribution/palettes',        'FKC_DistributionController@palettes', $dto );
	$router->post( 'distribution/palettes',        'FKC_DistributionController@palettesAction', $dto );
	$router->get(  'distribution/chauffeurs',      'FKC_DistributionController@chauffeurs', $dto );
	$router->post( 'distribution/chauffeurs',      'FKC_DistributionController@chauffeursAction', $dto );
	$router->get(  'distribution/tournees',        'FKC_DistributionController@tournees', $dto );
	$router->post( 'distribution/tournees',        'FKC_DistributionController@tourneesAction', $dto );
	$router->get(  'distribution/tournee',         'FKC_DistributionController@tourneeVoir', $dto );
}

/* ── Pack Industrie : production, MRP, OEE, maintenance ──────────────────── */
// require_once (1.876.0) : FKC_Packs::boot() a DÉJÀ chargé les modèles du pack principal ;
// un second « require » déclarait deux fois FKC_IndMrp et chaque page tombait en erreur fatale
// dès que le pack principal était Industrie (ou Distribution, plus haut).
if ( in_array( 'industrie', FKC_Packs::activeCodes(), true ) ) {
	require_once FKC_ROOT . 'Packs/industrie/Models/Production.php';
	require_once FKC_ROOT . 'Packs/industrie/Models/Mrp.php';
	require_once FKC_ROOT . 'Packs/industrie/Models/IndKpi.php';
	require_once FKC_ROOT . 'Packs/industrie/Models/Qualite.php';
	require_once FKC_ROOT . 'Packs/industrie/Controllers/IndustrieController.php';
	$ito = array( 'auth' => true, 'societe' => true, 'module' => 'industrie' );
	$router->get(  'industrie/production',      'FKC_IndustrieController@production', $ito );
	$router->post( 'industrie/production',      'FKC_IndustrieController@productionAction', $ito );
	$router->get(  'industrie/mrp',             'FKC_IndustrieController@mrp', $ito );
	$router->get(  'industrie/pilotage-usine',  'FKC_IndustrieController@pilotageUsine', $ito );
	$router->get(  'industrie/qualite',         'FKC_IndustrieController@qualite', $ito );
	$router->post( 'industrie/qualite',         'FKC_IndustrieController@qualiteAction', $ito );
	$router->get(  'industrie/maintenance',     'FKC_IndustrieController@maintenance', $ito );
	$router->post( 'industrie/maintenance',     'FKC_IndustrieController@maintenanceAction', $ito );
}

/* ── Webhooks publics (appelés par les fournisseurs de paiement) ───────────
 *
 * Trois problèmes se cumulaient, tous propres aux appels ANONYMES :
 *
 * 1. FATAL — enregistrées sans condition, ces routes désignaient
 *    FKC_RetailController, classe chargée seulement quand le pack Retail est
 *    actif : « Class not found », et les chemins du serveur dans la trace.
 *
 * 2. 404 — le conditionner à FKC_Packs::activeCode() a résolu le fatal mais
 *    créé l'inverse : un webhook arrive SANS session, donc sans société
 *    branchée ; sous licence « multi », le pack se lit dans la base de la
 *    société et activeCode() retombe sur « generique ». La route n'était donc
 *    plus enregistrée du tout pour l'appelant qu'elle vise.
 *
 * 3. MAUVAISE BASE — sans société branchée, FKC_DB retombe sur le fichier de
 *    repli FKC_DATA_DIR/finakopcore.db : la recherche de la transaction se
 *    faisait dans une base vide, que l'appel CRÉAIT et migrait au passage.
 *    Aucun paiement n'a donc jamais pu être confirmé par webhook.
 *
 * La société est désormais portée par l'URL — même principe que le code
 * d'appairage « <socId>-… » du Smart Scan. La route s'enregistre toujours ;
 * c'est le gestionnaire qui branche la base, charge le pack et vérifie. Les
 * fournisseurs doivent être reconfigurés sur :
 *     /webhook/momo/<id_societe>/<fournisseur>
 * L'ancienne forme répond 400 avec un message explicite, sans toucher à
 * aucune base.
 */
$fkcMomo = function ( $societeId, $provider ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$repondre = function ( $http, $message ) {
		http_response_code( $http );
		echo json_encode( array( 'ok' => false, 'message' => $message ), JSON_UNESCAPED_UNICODE );
	};
	// Limiteur : route publique, donc exposée au balayage d'identifiants.
	// FKC_Security est chargé inconditionnellement par ce fichier.
	$ip = FKC_Security::clientIp();
	if ( FKC_Security::actionBloquee( 'webhook_momo', $ip ) ) {
		$repondre( 429, 'Trop de tentatives. Réessayez plus tard.' ); return;
	}
	if ( ! FKC_Tenant::bindById( (int) $societeId ) ) {
		FKC_Security::actionEchec( 'webhook_momo', $ip, 20, 15 );
		$repondre( 404, 'Société inconnue.' ); return;
	}
	// La base de la société est branchée : le pack peut enfin être résolu,
	// puis chargé (c'est lui qui définit FKC_RetailController).
	FKC_Packs::reset();
	/*
	 * 1.843.0 — LA CAISSE DU NOYAU reçoit aussi ses notifications, quel que
	 * soit le pack : la transaction est cherchée d'abord dans ses demandes
	 * (caisse_paiements_mm). Une référence inconnue d'elle retombe, pour une
	 * société Retail, sur l'ancien point de vente (historique).
	 */
	if ( class_exists( 'FKC_PaiementMobile' ) ) {
		$payload = (string) file_get_contents( 'php://input' );
		$entetes = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();
		list( $okW, $msgW, $httpW ) = FKC_PaiementMobile::webhook( (string) $provider, $payload, $entetes );
		if ( 404 !== (int) $httpW || 'retail' !== FKC_Packs::activeCode() ) {
			FKC_Security::actionReussie( 'webhook_momo', $ip );
			http_response_code( (int) $httpW );
			echo json_encode( array( 'ok' => (bool) $okW, 'message' => $msgW ), JSON_UNESCAPED_UNICODE );
			return;
		}
	}
	if ( 'retail' !== FKC_Packs::activeCode() ) {
		$repondre( 404, 'Encaissement Mobile Money non activé pour cette société.' ); return;
	}
	FKC_Packs::boot();
	if ( ! class_exists( 'FKC_RetailController' ) ) {
		$repondre( 503, 'Module d\'encaissement indisponible.' ); return;
	}
	FKC_Security::actionReussie( 'webhook_momo', $ip );
	$c = new FKC_RetailController();
	return $c->momoWebhook( (string) $provider );
};
$router->get(  'webhook/momo/{societe}/{provider}', $fkcMomo, array( 'public' => true ) );
$router->post( 'webhook/momo/{societe}/{provider}', $fkcMomo, array( 'public' => true ) );

/* Ancienne forme sans société : refus explicite, aucune base ouverte. */
$fkcMomoLegacy = function ( $provider ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	http_response_code( 400 );
	echo json_encode( array(
		'ok'      => false,
		'message' => 'URL de webhook incomplète : utilisez /webhook/momo/<id_societe>/<fournisseur>.',
	), JSON_UNESCAPED_UNICODE );
};
$router->get(  'webhook/momo/{provider}', $fkcMomoLegacy, array( 'public' => true ) );
$router->post( 'webhook/momo/{provider}', $fkcMomoLegacy, array( 'public' => true ) );

$router->dispatch();
