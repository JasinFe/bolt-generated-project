#!/usr/bin/env python3
"""Vidéos d'aide de FinaKop (1.876.4) : génère une page HTML animée par vidéo.

Chaque scène montre un VRAI écran de FinaKop (capturé dans un espace de
démonstration, ecrans/*.jpg, 1440×810 en px CSS), avec encadrés, curseur et
légende. Le rendu image par image est fait par ../site-vitrine-studio/render.js.

    python3 gen.py            → videos/*.html
    bash rendre.sh            → app/assets/video/aide-*.mp4 + affiches
"""
import html, json, os

ICI = os.path.dirname(os.path.abspath(__file__))
K = 1280 / 1440  # px CSS de la capture → px de la scène
B = json.load(open(os.path.join(ICI, 'ecrans', 'boites.json'), encoding='utf-8'))


def b(ecran, cle, pad=6):
    r = B[ecran][cle]
    return (r['x'] - pad, r['y'] - pad, r['w'] + 2 * pad, r['h'] + 2 * pad)


def tuile(ecran, cle):
    """Les tuiles de menu : la boîte relevée est celle du titre ; on englobe la tuile."""
    r = B[ecran][cle]
    return (r['x'] - 12, r['y'] - 12, r['w'] + 24, r['h'] + 40)


e = html.escape
OUTRO_SUPPORT = ['💬 WhatsApp +225 05 03 40 43 89', '✉️ supports@finakoperp.com', '❓ Menu Aide : guide, tutoriels, vidéos']

