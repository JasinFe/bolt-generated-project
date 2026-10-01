#!/usr/bin/env bash
# Fabrique les archives de livraison à partir d'un arbre source FinaKop complet.
#
#   bash build.sh <source finakop-erp-core> <dossier de sortie>
#
# Produit :
#   finakop-plateforme-<v>.zip   application autonome (Hostinger / VPS)
#   finakop-erp-core-<v>.zip     extension WordPress (mêmes correctifs, pour les sites restés sous WordPress
#                                et pour préparer l'export depuis l'ancien site)
#   finakop-export-<v>.zip       outils à déposer sur l'ANCIEN hébergement (export contrôlé, maintenance)
set -euo pipefail
SRC="$(cd "${1:?source}" && pwd)"; OUT="$(mkdir -p "${2:?sortie}" && cd "$2" && pwd)"
ICI="$(cd "$(dirname "$0")" && pwd)"
V="$(tr -d ' \n' < "$SRC/VERSION")"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# Contrôles : version cohérente, aucune clé privée, syntaxe PHP.
grep -q "Version:      $V" "$SRC/finakop-erp-core.php" || { echo "VERSION ($V) ≠ en-tête de l'extension"; exit 1; }
if find "$SRC" -name '*private*.pem' | grep -q .; then echo "REFUS : clé privée dans la source"; exit 1; fi
find "$SRC/app" "$SRC/bin" "$SRC/cron" "$SRC/public" "$SRC/scripts" -name '*.php' -print0 | xargs -0 -n 50 -P 4 php -l | grep -v '^No syntax errors' && { echo "Erreur de syntaxe"; exit 1; } || true
php -l "$SRC/bin/finakop" >/dev/null

# 1. Plateforme autonome
P="$TMP/finakop-plateforme"
mkdir -p "$P/docs"
cp -a "$SRC/app" "$SRC/assets" "$SRC/bin" "$SRC/cron" "$SRC/config" "$SRC/public" "$SRC/scripts" "$SRC/VERSION" "$P/"
rm -f "$P/config/config.php"
cp -a "$ICI/docs/." "$P/docs/"
cp "$SRC/PATCH_NOTES.md" "$P/docs/NOTES-DE-VERSION-produit.md"
cp "$ICI/LISEZ-MOI.md" "$P/LISEZ-MOI.md"
find "$P" \( -name '.DS_Store' -o -name '*.orig' -o -name '*.rej' \) -delete
(cd "$TMP" && zip -qr -X "$OUT/finakop-plateforme-$V.zip" finakop-plateforme)

# 2. Extension WordPress (arbre complet, sans la couche plateforme inutile sous WordPress)
E="$TMP/finakop-erp-core"
mkdir -p "$E"
( cd "$SRC" && tar --exclude='./.git' --exclude='./config/config.php' -cf - . ) | ( cd "$E" && tar -xf - )
rm -rf "$E/public" "$E/bin" "$E/cron" "$E/config" "$E/scripts/installer.sh" "$E/scripts/deployer.sh" "$E/VERSION"
(cd "$TMP" && zip -qr -X "$OUT/finakop-erp-core-$V.zip" finakop-erp-core)

# 3. Outils pour l'ancien hébergement
X="$TMP/finakop-export"
mkdir -p "$X"
cp "$SRC/scripts/migration-donnees.php" "$SRC/scripts/diagnostic-hebergement.php" "$X/"
cp "$ICI/outils-export/maintenance-wp.php" "$ICI/outils-export/LISEZ-MOI.md" "$X/"
(cd "$TMP" && zip -qr -X "$OUT/finakop-export-$V.zip" finakop-export)

# 4. Site vitrine finakoperp.com (contenu à extraire DANS public_html)
if [ -d "$ICI/site-vitrine" ]; then
  rm -f "$OUT/finakop-site-vitrine-$V.zip"
  (cd "$ICI/site-vitrine" && zip -qr -X "$OUT/finakop-site-vitrine-$V.zip" .)
fi

( cd "$OUT" && sha256sum finakop-*-"$V".zip > SHA256SUMS-"$V".txt )
ls -la "$OUT"
