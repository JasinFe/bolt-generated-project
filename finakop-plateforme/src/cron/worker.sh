#!/bin/sh
# Variante SHELL du cron, pour un hébergement où proc_open est désactivé.
# Même travail que worker.php, un client après l'autre :
#   */5 * * * *  sh /home/<compte>/finakop/current/cron/worker.sh
R="$(cd "$(dirname "$0")/.." && pwd)"
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
exec 9>"${TMPDIR:-/tmp}/finakop-cron-$(id -u).lock"
if command -v flock >/dev/null 2>&1; then flock -n 9 || exit 0; fi
for t in $("$PHP" "$R/bin/finakop" tenant:lister --statut=actif --slugs); do
  if command -v timeout >/dev/null 2>&1; then
    timeout 180 "$PHP" "$R/bin/finakop" --tenant="$t" cron:client
  else
    "$PHP" "$R/bin/finakop" --tenant="$t" cron:client
  fi
done
