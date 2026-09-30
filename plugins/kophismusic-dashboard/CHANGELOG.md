# KOPHI'S MUSIC — Artist Dashboard : journal des modifications

## 5.8.1 — 2026-09-30

### Sécurité
- **Fuite de relevés entre artistes** : le repli de recherche des rapports utilisait le nom d'affichage du compte (modifiable par l'artiste) avec une comparaison « contient » (LIKE). Un artiste renommé « a » voyait les streams et gains de tous les artistes contenant un « a ». Seul le nom TuneCore fixé par le label est utilisé, en égalité stricte, sans jamais reprendre un rapport attribué à un autre compte.
- **Force brute** : la connexion artiste, « mot de passe oublié » et la réinitialisation n'avaient aucune limite de tentatives. Limiteur partagé avec KM Family (par compte visé + plafond large par source), repli local sinon.
- Shortcode `[km_family_revenus user_id="…"]` : l'attribut n'est plus honoré que pour le personnel du label.
- Import CSV : contrôle de capacité, `is_uploaded_file`, taille maximale.
- Commission du label bornée à 0–100 %.

### Ponts avec KM Family
- `admin-post.php` n'est plus intercepté par la redirection « artistes hors de wp-admin » : la publication « Parler à ma famille » fonctionne enfin pour le rôle `artiste_label` (elle était redirigée avant traitement).
- Déconnexion : plus de `wp_redirect()+exit` global sur `wp_logout` (tous les utilisateurs, y compris membres KM Family et admins, étaient envoyés sur « Connexion Artiste » et `redirect_to` était ignoré). Filtre `logout_redirect`, limité aux artistes/managers.
- Connexion : un membre KM Family (ou un compte relié à une fiche artiste sans le rôle) est envoyé vers le bon espace au lieu de « Accès réservé au label » (`km_user_home_url()`).
- Accès au Dashboard Label et à la section KM Family du label via `km_user_is_label_staff()` (inclut la capacité `km_view_label_dashboard`).
- Mots de passe traités comme wp-login.php (forme slashée) : un mot de passe contenant `'` ou `\` fonctionne partout.

### Bugs
- « KM Dashboard → Import TuneCore » appelait `km_import_tunecore_csv()`, fonction inexistante (erreur fatale) : délégation à l'importeur complet.
- Compteurs KPI : ils héritaient du style « pastille de notification » (`.km-counter`) — « Total streams » s'affichait comme une barre orange vide et « Mes gains » sortait de sa carte. Valeurs rendues côté serveur, animation indépendante de Chart.js (CDN bloqué = chiffres à 0 auparavant), respect de `prefers-reduced-motion`.
- Avertissements PHP `REQUEST_METHOD` en CLI/cron.
- Certificats de relevé conservés jusqu'à 5 000 (les plus anciens devenaient invérifiables trop vite).

### Responsive & design
- En-tête mobile : les boutons ne sont plus masqués par un défilement horizontal (« Relevé PDF » coupé) — passage sur plusieurs lignes, cibles 44 px.
- KPI sur 2 colonnes en smartphone, 3 en tablette ; carte orpheline pleine largeur ; mini-donut contenu dans sa carte.
- Gouttière cohérente pour filtres et KPI (collaient aux bords entre 1024 et 1600 px) ; suppression de la « carte dans la carte » des filtres.
- Tableaux défilants dans leur cadre, champs 16 px (pas de zoom iOS), focus clavier visible.
