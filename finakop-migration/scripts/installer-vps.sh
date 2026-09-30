#!/usr/bin/env bash
# FinaKop — préparation d'un VPS neuf (Debian 13 recommandé, ou Ubuntu 24.04 LTS).
#
# Usage (en root, depuis le dossier finakop-migration/ copié sur le VPS) :
#   DOMAINE=finakopcore.kophisgroup.com EMAIL=admin@kophisgroup.com ./scripts/installer-vps.sh
# Options :
#   RELAIS=relais.kophisgroup.com   installe aussi le relais FinaKop Connect (Node)
#   SANS_CERT=1                     n'obtient pas le certificat (DNS pas encore basculé)
#
# Idempotent : peut être relancé sans casse.
set -euo pipefail

: "${DOMAINE:?Définissez DOMAINE (ex. finakopcore.kophisgroup.com)}"
: "${EMAIL:?Définissez EMAIL (contact Let’s Encrypt)}"
RELAIS="${RELAIS:-}"
SANS_CERT="${SANS_CERT:-0}"
KIT="$(cd "$(dirname "$0")/.." && pwd)"
PHPV=8.4

[ "$(id -u)" -eq 0 ] || { echo "À lancer en root."; exit 1; }
. /etc/os-release
echo "==> Système : $PRETTY_NAME"

export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get -yq upgrade

# PHP 8.4 : natif sur Debian 13 ; dépôt ondrej/sury sur Ubuntu 24.04 / Debian 12.
if ! apt-cache show php${PHPV}-fpm >/dev/null 2>&1; then
  echo "==> Ajout du dépôt PHP ${PHPV}"
  if [ "$ID" = "ubuntu" ]; then
    apt-get install -yq software-properties-common
    add-apt-repository -y ppa:ondrej/php
  else
    apt-get install -yq lsb-release ca-certificates curl
    curl -fsSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
  fi
  apt-get update -q
fi

echo "==> Paquets"
apt-get install -yq nginx certbot sqlite3 unzip rsync restic msmtp ca-certificates \
  ufw fail2ban unattended-upgrades apt-listchanges \
  php${PHPV}-fpm php${PHPV}-cli php${PHPV}-sqlite3 php${PHPV}-mbstring php${PHPV}-intl \
  php${PHPV}-gd php${PHPV}-zip php${PHPV}-curl php${PHPV}-xml php${PHPV}-opcache php${PHPV}-bcmath

echo "==> Comptes système"
id finakop >/dev/null 2>&1 || useradd --system --home /srv/finakop --shell /usr/sbin/nologin finakop

echo "==> Arborescence"
install -d -m 0755 -o root    -g root    /srv/finakop /srv/finakop/releases /srv/finakop/autonome /srv/finakop/scripts /var/www/acme
install -d -m 0750 -o finakop -g finakop /var/lib/finakop /var/lib/finakop/data /var/lib/finakop/sessions /var/cache/finakop /var/log/finakop
install -d -m 0750 -o root    -g finakop /etc/finakop
install -d -m 0700 -o root    -g root    /var/backups/finakop

echo "==> Application autonome et scripts"
rsync -a --delete "$KIT/autonome/" /srv/finakop/autonome/
rsync -a "$KIT/scripts/" /srv/finakop/scripts/
chown -R root:root /srv/finakop/autonome /srv/finakop/scripts
chmod 0755 /srv/finakop/autonome/bin/finakop /srv/finakop/scripts/*.sh
ln -sf /srv/finakop/autonome/bin/finakop /usr/local/bin/finakop

if [ ! -f /etc/finakop/config.php ]; then
  sed "s#https://finakopcore.kophisgroup.com/#https://${DOMAINE}/#" "$KIT/autonome/config.exemple.php" > /etc/finakop/config.php
  echo "   → /etc/finakop/config.php créé : À COMPLÉTER (clé de chiffrement, courriel)."
else
  # Relance avec un autre DOMAINE (répétition sur essai.… puis bascule) : on ne touche qu'à base_url.
  sed -i "s#^\(\s*'base_url'\s*=>\s*\)'[^']*'#\1'https://${DOMAINE}/'#" /etc/finakop/config.php
  echo "   → base_url = https://${DOMAINE}/"
fi
chown root:finakop /etc/finakop/config.php && chmod 0640 /etc/finakop/config.php

echo "==> PHP-FPM"
install -m 0644 "$KIT/deploiement/php/finakop-pool.conf" /etc/php/${PHPV}/fpm/pool.d/finakop.conf
install -m 0644 "$KIT/deploiement/php/99-finakop.ini"    /etc/php/${PHPV}/fpm/conf.d/99-finakop.ini
install -m 0644 "$KIT/deploiement/php/99-finakop.ini"    /etc/php/${PHPV}/cli/conf.d/99-finakop.ini
# Le pool « www » par défaut ne sert à rien ici.
[ -f /etc/php/${PHPV}/fpm/pool.d/www.conf ] && mv /etc/php/${PHPV}/fpm/pool.d/www.conf /etc/php/${PHPV}/fpm/pool.d/www.conf.desactive
# Ajuste le nombre de processus à la mémoire réelle (~60 Mo par processus, 40 % de la RAM).
RAM_MO=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
ENFANTS=$(( RAM_MO * 40 / 100 / 60 )); [ "$ENFANTS" -lt 6 ] && ENFANTS=6
sed -i "s/^pm.max_children .*/pm.max_children      = ${ENFANTS}/" /etc/php/${PHPV}/fpm/pool.d/finakop.conf
echo "   → pm.max_children = ${ENFANTS} (RAM ${RAM_MO} Mo)"
php-fpm${PHPV} -t
systemctl enable --now php${PHPV}-fpm
systemctl reload php${PHPV}-fpm

