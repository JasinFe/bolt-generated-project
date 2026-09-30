# FinaKop ERP Core : plan de migration vers un VPS, en application autonome

> Version analysée : **FinaKop ERP Core 1.875.5**, avec le **Toolkit Licences 1.174.0**.
> Objectif : sortir FinaKop de WordPress et le servir seul sur son sous-domaine, depuis un VPS.
> Conditions : aucune perte de données, la délivrance des licences continue, et les performances sont au moins aussi bonnes.

---

## 0. En bref

| Question | Réponse | Preuve |
|---|---|---|
| FinaKop peut-il fonctionner sans WordPress ? | **Oui.** WordPress ne sert qu'à aiguiller les requêtes. L'authentification, les bases, le routeur et les licences sont propres à FinaKop. | L'app autonome de ce kit a été démarrée sans WordPress : connexion, changement de mot de passe imposé, Cockpit, Licence, Sociétés, Processus et fichiers statiques répondent tous correctement. |
| Sur le même sous-domaine ? | **Oui.** On garde l'hôte actuel, par exemple `finakopcore.kophisgroup.com`. | La licence est liée à l'**hôte** de la requête (`FKC_License::hostMatchesDomain`), pas à WordPress. |
| Les licences continuent-elles d'être délivrées ? | **Oui, sans rien changer.** | Un jeton émis par `make_license.php` a été installé dans l'app autonome : statut **« active »**. La clé privée du toolkit et la clé publique du plugin forment bien une paire. |
| Sans perte de données ? | **Oui**, grâce à un export contrôlé : copie cohérente des bases, nombre de lignes de chaque table et empreinte SHA-256 de chaque fichier. | `scripts/migration-donnees.php` a été testé : il détecte bien un fichier modifié d'un seul octet. |
| Les performances ? | **Meilleures.** WordPress ne se charge plus à chaque requête, OPcache est réglé pour le code, les tâches planifiées passent par un vrai cron et la base SQLite est sur NVMe local. | — |

**Le seul vrai risque est la clé de chiffrement.** Il faut reprendre à l'identique soit la constante `FKC_ENCRYPTION_KEY` de `wp-config.php`, soit le fichier `.fkc-secret.key` du dossier de données. Si elle manque, FinaKop génère **en silence** une clé neuve, et tous les secrets déjà chiffrés deviennent illisibles. Le kit contrôle ce point à trois étapes : à l'export, à l'import et dans `finakop verifier`.

---

## 1. Ce que WordPress fait aujourd'hui, et ce qui le remplace

