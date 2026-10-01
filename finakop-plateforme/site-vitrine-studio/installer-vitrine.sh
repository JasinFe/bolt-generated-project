#!/usr/bin/env bash
# FinaKop — installe ou met à jour le site vitrine dans public_html, droits compris.
#
#   bash ~/domains/finakoperp.com/installer-vitrine.sh            → le ZIP de vitrine le plus récent
#   bash ~/domains/finakoperp.com/installer-vitrine.sh <zip>      → un ZIP précis
#
# Ne touche jamais au dossier de la plateforme (finakop-app). Les dossiers
# reçoivent 755 et les fichiers 644 : un dossier en 644 ne peut plus être
# ouvert, ni par unzip (« Permission denied ») ni par le serveur web (site
# affiché sans mise en forme ni images).
set -euo pipefail
D="$HOME/domains/finakoperp.com"; W="$D/public_html"
Z="${1:-$(ls -1t "$D"/finakop-site-vitrine-*.zip 2>/dev/null | head -n1 || true)}"
[ -n "$Z" ] && [ -f "$Z" ] || { echo "ZIP de la vitrine introuvable dans $D (déposez finakop-site-vitrine-X.Y.zip à côté de public_html)."; exit 1; }
[ -d "$W" ] || { echo "Dossier $W introuvable."; exit 1; }
cd "$W"
echo "==> ZIP : $(basename "$Z")"

# 1. Réparer les droits AVANT tout (sinon ni rm ni unzip ne peuvent entrer dans les dossiers)
[ -d assets ] && { chmod 755 assets; find assets -type d -exec chmod 755 {} +; }

# 2. Copie de sécurité de l'ancien .htaccess, puis remplacement des fichiers de la vitrine
[ -f .htaccess ] && cp .htaccess "$HOME/htaccess-public_html.$(date +%Y%m%d-%H%M%S)"
rm -rf assets
rm -f index.html default.php merci.html contact.php robots.txt sitemap.xml
unzip -oq "$Z"

# 3. Droits : dossiers 755, fichiers 644
find assets -type d -exec chmod 755 {} +
find assets -type f -exec chmod 644 {} +
chmod 644 index.html merci.html contact.php robots.txt sitemap.xml .htaccess

# 4. Vérifications
ok=1
for d in assets assets/css assets/js assets/img assets/video; do
  p=$(stat -c %a "$d"); [ "$p" = 755 ] || { echo "  [!!] $d en $p (attendu 755)"; ok=0; }
done
ref=$(grep -o 'assets/css/site.css?v=[a-f0-9]*' index.html | head -n1 | cut -d= -f2)
reel=$(sha256sum assets/css/site.css | cut -c1-10)
[ "$ref" = "$reel" ] || { echo "  [!!] site.css ne correspond pas à index.html ($reel au lieu de $ref)"; ok=0; }
ver=$(grep -o 'vitrine-[0-9.]*' index.html | head -n1)
if [ $ok = 1 ]; then
  echo "  [OK] droits des dossiers (755) et des fichiers (644)"
  echo "  [OK] mise en forme et script à jour"
  echo "Site vitrine ${ver#vitrine-} installé. Le dossier finakop-app n'a pas été touché."
else
  echo "Installation incomplète : envoyez cette sortie au support."; exit 1
fi
