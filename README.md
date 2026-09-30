# KOPHI'S MUSIC — extensions WordPress

| Extension | Dossier | Version |
|---|---|---|
| KM Family (soutien des artistes, paiements Mobile Money, médias protégés) | `plugins/km-family` | 3.4.1 |
| KOPHI'S MUSIC — Artist Dashboard (relevés TuneCore, espace artiste/label) | `plugins/kophismusic-dashboard` | 5.8.1 |

Les deux extensions communiquent par des « ponts » optionnels : le tableau de bord lit
`KMFamily_Revenue` (revenus communautaires) et délègue la publication artiste à
`KMFamily_Artist_Publishing`. Chacune fonctionne seule si l'autre est désactivée.

Journaux des modifications : `plugins/km-family/readme.txt` et
`plugins/kophismusic-dashboard/CHANGELOG.md`.

## Pré-requis serveur à ne pas oublier
- **Nginx** : le `.htaccess` du dossier des médias protégés est ignoré. Ajouter
  `location ^~ /wp-content/uploads/km-family-protected/ { deny all; }`.
- **Derrière Cloudflare / un proxy** : `define( 'KMFAMILY_TRUSTED_PROXY', 'cloudflare' );`
  (ou `true`) dans `wp-config.php`, sinon tous les visiteurs partagent la même IP pour
  les limites anti-abus.
