#!/usr/bin/env bash
# Télécharge les polices libres (licence OFL, Google Fonts) utilisées par les presets.
# Elles sont chargées automatiquement par lyricfx depuis ce dossier.
set -euo pipefail
cd "$(dirname "$0")"
BASE="https://github.com/google/fonts/raw/main/ofl"
for f in \
  anton/Anton-Regular.ttf \
  bebasneue/BebasNeue-Regular.ttf \
  pacifico/Pacifico-Regular.ttf \
  poppins/Poppins-Black.ttf \
  poppins/Poppins-ExtraBold.ttf \
  poppins/Poppins-SemiBold.ttf
do
  name="$(basename "$f")"
  [ -f "$name" ] && { echo "✔ $name (déjà présent)"; continue; }
  echo "↓ $name"
  curl -fsSL -o "$name" "$BASE/$f"
done
echo "Polices prêtes dans $(pwd)"
