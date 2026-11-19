=== KM Blog ===
Contributors: kophisgroup
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Blog personnalisé pour KOPHI'S MUSIC, séparé des actualités du label.

== Description ==
Type de contenu « Blog » avec rubriques et sujets, sous-titre, temps de lecture,
article « À la une », sommaire automatique, barre de progression de lecture,
partage WhatsApp/Facebook/X/natif, articles similaires, encarts newsletter et
KM Family, données structurées + Open Graph, shortcode [km_blog] et réglages.

== Shortcode ==
[km_blog limit="6" columns="3" category="slug-rubrique" tag="slug-sujet" offset="0" title="À lire sur le blog" more="1"]
more="0" (ou "non", "false") masque le bouton « Tous les articles ».

== Installation ==
Extensions > Ajouter > Téléverser une extension > km-blog.zip > Activer.
Puis Blog > Réglages.

== Personnalisation ==
Copiez les fichiers du dossier templates/ dans votre thème, dans un dossier km-blog/, pour les surcharger.

== Changelog ==
= 1.2.0 =
* Correctif : les pages de rubriques (/blog/rubrique/…) et de sujets (/blog/sujet/…) renvoyaient une erreur 404.
* Correctif : les titres partagés (WhatsApp, X) et le JSON-LD affichaient des entités HTML (&#8217;…).
* Correctif : « Copier le lien » ne retombait pas sur la méthode de secours en cas d'échec et pouvait garder le libellé « Lien copié ».
* Sécurité : JSON-LD protégé contre l'injection de balise </script> ; URL des encarts limitées à http(s) ou chemins relatifs.
* Performance : temps de lecture mis en cache à l'enregistrement ; styles chargés dans le <head> sur les pages avec [km_blog] ; script en defer ; images responsives (sizes, fetchpriority).
* SEO : balises Open Graph / Twitter, fil d'Ariane et JSON-LD enrichi (logo, rubrique, temps de lecture) quand aucune extension SEO n'est active (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework, Slim SEO).
* Nouveau design : cartes entièrement cliquables, rubrique en pastille sur l'image, « À la une » repensé, compteurs par rubrique, typographie éditoriale, sommaire, barre de progression, icônes de partage, partage natif sur mobile, encadré auteur et encarts redessinés.
* Accessibilité : focus visibles, aria-current, annonces pour lecteurs d'écran, zones tactiles de 44 px, contraste AA en thème clair, prise en compte de « réduire les animations ».
* Articles similaires complétés par les plus récents si la rubrique ne suffit pas.
* Colonne « À la une » dans la liste des articles, lien « Réglages » dans les extensions, alerte si le slug du blog est déjà pris par une page.
* Les URL sont régénérées automatiquement après mise à jour du plugin.
= 1.1.0 =
* Article « À la une » (case dans la fiche de l'article).
* Encarts de fin d'article : newsletter et KM Family (adresses réglables).
= 1.0.0 =
* Première version.
