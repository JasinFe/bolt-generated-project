#!/usr/bin/env python3
"""Sections « Packs métier » et « Éditions » du site vitrine, générées depuis les
données réelles du produit (donnees-produit.json, exporté de FKC_AidePacks et
FKC_Plans). Remplace les blocs balisés dans ../site-vitrine/index.html.

    python3 sections.py
"""
import html, json, os, re

ICI = os.path.dirname(os.path.abspath(__file__))
INDEX = os.path.join(ICI, '..', 'site-vitrine', 'index.html')
D = json.load(open(os.path.join(ICI, 'donnees-produit.json'), encoding='utf-8'))
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
CAPS = [
    ('consolidation', 'Consolidation'), ('inter_societes', 'Opérations inter-sociétés'), ('dashboards_consolides', 'Tableaux de bord consolidés'),
    ('etats_consolides', 'États consolidés'), ('api_keys', 'Clés API'), ('workflow', 'Circuits de validation'), ('bi_avancee', 'BI avancée'),
    ('multi_pays', 'Multi-pays'), ('multi_devises', 'Multi-devises'), ('multi_plans', 'Multi-plans comptables'), ('gouvernance', 'Gouvernance'),
    ('audit_centralise', 'Audit centralisé'), ('portails', 'Portails'), ('data_warehouse', 'Entrepôt de données'), ('replication', 'Réplication'),
    ('haute_dispo', 'Haute disponibilité'), ('support_premium', 'Support premium'), ('sauvegardes', 'Sauvegardes renforcées'),
    ('portail_artiste', 'Portail artiste'), ('distribution', 'Distribution musicale'), ('publishing', 'Publishing / édition'),
]
CAPVAL = {'simple': 'simple', 'complete': 'complète', 'basique': 'basique', 'quotidiennes': 'quotidiennes', 'quotidiennes+replication': 'quotidiennes + réplication', 'personnalisees': 'personnalisées'}
T = D['tiers']


def lim(v, unite=''):
    if v == -1:
        return 'Illimité'
    s = '{:,}'.format(v).replace(',', ' ')
    return s + unite


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
    return ''.join(out) or '<li class="muet">Les capacités avancées arrivent avec Business et Pro.</li>'


EDITIONS = [
    ('starter', 'Démarrer', 'Starter', "L'essentiel pour vendre et tenir vos comptes", "Commerces, TPE, indépendants : une société, une équipe réduite.",
     "Dès le premier jour : comptabilité SYSCOHADA, facturation normalisée, stock, caisse et scan. Tout ce qu'il faut pour vendre, encaisser et tenir des comptes justes, sans rien installer.", None),
    ('business', 'Croître', 'Business', 'Pour les PME qui se développent', "PME avec plusieurs sites ou plusieurs sociétés, qui veulent piloter leurs marges et leurs impayés.",
     "Tout Starter, plus la comptabilité analytique, la fiscalité et les déclarations DGI, et le recouvrement des créances. Jusqu'à 3 sociétés et 10 établissements, avec une première consolidation.", 'starter'),
    ('pro', 'Performer', 'Pro', 'Les outils complets des structures exigeantes', "Groupes et entreprises structurées : équipes nombreuses, filiales, besoin d'intégrations.",
     "Tout Business, plus les ressources humaines et l'API pour connecter vos autres outils. Utilisateurs illimités, jusqu'à 10 sociétés, consolidation complète, opérations inter-sociétés et états consolidés.", 'business'),
]


