#!/usr/bin/env bash
# FinaKop Plateforme — installation / mise à jour / retour arrière, SANS droits root.
# Hébergement mutualisé (Hostinger) comme VPS. À lancer en SSH, sous votre compte :
#
#   bash deployer.sh <archive.zip> [--web=<dossier web des sous-domaines>]
#   bash deployer.sh --retour                      revient à la version précédente
#
# Première fois : --web=$HOME/domains/finakoperp.com/public_html/finakop-app
# (le dossier est mémorisé dans ~/finakop/.dossier-web pour les fois suivantes).
set -euo pipefail
trap 'echo; echo "ÉCHEC à la ligne $LINENO : rien n a été basculé si l échec précède « Bascule »."' ERR

R="${FINAKOP_RACINE:-$HOME/finakop}"
# PHP : celui imposé par $PHP, sinon le premier qui a pdo_sqlite ET sodium.
# Chez Hostinger, « php » en SSH peut être une autre version que celle du site
# (8.5 sans sodium constaté) ; les versions alternatives sont sous /opt/alt.
choisir_php() {
  if [ -n "${PHP:-}" ]; then return 0; fi
  for c in php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php85/usr/bin/php /opt/alt/php82/usr/bin/php php8.4 php8.3; do
    command -v "$c" >/dev/null 2>&1 || [ -x "$c" ] || continue
    if "$c" -r 'exit(version_compare(PHP_VERSION,"8.1",">=") && extension_loaded("pdo_sqlite") && extension_loaded("sodium") ? 0 : 1);' 2>/dev/null; then PHP="$c"; return 0; fi
  done
  PHP=php
}
choisir_php
ARCHIVE=""; WEB=""; RETOUR=0
for a in "$@"; do
  case "$a" in
    --web=*) WEB="${a#--web=}" ;;
    --retour) RETOUR=1 ;;
    *) ARCHIVE="$a" ;;
  esac
done
mkdir -p "$R/releases"
echo "PHP utilisé : $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
[ -z "$WEB" ] && [ -f "$R/.dossier-web" ] && WEB="$(cat "$R/.dossier-web")"
[ -n "$WEB" ] || { echo "Dossier web inconnu : --web=\$HOME/domains/finakoperp.com/public_html/finakop-app"; exit 1; }
mkdir -p "$WEB"; echo "$WEB" > "$R/.dossier-web"
[ -f "$R/config.php" ] || { echo "Configuration absente : $R/config.php (lancez d'abord scripts/installer.sh)."; exit 1; }

publier_web() {   # $1 = dossier réel de la version
  local V="$1"
  # Fichiers statiques : copie complète, puis bascule par renommage.
  rm -rf "$WEB/_fkc.nouveau"; cp -a "$V/app/assets" "$WEB/_fkc.nouveau"
  find "$WEB/_fkc.nouveau" -name '*.php' -delete
  [ -d "$WEB/_fkc" ] && mv "$WEB/_fkc" "$WEB/_fkc.ancien"
  mv "$WEB/_fkc.nouveau" "$WEB/_fkc"; rm -rf "$WEB/_fkc.ancien"
  sed -e "s#__DOSSIER_WEB__#$(basename "$WEB")#" "$V/public/.htaccess" > "$WEB/.htaccess.nouveau" && mv "$WEB/.htaccess.nouveau" "$WEB/.htaccess"
  # robots.txt : refus général, et refus NOMMÉ des robots d'IA (certains
  # n'honorent que leur propre nom). Ce n'est qu'une demande : la plateforme
  # refuse en plus activement ces robots (403).
  {
    for b in GPTBot ChatGPT-User OAI-SearchBot ClaudeBot Claude-Web Claude-SearchBot Claude-User anthropic-ai PerplexityBot Perplexity-User \
             CCBot Google-Extended GoogleOther Applebot-Extended Amazonbot Bytespider meta-externalagent FacebookBot cohere-ai Diffbot \
             YouBot MistralAI-User AI2Bot Omgilibot Timpibot ImagesiftBot; do printf 'User-agent: %s\nDisallow: /\n\n' "$b"; done
    printf 'User-agent: *\nDisallow: /\n'
  } > "$WEB/robots.txt"
  # index.php pointe vers le chemin RÉEL de la version (pas le lien « current ») :
  # chaque déploiement change ses chemins, OPcache ne peut pas servir l'ancien code.
  sed -e "s#__FINAKOP_RACINE__#$V#" -e "s#__FINAKOP_CONFIG__#$R/config.php#" "$V/public/index.php" > "$WEB/index.php.nouveau"
  "$PHP" -l "$WEB/index.php.nouveau" >/dev/null
  mv "$WEB/index.php.nouveau" "$WEB/index.php"
  # Dossier web : lisible par le serveur web (LiteSpeed/Apache), quel que soit le
  # umask de la session (installer.sh pose 027 pour les DONNÉES). Il ne contient
  # que le point d'entrée et des fichiers statiques publics ; les fichiers que
  # l'hébergeur dépose à la création d'un sous-domaine (default.php) sont retirés.
  rm -f "$WEB/default.php" "$WEB/default.php.old.php"
  chmod 0755 "$WEB"
  find "$WEB/_fkc" -type d -exec chmod 0755 {} +
  find "$WEB/_fkc" -type f -exec chmod 0644 {} +
  chmod 0644 "$WEB/index.php" "$WEB/.htaccess" "$WEB/robots.txt"
}

