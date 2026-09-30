=== KM Family ===
Contributors: kophismusic
Tags: membership, mobile money, urban gospel, cinetpay, paystack, multisite
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.3.0
License: GPLv2 or later

Plateforme de soutien pour les artistes du label Urban Gospel KOPHI'S MUSIC.

== Changelog ==

= 3.3.0 - 2026-09-20 =
* CORRECTIF (parcours de soutien) : après « Soutenir / Rejoindre » → connexion ou création de compte, la personne est désormais ramenée sur le soutien de l'artiste choisi (avec le palier cliqué mis en avant) au lieu de rester dans son espace membre. Cause : le bouton « Rejoindre » des paliers et du paywall pointait vers la page de connexion sans paramètre de retour (« next »).
* NOUVEAU : lien direct de soutien pour chaque artiste — https://votre-site.com/soutenir/{slug-artiste}/ (repli sans permaliens : /?kmfamily_soutenir={id}). Déconnecté(e) → page de connexion / création de compte puis retour sur le soutien de l'artiste ; déjà connecté(e) → directement sur le soutien. Options : ?palier=or&period=yearly (présélection) et ?tab=register (ouvre l'onglet Inscription).
* NOUVEAU : génération automatique du lien direct à la création de chaque artiste (fiche « nos-artistes »), rattrapage unique des artistes existants, action `kmfamily_artist_direct_link_created` pour les développeurs. Encart « Lien direct de soutien » avec bouton Copier sur la fiche artiste, colonne dans la liste des artistes, et page KM Family → Liens de soutien (avec bouton de resynchronisation).
* NOUVEAU : shortcode [kmfamily_soutenir artiste_id="" label="" register="0" palier=""] pour placer un bouton de soutien dans Elementor / une page.
* NOUVEAU : la page de connexion affiche « Vous allez soutenir {artiste} » quand le parcours vient d'un artiste ; si un bouton du thème pointe vers la page de connexion sans paramètre, l'artiste de la page d'origine est repris automatiquement.
* CORRECTIF : une personne déjà connectée qui ouvre la page de connexion était redirigée par le shortcode APRÈS l'envoi des en-têtes (redirection impossible) et sans tenir compte du paramètre « next » — la redirection se fait maintenant avant l'affichage et respecte la destination demandée.
* CORRECTIF : connexion via wp-login.php?redirect_to=… renvoyait toujours vers l'espace membre, quelle que soit la destination demandée.
* CORRECTIF : la présélection du palier/de la périodicité (sessionStorage) était supprimée sur la page de connexion, avant le retour sur la page artiste — elle n'est plus consommée que sur une page qui affiche les paliers.
* SÉCURITÉ : toute URL de retour est validée côté serveur (même site uniquement, jamais la page de connexion elle-même ni wp-login.php).

= 2.4.0 - 2026-08-09 =
* NOUVEAU (billetterie) : intégration de la billetterie évènementielle (snippet "KM Évènements") au Smart Payment Center — un billet payant passe désormais par le même moteur de paiement que les abonnements (Wave, Orange Money, MTN, Moov, CinetPay, Paystack), avec jetons signés anti-fraude, réservation de capacité atomique (fin de la fenêtre de course sur les dernières places), rate limiting dédié et file d'attente admin. Un billet gratuit reste confirmé instantanément.
* NOUVEAU : compte KM Family obligatoire pour tout achat de billet (comme pour un abonnement) — base du nouveau programme de fidélité.
* NOUVEAU : programme de fidélité (class-kmfamily-loyalty.php) — grand livre de points unifié abonnements + billets, idempotent, affiché dans le profil membre.
* NOUVEAU : Paystack — canaux de paiement explicitement ordonnés (Mobile Money en premier, puis carte) pour la Côte d'Ivoire.
* AMÉLIORATION : file d'attente admin des commandes en attente (KM Family → Abonnements) affiche désormais correctement les commandes de billets (acheteur, type de billet, quantité) aux côtés des abonnements.

