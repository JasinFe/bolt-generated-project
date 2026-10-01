# 1.876.2 : sécurité renforcée, plateforme invisible, site vitrine

Deux espaces distincts sur le même domaine :

| Adresse | Rôle | Moteurs et IA |
| --- | --- | --- |
| `finakoperp.com` (et `www`, redirigé) | **Site vitrine** : présentation commerciale, public | Indexable, pour être trouvé |
| `app.finakoperp.com`, `<client>.finakoperp.com` | **Plateforme** : données des entreprises | **Invisible** : refus, non-indexation, aucun lien |

## 1. Plateforme invisible

Plusieurs couches, car aucune ne suffit seule :

| Couche | Effet | Où |
| --- | --- | --- |
| `robots.txt` | Interdit tout aux robots. Interdit aussi, nommément, 26 robots d'IA (GPTBot, ClaudeBot, PerplexityBot, CCBot, Google-Extended, Bytespider…), car certains n'obéissent qu'à leur nom | Écrit par `deployer.sh` |
| En-tête `X-Robots-Tag` | `noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai` sur **toutes** les réponses, fichiers statiques compris | `.htaccess` de la plateforme + PHP |
| Refus actif | Les robots connus (moteurs, IA, aperçus de liens WhatsApp/Facebook/Slack, navigateurs automatisés…) reçoivent **403**, avant toute résolution de client | `Amorcage::estRobot()` ; réglage `securite.bloquer_robots` |
| Pas d'oracle | Le portail `app.` ne dit plus si un espace existe : tout identifiant valide est redirigé, l'existence n'est révélée qu'à l'arrivée | `Pages::portail()` |
| Version masquée | Le numéro de version n'apparaît plus sur les pages publiques (connexion…) | `securite.masquer_version` |
| Dossier caché | `finakoperp.com/finakop-app/…` → 404 : la plateforme n'est joignable que par ses sous-domaines | `.htaccess` |
| Cookie `__Host-` | Cookie de session lié à l'hôte exact, HTTPS obligatoire | `Amorcage` |
| Cloudflare | Blocage des robots vérifiés sur les sous-domaines (§4) | Tableau de bord Cloudflare |

Les appels d'API (`/api`) et les webhooks de paiement (`/webhook`) ne sont **pas** concernés par le refus des robots. Ils restent protégés par jeton ou signature.

**Limites, honnêtement :**
- `robots.txt` et les en-têtes sont des **demandes** : les robots respectueux (Google, Bing, OpenAI, Anthropic…) les suivent, les autres non.
- Le refus par « user-agent » arrête les robots qui se déclarent. Un robot qui se fait passer pour un navigateur passe ce filtre, mais il tombe ensuite sur la page de connexion, sans rien à indexer.
- Les **noms** de sous-domaine sont publics par nature. Les certificats SSL sont inscrits dans des registres publics (Certificate Transparency), et un curieux peut y lire `newloock.finakoperp.com`. Le contenu reste inaccessible sans identifiants. Pour ne plus exposer chaque nom, utilisez un certificat **joker** (`*.finakoperp.com`), par exemple un certificat d'origine Cloudflare, plutôt qu'un certificat par sous-domaine.

## 2. Double authentification (2FA)

- Code à 6 chiffres d'une application d'authentification (Google Authenticator, Microsoft Authenticator, Authy, 2FAS, Aegis…). Depuis la 1.876.4, l'application est la méthode de référence : seule méthode pour les administrateurs ; code par e-mail désactivé par défaut (`securite.2fa_email`), réservé aux non-administrateurs si on le réactive. Le SMS demanderait un fournisseur payant : voir 11-GUIDE-PRATIQUE §5.
- **Obligatoire pour les administrateurs** (`securite.2fa_admin_obligatoire`) ; facultative pour les autres comptes (Mot de passe → *Double authentification*).
- À la prochaine connexion, un administrateur est conduit à l'écran d'activation : il scanne le QR code, saisit un code, puis reçoit **10 codes de secours** à usage unique. Il doit les **imprimer ou les ranger** dans un coffre de mots de passe.
- Le secret est chiffré avec la clé de l'espace. Un code déjà utilisé ne peut pas resservir. 5 essais au plus, puis retour à la connexion, et la tentative compte dans le blocage par IP.
- Téléphone perdu et codes de secours perdus : le super administrateur (SSH) lève la 2FA du compte, qui devra la reconfigurer :
  ```bash
  fk --tenant=<client> 2fa:etat
  fk --tenant=<client> 2fa:desactiver <identifiant>
  ```
  L'action est journalisée.

## 3. Autres renforcements de 1.876.2
- En-têtes supplémentaires : `X-Permitted-Cross-Domain-Policies: none`, `Referrer-Policy` sur les fichiers statiques.
- Mise à jour plus sûre : `deployer.sh` confie désormais le déploiement au script de la **nouvelle** version, qui seul connaît les nouvelles règles du dossier web.
- Déjà en place (rappel) : bases séparées par client, secrets chiffrés, sauvegardes chiffrées et relues, CSRF, CSP, blocage progressif par IP réelle et par compte, licence obligatoire, contrôle des téléversements, neutralisation des formules dans les CSV.

