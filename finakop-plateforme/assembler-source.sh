#!/usr/bin/env bash
# Reconstitue l'arbre source complet : FinaKop 1.875.5 d'origine + src/ (fichiers modifiés et ajoutés).
#   bash assembler-source.sh <finakop-erp-core-1_875_5.zip> <dossier cible>
set -euo pipefail
ZIP="${1:?zip 1.875.5}"; DEST="${2:?dossier}"; ICI="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"; unzip -q "$ZIP" -d "$TMP"
rm -rf "$DEST"; mv "$TMP/finakop-erp-core" "$DEST"; rmdir "$TMP"
cp -a "$ICI/src/." "$DEST/"
echo "Source assemblée : $DEST (version $(cat "$DEST/VERSION"))"
