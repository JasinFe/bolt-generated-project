#!/usr/bin/env bash
# Régénère les vidéos, affiches et images du site vitrine.
# Prérequis : node + playwright (Chromium), ffmpeg avec libx264 (ex. pip install imageio-ffmpeg).
set -euo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"; V="$ICI/../site-vitrine/assets"
export PLAYWRIGHT="${PLAYWRIGHT:-$(npm root -g)/playwright}"
export FFMPEG="${FFMPEG:-$(python3 -c 'import imageio_ffmpeg;print(imageio_ffmpeg.get_ffmpeg_exe())' 2>/dev/null || echo ffmpeg)}"
cd "$ICI"
if [ "${1:-}" != "--images" ]; then
  node render.js video demo.html  "$V/video/finakop-demo.mp4"  60 30 1.25 &
  node render.js video guide.html "$V/video/finakop-guide.mp4" 65 30 1.25 &
  wait
fi
T="$(mktemp -d)"
node render.js images demo.html "$T/d" 12.5,5.5 1.5
node render.js images guide.html "$T/g" 4.5 1.5
"$FFMPEG" -loglevel error -y -i "$T/d/image-12.5.jpg" -vf scale=1280:-2 -q:v 3 "$V/video/demo-affiche.jpg"
"$FFMPEG" -loglevel error -y -i "$T/g/image-4.5.jpg" -vf scale=1280:-2 -q:v 3 "$V/video/guide-affiche.jpg"
"$FFMPEG" -loglevel error -y -i "$T/d/image-5.5.jpg" -vf "scale=1200:-2,crop=1200:630" -q:v 3 "$V/img/partage.jpg"
node galerie.js "$ICI/../site-vitrine/index.html" "$V/img"
rm -rf "$T"; ls -la "$V/video" "$V/img"