VIDEOS = {
 'aide-prise-en-main': {'titre': 'Prise en main de FinaKop', 'scenes': [
  {'type': 'titre', 'd': 5, 'icone': '🚀', 'titre': 'Prise en main de <span class="g">FinaKop</span>', 'sous': 'Vos premières minutes, pas à pas', 'puces': ['Se connecter', 'Votre mot de passe', 'Se repérer', "Trouver de l'aide"]},
  {'type': 'ecran', 'd': 7, 'img': '00-portail', 'n': 1, 'titre': 'Accédez à votre espace', 'texte': "Ouvrez l'adresse de votre entreprise, ou le portail app.finakoperp.com et saisissez son identifiant.",
   'cibles': [(1, b('00-portail', 'champ'), "L'identifiant de votre entreprise"), (3.6, b('00-portail', 'bouton'), 'Puis « Accéder à mon espace »')]},
  {'type': 'ecran', 'd': 7, 'img': '01-connexion', 'n': 2, 'titre': 'Connectez-vous', 'texte': "Identifiant et mot de passe reçus de votre administrateur.",
   'cibles': [(1, b('01-connexion', 'identifiant'), 'Votre identifiant'), (2.8, b('01-connexion', 'mdp'), 'Votre mot de passe'), (4.6, b('01-connexion', 'bouton'), 'Se connecter')]},
  {'type': 'ecran', 'd': 8, 'img': '02-mot-de-passe', 'n': 3, 'titre': 'Choisissez votre mot de passe', 'texte': "Le mot de passe reçu est provisoire : choisissez le vôtre, 12 caractères minimum, 3 catégories sur 4.",
   'cibles': [(1, (60, 300, 360, 150), 'Les règles à respecter'), (3.4, b('02-mot-de-passe', 'ancien'), 'Le mot de passe provisoire'), (5.2, (946, 417, 448, 145), 'Le nouveau, deux fois')]},
  {'type': 'ecran', 'd': 6, 'img': '03-2fa-activation', 'n': 4, 'titre': 'Activez la double authentification', 'texte': "Obligatoire pour les administrateurs : voyez la vidéo « Sécuriser votre compte ».",
   'cibles': [(1, b('03-2fa-activation', 'qr'), 'Un QR code à scanner avec votre téléphone')]},
  {'type': 'ecran', 'd': 7, 'img': '05-accueil', 'n': 5, 'titre': 'Le menu et votre entité', 'texte': "À gauche : l'entité active et vos modules, regroupés par activité.",
   'cibles': [(1, (24, 106, 206, 60), "L'entité active : changez de société ici"), (3.6, (14, 180, 226, 600), 'Vos modules')]},
  {'type': 'ecran', 'd': 6, 'img': '05-accueil', 'n': 6, 'titre': 'Rechercher et agir vite', 'texte': "La recherche trouve un écran, un client, un article. « + Action » crée une facture, un reçu…",
   'cibles': [(0.8, (384, 72, 784, 56), 'Recherche (touche /)'), (3, (278, 80, 98, 40), '« + Action »')]},
  {'type': 'ecran', 'd': 6, 'img': '05-accueil', 'n': 7, 'titre': 'Alertes et assistant', 'texte': "Les alertes signalent ce qui demande votre attention ; Copilot vous aide à analyser.",
   'cibles': [(0.8, (1174, 80, 128, 40), 'Alertes'), (3, (1308, 80, 104, 40), 'Copilot')]},
  {'type': 'ecran', 'd': 11, 'img': '17-aide', 'n': 8, 'titre': "Trouver de l'aide", 'texte': "Menu Aide : recherche, guide de prise en main, vidéos, tutoriels et dépannage.",
   'cibles': 'AIDE'},
  {'type': 'titre', 'd': 7, 'icone': '💬', 'titre': 'Une question ? <span class="g">Nous sommes là.</span>', 'sous': 'Le support FinaKop vous répond', 'puces': OUTRO_SUPPORT},
 ]},
 'aide-double-authentification': {'titre': 'Sécuriser votre compte', 'scenes': [
  {'type': 'titre', 'd': 5, 'icone': '🔐', 'titre': 'Sécuriser <span class="g">votre compte</span>', 'sous': 'La double authentification, en deux minutes', 'puces': ['Mot de passe', '+ code du téléphone', '= compte protégé']},
  {'type': 'titre', 'd': 7, 'icone': '📱', 'titre': 'Installez une application', 'sous': "Sur votre téléphone, depuis Play Store ou App Store", 'puces': ['Google Authenticator', 'Microsoft Authenticator', 'Authy', '2FAS · Aegis']},
  {'type': 'ecran', 'd': 15, 'img': '03-2fa-activation', 'n': 1, 'titre': 'Scannez le QR code', 'texte': "Dans l'application : Ajouter → Scanner un QR code. Puis tapez le code affiché et validez.",
   'cibles': [(0.8, b('03-2fa-activation', 'qr'), 'Visez ce QR code'), (4.5, b('03-2fa-activation', 'cle'), 'Sans appareil photo : saisissez cette clé'), (8, b('03-2fa-activation', 'code'), 'Le code à 6 chiffres affiché'), (11, b('03-2fa-activation', 'bouton'), "Activer avec l'application")]},
  {'type': 'ecran', 'd': 8, 'img': '04-2fa-codes', 'n': 2, 'titre': 'Conservez vos codes de secours', 'texte': "10 codes à usage unique, affichés une seule fois : imprimez-les ou rangez-les en lieu sûr.", 'flou': b('04-2fa-codes', 'codes', 0),
   'cibles': [(0.8, b('04-2fa-codes', 'codes'), 'Vos 10 codes de secours (masqués ici)'), (4.5, b('04-2fa-codes', 'bouton'), "Puis « J'ai noté mes codes »")]},
  {'type': 'ecran', 'd': 10, 'img': '23-verification', 'n': 3, 'titre': 'À chaque connexion', 'texte': "Après le mot de passe, FinaKop demande le code de l'application. 5 essais au plus.",
   'cibles': [(1, b('23-verification', 'code'), 'Le code affiché par votre téléphone'), (4.5, b('23-verification', 'bouton'), 'Vérifier')]},
  {'type': 'titre', 'd': 15, 'icone': '🛟', 'titre': 'En cas de <span class="g">problème</span>', 'sous': 'Les bons réflexes', 'liste': [
     ('⏱️', 'Code refusé', "Réglez l'heure du téléphone en automatique."),
     ('🛟', 'Téléphone perdu', 'Tapez un code de secours à la place du code.'),
     ('📲', 'Nouveau téléphone', 'Désactivez puis réactivez la protection pour rescanner.'),
     ('💬', 'Rien ne marche', 'Support : +225 05 03 40 43 89 · supports@finakoperp.com')]},
 ]},
 'aide-facturer-encaisser': {'titre': 'Facturer et encaisser', 'scenes': [
  {'type': 'titre', 'd': 5, 'icone': '🧾', 'titre': 'Facturer <span class="g">et encaisser</span>', 'sous': 'Du client au paiement, sans ressaisie', 'puces': ['Clients', 'Factures', 'Encaissements', 'Impayés']},
  {'type': 'ecran', 'd': 7, 'img': '06-facturation-menu', 'n': 1, 'titre': 'Le module Facturation', 'texte': "Tout part d'ici : factures, nouvelle facture, proformas et clients.",
   'cibles': [(0.8, tuile('06-facturation-menu', 'factures'), 'La liste des factures'), (2.8, tuile('06-facturation-menu', 'nouvelle'), 'Établir une facture'), (4.8, (305, 690, 1100, 44), 'Vos clients')]},
  {'type': 'ecran', 'd': 7, 'img': '09-clients', 'n': 2, 'titre': 'Créez vos clients', 'texte': "Entreprises : renseignez leur NCC, utile pour la facture normalisée.",
   'cibles': [(1, b('09-clients', 'nouveau'), 'Nouveau client'), (3.6, b('09-clients', 'filtre'), 'Retrouvez un client')]},
  {'type': 'ecran', 'd': 12, 'img': '08-facture-nouvelle', 'n': 3, 'titre': 'Établissez la facture', 'texte': "Client, date, échéance, règlement, puis les lignes : article, quantité, prix, TVA.",
   'cibles': [(1, (297, 517, 270, 48), 'Le client'), (3.2, (573, 517, 542, 48), 'Date et échéance'), (5.4, (1124, 517, 268, 48), 'Le mode de règlement'), (7.8, b('08-facture-nouvelle', 'lignes'), 'Ajoutez les lignes')]},
  {'type': 'ecran', 'd': 6, 'img': '10-encaissement', 'n': 4, 'titre': 'Encaissez', 'texte': "Le Centre d'Encaissement enregistre chaque paiement reçu.",
   'cibles': [(0.8, b('10-encaissement', 'nouveau'), 'Nouveau reçu'), (3, b('10-encaissement', 'journal'), 'Journal / Z de caisse')]},
  {'type': 'ecran', 'd': 8, 'img': '11-encaissement-nouveau', 'n': 5, 'titre': 'Le reçu', 'texte': "Choisissez le type d'opération et le mode de paiement : espèces, Mobile Money, virement…",
   'cibles': [(1, (295, 569, 1100, 52), "Type d'opération"), (4, (844, 670, 551, 52), 'Mode de paiement')]},
  {'type': 'ecran', 'd': 8, 'img': '21-recouvrement', 'n': 6, 'titre': 'Suivez les impayés', 'texte': "Balance âgée, relances, promesses : qui doit quoi, depuis quand.",
   'cibles': [(1, (346, 386, 387, 84), 'Balance âgée')]},
  {'type': 'titre', 'd': 7, 'icone': '💡', 'titre': 'Le bon <span class="g">réflexe</span>', 'sous': 'Chaque facture et chaque reçu passent en comptabilité tout seuls', 'puces': OUTRO_SUPPORT},
 ]},
 'aide-comptabilite-caisse-equipe': {'titre': 'Comptabilité, caisse, stock et équipe', 'scenes': [
  {'type': 'titre', 'd': 5, 'icone': '📒', 'titre': 'Comptabilité, caisse, <span class="g">stock et équipe</span>', 'sous': "L'essentiel du quotidien", 'puces': ['Écritures', 'Caisse', 'Stock', 'Utilisateurs']},
  {'type': 'ecran', 'd': 7, 'img': '14-compta', 'n': 1, 'titre': 'La comptabilité', 'texte': "Clients, fournisseurs, trésorerie, grand livre, immobilisations, états financiers.",
   'cibles': [(1, (355, 460, 1060, 200), 'Les grands domaines SYSCOHADA')]},
  {'type': 'ecran', 'd': 7, 'img': '15-ecriture-nouvelle', 'n': 2, 'titre': 'Saisir une écriture', 'texte': "Journal, date, pièce, puis les lignes en partie double : le débit égale le crédit.",
   'cibles': [(1, (295, 432, 1100, 140), "En-tête de l'écriture"), (3.6, (295, 708, 1100, 48), 'Lignes (partie double)')]},
  {'type': 'ecran', 'd': 8, 'img': '12-caisse', 'n': 3, 'titre': 'La caisse', 'texte': "Terminal de vente, ouverture, encaissements, clôture avec comptage.",
   'cibles': [(1, tuile('12-caisse', 'terminal'), 'Terminal de vente'), (3.6, tuile('12-caisse', 'macaisse'), 'Ma caisse')]},
  {'type': 'ecran', 'd': 8, 'img': '13-inventaire', 'n': 4, 'titre': 'Le stock', 'texte': "Tableau de bord, alertes, réapprovisionnement, valorisation.",
   'cibles': [(1, tuile('13-inventaire', 'tableau'), 'Tableau de bord du stock'), (3.6, tuile('13-inventaire', 'reappro'), 'Réapprovisionnement et alertes')]},
  {'type': 'ecran', 'd': 10, 'img': '16-utilisateurs', 'n': 5, 'titre': 'Votre équipe', 'texte': "Un compte par personne, avec les seuls modules dont elle a besoin.",
   'cibles': [(1, b('16-utilisateurs', 'nouveau'), 'Nouvel utilisateur')]},
  {'type': 'titre', 'd': 10, 'icone': '🎓', 'titre': 'Allez <span class="g">plus loin</span>', 'sous': "Le centre d'aide vous accompagne", 'puces': ['🎯 Guide de prise en main', '🎓 Tutoriels guidés', '🩺 Dépannage', '💬 Support +225 05 03 40 43 89']},
 ]},
}