## 4. Cloudflare : bloquer les robots sur la plateforme seulement

Security → WAF → *Custom rules* → **Create rule** :

| Champ | Valeur |
| --- | --- |
| Nom | `Plateforme invisible` |
| Expression (*Edit expression*) | `(cf.client.bot or http.user_agent contains "GPTBot" or http.user_agent contains "ClaudeBot" or http.user_agent contains "PerplexityBot" or http.user_agent contains "CCBot" or http.user_agent contains "Bytespider") and not http.host in {"finakoperp.com" "www.finakoperp.com"} and not starts_with(http.request.uri.path, "/api/") and not starts_with(http.request.uri.path, "/webhook")` |
| Action | **Block** |

- `cf.client.bot` couvre les robots **vérifiés** par Cloudflare (Google, Bing, robots d'IA déclarés…). Il est disponible en Free.
- Le site vitrine reste exclu, donc trouvable.
- Security → Bots → *Block AI bots* : laissez-le **désactivé**, car il agit sur tout le domaine, vitrine comprise. La règle ci-dessus fait le travail pour la plateforme. Si vous ne voulez pas non plus que les IA lisent la vitrine, activez-le : c'est un choix commercial, sans risque pour les données.
- *Manage robots.txt* (si proposé) : **Off**. Chaque espace sert déjà son propre `robots.txt`.

## 5. Installer le site vitrine

Vitrine 2.2 (`finakop-site-vitrine-2.2.zip`) : `index.html`, `merci.html`, `contact.php`, `assets/` (CSS, JS, images, vidéos MP4), `robots.txt`, `sitemap.xml`, `.htaccess`. Aucun service extérieur (ni police, ni statistiques, ni CDN) ; CSP stricte (`script-src 'self'`). Elle ne contient **aucun lien** vers la plateforme. Le seul script PHP autorisé est `contact.php` (formulaire de devis) : origine vérifiée, champ piège, délai minimal, limites de débit, aucun e-mail automatique au visiteur, copie de chaque demande hors web (`~/domains/finakoperp.com/vitrine-donnees`, 0600). Installation pas à pas : **11-GUIDE-PRATIQUE.md §3**.

1. hPanel → Gestionnaire de fichiers → `domains/finakoperp.com/public_html/`.
2. Supprimez la page d'attente `index.html` (ou `default.php`) d'Hostinger. **Ne touchez pas** au dossier `finakop-app`.
3. Envoyez le ZIP dans `public_html`, puis *Extraire* (fichier `.htaccess` compris : activez l'affichage des fichiers cachés pour le vérifier).
   En SSH, au lieu des étapes 1 à 3 :
   ```bash
   cd ~/domains/finakoperp.com/public_html && rm -f index.html default.php && rm -rf assets index.html merci.html contact.php && unzip -o ~/domains/finakoperp.com/finakop-site-vitrine-2.2.zip
   ```
4. Adresse de contact : le formulaire écrit à `supports@finakoperp.com`. Créez cette boîte dans hPanel (Emails).
5. Contrôle :
   - https://finakoperp.com s'affiche ;
   - https://www.finakoperp.com redirige vers https://finakoperp.com ;
   - https://finakoperp.com/finakop-app/ répond **404** ;
   - une connexion à un espace client fonctionne toujours (le `.htaccess` de la vitrine ne s'applique qu'à `finakoperp.com`).
6. Facultatif : déclarez le site dans Google Search Console et Bing Webmaster Tools, avec `https://finakoperp.com/sitemap.xml`.

## 6. Mise à jour 1.876.1 → 1.876.2

Cette fois, utilisez le script **de l'archive** : celui installé (1.876.1) ne sait pas encore publier les nouvelles règles. Les versions suivantes le feront d'elles-mêmes.

```bash
unzip -o -j ~/domains/finakoperp.com/finakop-plateforme-1.876.2.zip finakop-plateforme/scripts/deployer.sh -d /tmp/fkdep \
  && PHP=/opt/alt/php84/usr/bin/php bash /tmp/fkdep/deployer.sh ~/domains/finakoperp.com/finakop-plateforme-1.876.2.zip
```

Aucune migration de base n'est nécessaire : la table de la 2FA se crée d'elle-même.

## 7. Tests (simulation)
- Harnais `tests/securite_2fa_invisibilite_1876_2.php` : 20 vérifications (vecteurs RFC 6238, fenêtre, anti-rejeu, robots, `.htaccess`).
- Test 23 (invisibilité) : robots en 403, navigateur en 200, en-têtes sur les fichiers statiques, `robots.txt`, 404 par le domaine principal, portail sans oracle, version masquée, cookie `__Host-`.
- Test 24 (2FA) : enrôlement imposé à l'administrateur, QR code, codes faux et justes, 10 codes de secours, secret chiffré, anti-rejeu, code de secours à usage unique, facultatif pour les autres comptes, levée par la console.
- Test 25 (vitrine) : vitrine en 200 et ouverte aux robots, www → domaine principal en 301, `.htaccess` refusé. Avec la vitrine au-dessus de `finakop-app`, la plateforme garde sa propre CSP et ses en-têtes.
