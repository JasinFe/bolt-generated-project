# Patch 1.876.2 — Double authentification, invisibilité, durcissements

Base : 1.876.1.

- Nouveau `app/Core/DeuxFacteurs.php` (`FKC_DeuxFacteurs`) : TOTP RFC 6238 (SHA-1, 6 chiffres, 30 s, fenêtre ±1), secret de 160 bits chiffré par `FKC_Crypto` dans `auth_deux_facteurs` (registre), anti-rejeu (`dernier_pas`), 10 codes de secours hachés à usage unique, QR code par le générateur interne (`FKC_Barcode::qr`).
- `FKC_Auth::attempt()` : si la 2FA est active, la session n'est PAS ouverte (`fkc_2fa_attente`, 5 min, 5 essais, échecs comptés par le limiteur de connexion) ; `finaliserDeuxFacteurs()` l'ouvre ; `ouvrirSession()` factorisé.
- Nouveau `DeuxFacteursController` + vues `verification.php`, `deux_facteurs.php` ; routes `verification`, `securite/deux-facteurs[/activer|/desactiver]` ; routeur : renvoi vers `verification` quand le second facteur est attendu, et vers l'activation quand `FKC_2FA_ADMIN_OBLIGATOIRE` l'impose (option de route `sans_2fa`).
- Console : `--tenant=<id> 2fa:etat`, `2fa:desactiver <login>`.
- Plateforme : `securite.bloquer_robots` (`FKC_Plateforme_Amorcage::estRobot()` : moteurs, robots d'IA, aperçus de liens → 403 ; `/api` et `/webhook` exemptés), `securite.masquer_version` (`fkc_version_publique()`), `securite.2fa_admin_obligatoire` ; cookie de session `__Host-` en mode sous-domaine ; portail sans consultation du registre (pas d'énumération).
- `public/.htaccess` : X-Robots-Tag `noindex … noai, noimageai` sur toutes les réponses, `X-Permitted-Cross-Domain-Policies: none`, 404 si le dossier est atteint par l'adresse principale (`__DOSSIER_WEB__` inscrit par `deployer.sh`) ; `robots.txt` nommant les robots d'IA.

Tests : `tests/securite_2fa_invisibilite_1876_2.php`.

---

# Patch 1.876.1 — Licence obligatoire, reprise dans un espace existant, mutualisé

Base : 1.876.0 (retours du premier déploiement chez Hostinger).

- **Licence obligatoire** (plateforme, `licence.obligatoire`, par défaut `true` ; constante `FKC_LICENSE_OBLIGATOIRE`) : sans licence active (absente, invalide, expirée, révoquée, autre domaine), aucun module ; toute route authentifiée renvoie vers l'écran Licence (seuls « licence », « mot-de-passe », « logout » restent ouverts) ; l'API répond 402 `licence_requise`. Sans la constante (extension WordPress) : édition Starter inchangée.
- Défaut corrigé (sécurité) : **tout utilisateur connecté pouvait installer ou remplacer la licence** de la société (`activateLicense` sans contrôle de rôle). Réservé à l'administrateur (403 et trace `license_install_refuse` sinon) ; le formulaire n'est affiché qu'à lui.
- `tenant:importer … --remplacer` : reprise dans un espace existant (créé vide) ; contenu actuel mis de côté, retour à l'identique en cas d'écart au contrôle, sessions invalidées.
- `scripts/deployer.sh` : dossier web en 755, fichiers publiés en 644 (le umask 027 de l'installation rendait 403 sous LiteSpeed) ; retrait de `default.php` déposé par l'hébergeur.
- `scripts/installer.sh`, `scripts/deployer.sh`, `cron/worker.sh` : choix automatique d'un PHP avec pdo_sqlite et sodium (`$PHP`, sinon `php`, puis `/opt/alt/php84|83|85|82`).

Tests : `tests/licence_obligatoire_1876.php` — 7 vérifications (échouent sur 1.876.0).

---

# Patch 1.876.0 — Plateforme autonome multi-clients (sans WordPress)

Base : 1.875.6.

- Nouveau `public/index.php`, `public/.htaccess` : contrôleur frontal unique.
- Nouveau `app/Plateforme/` : `Config.php` (`FKC_Config`), `Registre.php` (`plateforme.db` : tenants, tenant_domaines, journal, taches), `TenantResolver.php`, `Cloudflare.php` (`FKC_Plateforme_Proxy`), `Pages.php`, `Amorcage.php`, `Instantane.php`, `Sauvegarde.php`, `Console.php`, `Cron.php`.
- Nouveaux `bin/finakop`, `cron/worker.php`, `cron/worker.sh`, `config/config.exemple.php`, `scripts/installer.sh`, `scripts/deployer.sh`, `scripts/migration-donnees.php`, `scripts/diagnostic-hebergement.php`, `VERSION`.
- Nouveau `app/Core/Mailer.php` (`FKC_Mailer`) ; `FKC_Courriel` l'utilise quand il est configuré.
- `app/noyau.php` : création explicite d'un registre neuf (`FKC_NOYAU_CREER`).
- `FKC_Security::cookieParams()` : chemin du cookie = `FKC_BASE_PATH` ; `app/index.php` : nom de session `FKC_SESSION_NAME` ; posture « données sous la racine web » générique.
- `FKC_BPEWorker`, `FKC_Balayeur` : `FKC_CRON_EXTERNE` / `FKC_CRON_INTERVALLE`.
- `FKC_Connect_Push` : `pointAutorise()` (liste des services de poussée, adresses publiques, `follow_location=0`), `FKC_ADMIN_EMAIL`.
- `FKC_PlanAudit::cibleDans()` ; `FKC_PlanPurge::analyser()` calcule le classement une fois.
- Défaut corrigé (préexistant) : `app/index.php` incluait sept fichiers des packs distribution et industrie par `require` alors qu'un autre chemin les avait déjà chargés → « Cannot declare class FKC_IndMrp », erreur fatale sur **toutes** les pages quand le pack principal est industrie ou distribution. Passés en `require_once`.
- `FKC_Router` : échec CSRF → 403 au lieu de 419 (code non standard, transformé en 500 par Apache/LiteSpeed).
- Reprise d'un site WordPress dont la clé est la constante `FKC_ENCRYPTION_KEY` : `migration-donnees.php` l'emporte dans `.fkc-encryption-key` (0600, dans le manifeste) ; la plateforme la redéfinit pour ce client (même dérivation, même repli). Import refusé si la clé manque.

- Défaut corrigé (sécurité) : injection de formule dans les exports CSV (`fkc_csv()`, journal Connect). Nouvelle `fkc_csv_cellule()` : cellule commençant par = + - @ tabulation ou retour chariot préfixée d'une apostrophe ; les nombres (« -1 500,00 ») ne sont pas touchés.
- Plateforme : sauvegarde quotidienne du registre des clients et de la configuration (`plateforme:sauvegarder`, `plateforme:restaurer`) ; nettoyage des restes d'une sauvegarde interrompue ; `environnement` (production | staging | development) — `debug` sans effet en production, erreurs fatales au journal `php-erreurs.log` ; identifiant de client de 3 à 40 caractères à la création. Adresse canonique d'un client calculée en un seul endroit (`FKC_Plateforme_Amorcage::adresseClient()`, sous-domaine ou chemin) ; en mode chemin, un espace inconnu répond 404 et le portail ne répond qu'à « / ».

- Fichiers téléversés (GED, pièces comptables, logos, pièces du cabinet) en 0640 au lieu de 0644 : illisibles des autres comptes d'un serveur mutualisé (Connect et terminal l'étaient déjà).
- Flux temps réel (afficheur client, scanner) : `FKC_SSE` / `fkc_sse_actif()`. Désactivé par défaut sur la plateforme (`temps_reel.sse = false`) : un flux SSE occupe un processus PHP par écran ouvert, rationnés sur un mutualisé ; les écrans passent par leur interrogation courte existante, le flux répond 204. Sans la constante (extension WordPress) : inchangé.

Tests : `tests/plateforme_chargement_packs_1876.php` — 7 vérifications (5 échouent sur 1.875.5) ; `tests/export_csv_injection_1876.php` — 21 vérifications ; `tests/plateforme_temps_reel_1876.php` — 7 vérifications ; `tests/televersements_droits_1876.php` — 2 vérifications  (échouent sur 1.875.5).

---

# Patch 1.875.6 — Traitements planifiés réparés, clé de chiffrement protégée, mode autonome

Base : 1.875.5.

- Nouveau `app/noyau.php` : charge le noyau hors requête HTTP (WP-Cron, WP-CLI, cron système du mode autonome). `app/index.php` s'arrête avant l'API quand `FKC_CLI_NOYAU` est défini ; noyau.php enchaîne `FKC_Master::conn()`, `FKC_License::bootstrap()`, `FKC_Immobilisation::brancher()`, `FKC_License_Depot::enregistrerTraitement()`, `FKC_Assistant::brancherForecast()`. Ne crée rien si le registre n'existe pas.
- `finakop-erp-core.php` : `fkc_hote_application()` (domaine du jeton, sinon préfixe de sous-domaine ou hôte du site en mode chemin) et `fkc_executer_hors_requete()` (constantes, HTTP_HOST posé puis rendu). Crochets `fkc_bpe_worker`, `fkc_balayage_temporisation` et commande `wp finakop bpe` réécrits dessus ; nouvelle commande `wp finakop balayage [--forcer]`.
- `FKC_Crypto::key()` : empreinte HMAC de la clé dans `cab_parametres` (`crypto_empreinte`) ; clé divergente → `RuntimeException` ; clé absente avec données chiffrées (empreinte connue, ou valeurs `FKC1.`/`FKC2.` trouvées dans les bases, balayage borné à 20 s) → `RuntimeException`, aucune clé écrite. Connexion PDO distincte du registre (pas de dépendance aux migrations).

Tests : `tests/cle_chiffrement_noyau_1875_6.php` — 15 vérifications (échouent sur 1.875.5).

---

# Patch 1.875.5 — Moteur de référentiels (lot D) : microfinance au référentiel des SFD

Base : 1.875.4.

- Nouveau `app/Core/PlanSfd.php` (`FKC_PlanSfd`) : `nomenclature()` (référentiel SFD UMOA, classes 1 à 7, niveaux à 2 et 3 chiffres publiés), `comptes()`, `correspondances()`, `historiques()` (411131 → 381010, 771200 → 702510, 706188 → 708010, 758150 → 702520, 674120 → 602010, 659410 → 664000, 491200 → 299000), `installer()`, `valider()`, `conflits()`, `manquants()`. Chargé par `app/index.php`.
- `FKC_Referentiel` : `rubriquesPropres('rcsfd_umoa')` = classes 1 à 8 ; `planDe()`, `correspondancesDe()` ; `complet()` et `traduire()` génériques pour les plans tenus (SYCEBNL, CIMA, SFD).
- Pack `microfinance` : `ecritures.php` et `ModelesEcritures` (381010, 702510, 702520, 708010, 332010) ; `PlanMetier` : section microfinance (381010, 602010, 702510, 702520, 708010, 332010).
- `FKC_Provision::racinesProvision()` : 51, 52 pour les SFD. Paramètres › Référentiel : carte « Plan comptable » générique (CIMA, SFD) ; `referentielPlan()` choisit le plan par référentiel.
- Banques : PCB révisé déclaré, non tenu (plan de comptes non relevé).

Tests : `tests/referentiel_sfd_1875_5.php` — 24 vérifications ; `plan.php` : correspondances de tout référentiel tenu.

---

# Patch 1.875.4 — Moteur de référentiels (lot C) : subdivisions hiérarchiques, référentiel par type d'entité

Base : 1.875.3.

- `FKC_PlanSyscohada::normaliser()` conserve les numéros de 7 à 10 chiffres (`LONGUEUR_MAX = 10`) ; `parentOfficiel()` et `FKC_Referentiel::parentOfficielDans()` rattachent une subdivision hiérarchique à son divisionnaire.
- Nouveau `app/Core/Subdivision.php` (`FKC_Subdivision`) : `estHierarchique()`, `parent()`, `controler()`, `prochainLibre()`, `saturation()`, `divisionnairesSatures()`.
- `FKC_PlanMetier::valider()` accepte 7 à 10 chiffres (rattachement au compte existant au-dessus) ; `familleNomenclature()` ; `referentielDuPack()` : banque → pcb_umoa, microfinance → rcsfd_umoa. `FKC_Compte::create()` : parent hiérarchique. `FKC_CompteController` : 1 à 10 chiffres, contrôle dans le référentiel du dossier, `?sous=` propose le prochain numéro. Plan comptable : carte « Divisionnaires presque pleins », retrait des subdivisions, intitulé du référentiel actif. Analytique, `PlanPurge`, `PlanCanonique::doublonsDeCle()` : 6 à 10 chiffres.
- `FKC_Referentiel` : référentiels `pcb_umoa` et `rcsfd_umoa` (non tenus, `reperesBancaires()`) ; `typesEntite()`, `typeDuPack()`, `typeEntite()`, `referentielDuType()`, `definirTypeEntite()` ; ordre de résolution : surcharge → type d'entité → pack → défaut ; `source()` = « type_entite » ; `pourGeneration()` suit le type.
- Paramètres › Référentiel comptable : carte « Type d'entité » (route POST `parametres/referentiel/type`), bandeau de couverture réécrit.
- Packs `banque` et `microfinance` : référentiels sectoriels déclarés.

Tests : `tests/referentiels_subdivisions_1875_4.php` — 25 vérifications ; `referentiel_cima_1611` : cinq référentiels.

---

# Patch 1.875.3 — Moteur de référentiels (lot B) : assurance en plan CIMA natif, courtage distinct

Base : 1.875.2.

- Nouveau `app/Core/PlanCima.php` (`FKC_PlanCima`) : `nomenclature()` (Code CIMA art. 431, classes 1 à 8), `comptes()` (socle), `correspondances()` (modules communs → CIMA), `historiques()` (anciens numéros du pack → CIMA), `installer()`, `valider()`, `conflits()`, `manquants()`. Chargé par `app/index.php` (require_once, avec `Referentiel.php` et `PlanSycebnl.php`).
- `FKC_Referentiel` : `rubriquesPropres('cima')` = classes 1 à 8 ; index à six chiffres qui tient le « 0 » signifiant (compte le plus précis, intitulé composé) ; `estImputableDans()` ; `pourGeneration( $pack )` ; `complet('cima')` = installé et validé ; `traduire()` généralisé (SYCEBNL et CIMA) ; un numéro officiel à la fois CIMA/SYCEBNL et SYSCOHADA n'est natif que s'il est déclaré par le référentiel.
- Pack `assurance_compagnie` : `ecritures.php` réécrit (411050, 702200, 702220, 701020, 435000, 801100/320100, 801500/325000, 602000, 601000, 709200/400100, 400000/609200, 400000/752000) ; types de pièces `prime_vie`, `prestation_vie`, `recuperation`, `commission_reassurance` ; `hooks.php` (primes et sinistres vie dans le S/P) ; `ModelesEcritures` aligné ; `PlanMetier` : section CIMA (411050, 701020, 702220, 801100, 801500), `referentielDuPack()` → cima, `chargerReferentiels()`, `referentielDeLEvenement()`.
- Pack `assurance_courtage` : référentiel SYSCOHADA.
- `FKC_GenerateurPlan` : niveau 1 posé dans le référentiel du pack généré (`pourGeneration`), type imputable selon ce référentiel ; `retirerHorsReferentiel()` désactive les comptes réglementaires vierges sans équivalent. `FKC_PlanAudit::analyser()`, `FKC_Ecriture` (comptes de regroupement), `FKC_Provision::racinesProvision()` et `FKC_Fait::incoherences()` lisent le référentiel.
- Paramètres › Référentiel comptable : carte « Plan comptable CIMA » (installation, validation, conflits).

Tests : `tests/referentiel_cima_1875_3.php` — 40 vérifications ; mis à jour : plan, regressions_1520, referentiel_cima_1611 (le courtage relève du SYSCOHADA), ponts_maestro_provision_1722 (3250 / 80).

---

