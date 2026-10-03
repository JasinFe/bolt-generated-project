#!/bin/sh
# Lance le serveur des overlays (macOS / Linux). Ajoutez --lan pour piloter depuis un téléphone.
cd "$(dirname "$0")"
command -v node >/dev/null 2>&1 || { echo "Node.js n'est pas installé : https://nodejs.org"; exit 1; }
( sleep 1; (command -v xdg-open >/dev/null && xdg-open http://localhost:3333/controle.html) || open http://localhost:3333/controle.html ) >/dev/null 2>&1 &
exec node server.mjs "$@"
