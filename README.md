# OBS Overlay Kit + gestionnaire de licences

**Propriété de KOPHI'S GROUP SAS** — tous droits réservés.
✉ contact@kophisgroup.com · ☎ / WhatsApp +225 05 03 40 43 89 · 🌐 www.kophisgroup.com

| Dossier | Contenu |
|---------|---------|
| [`obs-overlay-kit/`](obs-overlay-kit/README.md) | Le kit d'habillage TV pour OBS Studio (v7), avec licence d'utilisation : essai de 14 jours, mode gratuit limité avec filigrane, activation par clé signée |
| [`licence-manager/`](licence-manager/README.md) | Le gestionnaire de licences du vendeur : génération des clés, clients, offres, tableau de bord avec graphiques, activation en ligne et révocation |

Les deux outils fonctionnent avec Node.js 18+ et sans aucune dépendance.

La clé privée de signature (`licence-manager/data/cle-privee.pem`) n'est **pas** dans ce dépôt :
elle est créée au premier lancement du gestionnaire (ou fournie dans le zip de livraison).
Copiez ensuite la clé publique correspondante dans `obs-overlay-kit/licence/cle-publique.pem`.