# Patch 1.875.2 — Moteur de référentiels comptables (lot A) : SYCEBNL natif, rubrique 707

Base : 1.875.1.

- `FKC_Referentiel` : nomenclature par référentiel (`nomenclature()`, `indexNomenclature()`, `rubriquesPropres()`, `estOfficielDans()`, `libelleOfficielDans()`, `parentOfficielDans()`), comptes propres (`comptesPropres()`, `estNatif()`, `libelleCompte()`), compte par rôle (`compteRole()`). `traduire()` : les numéros retirés sont toujours traduits dans un dossier SYCEBNL ; `controler()` ne signale plus un compte natif.
- `FKC_PlanSycebnl` : `nomenclature()` (rubrique 70 du SYCEBNL), socle de revenus refondu (701000, 702000, 703000, 704100…704700, 705100/705200/705300, 706000, 707000, 708000), `correspondances()` limitée aux comptes génériques, `historiques()` (758110 → 704410, 758120 → 704420, 758130 → 704120, 758140 → 701810, 758200 → 704110, 706182 → 705210).
- `FKC_PlanMetier` : `socleSycebnl()` (471160, 701810, 704110, 704120, 705210), `socleReferentiel()`, `referentielDuPack()`, `soclePour()` ; `valider( $comptes, $referentiel )` et unicité par référentiel ; sections Église (704410, 704420, 704510), Association et ONG sans 758xxx ; coopérative : + 758200.
- `FKC_GenerateurPlan` : niveau 1 posé depuis la nomenclature du référentiel du dossier ; niveau 2 = `soclePour( référentiel du pack )` + pack. `FKC_PlanCanonique` : socles de référentiel, parent calculé dans le référentiel. `FKC_Posting::compteId()` : intitulé du référentiel d'abord.
- Schémas : `eglise/ecritures.php`, `ong/ecritures.php`, `ModelesEcritures` (Église, ONG). `FKC_Cotisation::compteProduit()`, `FKC_Don::compteProduit()`.
- `FKC_PlanSyscohada` : 7072 ajouté ; 7073, 7076, 7077 corrigés. Comptes : 707610 → 707210, 707710 → 707610, 707720 → 707620, 707320 → 707630, 707250 → 702150.
- `FKC_DB` : schéma 30, `realignerReferentiels()` (intitulés 707200/707300/707600/707700 des comptes réglementaires existants).

Tests : `tests/referentiels_sycebnl_1875_2.php` — 35 vérifications ; mis à jour : sycebnl_lot7_1520_152, social_lot2_1520_147, social_lot4_1520_149, tracabilite_faits_1605, regressions_1520, invariant_subdivisions_1591, commerce_vague2_bout_en_bout.

---

# Patch 1.875.1 — Audit transversal : ponts par le journal temporaire, imputations, design

Base : 1.875.0.

- `FKC_Brouillard` : 19 tables ajoutées à `LIENS` (ec_inscriptions, ec_inscription_frais, ec_vacations, inv_mouvements, lbl_paiements, lbl_royalty_imports, lbl_royalty_encaissements, rev_revenus, rights_droits, contract_contrats, btp_situations, rt_sessions, co_parts, soc_appel_lignes, on_fonds_dedies, tr_missions, tr_entretiens, tr_carburant, tr_recettes) ; nouvelle `lier( $brouillonId, $table, $colonne, $ids )`.
- École (`Scolarite`, `Pedagogie`) : lien sur les créances, frais et vacations ; garde « déjà au journal temporaire » ; `inscriptionsNonComptabilisees()` exclut les brouillons en attente ; `radier()` refuse tant que la créance attend.
- Royalties (`LabelComptable`, `DistributionComptable`, `Royalty`, `Paiement`, `RoyaltyEncaissement`) et Stock (`InvComptable::$dernier`, `InvMouvement::record()`) : l'identifiant de brouillon n'est plus rangé comme écriture ; brouillon relié au document.
- `FKC_Revenu` : lien `rev_revenus` ; `annuler()` refuse si l'écriture attend. `FKC_RevenuReglement::annuler()` lit l'écriture sur `reglements` et refuse si elle attend.
- `FKC_RightsEngine`, `FKC_ContractEngine` : lien au format {table, colonne, ids}. BTP, Retail, Coopérative, Social, ONG, Transport : `lier()` / lien.
- Imputations : 467xxx → 4711xx/4712xx/4731xx ; 449110 → 449210 ; 6015xx → 6011xx (601165, 601175) ; 601800 → 601185 ; import 601200 / 401500 ; 701121 → 706103 (au socle `PlanMetier::socleCommun()`) ; 707200 → 706198 ; 707410 → 706199 ; 707110 → 707830 ; 638120 → 638840 ; streaming : 411 = `ttc - commission`, commission 632290 ; paie : 663800. `EtatsDgi` : note 8 ligne 7 + 4711, note 19 ligne 14 + 473.
- Audiovisuel : métrique « Livrables en retard » sur `deliverable_items`.
- Design : `app.css` (bloc « 1.875.1 — RAFFINEMENTS VISUELS ») ; `_cockpitnav.php` : défilement horizontal de la barre d'onglets sans faire défiler la page.

Tests : `tests/ponts_brouillard_1875_1.php` — 14 vérifications ; tests mis à jour pour les nouveaux comptes (plan, tracabilite_faits_1605, revenus_numeriques_1779, paie_od_analytique_1843_2 et suites des packs concernés).

---

# Patch 1.875.0 — Page de connexion : aucune mention de l'hébergement

Base : 1.874.0.

- `app/views/login.php` : l'aide « Mot de passe oublié ? » ne mentionne plus WordPress ni ses réglages.

Tests : `tests/mise_en_page_login_1546.php` (31 contrôles, dont « aucune mention de WordPress sur la page »).

---

# Patch 1.874.0 — Page de connexion : nom du produit, mot de passe

Base : 1.873.0.

- `app/views/login.php` : nom à côté du logo ; case `changer_mdp` ; bloc « Mot de passe oublié ? » (`<details>`, sans script).
- `FKC_DashboardController::login()` : après une connexion réussie, redirige vers `mot-de-passe` si `changer_mdp` est coché.
- `app/assets/css/app.css` : `.lg-nom`, `.lg-options`, `.lg-check`, `.lg-oubli`.

Tests : `tests/mise_en_page_login_1546.php` (30 contrôles).

---

# Patch 1.873.0 — Page de connexion : textes revus, cockpit retiré

Base : 1.872.0.

- `app/views/login.php` : nouveaux titre, domaines, textes, atouts, accroche et signature ; « Bienvenue sur FinaKop » ; retrait de l'aperçu du cockpit et du sélecteur de métiers (et de leur script) ; ruban titré.
- `app/assets/css/app.css` : bloc « VITRINE EN UN ÉCRAN » réécrit (tailles adaptées à Syne, chiffres compacts, domaines, accroche, signature) ; le ruban continue de défiler sous `prefers-reduced-motion`, ralenti.

Tests : `tests/mise_en_page_login_1546.php` (26 contrôles) et `tests/mise_en_page_login_degrade_1546.php` (10 contrôles).

---

# Patch 1.872.0 — Page de connexion : vitrine en un écran

Base : 1.871.0.

