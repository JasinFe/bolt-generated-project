#!/bin/sh
# Lance le gestionnaire de licences (macOS / Linux). Ajoutez --public pour l'activation en ligne.
cd "$(dirname "$0")"
command -v node >/dev/null 2>&1 || { echo "Node.js n'est pas installé : https://nodejs.org"; exit 1; }
( sleep 1; (command -v xdg-open >/dev/null && xdg-open http://localhost:4444) || open http://localhost:4444 ) >/dev/null 2>&1 &
exec node server.mjs "$@"
