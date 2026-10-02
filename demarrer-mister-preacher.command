#!/bin/sh
# Lanceur macOS / Linux : double-cliquez (macOS) ou lancez ./demarrer-mister-preacher.command
cd "$(dirname "$0")"
[ -d node_modules ] || npm install --omit=dev --no-audit --no-fund
node server/index.js