- `app/views/login.php` : vitrine réécrite (sélecteur de métiers entrelacés par famille, chiffres animés, aperçu du cockpit illustratif, quatre forces, ruban des packs en deux rangées) ; formulaire enrichi (afficher le mot de passe, verrouillage des majuscules, état d'envoi). Aucune dépendance nouvelle ; script en ligne compatible avec la CSP existante.
- `app/assets/css/app.css` : nouveau bloc « VITRINE EN UN ÉCRAN (1.872.0) », préfixe `lg-` ; retrait des règles de la vitrine 1.546.0 devenues sans usage (`hero-chiffres`, `hero-fam*`, `hero-mod*`, `hero-ed*`, `hero-atout*`, `hero-foot`, `hero-inner`). Les classes partagées avec `motdepasse.php` sont inchangées.

Tests : `tests/mise_en_page_login_1546.php` (24 contrôles) et `tests/mise_en_page_login_degrade_1546.php` (11 contrôles), réécrits.

---

# Patch 1.871.0 — Transport & Logistique : recommandations de l'audit 1.870.0

Base : 1.870.0. Rapport : `AUDIT_TRANSPORT_LOGISTIQUE_1870.md` (§ 6).

- Transport : `Models/Exploitation.php` (`FKC_TransportExploitation` — tables `tr_missions`, `tr_echeances` ; missions, anomalies de consommation, échéances, `immobiliserNonConformes()`) ; schémas `transport_frais_mission`, `transport_echeance_payee`, `transport_plein_paye_tva`, `transport_plein_credit_tva`, `transport_affretement_validee` ; pièce `affretement` ; `FKC_TransportFlotte::tvaCarburantRecuperable()`, `definirTvaCarburant()`, rentabilité avec frais de route et échéances ; routes et vue `transport/missions` ; métriques et alertes `tra_echeances_expirees`, `tra_anomalies_conso`.
- Transit : `Models/Suivi.php` (`FKC_TransitSuivi`, tables `tn_suivi`, `tn_etapes`), `FKC_TransitController`, route et vue `transit/suivi`, alerte `transit_franchise_depassee`.
- Logistique : `Models/Entrepot.php` (`FKC_LogEntrepot`, tables `lg_articles`, `lg_mouvements`, `lg_facturations`), `FKC_LogistiqueController`, routes et vues `logistique/entrepot[/{id}]`.
- Import-Export : `Models/Negoce.php` (`FKC_IeNegoce`, tables `ie_pieces_devise`, `ie_stocks`), `FKC_ImportexportController`, route et vue `importexport/negoce` ; champs `quantite`, `credoc`, `credoc_banque`, `credoc_echeance` ; schémas `importexport_change_{gain|perte}_{client|fournisseur}`, `importexport_stock_final` ; alerte `importexport_credocs`.
- `FKC_EcritureLiens` : `tr_missions`, `tr_echeances`, `ie_pieces_devise`, `ie_stocks`.

Tests : `tests/transport_recommandations_1871.php` — 24 contrôles.

---

# Patch 1.870.0 — Transport & Logistique : audit de la famille

Base : 1.869.0. Rapport : `AUDIT_TRANSPORT_LOGISTIQUE_1870.md`.

- `FKC_VpPilotage::typesHorsCa()`, `typesCharge()`, `margeParDossier()`, `deboursARefacturer()` ; `caPeriode()`, `caParType()`, `caParDossier()` hors débours et charges. Manifeste : clés de pièce `hors_ca`, `charge`.
- `FKC_VpDossier::cumulParNature()` ; `cumulFacture()` hors débours et charges ; vue `vertical/dossier_show` (débours, charges, marge).
- Transport : `FKC_TransportFlotte` cache par base, colonnes `mode`, `ecriture_id` (`tr_carburant`, `tr_entretiens`, `tr_recettes`), `statuts()`, `changerStatut()`, `majVehicule()`, `facturesVehicule()` ; `enregistrerPlein/Entretien/Recette( …, $mode )` transactionnels ; schémas `transport_plein_paye`, `transport_entretien_paye`, `transport_entretien_credit`, `transport_recette_exploitation` ; actions `statut`, `fiche` ; vues `flotte`, `vehicule`. `FKC_EcritureLiens` : trois tables du carnet.
- Transit : pièce `debours_paye` (`transit_debours_payes`), `debours` marqué `hors_ca`, `tiers_client` ; métrique, KPI et alerte `transit_debours_a_refacturer`.
- Import-Export : pièces `achat` (`importexport_achat_validee`) et `frais_approche` (`importexport_frais_approche_validee`), `tiers_client` ; métrique `importexport_moyenne` (vraie marge), `importexport_deficitaires` et alerte.
- Logistique : `tiers_client`. Lignes 411 `'tiers' => true` dans les quatre packs.

Tests : `tests/transport_logistique_1870.php` — 23 contrôles.

---

# Patch 1.869.0 — Créatif & Médias : recommandations de l'audit 1.868.0

Base : 1.868.0. Rapport : `AUDIT_CREATIF_MEDIAS_1868.md` (§ 6).

- `FKC_RightsEngine::payer()`, `schemas()` (`reglement_droit_licence`), colonnes `rights_droits.paye`, `date_paiement`, `mode_paiement`, `ecriture_id` ; `FKC_EcritureLiens` : `rights_droits` ; action `regler_droit` (9 fiches).
- Édition : tables `edition_mouvements`, `edition_avances`, colonnes `edition_ouvrages.stock`, `suivi_stock`, `edition_droits_dus.montant_impute`, `date_paiement` ; `FKC_Ouvrage::tirer()`, `retour()`, `verserAvance()`, `avanceRestante()`, `mouvements()`, `releveAuteur()` ; schémas `maison_edition_retour`, `_avance_auteur`, `_avance_imputee` ; route `maison_edition/ouvrage/{id}/releve`, vue `releve`.
- Structure culturelle : `Models/Saison.php` (`FKC_CultureSaison`, tables `sc_abonnements`, `sc_abonnement_usages`, schémas `structure_culturelle_abonnement_vendu`, `_consomme`) ; actions `vendre_abonnement`, `consommer_abonnement`.
- `FKC_Mission::tvaSoldeParDefaut()`, `mentionLivrables()` ; partiel `vertical/facture-solde` (`tvaDefaut`) ; `FKC_FolioAcompte::facturerSolde( opts.mention )`, `clientPour()`.
- `FKC_MediaDistributionEngine` : table `mediadist_tarifs`, colonnes `mediadist_insertions.tarif_catalogue`, `facture_id` ; `ajouterTarif()`, `tarifs()`, `supprimerTarif()`, `tarifApplicable()`, `remise()`. `FKC_Campagne::facturerDiffuse()`, `diffuseesAFacturer()`, `insertionsFacturees()`.
- Partiel `vertical/livrables` ; actions livrables sur les sept fiches mission ; `FKC_Mission::ensure()` initialise `FKC_DeliverableEngine`.
- Gestion collective : schémas `gc_non_identifie_reclame`, `gc_non_identifie_affecte_fonds`, `gc_non_identifie_affecte_redistribution` ; `FKC_GcReclamation::reclamerNonIdentifie()` et `affecter()` transactionnels et comptabilisés.
- `FKC_HospitalityFolio::ctxClientDe()` : folios d'acompte (missions, productions, campagnes, événements) ; `'tiers' => true` sur les lignes 411 de onze packs.
- Événementiel : colonne `ev_evenements.jauge` ; `FKC_Evenement::billetsEngages()`, `placesPrises()`, `placesRestantes()`, `definirJauge()`, `verifierPlaces()` ; `FKC_ProgrammingEngine::vendreBillets()` respecte la jauge de l'événement.

Tests : `tests/creatif_recommandations_1869.php` — 30 contrôles.

---

# Patch 1.868.0 — Créatif & Médias : audit de la famille

Base : 1.867.0. Rapport : `AUDIT_CREATIF_MEDIAS_1868.md`.

- Caches `ensure()` par base (`$ready[cleBase]`) : `FKC_Mission`, `FKC_BudgetEngine`, `FKC_ContractEngine`, `FKC_RightsEngine`, `FKC_CrmBriefEngine`, `FKC_ProjectEngine`, `FKC_DeliverableEngine`, `FKC_ResourceEngine`, `FKC_ProgrammingEngine`, `FKC_MediaDistributionEngine`, modèles Artiste, Audiovisuel, Label, Événementiel, Gestion collective (10), Édition, Médias, `FKC_RoyaltyEncaissement`.
- Nouveau `Core/Creative/Portee.php` (`FKC_CreatifPortee::controler()`, `briefDuPack()`), appelé par les onze fiches de la famille.
- `FKC_FolioAcompte::resoudreClient()` (facture de solde).
- `FKC_ContractEngine` : colonnes `mode_paiement`, `date_paiement`, `ecriture_id` ; schéma `reglement_cachet_contrat` ; `payer( $id, array( 'mode' => … ) )` transactionnel. `rights_droits.contrat_id`, `FKC_RightsEngine::coutTotal()` hors droits issus d'un contrat. `FKC_EcritureLiens` : `contract_contrats`.
- `FKC_ProgrammingEngine::vendreBillets()` : taux `FKC_CategorieFiscale` (nature `billetterie`), représentation terminée close.
- `FKC_MediaDistributionEngine` : créneau unique, `majStatut()` sans « diffusée », confirmation refusée si annulée.
- `FKC_Paiement::delete()` rend `array( ok, message )`.
- `FKC_Production::rembourserAcompte()`, `FKC_Campagne::rembourserAcompte()` : refus si facturé ; `payerContrat( $id, $mode )`.
- Événementiel : tables `ev_paiements`, `ev_sponsor_paiements`, colonne `ev_evenements.facture_id` ; `FKC_Evenement::natures()`, `encaisserAcompte( …, $autoriserTropPercu )`, `encaisserSponsor( …, $autoriserTropPercu )`, `paiements()`, `encaisse()`, `avanceTotal()`, `rembourserAcompte()`, `soldeDevis()`, `facturer()`, `nonFactures()`.
- Maison d'édition : schémas `maison_edition_reglement_caisse`, `_reglement_banque`, `_correction_reglement` ; table `edition_corrections` ; `FKC_Ouvrage::reglementsInverses()`, `corrigerReglementsInverses()` ; action `regulariser_reglements`.
- `FKC_Mission::naturesPack()` ; `encaisserAcompte()` refuse une nature sans schéma. Action `facturer` et bloc facture de solde sur les sept fiches mission.
- Vues : partiel `vertical/creatif-kpis`, CSS `.crea-kpis`, `.crea-jauge`, `form select.input-sm`.

Tests : `tests/creatif_medias_1868.php` — 30 contrôles. `tests/mission.php` : le droit issu d'un contrat n'est plus recompté.

---

# Patch 1.867.0 — Services professionnels : recommandations de l'audit 1.866.0

Base : 1.866.0. Rapport : `AUDIT_SERVICES_1866.md` (§ 6).

- Garage : table `ga_vehicules`, colonnes `ga_ordres.vehicule_id`, `kilometrage` ; `FKC_GarageAtelier::vehiculePour()`, `vehicule()`, `vehicules()`, `majVehicule()`, `historique()`, `rappels()`, `planifierEntretien()`, `ouvrirStock()`, `natures()`, `nature()`, `changerNature()` ; `creerPiece( origine_stock )` ; `FKC_GarageController::vehicules/vehicule/vehiculeAction`, routes `garage/vehicules[/{id}]`, vues `vehicules`, `vehicule` ; compte `471800` (`FKC_PlanMetier`).
- Immobilier : table `im_revisions`, colonnes `im_baux.charges`, `date_revision`, `im_quittances.charges` ; `FKC_ImmoGerance::reviserLoyer()`, `revisions()`, `revisionsDues()`, `regulariserCharges()`, `quittancesAEmettre()`, `emettreMois()`, `etatReddition()`, `proprietaires()` ; métrique et automatisation `immo_emission_quittances` (handler `immo_emettre_quittances`) ; route `immobilier/reddition`, vue `reddition_print`.
- Cabinet juridique : tables `jr_notes`, `jr_provisions`, colonne `jr_diligences.facture_id` ; `FKC_JuridiqueAffaires::facturerHonoraires()`, `recevoirProvision()`, `diligencesAFacturer()`, `soldeProvisions()`, `provisions()`, `notes()`.
- Cabinet : `FKC_CabinetHonoraires::lettrer()` (appelé par `encaisser()`), `situation()` fondée sur le lettrage ; action `lettrer`.
- Imprimerie : `FKC_ImprimerieTravaux` (tables `ip_papiers`, `ip_travaux`, `ip_mouvements`), `FKC_ImprimerieController`, routes `imprimerie/travaux[/{id}]`, vues `travaux`, `travail`, `_ipnav` ; lien au tableau de bord vertical.
- `FKC_StockCompta::comptabiliserReception( …, $lien )`. `FKC_Brouillard::LIENS` et `FKC_EcritureLiens` : `jr_provisions`, `ip_mouvements`.

Tests : `tests/services_recommandations_1867.php` — 43 contrôles.

---

# Patch 1.866.0 — Services professionnels : audit de la famille

Base : 1.865.0. Rapport : `AUDIT_SERVICES_1866.md`.

- Socle vertical : `FKC_VpPiece::schemaPorteTva()`, montant et TVA par `fkc_nombre()` ; `FKC_VpDossier::resoudreClient()` (manifeste `vertical.tiers_client`), `create()`/`update()` ; vue `vertical/dossier_form`. Lignes 411 `'tiers' => true` dans `cabinet_juridique`, `garage`, `immobilier`, `imprimerie`. `FKC_ModelesEcritures` aligné.
- `FKC_StockCompta::comptabiliserConsommation( …, $lien )`, `annulerConsommation()`, `ecrire( …, $lien )`.
- Garage : table `ga_mouvements`, colonnes `ga_ordres.client_id`, `facture_id` ; `FKC_GarageAtelier::facturer()`, `receptionnerPiece()`, `mouvements()`, `poser()` ; `supprimerLigne( $id, $ordreId )` ; `nextReference()` par rang maximal ; actions `facturer`, `receptionner_piece` ; vues `atelier`, `ordre`.
- Immobilier : table `im_operations` ; `FKC_ImmoGerance::appeler()`, `reverser()`, `restituerDepot()`, `operations()`, `locataireClient()` ; `reddition()` (honoraires TTC, reversé, reste dû) ; actions `reverser`, `restituer_depot` ; vues `gerance`, `bien`.
- Cabinet juridique : colonnes `jr_affaires.client_id`, `jr_debours.ecriture_id`, `refacture_ecriture_id`, `jr_sequestre.ecriture_id` ; `FKC_JuridiqueAffaires::clientAffaire()`, `ajouterDebours( …, $moyen )`, `marquerDeboursRefacture( $id, $affaireId )` ; comptes `467500`, `521800` (`FKC_PlanMetier`).
- Cabinet : `FKC_CabinetMissions::n()` ; `FKC_CabinetHonoraires::situation()` compte les reçus.
- Imprimerie : champs `bat`, `support`.
- `FKC_Brouillard::LIENS` et `FKC_EcritureLiens` : `ga_mouvements`, `im_operations`, `jr_debours`, `jr_sequestre`. `FKC_ChaineDoc` : `ordre_reparation`.
- CSS : `.alert a:not(.btn)`.

Tests : `tests/services_1866.php` — 76 contrôles.

---

# Patch 1.865.0 — Commerce & Distribution : recommandations de l'audit 1.864.0

Base : 1.864.0. Rapport : `AUDIT_COMMERCE_DISTRIBUTION_1864.md` (§ 6).

- Caisse : `Core/PosBons.php` (`FKC_PosBons::appliquer()`, `restituer()`), colonnes `caisse_tickets.remise_bon`, `bon_code` ; `FKC_Pos::recalculer()` et `annuler()` ; action `PosController::bonAppliquer`, route `caisse/bon/appliquer` ; bouton « 🎟️ Bon » (`caisse::pos`, `pos.js`).
- Facturation : `FKC_Facture::createAvoirPartiel()`. Stock : retour partiel de vente à crédit (`executerRetourCredit( …, $numeros )`), vue `vertical/stock_retour`.
- Lots : colonne `vp_lots.pu`, `FKC_VpLot::enregistrerEntree( …, $pu )`, `coutPlan()` ; `FKC_VpStock::coutLotSortie()`, `reajusterCump()`, `reponderer()` ; livraison de commande au coût des lots.
- `FKC_VpStock::inventorier()`, `proximiteUnifiee()`, extensions de garantie (`vp_articles.extension_mois`, tables `vp_extensions`, `vp_pca`, `pcaExtensions()`, `pcaConstate()`, `ajusterPcaExtensions()`, schéma `vp_pca_extensions`) ; `FKC_VpStockPilotage::HORS_VENTE` exclut « Inventaire » ; routes `{pack}/stock/inventaire`, `{pack}/stock/pca-extensions`.
- `FKC_VpTarif::paliersDuJour()` ; recalcul du palier dans `vertical/stock_vente`.
- Commandes : `vp_commande_lignes.etape`, `FKC_VpCommande::livrerEtape()`, `etapes()` ; route `{pack}/commandes/{id}/livrer-etape` ; vues `commande_form`, `commande_show`.
- Station-service : `FKC_StationCuves` (table `st_releves`), `FKC_StationController`, vue `stationservice::cuves`, routes `stationservice/cuves`.
- Téléphonie : `FKC_TelFloat` (table `tl_float`, schéma `telephonie_float`), `FKC_TelephonieController`, vue `telephonie::float`, routes `telephonie/float`.
- `FKC_EcritureLiens` : `vp_pca`, `tl_float`.
- Test mis à jour : `commerce_retour_com017.php` (avoirs partiels désormais acceptés).

Tests : `tests/commerce_recommandations_1865.php` — 23 contrôles.

---

# Patch 1.864.0 — Commerce & Distribution : second audit de la famille

Base : 1.863.0. Rapport : `AUDIT_COMMERCE_DISTRIBUTION_1864.md`.

- `fkc_nombre()` (`Core/helpers.php`) ; employée par `VpStockController`, `VpCommandeController`, `VpApresVenteController`, `DistributionController`, `RetailController`, et par `FKC_VpStock::createArticle()/updateArticle()`, `FKC_VpTarif::creer()`, `FKC_VpCommande::creerDevis()`, `FKC_VpUnite::creer()`.
- Caches `ensure()` par base (`cleBaseReady()`) : `FKC_VpApresVente`, `ArticleLie`, `Attribut`, `Capacite`, `Categorie`, `Commande`, `Depot`, `Dossier`, `Lot`, `Marque`, `Serie`, `Stock`, `Tarif`, `Unite` ; `FKC_RetailCentrale`, `Consigne`, `Fidelite`, `Inventaire`, `Merch`, `MobileMoney`, `Store` ; `FKC_DistDepot`, `FKC_DistTournee`.
- `FKC_VpStock` : colonne `vp_articles.prix_ttc` ; `prixTtcParDefaut()` (manifeste `stock.prix_ttc`, paramètre `vp_prix_ttc_{pack}`), `puHt()`, `prixApplicable()`, `regulariserEcart()` ; `vente()` transactionnelle ; unités de facteur ≠ 1 ; `sortirStockSansEcriture( …, $pu )` ; garde de `serialise` dans `updateArticle()`.
- `FKC_VpLot::restituer( $mouvementId, $qte )` (restitution partielle, retour crédit).
- `FKC_VpCommande::livrer( …, $series )` (numéros, garantie, prix de ligne), `annuler( …, $moyenRemboursement )` + `rembourserAcompte()`, schéma `vp_acompte_remboursement`, imputation `{compte_client}`.
- `FKC_Facture::comptabiliser()` et `FKC_Recu::comptabiliser()` créent le compte auxiliaire du client absent du plan.
- Distribution : `ds_arrets.facture_id`, `FKC_DistTournee::livrer()` (différentiel, contrôle de stock, arrêt clos), `facturerArret()` ; schémas `{compte_client}`.
- Électroménager : imputation `{compte_client}`. Station-service : `stock.prix_ttc` = true.
- Retail : `FKC_RetailFidelite::creerBon()` (montant > 0), `utiliserBon()` atomique, action `bon_utiliser`.
- Routes `{pack}/stock/regulariser`, `{pack}/stock/prix-ttc`. Vues `vertical/stock`, `stock_article_form`, `stock_vente`, `commande_show`, `distribution::tournee`, `retail::bons`.
- Test mis à jour : `commerce_electromenager_elm003.php` (imputation `{compte_client}`, repli 411100).

Tests : `tests/commerce_distribution_1864.php` — 32 contrôles.

---

# Patch 1.863.0 — Éducation & Formation : recommandations de l'audit 1.862.0

Base : 1.862.0. Rapport : `AUDIT_EDU_1862.md` (§ 6).

- `FKC_EcoleScolarite` : colonnes `ec_inscriptions.remise_pct`, `remise_motif`, `pec_client_id`, `pec_organisme`, `pec_montant`, `relance_le`, `ec_eleves.portail_token` ; tables `ec_classe_frais`, `ec_inscription_frais` ; `inscrire( …, $opts )` (frais optionnels, remise, prise en charge) ; `natureFrais()`, `compteNature()`, `ajouterFraisClasse()`, `supprimerFraisClasse()`, `fraisClasse()`, `fraisInscription()`, `remiseFratrie()` / `definirRemiseFratrie()`, `clientOrganisme()`, `prisesEnCharge()`, `messageRelance()`, `noterRelance()`, `instructionsPaiement()` / `definirInstructionsPaiement()`, `jetonPortail()`, `lienPortail()`, `eleveParJeton()` ; schéma `ecole_inscription_creance` à deux débiteurs (conditionnels `part_famille` / `part_organisme`, tiers payeur), nouveau `ecole_frais_creance` ; `calculAvance()` hors frais annexes, organisme compris.
- Nouveau `FKC_EcolePedagogie` (`Packs/ecole/Models/Pedagogie.php`) : tables `ec_seances`, `ec_vacations` ; `ajouterSeance()` (conflits), `seances()`, `seancesDuJour()`, `heuresSemaine()`, `saisirVacation()` (schéma `ecole_vacation`), `vacations()`, `coutParClasse()`, `definirCredits()`, `resultat()`, `toleranceExamen()` / `definirToleranceExamen()`, `eligibleExamen()`, `nonAutorises()`.
- `FKC_EcoleVieScolaire` : colonnes `ec_matieres.credits`, `ec_notes.session`, `ec_presences.seance_id` ; `moyenneMatiere()` retient le rattrapage s'il est meilleur ; `saisirNote( …, $session )`, `pointerPresence( …, $seanceId )`, `faireAppel( …, $seanceId )`, `appelDuJour( …, $seanceId )`.
- Portail : `FKC_EcolePortailController` + vue `ecole::portail`, route publique `portail-scolarite/{soc}/{jeton}`.
- Contrôleur et vues École / Université : relances, paramètres, prises en charge, coût des vacations (Scolarité) ; frais annexes, emploi du temps, vacations, accès aux examens, crédits (Classe) ; options d'inscription, espace famille, session de note, crédits (Fiche).
- `FKC_EcritureLiens` : `ec_inscription_frais`, `ec_vacations`. Université : charge `Pedagogie.php`.

Tests : `tests/education_recommandations_1863.php` — 40 vérifications.

---

# Patch 1.862.0 — Éducation & Formation : packs École et Université

Base : 1.861.0. Rapport : `AUDIT_EDU_1862.md`.

- Schémas `ecole_*_validee` et `universite_*_validee` : ligne 411 en `{compte_client}` + tiers (repli compte client par défaut).
- `FKC_EcoleScolarite` : caches `ensure()` par base ; `pack()`, `comptes()`, `vocab()` ; colonnes `ec_inscriptions.client_id`, `ecriture_id`, `radiee_le`, `avoir_ecriture_id` ; table `ec_pca` ; `clientPayeur()`, `comptabiliserInscription()`, `comptabiliserToutes()`, `inscriptionsNonComptabilisees()`, `creanceALInscription()` / `definirCreanceALInscription()` (paramètre `ec_creance_inscription`), `radier()`, `activerAnnee()`, `paiements()`, `pcaConstate()` ; `constaterAvance()` ajuste au solde cible ; `encaisser()` refuse le surpaiement ; `nextMatricule()` au plus grand numéro ; `inscrire()` valide date et nombre de tranches ; schémas `ecole_inscription_creance`, `ecole_pca_scolarite` (origine `ecole`).
- `FKC_EcoleVieScolaire` : caches par base ; périodes par pack (T1–T3 / S1–S2), `periodeParDefaut()`, `mention()`, `moyenneClasse()`, `appreciation()` / `definirAppreciation()` (table `ec_appreciations`), `faireAppel()`, `appelDuJour()` ; `saisirNote()` contrôle période, matière et valeur ; `pointerPresence()` un pointage par jour.
- `FKC_EcoleController` : sert `ecole/…` et `universite/…` ; actions `activer_annee`, `pca`, `comptabiliser_tout`, `mode_creance`, `comptabiliser`, `radier`, `appreciation`, `appel`. Vues `ecole::*` sur le kit `fk-*`.
- Université : `Packs/universite/Models/Scolarite.php` (charge le moteur de l'École), lanceur, routes (`index.php`), assistant.
- `FKC_Recu::comptabiliser()` : tiers client sur les lignes de compte client et d'avances.
- Recouvrement (source « scolarité ») : inscriptions radiées incluses. `FKC_EcritureLiens` : `ec_inscriptions`, `ec_pca`. Hooks École : métriques lues dans la scolarité, `ecole_taux_recouvrement`.
- `FKC_ModelesEcritures::familleEnseignement()` : modèle `ecole_scolarite_percue_avance` retiré (remplacé par `ecole_pca_scolarite`).
- Tests mis à jour : `recouvrement_sources_1841_2.php`, `recouvrement_ponts_1841_6.php` (le tuteur est rattaché et la créance constatée dès l'inscription).

Tests : `tests/education_1862.php` — 37 vérifications.

---

# Patch 1.861.0 — Restauration, Hôtellerie & Loisirs : recommandations de l'audit 1.860.0

Base : 1.860.0. Rapport : `AUDIT_HRL_1860.md` (§ 6).

- Fast-food : champs de point de vente `plateforme`, `commission_pct` ; hook `FKC_VpPiece::onContexte` (client plateforme, `compte_client`, `commission`) ; schéma `fastfood_livraison_validee` conditionnel (411 + 632230 / trésorerie). `FKC_VpPiece::encaisse()` lit aussi les blocs conditionnels.
- Hôtel : colonnes `nb_personnes`, `exonere_taxe`, table `ht_nuits_auditees` ; `FKC_HotelReservation::auditNuit()`, `taxeSejourMode()`, `ajouterTaxe()`, `definirTaxeSejour( $montant, $mode )` ; `checkout()` ne facture que les nuits non auditées ; `FKC_HotelFolio::comptabiliserLignes()` (extrait de `fermer()`) ; action `audit_nuit` ; vues `hotel::reservations`, `hotel::tarifs`.
- `FKC_StockCompta::receptionDirecte()` (écriture + `FKC_AchatReception::enregistrer()`), `comptabiliserReception( …, $fournisseurId )`, tiers sur les lignes de `ecrire()` ; `FKC_RestoCuisine::approvisionner( …, $fournisseurId )`, `FKC_BarCatalogue::ajusterComptabilise( …, $fournisseurId )` ; sélecteur fournisseur (restaurant, maquis, fast-food, bar).
- Bar : table `br_mouvements`, `FKC_BarCatalogue::coulage()` ; vue `bar::inventaire`.
- Loisirs : colonnes `relance_le`, `renouvelle_id` ; `FKC_LoisirsAbonnement::fiche()`, `renouveler()`, `moisEntre()`, `aRelancer()`, `messageRelance()`, `noterRelance()` ; actions `renouveler`, `relance` ; carte imprimable `?carte=` (QR) dans `loisirs::abonnements`.
- Boulangerie : `FKC_BlBoutique::prevision()` ; vue `boulangerie::boutique` (`?prevision=`).

Tests : `tests/hrl_recommandations_1861.php` — 31 vérifications.

---

# Patch 1.860.0 — Restauration, Hôtellerie & Loisirs : socle commun et sept packs

Base : 1.859.0. Rapport : `AUDIT_HRL_1860.md`.

- `FKC_HospitalityPayment::ctxClient()` ; `FKC_HospitalityFolio::ctxClientDe()`, `FKC_HotelFolio::ctxClientDe()` ; `FKC_HospitalityAnnulation` (rembourser / emettreAvoir / consommerAvoir : client) ; schémas `hotel` et `loisirs` : lignes 411 en `{compte_client}` + tiers.
- Brique verticale : `vp_pieces.moyen`, `FKC_VpPiece::encaisse()`, contexte `moyen` ; `vertical/piece_form.php` (« Encaissé par »).
- Caches `ensure()` par base : `FKC_HospitalityBooking`, `Folio`, `Order`, `FKC_PosCommande`, `PosFidelite`, `PosFiscal`, `PosPromotions`, `PosVariantes`, modèles des packs restaurant, bar, boulangerie, hotel, loisirs.
- `FKC_StockCompta::comptabiliserReception()` ; `FKC_RestoCuisine::approvisionner()` (contrôleurs restaurant, maquis, fastfood ; vue `restaurant::cuisine`) ; `FKC_BarCatalogue::ajusterComptabilise()`.
- Hôtel : nature `taxe_sejour`, schémas `hotel_taxe_sejour_validee` et `hotel_commission_ota`, `FKC_HotelReservation::taxeSejourNuit()`, `definirTaxeSejour()`, `comptabiliserCommission()`, colonnes `commission_montant`, `commission_ecriture_id`, `nuits()` calendaire ; vue `hotel::tarifs`.
- Loisirs : `FKC_LoisirsAbonnement` (`Packs/loisirs/Models/Abonnements.php`, tables `ls_abonnements`, `ls_abonnement_durees`, `ls_passages`, `ls_pca`, schéma `loisirs_pca_abonnements`) ; routes `loisirs/abonnements` ; vue `loisirs::abonnements`.
- Boulangerie : table `bl_lignes_boutique`, `stockVendable()`, `annulerCommande()`, vente FIFO hors lots périmés, sortie à l'encaissement.
- Restaurant : `FKC_RestoReservation::reserver()` refuse un créneau passé.
- CSS : kit `fk-*` ; 16 vues passées aux grilles adaptatives. Test `studio_session.php` : réinitialisation du cache par tableau.

Tests : `tests/hrl_1860.php` — 26 vérifications.

---

# Patch 1.859.0 — Santé : recommandations de l'audit 1.858.0

Base : 1.858.0. Rapport : `AUDIT_SANTE_1858.md` (§ 6).

- Brique verticale : `vp_pieces.article_id`, `qte_article`, `stock_mouvement_id` ; `FKC_VpPiece::create()` (type `'stock' => true`, article + quantité), `FKC_VpPiece::sortirArticle()` appelée à la comptabilisation ; `vertical/piece_form.php` (article délivré).
- Packs `clinique`, `optique` : bloc `stock` du manifeste, schémas `{pack}_entree_stock` / `{pack}_vente` ; `optique` : examen TVA 0 par défaut.
- Nouveau `FKC_SanteTiersPayant` (`Modules/Sante/Models/TiersPayant.php`) : tables `sante_conventions`, `sante_pec`, `sante_rejets` ; `enregistrerConvention()`, `convention()`, `appliquer()`, `consomme()`, `inscrire()`, `assureurs()`, `soldeAssureur()`, `bordereau()`, `rejeter()` ; schémas `sante_rejet_pec_patient` / `sante_rejet_pec_perte` (origine reglement). `FKC_SanteFacturation::enrichir()` applique la convention et inscrit la prise en charge ; `clientPayeur( $nom, $compte )`.
- `FKC_SanteDossier` : `MARQUES`, `INTERACTIONS`, `molecules()`, `verifierInteractions()`, `tracer()`, `acces()`, table `sante_acces`. Vue `sante::ordonnance`.
- `FKC_SantePlanning` : colonnes `duree`, `telephone`, `rappel_at` ; chevauchements ; `jour( $date, $praticienId )`, `absent()`, `tauxAbsence()`, `rappels()`, `messageRappel()`, `marquerRappel()`. `FKC_SantePraticien` : `duree_creneau`.
- `FKC_SanteHospit` : tables `sante_services`, `sante_sejour_factures`, colonne `facture_jusqu` ; `facturerPeriode()`, `facturerIntermediaire()`, `transferer()`, `forfaitService()`, `enregistrerForfait()`, `services()`, `facturesSejour()` ; `sortir()` facture le reste.
- `FKC_PharmaOfficine::tpAReclasser()`, `reclasserTp()` (table `ph_tp_reclassements`, schéma `pharmacie_reclassement_tp`).
- Routes : `{pack}/assurances` (GET/POST), `{pack}/dme/{id}/ordonnance/{cid}`, `{pack}/planning/rdv/{id}/{absent|rappel}`, `{pack}/hospitalisation/sejour/{id}/{facturer|transferer}`, `{pack}/hospitalisation/forfait` ; écrans santé : `assurances` pour les 5 packs de soins, DME pour le cabinet médical.
- Vues : `sante::assurances` (nouvelle), `planning`, `praticiens`, `hospitalisation`, `dossier`, `_santenav`, `pharmacie::comptoir` ; CSS impression.
- Indicateur commun « RDV non honorés (90 j) ».

Tests : `tests/sante_recommandations_1859.php` — 42 vérifications.

---

# Patch 1.858.0 — Santé : socle commun et six packs

Base : 1.857.0. Rapport : `AUDIT_SANTE_1858.md`.

- `app/index.php` : routes santé `auth` + `societe` ; DME sous `{pack}/dme`, `{pack}/dme/creer`, `{pack}/dme/{id}`, `POST {pack}/dme/{id}/facturation` ; `/dossiers` rendu au moteur vertical pour clinique/hôpital ; `POST {pack}/praticiens/retrocessions`, `POST {pack}/praticiens/{id}`.
- Nouveau `FKC_SanteFacturation` (`Modules/Sante/Models/Facturation.php`) : `clientPatient()`, `clientPayeur()`, `repartition()`, `enrichir()` (hook `FKC_VpPiece::onContexte`), `dossierPatient()`, `emettrePiece()`, `encours()`, `indicateurs()`, `comptabiliserRetrocessions()`, schéma `sante_retrocession` (origine achat).
- `FKC_Posting` : `'tiers' => 'payeur'` (`tiersLigne()`) ; `FKC_VpPiece::contexte()` : `compte_client`, `part_patient`, `part_assurance`.
- Schémas `cabinet_medical`, `clinique`, `hopital`, `laboratoire`, `optique` : part patient `{compte_client}` + tiers, part assurance `{compte_payeur}` + payeur ; `pharmacie_encaissement_validee` : trésorerie / 707410. Manifestes : champ `taux_pec`. `FKC_PlanMetier` : 401140, 411115/411116/411118/632460 complétés.
- `FKC_SantePlanning` : `typeConsultation()`, `create()` (créneau, heure, praticien actif), `facturer()`, `periode()`, compteurs `actifs`/`facture`. `FKC_SanteHospit` : `admettre()`, `sortir()`, `creerLit()`, `sortiesRecentes()`, `dms()`. `FKC_SanteDossier` : `nextCode()`, `dossierFacturation()`, `verifierAllergie()` (familles), contrôles de patient, colonne `dossier_id`. `FKC_SantePraticien` : colonne `fournisseur_id`, `constate` au bordereau. Caches `ensure()` par base.
- `FKC_SanteController` : planning (semaine), `praticienMaj()`, `retrocessionsComptabiliser()`, `dossierFacturation()`, redirections `/dme`.
- Pharmacie : `FKC_PharmaOfficine` (cache par base, `entrerLot()` validé, créances TP encaissées seulement, tiers au règlement TP) ; `FKC_Pos` : organisme de tiers-payant en client.
- Hooks des 5 packs de soins : indicateurs communs de créances ; libellés « Pièces à valider » ; alerte « dossiers sans facturation » retirée (cabinet, laboratoire) ; liens DME.
- Vues : `sante::_santenav`, `planning`, `praticiens`, `hospitalisation`, `dossiers`, `dossier` ; `vertical/dashboard.php`, `dossier_show.php` (Encaisser), `piece_form.php` (tiers payant) ; CSS `sa-*`. `FKC_UxIntentions` : Patients → `/dme` d'abord.

Tests : `tests/sante_1858.php` — 89 vérifications.

---

# Patch 1.857.0 — Commerce & Distribution : socle commun et dix packs

Base : 1.856.0. Rapport : `AUDIT_COMMERCE_DISTRIBUTION_1857.md`.

- `FKC_VpStock` : `comptesStockPack()`, `constaterCout()`, `coutUnitaireSortie()`, `rapprochementComptable()` ; colonnes `vp_mouvements.cout`, `cout_ecriture_id` ; `vente()` / `venteCredit( …, $depotId )` / retours (coût, moyen d'origine, pas d'écriture sans vente comptabilisée) ; `compteVenteFor()` évalue les conditions ; `kpi()`.
- `FKC_VpCommande` : schémas communs `vp_acompte` / `vp_acompte_imputation`, `evtAcompte()`, `totalTtc()`, colonne `montant_impute`, imputation partielle, client et moyen transmis ; livraison → coût des ventes ; encours net des acomptes.
- `FKC_VpStockPilotage` : `HORS_VENTE`, `caPeriode()`, CA et marge nets des retours, `serieJournaliere()` indexée par date, top articles et rayons.
- `FKC_VpPiece::contexte()` : client du dossier sur les lignes « tiers » ; `FKC_VpApresVente` (SAV sous garantie sans schéma) et `FKC_VpLot::chute()` : coût constaté.
- `FKC_StockCompta` : compte d'écart par défaut 658800 (et non 6582 « Dons »).
- `FKC_Brouillard` : `vp_mouvements` raccordée (`ecriture_id`, `cout_ecriture_id`).
- `FKC_ModelesEcritures` : Distribution 706127, Station 706144, Supérette 701140, Téléphonie 706143 / 706147.
- Packs : `stationservice` et `telephonie` (écritures) ; `distribution` (écritures, `FKC_DistDepot::ajusterStock` avec nature, `FKC_DistTournee::livrer`, contrôleur, vue dépôts) ; `retail` (schémas de régularisation, `corrigerStock`, cockpit) ; manifestes `cosmetiques`, `superette`, `quincaillerie`, `telephonie`.
- Vues : `vertical/dashboard.php` (bande Ventes & stock), `vertical/stock.php` (rapprochement), CSS global (grilles en ligne).
- Tests adaptés : `commerce_vague2_bout_en_bout.php` (capacités déclarées), `commerce_tarif_baz003.php` (pack sans déclaration), `valorisation.php` (6588).

Tests : `tests/commerce_distribution_1857.php` — 45 vérifications.

---

# Patch 1.856.0 — Immobilisations : recommandations de l'audit 1.855.0

Base : 1.855.0. Rapport : `AUDIT_IMMOBILISATIONS_1855.md` (§ 5).

- `FKC_Immobilisation` : moteur de plan (`planTheorique()` → `planLineaire()`, `planDegressif()`, `planUnites()`, `rebaser()`), `dateDepart()`, `prorataPremier()`, `coefDegressif()`, `methodes()` ; schéma `assurerSchemaAvance()` (colonnes `parent_id`, `date_mise_en_service`, `unites_total`, `coef_degressif`, `compte_depreciation` ; tables `immo_depreciations`, `immo_unites`, `immo_reevaluations`) ; `deprecier()`, `cumulDeprecie()`, `depreciations()`, `numerosDepreciation()` ; `saisirUnites()`, `unites()` ; `ventilerComposant()`, `composants()` ; `reevaluer()`, `reevaluations()` ; `estEnCours()`, `mettreEnService()` ; `brancher()`, `surAcquisitionDetectee()` ; `create()` (méthode et colonnes avancées), `vnc()` (dépréciation), `ceder()` (reprise du 29x), `rapprochement()` (nature `dep`), `synthese()` (`deprecie`, `en_cours`), `dotationComplementaire()` (date de mise en service), `blocages()`.
- `FKC_Brouillard` : `immo_depreciations` raccordée.
- `FKC_EtatsDgi::tableauAmortissementsRegistre()` ; note 2 proposée (modes réels).
- `FKC_ImmoController` : `deprecier()`, `unites()`, `composant()`, `reevaluer()`, `miseEnService()` ; `erreursFiche()` (méthode, mise en service). Routes `POST comptabilite/immobilisations/{id}/{deprecier|unites|composant|reevaluer|mise-en-service}`.
- `FKC_EtatsDgiController` : note 3C registre (écran des notes, CSV).
- `app/index.php` : `FKC_Immobilisation::brancher()` avant `FKC_Packs::boot()`.
- Vues : `immobilisations/_avance.php` (nouvelle), `show.php`, `form.php`, `index.php` ; `etats_dgi/index.php`.

Tests : `tests/immobilisations_recommandations_1856.php` — 50 vérifications.

---

# Patch 1.855.0 — Immobilisations : bugs, ponts, design

Base : 1.854.0. Rapport : `AUDIT_IMMOBILISATIONS_1855.md`.

- `FKC_Immobilisation` : `nombre()`, `estAmortissable()`, `estEnService()`, `doteSurExercice()`, `planTheorique()`, `passerDotation()` (extrait de `comptabiliserDotation()`), `jours360()`, `dotationComplementaire()`, `numerosCession()`, `comptesCession()`, `rapprochement()`, `synthese()` ; `create()` (montants, biens non amortissables), `plan()` (non amortissable, arrêt à la cession), `vnc()` (0 hors service), `comptabiliserDotation()` (garde-fous), `ceder( $immo, $date, $prix, $tresId, $opts )` réécrite, `ecrituresImmobilisables()` filtrée.
- `FKC_Cloture::dotationsManquantes()` : montant comptabilisé contre annuité du plan.
- `FKC_ImmoController` : `exerciceCourant()`, `erreursFiche()`, filtre de statut, synthèse et rapprochement à l'index, aperçu de dotation complémentaire.
- `FKC_Rapport::registreImmobilisations()` (`vnc`, `date_cession`, `amortissable`) ; CSV du registre enrichi.
- `FKC_EtatsDgi::controle()` : information « registre ≠ bilan ».
- Vues : `immobilisations/index.php` (réécrite), `show.php`, `cession.php`, `import.php`, `form.php` ; `extractions/index.php` (registre).

Tests : `tests/immobilisations_1855.php` — 30 vérifications.

---

# Patch 1.854.0 — Liasse DGI & extractions : recommandations de l'audit 1.853.0

Base : 1.853.0. Rapport : `AUDIT_ETATS_EXTRACTIONS_1853.md` (§ 5).

- `FKC_EtatsDgi` : `declarerComptesLiasse()`, `correspondancesDeclarees()`, `ligneDeclaree()` ; mémoïsation `memo()` (clé `total_changes()`) de `bilan`, `resultat`, `bilanSN`, `sigBruts`, `resultatSN`, `fluxTresorerie`, `notes`, `codesLiasse`, `edi`, `controle`, `compCharges`, `synthese` (calcul dans `…Calcul()`) ; dépôt : `ensureDepots()`, `empreinte()`, `deposer()`, `depots()`, `depot()` ; `rapprochementFiscal()` ; `controle()` enrichi (`fiscal`, `depot`, rejets de déclarations, message 130).
- `FKC_SoldeOuverture` : `complementCalcule()` reporte le résultat N-1 non déterminé en 130 ; `resultatNonDetermine()`, `compteInstance()`.
- Packs : `Packs/ong/pack.php` (clé `liasse`) ; `FKC_LabelComptable::lignesLiasse()`, 709000 → 706095 ; `FKC_PlanSycebnl::lignesLiasse()`.
- `FKC_Reclassement` : `remplacements()` (709000, conditionnel au libellé — `si_libelle`) distinct du catalogue des regroupements, `entrees()`, `normaliserLibelle()` ; `FKC_Ecriture::comptesDeRegroupement()` respecte la condition et renvoie un message dédié.
- `FKC_Imputation` : `$asOf` sur `parFacture()`, `facturesClient()`, `postesClient()`, `postesFournisseur()` ; `FKC_Reglement::soldeClient( $id, $asOf )`, `FKC_Fournisseur::totalPaye( $id, $asOf )`.
- `FKC_Rapport::baliseTiers()` réécrit sur `FKC_Imputation` (champs `id`, `impute`, `credit`, `nb_ouvertes`).
- `FKC_EtatsDgiController` : calcul par onglet, `deposer()` (route `POST comptabilite/etats-dgi/depot`). Vues : badge et bloc de dépôt (`etats_dgi/index.php`), balance âgée (`extractions/index.php`), styles (`_etats_style.php`).

Tests : `tests/liasse_recommandations_1854.php` — 37 vérifications ; `tests/etats_extractions_1853.php` adapté (35).

---

# Patch 1.853.0 — États financiers, liasse DGI, extractions : bugs, ponts, design

Base : 1.852.0. Rapport : `AUDIT_ETATS_EXTRACTIONS_1853.md`.

- `FKC_EtatsDgi` : `replisResultat()`, `ligneResultat($n, &$repli)`, `gestionNonVentiles()`, `synthese()` ; `sigBruts()` et `resultat()` routés compte par compte ; `compCharges()` réécrit (même carte, même rattachement que le SN) ; `bilan()` sur `soldesClasses()` ; `controle()` nomme les comptes de gestion en repli ; `avecComplement()` / `detailAvecComplement()` supprimés.
- `FKC_EtatsDgiController` : `systemeDossier()`, `definirSysteme()` (route `POST comptabilite/etats-dgi/systeme`), barrage EDI, `quoi` contrôlé, `synthese` transmise.
- `FKC_EtatsFinanciersController` : SMT / SN, synthèse, flash.
- `FKC_ExtractionController` : période = exercice, dates validées, `sousTotauxClasses()`, `collectif()`, réserve « journal temporaire » dans les CSV, `slug()` translittéré.
- `FKC_Rapport` : `FACTURES_COMPTABILISEES` ; balance âgée bornée à l'arrêté (échéance client) ; balance auxiliaire (avoirs au crédit, `id` du tiers).
- Vues : `etats_financiers/index.php` (réécrite), `etats_dgi/index.php` (CSRF, KPI, défilement), `etats_dgi/print.php`, `extractions/index.php`, `_etats_controle.php` ; nouveaux `_etats_style.php`, `_etats_kpi.php`.

Tests : `tests/etats_extractions_1853.php` — 34 vérifications.

---

# Patch 1.852.0 — Fiscalité : calculs de TVA, cycle de vie, ponts, synthèse

Base : 1.851.0. Rapport : `AUDIT_FISCALITE_1852.md`.

- `FKC_Fiscalite` : `sqlHorsDeclarationTva()`, `prochaineEcheance()`, `caAnnualise()`, `calendrier()` ; `reportTva()` par compte ; `synthese()` : patente au total, `echeance_periode`, `ca_annualise`.
- `FKC_DeclarationFiscale::soldesTvaParCompte()` hors écritures de déclaration.
- `FKC_Declaration` : règlement retenu à la comptabilisation « et payer » (y compris via le brouillard) ; `payer()` exige une dette comptabilisée ; pénalités figées une fois payée.
- `FKC_FiscaliteController` : période par défaut = exercice, dates/mois/type contrôlés, montants lus par `FKC_FiscalConfig::nombre()`, date d'écriture de l'IS à la clôture.
- Vues : `dashboard.php` (refonte), `declaration_show.php`, `declarations_journal.php`, `declarations.php`, `regime.php` ; styles « Fiscalité » dans `app.css`.
- Ponts : `FKC_CockpitData::echeancesFiscales()` lit le moteur fiscal (repli `echeancesFiscalesRepli()`) ; `FKC_Fie_Knowledge` : `tva_echeance_jour` suit le paramétrage Fiscalité.

Tests : `tests/fiscalite_ponts_1852.php` — 23 vérifications.

---

# Patch 1.851.0 — Cockpit : audit complet, ponts packs, affichage

Base : 1.850.0. Rapport : `AUDIT_COCKPIT_1851.md`.

- Registre : origine des déclarations de packs (`FKC_Cockpit::origine()`, `_pack`), `addSection()` idempotent, rôles stock → cockpit Stock. `FKC_Packs::boot()` ouvre et ferme la fenêtre d'origine.
- Nouveau `Core/CockpitPrefs.php` (`FKC_CockpitPrefs`, table `ckp_prefs`) ; route `POST cockpit/preferences` (`FKC_DashboardController::cockpitPreferences`).
- `FKC_CockpitData` : `seuilsDefaut()`, `seuil()` (via `FKC_Fie_Knowledge::valeurSeuil`), corrections d'autonomie, rotations, consolidation, ventes, TVA, DSO.
- `FKC_CockpitTemplates` : `rolesMetier()`, correction du filtre `sens`, pertes au CUMP, filtre des automatisations.
- `FKC_Chart` : options `projete` (line) et `max_libelles` (bars, line, combo).
- Vues : `_cockpitsection.php`, `cockpit.php`, `_cockpitnav.php`, `cockpit_dossier.php`, blocs `dashboard/*` ; Retail (`Models/Cockpit.php`, `Views/cockpit.php`) ; Cabinet (`Models/Rentabilite.php`, `Views/cockpit.php`).
- `DashboardController::stats()` et `scalar()` supprimés (code mort).

Tests : `tests/cockpit_ponts_1851.php` — 43 vérifications.

---

# Patch 1.843.4 — Paie, lot P4 : fin d'année et pilotage

Base : 1.843.3.

- `FKC_Bulletin::cumuls()` ; cumuls affichés dans `bulletins/show.php` et `bulletins/print.php`.
- `FKC_PaieDeclaration::annuel($annee)` (par salarié : brut, imposable, ITS, contribution, base et cotisations CNPS, CMU, FDFP, net, coût) et `masseParMois($annee)` (paramètre `paie_budget_{annee}`).
- Nouveau `Controllers/AnnuelController.php` (`index`, `budget`, `csv`, `imprimer`) ; vues `annuel.php`, `annuel_print.php` ; routes `paie/annuel`, `paie/annuel/budget`, `paie/annuel/csv`, `paie/annuel/imprimer` ; entrée « Récapitulatif annuel » au lanceur Paie.
- `FKC_PaieParams::conformite($annee, $p)` ; bandeau des écarts dans `parametres.php`. Maestro Paie : source `paie_reglementation` (référence), fait `paie.ecarts_bareme`, pattern `paie_bareme_non_conforme`.
- Aide : article « Fin d'année : État 301, DISA et budget de masse salariale ».

Tests : `tests/paie_annuel_1843_4.php` — 21 vérifications.

---

# Patch 1.843.3 — Paie, lot P3 : ponts RH et paiement des salaires

Base : 1.843.2.

- Nouveau `Modules/Paie/Models/Paiement.php` (`FKC_PaiePaiement` : `bulletins()`, `etat()`, `periodes()`, `payer()`, `nettoyer()`) et `Controllers/PaiementController.php` (`index`, `payer`, `virement`, `csv`) ; vues `paiements.php`, `paiements_virement.php` ; routes `paie/paiements`, `paie/paiements/virement`, `paie/paiements/csv` ; entrée « Paiement des salaires » au lanceur Paie. Table `paie_paiements`, colonne `paie_bulletins.paiement_id` (schéma 29) ; `FKC_Brouillard::LIENS`, `FKC_EcritureLiens` (action `supprimer`), `FKC_Orphelins`.
- `FKC_RhPointage::heuresSupp()` (heures par semaine ISO, durée hebdomadaire et tranche +15 % paramétrables). `FKC_BulletinController::generer()` : extras `hs_pointage`, `periode`, `jours_conge`, `conge_moyenne` ; cases dans `bulletins/form.php` et `bulletins/index.php`.
- `FKC_PaieParams` : `anciennete_*`, `conges_majoration`, `conges_allocation`, `conges_jours_ouvrables_mois`, `hs_duree_hebdo`, `hs_tranche_15`, `conges_acquis_an` 26,4 (reprise de l'ancien 26) ; `tauxAnciennete()`, `majorationConges()`. `FKC_Employe::ancienneteAnnees()`. `FKC_Absence::joursPeriode()`.
- `FKC_Bulletin::calcul()` : prime d'ancienneté automatique (plafond 25 %) ; lignes `CONGE_ABS` et `ALLOC_CONGE` sur option. `FKC_Conge::solde()` : majoration d'ancienneté. `FKC_RhConge::soldes()` : défaut 26,4.
- Barèmes : sections Prime d'ancienneté, Congés payés, Heures supplémentaires. Aide : article « Payer les salaires et préparer le virement ».

Tests : `tests/paie_ponts_rh_1843_3.php` — 31 vérifications.

---

# Patch 1.843.2 — Paie, lot P2 : OD ventilée, 663, état nominatif

Base : 1.843.1.

- `FKC_Bulletin::save()` : enregistre `division_id`, `projet_valeur_id`, `parts`, `expatrie` (schéma 28). Vues `bulletins/show.php` et `print.php` : parts lues sur le bulletin.
- `FKC_OdPaie` : `chargesParAffectation($periode, $divisionDefaut)`, `libAffectation()`, `indemnites663()`, `axeProjet()` ; `lignes($periode, $divisionDefaut)` ventile les charges par division|projet (clé `ventile`, champ `affectation`) ; comptes `ind_transport` (663400) et `ind_logement` (663100) ; `comptabiliser()` pose `division_id` et `axe_id`/`valeur_id` (axe PROJ) sur les lignes de charges.
- `FKC_OdPaieController::reglages()` + route `POST paie/od/reglages` (option `od_paie_indemnites_663`, `od_paie_division_id`). Vue `od/index.php` : colonne « Affectation », carte « Réglages de l'OD de paie ».
- `FKC_PaieDeclaration` : `contribution($periode)` (local / expatrié), `nominatif($periode)` ; `data()['contrib']`. `FKC_PaieDeclarationController::nominatif()` + route `GET paie/declarations/nominatif` (CSV). Vues `declarations.php` et `declarations_print.php` : ventilation et état nominatif.

- `declarations.php` : tableaux à deux colonnes sans largeur minimale (montants visibles sur téléphone).

Tests : `tests/paie_od_analytique_1843_2.php` — 24 vérifications.

---

# Patch 1.843.1 — Paie, lot P1 : moteur fiscal 2024

Base : 1.843.0.

- `FKC_PaieParams` : `defaults($annee)` selon l'année (réforme à partir de 2024) ; `baremeIts2024()`, `ricf2024()`, `baremeItsAncien()` ; clés `its_methode` (`mensuel_ricf` | `quotient_annuel`), `its_ricf`, `parts_max`, `contrib_employeur_actif`, `contrib_employeur_taux_local` (2,8 %), `contrib_employeur_taux_expat` (12 %), `cmu_part_patronale` (500) ; `reprendre($p, $annee)` bascule l'ancien défaut figé et signale un barème modifié ; `memeBareme()`, `methodeIts()`, `partsRetenues()`, `ricf()`, `pct()`.
- `FKC_Employe` : colonnes `situation_familiale`, `enfants_charge`, `enfants_infirmes`, `expatrie` ; `situations()`, `partsCalculees()`, `partsFiscales()` ; les parts enregistrées suivent la situation (plafond 5).
- `FKC_Bulletin::calcul()` : `itsDetail()` (barème mensuel − RICF) ; lignes `CMU_PAT` et `CONTRIB_EMP` ; assiette CNPS unique ; libellés depuis les paramètres ; `codesReserves()`, `codeRubrique()` (aussi appliqué par `FKC_EmployeRubrique::parsePost()`).
- `FKC_OdPaie` : compte `contrib_pat` (`od_paie_cpt_contrib_pat`, 641300) ; agrégats `cmu_pat`, `contrib_pat` ; 664 += CMU patronale, 431 += CMU patronale, 447 += contribution.
- `FKC_PaieDeclaration::data()` : `cmu_sal`, `cmu_pat`, `contrib_employeur`, `impots_retenus` ; `cmu` et `impots_total` incluent les parts employeur. `FKC_DeclarationFiscale::apercu()` détaille la composition.
- `FKC_PaieParamController` : méthode ITS, RICF, contribution, CMU patronale ; `controleBareme()` (chevauchement, trou, tranche ouverte). Vues `parametres.php`, `employes/form.php`, `declarations*.php`, `od/index.php` ; aide `regime-its` réécrite.
- `FKC_DB::SCHEMA_VERSION` 27.

Tests : `tests/paie_its2024_1843_1.php` — 56 vérifications. `tests/paie_rh_1561.php` adapté (défaut légal par année ; les interrupteurs sont éprouvés sur l'ancien régime) — 169 vérifications.

---

# Patch 1.841.6 — Recouvrement : vérification et correction des ponts

Base : 1.841.5.

- `FKC_Brouillard::valider()` : renseigne `vp_pieces.ecriture_id` pour une écriture de référence `vp.piece#<id>`.
- `FKC_RecouvrementSources` : source `pieces` lue par `ecriture_id` ou par référence ; `imputationsExternes()` + source `redevances` (gestion collective) ; `routeEncaissement()` (`encaisser` : cotisations, scolarité, loyers) ; `noterErreur()` ignore les tables absentes.
- `FKC_Recouvrement::compute()` : imputations externes appliquées aux factures sans puiser dans le pool ; acomptes de commande (`recus.order_id` → `svc_orders.facture_id`) comptés dans l'encaissé et imputés à la facture.
- `FKC_Maestro_Recouvrement` : `delaisParClient()` au périmètre du moteur ; `paiementsDepuis()` (règlements + reçus) utilisé aussi par `efficaciteCanaux()`.
- `FKC_Cotisation` : `relancer()` reporte au journal `recouvrement_actions` ; `soldeMembre()['non_lettre']` calculé par le moteur.
- Vues : `comptabilite/clients/index.php` (encaissement des sources), `recouvrement/sources.php` (sources vides masquées), `cabinet/recouvrement.php` (lien « Relancer »).

Tests : `tests/recouvrement_ponts_1841_6.php` — 39 contrôles (12 échouent sur 1.841.5). Suites existantes : 359 fichiers verts.

---

# Patch 1.841.5 — Recouvrement, lot R4 : pilotage

Base : 1.841.4.

- Nouveau `Modules/Recouvrement/Models/Pilotage.php` (`FKC_RecouvrementPilotage`) : `ensure()` (table `recouvrement_photos`), `facture()`, `encaisse()`, `dsoGlissant()`, `photographier()`, `historique()`, `cei()`, `promesses()`, `agents()`.
- `FKC_RecouvrementController` : `pilotage()` ; `dashboard()` photographie le mois. Vue `pilotage.php` (indicateurs, graphique empilé retard / non échu, historique, équipe) ; lien « Tendances » sur `dashboard.php`.
- `index.php` : chargement de `Pilotage.php`, route `recouvrement/pilotage`. `Launcher` : entrée « Pilotage ». `FKC_BPEWorker::traiterToutesSocietes()` : photo mensuelle de chaque société où le module Recouvrement est actif.

Tests : `tests/recouvrement_1841_5.php` — 17 contrôles. Suites existantes : 358 fichiers verts.

---

# Patch 1.841.4 — Recouvrement, lot R3 : paiement, pénalités, envoi réel, précontentieux, vocabulaire

Base : 1.841.3.

- `Politiques.php` : nouvelle classe `FKC_RecouvrementReglages` — `operateurs()`, `paiement()`, `penalites()`, `definirPaiement()`, `definirPenalites()`, `calculerPenalites()`, `lienPaiement()`, `blocPaiement()`, `blocPenalites()` (paramètres `recouv_paiement`, `recouv_penalites`).
- `FKC_RecouvrementSuivi` : `modeleMessage()` / `modeleReleve()` enrichis via `enrichir()` (textes d'origine : `modeleMessageBrut()`, `modeleReleveBrut()`) ; `apresMiseEnDemeure()`.
- `FKC_RecouvrementController` : `fileDeRelance()` (extraite de `relances()`), `relanceEnvoyer()`, `relanceEnvoyerTout()`, `envoyerReleve()` ; `relanceLog()` appelle `apresMiseEnDemeure()` ; `miseEnDemeure()` passe `penalites` et `paiement`.
- `FKC_PolitiqueController` : `paiement()`, `penalites()` (administrateur).
- `FKC_Recouvrement::motDebiteur()` ; libellés dans `dashboard.php`, `clients.php`, `agee.php`, `centre.php`, `fiche.php`, Launcher.
- `index.php` : routes `recouvrement/relances/envoyer`, `recouvrement/relances/envoyer-tout`, `recouvrement/politiques/paiement`, `recouvrement/politiques/penalites`.
- Vues : `relances.php` (envoi direct, précontentieux), `politiques.php` (moyens de paiement, pénalités), `mise_en_demeure.php` (pénalités, paiement).

Tests : `tests/recouvrement_1841_4.php` — 27 contrôles. Suites existantes : 357 fichiers verts.

---

# Patch 1.841.3 — Recouvrement, fin du lot R2 : relevé groupé, promesses constatées, contrôle d'encours

Base : 1.841.2.

- `FKC_RecouvrementSuivi` : `modeleReleve()` ; `ensurePromesses()` (colonnes `reste_initial`, `constate_le` de `recouvrement_promesses`) ; `ajouterPromesse()` mémorise le reste dû ; `actualiserPromesses()`, `encaisseDepuis()`, `gracePromesse()` (paramètre `recouv_promesse_grace`).
- `FKC_RecouvrementController` : `relances()` regroupe par client (`lignes`, `references`, `montant` total) ; `constaterPromesses()` appelé par `dashboard()`, `centre()`, `fiche()`, `promesses()`, `relances()`. Vue `relances.php` : tableau des pièces du relevé.
- `FKC_FactureController` : `avisEncours()`, `controleEncours()` appelés par `comptabiliser()` et `certify()` (champ `forcer_encours`, trace `FKC_Security::audit( 'encours_force' )` et action de recouvrement) ; `show()` passe `avisEncours`. Vue `factures/show.php` : bandeau et case « passer outre ».
- `FKC_ServiceOrder::facturer()` : avertissement d'encours ou de contentieux.

Tests : `tests/recouvrement_1841_3.php` — 21 contrôles. Suites existantes : 356 fichiers verts.

---

# Patch 1.841.2 — Recouvrement, lot R2 : registre des sources de créances

Base : 1.841.1.

- Nouveau `Modules/Recouvrement/Models/Sources.php` (`FKC_RecouvrementSources`) : `register()`, `all()`, `get()`, `creances()`, `consommeParClient()`, `document()`, `court()`, `feminin()`, `tiers()` (fiche client miroir, table `recouvrement_tiers`), `correspondances()`, `lier()` (table `recouvrement_liens`), `rattacherTout()`, `synthese()`, `erreurs()`. Sources livrées : `cotisations`, `pieces`, `scolarite`, `loyers` ; fonctions `fkc_recouv_cle_ecole()`, `fkc_recouv_cle_bail()`.
- `FKC_Recouvrement::compute()` : créances des sources fusionnées au lettrage par date ; payé nommé des sources ; pool diminué des règlements/reçus consommés ; lignes `source`, `source_ref`, `tiers_nom` ; KPI `origines`. `anomalies()` : `source_sans_tiers_<code>`.
- `FKC_RecouvrementSuivi` : `contactesAujourdhui()` ; `modeleMessage()` nomme la créance selon sa source et accorde les participes.
- `FKC_RecouvrementController` : `sources()`, `sourcesRattacher()` ; `relances()` écarte les clients déjà contactés aujourd'hui. Vue `sources.php` ; libellés de source dans `centre.php`, `relances.php`, `fiche.php`, `mise_en_demeure.php`.
- `index.php` : chargement de `Sources.php`, routes `recouvrement/sources` et `recouvrement/sources/rattacher`. `Launcher` : entrée « Sources de créances ».
- `FKC_EcoleScolarite::encaisser()` et `FKC_ImmoGerance::encaisserQuittance()` : fiche client miroir, `client_id` passé au Centre d'Encaissement, reçu lié.
- `FKC_ServiceRelance` : `relancable()` refuse un client suspendu ; `journaliser( …, forcer )` refuse un client en contentieux.
- `FKC_Depreciation::candidats()` : lignes sans facture écartées.

Tests : `tests/recouvrement_sources_1841_2.php` — 27 contrôles. Suites existantes : 355 fichiers verts.

---

# Patch 1.841.1 — Recouvrement, lot R1 : exactitude du poste client et droits

Base : 1.841.0.

- `FKC_Imputation` : nouveau `avoirsAffectes()` (avoir → facture d'origine, surplus libre) utilisé par `postesClient()` (ligne `avoir`, `regle` l'inclut) ; `parFacture()` ne retient plus que les reçus qui touchent le 411 (paiement, comptant, remboursement, acompte/avance rapprochés).
- `FKC_Recouvrement::compute()` : avoirs affectés à leur origine ; acomptes rattachés non rapprochés comptés dans l'encaissé ET l'imputation ; créances en perte exclues de l'encours et de la file (`clients[].perte`, KPI `passe_en_perte`), créances provisionnées marquées `douteuse` ; suspension par facture pour les promesses qui en désignent une. Nouveaux `depreciationsParFacture()`, `promessesParFacture()`.
- `FKC_RecouvrementSuivi` : `ajouterPromesse()` rend l'id de la promesse ; `ajouterMois()` sans débordement pour `creerPlan()` ; `modeleMessage()` cite le numéro de facture.
- `FKC_RecouvrementController` : `fiche()` passe les factures ouvertes ; `promesse()` contrôle la facture et le montant ; `miseEnDemeure()` réclame l'échu (`echues`, `montant_echu`, `relances_prealables`).
- `FKC_PolitiqueController` : flash typé ok/err, seuil de montant enregistré seulement si les paliers sont valides, `peut_modifier`.
- `index.php` : POST `recouvrement/politiques/{relances,workflow,reinit}` avec `'admin' => true`.
- Vues : `mise_en_demeure.php` (réécrite), `fiche.php` (factures ouvertes, facture de la promesse), `dashboard.php` (perte), `centre.php`, `relances.php` (numéro), `politiques.php` (barre de navigation, consultation seule hors administrateur).

Tests : `tests/recouvrement_1841_1.php` — 33 contrôles. Suites existantes : 355 fichiers verts (verifier.php, agrégateur séquentiel, non relancé en entier faute de temps : il dépasse 10 min).

---

# Patch 1.838.0 — Alertes critiques par courriel

Base : 1.837.0.

- Nouveau `Core/Courriel.php` (`FKC_Courriel`) : `disponible()`, `adresseValide()`, `envoyer()` (wp_mail ou transport de remplacement `$transport`, crochet `wp_mail_failed`), journal `courriels_journal` (créée à la demande), `journal()`.
- `FKC_Alerte` : `destinatairesDetail()` (nom, adresse), `modesEmail()`, `modeEmail()` / `setModeEmail()` (paramètre `alertes_email`, défaut `critiques`), `parCourriel()`, `courriel()` (sujet, texte, HTML échappé), `envoyerCourriels()` appelé par `notifier()` au franchissement et au rappel.
- `AnalyseController` : `alertes()` passe réglage, destinataires, disponibilité et journal ; `alertesReglages()` et `alertesTest()` (administrateur). Routes `analyse/alertes/reglages`, `analyse/alertes/test`.
- Vue `alertes.php` : carte « Envoi par courriel ».
- `app.css` : sélecteur de société masqué dans la barre du haut sur téléphone.
- `index.php` : chargement de `Core/Courriel.php`.

Tests : `tests/alertes_courriel_1838.php` — 23 contrôles.

---

# Patch 1.837.0 — Analyse, lot 4 : Maestro, sécurité et performance

Base : 1.836.0 (caisse 1.835–1.836 d'une autre session, préservée ; fichiers communs : index.php, BPEWorker.php, layout.php, app.css).

- Nouveau `Core/Maestro/Acces.php` (`FKC_Maestro_Acces`) : `carte()` domaine → modules, `actif()`, `domaine()`, `predicat()`, `domaineDecision()`, `filtrerAnalyse()` (décisions, faits, dérivés, index, `masquees`), `proprietaire()`.
- `FKC_Maestro` : `analyser()` = `analyserComplet()` filtré par droits ; `observer()` sur l'analyse complète ; `noterSante()`, `activite()`, `sante()` (source unique) ; cache partagé `fieBrut()` (table `fie_cache`, signature `signatureDonnees()`, TTL 600 s, `$cachePartage`, `invaliderCache()`) ; garde de droits dans `expliquer()` et `prevoir()`.
- `FKC_Maestro_Action` : contrôle de domaine à la préparation ; propriétaire pour `executer()`, `abandonner()`, `dossier()`, `enAttente()` ; réservation atomique (`prepare` → `en_cours`) ; exception par cible capturée.
- `MaestroController` : `ouvert()`/`refus()` sur `pourquoi`, `backtest`, `simuler` ; `observer()` réservé et espacé (paramètre `maestro_observe_at`) ; 403 sur dossier d'autrui ; cockpit reçoit `sante_globale`, `masquees`.
- `FKC_Maestro_Dg::etat()` : santé nullable. `FKC_Assistant::briefing()` : score = `FKC_Maestro::sante()`.
- `FKC_Alerte` : colonnes `etat`, `notifie_le` (ajoutées à la demande), `destinataires()`, `notifier()`, `notifierSiDu()` ; appelés par `AnalyseController::index()/alertes()`, `UxController::chargeAlertes()`, `FKC_BPEWorker::traiterToutesSocietes()`.
- `FKC_Ecriture` : invalide le cache Maestro avec les soldes.
- Vues : `maestro.php` (santé, masqués, rendu `textContent` : `mTexte`, `mLignes`), `fie.php` (santé unique).
- `app.css` : grilles `minmax(min(100%,…))` pour les largeurs ≥ 300 px ; barre du haut téléphone. `layout.php` : bouton Déconnexion avec icône.
- Tests ajustés (changements voulus) : `tests/run.php` (grilles bornées), `tests/maestro_experts_1583.php` (santé présente mais nullable).

Tests : `tests/maestro_securite_1837.php` — 40 contrôles.

---

# Patch 1.834.0 — Analyse, lot 3 : le centre « Rapports »

Base : 1.833.0.

- Nouveau `Modules/Analyse/Models/Rapports.php` (`FKC_AnaRapports`) : `catalogue()`, `periodesRelatives()`, `resoudrePeriode()`, `accessible()`, `parGroupe()`, `autresEditions()`, `produire()` (+ `r_sig`, `r_resultat_mensuel`, `r_charges_nature`, `r_marge_axe`, `r_marge_article`, `r_ca_clients`, `r_balance_agee`), `formater()`, `csv()`, `cellule()`, `nomFichier()` ; rapports enregistrés : table `analyse_rapports` (créée à la demande), `enregistres()`, `trouver()`, `enregistrer()`, `supprimer()`, `lien()`.
- `AnalyseController` : `rapports()`, `rapportVoir()`, `rapportExport()`, `rapportEnregistrer()`, `rapportOuvrir()`, `rapportSupprimer()`. Routes `analyse/rapports`, `/voir`, `/export`, `/enregistrer`, `/{id}/ouvrir`, `/{id}/supprimer`.
- Vues `rapports.php` (centre) et `rapport.php` (écran + impression A4). `_periode.php` : paramètres cachés conservés (`caches`) et comparaison masquable (`sans_cmp`).
- `FKC_Metrics::sqlFacturesValides()` rendue publique (même filtre de factures pour le rapport CA par client).
- Intention « Rapports » : `analyse/rapports` avant `analyse` ; lanceur Analyse : entrée « Rapports ».

Tests : `tests/analyse_rapports_1834.php` — 32 contrôles.

---

# Patch 1.833.0 — Analyse, lot 2 : l'écran « Marge »

Base : 1.832.0.

- Nouveau `Modules/Analyse/Models/Marge.php` (`FKC_Marge`) : `definition()`, `valeur()`, `moinsUnAn()`, `sig()` (sur `FKC_EtatsDgi::sigBruts`, N et N-1, % CA, variation), `variation()`, `mensuel()`, `moisCourt()`, `lienGrandLivre()`, `analytiqueDisponible()`, `axes()`, `parAxe()` (sur `FKC_Affectation::resultatsParAxe`), `articles()` (caisse_lignes × caisse_tickets, coefficient de remise du ticket, retours).
- `AnalyseController::rentabilite()` : titre « Marge », données SIG, mensuel, axes, marge par axe (`?axe=`), articles (`?articles=tous`).
- Vue `rentabilite.php` réécrite.
- Grand livre : filtres `prefixes` (liste de préfixes) et `gestion` (hors écriture de détermination du résultat) dans `RapportController::glFilters()` et `FKC_Rapport::grandLivreGeneral()` ; bandeau dans `grand_livre.php`, filtres conservés (formulaire, export).
- Lanceur : « Marge — Soldes de gestion, marge par section et par article ».
- `index.php` : chargement de `Marge.php`.

Tests : `tests/analyse_marge_1833.php` — 32 contrôles.

---

# Patch 1.832.0 — Analyse (Marge · Rapports · Analyse), lot 1 : chiffres justes

Base : 1.831.0.

- `FKC_Metrics` : `bornes()` = exercice en cours (ou période d'une question Maestro), `exercice()`, `exerciceDe()`, `raccourcis()`, `horsCloture()`, mémo par requête invalidé par `total_changes()` ; `finance()` (clôture exclue, classe 8, `resultat_ao`, `hao_mois`), `rapprochement()` (SIG XI), `tresorerie()` (hors 59), `commercial()` (CA comptable 70, factures valides, repli), `topClients()` (par fiche), `rh()` (actifs, brut dernière paie), `budgetaire()` (mensuel + divisions par périmètre), `prevision()` (mois complets, étiquettes).
- `FKC_Warehouse` : `frais()`, `utiliseDepuis()` ; `data()` ne sert que l'instantané du jour.
- Vues : `_periode.php` (raccourcis d'exercice), `_rappro.php` (nouveau), `dashboard`, `financiere`, `rentabilite`, `commerciale`, `previsionnelle`, `rh`, `budgetaire` (réécrite), `warehouse`.
- Maestro : `Context::periode()` (exercice courant), `Context::periodeDemandee()`, `Conversation::periode()` (« cette année » = exercice), `Paie::ecartComptable()` (661-663, hors contre-passées et clôture), Experts Trésorerie (6 mois complets) et Analytique (exercice, hors clôture), Producers (charges 12 mois complets, `commercial.ca_mensuel_moyen`, poids des charges).
- Contrôleur : contrôles de licence sur `fie`, `fiePlaybook`, `kpiDelete`, `alerteDelete`.

Tests : `tests/analyse_lot1_1832.php` — 36 contrôles.

---

# Patch 1.831.0 — Analytique, lot 3 (fin) : budget mensuel par nature

Base : 1.830.0 (Scan 1.827–1.829 et caisse 1.830 d'une autre session, préservés ; fichiers communs : index.php, Launcher.php).

- Nouveau `Modules/Analytique/Models/BudgetMensuel.php` (`FKC_BudgetMensuel`) : table `ana_budget_mensuel` (créée à la demande), `natures()`, `moisExercice()`, `budget()`, `reel()` (ventilations comprises), `enregistrer()`, `copierN1()`, `saisonnalite()`, `suivi()`, `moisCourant()`, `reporterAnnuel()`, `csv()`.
- `FKC_AnaDetail` / `FKC_AnaCroise::detailCase()` : filtre `compte` (préfixe).
- Contrôleur : `budgetMensuel()`, `budgetMensuelSave()`, `budgetMensuelSuivi()`, `budgetMensuelExport()`. Routes `analytique/budget-mensuel*`. Vues `budget_mensuel.php`, `budget_mensuel_suivi.php`. Menu Budgets & contrôle.
- Correctif : liens `budgets-axe` / `controle-axe` sans préfixe `analytique/`.

Tests : `tests/analytique_budget_mensuel_1831.php` — 29 contrôles.

---

# Patch 1.829.0 — Audit des ponts Scan × modules × packs

Base : 1.828.0.

- Audit automatisé des 63 packs (résolveurs, contextes, abonnés, processus, navigation) : aucune exception ni incident.
- Liens morts corrigés (actions, intention, portes) + garde-fou de route.
- Caisse : registre avant pesée (EAN internes 20… à nouveau vendables), messages des codes non vendables.

Tests : `tests/scan_ponts_1829.php` (63 packs).

---

# Patch 1.828.0 — Scan Workspace

Base : 1.827.0.

- Écran unique de scan (FKC_ScanWorkspace + vue workspace) : scan libre avec intention, opération pilotée sans quitter l'écran, historique de l'opérateur, codes inconnus à rattacher.
- Clôt l'audit Scan & Identification (50 points, 4 phases).

Tests : `tests/scan_workspace_1828.php` — 18 vérifications.

---

# Patch 1.827.0 — Scan Engine 2.0, phase 4 lot 1

Base : 1.826.0 (travaux Terminaux 1.820 et Analytique 1.821–1.826 préservés).

- Équivalences GTIN (UPC-A / EAN-13 / GTIN-14) à clé valide.
- Assistant d'identification des codes inconnus (FKC_ScanIdentification) ; affectation sans vol de code.
- Étiquettes métier avec QR GS1 ; mode PDA (wedge clavier) ; API enrichie.

Tests : `tests/scan_assistant_1827.php` — 16 vérifications.

---

# Patch 1.826.0 — Analytique, lot 3 (2e partie) : tableau croisé sur deux axes

Base : 1.825.0.

- Nouveau `Modules/Analytique/Models/AnaCroise.php` (`FKC_AnaCroise`) : parts de chaque ligne par axe (affectation, ventilation, non affecté, valeur disparue → non affecté), `calculer()`, `colonnesAffichees()` (12 max, « Autres »), `detailCase()`, `csv()`.
- `FKC_AnaDetail::csvDepuis()` (export d'un détail déjà calculé).
- Contrôleur : `croise()`, `croiseExport()` ; `detail()` / `detailExport()` acceptent `axe2` / `valeur2`. Routes `analytique/croise`, `analytique/croise/export`. Vue `croise.php` ; `detail.php` gère la case croisée. Menu : Résultats & divisions › Tableau croisé.

Tests : `tests/analytique_croise_1826.php` — 19 contrôles.

---

# Patch 1.825.0 — Analytique, lot 3 (1re partie) : détail des montants, exports CSV

Base : 1.824.0.

- Nouveau `Modules/Analytique/Models/AnaDetail.php` (`FKC_AnaDetail`) : `lignes()` (affectations, ventilations, non imputé = reste après ventilation ; filtres nature/mois ; borne d'affichage), `csvDetail()`, `csvResultats()`, `cellule()` (neutralisation des formules), `slug()`.
- Contrôleur : `detail()`, `detailExport()`, `resultatsExport()` ; `index()` passe par `anneeParam()`. Routes `analytique/detail`, `analytique/detail/export`, `analytique/export`.
- Vues : `detail.php` (nouvelle) ; liens 🔎 et export dans `index.php` et `resultats_analytiques.php` (+ colonnes HAO).

Tests : `tests/analytique_detail_1825.php` — 23 contrôles (dont un worker dédié à l'export réel).

---

# Patch 1.824.0 — Analytique, lot 2 (fin) : règles d'imputation automatique

Base : 1.823.0.

- Nouveau `Modules/Analytique/Models/AnaRegle.php` (`FKC_AnaRegle`) : table `ana_regles` (créée à la demande), `creer()` (contrôles), `basculer()`, `supprimer()`, `decrire()`, `appliquerEcriture()` (jamais bloquant), `apercu()`, `appliquerExercice()`.
- `FKC_Ecriture::save()` appelle `FKC_AnaRegle::appliquerEcriture()` après l'insertion des lignes (donc aussi à la validation du journal temporaire). Exclus : contre-passations (`ANN#`, type annulation), écritures de clôture.
- Contrôleur : `regles()`, `regleStore()`, `regleToggle()`, `regleDelete()`, `reglesAppliquer()` ; routes `analytique/regles*` ; vue `regles.php` ; entrée de menu Analytique › Paramétrage.

Tests : `tests/analytique_regles_1824.php` — 28 contrôles.

---

# Patch 1.823.0 — Analytique, lot 2 (1re partie) : ventilation en pourcentages, clés appliquées et réappliquées

Base : 1.822.0.

- Nouveau `Modules/Analytique/Models/Ventilation.php` (`FKC_Ventilation`) : table `ligne_ventilations` (créée à la demande), `definir()` / `retirer()` / `valider()`, `montantsAxe()`, `partsCle()` (fixe, dynamique sur imputations directes, traduction valeur → division pour l'axe DIV), `appliquerCle()`, `reappliquerCle()`, `usageCles()`, `reprendre()`, `purgerOrphelins()`.
- `FKC_Division` : `sqlBlocs()` accepte un coefficient ; `resultats()` porte les parts ventilées aux divisions (et les retire du non imputé) ; `sectionsDuResultat()`, `impact()`, `delete()`.
- `FKC_Affectation` : `setForLigne()` efface la ventilation de l'axe quand une valeur unique est posée ; « reste à affecter » exclut les lignes ventilées ; `resultatsParAxe()` ajoute les parts ; purge.
- `FKC_Ecriture::prepare()/save()` : une affectation peut être un jeu de parts (reprise de ventilation) ; `EcritureAnnulation` la reprend ; `BrouillardController` la conserve.
- `FKC_Repartition::simuler()` (dynamique) = `FKC_Ventilation::partsCle()`.
- Routes : `analytique/affectations/{id}/ventiler` (GET/POST), `analytique/affectations/appliquer-cle`, `analytique/repartitions/{id}/reappliquer`. Vues : `ventiler.php` (nouvelle), `affectations.php`, `repartitions.php`.

Tests : `tests/analytique_ventilation_1823.php` — 40 contrôles.

---

# Patch 1.822.0 — Analytique, lot 1 : clôture exclue, HAO, affectations fiables et protégées, paramètres utiles

Base : 1.821.0.

- `FKC_Division` : `horsCloture()` (exclut `sqlEcritureResultat`) appliqué dans `filtrePeriode()` — donc partout —, `resultatMensuel()` et `FKC_AnaKpi::prefixe()` ; `sqlBlocs()` / `sqlGestion()` ; `resultats()` en une requête groupée, avec `hao` et `resultat_net`.
- `FKC_Affectation` : HAO dans `resultatsParAxe()` ; classe 8 dans `lignesAnalytiques()` / `resteAAffecter()` ; `compterLignes()`, `taillePage()` (≤ 50, borné par max_input_vars), `estVerrouillee()`, `enregistrerLot()` (diff, contrôle valeur/axe/actif, ligne 6/7/8, exercice clos, audit `analytique_affectation`).
- `AnalytiqueController` : `rapprochement()` (SIG XI) ; `affectations()` paginé ; `affectationsSave()` exige `_fin` ; `anneeParam()` lit `ana_exercice_defaut` puis `FKC_Exercice::courant()` ; `exercicesReference()` ; `exerciceStore()` ne crée plus.
- Vues : `index.php` (HAO, résultat net, bandeau de rapprochement), `affectations.php` (réécrite), `parametrage.php` (réécrite).

Tests : `tests/analytique_lot1_1822.php` — 34 contrôles.

---

# Patch 1.821.0 — Cockpit : trésorerie nette juste avant clôture de N-1 ; contrôle du cockpit réparé

Base : 1.820.0.

- `FKC_CockpitData::equilibreFinancier()` : ressources stables + résultat antérieur non affecté (Σ classes 6-8 jusqu'à la veille de l'exercice, TOUTES écritures comprises — nul après clôture).
- `tests/rendu_direction.php` : `--verifier` sème le jeu de démonstration ; le jeu n'impute plus sur un compte de regroupement ; nouveau contrôle « N-1 clôturé ». 13 contrôles, tous réussis.

---

# Patch 1.820.0 — Terminaux, Phase 3 lot 3 : horaires, rubriques, aperçu

Base : 1.819.0 (Scan Engine 1.816–1.819 d'une autre session, préservés ; aucun fichier en commun hormis index.php).

- `FKC_Terminal` : colonnes `horaires`, `rubriques` ; `horaires()`, `ouverture()` (plage passant minuit, libellé « nous rouvrons … », secondes avant bascule), `enregistrerHoraires()`, `rubriques()`, `enregistrerRubriques()`, `filtrerCatalogue()`.
- Borne : `components/ferme.php`, refus serveur si fermé ou article hors rubrique, catalogue filtré (page et route), empreinte `ping` enrichie (horaires, rubriques, ouverture, apparence).
- Administration : `parametres/terminaux/{id}/configurer` (horaires, rubriques, aperçu à l'échelle) et `parametres/terminaux/{id}/apercu` (sans jeton, rien n'est envoyé).

Tests : `tests/terminal_config_1820.php` — 33 contrôles.

---

# Patch 1.819.0 — Scan Engine 2.0, phase 3 lot 3

Base : 1.818.0.

- Réception : séries distinctes conservées ; transfert entre entrepôts enfin réel ; clôture de processus unique.
- Séries natives (doublon, déjà en stock, déjà sortie) ; SSCC généré et contrôlé.
- « Scanner pour démarrer » un processus.

Tests : `tests/scan_processus_1819.php` — 23 vérifications.

---

# Patch 1.818.0 — Scan Engine 2.0, phase 3 lot 2

Base : 1.817.0.

- Emplacements hiérarchiques (zone → bac), chemin, QR unique au registre.
- Smart Scan : emplacement courant de l'appareil ; inventaire aveugle.
- Validation des écarts avant écriture (cause obligatoire, revalidation si nouveau scan).
- Traçabilité scan → mouvements → écritures.

Tests : `tests/scan_emplacements_1818.php` — 26 vérifications.

---

# Patch 1.817.0 — Scan Engine 2.0, phase 3 lot 1

Base : 1.816.0.

- FKC_ScanIntent : phrase, indicateurs, alertes, prochaines actions ; aperçu « Ce scan va… » sans effet.
- Multi-objets GS1 résolus (emplacement, série, SSCC) ; création de lot depuis le scan.
- Étiquette de balance reconnue par le moteur central ; forme GS1 mixte décodée.
- Analytique du scan (contextes, opérateurs, cadence, inconnus à affecter).

Tests : `tests/scan_intent_1817.php` — 27 vérifications.

---

# Patch 1.816.0 — Scan & Identification, phases 1 et 2

Base : 1.815.0 (travaux Terminaux 1.811–1.815 préservés).

- Phase 1 : application Smart Scan unique, agrégat des seuls scans acceptés, event_uuid, mobile hors ligne IndexedDB, jeton d'appareil haché/expirant/tournant, contextes hiérarchiques, CSRF POST-only, journal sans bruit, GS1 calendrier + siècle, EAN interne réservé.
- Collisions corrigées : immobilisation et anciens champs Inventaire ne volent plus le code d'un autre article.
- Phase 2 : Retail inscrit au registre central, caisse Retail carton ×N et GS1, FKC_Scan::identifier().
- Constats non confirmés : double else dans console.php (absent), CSRF effectivement couvert par csrf.js (durci quand même).

Tests : `tests/scan_identite_1816.php` — 47 vérifications.

---

# Patch 1.815.0 — Terminaux, Phase 3 lot 2 : apparence des bornes de l'établissement

Base : 1.814.0.

- Nouveau `Core/TerminalMarque.php` (table `terminal_marque`, clé/valeur) : thème, accent, police des titres, arrondis, ambiance, photo de fond ; `css()` traduit les listes fermées en variables.
- `FKC_TerminalTheme::resoudre()` : Core → métier → établissement → terminal ; `variablesCss()` ajoute police et rayons ; `ui` porte `ambiance` et `fond_attente`.
- Polices OFL embarquées : Poppins, Nunito, Playfair Display (700/800) + licences.
- Écran d'attente : `fkt-attract--halos|photo|sobre`, route `terminal/fond`.
- Administration : `parametres/terminaux/apparence` avec aperçu en direct (iframe srcdoc).

Tests : `tests/terminal_apparence_1815.php` — 29 contrôles.

---

# Patch 1.814.0 — Terminaux, Phase 3 lot 1 : contenus promotionnels et carrousel

Base : 1.813.0.

- Nouveau `Core/TerminalPromo.php` (table `terminal_promos`) : saisie validée, période, métier, emplacement, ordre, photo (`FKC_TerminalMedia`, famille `promos`), `publiques()` relit l'article lié dans le catalogue de la borne.
- Administration : `parametres/terminaux/promos` (vue `terminaux_promos.php`), lien depuis la page Terminaux.
- Borne : `components/promo-banner.php`, défilé dans `components/attract.php`, `assets/terminal/js/terminal-promo.js` (scroll-snap + avance auto), action `promo` dans `terminal-client.js`, contenus dans `terminal/catalogue` et dans la signature de rechargement.
- Route `terminal/promo/{id}/image` (jeton, contenu à l'écran et du bon métier).

Tests : `tests/terminal_promos_1814.php` — 40 contrôles.

---

# Patch 1.813.0 — Terminaux Restaurant : commande à emporter

Base : 1.812.0.

- Borne : étape « Service » (Sur place / À emporter), `identite()['lieu']['emporter']` ; champ `mode` envoyé par `terminal/commander`.
- `rs_borne_demandes.mode` (sur_place | emporter) ; `FKC_RestoBorne::creer(..., $mode)` sans table pour l'emporter ; `valider()` → `FKC_RestoSalle::ouvrirEmporter()`.
- `FKC_Pos::ouvrir()` option `nouveau` (ticket propre, sans table) ; tickets `canal='emporter'` + `numero_commande` (colonnes de `FKC_HospitalityOrder`, réutilisées).
- Écran Salle : liste et panneau `?emporter=` ; `serviceDuJour()` inclut l'emporter.
- Suivi / affichage : libellés « comptoir », numéro d'appel.

Tests : `tests/terminal_restaurant_emporter_1813.php` — 30 contrôles.

---

# Patch 1.812.0 — Terminaux Restaurant : options des plats, photos/badges, tables réservées

Base : 1.811.0.

- Options (variantes) sur la borne : fenêtre de choix, groupe unique obligatoire (cuisson), suppléments ; panier par clé `id|options` (`terminal-cart.js`), serveur `FKC_TerminalRestaurant::options()` / `panierOptions()`.
- `rs_borne_lignes` : colonnes `variantes_ids`, `options` ; `FKC_RestoBorne::valider()` transmet les variantes à `ajouterPlat()`.
- `rs_plats` : colonnes `image`, `badge` ; fiche du plat « Sur la borne » ; route `restaurant/cuisine/plat/{id}/image`.
- Nouveau `Core/TerminalMedia.php` (réception/service des photos) ; `FKC_ServiceCatalog` y délègue ; `image()` facultative au contrat d'adaptateur.
- `FKC_RestoBorne::sqlReservee()` : réservation confirmée dans l'heure exclue des tables libres et de l'INSERT atomique.
- Correctifs Caisse : `FKC_Pos::ouvrir()` ne rend plus le ticket d'une autre table ; `ajouterArticle()` ne fusionne plus deux notes différentes ; reprise automatique de l'addition mise en attente (Restaurant, Bar).

Tests : `tests/terminal_restaurant_options_1812.php` — 58 contrôles.

---

# Patch 1.811.0 — Terminaux : la société en en-tête, FinaKop en pied de page

Base : 1.810.0 (lots Cabinet 1.809 et Cockpit 1.810 d'une autre session, préservés ; aucun fichier en commun hormis une ligne de route dans index.php).

- `FKC_TerminalTheme::marque()` : raison sociale + initiales (sigle), repli sur la société de la session quand le jeton ne la porte pas.
- `components/header.php`, `components/attract.php` : logo de la société ou ses initiales, plus jamais le logo FinaKop.
- Nouveau `components/signature.php` : seul endroit où la marque FinaKop est dessinée ; inclus en pied de client, atelier, affichage, suivi, appairage et écran d'attente.
- Route `suivre/{code}/logo` (lien signé).

Tests : `tests/terminal_marque_1811.php` — 24 contrôles.

---

# Patch 1.810.0 — Cockpit : chaque carte sur sa ligne, en pleine largeur

Base : 1.809.0.

- Drapeau pleine largeur étendu : Direction Générale, Direction Financière, Commercial, Clients, Streaming (s'ajoutent aux huit cockpits de 1.734.0).
- Prévisions de chiffre d'affaires et Radar de risques : chacun sur sa propre ligne, horizons répartis sur toute la largeur.
- Tests : `tests/pleine_largeur_1810.php` (26) ; deux harnais de mise en page mis à jour sur la nouvelle maquette.

---

# Patch 1.809.0 — Cabinet comptable, lot 4 : cycle des honoraires, rentabilité, cockpit, export

Base : 1.808.0 (travaux Terminal d'une autre session, préservés). Les lots 1 à 3 (1.803–1.805) y étaient déjà.

- Créance d'honoraires attribuée au client (tiers sur la ligne 411) : encaissable, lettrable, relançable.
- Honoraires du dossier : notes reliées à la mission / échéance, encaissement plafonné au reste dû, ancienneté, recoupement grand livre.
- Impayés du cabinet, rentabilité (client, mission réel/attendu, mois, collaborateur), cockpit multi-dossiers sans mélange des comptabilités.
- Export ZIP d'un dossier, comptabilité du client incluse.

Tests : `tests/cabinet_honoraires_1809.php` — 39 vérifications.

---

# Patch 1.808.0 — Terminaux : pack Restaurant

Base : 1.807.0.

## Décisions de l'utilisateur
1. Commande de borne = demande à valider ; la validation crée l'addition (ticket FKC_Pos) à encaisser.
2. Le client choisit sa table parmi les tables disponibles.

## Livré
- `app/Packs/restaurant/Models/Borne.php` (`FKC_RestoBorne`) : tables `rs_borne_demandes` / `rs_borne_lignes`, `tablesLibres()`, `creer()` (retenue atomique INSERT … WHERE NOT EXISTS, expiration 45 min), `valider()` (réservation `en_validation`, refus si addition déjà ouverte, `ouvrirAddition` + `ajouterPlat` + `envoyerCuisine`), `refuser()`, `etape()` (demande × lignes cuisine × encaissement), `tableau()`.
- `FKC_TerminalRestaurant` (Core/TerminalAdapter.php) : chargement paresseux des modèles du pack (route publique exécutée avant le boot du pack de la société), carte publique avec allergènes, `lieux()`.
- Contrat d'adaptateur : `lieux` ajouté aux méthodes facultatives ; route `terminal/lieux` (capacité `commander`).
- Parcours client : étape « lieu » générique (`identite()['lieu']`), téléphone désactivable (`identite()['telephone']`).
- Écran Salle : carte « Commandes de la borne », actions `borne_valider` / `borne_refuser`, route `restaurant/borne/compte`.

## Tests
`tests/terminal_restaurant_1808.php` — 60 contrôles.

---

# Patch 1.807.0 — Terminaux : charte FinaKop + Phase 2

Base : 1.806.0.

## Charte FinaKop
- `themes.css` réécrit sur la palette de `app.css` (bleu roi / orange / nuit marine) ; jetons `--t-brand*` (bleu, fixe) et `--t-accent*` (orange, remplaçable par l'établissement seulement).
- `FKC_TerminalTheme::ACCENT_DEFAUT = #f39a12`, encre sombre `#0a0f1c` ; l'accent d'un pack est ignoré.
- Polices Syne 700/800 et Inter 400–700 embarquées (`app/assets/terminal/fonts`, OFL).
- Logo FinaKop à défaut de logo d'établissement ; signature « Propulsé par FinaKop ».

## Phase 2
- `FKC_TerminalSuivi` : code signé `soc.pack.ref.sig` (HMAC-SHA256 tronqué à 64 bits), frise 4 étapes.
- Route publique `suivre/{code}` (HTML + `?format=json`), limitée par IP sur les liens invalides.
- `FKC_TerminalStudio::statut()` renvoie `etape` (commercial × atelier) ; `commander` renvoie `suivi_url`.
- Colonnes additives `svc_prestations.image`, `svc_prestations.badge` ; `FKC_ServiceCatalog::enregistrerImage()/retirerImage()/badges()`.
- Routes `terminal/image/{id}` (jeton) et `studio/catalogue/{id}/image` (gestion).
- Recherche (> 8 cartes), container queries par carte, bandeau hors ligne, QR via `js/qrcode.js`.

## Reste à décider (lot suivant)
- Adaptateur Restaurant : voir le message de livraison.

## Tests
`tests/terminal_phase2_1807.php` — 78 contrôles.

---

# Patch 1.806.0 — Terminaux, Phase 1 : FinaKop Terminal Premium

Base : 1.805.0.

## Correctifs de fond

- **Appairage en boucle (multi-sociétés)** : `jetonComplet()` lisait la société dans la session ; une tablette n'en a pas. Le cookie partait sans préfixe et l'appareil redemandait un code. La société résolue par le code est désormais portée par la ligne (`societe_id`).
- **Jeton d'une autre société** sur un poste où un administrateur est connecté : la base du jeton est ouverte (`brancherSociete()`).
- **IDOR inter-packs** : `commander()` n'excluait ni les prestations d'un autre pack ni celles retirées de l'accueil. Vérification avant toute écriture.
- Panier dédoublonné/plafonné, identité client validée, `mb_substr`, réponses `no-store`, révocation détectée par `terminal/ping`.
- Admin : texte « valable une seule fois » corrigé (faux depuis 1.736.0) ; un terminal se modifie enfin sans révocation.

## Terminal Engine

- `FKC_TerminalTheme` (Theme Engine) : thèmes clair/sombre/premium, couleurs validées, contraste WCAG, Core → pack → établissement, logo.
- `FKC_TerminalAdapter::contrat()` / `manquantes()` : contrat Métier vérifié ; `identite()` du Studio (premium, or, +225).
- Design System : `app/assets/terminal/css/{terminal-core,themes,components,responsive}.css`.
- JS modulaire : `app/assets/terminal/js/{terminal-core,terminal-cart,terminal-client}.js`.
- Vues composées : `app/Modules/Terminal/Views/components/` (_head, icons, header, categories, card, action-bar, panier, success, attract, idle).
- Nouvelles colonnes additives : `terminaux.theme|accent|delai_inactivite|attract|message_accueil`, `svc_prestations.rubrique`.
- Routes : `terminal/ping`, `terminal/logo`, `parametres/terminaux/{id}/reglages`.

## Tests

`tests/terminal_premium_1806.php` — 134 contrôles (option `--dump=<dossier>` pour rendre les pages).
`tests/terminal_client_1724.php` — adapté aux écrans composés, 94 contrôles. Parcours vérifié sous Chromium sur 6 formats (téléphone, tablette portrait/paysage, desktop, borne portrait/paysage) : aucune erreur JS.

---

# Patch 1.805.0 — Cabinet comptable, lot 3 : échéances et fiscalité du dossier

Base : 1.804.0.

- Aucune date légale codée : règle du cabinet sur la mission, ou saisie manuelle (décision utilisateur).
- Échéances sur l'exercice propre du client, « date à renseigner » jamais comptée en retard.
- Dépôt : cause du retard exigée, déclaration vérifiée dans les livres du client.
- Écran Fiscalité & échéances du dossier, Radar des échéances du cabinet (qui, quoi, pourquoi).
- Types de missions étendus, responsable de mission et d'échéance, temps rattaché à un utilisateur.

Tests : `tests/cabinet_echeances_1805.php` — 43 vérifications.

---

# Patch 1.804.0 — Cabinet comptable, lot 2 : la production du dossier

Base : 1.803.0.

- Registre des pièces reçues (dossier, période, exercice propre, mission), fichier vérifié par signature.
- Circuit à séparation des tâches ; comptabilisation vérifiée dans les livres du client.
- Points en suspens, relance composée et tracée.
- Checklists mensuelle / clôture, contrôles automatiques, forçage motivé, revue qui fige la période.
- Réglages de production (administrateur) : modèles de checklist, séparation stricte ou assouplie.

Décision utilisateur notée pour le lot 3 : les dates d'échéance fiscales resteront saisies manuellement (aucun calendrier DGI codé en dur).

Tests : `tests/cabinet_production_1804.php` — 60 vérifications.

---

# Patch 1.803.0 — Cabinet comptable : le dossier client relié à sa comptabilité

Base : 1.802.0.

## Le défaut de fond

Le pack suivait des clients (`cb_clients`) et le produit tenait des sociétés
(registre `societes`, une base SQLite par société) : rien ne reliait les deux.
Le pack portait en outre deux registres de clients sans lien — `cb_clients`
(missions) et `vp_dossiers` (notes d'honoraires). Les routes `cabinet/dossiers…`
citées par `hooks.php` existent bien (moteur vertical) : le harnais le vérifie.

## Livré

- Liaison `cb_clients.societe_id`, création client + société, rattachement.
- Fiche « Dossier comptable », portefeuille, « Ouvrir la comptabilité »
  (19 destinations, liste fermée), bandeau de contexte, retour au cabinet.
- Droits par dossier (collaborateur / chef de mission / associé) synchronisés
  avec `societe_acces`, sans jamais retirer un accès antérieur.
- Failles fermées : mission sans contrôle d'accès, IDOR sur les échéances,
  radar et rentabilité non cloisonnés.
- Archivage, journal du dossier, pont vers la facturation d'honoraires.
- Correctif transversal : cache d'exercice décalé partagé entre sociétés.

## Décisions confirmées par l'utilisateur

- Créer une société depuis le cabinet est réservé à l'administrateur.
- Un collaborateur non affecté ne voit aucun dossier, même avec le module Cabinet.

## Tests

`tests/cabinet_dossiers_1803.php` — 56 vérifications. Plafond de mise en page
descendu à 41.

---

# Patch 1.802.0 — Spécimens FNE : le dossier de validation DGI en trois clics

Base : 1.801.1.

## Le besoin

Avant de délivrer une clé de **production**, la DGI demande à chaque
contribuable de produire dans son bac à sable un jeu de factures certifiées —
les **spécimens** — puis de les lui transmettre. Tant que ce dossier n'est pas
validé, aucune facture normalisée ne peut être émise. C'est l'étape que toute
société traverse, et elle se faisait à la main.

## Ce que FinaKop fait désormais

Facturation → Paramètres FNE → **Spécimens de validation** :

1. **Générer et certifier les ventes** — cinq cas, volontairement neutres pour
   servir à n'importe quelle société :

| Cas | Situation |
|---|---|
| `b2c_especes` | Particulier, paiement en espèces |
| `b2c_multi` | Particulier, mobile money, plusieurs lignes et quantités |
| `b2b_virement` | Entreprise avec NCC, virement |
| `b2b_cheque` | Entreprise avec NCC, chèque, quantité multiple |
| `exonere` | Opération exonérée de TVA |

2. **Générer et certifier les avoirs** — un sur une vente B2C, un sur une vente
   B2B, via l'endpoint `/refund` : la DGI vérifie l'annulation autant que la vente.
3. **Télécharger le dossier (JSON)** — contribuable (NCC, établissement, point de
   vente), environnement et adresse d'API utilisés, empreinte de la clé, et pour
   chaque pièce : numéro, cas, montants, référence FNE, jeton, lien de
   vérification. À joindre au courriel adressé à `support.fne@dgi.gouv.ci`.

Puis, une fois la clé de production reçue : **Supprimer les spécimens**.

## Trois garde-fous, parce qu'un jeu d'essai n'a rien à faire en comptabilité

1. **Génération refusée hors du bac à sable.** En production, elle consommerait
   vos stickers et certifierait de fausses ventes à votre nom.
2. **Un spécimen ne se comptabilise pas.** Ni chiffre d'affaires, ni TVA
   collectée, ni poste client. L'avoir d'un spécimen hérite de cette nature : il
   n'est plus comptabilisé automatiquement, ni rattrapé par la reprise des avoirs
   en attente — sans quoi le dossier de validation laissait des écritures
   d'annulation sans vente correspondante.
3. **Suppression complète** en un geste, refusée si une pièce porte encore une
   écriture.

La génération est **idempotente** : la relancer ne crée pas de doublon, et un
avoir n'est proposé que sur une vente réellement certifiée.

## Tests
`tests/fne_specimens_1802.php` — 13 vérifications.
Balayage : **316 suites vertes**, `run.php` 452/452.
