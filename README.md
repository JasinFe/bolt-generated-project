# Suivi énergie CIE

Application Laravel de suivi d'un compteur électrique prépayé (CIE, Côte d'Ivoire) :
enregistrement des codes de recharge STS de 20 chiffres, cycle de vie
(à saisir → saisi → confirmé / anomalie), relevés, consommation journalière, autonomie et alertes.

L'application **ne vend rien et ne crédite pas le compteur** : l'utilisateur tape le code sur le clavier.

Elle comprend une API JSON (`/api`, jetons Sanctum) et une interface web d'une page (`/`) qui consomme cette API.

## Démarrage

Prérequis : PHP 8.3+ et Composer. SQLite par défaut ; PostgreSQL ou MySQL en changeant `DB_*` dans `.env`.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Générez le secret des empreintes (une fois, **ne jamais le changer ensuite**) et collez-le dans `.env`, ligne `ENERGIE_CODE_PEPPER=` :

```bash
openssl rand -hex 32
```

Puis créez la base avec les données de démonstration et lancez le serveur :

```bash
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Ouvrez http://localhost:8000 — compte de démonstration : `demo@example.com` / `demo-energie`
(à ne pas créer en production : utilisez `php artisan migrate` sans `--seed`).

Tests :

```bash
php artisan test
```

## Ce que fait l'interface

- Création de compte, connexion, plusieurs compteurs par utilisateur.
- Ajout d'un compteur avec contrôle du numéro (11 ou 13 chiffres, clé de Luhn).
- Tableau de bord : crédit estimé maintenant, autonomie (fourchette), consommation moyenne, graphique sur 30 jours.
- Enregistrement d'une recharge — on peut coller le SMS entier, le code, le montant et les kWh sont repérés.
- Mode saisie : le code s'affiche en 5 groupes de 4 chiffres, puis « J'ai tapé le code » → « Confirmer le crédit ».
- Ajout de relevés (crédit ou index) avec contrôle de cohérence immédiat.
- Alertes à lire.

Le partage d'un compteur et l'association d'un capteur IoT sont disponibles par l'API (pas encore d'écran).

## Sécurité des codes

| Colonne | Rôle |
|---|---|
| `code_chiffre` | code chiffré avec `APP_KEY` (Crypt) |
| `code_empreinte` | HMAC-SHA256 avec `ENERGIE_CODE_PEPPER`, index **UNIQUE** → anti-doublon fiable |
| `code_fin` | 4 derniers chiffres pour l'affichage `**** **** **** **** 1234` |

- Le code complet n'est rendu que par `POST /api/recharges/{id}/reveler` (policy + throttle + journal `journal_acces_codes`).
- Sauvegardez `APP_KEY` et `ENERGIE_CODE_PEPPER` hors du serveur : sans eux, les codes stockés sont illisibles.
- En cas de rotation de `APP_KEY`, Laravel accepte `APP_PREVIOUS_KEYS` ; le pepper, lui, ne tourne pas.

## Rôles

| Rôle | Droits |
|---|---|
| `proprietaire` | tout, y compris modifier / supprimer le compteur, le partager, gérer le capteur |
| `gestionnaire` | enregistrer des recharges, afficher les codes, relevés |
| `occupant` | consulter, ajouter des relevés, marquer un code saisi / confirmé / refusé — sans voir le code |

## API

Toutes les routes sauf inscription, connexion et IoT demandent `Authorization: Bearer <jeton>`.

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/api/inscription`, `/api/connexion` | renvoient `{ user, token }` |
| GET / POST | `/api/moi`, `/api/deconnexion` | compte courant |
| GET, POST, PUT, DELETE | `/api/sites`, `/api/sites/{site}` | lieux de l'utilisateur |
| POST | `/api/sites/{site}/compteurs` | ajouter un compteur (Luhn, unique) |
| GET, PUT, DELETE | `/api/compteurs`, `/api/compteurs/{compteur}` | compteurs accessibles, avec le rôle |
| GET, POST, DELETE | `/api/compteurs/{compteur}/partages[/{user}]` | partager avec un utilisateur inscrit |
| POST, DELETE | `/api/compteurs/{compteur}/capteur` | associer un capteur ; le jeton n'est renvoyé qu'une fois |
| GET | `/api/compteurs/{compteur}/tableau-de-bord` | crédit estimé, autonomie, 30 jours, recharges du mois |
| GET | `/api/compteurs/{compteur}/consommations?du=&au=` | consommation par jour |
| GET / POST | `/api/compteurs/{compteur}/alertes`, `/api/alertes/{alerte}/lue` | alertes |
| GET, POST | `/api/compteurs/{compteur}/releves` | relevés ; 422 + `peut_forcer` si incohérent |
| DELETE | `/api/releves/{releve}` | corriger un relevé saisi à la main |
| GET, POST | `/api/compteurs/{compteur}/recharges` | historique (codes masqués) ; enregistrer (409 si doublon) |
| POST | `/api/recharges/{recharge}/reveler` | code en groupes de 4 |
| POST | `/api/recharges/{recharge}/saisi` | `credit_avant_kwh` facultatif |
| POST | `/api/recharges/{recharge}/confirmer` | `credit_apres_kwh` requis → crée un relevé de recalage |
| POST | `/api/recharges/{recharge}/rejeter` | `message_compteur` requis |
| POST | `/api/iot/mesures` | envoi des mesures par le capteur |

