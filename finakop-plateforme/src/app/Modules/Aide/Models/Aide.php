<?php
/**
 * Centre d'aide : registre de contenu (guide de prise en main, catégories,
 * articles, tutoriels, glossaire, dépannage, visites guidées).
 *
 * Tout est hors-ligne et déclaratif : aucune requête réseau, aucune base. Le
 * contenu vit dans ce fichier, versionné avec le code — une fonction qui
 * change et sa documentation changent dans le même commit.
 *
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

class FKC_Aide {

	/* ═══════════════ CATÉGORIES ═══════════════ */

	public static function categories() {
		return array(
			'demarrage'    => array( 'icon' => '🚀', 'titre' => 'Démarrage',               'intro' => "Entité, licence, utilisateurs, exercice : tout ce qu'il faut avant la première écriture." ),
			'navigation'   => array( 'icon' => '🧭', 'titre' => 'Interface & navigation',   'intro' => "Lanceurs, cockpits, listes, notifications, bascule entre entités." ),
			'comptabilite' => array( 'icon' => '📒', 'titre' => 'Comptabilité SYSCOHADA',   'intro' => "Plan comptable, journaux, saisie, brouillard, lettrage, états, clôture." ),
			'achats'       => array( 'icon' => '🚚', 'titre' => 'Achats & fournisseurs',    'intro' => "Bons de commande, réceptions, comptes 408 et 401, règlements." ),
			'ventes'       => array( 'icon' => '🧾', 'titre' => 'Ventes & encaissement',    'intro' => "Clients, factures, FNE, reçus, avoirs, recouvrement." ),
			'stock'        => array( 'icon' => '📦', 'titre' => 'Stock & inventaire',       'intro' => "Articles, réceptions, sorties, CUMP, inventaire physique, écarts." ),
			'caisse'       => array( 'icon' => '🛒', 'titre' => 'Caisse & point de vente',  'intro' => "Postes, encaissement, promotions, fidélité, clôture Z, journal fiscal." ),
			'scan'         => array( 'icon' => '🔍', 'titre' => 'Scan & identification',    'intro' => "Codes-barres, QR, étiquettes, douchette, téléphone en scanner." ),
			'immo'         => array( 'icon' => '🏗️', 'titre' => 'Immobilisations',          'intro' => "Fiches d'actifs, amortissements, récolement du parc, cessions." ),
			'fiscalite'    => array( 'icon' => '🏛️', 'titre' => 'Fiscalité',                'intro' => "Régimes, TVA, TEE, journal des déclarations." ),
			'rh'           => array( 'icon' => '👥', 'titre' => 'RH & Paie',                'intro' => "Employés, bulletins, congés, avances, déclarations sociales." ),
			'analyse'      => array( 'icon' => '📊', 'titre' => 'Analyse & pilotage',       'intro' => "Tableaux de bord, KPI, alertes, analytique, budgets." ),
			'connect'      => array( 'icon' => '💬', 'titre' => 'FinaKop Connect',        'intro' => "Messagerie d'entreprise rattachée aux pièces : conversations, documents, appels, invités externes." ),
			'packs'        => array( 'icon' => '🧩', 'titre' => 'Packs métier',             'intro' => "Adapter l'ERP à votre métier : 62 packs, terminologie, écritures dédiées." ),
			'admin'        => array( 'icon' => '⚙️', 'titre' => 'Administration & données', 'intro' => "Paramètres, maintenance, sauvegardes, automatisations, GED." ),
			'securite'     => array( 'icon' => '🛡️', 'titre' => 'Sécurité & API',           'intro' => "Mots de passe, chiffrement, journal d'audit, clés API." ),
			'creative'     => array( 'icon' => '🎵', 'titre' => 'Édition Creative',         'intro' => "Label musical, secteurs créatifs, royalties, contrats d'artistes." ),
		);
	}

	/* ═══════════════ ARTICLES ═══════════════ */

	public static function articles() {
		static $cache = null;
		if ( null !== $cache ) { return $cache; }
		return $cache = FKC_AidePlateforme::adapterArticles( array_merge(
			self::artDemarrage(), self::artNavigation(), self::artComptabilite(),
			self::artAchats(), self::artVentes(), self::artStock(), self::artCaisse(),
			self::artScan(), self::artImmo(), self::artFiscalite(), self::artRh(),
			self::artAnalyse(), self::artPacks(), self::artAdmin(), self::artSecurite(),
			self::artCreative(), self::artConnect()
		) );
	}

	/* ── Démarrage ── */

	protected static function artDemarrage() {
		return array(

		array( 'id' => 'changer-mot-de-passe', 'cat' => 'demarrage', 'niveau' => 'essentiel',
			'titre' => "⚠️ Changer le mot de passe administrateur — à faire en premier",
			'tags' => 'mot de passe admin sécurité première connexion défaut',
			'corps' =>
			"<p>À l'installation, un compte <code>admin</code> est créé avec un mot de passe par défaut, <strong>identique sur toutes les installations</strong> et publié dans la documentation du produit. Tant qu'il n'est pas changé, n'importe qui connaissant l'adresse de votre application peut entrer.</p>"
			. "<p><strong>Faites-le avant toute autre chose</strong>, avant même de créer votre entité :</p>"
			. "<ol><li>Menu <em>Utilisateurs</em>.</li><li>Ouvrez le compte <code>admin</code>.</li><li>Saisissez un mot de passe long et propre à votre organisation.</li><li>Enregistrez, puis reconnectez-vous.</li></ol>"
			. "<p>Dans la foulée, créez un compte nominatif pour chaque personne qui utilisera l'application. Un compte partagé rend le journal d'audit inutile : si tout le monde est « admin », plus personne n'est responsable de rien.</p>" ),

		array( 'id' => 'premier-jour', 'cat' => 'demarrage', 'niveau' => 'essentiel',
			'titre' => "Votre premier jour : de l'installation à la première écriture",
			'tags' => 'démarrage installation prise en main premiers pas checklist ordre',
			'corps' =>
			"<p>L'ordre compte. Chaque étape suppose la précédente, et sauter la troisième vous fera ressaisir la cinquième.</p>"
			. "<ol>"
			. "<li><strong>Sécuriser l'accès</strong> — changez le mot de passe <code>admin</code>, créez les comptes nominatifs.</li>"
			. "<li><strong>Créer l'entité</strong> — raison sociale, NCC, forme juridique, régime fiscal, devise.</li>"
			. "<li><strong>Choisir le pack métier</strong> — il décide de la terminologie, des écrans et des écritures automatiques. En changer plus tard est possible mais moins confortable.</li>"
			. "<li><strong>Vérifier le plan comptable</strong> — SYSCOHADA est déjà là. Ajoutez vos comptes propres maintenant, pas au milieu d'une saisie.</li>"
			. "<li><strong>Ouvrir l'exercice</strong> — sans exercice ouvert, la saisie est bloquée.</li>"
			. "<li><strong>Renseigner les référentiels</strong> — clients, fournisseurs, articles. Le moment le plus ingrat, et celui qui fait gagner le plus de temps ensuite.</li>"
			. "<li><strong>Reprendre l'existant</strong> — soldes d'ouverture, stock, immobilisations, avant toute écriture du nouvel exercice.</li>"
			. "<li><strong>Saisir une écriture d'essai</strong> — vérifiez qu'elle apparaît au grand livre et à la balance, puis annulez-la.</li>"
			. "</ol>"
			. "<p>Comptez une demi-journée pour une petite structure, deux à trois jours si vous reprenez une comptabilité existante avec un stock à inventorier.</p>" ),

		array( 'id' => 'creer-societe', 'cat' => 'demarrage',
			'titre' => "Créer et activer une entité",
			'tags' => 'entité société entreprise cabinet multi NCC devise',
			'corps' =>
			"<p>Une installation peut héberger plusieurs entreprises indépendantes. Chaque entité dispose de <strong>ses propres livres, dans un fichier de base séparé</strong> : aucune écriture ne peut passer d'une entité à l'autre, même par erreur.</p>"
			. "<ol><li>Menu <em>Sociétés</em>, puis <em>Nouvelle société</em>.</li>"
			. "<li>Renseignez raison sociale, sigle, NCC, forme juridique, régime fiscal, devise et début d'exercice.</li>"
			. "<li>Validez : plan comptable SYSCOHADA, journaux et paramètres sont initialisés automatiquement.</li>"
			. "<li>Sélectionnez l'entité active en haut de la barre latérale.</li></ol>"
			. "<p>Le NCC conditionne la facturation normalisée : renseignez-le dès la création, sinon vos premières factures partiront sans.</p>"
			. "<p>La licence s'applique au niveau du cabinet : toutes les entités partagent la même édition et les mêmes quotas.</p>" ),

		array( 'id' => 'editions', 'cat' => 'demarrage',
			'titre' => "Éditions, paliers et quotas",
			'tags' => 'licence édition starter business pro entreprise creative quota limite palier',
			'corps' =>
			"<p>Les fonctions disponibles dépendent de votre <strong>palier</strong> : Starter, Business, Pro, Entreprise Standard, Avancée et Premium — déclinés en gamme <em>Core</em> et en gamme <em>Creative</em>.</p>"
			. "<p>Trois choses varient d'un palier à l'autre : les <strong>modules</strong> ouverts (les autres apparaissent verrouillés 🔒), les <strong>quotas</strong> (sociétés, utilisateurs, établissements, stockage, appels API mensuels) et les <strong>capacités</strong> optionnelles. Les quotas exacts de votre palier et votre consommation s'affichent dans <em>Paramètres → Licence</em> ; aucun palier n'est illimité.</p>"
			. "<p>Sans clé installée, l'application fonctionne en <strong>Starter</strong> : elle reste pleinement utilisable, avec les limites de ce palier. Vous ne perdez jamais l'accès à vos données parce qu'une licence a expiré.</p>" ),

		array( 'id' => 'licence-installer', 'cat' => 'demarrage',
			'titre' => "Installer ou renouveler une clé de licence",
			'tags' => 'licence clé activation expiration domaine révocation hors ligne grâce',
			'corps' =>
			"<p>Écran <em>Licence</em> : collez la clé fournie, validez. La signature est vérifiée localement — l'activation ne dépend pas d'Internet.</p>"
			. "<p><strong>Une clé ne se modifie pas : elle se remplace.</strong> Ajouter un service ou fermer une fonction produit toujours une nouvelle clé. Votre partenaire la réémet en conservant votre date d'expiration : vous ne perdez pas les jours restants.</p>"
			. "<p><strong>Depuis la version 1.711, vous n'avez souvent plus rien à coller.</strong> Si votre partenaire a publié un dépôt de licences, l'application va chercher elle-même la clé mise à jour, une fois par jour. Un service activé le lundi est actif chez vous le mardi. Voir « Le dépôt de licences ».</p>"
			. "<p>Un contrôle distant détecte une révocation. <strong>Il ne bloque jamais sur une panne de réseau</strong> : dépôt injoignable, réponse illisible ou périmée, rien ne change — vous continuez à travailler. Seule une révocation signée ferme quelque chose.</p>"
			. "<p>Statuts possibles : <em>active</em>, <em>expirée</em>, <em>révoquée</em>, <em>hors ligne</em>, <em>grâce</em>. Une licence expirée ne détruit rien : vous repassez au palier Starter et retrouvez tout en réinstallant une clé valide.</p>" ),

		array( 'id' => 'licence-depot', 'cat' => 'demarrage',
			'titre' => "Le dépôt de licences : mise à jour automatique et révocation",
			'tags' => 'dépôt licence révocation mise à jour distante signature hors ligne réseau',
			'corps' =>
			"<p>Le dépôt est un fichier signé, publié par votre partenaire, que l'application consulte <strong>une fois par jour</strong>. Il sert à deux choses.</p>"
			. "<p><strong>1. Recevoir vos mises à jour sans rien faire.</strong> Quand votre partenaire active un service — Connect, la visio, le partage de documents — la nouvelle clé arrive seule. Vous n'avez ni fichier à recevoir ni écran à ouvrir.</p>"
			. "<p><strong>2. Permettre une révocation.</strong> En cas de résiliation, votre partenaire peut fermer l'accès sans attendre l'expiration.</p>"
			. "<p><strong>Ce qui ne vous bloquera jamais :</strong> une coupure Internet, un dépôt injoignable, une réponse illisible. Dans tous ces cas l'application ne change rien et vous continuez à travailler — une entreprise dont la connexion tombe un mardi doit facturer le mercredi.</p>"
			. "<p>L'écran <em>Licence</em> affiche la date de la dernière consultation et ce qui en est ressorti. Si vous y lisez « dépôt injoignable » pendant des semaines, signalez-le à votre partenaire : vos mises à jour ne vous parviennent plus, mais rien n'est cassé pour autant.</p>"
			. "<p>Aucune donnée de votre comptabilité ne sort par ce canal. L'application demande un fichier et lit sa réponse ; elle n'envoie rien.</p>" ),

		array( 'id' => 'demarrage-assistant', 'cat' => 'demarrage',
			'titre' => "L'assistant de démarrage : cinq questions, aucun accès fermé",
			'tags' => 'démarrage assistant questions rôle métier effectif façon travail affichage mode',
			'corps' =>
			"<p>Écran <em>Démarrage</em>. Il règle <strong>l'ordre et la densité des écrans</strong>, rien d'autre. Aucune réponse n'ouvre ni ne ferme un droit, et tout se rejoue à volonté.</p>"
			. "<p><strong>1. Votre métier</strong> — il vient de votre licence, pas de vous. La question n'apparaît que si votre clé a été délivrée en « multi », vendue précisément pour choisir.</p>"
			. "<p><strong>2. Combien êtes-vous</strong> — sert de repère pour l'affichage par défaut de l'entité.</p>"
			. "<p><strong>3. Comment travaillez-vous</strong> — <strong>plusieurs réponses possibles, jusqu'à trois</strong>, et <strong>l'ordre dans lequel vous cochez compte</strong> : la première pèse le plus. Un restaurant sert au comptoir et prend des réservations ; un hôtel qui vit de ses réservations ne doit pas voir le stock remonter en premier. Cette réponse <em>remonte</em> des entrées dans votre accueil : elle n'en retire aucune.</p>"
			. "<p><strong>4. Que voulez-vous voir</strong> — décocher range un module hors de vue. Cela ne le résilie pas, n'efface aucune donnée, et se rouvre ici même. Seuls les modules que votre licence accorde sont proposés.</p>"
			. "<p><strong>5. Quel est votre rôle</strong> — <strong>plusieurs réponses possibles, jusqu'à trois</strong>. Dans une petite structure, la même personne tient la caisse le matin, saisit la comptabilité le soir et décide des achats : ce n'est pas un cas limite, c'est le cas courant.</p>"
			. "<p><strong>Avec plusieurs rôles, c'est le plus large qui décide de l'affichage.</strong> Caissier appelle le Mode Simple, comptable l'Expert : qui est les deux obtient l'Expert. Mieux vaut voir un écran de trop, qui se range d'un clic, qu'en chercher un qui manque. Et cela n'ouvre toujours aucun droit : attribuer un rôle est un acte de sécurité, qui se fait dans l'écran Utilisateurs, se journalise et se révoque.</p>"
			. "<p>Sauter une étape ne l'efface pas : une question laissée vide conserve la réponse précédente.</p>" ),

		array( 'id' => 'mise-en-page', 'cat' => 'navigation',
			'titre' => "Pourquoi les écrans se lisent de haut en bas",
			'tags' => 'mise en page écran colonne cartes largeur formulaire disposition',
			'corps' =>
			"<p>Les écrans de FinaKop se composent <strong>de haut en bas, sur toute la largeur</strong> : la liste d'abord, le formulaire dessous, les cartes de contexte côte à côte.</p>"
			. "<p>Ce n'est pas un choix esthétique. Une colonne latérale étroite ne tient qu'un champ de front : les huit champs d'un formulaire s'y empilent un par un, et leurs libellés se tronquent — on perd la seule chose qui dit à quoi sert chaque champ.</p>"
			. "<p>Une colonne latérale subsiste là où elle a un sens : le ticket d'un terminal de vente, le récapitulatif d'une facture qu'on lit en parcourant ses lignes, un sommaire de navigation. Ce sont des contenus qu'on <em>lit</em>, pas qu'on remplit.</p>"
			. "<p>Sur un écran étroit — portable, tablette — les cartes se replient d'elles-mêmes les unes sous les autres.</p>" ),

		array( 'id' => 'barre-laterale', 'cat' => 'navigation',
			'titre' => "Replier la barre latérale : chaque groupe s'ouvre et se ferme",
			'tags' => 'barre latérale menu navigation accordéon replier groupe maestro',
			'corps' =>
			"<p>Chaque intitulé de la barre — <em>Modules</em>, <em>Services</em>, <em>Référentiels</em>, <em>Système</em>, <em>Paramètres</em> — se replie d'un clic. Un chevron indique l'état.</p>"
			. "<p><strong>Votre choix est retenu</strong>, groupe par groupe, sur cet appareil. Rien n'est replié à la première visite : c'est vous qui rangez ce que vous ne regardez pas.</p>"
			. "<p>Le groupe de l'écran où vous vous trouvez est toujours rouvert, même s'il était replié — se retrouver sur une page dont l'entrée est cachée est la meilleure façon de se croire perdu.</p>"
			. "<p>Replier un groupe ne ferme aucun accès et ne change aucun droit : c'est du rangement, rien d'autre.</p>" ),

		array( 'id' => 'utilisateurs', 'cat' => 'demarrage',
			'titre' => "Créer des utilisateurs et attribuer les accès",
			'tags' => 'utilisateur rôle accès droits administrateur module cockpit',
			'corps' =>
			"<p>Menu <em>Utilisateurs</em> (réservé aux administrateurs). Pour chaque compte, vous choisissez les <strong>modules autorisés</strong> et les cockpits visibles.</p>"
			. "<p>Un administrateur a accès à tout, y compris à la maintenance des données et aux purges. Attribuez ce rôle avec parcimonie : il permet d'effacer des exercices entiers.</p>"
			. "<p>Principe utile : donnez le minimum, puis ouvrez à la demande. L'inverse ne se fait jamais en pratique.</p>" ),

		array( 'id' => 'exercices', 'cat' => 'demarrage',
			'titre' => "Ouvrir et paramétrer un exercice",
			'tags' => 'exercice période dates ouverture fiscal comptable',
			'corps' =>
			"<p><em>Paramètres → Exercices</em>. Un exercice délimite la période sur laquelle vous saisissez. Il est généralement calé sur l'année civile, mais peut en différer.</p>"
			. "<p>Les états financiers, la clôture et les comparatifs N/N-1 s'appuient sur cette définition : un exercice mal borné fausse tous les rapports d'un coup.</p>" ),

		array( 'id' => 'etablissements', 'cat' => 'demarrage',
			'titre' => "Établissements, sites et divisions",
			'tags' => 'établissement site agence succursale division analytique',
			'corps' =>
			"<p><em>Paramètres → Établissements</em> décrit vos sites physiques (siège, agences, points de vente) : coordonnées et mentions sur les documents imprimés.</p>"
			. "<p>À ne pas confondre avec les <strong>divisions analytiques</strong>, qui ventilent charges et produits par centre de coût ou de profit. Un établissement est un lieu ; une division est une clé d'analyse. On peut avoir l'un sans l'autre.</p>" ),

		);
	}

	/* ── Navigation ── */

	protected static function artNavigation() {
		return array(

		array( 'id' => 'navigation', 'cat' => 'navigation',
			'titre' => "Se repérer : lanceurs, Retour et Fermer",
			'tags' => 'navigation menu lanceur retour fermer interface écran cadenas',
			'corps' =>
			"<p>Chaque module ouvre d'abord un <strong>lanceur</strong> : une page de cartes qui regroupe ses fonctions par thème. Cliquez une carte pour ouvrir l'écran de données.</p>"
			. "<p>Sur un écran de données, <strong>← Retour</strong> ramène au lanceur du module, <strong>Fermer ✕</strong> ramène au tableau de bord. C'est le même geste partout, y compris dans les packs métier.</p>"
			. "<p>Un module grisé avec un cadenas 🔒 n'est pas cassé : il n'est simplement pas compris dans votre palier.</p>" ),

		array( 'id' => 'cockpits', 'cat' => 'navigation',
			'titre' => "Le Centre de Pilotage : voir, comprendre, agir",
			'tags' => 'cockpit pilotage tableau de bord direction action rôle',
			'corps' =>
			"<p>Un <strong>cockpit</strong> est une page conçue pour un rôle, pas pour un module : Direction, Finance, Stock, Commercial, plus ceux qu'apporte votre pack métier.</p>"
			. "<p>Chaque bloc suit la même boucle : un chiffre (<em>voir</em>), son explication ou sa variation (<em>comprendre</em>), et un bouton qui déclenche l'action correspondante (<em>agir</em>) — relancer un client, lancer un réapprovisionnement, ouvrir la pièce en cause.</p>"
			. "<p>Les cockpits visibles dépendent des droits de l'utilisateur et du pack actif.</p>" ),

		array( 'id' => 'recherche-listes', 'cat' => 'navigation',
			'titre' => "Rechercher et filtrer dans les listes",
			'tags' => 'recherche filtre liste tri colonne export archivé',
			'corps' =>
			"<p>Les écrans de liste offrent une recherche immédiate et des filtres (période, statut, tiers), qui se cumulent.</p>"
			. "<p>Réflexe utile quand un enregistrement « a disparu » : vérifiez d'abord qu'un filtre de période ou de statut n'est pas resté actif d'une consultation précédente, et que vous n'excluez pas les éléments archivés.</p>" ),

		array( 'id' => 'notifications', 'cat' => 'navigation',
			'titre' => "Notifications et alertes",
			'tags' => 'notification alerte seuil rappel cloche échéance',
			'corps' =>
			"<p>La cloche 🔔 regroupe ce qui demande votre attention : seuils de stock franchis, échéances proches, tâches en attente, alertes définies dans <em>Analyse → Alertes</em>.</p>"
			. "<p>Une alerte n'est utile que si elle est rare. Si la cloche est toujours pleine, relevez vos seuils plutôt que d'apprendre à les ignorer.</p>" ),

		array( 'id' => 'multi-societes', 'cat' => 'navigation',
			'titre' => "Travailler sur plusieurs entités",
			'tags' => 'multi société bascule entité isolation cabinet',
			'corps' =>
			"<p>Le sélecteur en haut de la barre latérale bascule d'une entité à l'autre. Toute la session suit : écritures, stocks, factures, paramètres.</p>"
			. "<p>Les livres sont <strong>physiquement séparés</strong> (un fichier de base par entité). Une écriture ne peut donc pas atterrir dans la mauvaise société, même après une bascule en cours de saisie.</p>"
			. "<p>Restent partagés au niveau du cabinet : la licence, les comptes utilisateurs et le journal d'audit.</p>" ),

		);
	}

	/* ── Comptabilité ── */

	protected static function artComptabilite() {
		return array(

		array( 'id' => 'plan-comptable', 'cat' => 'comptabilite',
			'titre' => "Le plan comptable SYSCOHADA",
			'tags' => 'plan comptable compte classe syscohada 401 411 601 701 ohada',
			'corps' =>
			"<p>Le plan suit le référentiel SYSCOHADA révisé, en comptes à six chiffres, classes 1 à 8. Il est semé à la création de l'entité : vous n'avez rien à importer.</p>"
			. "<p>Repères utiles au quotidien : <code>401</code> fournisseurs, <code>408</code> fournisseurs — factures non parvenues, <code>411</code> clients, <code>419</code> clients — avances et acomptes, <code>31x</code> stocks, <code>52x/57x</code> banque et caisse, <code>60x</code> achats, <code>70x</code> ventes, <code>44x</code> État et TVA.</p>"
			. "<p><em>Comptabilité → Plan comptable</em> permet d'ajouter vos comptes propres. Les packs métier en ajoutent d'eux-mêmes : une pharmacie reçoit un compte de stock pharmaceutique, un hôtel un compte de recettes d'hébergement.</p>" ),

		array( 'id' => 'journaux', 'cat' => 'comptabilite',
			'titre' => "Les journaux et leur usage",
			'tags' => 'journal AC VE BQ CA OD achats ventes banque caisse opérations diverses',
			'corps' =>
			"<p>Chaque écriture appartient à un journal, qui dit <em>de quelle nature</em> est l'opération : <code>AC</code> achats, <code>VE</code> ventes, <code>BQ</code> banque, <code>CA</code> caisse, <code>OD</code> opérations diverses.</p>"
			. "<p>Le journal détermine la numérotation des pièces et la présentation des états. Une écriture de vente passée en OD n'est pas fausse comptablement, mais elle échappera aux contrôles et aux totaux par journal — c'est la première chose qu'un contrôleur regarde.</p>" ),

		array( 'id' => 'saisir-ecriture', 'cat' => 'comptabilite', 'niveau' => 'essentiel',
			'titre' => "Saisir une écriture comptable",
			'tags' => 'écriture saisie journal débit crédit équilibre partie double pièce tiers',
			'corps' =>
			"<p>Une écriture doit être <strong>équilibrée</strong> : total débit = total crédit, et au moins deux lignes.</p>"
			. "<ol><li><em>Comptabilité → Nouvelle écriture</em>.</li>"
			. "<li>Choisissez le journal, la date et le numéro de pièce.</li>"
			. "<li>Ajoutez les lignes : compte, libellé, débit <em>ou</em> crédit — jamais les deux sur la même ligne.</li>"
			. "<li>Renseignez le tiers sur les lignes de compte 401 ou 411 : sans lui, le grand livre auxiliaire restera muet et le lettrage impossible.</li>"
			. "<li>L'indicateur passe à <em>Équilibrée</em> : validez.</li></ol>"
			. "<p>Si la date tombe dans une période clôturée, l'enregistrement est refusé. C'est voulu.</p>" ),

		array( 'id' => 'brouillard', 'cat' => 'comptabilite',
			'titre' => "Le brouillard : contrôler avant de comptabiliser",
			'tags' => 'brouillard vérification contrôle validation provisoire',
			'corps' =>
			"<p>Le brouillard est une salle d'attente : les écritures y sont visibles et modifiables, mais <strong>n'affectent aucun solde</strong> tant qu'elles ne sont pas validées.</p>"
			. "<p>Activez-le pour que toutes les écritures automatiques (factures, réceptions, paie, caisse) y transitent. Un comptable les contrôle, corrige, puis comptabilise en lot.</p>"
			. "<p>Recommandé pendant les premières semaines et lors d'un changement de pratique. Une fois la confiance établie, beaucoup le désactivent pour les flux routiniers et le gardent sur les imports.</p>" ),

		array( 'id' => 'import-ecritures', 'cat' => 'comptabilite',
			'titre' => "Importer des écritures (CSV)",
			'tags' => 'import csv écritures pièce libellé regroupement numérotation modèle brouillard',
			'corps' =>
			"<p><em>Comptabilité → Importer des écritures</em> : téléchargez le modèle CSV, remplissez-le, déposez le fichier.</p>"
			. "<p><strong>Une ligne du fichier = une ligne d'écriture.</strong> Le regroupement se fait de deux façons :</p>"
			. "<ul><li><strong>Pièce renseignée</strong> : les lignes de même <em>date + journal + pièce</em> forment une écriture.</li>"
			. "<li><strong>Pièce vide</strong> : les lignes de même <em>date + journal + libellé</em> forment une écriture, et le numéro de pièce est généré automatiquement par journal, dans l'ordre chronologique (ex. OD-2026-001), à la suite des pièces existantes.</li></ul>"
			. "<p>Colonnes : <em>date</em> (AAAA-MM-JJ), <em>journal</em>, <em>piece</em>, <em>libelle</em>, <em>compte</em>, <em>libelle_ligne</em>, <em>debit</em>, <em>credit</em>, <em>axe</em>, <em>valeur</em>. Séparateur « ; ».</p>"
			. "<p>N'utilisez qu'un seul mode de regroupement par écriture. Et cochez <em>Déposer au brouillard</em> pour un premier import : relire cent écritures est plus rapide que d'en annuler cent.</p>" ),

		array( 'id' => 'grand-livre-balance', 'cat' => 'comptabilite',
			'titre' => "Grand livre, balance et extractions",
			'tags' => 'grand livre balance solde mouvement extraction export auxiliaire contrôle',
			'corps' =>
			"<p>La <strong>balance</strong> donne, par compte, le cumul des débits, des crédits et le solde. C'est le point de départ de tout contrôle.</p>"
			. "<p>Le <strong>grand livre</strong> déroule le détail des mouvements d'un compte. Les grands livres <em>clients</em> et <em>fournisseurs</em> font la même chose par tiers : c'est là qu'on lit ce que doit réellement un client donné.</p>"
			. "<p>Les <strong>extractions</strong> exportent en CSV pour un tableur ou pour votre expert-comptable.</p>"
			. "<p>Contrôle de routine : le solde du 411 en balance doit égaler le total des créances au grand livre clients. Un écart signale une écriture passée sans tiers.</p>" ),

		array( 'id' => 'lettrage', 'cat' => 'comptabilite',
			'titre' => "Lettrer les comptes de tiers",
			'tags' => 'lettrage rapprochement client fournisseur solde facture règlement délettrer',
			'corps' =>
			"<p>Lettrer, c'est relier une facture à son règlement pour que la paire disparaisse du solde restant dû. Sans lettrage, un compte client accumule indéfiniment factures et encaissements, et vous ne savez plus qui doit quoi.</p>"
			. "<p>Sélectionnez les lignes à rapprocher : la somme des débits doit égaler celle des crédits. Un lettrage peut être défait s'il a été fait à tort.</p>"
			. "<p>Conséquence pratique : une écriture dont une ligne est lettrée ne peut plus être supprimée. Il faut d'abord délettrer, ou passer par une contre-passation.</p>" ),

		array( 'id' => 'annuler-ecriture', 'cat' => 'comptabilite', 'niveau' => 'essentiel',
			'titre' => "Annuler une écriture : contre-passation ou suppression",
			'tags' => 'annuler écriture erreur contre-passation extourne suppression correction maintenance numéro pièce',
			'corps' =>
			"<p><em>Paramètres → Maintenance des données → Annuler une écriture précise</em>. Saisissez un numéro de pièce, une référence ou un identifiant : toutes les correspondances s'affichent, avec journal, date, montant et état. Le numéro de pièce n'étant pas unique d'un journal ou d'un exercice à l'autre, c'est vous qui désignez la bonne.</p>"
			. "<p><strong>Contre-passer</strong> — la voie normale. Une écriture inverse est générée à la date choisie ; l'écriture d'origine est conservée. Le solde revient à sa valeur d'avant et le contrôleur voit l'erreur <em>et</em> sa correction. Les tiers et les divisions sont repris dans l'inverse, sans quoi le grand livre auxiliaire resterait déséquilibré.</p>"
			. "<p><strong>Supprimer</strong> — la voie exceptionnelle, proposée uniquement si rien ne référence l'écriture. Elle est refusée si la période est clôturée, si une ligne est lettrée, si une facture, un règlement, une dotation ou un bulletin la désigne, si un justificatif y est attaché, ou si elle a déjà été contre-passée.</p>"
			. "<p>Réservez la suppression aux écritures qui n'auraient jamais dû exister : saisie de test, doublon constaté le jour même. Dès qu'une pièce a circulé, contre-passez.</p>"
			. "<p>Les deux opérations sont inscrites au journal d'audit.</p>" ),

		array( 'id' => 'etats-financiers', 'cat' => 'comptabilite',
			'titre' => "Générer les états financiers",
			'tags' => 'bilan compte de résultat sig états financiers annexe contrôle',
			'corps' =>
			"<p><em>Comptabilité → États financiers</em> produit le bilan, le compte de résultat et les soldes intermédiaires de gestion, au format SYSCOHADA.</p>"
			. "<p>Avant de les diffuser, vérifiez trois choses : la balance est équilibrée, les comptes d'attente sont soldés, et les dotations aux amortissements de la période ont été passées. Un état financier juste repose sur ces trois-là.</p>" ),

		array( 'id' => 'etats-dgi', 'cat' => 'comptabilite',
			'titre' => "Les états DGI",
			'tags' => 'dgi état déclaration liasse administration impôts export impression',
			'corps' =>
			"<p><em>Comptabilité → États DGI</em> prépare les éléments destinés à l'administration fiscale, consultables, imprimables et exportables.</p>"
			. "<p>Ce sont des états <strong>préparatoires</strong> : ils reflètent vos écritures. Ils ne remplacent ni le contrôle de votre comptable ni la télédéclaration officielle.</p>" ),

		array( 'id' => 'cloture', 'cat' => 'comptabilite',
			'titre' => "Clôturer une période",
			'tags' => 'clôture verrouillage mois trimestre exercice réouverture',
			'corps' =>
			"<p>Clôturer verrouille une période : plus aucune écriture ne peut y être enregistrée, modifiée ou supprimée. C'est ce qui rend vos états stables.</p>"
			. "<p>Clôturez au mois si votre activité est dense, au trimestre sinon. Une clôture peut être rouverte par un administrateur, mais l'opération est tracée — et si elle devient courante, la clôture ne veut plus rien dire.</p>"
			. "<p>Pour corriger une erreur découverte après clôture : contre-passez à une date postérieure, ne rouvrez pas.</p>" ),

		array( 'id' => 'migration', 'cat' => 'comptabilite',
			'titre' => "Reprendre une comptabilité existante",
			'tags' => 'migration reprise soldes à nouveaux ancien logiciel bilan ouverture',
			'corps' =>
			"<p><em>Comptabilité → Migration</em> accompagne la reprise depuis un autre logiciel : soldes d'ouverture, tiers, en-cours clients et fournisseurs.</p>"
			. "<p>Faites-la <strong>avant</strong> toute saisie du nouvel exercice. Reprendre après coup oblige à recaler des soldes déjà mouvementés, ce qui prend dix fois plus de temps.</p>"
			. "<p>Contrôle final : la balance reprise doit correspondre, au centime, au bilan de clôture de l'ancien système. Ne commencez pas à saisir tant que ce n'est pas le cas.</p>" ),

		);
	}

	/* ── Achats ── */

	protected static function artAchats() {
		return array(

		array( 'id' => 'fournisseurs', 'cat' => 'achats',
			'titre' => "Créer et suivre un fournisseur",
			'tags' => 'fournisseur tiers référentiel compte 401 dette encours',
			'corps' =>
			"<p><em>Comptabilité → Fournisseurs</em> ou <em>Référentiels → Fournisseurs</em> — c'est le même référentiel, partagé par la comptabilité et les packs métier. Un fournisseur créé côté stock est immédiatement disponible côté comptabilité.</p>"
			. "<p>Chaque fournisseur porte un compte de rattachement (<code>401xxx</code>). L'écran <em>Dettes fournisseurs</em> donne l'encours par tiers et son ancienneté.</p>" ),

		array( 'id' => 'bons-commande', 'cat' => 'achats',
			'titre' => "Bons de commande",
			'tags' => 'bon de commande achat engagement fournisseur livraison',
			'corps' =>
			"<p>Le bon de commande matérialise un engagement avant livraison. Il ne génère aucune écriture : engager n'est pas devoir.</p>"
			. "<p>Il sert de référence à la réception puis au contrôle de la facture — c'est ce qui permet de repérer qu'on vous a livré moins que commandé, ou facturé plus que livré.</p>" ),

		array( 'id' => 'receptions-408', 'cat' => 'achats', 'niveau' => 'essentiel',
			'titre' => "Recevoir sans facture : le compte 408",
			'tags' => 'réception 408 facture non parvenue marchandise dette achat extourne',
			'corps' =>
			"<p>La marchandise arrive souvent avant la facture. Comptablement, la dette existe pourtant dès la réception : c'est le rôle du compte <code>408</code>, « fournisseurs, factures non parvenues ».</p>"
			. "<p>À la réception, l'écriture est <code>D 31x stock / C 408</code>. À l'arrivée de la facture, le <code>408</code> est extourné et la dette bascule en <code>401</code>, avec la TVA.</p>"
			. "<p>Intérêt concret : votre stock et votre dette sont justes en permanence, sans attendre le courrier du fournisseur. Et l'écart éventuel entre le montant reçu et le montant facturé apparaît explicitement au rapprochement, au lieu de se dissoudre dans les achats.</p>"
			. "<p>Un solde <code>408</code> qui ne bouge pas signale des réceptions jamais facturées : bon indicateur à surveiller en fin de mois.</p>" ),

		array( 'id' => 'facture-fournisseur', 'cat' => 'achats',
			'titre' => "Saisir une facture fournisseur",
			'tags' => 'facture fournisseur achat tva déductible 401 écart rapprochement',
			'corps' =>
			"<p><em>Comptabilité → Achats → Nouvelle facture</em>. Désignez le fournisseur, la date, le montant hors taxe et la TVA.</p>"
			. "<p>Si des réceptions en attente existent pour ce fournisseur, sélectionnez-les : elles seront soldées du <code>408</code>. Un écart entre le reçu et le facturé est isolé sur une ligne dédiée plutôt que noyé dans la charge.</p>"
			. "<p>Une facture peut être annulée par contre-passation : les réceptions redeviennent alors à facturer, ce qui est correct — la marchandise est toujours là et toujours due.</p>" ),

		array( 'id' => 'reglements-fournisseurs', 'cat' => 'achats',
			'titre' => "Régler un fournisseur",
			'tags' => 'règlement paiement fournisseur virement chèque espèces lettrage trésorerie',
			'corps' =>
			"<p>Depuis la facture ou depuis <em>Trésorerie → Mouvement</em> : mode, date, compte de trésorerie, montant. L'écriture <code>D 401 / C 5xx</code> est générée.</p>"
			. "<p>Lettrez ensuite la facture et son règlement, sinon l'encours fournisseur restera gonflé de dettes déjà payées.</p>" ),

		);
	}

	/* ── Ventes ── */

	protected static function artVentes() {
		return array(

		array( 'id' => 'clients', 'cat' => 'ventes',
			'titre' => "Le référentiel clients",
			'tags' => 'client tiers B2B B2C NCC référentiel passage',
			'corps' =>
			"<p><em>Référentiels → Clients</em>. Distinguez les clients <strong>B2B</strong> (entreprises, NCC obligatoire pour la facturation normalisée) des clients <strong>B2C</strong> (particuliers).</p>"
			. "<p>Un client de passage n'a pas besoin d'une fiche : le Centre d'Encaissement et la caisse acceptent un nom libre. Ne créez une fiche que pour ceux que vous reverrez ou à qui vous ferez crédit.</p>" ),

		array( 'id' => 'creer-facture', 'cat' => 'ventes', 'niveau' => 'essentiel',
			'titre' => "Émettre une facture",
			'tags' => 'facture client devis ttc tva émission vente brouillon avoir',
			'corps' =>
			"<ol><li>Module <em>Facturation</em>, puis <em>Nouvelle facture</em>.</li>"
			. "<li>Sélectionnez le client, ou créez-le.</li>"
			. "<li>Ajoutez les lignes ; vérifiez la TVA et le total TTC.</li>"
			. "<li>Validez : l'écriture comptable correspondante est générée.</li></ol>"
			. "<p>Tant qu'une facture est en brouillon, elle se modifie librement. Une fois émise, corrigez-la par un avoir plutôt qu'en la retouchant : une facture qui change après avoir été envoyée est une facture qu'on ne peut plus opposer à personne.</p>" ),

		array( 'id' => 'fne', 'cat' => 'ventes',
			'titre' => "La facture normalisée électronique (FNE)",
			'tags' => 'fne facture normalisée certification dgi ncc statut erreur',
			'corps' =>
			"<p>Renseignez vos paramètres FNE dans <em>Facturation → Paramètres FNE</em> et testez la connexion avant votre première émission réelle.</p>"
			. "<p>Chaque facture porte un statut de certification : <em>non certifiée</em>, <em>certifiée</em> ou <em>erreur</em>. En cas d'erreur, le message renvoyé par le service est conservé sur la facture — c'est lui qui indique quoi corriger, le plus souvent un NCC client manquant ou mal formé.</p>" ),

		array( 'id' => 'encaissement', 'cat' => 'ventes',
			'titre' => "Centre d'Encaissement : générer un reçu",
			'tags' => 'reçu encaissement comptant acompte avance remboursement ticket rapprochement imputation journal Z 419',
			'corps' =>
			"<p>Le <strong>Centre d'Encaissement</strong> produit des reçus qui deviennent de vraies pièces de gestion : transformables en facture, imputables sur une facture ou rapprochables.</p>"
			. "<p><strong>Six types</strong> : vente au comptant, acompte client, avance sur commande, paiement de facture, remboursement, encaissement divers.</p>"
			. "<p><strong>Cycle de vie</strong> — aucun impact comptable tant que le reçu n'est pas comptabilisé : Brouillon → En attente → Validé → Comptabilisé → Transformé (ou Annulé).</p>"
			. "<ol><li><em>Nouveau reçu</em> : type, client (enregistré ou de passage), montant TTC, compte de trésorerie, compte de produit.</li>"
			. "<li><em>Soumettre au contrôle</em> puis <em>Valider</em>.</li>"
			. "<li><em>Comptabiliser</em> : écriture <code>5xx / 411</code>, ou <code>5xx / 419</code> pour un acompte ou une avance.</li>"
			. "<li>Puis, selon le type : transformer en facture, imputer sur une facture (<code>419 → 411</code> puis lettrage), ou rapprocher.</li></ol>"
			. "<p>Impression A4 ou ticket thermique 58/80 mm, justificatifs rattachables, et clôture journalière (journal Z) ventilée par mode de règlement.</p>" ),

		array( 'id' => 'avoirs', 'cat' => 'ventes',
			'titre' => "Émettre un avoir",
			'tags' => 'avoir annulation retour remise facture erreur correction',
			'corps' =>
			"<p>Un avoir annule tout ou partie d'une facture émise : erreur de montant, retour de marchandise, geste commercial.</p>"
			. "<p>C'est la seule façon correcte de corriger une facture déjà transmise au client. Les deux pièces restent visibles, et leur relation est lisible — ce qu'un contrôle exigera.</p>" ),

		array( 'id' => 'recouvrement', 'cat' => 'ventes',
			'titre' => "Relancer les impayés",
			'tags' => 'recouvrement relance impayé balance âgée promesse plan échéancier créance',
			'corps' =>
			"<p>Le module <em>Recouvrement</em> transforme une créance en action : <strong>balance âgée</strong> (qui doit quoi, depuis combien de temps), dossiers, campagnes de relance, centre d'appels, promesses de paiement et plans d'échelonnement.</p>"
			. "<p>La balance âgée est l'écran à ouvrir en premier chaque semaine. Une créance de plus de 90 jours ne se recouvre pas toute seule, et sa probabilité de recouvrement chute vite.</p>" ),

		);
	}

	/* ── Stock ── */

	protected static function artStock() {
		return array(

		/* ── Moteurs de valorisation, comptabilisation et fabrication ──────── */
		array( 'id' => 'politique-comptabilisation', 'cat' => 'comptabilite',
			'titre' => "Choisir quand vos écritures deviennent définitives",
			'tags' => 'politique comptabilisation brouillard journal temporaire clôture lot validation publication',
			'corps' =>
			"<p>FinaKop sépare quatre moteurs qui travaillent indépendamment : le <strong>stock</strong> et la <strong>valorisation</strong> sont toujours en temps réel, le <strong>moteur comptable</strong> produit l'écriture, et le <strong>journal temporaire</strong> la retient jusqu'à sa publication.</p>"
			. "<p>Cette séparation a une conséquence pratique importante : <strong>quelle que soit votre organisation comptable, vous connaissez votre stock à la seconde</strong>. Retarder la comptabilité ne retarde jamais l'exploitation.</p>"
			. "<p>Chaque origine — caisse, achats, paie, production… — choisit son régime dans <em>Comptabilité → Brouillard</em> :</p>"
			. "<ul><li><strong>Publication immédiate</strong> : l'écriture rejoint directement les journaux. Adapté à une petite structure sans contrôle intermédiaire.</li>"
			. "<li><strong>Lot sur déclencheur</strong> : les écritures s'accumulent et partent ensemble à un moment métier — clôture de caisse, fin de service, fin d'ordre de fabrication, comptabilisation de la paie. Évite de noyer le grand livre sous des centaines de lignes.</li>"
			. "<li><strong>Validation comptable</strong> : rien ne part sans qu'une personne l'ait accepté. C'est le régime des achats et des immobilisations, qui engagent l'entreprise.</li></ul>"
			. "<p>Un déclencheur n'est pas une horloge : la journée d'un commerce se termine quand la dernière caisse ferme, pas à minuit.</p>"
			. "<p>Les écritures refusées par les contrôles restent au journal temporaire et seront reprises une fois leur cause corrigée. Une clôture ne masque jamais un problème.</p>" ),

		array( 'id' => 'valorisation-stock', 'cat' => 'stock',
			'titre' => "Comment le coût de vos articles est calculé",
			'tags' => 'valorisation cump cmp coût moyen pondéré frais accessoires transport douane sortie',
			'corps' =>
			"<p>Un seul moteur calcule la valeur de tous les stocks du produit, quel que soit le module ou le pack. Vous ne pouvez donc pas obtenir deux coûts différents pour le même article selon l'écran consulté.</p>"
			. "<p><strong>À l'entrée</strong>, le coût unitaire moyen pondéré se recalcule : (valeur du stock existant + valeur entrée) ÷ quantité totale.</p>"
			. "<p><strong>Les frais accessoires entrent dans le coût.</strong> SYSCOHADA impose d'incorporer transport, douane et manutention au coût d'acquisition. Un lot de 100 unités à 10 000 F supportant 200 000 F de dédouanement vaut 12 000 F l'unité, pas 10 000. Ne retenir que la facture sous-évaluerait votre actif et surévaluerait votre marge.</p>"
			. "<p><strong>Un retour client ne déplace pas le coût moyen</strong> : la marchandise revient au coût auquel elle est sortie. La faire rentrer à un prix ressaisi fausserait la moyenne.</p>"
			. "<p><strong>Toutes les sorties sont valorisées au même coût</strong>, qu'il s'agisse d'une vente, d'une consommation, d'un rebut ou d'une casse. C'est le compte de destination qui diffère, jamais la valorisation.</p>" ),

		array( 'id' => 'reception-facture', 'cat' => 'achats',
			'titre' => "Réception physique et facture : deux moments distincts",
			'tags' => 'réception bon livraison facture 408 fournisseur non parvenue charge tva',
			'corps' =>
			"<p>Recevoir de la marchandise et recevoir la facture sont deux événements séparés. FinaKop les traite comme tels.</p>"
			. "<p><strong>À la réception</strong>, la marchandise entre en stock et la dette est constatée au compte <em>408 — Fournisseurs, factures non parvenues</em>. Rien n'est payable : on ne règle pas un fournisseur sur un bon de livraison. La TVA n'est pas encore déductible.</p>"
			. "<p><strong>À l'arrivée de la facture</strong>, le 408 est extourné, la TVA devient déductible et la dette passe au compte 401, cette fois payable. Si le montant facturé diffère de ce qui a été reçu, l'écart est porté en charge plutôt que dissimulé dans un compte de tiers.</p>"
			. "<p><strong>Aucune charge n'est constatée dans ces deux étapes.</strong> L'entreprise a échangé une dette contre un actif, puis qualifié cette dette : elle ne s'est appauvrie de rien. La charge apparaît à la sortie du stock.</p>" ),

		array( 'id' => 'cout-consommation', 'cat' => 'stock',
			'titre' => "Quand le coût d'une vente est constaté",
			'tags' => 'coût vente consommation fiche technique inventaire tournant écart marge food cost',
			'corps' =>
			"<p>Le coût est constaté <strong>au moment de la consommation</strong>, jamais à la réception. C'est là qu'il est réellement connu.</p>"
			. "<p>Dans un restaurant, la <strong>fiche technique</strong> dit exactement ce qui est parti en cuisine pour chaque plat servi : la consommation est donc chiffrée à l'envoi, pas à un inventaire de fin de mois. Attendre reviendrait à ignorer le coût matière pendant trente jours, puis à le découvrir d'un bloc sans savoir de quels plats il provient.</p>"
			. "<p>Au garage, la pièce est comptée quand elle est posée : elle ne reviendra pas en rayon.</p>"
			. "<p><strong>L'inventaire tournant ne calcule pas la consommation, il la contrôle.</strong> Il confronte le stock théorique au stock compté, et l'écart — casse, vol, surportion, erreur de saisie — part sur un compte distinct. Le confondre avec la consommation normale masquerait un problème d'exploitation dans le coût matière, là où il doit sauter aux yeux : un food cost qui dérive doit pouvoir dire si les recettes coûtent plus cher ou si la cave fuit.</p>"
			. "<p>La gravité d'un écart se juge au taux, pas au montant : 2 kg de farine manquants sur 500 sont une tolérance de pesée, 3 bouteilles sur 12 non.</p>" ),

		array( 'id' => 'fabrication', 'cat' => 'packs',
			'titre' => "Fabriquer : matières, en-cours, produits finis, rebuts",
			'tags' => 'production fabrication ordre of nomenclature en-cours wip produits finis rebut coût de revient',
			'corps' =>
			"<p>Le module de fabrication sert tout métier qui transforme : industrie, imprimerie, boulangerie, menuiserie, textile, assemblage — et un label qui presse ses CD, grave ses clés USB ou fait imprimer son merchandising.</p>"
			. "<p>Le cycle suit quatre stocks :</p>"
			. "<ul><li><strong>Matières premières</strong> — elles sortent au lancement de l'ordre de fabrication ;</li>"
			. "<li><strong>En-cours de production</strong> — ce qui est engagé mais pas terminé. Ce compte existe pour que le bilan cesse de mentir entre le lancement et la clôture : sans lui, la matière a quitté le stock et le produit n'existe pas encore, l'actif disparaît ;</li>"
			. "<li><strong>Produits finis</strong> — ils entrent à la clôture, au coût de revient : matières consommées + main-d'œuvre + charges machine ;</li>"
			. "<li><strong>Rebuts</strong> — chiffrés, et traités selon leur nature.</li></ul>"
			. "<p><strong>Rebut normal ou anormal ?</strong> Un taux inhérent au procédé — quelques CD mal gravés sur mille — fait partie du coût normal : sa valeur est absorbée par les unités bonnes, qui portent donc un coût un peu plus élevé. Au-delà du taux admis, la perte est anormale et part en charge : capitaliser une défaillance reviendrait à porter à l'actif la valeur d'un accident subi.</p>"
			. "<p>Tant que rien n'est vendu, <strong>la production ne pèse pas sur le résultat</strong> : les charges engagées sont neutralisées par la production stockée. La charge apparaît à la vente du produit fini.</p>" ),

		array( 'id' => 'consolidation', 'cat' => 'analyse',
			'titre' => "Consolider plusieurs sociétés",
			'tags' => 'consolidation groupe cabinet multi-sociétés éliminations intra-groupe réciprocité cumul',
			'corps' =>
			"<p>Additionner des sociétés ne produit pas des comptes consolidés. Si A facture 10 000 000 F à B du même groupe, le cumul affiche 10 000 000 F de chiffre d'affaires et autant de charges qui n'existent pas hors du groupe : l'argent n'a fait que changer de poche.</p>"
			. "<p>Les opérations internes sont reconnues par le <strong>numéro de compte contribuable</strong> : un client dont le NCC est celui d'une autre société du périmètre EST cette société. C'est une identification vérifiable — deux entreprises peuvent porter la même raison sociale, aucune ne partage un NCC. Renseignez donc le NCC de chaque société et de chaque tiers.</p>"
			. "<p>Sont éliminés : les créances et dettes réciproques, les produits et charges réciproques.</p>"
			. "<p><strong>Ne sont pas retraités</strong>, faute des données nécessaires : les marges internes sur stocks, les titres de participation et les dividendes internes. L'écran le dit explicitement — un état de consolidation qui tait ce qu'il n'a pas retraité est plus dangereux qu'un cumul assumé.</p>"
			. "<p>Le <strong>contrôle de réciprocité</strong> est ce qu'un expert-comptable regarde en premier : la créance de A sur B doit égaler la dette de B envers A. Tout écart signale une opération comptabilisée d'un seul côté — décalage de facture, avoir non enregistré. Tant qu'il subsiste, le résultat consolidé reste approximatif.</p>" ),

		array( 'id' => 'dossier-conseil', 'cat' => 'analyse',
			'titre' => "Préparer un dossier de conseil d'administration",
			'tags' => 'dossier conseil impression pdf cockpit direction comité administration',
			'corps' =>
			"<p>Depuis le Centre de Pilotage, le bouton <em>Dossier de conseil</em> produit une version imprimable de votre cockpit : santé de l'entreprise, trésorerie et autonomie, formation du résultat SYSCOHADA, risques et décisions.</p>"
			. "<p>Le document rend <strong>les mêmes sections que l'écran</strong> : il ne peut pas y avoir d'écart entre ce que vous consultez et ce que vous présentez.</p>"
			. "<p>Les sections normalement chargées à la demande y sont calculées : un document imprimé n'a personne pour aller chercher la suite. De même, la chaîne de raisonnement des décisions, repliée à l'écran, est <strong>dépliée à l'impression</strong> — un lecteur qui ne peut pas vérifier une conclusion sur papier ne la vérifiera jamais.</p>"
			. "<p>Le document porte la raison sociale, le NCC, la date et l'auteur : un document de conseil non daté ne vaut rien.</p>" ),

		array( 'id' => 'articles', 'cat' => 'stock',
			'titre' => "Créer et gérer un article",
			'tags' => 'article produit référence catégorie unité prix seuil alerte',
			'corps' =>
			"<p>Un article porte une référence, un libellé, une catégorie, une unité, un prix d'achat, un prix de vente, un taux de TVA et un seuil d'alerte.</p>"
			. "<p><strong>Le stock ne se saisit pas sur la fiche.</strong> Il évolue uniquement par les réceptions (qui recalculent le coût moyen) et les sorties. C'est ce qui garantit qu'un stock a toujours une écriture derrière lui.</p>"
			. "<p>Renseignez le seuil d'alerte dès la création : c'est lui qui alimentera les alertes de réapprovisionnement et le cockpit stock.</p>" ),

		array( 'id' => 'supprimer-article', 'cat' => 'stock',
			'titre' => "Retirer un article du catalogue",
			'tags' => 'supprimer article archiver retirer catalogue erreur doublon réactiver',
			'corps' =>
			"<p>Ouvrez la fiche de l'article : le bouton en bas dit ce qui va se passer.</p>"
			. "<ul><li><strong>Supprimer</strong> — proposé seulement si l'article n'a jamais servi : aucun stock, aucun mouvement, aucun rattachement. Il disparaît sans laisser de trace, ce qui est correct puisqu'il n'a jamais rien produit.</li>"
			. "<li><strong>Archiver</strong> — dès qu'il a une histoire. La fiche sort des écrans de travail, mais le stock, les mouvements et les écritures restent intacts.</li></ul>"
			. "<p>Un stock retombé à zéro ne signifie pas « sans histoire » : les mouvements passés justifient des écritures déjà comptabilisées. Effacer la fiche ferait diverger votre stock et votre grand livre, sans qu'aucun écran ne le signale.</p>"
			. "<p>Un article archivé se réactive depuis la liste du catalogue.</p>" ),

		array( 'id' => 'receptions-stock', 'cat' => 'stock',
			'titre' => "Enregistrer une réception",
			'tags' => 'réception entrée stock achat lot péremption cump fournisseur',
			'corps' =>
			"<p>Une réception saisit la quantité, le prix unitaire d'achat et le fournisseur. Elle met à jour la quantité, <strong>recalcule le coût moyen pondéré</strong> et génère l'écriture d'achat.</p>"
			. "<p><strong>Désignez toujours le fournisseur.</strong> Sans lui, la dette reste sur un compte collectif et aucun règlement ne pourra s'y imputer proprement.</p>"
			. "<p>Pour les produits périssables, renseignez le lot et la date de péremption : c'est ce qui alimente les alertes et les sorties au plus proche périmé.</p>" ),

		array( 'id' => 'sorties-transferts', 'cat' => 'stock',
			'titre' => "Sorties, transferts et entrepôts",
			'tags' => 'sortie transfert entrepôt dépôt consommation perte casse motif',
			'corps' =>
			"<p>Une <strong>sortie</strong> retire de la marchandise sans vente : consommation interne, casse, perte, don. Elle est valorisée au coût moyen et comptabilisée.</p>"
			. "<p>Un <strong>transfert</strong> déplace entre entrepôts sans rien consommer : la valeur totale ne change pas, sa localisation si.</p>"
			. "<p>Motivez toujours une sortie. Une casse non justifiée est indéfendable en contrôle, et empêche d'identifier un problème récurrent.</p>" ),

		array( 'id' => 'inventaire-physique', 'cat' => 'stock',
			'titre' => "Faire un inventaire physique",
			'tags' => 'inventaire physique comptage écart ajustement recensement campagne',
			'corps' =>
			"<p>Un inventaire compare le stock théorique au stock compté. Ouvrez une campagne, comptez (au scan ou à la main), puis validez : les écarts constatés sont ajustés et comptabilisés.</p>"
			. "<p>Comptez de préférence hors activité, ou figez les mouvements le temps du comptage — sinon vous mesurez le mouvement, pas le stock.</p>"
			. "<p>Un écart n'est pas une anomalie en soi ; un écart <em>inexpliqué et récurrent</em> sur les mêmes références, si.</p>" ),

		array( 'id' => 'valorisation-cump', 'cat' => 'stock',
			'titre' => "La valorisation au coût moyen (CUMP)",
			'tags' => 'cump valorisation coût moyen pondéré marge stock 31x',
			'corps' =>
			"<p>Chaque entrée recalcule le coût unitaire moyen pondéré : <em>(valeur du stock existant + valeur de l'entrée) ÷ quantité totale</em>. Les sorties sont valorisées à ce coût.</p>"
			. "<p>Conséquence utile : votre marge est calculée sur un coût réel, pas sur le dernier prix d'achat. Sur un marché où les prix d'approvisionnement bougent, l'écart entre les deux méthodes est loin d'être anecdotique.</p>" ),

		array( 'id' => 'ecart-stock', 'cat' => 'stock',
			'titre' => "Corriger un écart de stock",
			'tags' => 'écart correction quantité divergence journal central régularisation motif',
			'corps' =>
			"<p>La fiche article signale un <strong>écart</strong> quand la quantité du pack diffère de celle du journal central. C'est la signature d'un report qui n'a pas abouti.</p>"
			. "<p>La correction amène le stock à la quantité constatée, réaligne le journal, ajuste les lots et trace l'opération au journal d'audit. <strong>Le motif est obligatoire</strong> : une correction de stock non justifiée est une correction que personne ne pourra défendre en contrôle.</p>"
			. "<p>Par défaut, aucune écriture n'est passée : corriger une quantité qui n'a jamais été comptabilisée ne doit rien produire en comptabilité, sinon on créerait une variation de stock qui n'a jamais existé. Une case permet la régularisation comptable quand la marchandise avait bien été valorisée.</p>" ),

		);
	}

	/* ── Caisse ── */

	protected static function artCaisse() {
		return array(

		array( 'id' => 'caisse-demarrer', 'cat' => 'caisse',
			'titre' => "Ouvrir un poste de caisse",
			'tags' => 'caisse poste session fond de caisse ouverture caissier',
			'corps' =>
			"<p><em>Caisse → Postes</em> déclare vos points d'encaissement. À l'ouverture d'une session, saisissez le fond de caisse : c'est la référence sans laquelle la clôture ne peut rien vérifier.</p>"
			. "<p>Une session appartient à un caissier et à un poste. C'est ce qui permet, en cas d'écart, de savoir où et quand chercher.</p>" ),

		array( 'id' => 'caisse-vendre', 'cat' => 'caisse',
			'titre' => "Encaisser une vente",
			'tags' => 'vente caisse ticket paiement espèces mobile money carte scan retour',
			'corps' =>
			"<p>L'écran de vente accepte la douchette, la recherche par libellé et la sélection tactile. Le ticket accepte plusieurs moyens de paiement pour une même vente (espèces + mobile money, par exemple).</p>"
			. "<p>Le stock est décrémenté et la vente comptabilisée à la validation. Un retour se fait depuis le ticket d'origine, pas par une vente négative.</p>" ),

		array( 'id' => 'caisse-cloture', 'cat' => 'caisse',
			'titre' => "Clôturer la caisse (Z)",
			'tags' => 'clôture Z caisse comptage écart session rapport journalier',
			'corps' =>
			"<p>La clôture compare l'encaissement théorique au comptage réel, par moyen de paiement, et produit le rapport Z.</p>"
			. "<p>Clôturez tous les jours, même sans activité. Un écart de caisse se retrouve le jour même ; au bout d'une semaine, il est perdu.</p>" ),

		array( 'id' => 'caisse-promotions', 'cat' => 'caisse',
			'titre' => "Promotions, coupons et fidélité",
			'tags' => 'promotion remise coupon fidélité points cagnotte VIP happy hour priorité',
			'corps' =>
			"<p>Les promotions se déclarent sans code : remise en pourcentage ou en valeur, N achetés M offerts, prix par palier, plage horaire (happy hour), jours de la semaine, cible article ou famille.</p>"
			. "<p>La fidélité fonctionne en cagnotte ou en points, avec des paliers VIP. Les avantages s'appliquent automatiquement à l'encaissement.</p>"
			. "<p>Donnez une priorité à chaque promotion : sans elle, c'est l'ordre de création qui tranche quand deux promotions se recouvrent, et ce n'est probablement pas ce que vous voulez.</p>" ),

		array( 'id' => 'caisse-fiscal', 'cat' => 'caisse',
			'titre' => "Le journal fiscal inaltérable",
			'tags' => 'journal fiscal inaltérable empreinte chaînage intégrité contrôle',
			'corps' =>
			"<p>Chaque ticket est inscrit dans un journal dont les entrées sont <strong>chaînées par empreinte</strong> : modifier ou supprimer une ligne rompt la chaîne et devient détectable.</p>"
			. "<p>Ce journal ne remplace aucune obligation légale locale, mais il vous donne une preuve d'intégrité de vos encaissements, et un contrôle de cohérence que vous pouvez lancer vous-même.</p>" ),

		);
	}

	/* ── Scan ── */

	protected static function artScan() {
		return array(

		array( 'id' => 'scan-principes', 'cat' => 'scan',
			'titre' => "Le registre universel de codes",
			'tags' => 'scan code barre registre EAN QR entité résolution conditionnement facteur',
			'corps' =>
			"<p>Un même registre relie un code à ce qu'il désigne : un article, une immobilisation, un lot, un employé, une chambre. La console, le mobile et la douchette interrogent tous ce registre unique.</p>"
			. "<p>Un article peut porter plusieurs codes selon le conditionnement (unité, pack, carton, palette), chacun avec son facteur de conversion. Scanner un carton de 24 ajoute 24 unités, pas une.</p>"
			. "<p>Les codes générés en interne appartiennent à la plage EAN-13 <code>200-299</code>, non attribuée par GS1 : aucune collision possible avec un produit du commerce.</p>" ),

		array( 'id' => 'scan-generer', 'cat' => 'scan',
			'titre' => "Générer codes-barres et étiquettes",
			'tags' => 'générateur étiquette planche impression EAN QR code128 datamatrix',
			'corps' =>
			"<p><em>Scan → Générateur</em> produit un code pour un article ou un actif qui n'en a pas, et <em>Étiquettes</em> compose une planche imprimable.</p>"
			. "<p>Avant d'imprimer une série, faites un essai sur une feuille et scannez-la réellement. Une planche mal calibrée, on s'en aperçoit à la centième étiquette collée.</p>" ),

		array( 'id' => 'scan-mobile', 'cat' => 'scan',
			'titre' => "Utiliser un téléphone comme douchette",
			'tags' => 'mobile téléphone caméra appairage jeton scanner https autorisation',
			'corps' =>
			"<p><em>Scan → Mobile</em> appaire un téléphone par un jeton : l'appareil devient un scanner sans installer d'application. La caméra sert de lecteur.</p>"
			. "<p>Le jeton d'appairage vaut autorisation d'accès aux données de l'entité. Traitez-le comme un mot de passe, et révoquez-le quand l'appareil quitte l'équipe.</p>"
			. "<p>HTTPS est obligatoire : sans lui, le navigateur refusera l'accès à la caméra.</p>" ),

		array( 'id' => 'scan-contextes', 'cat' => 'scan',
			'titre' => "Les contextes de scan",
			'tags' => 'contexte scan vente réception inventaire récolement contrôle',
			'corps' =>
			"<p>Le même code ne doit pas faire la même chose selon ce que vous êtes en train de faire. Le <strong>contexte</strong> le décide : contrôle (simple consultation), réception, vente, inventaire, récolement des actifs.</p>"
			. "<p>Hors campagne, le scan d'une immobilisation redevient une simple consultation. C'est ce qui évite de pointer un actif par accident en scannant pour vérifier une référence.</p>" ),

		);
	}

	/* ── Immobilisations ── */

	protected static function artImmo() {
		return array(

		array( 'id' => 'immo-creer', 'cat' => 'immo',
			'titre' => "Enregistrer une immobilisation",
			'tags' => 'immobilisation actif acquisition code interne code-barres emplacement détenteur',
			'corps' =>
			"<p><em>Comptabilité → Immobilisations → Nouvelle</em> : libellé, date d'acquisition, valeur, durée d'amortissement, comptes d'immobilisation, d'amortissement et de dotation.</p>"
			. "<p>La fiche porte aussi un emplacement, un détenteur et la date du dernier pointage : c'est elle que le comptable consulte, et c'est elle qui rend le récolement possible.</p>"
			. "<p>Un code-barres est attribué automatiquement à la création, en plus du code interne comptable. L'un identifie l'actif dans les comptes, l'autre le rend lisible par une douchette.</p>" ),

		array( 'id' => 'immo-amortir', 'cat' => 'immo',
			'titre' => "Plan d'amortissement et dotations",
			'tags' => 'amortissement dotation plan linéaire durée exercice vnc',
			'corps' =>
			"<p>Le plan d'amortissement se calcule à partir de la valeur d'acquisition et de la durée. L'écran <em>Amortir</em> génère les dotations de l'exercice et les comptabilise.</p>"
			. "<p>Passez les dotations avant d'éditer vos états financiers : un bilan sans dotations surévalue l'actif et le résultat, des deux côtés à la fois.</p>" ),

		array( 'id' => 'immo-recolement', 'cat' => 'immo',
			'titre' => "Le récolement : inventaire physique du parc",
			'tags' => 'récolement inventaire actif campagne pointage scan introuvable rapport',
			'corps' =>
			"<p>Le récolement est à l'immobilisation ce que l'inventaire est au stock : on vérifie que ce que disent les comptes existe encore physiquement.</p>"
			. "<p>Ouvrez une campagne, pointez au scan ou à la main, clôturez avec une note. Une seule campagne ouverte à la fois. Repointer un actif n'est pas une erreur : sur un parc, on repasse souvent devant le même bureau ; l'emplacement est simplement mis à jour.</p>"
			. "<p>Le rapport distingue les actifs <strong>vus</strong> des <strong>introuvables</strong> et chiffre la valeur d'acquisition en jeu. Les actifs cédés sont écartés du périmètre.</p>"
			. "<p><strong>Aucune écriture n'est passée d'office à la clôture</strong> : un actif introuvable peut être égaré, prêté ou volé, et seule une décision humaine distingue les trois. Le rapport reste consultable pour l'étayer.</p>" ),

		array( 'id' => 'immo-cession', 'cat' => 'immo',
			'titre' => "Céder ou mettre au rebut",
			'tags' => 'cession rebut sortie plus-value moins-value vente actif',
			'corps' =>
			"<p>La cession solde la valeur nette comptable et constate la plus ou moins-value. La mise au rebut suit la même mécanique avec un prix de cession nul.</p>"
			. "<p>Un actif cédé sort automatiquement du périmètre des récolements suivants : le compter comme manquant noierait le vrai signal.</p>" ),

		);
	}

	/* ── Fiscalité ── */

	protected static function artFiscalite() {
		return array(

		array( 'id' => 'regimes', 'cat' => 'fiscalite',
			'titre' => "Régimes fiscaux et TEE",
			'tags' => 'régime tee microentreprise cga impôt assujettissement',
			'corps' =>
			"<p>Le régime fiscal de l'entité conditionne les déclarations disponibles. Définissez-le dans <em>Fiscalité → Régime</em>.</p>"
			. "<p>La Taxe de l'Entrepreneur (TEE) se calcule sur le chiffre d'affaires de l'année N-1, mensualisée. Un régime mal renseigné produit des déclarations inapplicables : vérifiez-le avant la première échéance, pas après.</p>" ),

		array( 'id' => 'declaration-tva', 'cat' => 'fiscalite',
			'titre' => "Préparer une déclaration de TVA",
			'tags' => 'tva déclaration collectée déductible crédit à payer période contrôle',
			'corps' =>
			"<p><em>Fiscalité → Nouvelle déclaration</em>, type TVA. L'aperçu calcule la TVA collectée et la TVA déductible sur la période, et en déduit le montant à payer ou le crédit reportable.</p>"
			. "<p>Contrôlez avant de valider : une TVA déductible anormalement basse signale souvent des factures d'achat non saisies, et une TVA collectée basse, des ventes restées en brouillon.</p>" ),

		array( 'id' => 'fiscalite-journal', 'cat' => 'fiscalite',
			'titre' => "Le journal des déclarations",
			'tags' => 'journal déclaration historique échéance suivi paiement preuve',
			'corps' =>
			"<p>Le journal conserve l'historique des déclarations : période, montant, statut, date. C'est la mémoire de vos obligations, et de loin le moyen le plus simple de prouver qu'une déclaration a bien été faite.</p>" ),

		);
	}

	/* ── RH ── */

	protected static function artRh() {
		return array(

		array( 'id' => 'employes', 'cat' => 'rh',
			'titre' => "Gérer les employés",
			'tags' => 'employé salarié contrat organisation poste embauche fiche',
			'corps' =>
			"<p><em>RH → Employés</em> centralise les fiches : identité, contrat, poste, rattachement hiérarchique, rémunération de base.</p>"
			. "<p>Ces données alimentent la paie, les congés et les analyses RH. Une fiche incomplète se paie au moment du premier bulletin.</p>" ),

		array( 'id' => 'paie', 'cat' => 'rh',
			'titre' => "Préparer et comptabiliser la paie",
			'tags' => 'paie bulletin salaire cotisation période masse salariale',
			'corps' =>
			"<p><em>Paie → Bulletins</em> : générez les bulletins de la période, contrôlez-les, puis comptabilisez.</p>"
			. "<p>Contrôlez la masse salariale totale avant comptabilisation : un écart important d'un mois sur l'autre s'explique toujours par quelque chose — une prime, une embauche, un rappel — et il vaut mieux le savoir avant que le comptable ne le demande.</p>" ),

		array( 'id' => 'conges-absences', 'cat' => 'rh',
			'titre' => "Congés, absences et avances",
			'tags' => 'congé absence avance acompte solde demande validation retenue',
			'corps' =>
			"<p>Les demandes de congés suivent un circuit de validation. <strong>À la validation seulement</strong>, le congé est reporté dans les absences de paie : il déduit le solde, et un congé <em>sans solde</em> réduit le bulletin du mois. Un congé à cheval sur deux mois est découpé par mois. Le refus ou la suppression retire l'absence.</p>"
			. "<p>Si le bulletin du mois est déjà édité, il ne tient pas compte du changement : l'écran le dit. Supprimez-le et régénérez-le pour l'y intégrer.</p>"
			. "<p>Les avances et prêts sur salaire sont retenus sur le bulletin du mois d'échéance. La retenue est plafonnée par la <strong>quotité cessible</strong> (Paie → Barèmes, défaut : le tiers du net) : une échéance qui ne tient pas sous le plafond est reportée entière au mois suivant, jamais coupée en deux.</p>" ),

		array( 'id' => 'pointage', 'cat' => 'rh',
			'titre' => "Pointer les présences",
			'tags' => 'pointage présence badge scan heures entrée sortie assiduité',
			'corps' =>
			"<p><em>RH → Pointage</em> enregistre les entrées et sorties, à la main ou par badge scanné (module Scan, contexte « Pointage du personnel »). Sans sens précisé, le pointage alterne tout seul : un lecteur de badge n'a pas de bouton « je sors ».</p>"
			. "<p>La synthèse mensuelle donne les jours pointés et les heures. <strong>Seuls les couples entrée→sortie complets comptent des heures</strong> : une sortie oubliée est signalée comme non appairée, jamais devinée — la deviner créerait des heures supplémentaires nées d'un oubli.</p>" ),

		array( 'id' => 'avances-versement', 'cat' => 'rh',
			'titre' => "Verser une avance ou un prêt au personnel",
			'tags' => 'avance prêt versement acompte salarié trésorerie caisse retenue remboursement',
			'corps' =>
			"<p>Une avance suit deux actes distincts. <strong>Valider</strong>, c'est approuver la demande. <strong>Verser</strong>, c'est remettre l'argent — et c'est le versement, pas la validation, qui déclenche la retenue sur la paie : le logiciel ne reprend jamais une somme qu'il n'a pas remise.</p>"
			. "<p>Le bouton <em>Verser</em> demande de quel compte de trésorerie sort l'argent, puis passe l'écriture : sortie de trésorerie et créance sur le salarié. Chaque retenue de paie vient ensuite éteindre cette créance, si bien que le solde du compte d'avances correspond toujours au reste dû.</p>"
			. "<p>Contre-passer l'écriture de versement remet l'avance en « à verser » et suspend les retenues. Une avance versée ne se supprime pas directement — on contre-passe d'abord.</p>" ),

		array( 'id' => 'missions-frais', 'cat' => 'rh',
			'titre' => "Comptabiliser les frais de mission",
			'tags' => 'mission ordre de mission frais transport hôtel repas remboursement note de frais déplacement',
			'corps' =>
			"<p>Un ordre de mission suit le même circuit que les congés : demande, manager, RH, validation. <strong>La validation ne suffit pas à faire entrer les frais en comptabilité</strong> : la mission apparaît alors en « à comptabiliser », et c'est le bouton <em>Comptabiliser</em> qui passe l'écriture.</p>"
			. "<p>Cette écriture porte les frais en charge et inscrit le total au crédit du compte de <strong>frais avancés au personnel</strong> : c'est ce que l'entreprise doit encore au salarié qui a payé de sa poche. Le règlement se fait ensuite normalement, depuis la comptabilité ou la caisse.</p>"
			. "<p>Les comptes d'imputation des quatre postes (transport, hôtel, repas, autres) et le compte de dette sont paramétrables. Une mission comptabilisée ne se supprime plus : on contre-passe l'écriture, ce qui la remet en « à comptabiliser » sans annuler les validations déjà obtenues.</p>" ),

		array( 'id' => 'regime-its', 'cat' => 'rh',
			'titre' => "Impôts sur salaires : l'ITS unifié et la contribution employeur",
			'tags' => 'its igr cn contribution nationale régime unifié barème impôt salaire exonération ricf parts expatrié contribution employeur cmu',
			'corps' =>
			"<p>Depuis le <strong>1er janvier 2024</strong> (ordonnance n° 2023-719), l'IS, la contribution nationale et l'IGR sont remplacés par un <strong>ITS unique</strong>. Il se calcule chaque mois sur le brut imposable, sans abattement ni quotient familial, au barème 0 % jusqu'à 75 000 F, puis 16, 21, 24, 28 et 32 %. On en déduit ensuite la <strong>réduction pour charges de famille</strong> (RICF) : de 0 F pour 1 part à 44 000 F pour 5 parts. <em>Paie → Barèmes</em> applique ce calcul par défaut pour toute année à partir de 2024.</p>"
			. "<p>Les <strong>parts</strong> se déduisent de la situation de famille saisie sur la fiche employé : 1 pour un célibataire, divorcé ou veuf sans enfant, 2 pour un marié, plus ½ part par enfant à charge (1 part par enfant infirme) ; 1,5 + ½ par enfant pour un célibataire ou divorcé avec enfants, 2 + ½ par enfant pour un veuf avec enfants. Jamais plus de 5.</p>"
			. "<p>L'employeur paie en plus une <strong>contribution sur les salaires</strong> : 2,8 % du brut imposable pour le personnel local, 12 % pour un expatrié (case à cocher sur la fiche). Elle figure dans les charges du bulletin, dans l'OD de paie (compte 641300, dette au 447) et dans la déclaration ITS. La <strong>CMU</strong> du salarié lui-même est partagée : 500 F à sa charge, 500 F à celle de l'employeur ; ses ayants droit restent à sa charge.</p>"
			. "<p>Les cases <strong>ITS</strong>, <strong>IGR</strong> et <strong>Contribution Nationale</strong> restent disponibles pour les années antérieures à 2024 ou un cas particulier : un prélèvement décoché disparaît du bulletin et des déclarations. <strong>Modifier ces réglages change le net de tous vos salariés</strong> ; ils valent pour une année donnée, et les bulletins déjà édités ne sont jamais recalculés.</p>"
			. "<p>Un dossier dont les barèmes 2024 à 2026 avaient été enregistrés avec l'ancien calcul livré par défaut est passé automatiquement à l'ITS unifié (l'écran le signale). Si vous aviez modifié le barème vous-même, il est conservé et signalé pour que vous le revoyiez.</p>" ),

		array( 'id' => 'paiement-salaires', 'cat' => 'rh',
			'titre' => "Payer les salaires et préparer le virement",
			'tags' => 'paiement salaire virement banque rib 421 net à payer état de virement caisse',
			'corps' =>
			"<p>L'OD de paie inscrit le net de chaque salarié au compte 421. <em>Paie → Paiement des salaires</em> le solde : choisissez la période, cochez les salariés réglés ensemble (par exemple ceux payés par virement), le compte de trésorerie et la date. L'écriture débite le 421 et crédite la banque ou la caisse, au journal correspondant ; comme toute écriture, elle peut passer d'abord par le journal temporaire.</p>"
			. "<p>Le paiement n'est possible qu'après l'OD de paie, et un salarié n'est jamais payé deux fois. L'<strong>état de virement</strong> (impression ou CSV) liste les salariés restant à payer avec leur banque et leur RIB, et signale ceux dont le RIB manque (fiche RH).</p>"
			. "<p>Sur le bulletin, les <strong>heures supplémentaires</strong> peuvent être reprises du pointage (au-delà de 40 h par semaine : 6 h à +15 %, puis +50 %), la <strong>prime d'ancienneté</strong> se calcule sur la date d'embauche quand aucun taux n'est saisi (2 % à 2 ans, + 1 % par an, 25 % au plus), et, sur option, les jours de congé sont payés par l'<strong>allocation de congé</strong>.</p>" ),

		array( 'id' => 'paie-annuel', 'cat' => 'rh',
			'titre' => "Fin d'année : État 301, DISA et budget de masse salariale",
			'tags' => 'état 301 disa annuel cumul cnps dgi budget masse salariale conformité barème maestro',
			'corps' =>
			"<p><em>Paie → Récapitulatif annuel</em> additionne les bulletins de l'année par salarié : l'<strong>État 301</strong> (DGI : brut, brut imposable, ITS retenu, contribution employeur, net) et la <strong>DISA</strong> (CNPS : salaire soumis, retraite salariale et patronale, prestations familiales et accidents du travail, CMU). Chaque tableau s'imprime et s'exporte en CSV ; un salarié sans numéro CNPS est signalé. Les totaux égalent la somme des déclarations mensuelles.</p>"
			. "<p>Le même écran compare la masse salariale (coût employeur) au <strong>budget annuel</strong> que vous saisissez, mois par mois. Chaque bulletin affiche aussi ses <strong>cumuls</strong> depuis janvier.</p>"
			. "<p>Maestro contrôle les barèmes de l'année contre les textes en vigueur (ITS unifié, RICF, contribution employeur, SMIG) et le signale en cas d'écart ; <em>Paie → Barèmes</em> liste les mêmes écarts.</p>" ),

		array( 'id' => 'declarations-sociales', 'cat' => 'rh',
			'titre' => "Déclarations sociales",
			'tags' => 'déclaration sociale cnps cotisation export csv organisme',
			'corps' =>
			"<p><em>Paie → Déclarations</em> agrège les cotisations de la période, avec impression et export CSV pour transmission aux organismes.</p>" ),

		);
	}

	/* ── Analyse ── */

	protected static function artAnalyse() {
		return array(

		array( 'id' => 'maestro', 'cat' => 'analyse', 'niveau' => 'essentiel',
			'titre' => "Maestro : où le trouver et ce qu'il sait faire",
			'tags' => 'maestro copilot ia assistant priorités prévision simulation leviers analyse',
			'corps' =>
			"<p><strong>Barre latérale, rubrique <em>Services</em> → 🤖 Maestro.</strong> Il est aussi derrière le bouton <em>Copilot</em> de la barre supérieure — c'est le même moteur.</p>"
			. "<p>Il demande le module <em>Analyse</em> : sans lui, l'entrée n'apparaît pas.</p>"
			. "<p><strong>Ce qu'il fait :</strong></p>"
			. "<ul>"
			. "<li><em>Priorités</em> — ce qui mérite votre attention aujourd'hui, classé.</li>"
			. "<li><em>Prévoir</em> — trésorerie et activité à venir, avec le degré de confiance.</li>"
			. "<li><em>Simuler</em> — l'effet d'une décision avant de la prendre.</li>"
			. "<li><em>Leviers</em> — sur quoi agir pour un résultat donné.</li>"
			. "<li><em>Pourquoi / Expliquer</em> — d'où vient un chiffre, quelles écritures le composent.</li>"
			. "<li><em>Opportunités</em> — ce qu'il repère et que vous n'avez pas demandé.</li>"
			. "</ul>"
			. "<p><strong>Il répond avec VOS droits</strong>, jamais avec les siens : il n'ouvre aucun accès que vous n'avez pas déjà.</p>"
			. "<p><strong>Lisez toujours son degré de confiance.</strong> Une réponse à 40 % est une piste, pas un fait — et c'est ainsi qu'elle sera citée trois jours plus tard si vous ne le précisez pas.</p>"
			. "<p>Sur un exercice sans écriture, Maestro n'a rien à analyser : ses écrans restent vides, et c'est normal.</p>"
			. "<p>Si Connect est souscrit, vous pouvez aussi l'appeler dans une conversation avec <code>@maestro</code>.</p>" ),

		array( 'id' => 'dashboards', 'cat' => 'analyse',
			'titre' => "Lire les tableaux de bord",
			'tags' => 'analyse dashboard graphique couche opérationnel performance prédictif',
			'corps' =>
			"<p>Le module <em>Analyse</em> agrège vos données en trois couches : l'<strong>opérationnel</strong> (ce qui se passe aujourd'hui), la <strong>performance</strong> (pourquoi c'est arrivé), le <strong>prédictif</strong> (ce qui vient).</p>"
			. "<p>Des vues spécialisées existent par domaine : financière, commerciale, clients, rentabilité, stock, RH, recouvrement, budgétaire, sectorielle.</p>" ),

		array( 'id' => 'kpi', 'cat' => 'analyse',
			'titre' => "Définir vos KPI et vos alertes",
			'tags' => 'kpi indicateur cible seuil alerte règle mesure',
			'corps' =>
			"<p><em>Analyse → Centre KPI</em> : créez vos indicateurs (métrique, cible, sens de progression). <em>Alertes</em> définit des règles à seuil qui remontent sur le tableau de bord et dans les notifications.</p>"
			. "<p>Trois à cinq indicateurs suivis sérieusement valent mieux que vingt consultés une fois. Choisissez ceux sur lesquels vous êtes prêt à agir.</p>" ),

		array( 'id' => 'analytique-axes', 'cat' => 'analyse',
			'titre' => "Analytique : divisions, axes et centres de coûts",
			'tags' => 'analytique axe division centre de coût profit ventilation répartition',
			'corps' =>
			"<p>La comptabilité générale dit <em>combien</em>. L'analytique dit <em>où</em> et <em>pour quoi</em>. Une charge de carburant est un compte <code>60x</code> en général, et « véhicule 3 / chantier Nord » en analytique.</p>"
			. "<p>Déclarez vos <strong>divisions</strong> (centres de coûts et de profit) et vos <strong>axes</strong> (les dimensions d'analyse : projet, site, activité, client), puis ventilez à la saisie ou par des clés de répartition automatiques.</p>"
			. "<p>Commencez avec un seul axe. Un modèle analytique à quatre axes mis en place d'emblée n'est jamais alimenté correctement.</p>" ),

		array( 'id' => 'budgets', 'cat' => 'analyse',
			'titre' => "Budgets et contrôle budgétaire",
			'tags' => 'budget prévision écart contrôle budgétaire comparatif axe révision',
			'corps' =>
			"<p>Saisissez vos budgets par compte, par axe ou par division, puis suivez les écarts entre le réalisé et le prévu, mois par mois.</p>"
			. "<p>Le contrôle budgétaire n'a d'intérêt que si le budget est révisé quand la réalité change. Un budget figé en janvier ne mesure plus rien en septembre.</p>" ),

		array( 'id' => 'data-warehouse', 'cat' => 'analyse',
			'titre' => "Rafraîchir le Data Warehouse",
			'tags' => 'warehouse instantané performance rafraîchir analyse lenteur retard',
			'corps' =>
			"<p>Les analyses lisent des instantanés pré-calculés pour ne pas ralentir l'exploitation. Après une période d'activité, cliquez <em>Rafraîchir les données</em>.</p>"
			. "<p>Si un tableau de bord semble en retard sur la réalité, c'est presque toujours qu'il faut le rafraîchir — avant de suspecter une erreur de saisie.</p>" ),

		);
	}

	/* ── Packs ── */

	/* ── Maestro (rattaché à l'Analyse) ── */

	protected static function artPacks() {
		return array(

		array( 'id' => 'packs-principes', 'cat' => 'packs', 'niveau' => 'essentiel',
			'titre' => "Qu'est-ce qu'un pack métier",
			'tags' => 'pack métier vertical secteur activité adaptation terminologie',
			'corps' =>
			"<p>Un pack métier adapte l'ERP à votre activité. Il apporte quatre choses :</p>"
			. "<ul><li>une <strong>terminologie</strong> — un hôtel parle de réservations, une clinique de patients, un BTP de chantiers ;</li>"
			. "<li>des <strong>écrans dédiés</strong> à l'objet central du métier ;</li>"
			. "<li>des <strong>comptes</strong> spécifiques ajoutés au plan comptable ;</li>"
			. "<li>des <strong>écritures automatiques</strong> propres aux opérations du métier.</li></ul>"
			. "<p>Le socle reste identique : même comptabilité, même stock, même caisse. Le pack ne remplace rien, il habille et complète.</p>" ),

		array( 'id' => 'packs-choisir', 'cat' => 'packs',
			'titre' => "Activer ou changer de pack",
			'tags' => 'pack activation changement société licence multi',
			'corps' =>
			"<p>Le pack se choisit dans <em>Paramètres → Pack métier</em>, à condition que votre licence l'autorise. Une licence « multi » laisse le choix à chaque entité : deux sociétés d'un même cabinet peuvent tourner sur des packs différents.</p>"
			. "<p>Changer de pack en cours de route ne détruit rien, mais les écrans et la terminologie changent, et les données saisies dans les écrans du pack précédent deviennent moins accessibles. Mieux vaut trancher au démarrage.</p>" ),

		array( 'id' => 'packs-liste', 'cat' => 'packs',
			'titre' => "Les 50 packs disponibles",
			'tags' => 'liste packs métiers secteurs disponibles catalogue',
			'corps' =>
			"<p><strong>Commerce &amp; distribution</strong> — commerce de proximité (retail, supérette) ; commerce spécialisé (quincaillerie, prêt-à-porter, cosmétiques, téléphonie, bazar &amp; textile) ; équipement de la maison (électroménager) ; gros &amp; distribution (distribution, station-service).</p>"
			. "<p><strong>Restauration &amp; hôtellerie</strong> — restaurant, fast-food, maquis, bar, boulangerie, hôtel, événementiel.</p>"
			. "<p><strong>Santé</strong> — pharmacie, clinique, hôpital, cabinet médical, laboratoire, optique.</p>"
			. "<p><strong>Industrie &amp; artisanat</strong> — industrie, imprimerie, menuiserie, garage, BTP.</p>"
			. "<p><strong>Agriculture</strong> — agriculture, élevage, coopérative.</p>"
			. "<p><strong>Services &amp; professions</strong> — cabinet, cabinet juridique, immobilier, transit, transport, logistique.</p>"
			. "<p><strong>Finance</strong> — banque, microfinance, assurance (compagnie et courtage).</p>"
			. "<p><strong>Éducation &amp; institutions</strong> — école, université, ONG, église.</p>"
			. "<p><strong>Création &amp; médias</strong> — studio, audiovisuel, médias, distribution musicale, creative.</p>"
			. "<p>Et un pack <strong>générique</strong> si aucun ne correspond exactement.</p>" ),

		);
	}

	/* ── Administration ── */

	protected static function artAdmin() {
		return array(

		array( 'id' => 'sauvegarde', 'cat' => 'admin', 'niveau' => 'essentiel',
			'titre' => "Sauvegarder vos données",
			'tags' => 'sauvegarde backup restauration fichier base perte dossier données',
			'corps' =>
			"<p>Toutes vos données vivent dans un dossier unique du serveur : <code>wp-content/uploads/finakop-erp-core-data/</code>. Sauvegarder l'application, c'est sauvegarder ce dossier.</p>"
			. "<p><strong>Faites une copie avant toute opération de maintenance</strong> — purge, réinitialisation d'exercice, mise à jour. Les purges sont irréversibles et aucune corbeille ne les rattrape.</p>"
			. "<p>Une sauvegarde jamais restaurée n'est pas une sauvegarde. Testez la restauration au moins une fois, sur une installation d'essai.</p>" ),

		array( 'id' => 'maintenance', 'cat' => 'admin',
			'titre' => "Maintenance des données",
			'tags' => 'maintenance purge suppression exercice réinitialisation irréversible admin',
			'corps' =>
			"<p><em>Paramètres → Maintenance</em>, réservé aux administrateurs. Cinq opérations, toutes <strong>irréversibles</strong> :</p>"
			. "<ul><li>supprimer les écritures d'une période ;</li>"
			. "<li>supprimer les factures clients ou fournisseurs d'une période, avec ou sans leurs écritures ;</li>"
			. "<li>réinitialiser un exercice complet (le paramétrage est conservé) ;</li>"
			. "<li>purger toutes les transactions de l'entité ;</li>"
			. "<li>annuler une écriture précise, par contre-passation ou suppression.</li></ul>"
			. "<p>Les quatre premières servent à repartir d'une base propre, typiquement après une phase d'essai. En exploitation courante, c'est l'annulation d'une écriture précise qu'il faut utiliser — pas une purge de période d'un jour.</p>"
			. "<p>Sauvegardez avant. Toujours.</p>" ),

		array( 'id' => 'automatisations', 'cat' => 'admin',
			'titre' => "Automatiser sans écrire de code",
			'tags' => 'automatisation règle déclencheur planificateur workflow processus',
			'corps' =>
			"<p>Les règles d'automatisation déclenchent une action quand une condition est remplie : alerter à un seuil de stock, relancer une facture échue, générer un document.</p>"
			. "<p>Chaque règle se déclare dans l'interface — déclencheur, condition, action — sans aucun code.</p>"
			. "<p>Créez-les une par une et observez-les quelques jours. Dix règles activées d'un coup produisent un bruit dont plus personne ne cherche l'origine.</p>" ),

		array( 'id' => 'ged', 'cat' => 'admin',
			'titre' => "La GED : rattacher les justificatifs",
			'tags' => 'ged document justificatif scan pièce jointe archivage contrôle',
			'corps' =>
			"<p>Rattachez un fichier à une écriture, une facture, un reçu ou un actif : photo du bon de livraison, scan du chèque, contrat signé.</p>"
			. "<p>C'est l'écart entre une comptabilité défendable et une comptabilité à reconstituer. En contrôle, la pièce justificative se demande toujours, et la chercher dans un classeur coûte cher.</p>" ),

		array( 'id' => 'parametres-generaux', 'cat' => 'admin',
			'titre' => "Paramètres généraux et écritures assistées",
			'tags' => 'paramètre configuration intégration trésorerie processus écritures assistées récurrent',
			'corps' =>
			"<p><em>Paramètres</em> regroupe la configuration de l'entité : comptes de trésorerie, exercices, établissements, processus et intégrations externes.</p>"
			. "<p>Les <strong>écritures assistées</strong> méritent un détour : elles préparent des écritures récurrentes que le comptable déclenche et valide, plutôt que de les saisir de zéro chaque mois.</p>" ),

		);
	}

	/* ── Sécurité ── */

	protected static function artSecurite() {
		return array(

		array( 'id' => 'bonnes-pratiques-securite', 'cat' => 'securite', 'niveau' => 'essentiel',
			'titre' => "Les cinq règles de sécurité qui comptent",
			'tags' => 'sécurité bonnes pratiques https mot de passe accès sauvegarde chiffrement',
			'corps' =>
			"<p>Par ordre d'importance réelle :</p>"
			. "<ol><li><strong>Changer le mot de passe <code>admin</code></strong> dès l'installation, et n'utiliser que des comptes nominatifs ensuite.</li>"
			. "<li><strong>Servir l'application en HTTPS.</strong> Sans lui, les identifiants circulent en clair et la caméra du scanner mobile ne fonctionnera pas.</li>"
			. "<li><strong>Définir la clé de chiffrement</strong> <code>FKC_ENCRYPTION_KEY</code> dans <code>wp-config.php</code> avant de saisir des données sensibles.</li>"
			. "<li><strong>Sauvegarder</strong> le dossier de données, et tester une restauration.</li>"
			. "<li><strong>Limiter le rôle administrateur</strong> : il donne accès aux purges irréversibles.</li></ol>"
			. "<p>Aucune de ces cinq règles n'est technique au point d'exiger un prestataire. Les quatre premières se font en une heure.</p>" ),

		array( 'id' => 'chiffrement', 'cat' => 'securite',
			'titre' => "Chiffrement des données sensibles",
			'tags' => 'chiffrement clé wp-config secret coffre sécurité repos',
			'corps' =>
			"<p>Les données sensibles sont chiffrées au repos. En production, définissez <code>FKC_ENCRYPTION_KEY</code> dans <code>wp-config.php</code> : sans elle, une clé de repli est utilisée, moins solide.</p>"
			. "<p>Conservez cette clé ailleurs que sur le serveur. La perdre rend les secrets chiffrés illisibles, y compris depuis une sauvegarde.</p>" ),

		array( 'id' => 'audit', 'cat' => 'securite',
			'titre' => "Le journal d'audit",
			'tags' => 'audit journal traçabilité connexion suppression historique responsabilité',
			'corps' =>
			"<p>Le journal enregistre les événements sensibles : connexions, échecs répétés, installation de licence, suppressions et contre-passations d'écritures, corrections de stock, retraits d'articles.</p>"
			. "<p>Il n'a de valeur que si chaque personne a son propre compte. Avec un compte partagé, il enregistre fidèlement que « admin » a fait quelque chose — ce qui n'apprend rien.</p>" ),

		array( 'id' => 'cles-api', 'cat' => 'securite',
			'titre' => "Créer et utiliser une clé API",
			'tags' => 'api clé bearer scope mobile intégration partenaire quota openapi',
			'corps' =>
			"<p><em>Sécurité → Clés API</em> : générez une clé avec un libellé, des scopes et éventuellement une entité de rattachement. <strong>Copiez-la immédiatement, elle n'est affichée qu'une fois.</strong></p>"
			. "<p>Authentification par en-tête <code>Authorization: Bearer &lt;clé&gt;</code>. La documentation technique et le schéma OpenAPI sont exposés sur <code>/api/docs</code>.</p>"
			. "<p>Donnez le scope minimum et une clé par intégration : révoquer une clé compromise ne doit pas couper tout le reste. Le nombre d'appels mensuels est plafonné par votre palier.</p>" ),

		);
	}

	/* ── Creative ── */

	protected static function artCreative() {
		return array(

		array( 'id' => 'secteur-creative', 'cat' => 'creative',
			'titre' => "Choisir votre secteur créatif",
			'tags' => 'creative secteur label musique terminologie métier',
			'corps' =>
			"<p>L'édition Creative s'adapte à plusieurs métiers de la création. Dans <em>Label musical → Secteur d'activité</em>, choisissez le vôtre : la terminologie de l'interface et les comptes proposés suivent.</p>" ),

		array( 'id' => 'royalties', 'cat' => 'creative',
			'titre' => "Importer et répartir les royalties",
			'tags' => 'royalties streaming plateforme artiste reversement relevé répartition part',
			'corps' =>
			"<p>Importez un relevé de plateforme dans <em>Label → Revenus</em>. La répartition selon les parts d'artiste est automatique, puis comptabilisée. Les reversements se suivent dans <em>Paiements</em>.</p>"
			. "<p>Vérifiez les parts contractuelles avant le premier import : une répartition fausse se propage à toutes les périodes suivantes.</p>" ),

		array( 'id' => 'contrats-artistes', 'cat' => 'creative',
			'titre' => "Artistes, œuvres et contrats",
			'tags' => 'artiste œuvre contrat catalogue projet concert part rentabilité',
			'corps' =>
			"<p>Le catalogue relie artistes, œuvres, projets et contrats. Chaque contrat porte les parts qui serviront à la répartition des revenus.</p>"
			. "<p>Les concerts et les projets se suivent séparément, avec leur propre rentabilité.</p>" ),

		);
	}

	/* ── FinaKop Connect ── */

	/**
	 * La documentation de Connect dit d'abord CE QUE LE SERVICE NE FAIT PAS.
	 *
	 * Une messagerie d'entreprise soulève immédiatement trois questions que
	 * personne n'ose poser : mon patron lit-il mes messages privés ? le
	 * fournisseur que j'invite voit-il ce qu'on disait avant lui ? l'éditeur
	 * peut-il lire nos conversations ? Y répondre en premier, franchement,
	 * évite les réponses inventées dans les couloirs — qui sont toujours
	 * pires que la vérité.
	 */
	protected static function artConnect() {
		return array(

		array( 'id' => 'connect-quoi', 'cat' => 'connect', 'niveau' => 'essentiel',
			'titre' => "FinaKop Connect : à quoi ça sert, et pourquoi pas WhatsApp",
			'tags' => 'connect messagerie discussion conversation whatsapp service',
			'corps' =>
			"<p>Connect est une messagerie d'entreprise intégrée à FinaKop. C'est un <strong>service optionnel</strong> : il n'apparaît que si votre licence le porte.</p>"
			. "<p><strong>Ce qu'il apporte que WhatsApp ne fera jamais :</strong> ouvrir la discussion <em>d'une facture</em>. Depuis la liste des factures ou des demandes d'achat, le bouton 💬 ouvre un fil qui ne concerne que cette pièce — avec la pièce affichée à côté, ses boutons d'action, et la trace de qui a dit quoi avant qu'elle soit payée. Trois mois plus tard, la discussion est encore là, attachée au bon document.</p>"
			. "<p>Le reste — groupes, canaux, mentions, fichiers — n'est que le véhicule. Si vous n'utilisez Connect que pour discuter, WhatsApp vous suffira.</p>"
			. "<p><strong>Ce qu'il ne fait pas :</strong> il ne remplace pas votre téléphone pour joindre un client qui n'est pas dans FinaKop, et il n'envoie pas de SMS.</p>" ),

		array( 'id' => 'connect-qui-voit-quoi', 'cat' => 'connect', 'niveau' => 'essentiel',
			'titre' => "Qui peut lire quoi — la question qu'il faut poser",
			'tags' => 'connect confidentialité privé lecture admin audit droits conversation',
			'corps' =>
			"<p><strong>Une conversation privée ou de groupe ne se lit que par ses membres.</strong> L'administrateur de l'application n'y entre pas : administrer un outil n'est pas lire les messages de ses collègues.</p>"
			. "<p>Une seule exception, et elle laisse une trace : le droit <em>connect.audit</em>, réservé à la direction, permet de consulter une conversation en cas de litige. <strong>Chaque consultation est journalisée</strong> — qui a lu, quoi, quand. Le droit d'audit n'est pas un droit de lire en silence.</p>"
			. "<p><strong>Une conversation rattachée à une pièce suit le droit sur la pièce.</strong> Voir la discussion d'une facture, c'est exactement pouvoir voir cette facture. Si vous perdez l'accès au module, vous perdez la discussion au même instant — rien n'est recopié quelque part qui survivrait.</p>"
			. "<p><strong>Un canal est ouvert à toute la société.</strong> Ce qu'on y écrit est lu par des gens qu'on n'a pas choisis : c'est le bon endroit pour une annonce, le mauvais pour une remarque sur un collègue.</p>" ),

		array( 'id' => 'connect-chiffrement', 'cat' => 'connect', 'niveau' => 'essentiel',
			'titre' => "« Messagerie cryptée » : ce que cela veut dire exactement",
			'tags' => 'connect chiffrement sécurité confidentiel niveau bout en bout e2ee',
			'corps' =>
			"<p>Trois niveaux existent, et la différence compte.</p>"
			. "<p><strong>Niveau 1 — sécurisé.</strong> Le trafic est chiffré entre votre navigateur et le serveur, et le fichier de données vit hors du site web. C'est le niveau de tout le reste de FinaKop.</p>"
			. "<p><strong>Niveau 2 — confidentiel.</strong> Le contenu des messages est chiffré dans la base, avec une clé par conversation. <strong>Ce que cela protège :</strong> la fuite d'une sauvegarde — l'incident le plus courant. <strong>Ce que cela ne protège pas :</strong> l'administrateur du serveur, qui détient la clé. Nous le disons parce que c'est vrai : promettre l'inverse serait vous vendre une garantie que le produit ne tient pas. Les pièces jointes sont chiffrées elles aussi ; au-delà de 8 Mo, le dépôt est refusé plutôt qu'écrit en clair.</p>"
			. "<p><strong>Niveau 3 — bout en bout.</strong> Refusé pour l'instant, volontairement. Dans un navigateur, c'est le serveur lui-même qui envoie le code qui chiffre : la promesse ne tiendrait pas. Il viendra avec une application installée.</p>"
			. "<p><strong>Le niveau est fixé à la création et ne change plus.</strong> L'abaisser rendrait lisible après coup ce qui a été dit sous une autre promesse. Une conversation rattachée à une pièce reste au niveau 2 : elle fait partie de la piste d'audit du document.</p>"
			. "<p>La seule conversation réellement chiffrée de bout en bout est un <strong>appel</strong> : la voix et l'image passent d'un navigateur à l'autre sans toucher le serveur.</p>" ),

		array( 'id' => 'connect-pieces', 'cat' => 'connect',
			'titre' => "Discuter d'une facture, d'une commande ou d'une demande d'achat",
			'tags' => 'connect facture commande demande achat pièce conversation bouton',
			'corps' =>
			"<p>Le bouton 💬 est présent sur les factures clients, les factures fournisseurs, les commandes et les demandes d'achat. Il ouvre — ou crée au premier message — le fil de cette pièce.</p>"
			. "<p>La pièce s'affiche en tête du fil sous forme de <strong>carte</strong> : montant, échéance, statut. Cette carte est <em>vivante</em> — elle relit la pièce à chaque affichage. Une demande déjà approuvée n'affiche plus le bouton « Approuver ».</p>"
			. "<p><strong>Approuver depuis la conversation fait exactement la même chose que depuis l'écran Achats</strong> : mêmes contrôles, même refus si le motif manque, même impossibilité de valider deux fois. Ce n'est pas un raccourci qui contourne quelque chose.</p>"
			. "<p>La décision est réécrite dans le fil : ceux qui ont suivi la discussion voient comment elle s'est terminée.</p>" ),

		array( 'id' => 'connect-documents', 'cat' => 'connect',
			'titre' => "Partager un document ou un message vocal",
			'tags' => 'connect fichier document pièce jointe vocal audio photo taille',
			'corps' =>
			"<p>Le partage de documents est une <strong>fonction de licence distincte</strong> : si vous ne voyez pas le formulaire, c'est qu'elle n'est pas souscrite.</p>"
			. "<p>Sont acceptés : PDF, Word, Excel, CSV, images et audio. 16 Mo par fichier (8 Mo dans une conversation confidentielle). <strong>Un fichier dont le contenu ne correspond pas à son extension est refusé</strong> — renommer un programme en .png ne trompe pas le contrôle.</p>"
			. "<p>Les fichiers sont stockés <strong>hors du site web</strong> et servis par une adresse qui revérifie vos droits à chaque ouverture. Un lien collé dans un courriel ne donne rien à qui n'a pas la conversation.</p>"
			. "<p><strong>Un message vocal se réécoute sur place.</strong> Cochez « c'est un message vocal » en joignant un enregistrement audio.</p>"
			. "<p>Retirer une pièce l'efface réellement du disque — contrairement à un message, dont la ligne reste pour la traçabilité.</p>" ),

		array( 'id' => 'connect-maestro', 'cat' => 'connect',
			'titre' => "Appeler Maestro dans une conversation",
			'tags' => 'connect maestro ia assistant trésorerie question privée partager',
			'corps' =>
			"<p>Écrivez <code>@maestro</code> suivi de votre question : <em>@maestro quelle est notre trésorerie ?</em></p>"
			. "<p><strong>Maestro répond avec VOS droits, jamais avec les siens.</strong> Il n'ouvre aucun accès que vous n'avez pas déjà.</p>"
			. "<p><strong>Si un participant du fil n'a pas accès aux données citées, la réponse ne s'affiche que pour vous</strong>, avec le nom de celui qui ne peut pas la voir et un bouton <em>Partager</em>. C'est vous qui décidez de la montrer, en connaissance de cause — plutôt que de découvrir après coup que le stagiaire a lu la trésorerie. Dans un canal, la réponse est toujours privée : on ne sait pas qui le lit.</p>"
			. "<p><strong>Seul le destinataire d'une réponse privée peut la partager</strong> — pas un modérateur, pas l'administrateur.</p>"
			. "<p>Maestro se tait quand un intervenant externe participe, et n'entre jamais dans une conversation de bout en bout. Sa réponse affiche son <strong>degré de confiance</strong> : une réponse à 40 % n'est pas un fait, même citée trois jours plus tard.</p>" ),

		array( 'id' => 'connect-externe', 'cat' => 'connect',
			'titre' => "Inviter un client ou un fournisseur dans une conversation",
			'tags' => 'connect externe invité client fournisseur lien code partage sécurité',
			'corps' =>
			"<p>Dans un groupe ou une conversation directe, volet <em>Participants</em> : nommez l'invité, créez l'invitation. Vous obtenez <strong>un lien et un code à six chiffres, affichés une seule fois</strong>.</p>"
			. "<p><strong>Envoyez-les par deux canaux différents</strong> — le lien par courriel, le code par téléphone. Un lien intercepté seul ne donne alors rien.</p>"
			. "<p><strong>L'invité ne voit rien de ce qui a été écrit avant son arrivée.</strong> Le commentaire sur la marge, l'hésitation sur le prix, le rappel qu'il a déjà payé en retard : tout cela reste interne. Il ne voit pas non plus les cartes de pièces, ni les noms des autres participants, ni quoi que ce soit d'autre de FinaKop.</p>"
			. "<p><strong>Un bandeau permanent signale sa présence à tout le monde.</strong> Une conversation où l'on ignore qui lit est une conversation où l'on écrit ce qu'il ne faut pas.</p>"
			. "<p>L'invitation expire après 14 jours, sa session après 2 heures d'inactivité, et vous pouvez la retirer à tout moment — l'effet est immédiat, au clic suivant de l'invité.</p>"
			. "<p><strong>À ne jamais faire :</strong> inviter quelqu'un dans un canal. C'est refusé, et pour une bonne raison.</p>" ),

		array( 'id' => 'connect-appels', 'cat' => 'connect',
			'titre' => "Appels audio, vidéo et partage d'écran",
			'tags' => 'connect appel audio vidéo visio partage écran turn participants',
			'corps' =>
			"<p>Les boutons 📞 et 🎥 apparaissent dans un groupe ou une conversation directe, si votre licence porte la fonction <strong>et</strong> si le temps réel est configuré.</p>"
			. "<p><strong>Trois participants au maximum.</strong> Sans serveur de mélange, chacun envoie son image à tous les autres : au quatrième, c'est l'appel entier qui se dégrade, pas seulement celui qui arrive. La limite est tenue par le serveur, pas seulement masquée.</p>"
			. "<p><strong>La voix et l'image ne passent ni par FinaKop ni par aucun serveur intermédiaire</strong> : elles vont directement d'un navigateur à l'autre, chiffrées de bout en bout. C'est le seul endroit du produit où c'est vrai.</p>"
			. "<p><strong>Si un appel ne s'établit pas</strong>, la cause la plus fréquente est un réseau d'entreprise qui bloque la connexion directe. Il faut alors un <em>serveur TURN</em>, que votre partenaire doit héberger. Le message à l'écran vous le dit plutôt que de vous laisser devant un écran noir.</p>"
			. "<p>Chaque appel laisse une ligne dans la conversation : type, durée, nombre de participants, ou « sans réponse ». C'est souvent la seule chose qu'on cherche trois mois plus tard.</p>"
			. "<p>Pas d'appel dans un canal, ni devant un intervenant externe : un appel porte la voix et le visage, ce n'est pas une décision qu'on prend par inadvertance.</p>" ),

		array( 'id' => 'connect-notifications', 'cat' => 'connect',
			'titre' => "Être prévenu : notifications, présence, accusés de lecture",
			'tags' => 'connect notification push présence lecture livré coche temps réel',
			'corps' =>
			"<p><strong>Les canaux ne vous notifient pas</strong>, sauf si vous êtes mentionné avec <code>@votreidentifiant</code>. Un canal bavard viderait la cloche de son sens en une matinée. Le compteur de non-lus, lui, reste exact.</p>"
			. "<p><strong>Notifications sur téléphone :</strong> bouton « M'avertir sur cet appareil », sous la liste des conversations. Elles arrivent onglet fermé. <strong>La notification ne transporte aucun contenu</strong> : votre appareil vient le chercher, avec vos droits. Quelqu'un retiré d'un groupe entre l'envoi et le réveil ne verra pas le titre.</p>"
			. "<p><strong>Une coche = reçu par tous. Deux coches = lu par tous.</strong> Pas « par quelqu'un » : par tous les participants. Et vous ne voyez ces accusés que sur vos propres messages — savoir qui a lu ceux des autres n'est pas une information dont on a besoin.</p>"
			. "<p>La pastille verte indique qui a ouvert la conversation dans les deux dernières minutes.</p>"
			. "<p>Sans serveur de temps réel, tout fonctionne : les messages arrivent en quelques secondes au lieu d'être instantanés, et l'indicateur « untel écrit… » n'existe pas.</p>" ),

		array( 'id' => 'connect-admin', 'cat' => 'connect',
			'titre' => "Administrer Connect : politique, conservation, journal",
			'tags' => 'connect administration politique rétention conservation audit clés diagnostic',
			'corps' =>
			"<p>Écran <em>Connect → Administration</em>, réservé au Mode Expert. Vous y réglez qui peut créer des canaux, si les invités externes sont autorisés, la durée de modification d'un message, et la durée de conservation.</p>"
			. "<p><strong>La conservation supprime réellement</strong> — messages et fichiers. L'écran annonce combien de messages partiront au prochain passage, avant qu'ils partent. Ne sont <strong>jamais</strong> supprimés : les conversations rattachées à une pièce comptable (dix ans OHADA), les messages épinglés, et le journal d'audit.</p>"
			. "<p><strong>Le journal d'audit ne descend jamais sous un an</strong>, quelle que soit la durée réglée pour les messages : un journal qui s'efface avec ce qu'il journalise ne sert à rien. Il se consulte et s'exporte en CSV.</p>"
			. "<p><strong>Surveillez deux lignes de cet écran :</strong></p>"
			. "<ul>"
			. "<li><em>Clés illisibles</em> — doit afficher « Aucune ». Un autre chiffre signifie que la clé maîtresse du site a changé et que d'anciennes conversations confidentielles sont perdues. Restaurez la sauvegarde d'origine <strong>avant</strong> toute autre manipulation.</li>"
			. "<li><em>Tâche planifiée (WP-Cron)</em> — si elle est désactivée, les notifications restent en file sans jamais partir, et la conservation ne s'applique pas. Demandez à votre hébergeur un appel de <code>wp-cron.php</code> toutes les 5 minutes.</li>"
			. "</ul>"
			. "<p>Rien de tout cela ne prévient tout seul : ces pannes sont silencieuses, d'où cet écran.</p>" ),

		array( 'id' => 'connect-acces', 'cat' => 'connect',
			'titre' => "« Je ne vois pas Connect » — les trois causes",
			'tags' => 'connect absent invisible accès module autorisation licence dépannage',
			'corps' =>
			"<p>Dans l'ordre de fréquence.</p>"
			. "<p><strong>1. Le module n'est pas accordé à votre compte.</strong> C'est la cause la plus courante après une mise à jour : Connect est un module comme un autre, et <strong>aucun compte existant ne l'a tant qu'un administrateur ne l'a pas accordé, un par un</strong>. Écran <em>Utilisateurs</em>, ouvrez le compte, cochez Connect.</p>"
			. "<p><strong>2. Votre licence ne porte pas le service.</strong> Connect se vend à part des paliers : même une Entreprise Premium ne l'a pas d'office. Contactez votre partenaire.</p>"
			. "<p><strong>3. La fonction précise manque.</strong> Le service peut être ouvert sans le partage de documents, sans la visio ou sans les invités externes. L'écran d'administration liste ce que votre licence ouvre.</p>" ),

		);
	}

	/* ═══════════════ GUIDE DE PRISE EN MAIN ═══════════════ */

	/** Parcours linéaire, dans l'ordre où les choses doivent être faites. */
	public static function parcours() { return FKC_AidePlateforme::adapterParcours( self::parcoursBase() ); }

	protected static function parcoursBase() {
		return array(
			array( 'id' => 'p1', 'icon' => '🔐', 'titre' => "Avant tout : sécuriser", 'duree' => '30 minutes',
				'intro' => "Rien de ce qui suit n'a de valeur si n'importe qui peut entrer.",
				'etapes' => array(
					array( 'titre' => "Changer le mot de passe admin", 'detail' => "Le mot de passe par défaut est public et identique sur toutes les installations.", 'route' => 'utilisateurs', 'aide' => 'changer-mot-de-passe' ),
					array( 'titre' => "Créer les comptes nominatifs", 'detail' => "Un compte par personne, avec les seuls modules dont elle a besoin.", 'route' => 'utilisateurs', 'aide' => 'utilisateurs' ),
					array( 'titre' => "Vérifier HTTPS et la sauvegarde", 'detail' => "HTTPS est obligatoire pour le scanner mobile ; sauvegardez le dossier de données.", 'route' => 'securite', 'aide' => 'bonnes-pratiques-securite' ),
				) ),
			array( 'id' => 'p2', 'icon' => '🏢', 'titre' => "Poser les fondations", 'duree' => '1 à 2 heures',
				'intro' => "L'ordre compte : chaque étape suppose la précédente.",
				'etapes' => array(
					array( 'titre' => "Créer l'entité", 'detail' => "Raison sociale, NCC, régime fiscal, devise, début d'exercice.", 'route' => 'societes/nouvelle', 'aide' => 'creer-societe' ),
					array( 'titre' => "Choisir le pack métier", 'detail' => "Il décide de la terminologie, des écrans et des écritures automatiques.", 'route' => 'parametres/pack', 'aide' => 'packs-choisir' ),
					array( 'titre' => "Ouvrir l'exercice", 'detail' => "Sans exercice ouvert, la saisie est bloquée.", 'route' => 'parametres/exercices', 'aide' => 'exercices' ),
					array( 'titre' => "Vérifier le plan comptable", 'detail' => "SYSCOHADA est déjà là. Ajoutez vos comptes propres maintenant.", 'route' => 'comptabilite/comptes', 'aide' => 'plan-comptable' ),
					array( 'titre' => "Installer la licence", 'detail' => "Sans clé, l'application tourne en Starter — utilisable, mais limité.", 'route' => 'licence', 'aide' => 'licence-installer' ),
					array( 'titre' => "Répondre à l'assistant de démarrage", 'detail' => "Cinq questions qui règlent l'ordre et la densité des écrans. Elles ne ferment aucun accès.", 'route' => 'demarrage', 'aide' => 'demarrage-assistant' ),
				) ),
			array( 'id' => 'p3', 'icon' => '📇', 'titre' => "Remplir les référentiels", 'duree' => 'quelques heures à quelques jours',
				'intro' => "L'étape la plus ingrate, et celle qui fait gagner le plus de temps ensuite.",
				'etapes' => array(
					array( 'titre' => "Clients", 'detail' => "B2B avec NCC pour la facturation normalisée, B2C sans.", 'route' => 'referentiels/clients', 'aide' => 'clients' ),
					array( 'titre' => "Fournisseurs", 'detail' => "Référentiel partagé entre comptabilité et packs métier.", 'route' => 'referentiels/fournisseurs', 'aide' => 'fournisseurs' ),
					array( 'titre' => "Articles et seuils d'alerte", 'detail' => "Le stock ne se saisit pas ici : il viendra par les réceptions.", 'route' => 'inventaire/articles', 'aide' => 'articles' ),
					array( 'titre' => "Comptes de trésorerie", 'detail' => "Banques et caisses, avec leurs comptes de rattachement.", 'route' => 'parametres/tresorerie', 'aide' => 'parametres-generaux' ),
				) ),
			array( 'id' => 'p4', 'icon' => '📥', 'titre' => "Reprendre l'existant", 'duree' => 'une demi-journée',
				'intro' => "À faire AVANT toute écriture du nouvel exercice. Après, c'est dix fois plus long.",
				'etapes' => array(
					array( 'titre' => "Reprise des soldes", 'detail' => "Soldes d'ouverture, tiers, en-cours clients et fournisseurs.", 'route' => 'comptabilite/migration', 'aide' => 'migration' ),
					array( 'titre' => "Stock de départ", 'detail' => "Par un inventaire initial, valorisé au coût réel.", 'route' => 'inventaire/inventaires', 'aide' => 'inventaire-physique' ),
					array( 'titre' => "Immobilisations existantes", 'detail' => "Avec leur valeur nette et l'amortissement déjà pratiqué.", 'route' => 'comptabilite/immobilisations', 'aide' => 'immo-creer' ),
					array( 'titre' => "Contrôler la balance", 'detail' => "Elle doit correspondre au centime au bilan de clôture précédent.", 'route' => 'comptabilite/balance', 'aide' => 'grand-livre-balance' ),
				) ),
			array( 'id' => 'p5', 'icon' => '🔁', 'titre' => "Prendre le rythme", 'duree' => 'les premières semaines',
				'intro' => "Ce que vous ferez tous les jours, toutes les semaines, tous les mois.",
				'etapes' => array(
					array( 'titre' => "Chaque jour : saisir et encaisser", 'detail' => "Factures, réceptions, encaissements. Clôturez la caisse chaque soir.", 'route' => 'comptabilite/ecritures', 'aide' => 'saisir-ecriture' ),
					array( 'titre' => "Chaque semaine : la balance âgée", 'detail' => "Qui doit quoi, depuis combien de temps. Relancez avant 90 jours.", 'route' => 'recouvrement/balance-agee', 'aide' => 'recouvrement' ),
					array( 'titre' => "Chaque mois : lettrer et rapprocher", 'detail' => "Lettrage des tiers, rapprochement bancaire, contrôle du solde 408.", 'route' => 'comptabilite/tresorerie/rapprochements', 'aide' => 'lettrage' ),
					array( 'titre' => "Chaque mois : déclarer la TVA", 'detail' => "Contrôlez l'aperçu avant de valider.", 'route' => 'fiscalite/declarations', 'aide' => 'declaration-tva' ),
					array( 'titre' => "Chaque mois : clôturer la période", 'detail' => "C'est ce qui rend vos états stables.", 'route' => 'comptabilite/menu', 'aide' => 'cloture' ),
				) ),
		);
	}

	/* ═══════════════ TUTORIELS ═══════════════ */

	public static function tutoriels() {
		return array(
			array( 'id' => 't-demarrage', 'icon' => '🚀', 'titre' => "Configurer votre première entité", 'objectif' => "Être opérationnel en 5 étapes.", 'etapes' => array(
				array( 'titre' => "Créer la société", 'detail' => "Raison sociale, NCC, régime et devise.", 'route' => 'societes/nouvelle' ),
				array( 'titre' => "Choisir le pack métier", 'detail' => "Terminologie, écrans et écritures du métier.", 'route' => 'parametres/pack' ),
				array( 'titre' => "Vérifier le plan comptable", 'detail' => "Les comptes SYSCOHADA sont déjà initialisés.", 'route' => 'comptabilite/comptes' ),
				array( 'titre' => "Ajouter vos clients", 'detail' => "Premiers tiers du référentiel.", 'route' => 'referentiels/clients' ),
				array( 'titre' => "Vérifier la licence", 'detail' => "Édition, quotas et modules actifs.", 'route' => 'licence' ),
			) ),
			array( 'id' => 't-compta', 'icon' => '📒', 'titre' => "Tenir la comptabilité", 'objectif' => "De la saisie aux états financiers.", 'etapes' => array(
				array( 'titre' => "Saisir une écriture", 'detail' => "Journal, date, lignes équilibrées, tiers sur les comptes 401 et 411.", 'route' => 'comptabilite/ecritures/nouvelle' ),
				array( 'titre' => "Contrôler la balance", 'detail' => "Débits, crédits et soldes par compte.", 'route' => 'comptabilite/balance' ),
				array( 'titre' => "Dérouler le grand livre", 'detail' => "Le détail des mouvements d'un compte.", 'route' => 'comptabilite/grand-livre' ),
				array( 'titre' => "Lettrer les tiers", 'detail' => "Relier factures et règlements.", 'route' => 'comptabilite/grand-livre-clients' ),
				array( 'titre' => "Générer les états", 'detail' => "Bilan, compte de résultat, SIG.", 'route' => 'comptabilite/etats-financiers' ),
			) ),
			array( 'id' => 't-achat', 'icon' => '🚚', 'titre' => "Le cycle d'achat complet", 'objectif' => "Du bon de commande au règlement, sans perdre la dette en route.", 'etapes' => array(
				array( 'titre' => "Créer le fournisseur", 'detail' => "Référentiel partagé comptabilité / stock.", 'route' => 'comptabilite/fournisseurs/nouveau' ),
				array( 'titre' => "Émettre un bon de commande", 'detail' => "Un engagement, sans écriture.", 'route' => 'comptabilite/bons-commande/nouveau' ),
				array( 'titre' => "Réceptionner la marchandise", 'detail' => "La dette naît en 408, le stock entre au CUMP.", 'route' => 'inventaire/receptions' ),
				array( 'titre' => "Saisir la facture", 'detail' => "Le 408 est extourné, la dette bascule en 401 avec la TVA.", 'route' => 'comptabilite/achats/nouvelle' ),
				array( 'titre' => "Régler et lettrer", 'detail' => "Sans lettrage, l'encours reste gonflé.", 'route' => 'comptabilite/fournisseurs/dettes' ),
			) ),
			array( 'id' => 't-vente', 'icon' => '🧾', 'titre' => "Vendre et se faire payer", 'objectif' => "De la facture au recouvrement.", 'etapes' => array(
				array( 'titre' => "Créer le client", 'detail' => "B2B avec NCC, B2C sans.", 'route' => 'referentiels/clients/nouveau' ),
				array( 'titre' => "Émettre la facture", 'detail' => "L'écriture est générée à la validation.", 'route' => 'facturation' ),
				array( 'titre' => "Encaisser", 'detail' => "Reçu, imputation ou rapprochement selon le cas.", 'route' => 'encaissement/nouveau' ),
				array( 'titre' => "Suivre les créances", 'detail' => "Balance âgée : qui doit quoi, depuis quand.", 'route' => 'recouvrement/balance-agee' ),
				array( 'titre' => "Relancer", 'detail' => "Campagnes, promesses, plans d'échelonnement.", 'route' => 'recouvrement/relances' ),
			) ),
			array( 'id' => 't-stock', 'icon' => '📦', 'titre' => "Maîtriser le stock", 'objectif' => "Un stock juste, et une valeur juste.", 'etapes' => array(
				array( 'titre' => "Créer les articles", 'detail' => "Avec leur seuil d'alerte dès le départ.", 'route' => 'inventaire/articles/nouveau' ),
				array( 'titre' => "Réceptionner", 'detail' => "Le CUMP est recalculé à chaque entrée.", 'route' => 'inventaire/receptions' ),
				array( 'titre' => "Suivre les stocks", 'detail' => "Quantités, valeur, alertes de réapprovisionnement.", 'route' => 'inventaire/stocks' ),
				array( 'titre' => "Inventorier", 'detail' => "Comptage physique et ajustement des écarts.", 'route' => 'inventaire/inventaires' ),
				array( 'titre' => "Contrôler la valorisation", 'detail' => "La valeur du stock doit rejoindre le compte 31x.", 'route' => 'inventaire/valorisation' ),
			) ),
			array( 'id' => 't-encaissement', 'icon' => '💶', 'titre' => "Encaisser avec le Centre d'Encaissement", 'objectif' => "Du reçu à la comptabilisation et à la clôture.", 'etapes' => array(
				array( 'titre' => "Nouveau reçu", 'detail' => "Type, client, montant, comptes de trésorerie et de produit.", 'route' => 'encaissement/nouveau' ),
				array( 'titre' => "Valider", 'detail' => "Soumettre au contrôle puis valider.", 'route' => 'encaissement' ),
				array( 'titre' => "Comptabiliser", 'detail' => "Écriture de trésorerie, brouillard optionnel.", 'route' => 'encaissement' ),
				array( 'titre' => "Clôturer la journée", 'detail' => "Journal Z ventilé par mode de règlement.", 'route' => 'encaissement/journal' ),
			) ),
			array( 'id' => 't-caisse', 'icon' => '🛒', 'titre' => "Tenir un point de vente", 'objectif' => "Ouvrir, vendre, clôturer.", 'etapes' => array(
				array( 'titre' => "Déclarer les postes", 'detail' => "Un poste par point d'encaissement.", 'route' => 'caisse/postes' ),
				array( 'titre' => "Configurer la caisse", 'detail' => "Moyens de paiement, impression, affichage client.", 'route' => 'caisse/configuration' ),
				array( 'titre' => "Vendre", 'detail' => "Douchette, recherche ou sélection tactile.", 'route' => 'caisse/pos' ),
				array( 'titre' => "Clôturer (Z)", 'detail' => "Comptage réel contre théorique, par moyen de paiement.", 'route' => 'caisse/cloture' ),
			) ),
			array( 'id' => 't-scan', 'icon' => '🔍', 'titre' => "Mettre le scan en service", 'objectif' => "Des codes sur tout ce qui bouge.", 'etapes' => array(
				array( 'titre' => "Générer les codes manquants", 'detail' => "Plage EAN-13 interne, sans collision commerciale.", 'route' => 'scan/generateur' ),
				array( 'titre' => "Imprimer les étiquettes", 'detail' => "Testez une planche avant d'en lancer cent.", 'route' => 'scan/etiquettes' ),
				array( 'titre' => "Appairer un téléphone", 'detail' => "La caméra devient une douchette, sans application.", 'route' => 'scan/mobile' ),
				array( 'titre' => "Vérifier au journal", 'detail' => "Ce qui a été scanné, quand, par qui.", 'route' => 'scan/journal' ),
			) ),
			array( 'id' => 't-immo', 'icon' => '🏗️', 'titre' => "Gérer le parc d'immobilisations", 'objectif' => "De l'acquisition au récolement.", 'etapes' => array(
				array( 'titre' => "Enregistrer l'actif", 'detail' => "Valeur, durée, comptes, emplacement, détenteur.", 'route' => 'comptabilite/immobilisations/nouvelle' ),
				array( 'titre' => "Vérifier le plan d'amortissement", 'detail' => "Les dotations prévues par exercice.", 'route' => 'comptabilite/immobilisations/plan' ),
				array( 'titre' => "Passer les dotations", 'detail' => "Avant d'éditer les états financiers.", 'route' => 'comptabilite/immobilisations/amortir' ),
				array( 'titre' => "Récoler le parc", 'detail' => "Campagne, pointage au scan, rapport vus / introuvables.", 'route' => 'comptabilite/immobilisations/recolement' ),
			) ),
			array( 'id' => 't-fiscalite', 'icon' => '🏛️', 'titre' => "Déclarer la TVA", 'objectif' => "Une déclaration mensuelle contrôlée.", 'etapes' => array(
				array( 'titre' => "Vérifier le régime", 'detail' => "L'assujettissement conditionne tout le reste.", 'route' => 'fiscalite/regime' ),
				array( 'titre' => "Nouvelle déclaration", 'detail' => "Type TVA, période.", 'route' => 'fiscalite/declarations' ),
				array( 'titre' => "Contrôler l'aperçu", 'detail' => "Collectée, déductible, à payer ou crédit.", 'route' => 'fiscalite/declarations' ),
				array( 'titre' => "Archiver au journal", 'detail' => "La mémoire de vos obligations.", 'route' => 'fiscalite/declarations/journal' ),
			) ),
			array( 'id' => 't-analyse', 'icon' => '📊', 'titre' => "Piloter avec les KPI", 'objectif' => "Mettre en place vos indicateurs.", 'etapes' => array(
				array( 'titre' => "Définir un KPI", 'detail' => "Métrique, cible, sens de progression.", 'route' => 'analyse/kpi' ),
				array( 'titre' => "Créer une alerte", 'detail' => "Une règle à seuil, pas dix.", 'route' => 'analyse/alertes' ),
				array( 'titre' => "Poser un axe analytique", 'detail' => "Commencez avec un seul.", 'route' => 'analytique/divisions' ),
				array( 'titre' => "Rafraîchir le warehouse", 'detail' => "Pour des tableaux de bord à jour.", 'route' => 'analyse/warehouse' ),
			) ),
			array( 'id' => 't-api', 'icon' => '🔌', 'titre' => "Connecter une application", 'objectif' => "Générer et tester une clé API.", 'etapes' => array(
				array( 'titre' => "Générer une clé", 'detail' => "Libellé, scopes minimaux, entité liée.", 'route' => 'securite/cles-api' ),
				array( 'titre' => "Lire la documentation", 'detail' => "Endpoints, exemples, schéma OpenAPI.", 'route' => 'api/docs' ),
			) ),
		);
	}

	/* ═══════════════ GLOSSAIRE ═══════════════ */

	public static function glossaire() { return FKC_AidePlateforme::adapterGlossaire( self::glossaireBase() ); }

	protected static function glossaireBase() {
		return array(
			array( 'terme' => '401 — Fournisseurs', 'grp' => 'Comptes', 'def' => "Dette envers un fournisseur, constatée à réception de sa facture." ),
			array( 'terme' => '408 — Factures non parvenues', 'grp' => 'Comptes', 'def' => "Dette née d'une réception dont la facture n'est pas encore arrivée. Elle bascule en 401 à l'arrivée de la facture." ),
			array( 'terme' => '411 — Clients', 'grp' => 'Comptes', 'def' => "Créance sur un client, constatée à l'émission de la facture." ),
			array( 'terme' => '419 — Clients, avances et acomptes', 'grp' => 'Comptes', 'def' => "Somme reçue avant livraison ou facturation. Imputée sur le 411 lorsque la facture est émise." ),
			array( 'terme' => '31x — Stocks', 'grp' => 'Comptes', 'def' => "Valeur du stock détenu. Doit correspondre à la valorisation de l'inventaire." ),
			array( 'terme' => 'Accusé de lecture', 'grp' => 'Connect', 'def' => "Une coche : le message a été reçu par tous les participants. Deux coches : il a été ouvert par tous. Visible sur vos propres messages seulement." ),
			array( 'terme' => 'Canal', 'grp' => 'Connect', 'def' => "Lieu de discussion ouvert à toute la société, comme #general. On n'y invite pas d'externe et on n'y lance pas d'appel." ),
			array( 'terme' => 'Conversation de pièce', 'grp' => 'Connect', 'def' => "Fil rattaché à une facture, une commande ou une demande d'achat. Son accès est celui de la pièce elle-même, relu à chaque ouverture." ),
			array( 'terme' => 'Invité externe', 'grp' => 'Connect', 'def' => "Client ou fournisseur admis dans une seule conversation, sans compte FinaKop. Il ne voit rien de ce qui précède son arrivée." ),
			array( 'terme' => 'Niveau de sécurité', 'grp' => 'Connect', 'def' => "1 : sécurisé. 2 : contenu chiffré en base, protège d'une fuite de sauvegarde, pas de l'administrateur du serveur. 3 : bout en bout, non livré. Fixé à la création, immuable." ),
			array( 'terme' => 'Relais temps réel', 'grp' => 'Connect', 'def' => "Service séparé qui prévient les navigateurs qu'une conversation a bougé. Il ne voit aucun message : il transporte un signal, jamais du contenu." ),
			array( 'terme' => 'Serveur TURN', 'grp' => 'Connect', 'def' => "Relais de flux audio et vidéo, nécessaire quand deux navigateurs ne peuvent pas se joindre directement. Sans lui, une partie des appels échoue." ),
			array( 'terme' => 'Dépôt de licences', 'grp' => 'Licence', 'def' => "Fichier signé publié par l'éditeur, consulté une fois par jour. Il apporte les mises à jour de licence et permet une révocation. Injoignable, il ne bloque rien." ),
			array( 'terme' => 'Service (licence)', 'grp' => 'Licence', 'def' => "Module vendu à part des paliers — Connect, par exemple. Aucune édition ne l'inclut : seule une clé qui le nomme l'ouvre." ),
			array( 'terme' => 'Amortissement', 'grp' => 'Immobilisations', 'def' => "Constatation comptable de la perte de valeur d'un actif sur sa durée d'utilisation." ),
			array( 'terme' => 'Avoir', 'grp' => 'Ventes', 'def' => "Pièce qui annule tout ou partie d'une facture déjà émise. La seule façon correcte de corriger une facture transmise." ),
			array( 'terme' => 'Balance', 'grp' => 'Comptabilité', 'def' => "État qui donne, par compte, le cumul des débits, des crédits et le solde." ),
			array( 'terme' => 'Balance âgée', 'grp' => 'Recouvrement', 'def' => "Répartition des créances par ancienneté. L'outil de base du recouvrement." ),
			array( 'terme' => 'Brouillard', 'grp' => 'Comptabilité', 'def' => "Zone d'attente où les écritures sont visibles et modifiables sans affecter les soldes, jusqu'à validation." ),
			array( 'terme' => 'Clôture', 'grp' => 'Comptabilité', 'def' => "Verrouillage d'une période : plus aucune écriture ne peut y être ajoutée, modifiée ou supprimée." ),
			array( 'terme' => 'Cockpit', 'grp' => 'Pilotage', 'def' => "Écran conçu pour un rôle plutôt que pour un module, suivant la boucle voir → comprendre → agir." ),
			array( 'terme' => 'Contre-passation', 'grp' => 'Comptabilité', 'def' => "Écriture inverse qui annule les effets d'une écriture antérieure sans l'effacer. Aussi appelée extourne." ),
			array( 'terme' => 'CUMP', 'grp' => 'Stock', 'def' => "Coût unitaire moyen pondéré. Recalculé à chaque entrée, il sert à valoriser les sorties." ),
			array( 'terme' => 'Division analytique', 'grp' => 'Analytique', 'def' => "Centre de coût ou de profit sur lequel on ventile charges et produits." ),
			array( 'terme' => 'Dotation', 'grp' => 'Immobilisations', 'def' => "Charge d'amortissement d'un exercice." ),
			array( 'terme' => 'Entité', 'grp' => 'Général', 'def' => "Une entreprise gérée dans l'application, avec ses livres isolés. Aussi appelée société." ),
			array( 'terme' => 'Exercice', 'grp' => 'Comptabilité', 'def' => "Période comptable, généralement de douze mois, sur laquelle se calculent les états financiers." ),
			array( 'terme' => 'Extourne', 'grp' => 'Comptabilité', 'def' => "Voir contre-passation." ),
			array( 'terme' => 'FNE', 'grp' => 'Fiscalité', 'def' => "Facture normalisée électronique : facture certifiée auprès de l'administration fiscale." ),
			array( 'terme' => 'Grand livre', 'grp' => 'Comptabilité', 'def' => "Détail chronologique des mouvements d'un compte. Auxiliaire lorsqu'il est présenté par tiers." ),
			array( 'terme' => 'Journal', 'grp' => 'Comptabilité', 'def' => "Regroupement d'écritures par nature : achats, ventes, banque, caisse, opérations diverses." ),
			array( 'terme' => 'Lettrage', 'grp' => 'Comptabilité', 'def' => "Rapprochement d'une facture et de son règlement pour solder la paire au compte de tiers." ),
			array( 'terme' => 'Lot', 'grp' => 'Stock', 'def' => "Sous-ensemble d'un article partageant une origine et souvent une date de péremption." ),
			array( 'terme' => 'NCC', 'grp' => 'Fiscalité', 'def' => "Numéro de compte contribuable, identifiant fiscal de l'entreprise." ),
			array( 'terme' => 'Pack métier', 'grp' => 'Général', 'def' => "Adaptation de l'ERP à une activité : terminologie, écrans, comptes et écritures propres au métier." ),
			array( 'terme' => 'Palier', 'grp' => 'Licence', 'def' => "Niveau de licence qui détermine les modules ouverts, les quotas et les capacités." ),
			array( 'terme' => 'Partie double', 'grp' => 'Comptabilité', 'def' => "Principe selon lequel toute écriture comporte des débits égaux aux crédits." ),
			array( 'terme' => 'Pièce', 'grp' => 'Comptabilité', 'def' => "Référence d'une écriture, souvent numérotée par journal et par exercice. Non unique entre journaux." ),
			array( 'terme' => 'Rapprochement bancaire', 'grp' => 'Trésorerie', 'def' => "Comparaison entre le compte banque tenu en comptabilité et le relevé de la banque." ),
			array( 'terme' => 'Récolement', 'grp' => 'Immobilisations', 'def' => "Inventaire physique du parc d'immobilisations : vérifier que ce que disent les comptes existe encore." ),
			array( 'terme' => 'Reçu', 'grp' => 'Ventes', 'def' => "Pièce d'encaissement, transformable en facture, imputable ou rapprochable." ),
			array( 'terme' => 'SIG', 'grp' => 'Comptabilité', 'def' => "Soldes intermédiaires de gestion : marge, valeur ajoutée, excédent brut d'exploitation, résultat." ),
			array( 'terme' => 'SYSCOHADA', 'grp' => 'Général', 'def' => "Référentiel comptable commun aux États membres de l'OHADA, en comptes à six chiffres, classes 1 à 8." ),
			array( 'terme' => 'TEE', 'grp' => 'Fiscalité', 'def' => "Taxe de l'Entrepreneur, assise sur le chiffre d'affaires de l'année précédente." ),
			array( 'terme' => 'TVA collectée / déductible', 'grp' => 'Fiscalité', 'def' => "TVA facturée aux clients / TVA supportée sur les achats. Leur différence donne la TVA due." ),
			array( 'terme' => 'Valeur nette comptable', 'grp' => 'Immobilisations', 'def' => "Valeur d'acquisition diminuée des amortissements pratiqués." ),
			array( 'terme' => 'Z de caisse', 'grp' => 'Caisse', 'def' => "Rapport de clôture journalière d'un poste, ventilé par moyen de paiement." ),
		);
	}

	/* ═══════════════ DÉPANNAGE ═══════════════ */

	public static function depannage() { return FKC_AidePlateforme::adapterDepannage( self::depannageBase() ); }

	protected static function depannageBase() {
		return array(

		array( 'grp' => 'Accès', 'q' => "Je ne peux pas me connecter",
			'r' => "Après plusieurs échecs, l'accès est temporairement bloqué depuis votre adresse — patientez une quinzaine de minutes. Si le mot de passe est perdu, un administrateur peut le réinitialiser depuis l'écran Utilisateurs. S'il n'y a plus aucun administrateur disponible, il faut intervenir sur le serveur." ),

		array( 'grp' => 'Accès', 'q' => "Un module affiche un cadenas",
			'r' => "Il n'est pas compris dans votre palier de licence, ou l'utilisateur n'y a pas accès. Vérifiez d'abord l'écran Licence (édition et modules actifs), puis les droits du compte dans Utilisateurs." ),

		array( 'grp' => 'Accès', 'q' => "« Jeton de sécurité invalide ou session expirée »",
			'r' => "Votre session a expiré pendant la saisie. Rechargez la page et recommencez. Si cela se répète en quelques minutes, c'est souvent un problème de cookies, ou une bascule entre le domaine avec et sans www." ),

		array( 'grp' => 'Connect', 'q' => "Je ne vois pas Connect dans le menu",
			'r' => "Trois causes, par ordre de fréquence. (1) Le module n'est pas accordé à votre compte : c'est le cas de TOUS les comptes existants après l'installation, tant qu'un administrateur ne l'a pas coché un par un dans l'écran Utilisateurs. (2) Votre licence ne porte pas le service : Connect se vend à part des paliers, même une Entreprise Premium ne l'a pas d'office. (3) La fonction précise manque — partage de documents, visio, invités externes se souscrivent séparément." ),

		array( 'grp' => 'Connect', 'q' => "Mes collègues ne reçoivent pas de notification",
			'r' => "Ouvrez Connect → Administration et regardez la ligne « Tâche planifiée (WP-Cron) ». Si elle est désactivée, les notifications s'accumulent en file sans jamais partir — et rien ne le signale ailleurs. Demandez à votre hébergeur d'appeler wp-cron.php toutes les 5 minutes. Vérifiez aussi que le site est servi en HTTPS : les navigateurs refusent les notifications hors contexte sûr.",
		),

		array( 'grp' => 'Connect', 'q' => "Un message affiche « contenu illisible »",
			'r' => "La clé maîtresse du site a changé — fichier perdu lors d'une migration, constante réécrite dans wp-config.php. Les conversations confidentielles chiffrées avec l'ancienne clé ne peuvent plus être déchiffrées. ARRÊTEZ TOUTE AUTRE MANIPULATION et restaurez la clé d'origine depuis une sauvegarde : sans elle, ces messages sont définitivement perdus. Connect → Administration compte les clés illisibles." ),

		array( 'grp' => 'Connect', 'q' => "Un appel ne s'établit pas",
			'r' => "La cause la plus fréquente est un réseau d'entreprise qui bloque la connexion directe entre les deux navigateurs. Il faut alors un serveur TURN, que votre partenaire doit héberger — l'écran d'administration indique s'il est configuré. Vérifiez aussi que le relais temps réel est actif : sans lui, les appels ne sont pas proposés du tout." ),

		array( 'grp' => 'Connect', 'q' => "Un invité externe dit ne rien voir",
			'r' => "C'est le fonctionnement voulu : il ne voit que ce qui a été écrit APRÈS son arrivée. Les échanges antérieurs sont internes à l'entreprise. S'il ne voit rien du tout, vérifiez que quelqu'un a écrit depuis, et que son accès n'a pas expiré — l'invitation vaut 14 jours, sa session 2 heures d'inactivité." ),

		array( 'grp' => 'Licence', 'q' => "Une fonction activée par mon partenaire n'apparaît pas",
			'r' => "Si un dépôt de licences est configuré, la mise à jour arrive seule, mais au plus une fois par jour : attendez le lendemain. Sinon, votre partenaire doit vous transmettre la nouvelle clé, à coller dans l'écran Licence. Rien ne change tant qu'elle n'est pas installée." ),

		array( 'grp' => 'Comptabilité', 'q' => "Mon écriture refuse de se valider",
			'r' => "Trois causes, par ordre de fréquence : elle n'est pas équilibrée (débit ≠ crédit) ; elle n'a qu'une seule ligne ; sa date tombe dans une période clôturée. Le message affiché précise laquelle." ),

		array( 'grp' => 'Comptabilité', 'q' => "Le solde du 411 ne correspond pas au grand livre clients",
			'r' => "Une écriture a été passée sur un compte client sans désigner le tiers. Elle pèse sur le solde général mais n'apparaît dans le détail d'aucun client. Retrouvez-la par le grand livre du compte, puis corrigez-la par contre-passation et ressaisie." ),

		array( 'grp' => 'Comptabilité', 'q' => "Je veux annuler une écriture, mais la suppression est refusée",
			'r' => "C'est volontaire : quelque chose la référence — période clôturée, ligne lettrée, facture ou règlement rattaché, justificatif attaché, ou contre-passation déjà passée. Utilisez la contre-passation, qui laisse l'histoire lisible. Le motif exact du refus est affiché." ),

		array( 'grp' => 'Comptabilité', 'q' => "Le solde du compte 408 ne bouge plus",
			'r' => "Des réceptions n'ont jamais été facturées. Ouvrez le détail du 408 : chaque ligne ancienne correspond à une marchandise reçue dont la facture fournisseur manque. Relancez le fournisseur, ou régularisez si la facture ne viendra jamais." ),

		array( 'grp' => 'Comptabilité', 'q' => "Mon bilan ne semble pas juste",
			'r' => "Vérifiez dans cet ordre : la balance est-elle équilibrée ? les comptes d'attente sont-ils soldés ? les dotations aux amortissements de la période ont-elles été passées ? le stock comptable correspond-il à l'inventaire ? Ces quatre points expliquent l'essentiel des écarts." ),

		array( 'grp' => 'Stock', 'q' => "Le stock affiché diffère du stock réel",
			'r' => "Faites un inventaire physique : il ajustera l'écart et le comptabilisera. Si l'écart revient sur les mêmes références, cherchez la cause — sorties non saisies, casse non déclarée, erreurs de réception — plutôt que de réajuster tous les mois." ),

		array( 'grp' => 'Stock', 'q' => "La fiche article signale un écart avec le journal central",
			'r' => "C'est la signature d'un report de réception qui n'a pas abouti. Utilisez la correction de quantité depuis la fiche : elle réaligne le journal et trace l'opération. Le motif est obligatoire, et c'est heureux — une correction non justifiée est indéfendable en contrôle." ),

		array( 'grp' => 'Stock', 'q' => "Je ne peux pas supprimer un article",
			'r' => "Il a une histoire : du stock, des mouvements, ou un rattachement à une pièce. Il sera archivé à la place — la fiche sort des écrans de travail, les données restent. Un stock retombé à zéro ne suffit pas : les mouvements passés justifient des écritures déjà comptabilisées." ),

		array( 'grp' => 'Stock', 'q' => "La valeur de mon stock ne correspond pas au compte 31x",
			'r' => "Comparez l'écran de valorisation et le solde comptable. Causes habituelles : un inventaire ajusté sans comptabilisation, des sorties non valorisées, ou une reprise initiale mal calée. Reprenez à la date où les deux coïncidaient encore." ),

		array( 'grp' => 'Scan', 'q' => "La caméra du téléphone ne démarre pas",
			'r' => "Le navigateur exige HTTPS pour accéder à la caméra. Vérifiez que vous ouvrez l'application en https, et que l'autorisation caméra n'a pas été refusée pour ce site." ),

		array( 'grp' => 'Scan', 'q' => "Un code scanné n'est pas reconnu",
			'r' => "Il n'est pas encore inscrit au registre. Rattachez-le à l'article ou à l'actif depuis sa fiche, en choisissant le bon conditionnement (unité, pack, carton) et son facteur." ),

		array( 'grp' => 'Caisse', 'q' => "Écart au moment de la clôture Z",
			'r' => "Comparez par moyen de paiement : un écart en espèces seul évoque un rendu de monnaie ; un écart réparti sur plusieurs modes évoque une vente saisie deux fois ou un retour mal enregistré. Le détail des tickets de la session permet de retrouver l'opération." ),

		array( 'grp' => 'Analyse', 'q' => "Mes tableaux de bord semblent en retard",
			'r' => "Les analyses lisent des instantanés pré-calculés. Cliquez « Rafraîchir les données » dans Analyse → Data Warehouse avant de suspecter une erreur de saisie." ),

		array( 'grp' => 'Fiscalité', 'q' => "Ma TVA déductible paraît anormalement basse",
			'r' => "Des factures d'achat ne sont probablement pas saisies, ou sont restées au brouillard. Contrôlez le journal des achats sur la période avant de valider la déclaration." ),

		array( 'grp' => 'Données', 'q' => "J'ai purgé par erreur",
			'r' => "Les purges sont irréversibles et aucune corbeille ne les rattrape : seule une sauvegarde du dossier de données permet de revenir en arrière. C'est la raison pour laquelle une sauvegarde doit précéder toute opération de maintenance." ),

		array( 'grp' => 'Données', 'q' => "L'application est lente",
			'r' => "Rafraîchissez le Data Warehouse, et vérifiez la version de PHP côté hébergement (8.3 recommandé). Sur les gros volumes, l'archivage des exercices anciens allège les écrans de consultation." ),

		);
	}

	/* ═══════════════ VISITES GUIDÉES ═══════════════ */

	public static function tours() {
		return array(
			'interface' => array(
				'label' => "Découverte de l'interface",
				'etapes' => array(
					array( 'cible' => '.brand', 'titre' => 'Votre espace', 'texte' => "Le logo et l'édition active de votre cabinet." ),
					array( 'cible' => '.societe-box', 'titre' => 'Entité active', 'texte' => "Cliquez ici pour changer d'entité. Chaque entité a ses propres livres, physiquement séparés." ),
					array( 'cible' => '.nav', 'titre' => 'Les modules', 'texte' => "Tous vos modules. Un cadenas signale une fonction hors de votre palier." ),
					array( 'cible' => '.topbar-right', 'titre' => 'Votre session', 'texte' => "Statut de licence, notifications, profil et déconnexion." ),
					array( 'cible' => '.content', 'titre' => 'Zone de travail', 'texte' => "Le contenu s'affiche ici. Retour ramène au lanceur, Fermer au tableau de bord." ),
				),
			),
		);
	}

	/* ═══════════════ ACCÈS & RECHERCHE ═══════════════ */

	public static function article( $id ) {
		foreach ( self::articles() as $a ) { if ( $a['id'] === $id ) { return $a; } }
		return null;
	}

	public static function parCategorie( $cat ) {
		return array_values( array_filter( self::articles(), function ( $a ) use ( $cat ) { return $a['cat'] === $cat; } ) );
	}

	/** Nombre d'articles par catégorie, pour les vignettes du sommaire. */
	public static function compteParCategorie() {
		$n = array();
		foreach ( self::articles() as $a ) { $n[ $a['cat'] ] = ( $n[ $a['cat'] ] ?? 0 ) + 1; }
		return $n;
	}

	/** Articles marqués « essentiel » : la sélection à lire en premier. */
	public static function essentiels() {
		return array_values( array_filter( self::articles(), function ( $a ) { return 'essentiel' === ( $a['niveau'] ?? '' ); } ) );
	}

	/**
	 * Recherche plein-texte sur les articles, le glossaire et le dépannage.
	 *
	 * On exige que TOUS les mots saisis soient présents : un seul mot commun
	 * suffirait sinon à ramener la moitié du centre d'aide. Le tri privilégie
	 * les correspondances de titre, qui sont presque toujours ce que
	 * l'utilisateur cherchait.
	 *
	 * @return array{articles:array,glossaire:array,depannage:array,total:int}
	 */
	public static function rechercher( $q ) {
		$q = trim( fkc_lc( (string) $q ) );
		if ( '' === $q ) { return array( 'articles' => array(), 'glossaire' => array(), 'depannage' => array(), 'total' => 0 ); }
		$mots = array_values( array_filter( explode( ' ', $q ) ) );
		$tous = function ( $foin ) use ( $mots ) {
			foreach ( $mots as $m ) { if ( false === strpos( $foin, $m ) ) { return false; } }
			return true;
		};

		$arts = array();
		foreach ( self::articles() as $a ) {
			$foin = fkc_lc( $a['titre'] . ' ' . $a['tags'] . ' ' . fkc_aide_texte( $a['corps'] ) );
			if ( ! $tous( $foin ) ) { continue; }
			$a['_score'] = $tous( fkc_lc( $a['titre'] ) ) ? 2 : ( $tous( fkc_lc( $a['tags'] ) ) ? 1 : 0 );
			$arts[] = $a;
		}
		usort( $arts, function ( $x, $y ) { return $y['_score'] <=> $x['_score']; } );

		$glo = array_values( array_filter( self::glossaire(), function ( $g ) use ( $tous ) {
			return $tous( fkc_lc( $g['terme'] . ' ' . $g['def'] ) );
		} ) );

		$dep = array_values( array_filter( self::depannage(), function ( $d ) use ( $tous ) {
			return $tous( fkc_lc( $d['q'] . ' ' . $d['r'] ) );
		} ) );

		return array(
			'articles'  => $arts,
			'glossaire' => $glo,
			'depannage' => $dep,
			'total'     => count( $arts ) + count( $glo ) + count( $dep ),
		);
	}
}

if ( ! function_exists( 'fkc_aide_texte' ) ) {
	/** HTML d'un article ramené à du texte brut, pour la recherche. */
	function fkc_aide_texte( $s ) {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags_safe' ) ) {
	/** Compatibilité : ancien nom conservé pour d'éventuels appels externes. */
	function wp_strip_all_tags_safe( $s ) { return fkc_aide_texte( $s ); }
}
