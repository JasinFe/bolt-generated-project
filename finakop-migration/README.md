# Kit de migration FinaKop ERP Core → VPS (application autonome)

Ce kit fait passer FinaKop d'une extension WordPress à une **application autonome** servie sur son sous-domaine, depuis un VPS, sans perte de données et sans toucher au système de licences.

**Commencez par lire [PLAN-MIGRATION.md](PLAN-MIGRATION.md).** Il contient :
- le plan de migration par phases, avec des cases à cocher ;
- les recommandations ;
- le comparatif des hébergeurs (OVHcloud recommandé) ;
- la recette à faire avant d'ouvrir ;
- les procédures d'exploitation.

Démarrage rapide, sur un VPS Debian 13 neuf :

```bash
DOMAINE=essai.finakopcore.kophisgroup.com EMAIL=admin@kophisgroup.com ./scripts/installer-vps.sh
/srv/finakop/scripts/deployer-version.sh finakop-erp-core-1_875_5.zip
```

⚠️ Ne copiez **jamais** le dossier `LICENCES/` (clés privées) sur le serveur.
