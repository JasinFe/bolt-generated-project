#!/usr/bin/env bash
# FinaKop — import des données exportées de l'ancien hébergement, avec contrôle.
#
#   /srv/finakop/scripts/importer-donnees.sh /root/export-finakop     (dossier produit par « exporter »)
#
# Refuse d'écraser un dossier de données non vide, sauf FORCER=1 (répétition à blanc).
set -euo pipefail
EXPORT="${1:?Usage : $0 <dossier d’export contenant data/ et MANIFESTE.json>}"
DATA=/var/lib/finakop/data
PHPV="${PHPV:-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')}"
[ "$(id -u)" -eq 0 ] || { echo "À lancer en root."; exit 1; }
[ -f "$EXPORT/MANIFESTE.json" ] && [ -d "$EXPORT/data" ] || { echo "Export incomplet (data/ ou MANIFESTE.json absent)."; exit 1; }
trap 'echo; echo "ÉCHEC à la ligne $LINENO. PHP-FPM reste ARRÊTÉ tant que les données ne sont pas conformes : corrigez puis relancez avec FORCER=1."' ERR

echo "==> 1/5 Contrôle de l'export tel que reçu"
php /srv/finakop/scripts/migration-donnees.php controler "$EXPORT/data" "$EXPORT/MANIFESTE.json"

if [ -n "$(ls -A "$DATA" 2>/dev/null)" ]; then
  if [ "${FORCER:-0}" != "1" ]; then echo "REFUS : $DATA n'est pas vide (FORCER=1 pour écraser une répétition)."; exit 1; fi
  echo "   FORCER=1 : l'ancien contenu est mis de côté dans $DATA.avant-import-$(date +%s)"
  mv "$DATA" "$DATA.avant-import-$(date +%s)"
  install -d -m 0750 -o finakop -g finakop "$DATA"
fi

echo "==> 2/5 Arrêt du service le temps de la copie"
systemctl stop php${PHPV}-fpm

echo "==> 3/5 Copie et droits"
rsync -a "$EXPORT/data/" "$DATA/"
chown -R finakop:finakop "$DATA"
find "$DATA" -type d -exec chmod 0750 {} +
find "$DATA" -type f -exec chmod 0640 {} +
[ -f "$DATA/.fkc-secret.key" ] && chmod 0600 "$DATA/.fkc-secret.key"

echo "==> 4/5 Contrôle à l'arrivée"
# Contrôle SOUS L'UTILISATEUR DU SERVICE : ouvrir une base SQLite en mode WAL crée
# ses fichiers -wal/-shm ; créés par root, PHP-FPM ne pourrait plus écrire.
# Le manifeste est copié là où cet utilisateur peut le lire (l'export est dans /root).
MANIF=$(mktemp /tmp/finakop-manifeste.XXXXXX.json)
cp "$EXPORT/MANIFESTE.json" "$MANIF"; chmod 0644 "$MANIF"
runuser -u finakop -- php /srv/finakop/scripts/migration-donnees.php controler "$DATA" "$MANIF"
rm -f "$MANIF"
chown -R finakop:finakop "$DATA"

systemctl start php${PHPV}-fpm

echo "==> 5/5 Vérification de l'installation"
runuser -u finakop -- /srv/finakop/autonome/bin/finakop verifier || true

cat <<EOF

 Import terminé. Avant de basculer le DNS :
   • connectez-vous via le fichier hosts (voir PLAN-MIGRATION.md, recette) ;
   • comparez balance, grand livre et une facture avec l'ancien site ;
   • « finakop licence » doit afficher « active ».
 Puis SUPPRIMEZ l'export du VPS :  shred -u "$EXPORT/A-REPORTER-DANS-config.php.txt" 2>/dev/null; rm -rf "$EXPORT"
EOF