= 2.3.22 - 2026-08-05 =
* NOUVEAU (anti-fraude) : une déclaration de paiement ("J'ai déjà payé") est désormais refusée si la personne n'a jamais cliqué sur "Payer avec X" ni scanné le QR au préalable — jusqu'ici, n'importe qui connaissant une référence de commande pouvait déclarer un faux paiement sans même avoir ouvert l'app. Le délai entre le clic et la déclaration s'affiche aussi dans le tableau admin (⚠️ signalé si moins de 30s), pour aider à juger la plausibilité avant de confirmer.
* NOUVEAU : bouton "Rejeter" pour les commandes en attente de confirmation — jusqu'ici, la seule action possible était "Confirmer", sans aucun moyen de refuser une déclaration erronée ou frauduleuse sans soit activer à tort un abonnement non payé, soit la laisser bloquée indéfiniment. Motif optionnel, membre notifié par email.
* CORRECTIF : l'activation manuelle d'un abonnement (offrir/tester) affichait toujours "0 FCFA" dans "Abonnements actifs", y compris pour un palier payant — un champ "Montant" a été ajouté au formulaire, pré-calculé sur le prix officiel du palier et modifiable.

= 2.3.21 - 2026-08-05 =
* AUDIT DE SÉCURITÉ COMPLET du système de paiement et des endpoints publics. Résultats :
* CORRECTIF CRITIQUE (falsification de montant) : le parcours de paiement direct CinetPay/Paystack (hors Smart Payment Center) facturait le montant envoyé tel quel par le navigateur, sans jamais le confronter au prix officiel du palier + périodicité — une requête modifiée (outils développeur) pouvait faire payer 100 FCFA pour un palier à 25 000 FCFA. Le montant est désormais systématiquement recalculé côté serveur à partir du prix officiel ; seul le palier "Libre" (montant choisi) reste flexible, avec un minimum imposé. Le Smart Payment Center (parcours principal) faisait déjà ce contrôle correctement.
* CORRECTIF SÉCURITÉ (CSRF) : trois actions (mise à jour du profil, upload d'avatar, changement de palier) calculaient une vérification de jeton de sécurité (nonce) mais n'utilisaient jamais son résultat — seule la connexion était vérifiée, laissant la porte ouverte à des requêtes forgées depuis un autre site. La vérification est désormais réellement appliquée sur les trois.
* CONFIRMÉ SOLIDE (aucune action requise) : vérification de signature des webhooks (HMAC Paystack, re-vérification serveur-à-serveur CinetPay), absence de requêtes SQL non préparées, échappement correct des données affichées en admin (référence/note de paiement déclarées), validation réelle du type et de la taille des fichiers uploadés (vérification du contenu réel, pas seulement de l'extension), autorisation systématique (capacité + nonce) sur toutes les actions admin sensibles, limitation de débit en place sur les points d'entrée publics.
* CORRECTIF (régression) : deux correctifs d'une version précédente (message d'erreur du rate-limiting affiché correctement, vrai message serveur remonté en cas d'échec réseau) avaient disparu dans cette base de code — réappliqués, et étendus au repli REST introduit depuis.

= 2.3.20 - 2026-08-03 =
* CORRECTIF : "KOPHI'S MUSIC SAS" remplacé par "KOPHI'S GROUP SAS" dans le pied de page de l'email de bienvenue envoyé aux nouveaux abonnés.
* CORRECTIF (espace blanc entre le menu et le contenu, "sur toutes les pages") : la page Smart Payment Center (/km-payment/{reference}/) n'est pas une vraie Page WordPress mais une URL générée par une règle de réécriture — elle n'était donc jamais reconnue comme une page KM Family, et ne recevait jamais le traitement "pleine largeur" (masquage de la barre de titre Astra) appliqué aux autres pages du plugin. C'est justement la page la plus visitée du parcours (paiement), d'où l'impression que le problème touchait "toutes les pages". Elle est désormais correctement reconnue et traitée comme les autres.

= 2.3.19 - 2026-08-03 =
* VÉRIFICATION : un membre peut déjà soutenir plusieurs artistes simultanément (abonnements stockés indépendamment par artiste) — aucune correction nécessaire sur ce point.
* CORRECTIF (changement de palier à la baisse cassé) : le code promettait "ton palier passera à X à la prochaine échéance" en écrivant un statut "palier_pending" — mais rien nulle part dans le plugin ne relisait jamais cette valeur (pas de renouvellement automatique dans ce système à paiement manuel). La promesse ne se concrétisait jamais. Une baisse de palier ne coûtant pas plus cher, elle s'applique désormais immédiatement : le membre garde sa date d'expiration déjà payée, avec les avantages du nouveau palier dès la confirmation.
* Le bouton "changer de palier" utilise maintenant le même repli REST robuste que le reste du parcours de paiement (il utilisait encore l'ancienne logique limitée aux codes 403/406/503).

= 2.3.18 - 2026-08-03 =
* CORRECTIF (retour du message générique "problème réseau ou serveur" après plusieurs tests) : régression introduite en 2.3.16. Le repli REST se déclenchait sur TOUT échec, y compris une réponse d'erreur parfaitement légitime du serveur (ex. limite anti-abus de 10 tentatives/15 min atteinte pendant les tests). Résultat : chaque tentative bloquée consommait le quota anti-abus deux fois (une fois côté admin-ajax, une fois côté REST), épuisant le quota plus vite, ET le vrai message ("Trop de tentatives, réessaie dans quelques minutes") restait masqué derrière le message générique réseau. Le repli REST ne se déclenche désormais que sur un vrai échec réseau (réponse non exploitable) — une réponse d'erreur bien formée du serveur s'affiche maintenant telle quelle, immédiatement.
* Note : si le message de limite anti-abus apparaît, c'est normal après des tests répétés rapprochés — patienter ~15 minutes suffit, aucune action requise.

= 2.3.17 - 2026-08-03 =
* CORRECTIF (commande absente du tableau "Commandes en attente" malgré la notification reçue) : KMFamily_Orders::declare_payment() et mark_initiated() ne vérifiaient jamais si l'écriture en base de données réussissait réellement. En cas d'échec silencieux, la fonction renvoyait quand même "succès", l'email admin partait, et l'utilisateur voyait la confirmation d'envoi — mais la commande ne passait jamais au statut "awaiting_confirmation" en base, donc invisible dans le tableau. Le résultat de la mise à jour est désormais vérifié : en cas d'échec réel, un message d'erreur précis remonte maintenant (au lieu d'un faux succès), ce qui permettra aussi de diagnostiquer immédiatement si le problème se reproduit.

= 2.3.16 - 2026-08-03 =
* RENFORCEMENT DU REPLI REST (le message générique persistait malgré le repli ajouté en 2.3.15) : le repli ne se déclenchait que pour les codes HTTP 403/406/503, ce qui ratait d'autres formes de blocage (codes 429/502/520 renvoyés par certains WAF/CDN, ou une réponse HTTP 200 mais invalide comme "-1"/"0" — cas typique d'un nonce périmé à cause d'une page mise en cache, que jQuery traite comme un succès et non un échec). Le repli REST se déclenche désormais pour TOUT échec ou réponse invalide, sur toutes les actions du plugin (création de commande, paiement initié, déclaration de paiement, upload, profil, etc.), pas seulement sur une liste de codes.

= 2.3.15 - 2026-08-03 =
* CORRECTIF PAIEMENT CRITIQUE (commande créée mais qui ne progresse jamais, aucune notification admin) : cause identifiée précisément.
  1. "Paiement initié" (markInitiated) : l'interface affichait cette étape immédiatement après un clic sur "Payer", SANS attendre confirmation du serveur. Si l'appel échouait (WAF, réseau), l'utilisateur voyait quand même l'étape 2 s'afficher alors que rien n'était enregistré en base — désormais l'affichage n'avance qu'une fois le serveur confirmé, avec repli automatique via REST comme les autres actions.
  2. "Déclaration de paiement" ("J'ai déjà payé") : aucun repli REST n'existait ici. Si admin-ajax.php était bloqué, la déclaration échouait avec le message générique "problème réseau ou serveur" — et comme c'est CETTE étape qui déclenche l'email admin "[KM Family] Paiement à confirmer" ET qui fait passer la commande en file d'attente, un échec silencieux ici expliquait à la fois l'absence de notification et la commande introuvable dans "Abonnements > Commandes en attente". Ajout du même repli REST automatique (/mark-initiated et /declare-payment) que pour la création de commande et l'initiation du paiement automatique.

= 2.3.14 - 2026-08-03 =
* CORRECTIF CRITIQUE (cause réelle du bug de notification manquante) : l'action "J'ai déjà payé — confirmer ma transaction" n'était enregistrée côté serveur que pour les utilisateurs connectés à WordPress (wp_ajax_kmfamily_declare_payment), sans son pendant pour les invités (wp_ajax_nopriv_...) — contrairement à toutes les autres actions du Smart Payment Center. Or la quasi-totalité des paiements se font justement depuis un téléphone sans session WordPress (lien/QR scanné). Résultat : le clic semblait fonctionner côté client, mais la requête n'atteignait jamais le code qui crée la notification — rien n'était donc jamais enregistré, ni email, ni badge, ni "Historique des notifications".
* CORRECTIF (conséquence côté affichage) : en cas d'échec réseau ou serveur sur cette action (ou toute autre), le bouton restait bloqué indéfiniment sur "Envoi…" sans aucun message d'erreur — la fonction JS d'appel n'avait aucune gestion d'échec. Un message d'erreur clair s'affiche désormais et le bouton redevient utilisable.

= 2.3.13 - 2026-08-03 =
* CORRECTIF PRINCIPAL : les emails de notification (nouveau membre, paiement à confirmer) étaient envoyés à l'adresse e-mail d'administration générale de WordPress (Réglages → Général), sans aucun rapport avec le compte réellement utilisé par le plugin SMTP pour l'envoi (ex. contact@kophisgroup.com) — le SMTP peut fonctionner parfaitement et l'email partir sans erreur... vers une adresse que personne ne surveille. Ajout d'un champ dédié "Email de notification" dans Réglages KM Family → Général, indépendant de ce réglage générique.

= 2.3.12 - 2026-08-03 =
* CORRECTIF : quand une personne cliquait sur "J'ai déjà payé — confirmer ma transaction", la déclaration était bien enregistrée en base, mais l'alerte à l'équipe pouvait ne jamais s'afficher dans l'administration — un bug marquait TOUTES les notifications en attente comme "lues" dès qu'une page d'admin était ouverte, même celles qui n'avaient encore jamais été montrées (au-delà des 5 dernières affichées). Corrigé : seules les notifications réellement affichées sont désormais marquées comme lues.
* FIABILITÉ : l'envoi de l'email de notification (wp_mail) échoue silencieusement sur de nombreux hébergements (absence de SMTP configuré, etc.), sans laisser aucune trace jusqu'ici. Un échec est désormais journalisé et ajouté au panneau de notifications in-app, qui ne dépend pas de l'email pour fonctionner.
* AMÉLIORATION : un badge "💰 N paiement(s) à confirmer" apparaît maintenant dans la barre d'outils admin, visible sur TOUTE page de l'administration (et pas seulement en ouvrant le sous-menu Notifications) — garantit qu'une déclaration de paiement ne passe pas inaperçue même si l'email de notification n'arrive jamais. Un badge de compteur a aussi été ajouté sur le menu latéral KM Family.
* RECOMMANDATION : si les emails de KM Family n'arrivent toujours pas après cette mise à jour, vérifiez que l'adresse dans Réglages → Général → Adresse e-mail d'administration est une adresse réellement surveillée, pensez à vérifier les spams, et envisagez d'installer un plugin SMTP (WP Mail SMTP ou équivalent) — l'envoi d'email par défaut de WordPress est notoirement peu fiable pour des emails transactionnels comme ceux-ci.

= 2.3.11 - 2026-08-02 =
* CORRECTIF PAIEMENT (erreur générique "Une erreur est survenue" à l'initiation du paiement, surtout sur mobile) : deux causes possibles corrigées.
  1. La requête d'initiation de paiement (CinetPay/Paystack) n'avait aucun repli si admin-ajax.php était bloqué par le WAF/ModSecurity de l'hébergeur (cas déjà géré ailleurs dans le plugin — création de commande, upload d'avatar — mais jamais branché ici). Elle utilise maintenant le même mécanisme de repli automatique via l'API REST.
  2. La page de paiement (qui contient un jeton de sécurité unique par visite) n'était protégée que par nocache_headers(), insuffisant pour certains plugins de cache de page complète. Ajout de la constante DONOTCACHEPAGE pour empêcher toute mise en cache de cette page sensible.

= 2.3.10 - 2026-08-02 =
* CORRECTIF AFFICHAGE (bannière [kmfamily_home_banner] sur mobile/tablette) : les avatars des artistes s'affichaient en grandes images empilées au lieu de petits cercles alignés. Le CSS était correct mais pouvait être écrasé par des styles externes (thème, gestion responsive des images) ; les propriétés critiques (taille, forme circulaire, recadrage) sont désormais protégées par !important pour rester fiables quel que soit l'environnement.
* CORRECTIF PAIEMENT (Smart Payment Center sur mobile/tablette) : la détection mobile reposait uniquement sur wp_is_mobile() côté serveur, qui NE détecte PAS les iPad comme mobile (Safari sur iPadOS se présente comme un ordinateur de bureau depuis iPadOS 13). Résultat sur tablette : le lien de paiement s'ouvrait dans un nouvel onglet au lieu du même onglet, ce qui pouvait perturber la prise en main par l'app de paiement. Détection renforcée côté client (taille d'écran + support tactile) en complément du signal serveur.

= 2.3.9 - 2026-07-31 =
* NETTOYAGE CSS + CORRECTIF : passage en revue systématique du fichier km-family.css pour repérer les règles dupliquées accumulées au fil des refontes (62 sélecteurs concernés). La quasi-totalité sont redondants sans effet visuel néfaste (les règles récentes l'emportent proprement). Un second cas de "fuite" a été trouvé et corrigé : le badge "✓ ACTIF" (palier en cours de l'adhérent, visible sur la page palier d'un artiste) héritait d'un `left: 12px` obsolète en plus du `right: 12px` actuel, ce qui l'étirait sur presque toute la largeur de la carte au lieu d'un petit badge de coin. 6 doublons strictement identiques (aucun effet, juste du code mort) ont aussi été supprimés pour alléger le fichier.

= 2.3.8 - 2026-07-31 =
* CORRECTIF AFFICHAGE (badge "POPULAIRE" sur la carte Or) : le ruban débordait de la carte et son texte était coupé. Cause : deux définitions CSS concurrentes du même ruban (une ancienne en diagonale de coin, une récente en bandeau horizontal) se mélangeaient — la carte affichait la position du bandeau horizontal mais gardait la rotation à 45° de l'ancienne version. L'ancienne définition obsolète a été supprimée ; seul le bandeau horizontal (avec coins arrondis assortis à la carte) reste actif.

= 2.3.7 - 2026-07-31 =
* AMÉLIORATION AFFICHAGE (page "Rejoindre la KM Family") : les 6 paliers s'affichaient sur une seule ligne de 6 colonnes sur grand écran, ce qui les rendait étroits et moins lisibles. Disposition repensée en 2 lignes de 3 colonnes sur desktop (identique en dessous de 1400px), avec le même comportement responsive qu'avant sur tablette (2 colonnes) et mobile (1 colonne).

= 2.3.6 - 2026-07-31 =
* CORRECTIF AFFICHAGE (page "Rejoindre la KM Family" et bannière d'accueil) : les espaces entre le texte normal et les portions en gras/coloré ("piliers.Et ces piliers…", "abonnement.Vous devenez…", "francophonesles moyens…") étaient absents à l'affichage alors qu'ils étaient bien présents dans le code. Cause : un espace normal entre deux éléments HTML peut être supprimé par certains plugins de minification HTML (cache/optimisation) qui ne le jugent pas significatif. Même correctif que celui déjà appliqué en 2.2.1 sur le titre de la page artiste : remplacement par des espaces insécables (&nbsp;), qui ne sont jamais supprimés par ces outils.

= 2.3.5 - 2026-07-29 =
* SÉCURITÉ : ajout d'une limitation de débit (anti-abus) par adresse IP sur les endpoints publics sensibles, qui n'avaient jusqu'ici aucune limite de fréquence : création de commande (8/15 min), déclaration de paiement (10/15 min), connexion (8/10 min, anti brute-force) et inscription (5/15 min, anti-spam de faux comptes).

= 2.3.4 - 2026-07-29 =
* CORRECTIF AFFICHAGE (bande blanche menu/contenu, suite) : le correctif précédent (2.3.0) ciblait des noms de classes devinés pour Astra/Elementor, qui ne correspondent pas exactement à ce thème sur-mesure — la bande persistait. Nouvelle approche, indépendante du thème : détection automatique en JS de tout élément vide (sans texte ni média) entre le menu et le contenu KM Family, neutralisé au chargement de la page, sans jamais toucher à un élément contenant du vrai contenu.

= 2.3.3 - 2026-07-29 =
* NOUVEAU : parcours "palier d'abord, artiste ensuite" sur la page "Rejoindre la KM Family". Chaque carte palier générique (Bronze, Argent, Or...) a maintenant un bouton "Choisir ce palier" qui mémorise le choix et amène en douceur jusqu'à la section des artistes (mise en évidence visuelle) ; une fois l'artiste choisi, son palier et sa périodicité sont pré-sélectionnés automatiquement sur sa page. Le parcours "artiste d'abord" existant n'a pas changé — les deux sens fonctionnent désormais.
* Le CSS et le mécanisme de mémorisation existaient déjà dans le code (v2.1.9) mais le bouton avait été retiré du template à un moment donné (v2.1.10) sans que le CSS/JS associé ne soit nettoyé ; au passage, le scroll doux vers la section artistes, mentionné dans un commentaire mais jamais implémenté, a été ajouté.

= 2.3.2 - 2026-07-29 =
* CORRECTIF : les liens "se connecter" / "créer un compte" / "mot de passe oublié" générés ailleurs sur le site (thème, widgets, autres plugins — pas seulement le parcours d'abonnement déjà corrigé en 2.3.1) pouvaient encore pointer vers les pages WordPress natives (wp-login.php) au lieu de la page KM Family dédiée. Filtres globaux ajoutés (login_url, register_url, lostpassword_url) pour que ces trois cas ouvrent systématiquement le bon onglet de la page KM Family, où qu'ils apparaissent sur le site.
* GARDE-FOU : ces filtres n'interfèrent jamais avec l'accès /wp-admin/ — si le lien sert à revenir vers l'admin (ex. session expirée pendant une visite de wp-admin), WordPress garde son comportement natif, pour ne jamais bloquer l'accès administrateur.

= 2.3.1 - 2026-07-29 =
* CONFIRMATION : le compte KM Family est bien exigé AVANT tout paiement (vérifié côté serveur à la création de la commande, impossible à contourner depuis le navigateur) — personne ne peut s'abonner sans compte.
* CORRECTIF MAJEUR : le message "vous devez créer un compte" renvoyait vers les pages WordPress natives génériques (wp_registration_url() / wp_login_url()) au lieu du formulaire KM Family personnalisé — avec un risque réel d'impasse si l'inscription native WordPress est désactivée sur le site (réglage indépendant de la KM Family). Pointe désormais vers la page de connexion/inscription KM Family dédiée.
* AMÉLIORATION : le palier et la périodicité choisis avant la création de compte ne sont plus perdus — la personne est ramenée automatiquement sur la page de l'artiste, avec son choix pré-sélectionné et mis en évidence, au lieu de devoir tout reparcourir depuis la liste générale des artistes. Remplace aussi la boîte de dialogue navigateur (confirm()) par une redirection directe vers la page dédiée.

= 2.3.0 - 2026-07-29 =
* NOUVEAU : shortcode [kmfamily_home_banner], pensé pour la page d'accueil du site (mais utilisable sur n'importe quelle page). Bannière plein écran sombre/or dans l'identité visuelle KM Family : accroche, avatars des artistes actifs (photo de mise en avant, cliquables vers leurs paliers), compteur du nombre de piliers déjà actifs, et bouton "Devenir un pilier" vers la page d'adhésion. S'affiche en pleine largeur même sur une page hors périmètre KM Family (ne dépend pas du body class réservé aux pages KM Family). Attributs optionnels `titre` et `texte` pour personnaliser l'accroche.

= 2.2.4 - 2026-07-29 =
* AMÉLIORATION : le QR code du Smart Payment Center encode désormais directement le lien marchand (Wave/Orange Money/MTN/Moov) — identique à ce qu'ouvre déjà le bouton "Payer avec X", et au QR que ces opérateurs affichent eux-mêmes. L'app du moyen de paiement scanné le reconnaît nativement et va droit au paiement, au lieu de transiter d'abord par notre page (suppression de l'aller-retour et du code de redirection automatique associé, devenu inutile).

= 2.2.3 - 2026-07-29 =
* CORRECTIF CRITIQUE : scanner le QR code du Smart Payment Center avec un téléphone (non connecté au compte KM Family) forçait systématiquement une page de connexion au lieu de mener au paiement marchand — c'est pourtant l'usage prévu du QR (commande créée sur ordinateur, paiement finalisé depuis le téléphone avec l'app Wave/Orange Money). L'accès à une commande est désormais autorisé à tout appareil connaissant sa référence (jeton d'accès à 10 caractères, déjà secret et propre à cette commande), la vérification de propriétaire ne s'appliquant que si un AUTRE membre est connecté sur cet appareil.
* Ce correctif s'applique de façon cohérente à la page elle-même et aux actions associées (statut de commande, marquage "paiement initié", déclaration de transaction).

= 2.2.2 - 2026-07-29 =
* CORRECTIF CRITIQUE : la page /km-payment/{référence}/ affichait "Cette page ne semble pas exister" (404) — la règle de réécriture d'URL du Smart Payment Center n'était jamais rechargée par WordPress après une mise à jour de fichiers (seule une (ré)activation du plugin le fait). Un rechargement automatique se déclenche désormais à chaque changement de version.
* CORRECTIF AFFICHAGE : sur la page d'engagement ("Devenez un pilier de..."), l'espace avant le nom de l'artiste était visuellement écrasé ("deMonsieur Kophi" au lieu de "de Monsieur Kophi"), à cause d'un letter-spacing négatif combiné à deux définitions CSS concurrentes du même titre. Le CSS a été nettoyé (une seule définition) et l'espace sécurisé dans le HTML.
* CORRECTIF AFFICHAGE : bande blanche entre le menu et le contenu sur les pages KM Family (bandeau de titre du thème resté visible au-dessus de nos sections plein écran) — neutralisé spécifiquement sur ces pages.

= 2.2.1 - 2026-07-28 =
* CORRECTIF SÉCURITÉ : le Smart Payment Center ne revérifiait l'appartenance de la commande qu'au niveau du template (défense en profondeur incomplète, malgré le commentaire l'annonçant côté guard) — la vérification est maintenant bien faite dès template_redirect.
* CORRECTIF SÉCURITÉ : le webhook Paystack acceptait un secret non configuré (HMAC calculé avec une clé vide) — un webhook non configuré rejette désormais explicitement toute requête au lieu de calculer une signature devinable.
* CORRECTIF AFFICHAGE : la ligne de connexion entre les étapes de la timeline du Smart Payment Center était décalée par rapport aux points (chevauchement visuel) — repositionnée pour un alignement centre-à-centre exact.
* CORRECTIF AFFICHAGE : le bouton "Payer" ne montrait aucun changement visuel une fois désactivé pendant la connexion à la passerelle — ajout d'un style disabled clair (opacité réduite, curseur adapté).
* CORRECTIF AFFICHAGE : les champs du formulaire de déclaration de paiement pouvaient légèrement déborder du panel sur mobile (box-sizing manquant) — corrigé.
* AMÉLIORATION : ajout d'états de focus visibles (accessibilité clavier) et de survol cohérents sur les boutons et onglets du Smart Payment Center.
* CORRECTIF INFO : le texte de bas de page des paliers annonçait un "renouvellement mensuel automatique" même quand une périodicité trimestrielle/semestrielle/annuelle était choisie — reformulé pour rester exact quelle que soit la périodicité.
* MISE À JOUR : compatibilité testée jusqu'à WordPress 7.0 "Armstrong".

= 2.2.0 - 2026-07-25 =
* NOUVEAU : Smart Payment Center — page intermédiaire /km-payment/{ref}/ qui crée, suit et sécurise chaque commande avant de rediriger vers le marchand
* NOUVEAU : table dédiée wp_kmfamily_orders — cycle de vie complet (pending → initiated → awaiting_confirmation → confirmed → active)
* NOUVEAU : moyens de paiement manuels Wave, Orange Money, MTN Money, Moov Money — QR code dynamique (généré client-side, testé scannable), bouton de paiement, copie du lien
* NOUVEAU : QR code et bouton pointent tous deux vers la commande KM Family (jamais le lien marchand brut) — tracking complet + prêt pour bascule API dès disponibilité
* NOUVEAU : adaptation automatique mobile/desktop (wp_is_mobile) — QR mis en avant sur desktop, bouton en avant sur mobile
* NOUVEAU : déclaration de paiement par l'adhérent (référence de transaction) + file d'attente admin "Commandes en attente de confirmation" avec confirmation en un clic
* NOUVEAU : notification admin instantanée (email + notification dashboard) dès qu'un paiement manuel est déclaré
* AMÉLIORATION : montant recalculé côté serveur (jamais fait confiance au client) à partir du palier + périodicité
* AMÉLIORATION : webhooks CinetPay/Paystack délèguent à la commande liée si présente (order_ref) — plus aucun risque de double-activation
* CORRECTIF : le sélecteur d'onglet CinetPay/Paystack n'affiche plus une passerelle non réellement active (routage `payment_gateway` respecté)

= 2.1.11 - 2026-04-23 =
* Refonte visuelle premium de la page paliers artiste (style Hostinger)
* Sélecteur de périodicité proéminent avec tabs pilulaires, gradient doré actif
* Badges de réduction flottants style épinglé (-10%, -15%, -20%)
* Ribbon "LE PLUS POPULAIRE" sur le palier Or
* Hint "💡 Plus vous vous engagez longtemps, plus vous économisez"
* Cards palier avec hover lift + shadow + border-top coloré gradient
* Palier Or mis en avant avec scale(1.03) + background doré subtil
* Hiérarchie visuelle renforcée : eyebrow pill + titre large + nom artiste italique doré
* Social proof "X membres soutiennent déjà cet artiste" avec dot animé
* is_enabled() activé par défaut si option non définie (rétro-compat)

= 2.1.10 =
* Routing /rejoindre-km-family/?artiste_id=X pour paliers par artiste
* Suppression CTA sur cartes palier

= 2.1.9 =
* Refonte design premium de la page /rejoindre-km-family/
* Cache-busting sur is_enabled()

= 2.1.8 =
* Sélecteur de période sur showcase
* Palier Or : -20% tickets concert

= 2.1.7 =
* Shutdown handler agressif pour logger les fatal errors
* Casts explicites (int) dans l'aperçu admin

= 2.1.6 =
* sanitize_settings ultra-défensif avec try/catch global
* Fallback sur valeurs précédentes si erreur

= 2.1.5 =
* Système de périodicité avec réductions configurables (10/15/20%)
* Sélecteur de période avec recalcul live des prix
* H1 nom abonné en blanc capital bold

= 2.1.4 =
* Correctif changement de palier (nonce tolérant + fallback REST)
* Helper KMFamilyRequest() global pour fallback AJAX→REST
* Nouveau copywriting Urban Gospel Francophone

= 2.1.3 =
* Contournement WAF/ModSecurity via endpoints REST alternatifs

= 2.1.2 =
* Upload avatar : capacité upload_files temporaire
* Palier Bronze : tarif ajusté de 2 000 à 1 000 FCFA
* Nouveau palier LIBRE (Le Cœur Généreux) : don au montant libre (min. 500 FCFA)
* Palier Libre : 4 boutons de suggestion (500/1000/3000/10000 FCFA) + input custom
* Design distinct pour le palier Libre (gradient rose/corail)

= 2.1.0 =
* Page "Mon Profil" avec upload avatar
* Dashboard avec switcher de palier + onglet Découvrir
* Stats (artistes soutenus, contribution totale)
* Outil nettoyage doublons pages
* Template pleine largeur + body class auto

= 2.0.0 =
* 5 paliers standardisés
* Badges automatiques
* Auth moderne AJAX
* Notifications émotionnelles
* Protection totale médias (tokens HMAC)

== Shortcodes ==

* [kmfamily_paliers] — Paliers d'un artiste (6 paliers dont LIBRE)
* [kmfamily_paliers_all] — Tous les artistes
* [kmfamily_dashboard] — Espace membre
* [kmfamily_profile] — Modification profil + avatar
* [kmfamily_login] — Connexion/Inscription
* [kmfamily_badge] — Badge membre
* [kmfamily_emotional_message]
* [kmfamily_exclusive_feed artiste_id="X"]