| Rôle de WordPress | Remplacement dans ce kit |
|---|---|
| Intercepter la requête et définir `FKC_ROOT`, `FKC_DATA_DIR`, `FKC_BASE_URL`… (`fkc_intercept_request`) | `autonome/public/index.php` et `autonome/lib/amorcage.php`, qui définissent exactement les mêmes constantes |
| Stocker les réglages (`get_option`, `wp-config.php`) | `/etc/finakop/config.php` |
| Tâches planifiées (WP-Cron, qui ne part qu'aux visites) | `/etc/cron.d/finakop` et `bin/finakop bpe` / `balayage` |
| Courriels (`wp_mail`) | `mail()` → **msmtp** → relais SMTP (`lib/compat-wp.php`) |
| Requêtes HTTP (`wp_remote_*`) | Rien à faire : FinaKop a déjà une solution de secours native |
| Page d'administration (réémission des mots de passe compromis) | `finakop comptes-compromis` et `finakop reemettre-mdp LOGIN` |
| Commande WP-CLI `wp finakop bpe` | `finakop bpe` |
| Fuseau horaire : WordPress impose l'**UTC** à PHP | `date.timezone = UTC`, pour que les dates calculées ne bougent pas |

**Le dossier `app/` n'est pas modifié.** Le même ZIP reste donc vendable comme extension WordPress. Vous gagnez un second mode de distribution sans maintenir deux produits.

---

## 2. Architecture cible

```
                 Internet (HTTPS)
                        │
         ┌──────────────┴───────────────┐
         │ nginx  (TLS Let's Encrypt,    │
         │         HSTS, gzip, limiteurs)│
         └──────┬───────────────┬───────┘
     /_fkc/*    │               │  tout le reste
 (fichiers statiques)            ▼
  alias app/assets     PHP-FPM 8.4, pool « finakop »
                       (OPcache, open_basedir)
                                │
             /srv/finakop/autonome/public/index.php
                                │
             /srv/finakop/current/app/   ← ZIP de l'extension, lecture seule
                                │
             /var/lib/finakop/data/      ← bases SQLite, pièces, clé (0750)

 cron : bpe toutes les 5 min · balayage chaque heure · sauvegarde 02 h 17 · vérification le lundi
 sauvegarde : VACUUM INTO → /var/backups (7 j) → restic chiffré → stockage S3 hors site
 (option) relais Connect : Node 18, systemd, wss://relais.<domaine>
```

**Arborescence et droits**

| Chemin | Propriétaire | Droits | Contenu |
|---|---|---|---|
| `/srv/finakop/releases/<version>/` | root | 0755 / 0644 | Code de chaque version (le ZIP) |
| `/srv/finakop/current` | root | lien | Version en service (bascule atomique) |
| `/srv/finakop/autonome/` | root | 0755 | Point d'entrée web et commandes `bin/finakop` |
| `/etc/finakop/config.php` | root:finakop | 0640 | Réglages et clé éventuelle |
| `/var/lib/finakop/data/` | finakop | 0750 / 0640 | **Données**, hors racine web |
| `/var/lib/finakop/sessions/` | finakop | 0750 | Sessions PHP |
| `/var/backups/finakop/` | root | 0700 | Copies locales, 7 jours |

Le pool PHP tourne sous l'utilisateur `finakop`. Il **ne peut pas modifier le code**, et nginx ne peut pas lire les données.

---

## 3. Choix de l'hébergeur

Prix relevés en septembre 2026, **à vérifier au moment de la commande** : les grands hébergeurs ont augmenté leurs tarifs en 2026, sous l'effet du coût de la RAM et du NVMe.

| Offre | vCPU | RAM | Disque | Prix / mois (HT) | Avis |
|---|---|---|---|---|---|
| **OVHcloud VPS-1** | 4 | 8 Go | 75 Go SSD | ~5,50 € | ✅ **Meilleur rapport qualité-prix pour démarrer.** Entreprise française, anti-DDoS inclus, trafic illimité, support en français, facturation en €. |
| **OVHcloud VPS-2** | 6 | 12 Go | 100 Go NVMe | ~8,50 € | ⭐ **Recommandé en production** dès ~15 utilisateurs simultanés ou plusieurs cabinets. Le NVMe profite directement à SQLite. |
| OVHcloud VPS-3 | 8 | 24 Go | 200 Go NVMe | ~17 € | Seulement si vous hébergez de nombreux clients sur un même serveur. |
| Hetzner CX33 | 4 | 8 Go | 80 Go | ~8,50–9 € | Très bonne qualité, mais plus cher qu'OVH depuis la hausse de juin 2026. Vérification d'identité parfois exigée. |
| Contabo Cloud VPS 10 | 4 | 8 Go | 75 Go NVMe | ~4,50 € | Le moins cher, mais des performances disque et CPU plus irrégulières (serveurs survendus) et un support lent. Acceptable pour un serveur d'**essai**, pas pour la comptabilité de production. |

**Recommandation**

1. **OVHcloud VPS-1** pour la migration et le démarrage. On passe à **VPS-2** dans l'espace client, sans réinstallation, dès que la charge le demande. Le script ajuste alors tout seul le nombre de processus PHP à la RAM.
2. **Centre de données en France** (Gravelines, Roubaix ou Strasbourg). Depuis l'Afrique de l'Ouest, la latence reste d'environ 100 ms, confortable pour un ERP web. Si vos clients sont surtout en Côte d'Ivoire, c'est le meilleur compromis disponible chez OVH.
3. **Système : Debian 13.** PHP 8.4 y est fourni en natif. Ubuntu 24.04 LTS fonctionne aussi : le script ajoute alors le dépôt PHP.
4. Options OVH utiles : la **sauvegarde automatisée** du VPS (quelques € par mois), en filet de sécurité *en plus* de restic, et un **snapshot** manuel juste avant chaque étape à risque.

**Budget mensuel type**

| Poste | Coût |
|---|---|
| VPS-1 (ou VPS-2) | ~5,50 € (~8,50 €) HT |
| Stockage hors site (OVH Object Storage, ~8 €/To, ou Backblaze B2) | < 1 € pour quelques Go de données comptables |
| Sauvegarde automatisée OVH (optionnelle) | ~2–4 € |
| Relais SMTP (Brevo : 300 courriels/jour gratuits) | 0 € |
| Certificats TLS (Let's Encrypt) | 0 € |
| **Total** | **≈ 6 à 13 € HT / mois** |

---

## 4. Recommandations clés

### Sécurité
1. **Le toolkit Licences ne va jamais sur le VPS.** `license_private.pem` permet de fabriquer des licences valides. Gardez-le sur un poste hors ligne, avec une copie chiffrée sur une clé USB rangée à part. Sur le VPS ne vont que le dossier `depot/` (déjà signé) et les clés **publiques** fournies dans le ZIP. `deployer-version.sh` **refuse** un ZIP qui contiendrait une clé privée.
   - L'archive LICENCES a circulé dans cette conversation. Si elle a aussi transité par d'autres services, envisagez de changer la paire de clés.
   - Attention : changer `license_private.pem` invalide **tous** les jetons déjà émis. C'est une opération à planifier.
2. Connexion SSH par clé uniquement. Le script coupe les mots de passe **seulement si** une clé est déjà installée, pour éviter de vous enfermer dehors.
3. Pare-feu (UFW) : seuls SSH, 80 et 443 sont ouverts. fail2ban protège SSH. Les mises à jour de sécurité s'installent automatiquement.
4. Les données sont hors racine web. Le défaut « bases téléchargeables sous nginx », signalé par le code lui-même, disparaît.

### Données
5. **Ne changez pas d'hôte.** Même sous-domaine = même licence, sans réémission.
6. **Règle 3-2-1 des sauvegardes :**
   - 3 copies : les données en service, la copie locale de 7 jours, la copie restic hors site (14 jours, 8 semaines, 24 mois) ;
   - 2 supports ;
   - 1 copie hors site, chiffrée.
   Conservez la phrase `RESTIC_PASSWORD` **hors du VPS** : sans elle, aucune restauration n'est possible.
7. **Testez une restauration** une fois par trimestre, sur un VPS jetable à ~5 € pour une heure. Une sauvegarde jamais restaurée n'est pas une sauvegarde.

### Exploitation
8. Surveillance gratuite :
   - UptimeRobot ou Better Stack : une sonde HTTPS sur `/login` toutes les 5 minutes ;
   - alerte e-mail d'OVH sur le VPS ;
   - lecture hebdomadaire de `/var/log/finakop/verifier.log`.
9. **Republiez le dépôt de licences chaque mois**, depuis votre poste hors ligne, avec `php publier_depot.php registre.json`. Au-delà de 30 jours, les verdicts expirent et la révocation cesse de fonctionner. Mettez un rappel dans l'agenda.
10. Mises à jour de FinaKop : `deployer-version.sh nouvelle-version.zip`. Le script fait une sauvegarde automatique juste avant, puis bascule sans coupure. `deployer-version.sh --retour` revient à la version précédente.

### Pour l'éditeur (améliorations du produit, non bloquantes)
11. Ajouter dans `app/index.php`, juste avant `$fkc_req_path = fkc_rel_path();`, la ligne `if ( defined( 'FKC_CLI_NOYAU' ) ) { return; }`. Elle officialise le chargement du noyau en ligne de commande. `bin/finakop` fonctionne déjà avec ou sans elle.
12. Dans `FKC_Crypto::key()`, **refuser de générer une clé neuve** si le registre contient déjà des valeurs chiffrées (`FKC1.` / `FKC2.`). Cela transformerait une perte silencieuse en erreur visible.
13. Livrer `autonome/` dans le ZIP officiel : un seul produit, deux modes d'installation.
14. Défaut observé à la lecture du code (1.875.5) : sous WordPress, les crochets WP-Cron `fkc_bpe_worker` et `fkc_balayage_temporisation`, ainsi que la commande `wp finakop bpe`, testent `class_exists( 'FKC_BPEWorker' / 'FKC_Balayeur' )`. Or ces classes ne sont chargées que par `app/index.php`, c'est-à-dire seulement quand l'application sert une page. Dans `wp-cron.php` ou WP-CLI, le test échoue et rien n'est traité : la file différée (dont la consultation du dépôt de licences) ne se vide qu'au bouton de l'écran Processus. **Le mode autonome corrige ce défaut**, puisque `bin/finakop` charge le noyau. Côté extension, la même extraction du noyau corrigerait WP-Cron.

---

## 5. Déroulé de la migration

Durée totale : **environ 1 semaine calendaire**, dont **1 à 2 h d'interruption** le jour J.

### Phase 0 : préparation (J-7)

- [ ] **Relever sur l'installation actuelle :**
  - la version de FinaKop ;
  - l'emplacement du dossier de données (Réglages → FinaKop ERP Core ; par défaut `wp-content/uploads/finakop-erp-core-data/`) ;
  - sa taille ;
  - la présence de `FKC_ENCRYPTION_KEY` dans `wp-config.php`.
- [ ] **Noter les chiffres de référence**, pour la recette, pour chaque société :
  - le total de la balance générale ;
  - le nombre de factures ;
  - le numéro de la dernière écriture ;
  - le solde d'un compte client choisi.
- [ ] **Baisser la durée de cache DNS (TTL)** de l'enregistrement du sous-domaine à **300 s**. À faire au moins 48 h avant le jour J.
- [ ] Commander le VPS (OVHcloud VPS-1, Debian 13, centre de données en France) et y installer votre clé SSH publique.
- [ ] Créer le relais SMTP (Brevo ou autre) et publier SPF, DKIM et DMARC pour le domaine expéditeur.
- [ ] Créer le compartiment S3 de sauvegarde (OVH Object Storage, région GRA ou SBG) et une clé d'accès dédiée.
- [ ] Vérifier que l'ancien hébergeur donne un accès **SSH** (idéal) ou au moins SFTP.

### Phase 1 : installation du VPS (J-5)

```bash
# Sur votre poste
scp -r finakop-migration/ finakop-erp-core-1_875_5.zip root@IP_DU_VPS:/root/

# Sur le VPS. Le DNS pointe encore vers l'ancien site : on commence par le sous-domaine d'essai.
cd /root/finakop-migration
DOMAINE=essai.finakopcore.kophisgroup.com EMAIL=admin@kophisgroup.com ./scripts/installer-vps.sh
/srv/finakop/scripts/deployer-version.sh /root/finakop-erp-core-1_875_5.zip
```

- [ ] Créer d'abord l'enregistrement DNS `essai.finakopcore…` → IP du VPS, pour obtenir le certificat.
  - **La licence y est valide** : `essai.finakopcore.kophisgroup.com` est un vrai sous-domaine de l'hôte licencié. Le contrôle l'accepte, en mode historique comme en mode strict.
- [ ] Compléter `/etc/finakop/config.php` : `mail_from`, `admin_email`, et `constantes` si vous utilisiez `FKC_LICENSE_DEPOT`.
- [ ] Compléter `/etc/msmtprc`, puis tester : `echo test | msmtp -a default vous@domaine`.
- [ ] Créer `/etc/finakop/sauvegarde.env` (droits 0600), puis `restic init`.

### Phase 2 : répétition à blanc (J-3)

L'installation actuelle **reste en service**. On travaille sur une copie.

```bash
# Sur l'ANCIEN hébergement (SSH)
php migration-donnees.php exporter /chemin/finakop-erp-core-data ~/export-finakop /chemin/wp-config.php
tar czf ~/export-finakop.tgz -C ~ export-finakop

# Sur le VPS
scp ancien:export-finakop.tgz /root/ && tar xzf /root/export-finakop.tgz -C /root/
FORCER=1 /srv/finakop/scripts/importer-donnees.sh /root/export-finakop
```

- [ ] Reporter dans `config.php` ce que liste `A-REPORTER-DANS-config.php.txt`. Surtout `encryption_key`, **au caractère près**, si la constante existait.
- [ ] `runuser -u finakop -- finakop verifier` : toutes les lignes doivent être `[OK]`.
- [ ] Faire la **recette complète** (section 6) sur `https://essai.finakopcore…`.
- [ ] Chronométrer l'export, le transfert et l'import : c'est la durée d'interruption du jour J.
- [ ] Tester `sauvegarder.sh`, puis une restauration (section 7).

*Sans accès SSH à l'ancien hébergement :*
1. Déposez `maintenance-wp.php` (phase 3).
2. Attendez 2 minutes.
3. Téléchargez **tout** le dossier de données par SFTP ou par le gestionnaire de fichiers, y compris les fichiers `-wal` et `-shm`.
4. Sur le VPS, lancez `php migration-donnees.php exporter <copie> /root/export-finakop`. L'empreinte est alors prise sur le VPS, et `VACUUM INTO` y intègre le contenu du WAL.

### Phase 3 : bascule (jour J, créneau creux, par exemple samedi à 20 h)

| Heure | Action |
|---|---|
| H-0:00 | Prévenir les utilisateurs. Faire un **snapshot** du VPS dans l'espace OVH. |
| H+0:00 | Déposer `scripts/maintenance-wp.php` dans `wp-content/mu-plugins/` de l'ancien site. FinaKop répond alors 503, le reste du site WordPress continue. |
| H+0:05 | Export final (commande de la phase 2), puis transfert vers le VPS. |
| H+0:20 | Sur le VPS : `FORCER=1 importer-donnees.sh /root/export-finakop`. Le script affiche `Données identiques à la source.`. |
| H+0:30 | DNS : l'enregistrement `A` (et `AAAA`) de `finakopcore…` pointe désormais sur l'IP du VPS. |
| H+0:35 | Une fois le DNS propagé (`dig +short finakopcore…`) : `DOMAINE=finakopcore.kophisgroup.com EMAIL=… ./scripts/installer-vps.sh`. Le certificat est obtenu et `base_url` passe au vrai domaine. |
| H+0:45 | Recette express (section 6, points ★). `finakop licence` affiche **active**. |
| H+1:00 | Ouverture et annonce aux utilisateurs. |

**Retour arrière :**
- **Avant l'ouverture**, c'est simple et sans perte : remettre l'ancien DNS et retirer `maintenance-wp.php`. L'ancien site n'a reçu aucune écriture.
- **Après l'ouverture**, les nouvelles écritures sont sur le VPS. On fait le chemin inverse avec le même outil : `exporter` sur le VPS, puis copie dans le dossier de données WordPress.

### Phase 4 : après la bascule (J+1 à J+30)

- [ ] J+1 : lire `/var/log/finakop/cron.log`. Les passages `bpe` doivent avoir lieu toutes les 5 minutes. Vérifier que la sauvegarde de 02 h 17 est bien arrivée dans restic (`restic snapshots`).
- [ ] J+1 : supprimer du VPS `/root/export-finakop*` et `A-REPORTER-DANS-config.php.txt` (avec `shred -u`).
- [ ] J+7 : garder l'ancien WordPress **intact, en maintenance**.
- [ ] J+30 : désactiver l'extension sur l'ancien site. Archiver une dernière copie chiffrée de l'ancien dossier de données, puis l'effacer de l'ancien hébergement.
- [ ] Remettre le TTL DNS à 3600 s.
- [ ] Supprimer l'enregistrement DNS `essai.…`.

---

## 6. Recette : ce qu'on vérifie avant d'ouvrir

★ = à refaire le jour J (recette express).

- [ ] ★ **Connexion** d'un administrateur et d'un utilisateur simple, avec les mots de passe **existants** (ils sont dans le registre maître).
- [ ] ★ **Licence** : écran Licence, et `finakop licence` affiche « active », avec le bon palier et les bons packs.
- [ ] ★ **Chiffres de référence** (phase 0), identiques pour chaque société :
  - total de la balance générale ;
  - nombre de factures ;
  - dernier numéro d'écriture ;
  - solde du compte client témoin.
- [ ] ★ **Pièce jointe** : ouvrir une pièce justificative ancienne. Cela prouve que les fichiers ont été copiés et que la clé est bonne.
- [ ] **Secrets chiffrés** : ouvrir un réglage qui en contient (clé d'API, configuration Mobile Money, SMTP ou FNE). La valeur doit être lisible, et non vide ou corrompue.
- [ ] **Saisie** : créer une écriture de test dans un journal, vérifier sa numérotation, puis l'annuler.
- [ ] **Courriel** : envoyer une relance ou un document par e-mail, et vérifier la réception.
- [ ] **File différée** : Paramètres → Processus. Le « prochain passage » s'affiche et la file se vide en moins de 5 minutes.
- [ ] **Clés d'API**, si elles sont utilisées : un appel `curl -H "Authorization: …" https://…/api/…` répond.
- [ ] **Hors ligne / PWA** : `https://…/sw.js` et `/manifest.webmanifest` répondent 200.
- [ ] **Connect**, si le service est vendu : envoi d'un message et réception en temps réel, ce qui suppose l'URL du relais `wss://relais.…` et le secret configurés.
- [ ] **Sécurité** : `curl -I https://…/finakopcore-master.db` et `https://…/.fkc-secret.key` répondent **404**. Le score de [SSL Labs](https://www.ssllabs.com/ssltest/) est **A** ou mieux.

---

## 7. Exploitation au quotidien

| Besoin | Commande (sur le VPS, en root) |
|---|---|
| État général | `runuser -u finakop -- finakop verifier` |
| État de la licence | `runuser -u finakop -- finakop licence` |
| Vider la file différée maintenant | `runuser -u finakop -- finakop bpe` |
| Publier les écritures temporisées | `runuser -u finakop -- finakop balayage --forcer` |
| Compte bloqué (mot de passe publié) | `runuser -u finakop -- finakop comptes-compromis` puis `… finakop reemettre-mdp LOGIN` |
| Mettre à jour FinaKop | `/srv/finakop/scripts/deployer-version.sh finakop-erp-core-X.zip` |
| Revenir à la version précédente | `/srv/finakop/scripts/deployer-version.sh --retour` |
| Sauvegarder maintenant | `/srv/finakop/scripts/sauvegarder.sh` |
| Journaux | `/var/log/finakop/` (cron, php-error, php-slow, msmtp, sauvegarde) et `/var/log/nginx/finakop.*` |

**Restaurer une sauvegarde**, par exemple sur un VPS neuf :

```bash
set -a; . /etc/finakop/sauvegarde.env; set +a
restic snapshots --tag finakop
restic restore latest --tag finakop --target /root/restauration
# Le dossier restauré contient data/ et etc-finakop/ (config.php, clé éventuelle).
cp /root/restauration/var/backups/finakop/*/etc-finakop/config.php /etc/finakop/config.php
php /srv/finakop/scripts/migration-donnees.php exporter /root/restauration/var/backups/finakop/<date>/data /root/export-restau
FORCER=1 /srv/finakop/scripts/importer-donnees.sh /root/export-restau
```

**Performances : ce qu'on surveille**
- `/var/log/finakop/php-slow.log` liste les requêtes de plus de 5 s. Ce sont les candidates à une optimisation dans le produit.
- La RAM : si `free -m` montre de la mémoire d'échange utilisée en continu, on passe au VPS-2.
- SQLite n'accepte qu'une écriture à la fois **par base**. Chaque société ayant sa propre base, ce n'est pas une limite en pratique. Le registre maître est surtout lu.

---

## 8. Contenu du kit

```
finakop-migration/
├── PLAN-MIGRATION.md                  ce document
├── autonome/                          → /srv/finakop/autonome
│   ├── public/index.php               point d'entrée web (seul fichier de la racine web)
│   ├── lib/amorcage.php               constantes FKC_*, lecture de config.php, garde-fous
│   ├── lib/compat-wp.php              wp_mail → msmtp, prochain passage du cron, admin_email
│   ├── bin/finakop                    bpe, balayage, verifier, licence, comptes-compromis, reemettre-mdp
│   └── config.exemple.php             → /etc/finakop/config.php
├── deploiement/
│   ├── nginx/finakop.conf             HTTPS, fichiers statiques, limiteurs, contrôleur frontal
│   ├── nginx/relais.conf              wss:// pour FinaKop Connect (optionnel)
│   ├── php/finakop-pool.conf          pool FPM dédié (open_basedir, sessions, UTC, msmtp)
│   ├── php/99-finakop.ini             OPcache dimensionné pour ~2 100 fichiers
│   ├── cron/finakop                   bpe toutes les 5 min, balayage horaire, sauvegarde, vérification
│   ├── systemd/finakop-relais.service relais Connect, confiné
│   ├── msmtp/msmtprc.exemple          relais SMTP
│   └── logrotate/finakop
└── scripts/
    ├── installer-vps.sh               installation complète d'un VPS neuf (idempotente)
    ├── deployer-version.sh            déploiement ou retour arrière d'une version (ZIP de l'extension)
    ├── migration-donnees.php          export cohérent et contrôle (lignes, SHA-256, intégrité)
    ├── importer-donnees.sh            import sur le VPS, avec contrôle avant et après
    ├── sauvegarder.sh                 VACUUM INTO, copie locale, restic hors site
    └── maintenance-wp.php             met FinaKop en 503 sur l'ancien site pendant l'export
```

**Tests réalisés sur ce kit** (PHP 8.4, sans WordPress) :
- Suite de tests de FinaKop : 452 vérifications sur 452 réussies.
- App autonome : connexion, changement de mot de passe imposé, Cockpit, Licence, Sociétés et Processus répondent 200. Les fichiers statiques sont servis sous `/_fkc/`.
- `finakop bpe`, `balayage`, `licence`, `comptes-compromis` et `verifier` fonctionnent.
- Installation d'un jeton `pro` émis par `make_license.php` : statut « active ».
- `exporter` puis `controler` : conformes. Une modification d'un octet est détectée (code 1).
- `sauvegarder.sh --locale-seulement` : les bases sont copiées à chaud, et le contrôle de la copie est conforme.

*Non testés ici, faute de VPS réel :* `installer-vps.sh`, nginx et Let's Encrypt, msmtp, restic, `deployer-version.sh`. Ils ont seulement été vérifiés en syntaxe. Leur validation est l'objet de la **phase 2 (répétition à blanc)**.

---

## Sources des prix

- [OVHcloud VPS 2026 : tarifs et caractéristiques (learnwithhasan.com)](https://learnwithhasan.com/vps-providers/ovh-vps/)
- [Test OVHcloud VPS 2026 (hebergement-vps.fr)](https://www.hebergement-vps.fr/test/ovhcloud)
- [OVHcloud VPS-1 (vpsbenchmarks.com)](https://www.vpsbenchmarks.com/hosters/ovhcloud/plans/vps-1)
- [Hetzner : ajustement des prix au 15 juin 2026](https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/)
- [Hetzner Cloud pricing (costgoat.com)](https://costgoat.com/pricing/hetzner)
- [Contabo Cloud VPS 10 (whtop.com)](https://www.whtop.com/plans/contabo.com/133001)
- [Tarification Contabo 2026 (affinco.com)](https://affinco.com/contabo-pricing/)
- [OVHcloud Object Storage Standard 3-AZ](https://corporate.ovhcloud.com/en/newsroom/news/object-storage-3az/)
- [Comparatif des prix S3 2026 (s3compare.io)](https://www.s3compare.io/)
