#!/usr/bin/env bash
# FinaKop Plateforme — PREMIÈRE installation sur un compte d'hébergement (sans root).
#
#   bash installer.sh <archive.zip> --web=$HOME/domains/finakoperp.com/public_html/finakop-app
#
# Crée l'arborescence hors web, la configuration (0600) avec une clé de
# sauvegarde générée, déploie la version, puis affiche la tâche cron à déclarer.
set -euo pipefail
R="${FINAKOP_RACINE:-$HOME/finakop}"
D="${FINAKOP_DONNEES:-$HOME/finakop-data}"
PHP="${PHP:-php}"
ARCHIVE=""; WEB=""
for a in "$@"; do case "$a" in --web=*) WEB="${a#--web=}" ;; *) ARCHIVE="$a" ;; esac; done
[ -f "$ARCHIVE" ] && [ -n "$WEB" ] || { echo "Usage : bash installer.sh <archive.zip> --web=<dossier web des sous-domaines>"; exit 1; }

case "$(readlink -f "$D")/" in "$(readlink -f "$HOME")/domains/"*|*/public_html/*) echo "REFUS : le dossier de données doit être hors de public_html."; exit 1 ;; esac

"$PHP" -r 'exit(version_compare(PHP_VERSION,"8.1",">=") && extension_loaded("pdo_sqlite") && extension_loaded("openssl") ? 0 : 1);' \
  || { echo "PHP 8.1+ avec pdo_sqlite et openssl requis (en SSH : $("$PHP" -v | head -1)). Choisissez PHP 8.4 dans hPanel."; exit 1; }

umask 027
mkdir -p "$R/releases" "$D/plateforme/logs" "$D/tenants" "$D/sessions" "$D/sauvegardes"
chmod 0750 "$D" "$D"/*

if [ ! -f "$R/config.php" ]; then
  TMP="$(mktemp -d)"; unzip -q "$ARCHIVE" -d "$TMP"
  EX="$(find "$TMP" -maxdepth 3 -name config.exemple.php | head -1)"
  CLE="$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  sed -e "s#/home/uXXXXXXXX/finakop-data#$D#" -e "s#'cle'             => '',#'cle'             => '$CLE',#" "$EX" > "$R/config.php"
  chmod 0600 "$R/config.php"; rm -rf "$TMP"
  echo "==> $R/config.php créé (0600). Clé de sauvegarde générée :"
  echo "    $CLE"
  echo "    ⚠ COPIEZ-LA dans votre gestionnaire de mots de passe : sans elle, aucune sauvegarde ne se restaure."
  echo "    Complétez ensuite le mot de passe SMTP : nano $R/config.php"
fi

bash "$(dirname "$0")/deployer.sh" "$ARCHIVE" --web="$WEB"

cat <<EOF

────────────────────────────────────────────────────────────────────
 Installation terminée. Étapes suivantes (docs/04-INSTALLATION-HOSTINGER.md) :
  1. hPanel → Avancé → Tâches cron, toutes les 5 minutes :
       $PHP $R/current/cron/worker.php
  2. hPanel → Sous-domaines : « app » et chaque client → dossier $WEB
  3. Premier client :  $PHP $R/current/bin/finakop tenant:creer newloock "New Loock"
────────────────────────────────────────────────────────────────────
EOF
