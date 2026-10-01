#!/usr/bin/env python3
"""Empreintes de cache du site vitrine (2.4).

Chaque lien vers un fichier de assets/ reçoit « ?v=<empreinte> », tirée du
CONTENU du fichier. Un fichier modifié change donc d'adresse : ni le
navigateur ni Cloudflare ne peuvent resservir l'ancienne version (cause des
sections sans mise en forme en 2.1-2.3 : HTML neuf, CSS de la 2.0 en cache).

    python3 empreintes.py            → met à jour index.html et merci.html
    python3 empreintes.py --verifier → code 1 si une empreinte est périmée

Appelé par build.sh avant la fabrication du ZIP.
"""
import hashlib, os, re, sys

ICI = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.join(ICI, '..', 'site-vitrine')
PAGES = ['index.html', 'merci.html']
LIEN = re.compile(r'((?:src|href|poster|content)=")((?:https://finakoperp\.com/)?assets/[^"?#]+)(?:\?v=[^"#]*)?(")')
JSONLD = re.compile(r'("(?:logo|image)":")(https://finakoperp\.com/assets/[^"?#]+)(?:\?v=[^"#]*)?(")')


def empreinte(chemin):
    rel = re.sub(r'^https://finakoperp\.com/', '', chemin)
    f = os.path.join(SITE, rel)
    if not os.path.isfile(f):
        raise SystemExit('Fichier introuvable pour le lien %s' % chemin)
    return hashlib.sha256(open(f, 'rb').read()).hexdigest()[:10]


def traiter(page):
    p = os.path.join(SITE, page)
    s = open(p, encoding='utf-8').read()
    neuf = LIEN.sub(lambda m: '%s%s?v=%s%s' % (m.group(1), m.group(2), empreinte(m.group(2)), m.group(3)), s)
    neuf = JSONLD.sub(lambda m: '%s%s?v=%s%s' % (m.group(1), m.group(2), empreinte(m.group(2)), m.group(3)), neuf)
    return p, s, neuf


if __name__ == '__main__':
    verif = '--verifier' in sys.argv
    perimes = 0
    for page in PAGES:
        p, avant, apres = traiter(page)
        if avant != apres:
            perimes += 1
            if not verif:
                open(p, 'w', encoding='utf-8').write(apres)
        print('%-11s %s' % (page, 'à jour' if avant == apres else ('PÉRIMÉ' if verif else 'empreintes posées')))
    sys.exit(1 if verif and perimes else 0)
