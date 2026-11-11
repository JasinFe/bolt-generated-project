=== KM SALLE D'ÉCOUTE ===
Contributors: kmlabel
Tags: audio, music, player, demo, label, partenaire, écoute, wavesurfer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Système d'écoute sécurisé de démos et productions musicales pour partenaires d'un Label Musical.

== Description ==

**KM SALLE D'ÉCOUTE** est une extension WordPress conçue pour les labels musicaux souhaitant partager des sessions d'écoute (démos ou produits finis) avec leurs partenaires, distributeurs ou tout autre destinataire autorisé.

= Fonctionnalités principales =

* **Custom Post Type** dédié aux Sessions d'Écoute
* **Taxonomies** : Type de contenu (Démo, Produit Fini, Instrumental, Remix) & Genre Musical
* **Champs ACF** complets : pistes audio (repeater), artiste lié au CPT "Nos Artistes", cover art, BPM, date de création
* **Lecteur audio WaveSurfer.js v7** : forme d'onde animée, chargement à la demande, contrôle du volume
* **3 modes d'accès** : Public / Protégé par mot de passe / Privé (connecté)
* **Date d'expiration** automatique du lien
* **Téléchargement optionnel** (activable par session)
* **Watermark visuel** de confidentialité
* **Journal des accès** intégré (activable dans Réglages)
* **Snippets WPCode** intégrés (désactivation clic droit, email partenaire)
* **Pages créées automatiquement** à l'activation (Catalogue, Lien Expiré)
* **Shortcodes** : `[km_salle_ecoute_catalogue]`, `[km_salle_ecoute_player id="X"]`, `[km_salle_ecoute_expired]`
* **Logs d'accès** dans l'administration
* **Copie du lien** en un clic depuis la liste des sessions

= Dépendances =

* **Advanced Custom Fields (ACF)** — version gratuite ou Pro
* **WPCode** — optionnel, pour les snippets intégrés

= CPT existant utilisé =

Ce plugin se connecte au CPT `nos-artistes` (Nos Artistes) déjà présent sur votre site principal.

== Installation ==

1. Téléchargez le fichier `km-salle-ecoute.zip`
2. Dans WordPress, allez dans **Extensions > Ajouter une extension**
3. Cliquez sur **Téléverser une extension** et sélectionnez le fichier ZIP
4. Activez l'extension
5. Les pages **KM Salle d'Écoute – Catalogue** et **Lien Expiré** sont créées automatiquement
6. Allez dans **Sessions d'Écoute > Réglages** pour configurer votre label

== Utilisation ==

= Créer une session d'écoute =
1. Sessions d'Écoute > Ajouter
2. Renseignez le titre, l'artiste lié, les pistes audio (via ACF Repeater)
3. Dans le panneau latéral "Accès & Partage", choisissez le mode d'accès et le mot de passe
4. Publiez et partagez le lien avec vos partenaires

= Shortcodes =

`[km_salle_ecoute_catalogue]` — Liste toutes les sessions publiées
`[km_salle_ecoute_catalogue type="demo" limite="10"]` — Filtrée par type
`[km_salle_ecoute_catalogue artiste="Nom ou ID" genre="afrobeat"]` — Filtrée par artiste / genre
`[km_salle_ecoute_player id="123"]` — Lecteur inline pour une session spécifique
`[km_salle_ecoute_expired]` — Message de lien expiré (page auto)

= Snippets WPCode =
Deux snippets sont proposés à WPCode :
- **Désactiver le clic droit** sur les pages audio
- **Notification email** au partenaire à la publication

Le journal des accès est désormais intégré : Sessions d'Écoute > Réglages > Journal des accès.

== Changelog ==