echo "==> Courriel (msmtp)"
if [ ! -f /etc/msmtprc ]; then
  install -m 0640 -o root -g finakop "$KIT/deploiement/msmtp/msmtprc.exemple" /etc/msmtprc
  echo "   → /etc/msmtprc créé : À COMPLÉTER (identifiants du relais SMTP)."
fi

echo "==> Cron, logrotate"
install -m 0644 "$KIT/deploiement/cron/finakop"      /etc/cron.d/finakop
install -m 0644 "$KIT/deploiement/logrotate/finakop" /etc/logrotate.d/finakop

echo "==> Pare-feu"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp  >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable

echo "==> fail2ban (SSH)"
cat > /etc/fail2ban/jail.d/finakop.local <<'EOF'
[sshd]
enabled  = true
maxretry = 5
findtime = 10m
bantime  = 1h
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

echo "==> Mises à jour de sécurité automatiques"
dpkg-reconfigure -f noninteractive unattended-upgrades

echo "==> SSH : connexion par clé uniquement (si une clé est déjà installée)"
if [ -s /root/.ssh/authorized_keys ] || ls /home/*/.ssh/authorized_keys >/dev/null 2>&1; then
  cat > /etc/ssh/sshd_config.d/10-finakop.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
else
  echo "   ⚠ Aucune clé SSH trouvée : mots de passe laissés actifs. Installez une clé puis relancez."
fi

echo "==> Mémoire d'échange (2 Go) si absente"
if ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  sysctl -w vm.swappiness=10 >/dev/null; echo 'vm.swappiness=10' > /etc/sysctl.d/90-finakop.conf
fi

echo "==> nginx"
rm -f /etc/nginx/sites-enabled/default
sed "s/finakopcore.kophisgroup.com/${DOMAINE}/g" "$KIT/deploiement/nginx/finakop.conf" > /etc/nginx/sites-available/finakop.conf
CERT=/etc/letsencrypt/live/${DOMAINE}/fullchain.pem

obtenir_cert() {  # $1 = domaine
  local d="$1"
  [ -f "/etc/letsencrypt/live/${d}/fullchain.pem" ] && return 0
  [ "$SANS_CERT" = "1" ] && return 1
  # Site provisoire HTTP pour le défi ACME.
  cat > /etc/nginx/sites-enabled/00-acme.conf <<EOF
server { listen 80; listen [::]:80; server_name ${d};
  location ^~ /.well-known/acme-challenge/ { root /var/www/acme; }
  location / { return 503; } }
EOF
  nginx -t && systemctl reload nginx
  certbot certonly --webroot -w /var/www/acme -d "$d" -m "$EMAIL" --agree-tos -n
  rm -f /etc/nginx/sites-enabled/00-acme.conf
}

if obtenir_cert "$DOMAINE"; then
  ln -sf /etc/nginx/sites-available/finakop.conf /etc/nginx/sites-enabled/finakop.conf
else
  echo "   ⚠ Certificat non obtenu (SANS_CERT=1 ou DNS non basculé) : site HTTPS non activé."
  echo "     Relancez le script après avoir pointé ${DOMAINE} vers ce serveur."
fi

if [ -n "$RELAIS" ]; then
  echo "==> Relais FinaKop Connect ($RELAIS)"
  apt-get install -yq nodejs
  id finakop-relais >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin finakop-relais
  if [ ! -f /etc/finakop/relais.env ]; then
    umask 077; echo "FKC_RELAIS_SECRET=$(openssl rand -hex 32)" > /etc/finakop/relais.env; umask 022
    chown root:finakop-relais /etc/finakop/relais.env; chmod 0640 /etc/finakop/relais.env
    echo "   → Secret généré dans /etc/finakop/relais.env : reportez-le dans FinaKop (Connect → Administration)."
  fi
  install -m 0644 "$KIT/deploiement/systemd/finakop-relais.service" /etc/systemd/system/finakop-relais.service
  sed "s/relais.kophisgroup.com/${RELAIS}/g" "$KIT/deploiement/nginx/relais.conf" > /etc/nginx/sites-available/relais.conf
  if obtenir_cert "$RELAIS"; then ln -sf /etc/nginx/sites-available/relais.conf /etc/nginx/sites-enabled/relais.conf; fi
  systemctl daemon-reload
  [ -L /srv/finakop/current ] && systemctl enable --now finakop-relais || echo "   (le relais démarrera après le premier déploiement du code)"
fi

nginx -t && systemctl enable --now nginx && systemctl reload nginx
systemctl enable --now certbot.timer 2>/dev/null || true
# Recharger nginx après chaque renouvellement de certificat.
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh

cat <<EOF

────────────────────────────────────────────────────────────────────
 VPS prêt. Étapes suivantes :
  1. Déployer le code :   /srv/finakop/scripts/deployer-version.sh finakop-erp-core-X.Y.Z.zip
  2. Compléter :          /etc/finakop/config.php  et  /etc/msmtprc
  3. Importer les données (voir PLAN-MIGRATION.md, phase 4)
  4. Contrôler :          runuser -u finakop -- finakop verifier
────────────────────────────────────────────────────────────────────
EOF