def scene_titre(s, a):
    puces = ''.join('<span class="pop" style="--d:%.1fs">%s</span>' % (1.6 + 0.25 * i, e(p)) for i, p in enumerate(s.get('puces', [])))
    liste = ''.join('<div class="li up" style="--d:%.1fs"><i>%s</i><b>%s</b><span>%s</span></div>' % (1.4 + 1.6 * i, e(ic), e(t), e(x)) for i, (ic, t, x) in enumerate(s.get('liste', [])))
    return ('<div class="sc centre" style="--a:%ss;--b:%ss"><div>'
            '<div class="ico zoom" style="--d:.15s">%s</div><div class="t up" style="--d:.5s">%s</div>'
            '<p class="s up" style="--d:.9s">%s</p>%s%s</div></div>') % (
        a, a + s['d'], s['icone'], s['titre'], e(s['sous']),
        '<div class="puces">%s</div>' % puces if puces else '', '<div class="liste">%s</div>' % liste if liste else '')


def scene_ecran(s, a, idx):
    d = s['d']
    cibles = s['cibles']
    # Légende en haut si une cible tombe dans la zone de la légende du bas.
    haut = any((y + h) * K > 560 for _, (x, y, w, h), _l in cibles)
    css, blocs, souris = [], [], []
    for k, (t, (x, y, w, h), label) in enumerate(cibles):
        fin = cibles[k + 1][0] if k + 1 < len(cibles) else d + 1
        X, Y, W, H = x * K, y * K, w * K, h * K
        bas = Y + H + 54 < (700 if haut else 720 - 130)
        lab_style = ('top:%.0fpx' % (Y + H + 12)) if bas else ('top:%.0fpx' % (Y - 46))
        lx = min(max(X, 16), 1280 - 380)
        blocs.append('<div class="cible" style="left:%.0fpx;top:%.0fpx;width:%.0fpx;height:%.0fpx;--d:%ss;--f:%ss"></div>'
                     '<div class="etiq" style="left:%.0fpx;%s;--d:%ss;--f:%ss">%s</div>' % (X, Y, W, H, t, fin, lx, lab_style, t + 0.15, fin, e(label)))
        souris.append((t - 0.7, X + min(W * 0.6, W - 10), Y + H * 0.6))
    # Curseur : se déplace d'une cible à l'autre, puis « clique ».
    pts = [(0, 1100, 640)] + souris
    kf = []
    for i, (t, x, y) in enumerate(pts):
        t = max(0, t)
        kf.append('%.2f%%{transform:translate(%.0fpx,%.0fpx)}' % (100 * t / d, x, y))
        if i:
            kf.append('%.2f%%{transform:translate(%.0fpx,%.0fpx)}' % (min(100, 100 * (t + 0.7) / d), x, y))
    kf.append('100%%{transform:translate(%.0fpx,%.0fpx)}' % (pts[-1][1], pts[-1][2]))
    css.append('@keyframes sour%d{%s}' % (idx, ''.join(sorted(set(kf), key=lambda z: float(z.split('%')[0])))))
    zoom = s.get('zoom')
    zstyle = ''
    if zoom:
        z, cx, cy = zoom
        css.append('@keyframes zm%d{from{transform:scale(1)}to{transform:scale(%s)}}' % (idx, z))
        zstyle = 'transform-origin:%.0fpx %.0fpx;animation:zm%d 1.6s cubic-bezier(.4,0,.2,1) calc(var(--a) + .2s) both' % (cx * K, cy * K, idx)
    flou = ''
    if s.get('flou'):
        x, y, w, h = s['flou']
        flou = '<div class="flou" style="left:%.0fpx;top:%.0fpx;width:%.0fpx;height:%.0fpx"></div>' % (x * K, y * K, w * K, h * K)
    html_ = ('<div class="sc ecran" style="--a:%ss;--b:%ss"><div class="cadre" style="%s">'
             '<img src="../ecrans/%s.jpg" alt="">%s%s'
             '<div class="souris" style="animation:sour%d %ss linear calc(var(--a)) both"><svg viewBox="0 0 24 24"><path d="M3 2l17 9-7.5 1.6L9 20z" fill="#fff" stroke="#0A0F1C" stroke-width="1.6"/></svg></div>'
             '</div><div class="leg%s up" style="--d:.3s"><span class="n">%s</span><div><b>%s</b><span>%s</span></div></div></div>') % (
        a, a + d, zstyle, s['img'], flou, ''.join(blocs), idx, d, ' haut' if haut else '', s['n'], e(s['titre']), e(s['texte']))
    return html_, css


