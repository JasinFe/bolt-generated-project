# Résultats des tests

Tous les résultats ci-dessous ont été **réellement exécutés**. Un test qui n'a pas pu l'être est signalé comme tel (section 5). Le journal complet, ligne par ligne, est dans [07-TESTS-journal.txt](07-TESTS-journal.txt).

## 1. Environnement de test

Il reproduit Hostinger Business **sans** en être une copie :

| Élément | Test | Hostinger Business (cible) |
| --- | --- | --- |
| Compte | Utilisateur `fkhost` **sans droits root** ; arborescence `~/domains/finakoperp.com/public_html/finakop-app`, `~/finakop`, `~/finakop-data` | Identique |
| Serveur web | Apache 2.4.58, `.htaccess` actif | **LiteSpeed** (lit les mêmes `.htaccess`) |
| PHP web | 8.3 (module Apache) | 8.4 |
| PHP ligne de commande (cron, console) | 8.4.19 | 8.4 |
| SQLite | 3.45.1 | Version de l'hébergeur |
| Cloudflare | Simulé : requêtes émises depuis des IP des plages Cloudflare avec `CF-Connecting-IP` et l'en-tête secret | Cloudflare réel |
| Courriel | Serveur SMTP de test local (SSL, AUTH) | `smtp.hostinger.com` |
| Système | Ubuntu 24.04 | CloudLinux |

## 2. Tests de la plateforme : 212 vérifications, 0 échec

| Test | Objet | Réussies | Échecs |
| --- | --- | ---: | ---: |
| 1 | Installation propre (scripts/installer.sh, compte sans root) | 6 | 0 |
| 2 | Démarrage par index.php (sans WordPress) | 6 | 0 |
| 3 | Connexion, session, déconnexion | 10 | 0 |
| 4 | newloock.finakoperp.com charge New Loock | 3 | 0 |
| 5 | Isolation : New Loock ne voit rien de la société B | 9 | 0 |
| 6 | Deux entreprises simultanément (deux sessions, requêtes parallèles) | 4 | 0 |
| 7 | API REST par client | 8 | 0 |
| 8 | Envoi et lecture de documents (GED) | 9 | 0 |
| 8b | Téléversements dangereux | 7 | 0 |
| 9 | Courriels SMTP sans WordPress (FKC_Mailer) | 12 | 0 |
| 10 | Cron autonome (cron/worker.php, un processus par client) | 11 | 0 |
| 10b | Cron : délai maximal par client (injection de panne) | 4 | 0 |
| 11 | Licence : activation, validation, expiration, domaine, packs | 9 | 0 |
| 12 | HTTPS et relais Cloudflare | 12 | 0 |
| 13 | Sécurité : accès interdits | 28 | 0 |
| 14 | Sauvegarde puis restauration réelles | 19 | 0 |
| 14b | Reconstruction : dossier d'un client perdu, restauration en place | 2 | 0 |
| 15 | Reprise d'un site WordPress (export → import), clé = constante FKC_ENCRYPTION_KEY de wp-config.php | 10 | 0 |
| 16 | Sauvegarde et restauration de la PLATEFORME (registre des clients + configuration) | 5 | 0 |
| 17 | Règles d'identifiant et environnement | 12 | 0 |
| 18 | Recette (staging) : seconde installation isolée, mode chemin | 9 | 0 |
| 19 | Temps réel sur hébergement mutualisé (SSE désactivé par défaut) | 4 | 0 |
| 20 | IDOR et autorisations À L'INTÉRIEUR d'un client (utilisateur limité à la société A) | 13 | 0 |
| **Total** | | **212** | **0** |

Points saillants :
- **Hôte forgé** (test 13) : `evil.com`, `newloock.finakoperp.com.evil.com`, `newloock..finakoperp.com`, `-x.…`, `a_b.…`, port `:99999`, `[::1]` → refusés ; noms réservés → page neutre.
- **Isolation entre clients** (tests 5, 6, 8) :
  - les données d'un client sont physiquement absentes des fichiers de l'autre ;
  - une session ou un cookie rejoué d'un client à l'autre est refusé ;
  - un même identifiant de document donne des documents différents d'un client à l'autre ;
  - 40 requêtes parallèles réparties sur deux clients ne présentent aucun mélange.
- **IDOR et autorisations dans un client** (test 20) : un utilisateur limité à la société A n'atteint rien de la société B, ni par paramètre (`?societe=2`, `?societe_id=2`), ni par identifiant, ni en forçant la bascule. Les modules non attribués et l'administration répondent 403. Un retrait d'accès s'applique dès la requête suivante.
- **Cloudflare** (test 12) : l'IP réelle n'est prise que des plages Cloudflare ; `X-Forwarded-*` d'un inconnu est ignoré ; la garde d'origine refuse sans secret ou avec un mauvais secret. La force brute bloque l'IP **réelle** de l'attaquant, pas le relais (test 13).
- **Sauvegarde et restauration** (tests 14, 14b, 16) :
  - archives chiffrées et relues ;
  - restauration de vérification et restauration en place, l'ancien état mis de côté ;
  - reconstruction d'un client dont le dossier a disparu ;
  - restauration de la plateforme (registre + configuration) ;
  - archive altérée d'un octet ou mauvaise clé : refus, rien de modifié.
- **Reprise depuis WordPress** (test 15) : export, import, contrôle (lignes par table, SHA-256 par fichier). Un secret chiffré par l'ancien site est relu à l'identique, que la clé soit un fichier ou la constante `FKC_ENCRYPTION_KEY`. Un export sans sa clé est refusé.
- **Cron** (tests 10, 10b) : un passage, le verrou contre les doubles exécutions et, **par injection de panne**, un sous-processus gelé arrêté à son délai pendant que les autres clients passent, puis repris au passage suivant.
- **Recette** (test 18) : seconde installation isolée en mode chemin, en-têtes `staging` et `noindex`, production non affectée.
- **Téléversements** (tests 8, 8b) : `.php`, `x.php.pdf`, `.phtml`, `.phar`, SVG avec script et HTML sont refusés. Un PDF valide contenant du code PHP est accepté **comme PDF** : rangé hors du web, en 0640, servi en `application/pdf` avec `nosniff`, jamais exécuté.

## 3. Non-régression de FinaKop : suite complète

`php tools/run-tests.php` sur le code final 1.876.0 : **<<SUITE>>**. Pour comparaison, 1.875.5 : 402/402.

Les nouveaux harnais échouent tous sur 1.875.5, ce qui prouve qu'ils détectent le défaut corrigé :

| Harnais | Vérifie |
| --- | --- |
| `cle_chiffrement_noyau_1875_6` | Tâches hors requête, empreinte et refus d'une clé de chiffrement absente ou différente |
| `plateforme_chargement_packs_1876` | Plus d'erreur fatale « Cannot declare class FKC_IndMrp » (packs industrie et distribution) ; CSRF en 403 |
| `export_csv_injection_1876` | Exports CSV sans injection de formule, montants négatifs intacts |
| `plateforme_temps_reel_1876` | SSE désactivable, repli sur l'interrogation |
| `televersements_droits_1876` | Fichiers téléversés en 0640 |

### Matrice par domaine fonctionnel

