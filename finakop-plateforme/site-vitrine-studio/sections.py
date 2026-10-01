#!/usr/bin/env python3
"""Sections « Packs métier », « Éditions » et « Tarifs » du site vitrine, générées depuis les
données réelles du produit (donnees-produit.json, exporté de FKC_AidePacks et
FKC_Plans). Remplace les blocs balisés dans ../site-vitrine/index.html.

    python3 sections.py
"""
import html, json, os, re

ICI = os.path.dirname(os.path.abspath(__file__))
INDEX = os.path.join(ICI, '..', 'site-vitrine', 'index.html')
D = json.load(open(os.path.join(ICI, 'donnees-produit.json'), encoding='utf-8'))
TARIFS = json.load(open(os.path.join(ICI, 'tarifs.json'), encoding='utf-8'))  # grille validée : seule source des prix
e = html.escape

FAMILLES = [  # ordre d'affichage, libellé, icône
    ('commerce', 'Commerce & distribution', '🛍️'),
    ('restauration_hotellerie_loisirs', 'Restauration, hôtellerie & loisirs', '🍽️'),
    ('sante', 'Santé', '🩺'),
    ('services', 'Services', '💼'),
    ('creatif', 'Industries créatives', '🎨'),
    ('finance', 'Finance & assurance', '💳'),
    ('transport_logistique', 'Transport & logistique', '🚚'),
    ('economie_sociale', 'Associations & coopératives', '🤝'),
    ('education', 'Éducation', '🎓'),
    ('industrie', 'Industrie', '🏭'),
    ('btp', 'BTP', '🏗️'),
    ('agriculture', 'Agriculture & élevage', '🌾'),
]
NOMS = {'FinaKop Artist — Artiste, Producteur & Revenus': 'Artiste & producteur', 'Creative Suite — Label musical': 'Label musical'}


def nom_pack(label):
    return NOMS.get(label, re.sub(r'^FinaKop\s+', '', label))


def decoupe(desc):
    if ' : ' in desc:
        a, b = desc.split(' : ', 1)
        return a.strip(), b.strip().rstrip('.')
    return '', desc.strip().rstrip('.')


def section_metiers():
    packs = []
    for code, lib, ic in FAMILLES:
        for p in D['packs'].get(code, {}).values():
            packs.append((code, p))
    n = len(packs)
    onglets = ['<button class="fam actif" data-fam="*" role="tab" aria-selected="true">✨ Tous <span>%d</span></button>' % n]
    for code, lib, ic in FAMILLES:
        c = len(D['packs'].get(code, {}))
        onglets.append('<button class="fam" data-fam="%s" role="tab" aria-selected="false">%s %s <span>%d</span></button>' % (code, ic, e(lib), c))
    cartes = []
    for i, (code, p) in enumerate(packs):
        cible, detail = decoupe(p['description'])
        cartes.append('<article class="pack" data-fam="%s" data-txt="%s"><span class="pack-ico">%s</span><div><h3>%s</h3><small>%s</small><p>%s</p></div></article>' % (
            code, e((nom_pack(p['label']) + ' ' + p['description']).lower()), p['icone'], e(nom_pack(p['label'])), e(cible), e(detail)))
    defile = ''.join('<span>%s %s</span>' % (p['icone'], e(nom_pack(p['label']))) for _, p in packs)
    return '''<!-- ═══════════ MÉTIERS ═══════════ -->
<section id="metiers">
  <div class="titre rv wrap"><span class="kicker">Packs métier</span><h2><span class="grad">%d activités</span> prêtes à l'emploi</h2><p>Chaque pack apporte les écrans, les documents et les indicateurs de votre métier, par-dessus le socle commun. Choisissez votre secteur.</p></div>
  <div class="metiers metiers-1 rv" aria-hidden="true"><div class="defile" data-boucle>%s</div></div>
  <div class="wrap">
    <div class="packs-outils rv">
      <div class="familles-onglets" role="tablist" aria-label="Familles de métiers">%s</div>
      <label class="packs-recherche"><span aria-hidden="true">🔎</span><input type="search" id="packsQ" placeholder="Trouver mon métier : pharmacie, maquis, école…" autocomplete="off"></label>
    </div>
    <div class="packs" id="packs" aria-live="polite">%s</div>
    <p class="packs-vide" id="packsVide" hidden>Aucun pack ne correspond. Le socle <b>Gestion générale</b> convient à toute activité, et nous adaptons FinaKop à votre métier : <a href="#contact">parlons-en</a>.</p>
    <p class="packs-note rv">🏢 <b>Socle Gestion générale</b> inclus pour tous : comptabilité SYSCOHADA, facturation normalisée, trésorerie, fiscalité, stocks. Votre métier n'y est pas ? <a href="#contact">Dites-le-nous</a>.</p>
  </div>
</section>

''' % (n, defile, ''.join(onglets), ''.join(cartes))