= 1.7.2 =
**Corrections de bugs**
* **Erreur fatale PHP 8** sur les shortcodes utilisés sans attribut (`[km_salle_ecoute_expired]` sur la page « Lien Expiré », `[km_salle_ecoute_catalogue]`) : WordPress leur transmet une chaîne vide, refusée par le typage `array`.
* **Sécurité** : une session dont l'accès était refusé hors mode « mot de passe » (mode inconnu, accès révoqué) affichait quand même les pistes et les URL audio. Un écran « Accès restreint » s'affiche désormais.
* **Sécurité** : les téléchargements restaient possibles après la date d'expiration (lien direct). L'expiration est maintenant vérifiée partout (page, lecteur inline, téléchargement).
* **Accès par mot de passe** : abandon des sessions PHP, qui ne pouvaient pas démarrer une fois la page commencée (le lecteur inline d'une session protégée restait toujours bloqué, avertissements « headers already sent »). Remplacé par un cookie signé, valable 7 jours ou jusqu'à l'expiration ; changer le mot de passe révoque immédiatement les accès déjà accordés.
* Le mot de passe saisi n'est plus altéré par `sanitize_text_field` (caractères `<`, `%`… refusés à tort).
* Pages protégées exclues du cache (`DONOTCACHEPAGE` + en-têtes no-cache) : un plugin de cache ne peut plus servir une page déverrouillée à d'autres visiteurs.
* **Confidentialité** : les sessions n'apparaissent plus dans la recherche du site, le sitemap WordPress / Yoast, ni dans les moteurs de recherche (`noindex`).
* Expiration calculée dans le fuseau horaire du site (et non celui du serveur) ; la colonne « Expiration » de l'admin est cohérente avec le site.
* Redirection « Lien expiré » vers la page réellement créée (et non une URL codée en dur).
* Titre de la page catalogue créé avec un antislash (« d\'Écoute ») — corrigé automatiquement à la mise à jour.
* Modèle de page du catalogue déclaré à WordPress : il n'est plus réinitialisé quand on modifie la page.
* Couleurs « principale » et « accent » des Réglages enfin appliquées au design (elles étaient enregistrées mais ignorées).
* Réglages : l'enregistrement remettait à zéro l'option logo (champ inexistant).
* Journal des accès : la page « Logs d'Accès » restait vide (snippet jamais chargé, basé sur `$_SESSION`). Journal intégré, activable, dédoublonné (30 min), robots ignorés, option non chargée à chaque page.
* Snippets WPCode jamais enregistrés (initialisation manquante) ; snippet email : antislashs dans le texte et envoi impossible à la première publication (champs ACF pas encore enregistrés).
* Filtre `artiste` du catalogue par nom : il ne trouvait rien (comparaison d'un nom avec un ID). Recherche aussi dans « Artiste externe ».
* Filtres du catalogue limités aux types/genres réellement présents parmi les sessions visibles.
* Plus d'erreur fatale si ACF est désactivé (avertissement dans l'admin à la place).
* Lecteur inline : CSS/JS manquants si le shortcode était placé dans un widget ou un constructeur de page ; promesse `play()` non gérée ; lien vers la session au lieu d'un simple message quand l'accès est protégé.
* Téléchargement : envoi par blocs (les gros WAV ne saturent plus la mémoire PHP), bon type MIME, nom de fichier accentué, chemin vérifié dans le dossier uploads.
* Script « pleine largeur » : il pouvait masquer n'importe quel bloc du site contenant certains mots, ainsi que la barre d'administration, et agissait sur toute page contenant le catalogue. Il ne cible plus que les en-têtes/pieds du thème autour de la page d'écoute (option désactivable dans Réglages).
* Copie du lien partenaire (admin) qui ne fonctionnait pas sur un site en HTTP.

**Lecteur amélioré**
* **Reprise automatique** : chaque piste reprend là où l'écoute s'était arrêtée, avec un bouton « Depuis le début ».
* **Lecture aléatoire** (bouton + touche A).
* **Durées réelles** détectées automatiquement quand elles ne sont pas saisies dans l'admin.
* Touches **0 à 9** pour aller à 0 %…90 % de la piste, Page ↑/↓ (±30 s) sur les barres de progression, molette sur le volume, « / » pour rechercher dans le catalogue.
* Erreur de lecture : bouton **Réessayer** ; un clic sur une piste en erreur la recharge.
* Titre de l'onglet « ▶ Piste en cours », vitesse ≠ 1× mise en évidence, hauteur tonale préservée.
* Formulaire de mot de passe : vrai formulaire (Entrée, gestionnaires de mots de passe), message clair si la page a expiré, fonctionne sans JavaScript.
* Bouton « piste précédente » visible sur mobile.
* Lecteur inline : enchaînement automatique sur la piste suivante, glisser sur la forme d'onde, flèches ←/→, met en pause le lecteur principal.
* Interface traduisible (textes JS passés par WordPress), option pour ne pas charger Google Fonts (RGPD).

= 1.7.1 =
* **Refonte graphique complète** : nouveau design « Studio Premium » (hero avec fond flouté issu de la cover, typographie et espacements revus, cartes de catalogue modernisées, écran d'accès repensé, pied de page sobre). Tous les emojis sont remplacés par des icônes SVG nettes.
* **Lecteur professionnel** : un seul élément audio (enchaînement fiable, y compris sur iPhone), barres de progression glissables à la souris et au doigt avec infobulle de temps et indicateur de tampon, répétition (désactivée / toutes / une piste), vitesse de lecture (0,75× à 2×), volume mémorisé, bouton muet.
* **Media Session API** : commandes de lecture depuis l'écran verrouillé, le casque Bluetooth et les touches multimédia, avec pochette et titre.
* **Barre de lecture mobile** : lecture/pause et piste suivante désormais disponibles sur téléphone (auparavant masqués sous 860 px).
* **Accessibilité** : barres de progression en sliders ARIA pilotables au clavier, focus visible, respect de « réduire les animations ».
* **Catalogue** : recherche instantanée (titre / artiste, insensible aux accents), compteur de sessions, état « aucun résultat », filtres combinables avec la recherche.
* Affichage de la date de fin de validité du lien et du mode d'accès dans l'en-tête de session ; bouton « Raccourcis » listant les raccourcis clavier.
* Lecteur inline : couleur de progression de la forme d'onde corrigée (le canvas ne lisait pas la variable CSS), repli automatique sur le lecteur natif si la forme d'onde est impossible (CORS, fichier externe), styles isolés du thème.
* WaveSurfer.js 7.8.6 désormais hébergé dans le plugin (plus de dépendance au CDN unpkg).
* Corrections : double bascule lecture/pause avec la touche Espace sur une ligne de piste, flèches haut/bas qui bloquaient le défilement de la page même sans lecture, icônes de lecture désynchronisées si le navigateur bloquait le démarrage, styles manquants du message « Lien Expiré ».
* Les variables CSS sont désormais préfixées `--km-*` et limitées à la classe `.km-scope` (plus de fuite globale). **Si vous surchargez les templates dans votre thème** (`km-salle-ecoute/`), recopiez les nouvelles versions : le balisage a évolué.

= 1.6.1 =
* **Correctif majeur** : le lecteur inline (`[km_salle_ecoute_player]`) était totalement non fonctionnel (script WaveSurfer jamais enregistré, aucun JS d'initialisation). Implémentation complète avec WaveSurfer.js v7, waveform chargée à la demande au premier clic play, CSS dédié.
* **Sécurité** : comparaison du mot de passe de session désormais "timing-safe" (`hash_equals`) et protection anti-bruteforce (5 tentatives max par IP, blocage 15 min)
* **Confidentialité** : le catalogue public (`[km_salle_ecoute_catalogue]`, page catalogue, archives de taxonomie) n'affiche plus que les sessions en accès "Public" aux visiteurs — les sessions "Mot de passe"/"Privé" restent accessibles par lien direct mais ne sont plus listées publiquement. Les administrateurs/éditeurs continuent de tout voir.
* Correction du filtre `artiste` du shortcode catalogue, jusque-là ignoré
* Correction de l'email de contact codé en dur sur le message "Lien Expiré" (utilise désormais le réglage Email de contact)
* Correction du CSS de la liste d'administration (badges d'accès) jamais chargé
* Nettoyage de code mort (filtre inutilisé, hook de sidebar sans effet) ; la sidebar est désormais réellement vidée (`sidebars_widgets`) sur nos pages
* Optimisation : les termes de taxonomie par défaut ne sont plus réinsérés à chaque chargement de page

= 1.6.0 =
* Nouveau réglage global "Téléchargement des fichiers audio" (page Réglages) : Selon chaque session (par défaut) / Toujours autorisé / Toujours désactivé
* Téléchargement sécurisé : les liens ne pointent plus directement vers le fichier audio, mais passent par une vérification serveur (accès à la session + autorisation de téléchargement) avant de servir le fichier — empêche le contournement par copie d'URL
* Nouvelle colonne "Téléchargement" dans la liste des sessions, indiquant le statut effectif (selon le réglage global et/ou celui de la session)
* Lecteur : indicateur de chargement (spinner) pendant le buffering/chargement d'une piste, message d'erreur clair en cas d'échec de lecture (réseau, fichier introuvable)
* Lecteur : navigation au clavier améliorée — lignes de piste focusables (Tab), activables au clavier (Entrée/Espace), raccourcis Origine/Fin (Home/End) pour aller au début/à la fin de la piste
* Lecteur : protection contre les doubles-clics pendant le chargement d'une piste
* Correction d'un bug préexistant dans le lecteur inline (`[km_salle_ecoute_player]`) qui utilisait de mauvaises clés de tableau pour les pistes (le lecteur ne s'affichait pas correctement)

= 1.5.4 =
* Correction de l'affichage "KOPHI\'S MUSIC" : nettoyage des antislashs résiduels sur le nom du Label (méthode `label_nom()`) + correction de l'enregistrement (wp_unslash)
* Correction du layout Hero : la cover et les informations (titre, artiste, badges) sont désormais correctement disposées côte à côte (cover à gauche, infos à droite), avec repli en colonne sur mobile

= 1.5.3 =
* Ajout d'un filet JS qui force la pleine largeur quelle que soit la structure du thème (détection et masquage automatique du header/footer du thème)
* Renforcement du CSS (!important + spécificité) pour empêcher le thème de redimensionner les boutons et icônes du lecteur (now playing, play principal, tracklist, catalogue)

= 1.5.2 =
* Correction de l'erreur fatale à l'activation : suppression d'une déclaration de méthode incomplète/invalide dans class-admin.php

= 1.5.1 =
* Renommage complet en "KM SALLE D'ÉCOUTE" pour éviter tout conflit de noms (classes, constantes, CPT, taxonomies, options) avec d'autres extensions KM
* CPT renommé en `kse_session`, taxonomies en `kse_type_contenu` / `kse_genre`
* Corrections de chaînes PHP cassées suite au renommage

= 1.0.0 =
* Version initiale

== Frequently Asked Questions ==

= ACF est-il obligatoire ? =
Oui, ACF (gratuit ou Pro) est requis pour les champs personnalisés.

= WPCode est-il obligatoire ? =
Non, WPCode est optionnel. Les snippets fournis peuvent aussi être copiés dans functions.php.

= Puis-je personnaliser le design ? =
Oui, créez un dossier `km-salle-ecoute/` dans votre thème et copiez les templates à surcharger.

= Est-ce compatible multisite ? =
Oui, l'extension est conçue pour fonctionner sur le site principal d'un réseau multisite WordPress en sous-domaines.