def page(nom, v):
    a, corps, css = 0, [], []
    for i, s in enumerate(v['scenes']):
        if s['type'] == 'titre':
            corps.append(scene_titre(s, a))
        else:
            h, c = scene_ecran(s, a, i)
            corps.append(h); css += c
        a += s['d']
    total = a
    return total, ('<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>%s</title>'
                   '<link rel="stylesheet" href="../aide.css"><style>%s</style></head><body>'
                   '<div class="stage" style="--total:%ss"><div class="fond"></div>'
                   '<div class="marque"><img src="../../site-vitrine/assets/img/finakop-logo.png" alt="">FinaKop · Aide</div>'
                   '%s<div class="temps"></div></div></body></html>') % (e(v['titre']), ''.join(css), total, ''.join(corps))


if __name__ == '__main__':
    import sys
    os.makedirs(os.path.join(ICI, 'videos'), exist_ok=True)
    seules = sys.argv[1:]
    for nom, v in VIDEOS.items():
        for s in v['scenes']:
            if s.get('cibles') == 'AIDE':
                bx = B.get('17-aide', {})
                s['cibles'] = [(1, b('17-aide', 'recherche'), "Rechercher dans toute l'aide")]
                if bx.get('video'):
                    s['cibles'].append((4, b('17-aide', 'video'), 'Les vidéos de prise en main'))
                if bx.get('nouveau'):
                    s['cibles'].append((7.5, b('17-aide', 'nouveau'), 'Guide, vidéos, tutoriels, dépannage'))
        total, h = page(nom, v)
        open(os.path.join(ICI, 'videos', nom + '.html'), 'w', encoding='utf-8').write(h)
        print(nom, total, 's')
