# Cloudflare Free et DNS : réglages exacts

Tout ce qui suit est disponible dans l'offre **gratuite**. Les limites de l'offre Free ont été vérifiées le 01/10/2026 :
- **5 règles personnalisées**, sans expressions régulières ;
- **1 règle de limitation de débit**, période fixe de 10 s, action Bloquer pendant 10 s.

Elles peuvent évoluer : en cas de doute, la page *Plans* de votre tableau de bord Cloudflare fait foi.

**Sans Cloudflare**, FinaKop fonctionne aussi : `cloudflare.actif = false`, certificats Hostinger. Les protections applicatives (anti-force brute par IP, CSRF, en-têtes, isolation) restent actives. Vous perdez le filtrage DDoS et WAF en amont.

## 1. Mettre le domaine sur Cloudflare

1. Cloudflare → *Add a site* → `finakoperp.com` → offre **Free**.
2. Cloudflare importe les enregistrements existants. **Vérifiez-les un à un** avec le tableau de la section 2, en particulier les enregistrements de courriel.
3. Chez le registraire (hPanel → Domaines → `finakoperp.com` → *DNS / Serveurs de noms*), remplacez les serveurs de noms par les deux noms donnés par Cloudflare.
4. Attendez le statut *Active* chez Cloudflare (de quelques minutes à 24 h).

## 2. Enregistrements DNS

Remplacez `IP_HOSTINGER` par l'IP de **votre** site, lue dans hPanel. Ne recopiez pas une IP d'exemple.

| Type | Nom | Contenu | Proxy | Rôle |
| --- | --- | --- | --- | --- |
| A | `@` | `IP_HOSTINGER` | **Proxifié** (orange) | Site institutionnel |
| CNAME | `www` | `finakoperp.com` | **Proxifié** | Site institutionnel |
| A | `app` | `IP_HOSTINGER` | **Proxifié** | Portail FinaKop |
| A | `*` | `IP_HOSTINGER` | **Proxifié** | Tous les clients (`newloock`, …) |
| MX | `@` | serveurs MX donnés par Hostinger (`mx1.hostinger.com`, `mx2.hostinger.com` : à vérifier dans hPanel → E-mails) | DNS seul | Réception des courriels |
| TXT | `@` | SPF donné par hPanel (de la forme `v=spf1 include:_spf.mail.hostinger.com ~all`) | DNS seul | Autorise Hostinger à envoyer |
| TXT / CNAME | sélecteur DKIM donné par hPanel | valeur donnée par hPanel | DNS seul | Signature DKIM |
| TXT | `_dmarc` | `v=DMARC1; p=quarantine; rua=mailto:admin@finakoperp.com` | DNS seul | Politique DMARC (commencez par `p=none` une semaine) |
| CNAME | `autodiscover`, `autoconfig` | selon hPanel | DNS seul | Configuration des clients de messagerie |

Remarques :
- **Joker `*`** : il évite de toucher au DNS à chaque client. Côté Hostinger, chaque sous-domaine doit **quand même** être déclaré dans hPanel (doc 04 §5), sinon LiteSpeed ne sait pas quel site servir.
- Les noms réservés (`api`, `admin`, `status`…) sont couverts par le joker mais **ne servent rien** : FinaKop répond par une page neutre. Pour les servir ailleurs plus tard, créez un enregistrement explicite, qui l'emporte sur le joker.
- Ne proxifiez **jamais** les enregistrements de courriel : Cloudflare ne relaie que le web.

## 3. SSL/TLS

| Réglage (menu SSL/TLS) | Valeur | Pourquoi |
| --- | --- | --- |
| Mode de chiffrement | **Full (strict)** | Chiffré de bout en bout, certificat d'origine vérifié. Les certificats gratuits Hostinger (un par sous-domaine) sont valides. « Flexible » est interdit : il servirait l'origine en HTTP clair |
| Edge Certificates → Always Use HTTPS | On | — |
| Minimum TLS Version | 1.2 | — |
| TLS 1.3 | On | — |
| Automatic HTTPS Rewrites | On | — |
| HSTS (Cloudflare) | **Laisser désactivé** au début | FinaKop envoie déjà `Strict-Transport-Security` sur ses pages. L'activer au niveau du domaine avec `includeSubDomains` engage **tous** les sous-domaines, sans retour arrière rapide |
| Universal SSL | Actif | Couvre `finakoperp.com` et `*.finakoperp.com` (un seul niveau) |