MODULES = [
    ('comptabilite', '📒', 'Comptabilité SYSCOHADA révisé'),
    ('facturation', '🧾', 'Facturation & facture normalisée'),
    ('inventaire', '📦', 'Stocks & inventaire'),
    ('caisse', '🛒', 'Caisse & point de vente'),
    ('scan', '🔍', 'Scan & codes-barres'),
    ('analyse', '📊', 'Tableaux de bord & analyse'),
    ('analytique', '🧮', 'Comptabilité analytique'),
    ('fiscalite', '🏛️', 'Fiscalité & déclarations DGI'),
    ('recouvrement', '💰', 'Recouvrement des créances'),
    ('rh', '👥', 'Ressources humaines'),
    ('api', '🔌', 'API & connecteurs'),
    ('paie', '💵', 'Paie'),
    ('royalties', '🎵', 'Royalties & droits'),
]
# Capacités affichées : uniquement celles qui existent réellement dans FinaKop (1.876.7).
# Consolidation de groupe, inter-sociétés, multi-pays, réplication et haute
# disponibilité figurent dans d'anciens jetons mais ne sont pas des fonctions
# livrées : elles ne sont pas annoncées.
CAPS = [
    ('api_keys', 'Clés API'), ('workflow', 'Circuits de validation'), ('bi_avancee', 'BI avancée'),
    ('data_warehouse', 'Entrepôt de données'), ('multi_devises', 'Multi-devises'),
    ('support_premium', 'Support premium'), ('sauvegardes', 'Sauvegardes renforcées'),
    ('portail_artiste', 'Portail artiste'), ('distribution', 'Distribution musicale'), ('publishing', 'Publishing / édition'),
]
# Inclus dans toutes les éditions (fonctionnement de la plateforme).
CAPS_TOUS = ['Sauvegarde chiffrée chaque nuit, gardée 14 jours', 'Double authentification', 'Espace isolé par entreprise']
CAPVAL = {'simple': 'simple', 'complete': 'complète', 'basique': 'basique', 'quotidiennes': 'quotidiennes', 'quotidiennes+replication': 'quotidiennes + réplication', 'personnalisees': 'personnalisées'}
T = D['tiers']


def lim(v, unite=''):
    if v == -1:
        return 'Illimité'
    s = '{:,}'.format(v).replace(',', ' ')
    return s + unite


def titre(lab):
    """Libellé de ligne de tableau : majuscule initiale, sigles préservés."""
    lab = {'de stockage': 'stockage'}.get(lab, lab)
    return lab[:1].upper() + lab[1:]


def chiffres(t, cles):
    L = T[t]['limits']
    out = []
    for k, ic, lab, unit in cles:
        if k in L:
            out.append('<div class="chf"><i>%s</i><b>%s</b><span>%s</span></div>' % (ic, lim(L[k], unit), lab))
    return ''.join(out)


CLES = [('societes', '🏢', 'sociétés', ''), ('utilisateurs', '👤', 'utilisateurs', ''), ('etablissements', '🏬', 'établissements', ''), ('stockage_go', '💾', 'de stockage', ' Go')]
CLES_CR = [('entites_creatives', '🎙️', 'labels / studios', ''), ('artistes', '🎤', 'artistes', ''), ('oeuvres', '🎼', 'œuvres', ''), ('utilisateurs', '👤', 'utilisateurs', '')]


def modules_html(t, base=None):
    mods = T[t]['modules']
    prec = set(T[base]['modules']) if base else set()
    out = []
    for code, ic, lab in MODULES:
        if code in mods:
            neuf = base and code not in prec
            out.append('<li class="%s">%s %s%s</li>' % ('neuf' if neuf else '', ic, e(lab), ' <em>nouveau</em>' if neuf else ''))
    return ''.join(out)


