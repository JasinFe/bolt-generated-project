#!/bin/sh
# Variante SHELL du cron, pour un hébergement où proc_open est désactivé.
# Même travail que worker.php, un client après l'autre :
#   */5 * * * *  sh /home/<compte>/finakop/current/cron/worker.sh
R="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-php}"
exec 9>"${TMPDIR:-/tmp}/finakop-cron-$(id -u).lock"
if command -v flock >/dev/null 2>&1; then flock -n 9 || exit 0; fi
for t in $("$PHP" "$R/bin/finakop" tenant:lister --statut=actif --slugs); do
  if command -v timeout >/dev/null 2>&1; then
    timeout 180 "$PHP" "$R/bin/finakop" --tenant="$t" cron:client
  else
    "$PHP" "$R/bin/finakop" --tenant="$t" cron:client
  fi
done
