# Outils à déposer sur l'ANCIEN hébergement (site WordPress actuel)

| Fichier | Usage |
| --- | --- |
| `diagnostic-hebergement.php` | À lancer sur le NOUVEL hébergement avant migration (voir en tête du fichier). |
| `maintenance-wp.php` | À copier dans `wp-content/mu-plugins/` de l'ancien site juste avant l'export final : FinaKop répond « maintenance », plus aucune écriture. |
| `migration-donnees.php` | Export contrôlé des données : `php migration-donnees.php exporter <dossier-donnees-finakop> <dossier-sortie> <chemin/wp-config.php>` |

Le dossier de sortie (avec son `MANIFESTE.json`) s'importe ensuite dans la plateforme :
`php ~/finakop/current/bin/finakop tenant:importer <identifiant> <dossier-sortie> --nom="Nom de l'entreprise"`.

Recommandé : installer d'abord l'extension `finakop-erp-core-1.876.0.zip` sur l'ancien site (mise à jour normale), l'ouvrir une fois, puis exporter. Elle enregistre l'empreinte de la clé de chiffrement, ce qui permet à la plateforme de refuser de démarrer si la clé était oubliée.