def caps_html(t):
    c = T[t]['caps']
    out = []
    for code, lab in CAPS:
        v = c.get(code)
        if v in (True, 1) or (isinstance(v, str) and v):
            extra = (' ' + CAPVAL.get(v, v)) if isinstance(v, str) else ''
            out.append('<li>✓ %s%s</li>' % (e(lab), e(extra)))
    return ''.join('<li>✓ %s</li>' % e(x) for x in CAPS_TOUS) + ''.join(out)


EDITIONS = [
    ('starter', 'Démarrer', 'ESSENTIEL', "L'essentiel pour vendre et tenir vos comptes", "Commerces, TPE, indépendants : une société, une équipe réduite. Pour une TPE, l'offre START garde les mêmes modules avec 2 utilisateurs et 1 Go.",
     "Dès le premier jour : comptabilité SYSCOHADA, facturation normalisée, stock, caisse et scan. Tout ce qu'il faut pour vendre, encaisser et tenir des comptes justes, sans rien installer.", None),
    ('business', 'Croître', 'BUSINESS', 'Pour les PME qui se développent', "PME avec plusieurs sites ou plusieurs sociétés, qui veulent piloter leurs marges et leurs impayés.",
     "Tout ESSENTIEL, plus la comptabilité analytique, la fiscalité et les déclarations DGI, et le recouvrement des créances. Jusqu'à 3 sociétés, 3 établissements et 10 utilisateurs.", 'starter'),
    ('pro', 'Performer', 'PRO', 'Les outils complets des structures exigeantes', "Groupes et entreprises structurées : équipes nombreuses, filiales, besoin d'intégrations.",
     "Tout BUSINESS, plus les ressources humaines et l'API pour connecter vos autres outils. Jusqu'à 5 sociétés, 10 établissements et 25 utilisateurs, avec des clés API (50 000 appels par mois).", 'business'),
]


def fcfa(n):
    return '{:,}'.format(n).replace(',', ' ') + ' F'


def offre(nom):
    for o in TARIFS['core'] + TARIFS['creative']:
        if o['nom'] == nom:
            return o
    raise SystemExit('Offre inconnue dans tarifs.json : ' + nom)


def prix_ligne(nom):
    o = offre(nom)
    an = (' · ' + fcfa(o['annuel']) + ' / an') if o.get('annuel') and not o.get('des') else ''
    return '<p class="edp-prix"><span>%s</span><b>%s%s</b> / mois%s</p>' % (e(nom), 'dès ' if o.get('des') else '', fcfa(o['mensuel']), an)


def carte_tarif(o, gamme):
    des = 'dès ' if o.get('des') else ''
    an = fcfa(o['annuel']) if o.get('annuel') else 'sur devis'
    quotas = ''
    if o.get('quotas'):
        q = o['quotas']
        quotas = ('<ul class="trf-q"><li><b>%s</b> société%s</li><li><b>%s</b> utilisateurs</li>'
                  '<li><b>%s</b> établissement%s</li><li><b>%s</b> de stockage</li></ul>') % (
            q[0], '' if q[0] == 1 else 's', q[1], q[2], '' if q[2] == 1 else 's', q[3])
    points = ''.join('<li>%s</li>' % e(p) for p in o.get('points', []))
    badge = ('<em class="trf-badge">%s</em>' % e(o['badge'])) if o.get('badge') else ''
    return ('<article class="trf%s" data-gamme="%s">%s<header><h3>%s</h3><small>%s</small></header>'
            '<div class="trf-prix"><span class="m"><b>%s%s</b><i>/ mois</i></span><span class="a"><b>%s%s</b><i>%s</i></span></div>'
            '%s%s<a class="btn %s" href="#contact" data-edition="%s">%s</a></article>') % (
        ' pop' if o.get('badge') else '', gamme, badge, e(o['nom']), e(o['cible']),
        des, fcfa(o['mensuel']), des if o.get('annuel') else '', an, '/ an · 2 mois offerts' if o.get('annuel') else '',
        quotas, ('<ul class="trf-pts">%s</ul>' % points) if points else '', 'btn-or' if o.get('badge') else 'btn-ghost', e(o['nom']),
        'Demander un devis' if o.get('des') else 'Essayer 30 jours')