if [ "$RETOUR" = 1 ]; then
  ACT="$(readlink -f "$R/current")"
  PREC="$(ls -1dt "$R"/releases/*/ | sed 's#/$##' | grep -vx "$ACT" | head -1 || true)"
  [ -n "$PREC" ] || { echo "Aucune version précédente."; exit 1; }
  ln -sfn "$PREC" "$R/current.nouveau" && mv -T "$R/current.nouveau" "$R/current"
  publier_web "$PREC"
  echo "Retour à $(basename "$PREC")."
  echo "⚠ Si la version abandonnée a déjà migré des bases, restaurez la sauvegarde prise avant son déploiement."
  exit 0
fi

[ -f "$ARCHIVE" ] || { echo "Usage : bash deployer.sh <finakop-plateforme-X.Y.Z.zip> [--web=…] | --retour"; exit 1; }
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
unzip -q "$ARCHIVE" -d "$TMP"
SRC="$(dirname "$(find "$TMP" -maxdepth 3 -name VERSION -path '*/VERSION' | head -1)")"
[ -f "$SRC/app/Plateforme/Amorcage.php" ] && [ -f "$SRC/app/index.php" ] || { echo "Archive inattendue (app/Plateforme absent)."; exit 1; }
VERSION="$(tr -d ' \n' < "$SRC/VERSION")"

# C'est la NOUVELLE version qui sait publier son dossier web (règles, robots.txt…) :
# si ce script est celui de la version installée, on passe la main à celui de
# l'archive (une seule fois).
if [ -z "${FINAKOP_DEPLOYER_RELAIS:-}" ] && [ -f "$SRC/scripts/deployer.sh" ] && ! cmp -s "$0" "$SRC/scripts/deployer.sh"; then
  cp "$SRC/scripts/deployer.sh" "$TMP/deployer-nouveau.sh"
  echo "==> Déploiement confié au script de la version $VERSION"
  FINAKOP_DEPLOYER_RELAIS=1 PHP="$PHP" bash "$TMP/deployer-nouveau.sh" "$@"
  exit $?
fi

# Garde-fous : jamais de clé privée dans le code servi ; clé publique de licence présente.
if find "$SRC" -name '*private*.pem' | grep -q .; then echo "REFUS : clé PRIVÉE trouvée dans l'archive."; exit 1; fi
[ -f "$SRC/assets/keys/license_public.pem" ] || { echo "REFUS : assets/keys/license_public.pem absente."; exit 1; }
for f in bin/finakop cron/worker.php app/index.php app/Plateforme/Amorcage.php public/index.php; do "$PHP" -l "$SRC/$f" >/dev/null; done

DEST="$R/releases/$VERSION"
if [ -d "$DEST" ]; then echo "Version $VERSION déjà présente : réactivation."; else
  mv "$SRC" "$DEST"
  find "$DEST" -type d -exec chmod 0755 {} +; find "$DEST" -type f -exec chmod 0644 {} +
  chmod 0755 "$DEST/bin/finakop" "$DEST/cron/worker.sh" "$DEST/scripts/"*.sh
fi

# Sauvegarde de tous les clients AVANT bascule : une version peut migrer des schémas au premier accès.
if [ -L "$R/current" ] && "$PHP" "$R/current/bin/finakop" tenant:lister --slugs >/dev/null 2>&1; then
  if [ -n "$("$PHP" "$R/current/bin/finakop" tenant:lister --slugs)" ]; then
    echo "==> Sauvegarde de tous les clients avant bascule"
    "$PHP" "$R/current/bin/finakop" tenant:sauvegarder --tous
  fi
fi

echo "==> Bascule vers $VERSION"
ln -sfn "$DEST" "$R/current.nouveau" && mv -T "$R/current.nouveau" "$R/current"
publier_web "$DEST"

echo "==> Vérification"
"$PHP" "$R/current/bin/finakop" plateforme:verifier || true

# Conserver les 5 dernières versions.
ls -1dt "$R"/releases/*/ | tail -n +6 | xargs -r rm -rf
echo "FinaKop Plateforme $VERSION en service."