Le mode *Full (strict)* exige un certificat valide sur l'origine pour **chaque** sous-domaine : installez le SSL gratuit Hostinger pour chacun (doc 04 §5).

## 4. Protéger l'origine : en-tête secret

**Limite réelle.** Sur un hébergement mutualisé, impossible de restreindre l'origine aux seules IP de Cloudflare : pas de pare-feu, pas d'accès à la configuration du serveur. *Authenticated Origin Pulls* existe en Free, mais Hostinger ne permet pas d'exiger le certificat client. **Meilleure protection réaliste** : Cloudflare ajoute un en-tête secret à chaque requête, et FinaKop refuse (403) toute requête qui ne le porte pas.

1. Générez un secret (en SSH) : `php -r 'echo bin2hex(random_bytes(24)),"\n";'`
2. Cloudflare → Rules → **Transform Rules** → *Modify Request Header* → Create rule :
   - nom : `FinaKop origine` ;
   - quand : *All incoming requests* ;
   - action : **Set static** → en-tête `X-FinaKop-Origine` = le secret.
3. **Ensuite seulement**, dans `config.php` :

```bash
php ~/finakop/current/bin/finakop config:set cloudflare.secret_origine '<secret>'
```

4. Vérifiez que l'accès direct à l'origine, sans Cloudflare, est refusé :

```bash
curl -sk -o /dev/null -w '%{http_code}\n' --resolve newloock.finakoperp.com:443:IP_HOSTINGER https://newloock.finakoperp.com/login   # attendu : 403
curl -s  -o /dev/null -w '%{http_code}\n' https://newloock.finakoperp.com/login                                                  # attendu : 200
```