SECTION_TARIFS = """<!-- ═══════════ TARIFS ═══════════ -->
<section id="tarifs">
  <div class="wrap">
    <div class="titre rv"><span class="kicker">Tarifs</span><h2>Des prix clairs, <span class="grad">en F CFA</span></h2><p>Sans engagement au mois, 2 mois offerts à l'année. Prix nets, TVA non applicable. Payez par Wave, Orange Money, MTN, Moov ou virement.</p></div>
    <div class="trf-lancement rv"><b>🚀 Offre de lancement</b><ul>%(lance)s</ul></div>
    <div class="trf-outils rv">
      <div class="trf-bascule" role="group" aria-label="Période de facturation"><button type="button" class="actif" data-periode="m" aria-pressed="true">Mensuel</button><button type="button" data-periode="a" aria-pressed="false">Annuel <em>2 mois offerts</em></button></div>
      <div class="trf-gammes" role="group" aria-label="Gamme"><button type="button" class="actif" data-gamme="core" aria-pressed="true">FinaKop</button><button type="button" data-gamme="creative" aria-pressed="false">Creative Suite</button></div>
    </div>
    <div class="trfs rv" id="trfs" data-periode="m" data-gamme="core">%(core)s%(crea)s</div>
    <p class="trf-note">Creative Suite : toute la gestion FinaKop, plus le module Royalties &amp; droits, pour les artistes, labels, studios, médias et l'événementiel. Quotas identiques à l'offre FinaKop de même rang ; le module Royalties ajoute 25 %% au prix.</p>
    <div class="trf-bas rv">
      <div><h3>Services de mise en route</h3><div class="tab-w"><table class="niv trf-frais"><tbody>%(frais)s</tbody></table></div><p class="mention" style="text-align:left">Payés une fois. Activation offerte avec l'abonnement annuel.</p></div>
      <div><h3>Bon à savoir</h3><ul class="trf-pts">
        <li>Essai gratuit de 30 jours sur votre propre espace : vos données sont gardées si vous vous abonnez.</li>
        <li>Sans engagement au mois ; l'annuel vaut 10 mois.</li>
        <li>Une facture normalisée (FNE) pour chaque paiement.</li>
        <li>Vos données vous appartiennent : export possible à tout moment.</li>
        <li>Un besoin plus large ? Utilisateurs, sociétés ou stockage s'ajustent sur devis.</li>
      </ul></div>
    </div>
  </div>
</section>

"""


def section_tarifs():
    return SECTION_TARIFS % {
        'lance': ''.join('<li>%s</li>' % e(x) for x in TARIFS['lancement']),
        'core': ''.join(carte_tarif(o, 'core') for o in TARIFS['core']),
        'crea': ''.join(carte_tarif(o, 'creative') for o in TARIFS['creative']),
        'frais': ''.join('<tr><th>%s</th><td>%s</td></tr>' % (e(a), e(b)) for a, b in TARIFS['frais']),
    }