### Capteur IoT

ESP32 + photodiode sur le voyant 1600 imp/kWh. Après `POST /api/compteurs/{id}/capteur` avec
`{ "identifiant": "ESP32-A1B2" }`, le capteur envoie :

```
POST /api/iot/mesures
Authorization: Bearer <jeton du capteur>

{ "identifiant": "ESP32-A1B2",
  "mesures": [ { "mesure_le": 1794480000, "impulsions_cumul": 123456, "puissance_w": 420 } ] }
```

`mesure_le` est un timestamp Unix ou une date ISO 8601. Un envoi rejoué ne crée pas de doublon ;
une mesure datée d'avant 2020 (horloge non synchronisée) ou du futur est ignorée.

## Calcul nocturne et alertes

En production, activez le cron `* * * * * php /chemin/artisan schedule:run` et un worker
`php artisan queue:work` (sous Supervisor). Test manuel : `php artisan energie:quotidien 1 --sync`.

**Chaque nuit à 01:15**, pour chaque compteur actif :
- recalcul des 35 derniers jours de `consommations_journalieres` (un relevé saisi en retard corrige le passé) ;
- source par jour : IoT complet > relevés complets > ce qui couvre le plus ;
- alertes : crédit faible (crédit *estimé maintenant*), épuisement dans 3 jours ou moins,
  surconsommation (+30 % vs même jour de semaine, **IoT uniquement**), codes non saisis depuis 3 jours.

**Chaque heure** : capteurs IoT silencieux depuis 6 h.

Une même alerte n'est envoyée qu'une fois par 24 h et par compteur. Les consommations sont aussi
recalculées tout de suite après chaque relevé et chaque changement d'état d'une recharge.

Canaux de notification : `ENERGIE_CANAUX_ALERTES=database` (ajoutez `mail` après avoir réglé `MAIL_*`).

### Comment la consommation est calculée

| Données disponibles | Calcul |
|---|---|
| Relevés d'index | différence entre deux index |
| Relevés de crédit | crédit avant + recharges saisies entre les deux − crédit après |
| Capteur IoT | (impulsions de fin − impulsions de début) ÷ 1600 |

Chaque intervalle est réparti sur les jours au prorata du temps. Un jour n'est « complet » que
s'il est couvert de minuit à minuit par des données cohérentes ; l'autonomie n'utilise que ceux-là.
Cas qui rendent un intervalle incohérent : recharge sans kWh connus, crédit qui remonte sans recharge,
redémarrage de l'ESP32.

Crédit estimé maintenant = dernier relevé de crédit + recharges tapées depuis (pas encore confirmées)
− consommation moyenne depuis ce relevé.

## À faire ensuite

- Écrans de partage d'un compteur et d'association du capteur (l'API existe).
- Traduction française des messages de validation génériques de Laravel (`lang/fr`).
- Notifications push / SMS, réinitialisation du mot de passe par e-mail.
- Firmware ESP32 et, si besoin, passerelle MQTT → `POST /api/iot/mesures`.
