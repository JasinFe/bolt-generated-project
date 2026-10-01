# Éditions et capacité d'hébergement

Version 1.876.7 · 1er octobre 2026

Ce document explique comment les quotas des éditions ont été ramenés à des valeurs que l'hébergement de FinaKop peut réellement tenir, aujourd'hui sur l'hébergement mutualisé Hostinger et demain sur un VPS.

## 1. Ce qui n'allait pas

| Point | Avant | Problème |
| --- | --- | --- |
| Pro | Utilisateurs **illimités** | Plus que l'édition supérieure (Entreprise Standard : 300) |
| Stockage | 100 Go (Pro), 500 Go, 2 000 Go, illimité | Bien au-delà du disque de l'hébergement, sauvegardes comprises |
| Entreprise Avancée | 100 sociétés, 1 000 utilisateurs | Hors de portée d'un hébergement mutualisé et d'une base SQLite par société |
| Entreprise « Illimitée » | Tout illimité | Promesse impossible à tenir sur un serveur, quel qu'il soit |
| Capacités annoncées | Réplication, haute disponibilité | Non livrées : un seul serveur, pas de réplica |
| Capacités annoncées | Consolidation de groupe, opérations inter-sociétés, multi-pays | Aucune fonction correspondante dans le code à ce jour |

## 2. Ce qui consomme réellement le disque

- **Les bases** restent petites. Exemple réel : l'espace kophisgroup, avec 20 030 lignes, 2 bases et 43 fichiers, tient en une sauvegarde de 2,9 Mo. Une PME active ajoute de l'ordre de 50 à 150 Mo de base par an.
- **Les pièces jointes** (factures scannées, PDF, photos, documents GED) pèsent presque tout : de 200 Ko à 1 Mo chacune, sans compression possible.
- **Les sauvegardes** : chaque nuit, une copie chiffrée **complète** de chaque espace, gardée **14 jours**, sur le même disque. Les bases se compressent bien (environ 4 fois), les pièces jointes pas du tout.

Règle pratique, avec la rétention actuelle de 14 jours :

> **disque consommé ≈ 15 × pièces jointes + 4 × bases**

Le quota d'une édition est un **plafond** : il bloque les nouveaux envois de fichiers quand il est atteint, sans jamais verrouiller les données existantes. En moyenne, un client n'en utilise qu'une petite partie (souvent 5 à 20 %).

## 3. Les hébergements possibles

Ordres de grandeur des offres Hostinger : vérifiez les chiffres exacts de votre offre dans hPanel → Hébergement → Détails du plan, car ils évoluent.

| Hébergement | Disque (ordre de grandeur) | Pour quelles éditions |
| --- | --- | --- |
| Mutualisé **Business** (actuel) | environ 50 Go | Starter, Business, Pro ; quelques Entreprise Standard |
| **Cloud** (Startup → Enterprise) | environ 100 à 300 Go | Entreprise Standard, Entreprise Avancée |
| **VPS** (KVM 2 → KVM 8) | environ 100 à 400 Go | Entreprise Avancée, Entreprise Premium (VPS dédié au client) |

Gardez toujours au moins **20 % du disque libre** : les sauvegardes, les journaux et les mises à jour en ont besoin.

## 4. Les nouveaux quotas

Les gammes Core et Creative partagent les mêmes socles au même rang. La Creative ajoute ses compteurs propres.

| Édition | Sociétés | Utilisateurs | Établissements | Entrepôts | Stockage | API / mois | Hébergement |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Starter | 1 | 5 | 1 | 2 | 2 Go | — | Mutualisé |
| Business | 3 | 10 | 3 | 5 | 5 Go | — | Mutualisé |
| Pro | 5 | 25 | 10 | 15 | 10 Go | 50 000 | Mutualisé |
| Entreprise Standard | 10 | 50 | 25 | 40 | 25 Go | 150 000 | Mutualisé ou Cloud |
| Entreprise Avancée | 25 | 150 | 60 | 100 | 50 Go | 500 000 | Cloud ou VPS |
| Entreprise Premium | 50 | 300 | 150 | 300 | 100 Go | 2 000 000 | VPS dédié |

Creative Suite, compteurs propres :

| Édition | Labels / studios | Artistes | Projets | Œuvres | Contrats | Royalties / mois | Packs complémentaires |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Creative Starter | 1 | 5 | 20 | 100 | 20 | 1 000 | 0 |
| Creative Business | 5 | 50 | 100 | 500 | 500 | 20 000 | 2 |
| Creative Pro | 20 | 200 | 500 | 5 000 | 2 000 | 200 000 | 4 |
| Creative Enterprise Standard | 50 | 1 000 | 2 000 | 20 000 | 5 000 | 500 000 | 8 |
| Creative Enterprise Avancée | 150 | 5 000 | 10 000 | 100 000 | 20 000 | 2 000 000 | tous |
| Creative Enterprise Premium | 300 | 20 000 | 50 000 | 500 000 | 100 000 | 5 000 000 | tous |

Chaque colonne progresse d'une édition à la suivante : plus aucune édition ne donne plus que celle du dessus.

**Utilisateurs** : il s'agit de comptes nommés, pas de connexions simultanées. Sur l'hébergement mutualisé, comptez 20 à 30 personnes actives en même temps par espace. C'est une des raisons pour lesquelles les niveaux Avancée et Premium passent sur un serveur dédié.

**« Illimitée » devient « Premium »** : seul le libellé change. La clé technique (`enterprise_illimitee`, `creative_enterprise_illimitee`) reste la même, donc les licences déjà émises restent valides.

## 5. Ce que cela change pour les licences

- Les limites sont **inscrites dans chaque jeton de licence** au moment de son émission. Une licence déjà installée garde ses limites jusqu'à son renouvellement.
- Les **nouvelles** licences doivent porter les nouveaux quotas. Si votre outil LICENCES a sa propre table de limites, mettez-la à jour avec le tableau ci-dessus.
- Abaisser un quota ne bloque jamais l'existant : on ne peut simplement plus créer de société, d'utilisateur ou envoyer de fichier au-delà.

## 6. Capacité à surveiller sur le mutualisé

Avec la règle du §2 et environ 50 Go de disque (dont 10 Go de réserve), il reste à peu près 40 Go pour les données des clients et leurs sauvegardes. En pratique, cela représente environ **2,5 Go de pièces jointes au total, tous clients confondus**, avec 14 jours de sauvegardes complètes.

Recommandations, par ordre d'effet :

1. **Copier les sauvegardes hors du serveur et réduire la rétention locale** à 7 jours (`fk config:set sauvegarde.retention_jours 7`). La capacité utile double à peu près.
2. **Dédupliquer les pièces jointes dans les sauvegardes** : une pièce jointe ne change jamais, elle n'a pas besoin d'être copiée 14 fois. C'est une évolution à prévoir ; elle ramènerait le disque consommé à environ 2 fois les données.
3. **Passer au Cloud ou au VPS** dès le premier client Entreprise Avancée, ou quand le disque dépasse 60 % d'occupation.
