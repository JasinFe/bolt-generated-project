#!/usr/bin/env bash
# FinaKop — déploiement d'une version (mise à jour sans coupure, retour arrière en une commande).
#
#   /srv/finakop/scripts/deployer-version.sh finakop-erp-core-1_875_5.zip
#   /srv/finakop/scripts/deployer-version.sh --retour            # revient à la version précédente
#
# Le ZIP est EXACTEMENT celui de l'extension WordPress : un seul livrable pour les deux modes.
# Le code est installé en lecture seule (root) : le pool PHP ne peut pas le modifier.
set -euo pipefail
R=/srv/finakop/releases
PHPV="${PHPV:-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')}"
[ "$(id -u)" -eq 0 ] || { echo "À lancer en root."; exit 1; }

recharger() {
  systemctl reload php${PHPV}-fpm          # vide l'OPcache (validate_timestamps=0)
  systemctl is-enabled finakop-relais >/dev/null 2>&1 && systemctl restart finakop-relais || true
}

if [ "${1:-}" = "--retour" ]; then
  ACTUELLE=$(readlink -f /srv/finakop/current)
  PRECEDENTE=$(ls -1dt "$R"/*/ | sed 's#/$##' | grep -vx "$ACTUELLE" | head -1)
  [ -n "$PRECEDENTE" ] || { echo "Aucune version précédente."; exit 1; }
  ln -sfn "$PRECEDENTE" /srv/finakop/current.tmp && mv -T /srv/finakop/current.tmp /srv/finakop/current
  recharger
  echo "Retour à $(basename "$PRECEDENTE")."
  echo "⚠ Si la version abandonnée avait migré le schéma des bases, restaurez aussi la sauvegarde d'avant déploiement."
  exit 0
fi

ZIP="${1:?Usage : $0 finakop-erp-core-X.zip | --retour}"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP" -d "$TMP"
SRC="$TMP/finakop-erp-core"
[ -f "$SRC/finakop-erp-core.php" ] && [ -f "$SRC/app/index.php" ] || { echo "ZIP inattendu : finakop-erp-core/ absent."; exit 1; }

VERSION=$(sed -n 's/^[ *]*Version:[[:space:]]*\([0-9.]*\).*/\1/p' "$SRC/finakop-erp-core.php" | head -1)
[ -n "$VERSION" ] || { echo "Version illisible."; exit 1; }

# Garde-fous : jamais de clé privée dans le code servi, clés publiques présentes.
if find "$SRC" -name '*private*.pem' | grep -q .; then echo "REFUS : clé PRIVÉE trouvée dans le ZIP."; exit 1; fi
[ -f "$SRC/assets/keys/license_public.pem" ] || { echo "REFUS : assets/keys/license_public.pem absente."; exit 1; }

# Ne pas livrer ce qui n'a rien à faire en production.
rm -rf "$SRC/tests" "$SRC/.github"

DEST="$R/$VERSION"
if [ -d "$DEST" ]; then echo "Version $VERSION déjà présente : réactivation."; else
  mv "$SRC" "$DEST"
  chown -R root:root "$DEST"
  find "$DEST" -type d -exec chmod 0755 {} + ; find "$DEST" -type f -exec chmod 0644 {} +
fi

# Sauvegarde AVANT bascule : une version peut migrer le schéma des bases au premier accès.
if [ -x /srv/finakop/scripts/sauvegarder.sh ] && [ -f /var/lib/finakop/data/finakopcore-master.db ]; then
  /srv/finakop/scripts/sauvegarder.sh --locale-seulement "avant-$VERSION"
fi

php -l "$DEST/app/index.php" >/dev/null
ln -sfn "$DEST" /srv/finakop/current.tmp && mv -T /srv/finakop/current.tmp /srv/finakop/current
recharger
echo "FinaKop $VERSION en service."

# Conserver les 5 dernières versions.
ls -1dt "$R"/*/ | tail -n +6 | xargs -r rm -rf