Ce que l'en-tête protège, et ce qu'il ne protège pas :
- les requêtes refusées : pages, API, connexion. Contourner Cloudflare ne permet donc plus d'échapper au WAF ni d'usurper l'IP ;
- restent accessibles en direct : les fichiers statiques publics (`/_fkc/…`) et `/.well-known/`, servis sans PHP. Ils ne contiennent aucune donnée ;
- le secret ne doit jamais apparaître dans une URL. Changez-le (règle Cloudflare d'abord, puis `config:set`) si vous pensez qu'il a fuité.

### IP réelle des visiteurs

FinaKop lit `CF-Connecting-IP` **uniquement** quand la connexion vient d'une plage IP officielle de Cloudflare (liste intégrée). Il ignore `X-Forwarded-For`. Un visiteur qui forge ces en-têtes en accès direct n'obtient rien : c'est testé (test 12). Mise à jour des plages, une fois par trimestre :

```bash
php ~/finakop/current/bin/finakop cloudflare:plages
```

## 5. Cache : jamais de page privée en cache

FinaKop envoie `Cache-Control: no-store` sur toutes ses pages et téléchargements (vérifié sur `/login`, le tableau de bord et `/ged/{id}/telecharger`). Cloudflare ne les met donc pas en cache. On ajoute une **règle explicite** par sécurité. Caching → **Cache Rules**, dans cet ordre :

| # | Nom | Si | Alors |
| --- | --- | --- | --- |
| 1 | `FinaKop statique` | `starts_with(http.request.uri.path, "/_fkc/")` | Eligible for cache ; Edge TTL : *Use cache-control header if present* (30 jours, CSS et JS versionnés par `?v=`) |
| 2 | `FinaKop dynamique : jamais en cache` | `not starts_with(http.request.uri.path, "/_fkc/")` | **Bypass cache** |

Ainsi `/login`, `/logout`, le tableau de bord, `/api/…`, les pages en AJAX, les téléchargements et tout ce qui est authentifié ne sont **jamais** servis depuis le cache, quel que soit le client. Après une mise à jour : Caching → *Purge Everything* (images non versionnées).

Autres réglages :
- Speed → **Rocket Loader : Off**. Il réécrit les scripts et casse les formulaires protégés par CSRF.
- **Auto Minify** : sans objet (retiré par Cloudflare).
- **Always Online** : Off. Il pourrait présenter une ancienne page.

## 6. Sécurité (WAF Free)

| Réglage | Valeur |
| --- | --- |
| Security → WAF → *Free Managed Ruleset* | Actif (par défaut) |
| Security Level | Medium |
| **Bot Fight Mode** | **Off**. En Free, il ne peut pas être exempté par chemin. Il bloquerait les appels d'API, les webhooks de paiement, le terminal et le scanner mobile |
| Browser Integrity Check | On |

Règles personnalisées (1 ou 2 sur les 5 autorisées) :

| # | Nom | Expression | Action |
| --- | --- | --- | --- |
| 1 | `Machines autorisées` | `starts_with(http.request.uri.path, "/api/") or starts_with(http.request.uri.path, "/webhook") or starts_with(http.request.uri.path, "/recu/") or starts_with(http.request.uri.path, "/verifier") or starts_with(http.request.uri.path, "/portail-scolarite") or starts_with(http.request.uri.path, "/scan") or starts_with(http.request.uri.path, "/terminal")` | **Skip** → *Security Level* et *Browser Integrity Check*. Ces adresses sont appelées par des programmes, des terminaux ou des QR codes : un défi navigateur les casserait. Elles restent protégées par jeton ou signature et limitées par FinaKop |
| 2 | `Sondes WordPress` (facultatif, si le site institutionnel n'est pas un WordPress) | `http.request.uri.path contains "/wp-login.php" or http.request.uri.path contains "/xmlrpc.php" or http.request.uri.path contains "/wp-admin"` | Block |

Règle 3, **`Plateforme invisible`** (1.876.2) : bloque les robots sur les sous-domaines de la plateforme, sans toucher au site vitrine. Expression et explications : **10-SECURITE-ET-VITRINE.md §4**. Laissez *Block AI bots* désactivé : il agirait aussi sur la vitrine.

Une autre règle sur les noms d'hôte est inutile : Cloudflare ne transmet que les noms de la zone, et FinaKop refuse lui-même tout hôte qui n'est pas `finakoperp.com` ou l'un de ses sous-domaines (test 13).

### Limitation de débit (1 règle en Free)

Security → WAF → *Rate limiting rules* :
- **Si** : `http.request.uri.path eq "/login" and http.request.method eq "POST"` ;
- **Seuil** : 20 requêtes par 10 secondes, par IP ;
- **Action** : Block, 10 secondes (imposé en Free).

C'est un **filet grossier** contre les rafales. La vraie protection contre la force brute est dans FinaKop : blocage progressif par IP **réelle** et par compte, testé (test 13). Une limitation plus fine (fenêtres d'une minute, défi, comptage par compte) demande l'offre Pro : évolution possible, non requise.

## 7. Récapitulatif des options payantes non utilisées

| Fonction | Offre | Alternative retenue |
| --- | --- | --- |
| Domaines personnalisés des clients (`erp.newloock.com`) | Cloudflare for SaaS | Prévu dans le registre (`tenant:domaine`) ; à activer plus tard, plutôt sur VPS |
| Limitation de débit fine | Pro et plus | Limitation applicative de FinaKop |
| Bot Management | Enterprise | Désactivé ; jetons, signatures et limites applicatives |
| WAF géré complet (OWASP) | Pro | Free Managed Ruleset + protections applicatives |

## 8. Contrôle final

```bash
curl -sI https://newloock.finakoperp.com/login | grep -iE 'HTTP/|cf-cache-status|cache-control|server'
# attendu : HTTP/2 200, server: cloudflare, cf-cache-status: BYPASS (ou DYNAMIC), cache-control: no-store…
curl -sI https://newloock.finakoperp.com/_fkc/css/app.css | grep -i cf-cache-status   # HIT après le 2e appel
```

Côté FinaKop, `plateforme:verifier` doit afficher `[OK] Cloudflare : garde de l'origine (secret) configurée`.