def section_editions():
    cartes, panneaux = [], []
    def carte(i, cle, kick, nom, accroche, pop=False):
        cartes.append('<button class="ed%s%s" data-ed="%s" role="tab" aria-selected="%s"><small>%s</small><b>%s</b><span>%s</span></button>' % (
            ' actif' if i == 0 else '', ' pop' if pop else '', cle, 'true' if i == 0 else 'false', kick, nom, e(accroche)))
    for i, (t, kick, nom, accroche, ideal, desc, base) in enumerate(EDITIONS):
        carte(i, t, kick, nom, accroche, t == 'pro')
        panneaux.append('''<div class="edp%s" data-ed="%s" role="tabpanel"><div class="edp-g">
  <span class="kicker">%s</span><h3>FinaKop %s</h3><p class="edp-acc">%s</p>%s<p>%s</p>
  <p class="edp-ideal"><b>Idéal pour :</b> %s</p>
  <div class="chfs">%s</div>
  <a class="btn btn-or" href="#contact" data-edition="%s">Demander un devis %s →</a></div>
  <div class="edp-d"><h4>Modules inclus</h4><ul class="mods">%s</ul><h4>Capacités</h4><ul class="caps">%s</ul></div></div>''' % (
            ' actif' if i == 0 else '', t, kick, nom, e(accroche), prix_ligne(nom), e(desc), e(ideal), chiffres(t, CLES), nom, nom,
            modules_html(t, base), caps_html(t)))
    # Entreprise : trois niveaux
    carte(3, 'entreprise', 'Grands comptes', 'ENTREPRISE', 'ENTREPRISE ou ENTREPRISE+')
    niv = [('enterprise_standard', 'ENTREPRISE'), ('enterprise_avancee', 'ENTREPRISE+ Avancée'), ('enterprise_illimitee', 'ENTREPRISE+ Premium')]
    lignes = ''
    for k, ic, lab, unit in CLES + [('api', '🔌', 'appels API / mois', '')]:
        lignes += '<tr><th>%s %s</th>%s</tr>' % (ic, titre(lab), ''.join('<td>%s</td>' % lim(T[t]['limits'].get(k, 0), unit) for t, _ in niv))
    for code, lab in CAPS:
        vals = [T[t]['caps'].get(code) for t, _ in niv]
        if not any(vals):
            continue
        lignes += '<tr><th>%s</th>%s</tr>' % (e(lab), ''.join('<td>%s</td>' % (('✓ ' + CAPVAL.get(v, '')).strip() if isinstance(v, str) else ('✓' if v else '—')) for v in vals))
    panneaux.append('''<div class="edp" data-ed="entreprise" role="tabpanel"><div class="edp-g">
  <span class="kicker">Grands comptes</span><h3>FinaKop ENTREPRISE</h3><p class="edp-acc">Pour les groupes, réseaux et organisations multi-sites</p>''' + prix_ligne('ENTREPRISE') + prix_ligne('ENTREPRISE+') + '''
  <p>Tout Pro, plus la <b>paie</b>, les circuits de validation et la BI avancée. ENTREPRISE couvre 10 sociétés et 50 utilisateurs ; ENTREPRISE+ monte jusqu'à 50 sociétés et 300 utilisateurs, sur un serveur dédié, avec support prioritaire.</p>
  <p class="edp-ideal"><b>Idéal pour :</b> groupes, réseaux de points de vente, organisations à plusieurs sites et nombreuses équipes.</p>
  <ul class="mods mods-court">%s</ul>
  <a class="btn btn-or" href="#contact" data-edition="ENTREPRISE">Demander un devis ENTREPRISE →</a></div>
  <div class="edp-d"><h4>ENTREPRISE et ENTREPRISE+</h4><div class="tab-w"><table class="niv"><thead><tr><th></th>%s</tr></thead><tbody>%s</tbody></table></div></div></div>''' % (
        modules_html('enterprise_standard', 'pro'), ''.join('<th>%s</th>' % n for _, n in niv), lignes))
    # Creative Suite
    carte(4, 'creative', 'Industries créatives', 'Creative Suite', 'Labels, studios, artistes, médias')
    cniv = [('creative_starter', 'Starter'), ('creative_business', 'Business'), ('creative_pro', 'Pro'), ('creative_enterprise_illimitee', 'Premium')]
    lc = ''
    for k, ic, lab, unit in CLES_CR + [('contrats', '📝', 'contrats', ''), ('royalties', '💿', 'calculs de royalties / mois', '')]:
        lc += '<tr><th>%s %s</th>%s</tr>' % (ic, titre(lab), ''.join('<td>%s</td>' % lim(T[t]['limits'].get(k, 0), unit) for t, _ in cniv))
    for code, lab in [('portail_artiste', 'Portail artiste'), ('distribution', 'Distribution musicale'), ('publishing', 'Publishing / édition')]:
        vals = [T[t]['caps'].get(code) for t, _ in cniv]
        lc += '<tr><th>%s</th>%s</tr>' % (e(lab), ''.join('<td>%s</td>' % (('✓ ' + CAPVAL.get(v, '')).strip() if isinstance(v, str) else ('✓' if v else '—')) for v in vals))
    panneaux.append('''<div class="edp" data-ed="creative" role="tabpanel"><div class="edp-g">
  <span class="kicker">Industries créatives</span><h3>FinaKop Creative Suite</h3><p class="edp-acc">La gestion complète des métiers de la création</p>''' + prix_ligne('Creative STARTER') + '''
  <p>Toute la gestion FinaKop, plus le module <b>Royalties & droits</b> : artistes, catalogue d'œuvres, contrats, calcul et reversement des royalties, portail artiste, distribution et publishing selon le niveau.</p>
  <p class="edp-ideal"><b>Idéal pour :</b> labels, studios, artistes et producteurs, médias, maisons d'édition, événementiel, gestion collective des droits.</p>
  <a class="btn btn-or" href="#contact" data-edition="Creative Suite">Demander un devis Creative Suite →</a></div>
  <div class="edp-d"><h4>Les niveaux Creative</h4><div class="tab-w"><table class="niv"><thead><tr><th></th>%s</tr></thead><tbody>%s</tbody></table></div></div></div>''' % (
        ''.join('<th>%s</th>' % n for _, n in cniv), lc))
    # Comparatif complet
    cols = [('starter', 'ESSENTIEL'), ('business', 'BUSINESS'), ('pro', 'PRO'), ('enterprise_standard', 'ENTREPRISE')]
    comp = ''
    for code, ic, lab in MODULES[:12]:
        comp += '<tr><th>%s %s</th>%s</tr>' % (ic, e(lab), ''.join('<td>%s</td>' % ('✓' if code in T[t]['modules'] else '—') for t, _ in cols))
    for k, ic, lab, unit in CLES:
        vals = [lim(T[t]['limits'].get(k, 0), unit) for t, _ in cols]
        vals[3] = vals[3] + ' → ' + lim(T['enterprise_illimitee']['limits'].get(k, 0), unit)
        comp += '<tr><th>%s %s</th>%s</tr>' % (ic, titre(lab), ''.join('<td>%s</td>' % v for v in vals))
    for code, lab in [('api_keys', 'Clés API'), ('workflow', 'Circuits de validation'), ('bi_avancee', 'BI avancée'), ('data_warehouse', 'Entrepôt de données')]:
        comp += '<tr><th>%s</th>%s</tr>' % (lab, ''.join('<td>%s</td>' % (('✓ ' + CAPVAL.get(T[t]['caps'].get(code), '')).strip() if isinstance(T[t]['caps'].get(code), str) else ('✓' if T[t]['caps'].get(code) else '—')) for t, _ in cols))
    return '''<!-- ═══════════ ÉDITIONS ═══════════ -->
<section id="editions" style="background:linear-gradient(180deg,transparent,rgba(76,125,255,.05),transparent)">
  <div class="wrap">
    <div class="titre rv"><span class="kicker">Éditions</span><h2>Une édition <span class="grad">pour chaque étape</span></h2><p>Commencez simplement, puis élargissez vos modules et vos métiers sans changer d'outil ni ressaisir vos données. Cliquez sur une édition pour voir ce qu'elle contient ; les prix sont juste en dessous.</p></div>
    <div class="eds rv" role="tablist" aria-label="Éditions">%s</div>
    <div class="edps rv">%s</div>
    <details class="comparatif rv"><summary>📊 Comparer les éditions en détail</summary><div class="tab-w"><table class="niv comp"><thead><tr><th></th>%s</tr></thead><tbody>%s</tbody></table></div>
      <p class="mention" style="text-align:left">Votre pack métier s'ajoute à l'édition choisie. Le stockage compte vos bases et vos pièces jointes (scans, PDF) ; la sauvegarde chiffrée de chaque nuit est incluse. Les quotas peuvent être ajustés sur devis.</p></details>
  </div>
</section>

''' % (''.join(cartes), ''.join(panneaux), ''.join('<th>%s</th>' % n for _, n in cols), comp)


if __name__ == '__main__':
    s = open(INDEX, encoding='utf-8').read()
    a = s.index('<!-- ═══════════ MÉTIERS ═══════════ -->'); b = s.index('<!-- ═══════════ SÉCURITÉ ═══════════ -->')
    s = s[:a] + section_metiers() + s[b:]
    a = s.index('<!-- ═══════════ ÉDITIONS ═══════════ -->'); b = s.index('<!-- ═══════════ DÉMARRAGE ═══════════ -->')
    s = s[:a] + section_editions() + section_tarifs() + s[b:]
    open(INDEX, 'w', encoding='utf-8').write(s)
    print('sections régénérées')
