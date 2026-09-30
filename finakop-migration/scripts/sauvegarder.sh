#!/usr/bin/env bash
# FinaKop — sauvegarde cohérente des données, locale puis HORS SITE (chiffrée).
#
#   sauvegarder.sh                              quotidienne (cron) : locale + restic
#   sauvegarder.sh --locale-seulement ETIQUETTE avant un déploiement
#
# Hors site : restic vers un stockage S3 (OVH Object Storage, Backblaze B2, Scaleway…).
# Paramètres dans /etc/finakop/sauvegarde.env (root, 0600) :
#   RESTIC_REPOSITORY=s3:https://s3.gra.io.cloud.ovh.net/finakop-sauvegardes
#   RESTIC_PASSWORD=<phrase longue — SANS ELLE, AUCUNE RESTAURATION POSSIBLE : gardez-la aussi hors du VPS>
#   AWS_ACCESS_KEY_ID=...
#   AWS_SECRET_ACCESS_KEY=...
# Première fois seulement :  set -a; . /etc/finakop/sauvegarde.env; set +a; restic init
set -euo pipefail

DATA=/var/lib/finakop/data
LOCAL=/var/backups/finakop
GARDER_JOURS=7
MODE="${1:-}"
ETIQUETTE="${2:-$(date -u +%Y-%m-%dT%H%M)}"
DEST="$LOCAL/$ETIQUETTE"

[ -f "$DATA/finakopcore-master.db" ] || { echo "Rien à sauvegarder ($DATA vide)."; exit 0; }
mkdir -p "$DEST"; chmod 0700 "$LOCAL" "$DEST"

# 1. Bases : instantané cohérent pendant le service (VACUUM INTO lit une transaction figée).
n=0
while IFS= read -r -d '' db; do
  rel="${db#$DATA/}"
  if [ "$(head -c 15 "$db")" = "SQLite format 3" ]; then
    mkdir -p "$DEST/data/$(dirname "$rel")"
    php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout=20000"); $p->exec("VACUUM INTO " . $p->quote($argv[2]));' "$db" "$DEST/data/$rel"
    n=$((n+1))
  fi
done < <(find "$DATA" -type f \( -name '*.db' -o -name '*.sqlite' \) -print0)

# 2. Tout le reste (pièces, logos, GED, clé) — hors fichiers de journal SQLite.
rsync -a --exclude='*.db' --exclude='*.sqlite' --exclude='*-wal' --exclude='*-shm' --exclude='*-journal' "$DATA/" "$DEST/data/"

# 3. La configuration (clé éventuelle incluse) : indispensable à une restauration.
cp -a /etc/finakop "$DEST/etc-finakop"
[ -f /etc/msmtprc ] && cp -a /etc/msmtprc "$DEST/etc-finakop/"

echo "$(date -u +%FT%TZ) sauvegarde locale $ETIQUETTE : $n base(s)"

# Rétention locale.
find "$LOCAL" -mindepth 1 -maxdepth 1 -type d -mtime +"$GARDER_JOURS" -exec rm -rf {} +

[ "$MODE" = "--locale-seulement" ] && exit 0

# 4. Hors site, chiffré, dédupliqué.
if [ -f /etc/finakop/sauvegarde.env ]; then
  set -a; . /etc/finakop/sauvegarde.env; set +a
  restic backup --quiet --tag finakop --host "$(hostname)" "$DEST"
  restic forget --quiet --tag finakop --prune --keep-daily 14 --keep-weekly 8 --keep-monthly 24
  # Contrôle d'un échantillon de 5 % des données déjà envoyées.
  [ "$(date +%u)" = "7" ] && restic check --read-data-subset=5% --quiet
  echo "$(date -u +%FT%TZ) envoi hors site OK"
else
  echo "⚠ /etc/finakop/sauvegarde.env absent : AUCUNE copie hors site. Une panne disque = perte des données."
  exit 1
fi