def section_editions():
    cartes, panneaux = [], []
    def carte(i, cle, kick, nom, accroche, pop=False):
        cartes.append('<button class="ed%s%s" data-ed="%s" role="tab" aria-selected="%s"><small>%s</small><b>%s</b><span>%s</span></button>' % (
            ' actif' if i == 0 else '', ' pop' if pop else '', cle, 'true' if i == 0 else 'false', kick, nom, e(accroche)))
    for i, (t, kick, nom, accroche, ideal, desc, base) in enumerate(EDITIONS):
        carte(i, t, kick, nom, accroche, t == 'pro')
        panneaux.append('''<div class="edp%s" data-ed="%s" role="tabpanel"><div class="edp-g">
  <span class="kicker">%s</span><h3>FinaKop %s</h3><p class="edp-acc">%s</p><p>%s</p>
  <p class="edp-ideal"><b>Idéal pour :</b> %s</p>
  <div class="chfs">%s</div>
  <a class="btn btn-or" href="#contact" data-edition="%s">Demander un devis %s →</a></div>
  <div class="edp-d"><h4>Modules inclus</h4><ul class="mods">%s</ul><h4>Capacités</h4><ul class="caps">%s</ul></div></div>''' % (
            ' actif' if i == 0 else '', t, kick, nom, e(accroche), e(desc), e(ideal), chiffres(t, CLES), nom, nom,
            modules_html(t, base), caps_html(t)))
    # Entreprise : trois niveaux
    carte(3, 'entreprise', 'Grands comptes', 'Entreprise', 'Standard, Avancée ou Illimitée')
    niv = [('enterprise_standard', 'Standard'), ('enterprise_avancee', 'Avancée'), ('enterprise_illimitee', 'Illimitée')]
    lignes = ''
    for k, ic, lab, unit in CLES + [('api', '🔌', 'appels API / mois', '')]:
        lignes += '<tr><th>%s %s</th>%s</tr>' % (ic, lab.capitalize(), ''.join('<td>%s</td>' % lim(T[t]['limits'].get(k, 0), unit) for t, _ in niv))
    for code, lab in CAPS[:18]:
        vals = [T[t]['caps'].get(code) for t, _ in niv]
        if not any(vals):
            continue
        lignes += '<tr><th>%s</th>%s</tr>' % (e(lab), ''.join('<td>%s</td>' % (('✓ ' + CAPVAL.get(v, '')).strip() if isinstance(v, str) else ('✓' if v else '—')) for v in vals))
    panneaux.append('''<div class="edp" data-ed="entreprise" role="tabpanel"><div class="edp-g">
  <span class="kicker">Grands comptes</span><h3>FinaKop Entreprise</h3><p class="edp-acc">Pour les groupes, réseaux et organisations multi-pays</p>
  <p>Tout Pro, plus la <b>paie</b>, les circuits de validation et la BI avancée. Trois niveaux selon la taille de votre organisation : jusqu'au multi-pays, multi-devises et multi-plans comptables, avec gouvernance, audit centralisé, réplication et haute disponibilité.</p>
  <p class="edp-ideal"><b>Idéal pour :</b> groupes, réseaux de points de vente, organisations présentes dans plusieurs pays.</p>
  <ul class="mods mods-court">%s</ul>
  <a class="btn btn-or" href="#contact" data-edition="Entreprise">Demander un devis Entreprise →</a></div>
  <div class="edp-d"><h4>Les trois niveaux</h4><div class="tab-w"><table class="niv"><thead><tr><th></th>%s</tr></thead><tbody>%s</tbody></table></div></div></div>''' % (
        modules_html('enterprise_standard', 'pro'), ''.join('<th>%s</th>' % n for _, n in niv), lignes))
    # Creative Suite
    carte(4, 'creative', 'Industries créatives', 'Creative Suite', 'Labels, studios, artistes, médias')
    cniv = [('creative_starter', 'Starter'), ('creative_business', 'Business'), ('creative_pro', 'Pro'), ('creative_enterprise_illimitee', 'Enterprise')]
    lc = ''
    for k, ic, lab, unit in CLES_CR + [('contrats', '📝', 'contrats', ''), ('royalties', '💿', 'calculs de royalties / mois', '')]:
        lc += '<tr><th>%s %s</th>%s</tr>' % (ic, lab.capitalize(), ''.join('<td>%s</td>' % lim(T[t]['limits'].get(k, 0), unit) for t, _ in cniv))
    for code, lab in [('portail_artiste', 'Portail artiste'), ('distribution', 'Distribution musicale'), ('publishing', 'Publishing / édition')]:
        vals = [T[t]['caps'].get(code) for t, _ in cniv]
        lc += '<tr><th>%s</th>%s</tr>' % (e(lab), ''.join('<td>%s</td>' % (('✓ ' + CAPVAL.get(v, '')).strip() if isinstance(v, str) else ('✓' if v else '—')) for v in vals))
    panneaux.append('''<div class="edp" data-ed="creative" role="tabpanel"><div class="edp-g">
  <span class="kicker">Industries créatives</span><h3>FinaKop Creative Suite</h3><p class="edp-acc">La gestion complète des métiers de la création</p>
  <p>Toute la gestion FinaKop, plus le module <b>Royalties & droits</b> : artistes, catalogue d'œuvres, contrats, calcul et reversement des royalties, portail artiste, distribution et publishing selon le niveau.</p>
  <p class="edp-ideal"><b>Idéal pour :</b> labels, studios, artistes et producteurs, médias, maisons d'édition, événementiel, gestion collective des droits.</p>
  <a class="btn btn-or" href="#contact" data-edition="Creative Suite">Demander un devis Creative Suite →</a></div>
  <div class="edp-d"><h4>Quatre niveaux</h4><div class="tab-w"><table class="niv"><thead><tr><th></th>%s</tr></thead><tbody>%s</tbody></table></div></div></div>''' % (
        ''.join('<th>%s</th>' % n for _, n in cniv), lc))
    # Comparatif complet
    cols = [('starter', 'Starter'), ('business', 'Business'), ('pro', 'Pro'), ('enterprise_standard', 'Entreprise')]
    comp = ''
    for code, ic, lab in MODULES[:12]:
        comp += '<tr><th>%s %s</th>%s</tr>' % (ic, e(lab), ''.join('<td>%s</td>' % ('✓' if code in T[t]['modules'] else '—') for t, _ in cols))
    for k, ic, lab, unit in CLES:
        vals = [lim(T[t]['limits'].get(k, 0), unit) for t, _ in cols]
        vals[3] = vals[3] + ' → ' + lim(T['enterprise_illimitee']['limits'].get(k, 0), unit)
        comp += '<tr><th>%s %s</th>%s</tr>' % (ic, lab.capitalize(), ''.join('<td>%s</td>' % v for v in vals))
    for code, lab in [('consolidation', 'Consolidation'), ('inter_societes', 'Inter-sociétés'), ('api_keys', 'Clés API'), ('workflow', 'Circuits de validation'), ('bi_avancee', 'BI avancée')]:
        comp += '<tr><th>%s</th>%s</tr>' % (lab, ''.join('<td>%s</td>' % (('✓ ' + CAPVAL.get(T[t]['caps'].get(code), '')).strip() if isinstance(T[t]['caps'].get(code), str) else ('✓' if T[t]['caps'].get(code) else '—')) for t, _ in cols))
    return '''<!-- ═══════════ ÉDITIONS ═══════════ -->
<section id="editions" style="background:linear-gradient(180deg,transparent,rgba(76,125,255,.05),transparent)">
  <div class="wrap">
    <div class="titre rv"><span class="kicker">Éditions</span><h2>Une édition <span class="grad">pour chaque étape</span></h2><p>Commencez simplement, puis élargissez vos modules et vos métiers sans changer d'outil ni ressaisir vos données. Cliquez sur une édition pour voir ce qu'elle contient. Tarif sur devis.</p></div>
    <div class="eds rv" role="tablist" aria-label="Éditions">%s</div>
    <div class="edps rv">%s</div>
    <details class="comparatif rv"><summary>📊 Comparer les éditions en détail</summary><div class="tab-w"><table class="niv comp"><thead><tr><th></th>%s</tr></thead><tbody>%s</tbody></table></div>
      <p class="mention" style="text-align:left">Votre pack métier s'ajoute à l'édition choisie. Les quotas peuvent être ajustés sur devis.</p></details>
  </div>
</section>

''' % (''.join(cartes), ''.join(panneaux), ''.join('<th>%s</th>' % n for _, n in cols), comp)


if __name__ == '__main__':
    s = open(INDEX, encoding='utf-8').read()
    a = s.index('<!-- ═══════════ MÉTIERS ═══════════ -->'); b = s.index('<!-- ═══════════ SÉCURITÉ ═══════════ -->')
    s = s[:a] + section_metiers() + s[b:]
    a = s.index('<!-- ═══════════ ÉDITIONS ═══════════ -->'); b = s.index('<!-- ═══════════ DÉMARRAGE ═══════════ -->')
    s = s[:a] + section_editions() + s[b:]
    open(INDEX, 'w', encoding='utf-8').write(s)
    print('sections régénérées')
