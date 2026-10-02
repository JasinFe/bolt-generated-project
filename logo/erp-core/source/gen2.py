"""FinaKop ERP Core - logo v2 (multicolore, liserés or)."""
import math, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gen import (hex_pts, rounded_poly, text_path, icon_chart, icon_doc, icon_team,
                 icon_shield, icon_pie, icon_calc, WHITE, ORANGE)

HERE = os.path.dirname(os.path.abspath(__file__))
F_BRAND = os.path.join(HERE, "MontI900.ttf")   # Montserrat Black Italic (OFL)
F_TAG = os.path.join(HERE, "Mont700.ttf")      # Montserrat Bold (OFL)

NAVY = "#0F2C86"
NAVY_D = "#0A1C5C"
GOLD = "#E3A73A"
C = 500

# angle, (light, dark), icon, label
SATS = [
    (90, ("#FFB52E", "#E26B00"), icon_chart, "Croissance"),
    (30, ("#1BA79E", "#0A5F5B"), icon_doc, "Documents"),
    (-30, ("#E8504F", "#9C1B28"), icon_team, "Equipe"),
    (-90, ("#2C64E0", "#0D2A85"), icon_shield, "Securite"),
    (-150, ("#9058E0", "#4B2192"), icon_pie, "Analyse"),
    (150, ("#2F7BE8", "#123F9E"), icon_calc, "Comptabilite"),
]

R_CORE, R_SAT, D = 205, 90, 338


def defs():
    s = "<defs>"
    s += f'''<linearGradient id="gGold" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#FFE29A"/><stop offset="0.45" stop-color="{GOLD}"/>
      <stop offset="0.7" stop-color="#B9771A"/><stop offset="1" stop-color="#F4C766"/></linearGradient>
    <linearGradient id="gCore" x1="0.2" y1="0" x2="0.8" y2="1">
      <stop offset="0" stop-color="#2860DA"/><stop offset="0.55" stop-color="{NAVY}"/><stop offset="1" stop-color="{NAVY_D}"/></linearGradient>
    <linearGradient id="gCoreRim" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#2F6BE6"/><stop offset="1" stop-color="#0B2370"/></linearGradient>
    <linearGradient id="gF" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#FFFFFF"/><stop offset="1" stop-color="#DCE5F5"/></linearGradient>
    <linearGradient id="gAcc" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#FFC24A"/><stop offset="1" stop-color="#F06A00"/></linearGradient>
    <linearGradient id="gFina" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#1B4FC4"/><stop offset="1" stop-color="{NAVY_D}"/></linearGradient>
    <linearGradient id="gKop" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#FFB833"/><stop offset="1" stop-color="#EE7400"/></linearGradient>
    <linearGradient id="gLineL" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="{GOLD}" stop-opacity="0"/><stop offset="1" stop-color="{GOLD}"/></linearGradient>
    <linearGradient id="gLineR" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="{GOLD}"/><stop offset="1" stop-color="{GOLD}" stop-opacity="0"/></linearGradient>
    <radialGradient id="gNodeGold" cx="0.35" cy="0.3" r="0.75">
      <stop offset="0" stop-color="#FFF1C4"/><stop offset="0.45" stop-color="#F2B84B"/><stop offset="1" stop-color="#B8730F"/></radialGradient>
    <radialGradient id="gNodeBlue" cx="0.35" cy="0.3" r="0.75">
      <stop offset="0" stop-color="#9CC0FF"/><stop offset="0.45" stop-color="#2D64DC"/><stop offset="1" stop-color="#0B2370"/></radialGradient>
    <linearGradient id="gShine" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#FFFFFF" stop-opacity="0.22"/><stop offset="1" stop-color="#FFFFFF" stop-opacity="0"/></linearGradient>'''
    for i, (_, (l, d), _, _) in enumerate(SATS):
        s += f'<linearGradient id="gS{i}" x1="0.1" y1="0" x2="0.9" y2="1"><stop offset="0" stop-color="{l}"/><stop offset="1" stop-color="{d}"/></linearGradient>'
    s += f'<clipPath id="cCore"><path d="{rounded_poly(hex_pts(C, C, R_CORE - 26), 14)}"/></clipPath>'
    for i, (ang, *_r) in enumerate(SATS):
        cx, cy = sat_center(ang)
        s += f'<clipPath id="cS{i}"><path d="{rounded_poly(hex_pts(cx, cy, R_SAT - 6), 10)}"/></clipPath>'
    return s + "</defs>"


def sat_center(ang):
    a = math.radians(ang)
    return C + D * math.cos(a), C - D * math.sin(a)


def layer_network(mono=None):
    s = ""
    # arcs of the ring between satellites (double line: gold + blue)
    for k in range(6):
        a0 = math.radians(30 + 60 * k + 15)
        a1 = math.radians(30 + 60 * k + 45)
        for r, col, w in ((D, mono or "#1D47B8", 7),):
            x0, y0 = C + r * math.cos(a0), C - r * math.sin(a0)
            x1, y1 = C + r * math.cos(a1), C - r * math.sin(a1)
            s += f'<path d="M{x0:.1f},{y0:.1f} A{r},{r} 0 0 0 {x1:.1f},{y1:.1f}" fill="none" stroke="{col}" stroke-width="{w}" stroke-linecap="round"/>'
    for k in range(6):
        a = math.radians(60 * k)
        x, y = C + D * math.cos(a), C - D * math.sin(a)
        if mono:
            s += f'<circle cx="{x:.1f}" cy="{y:.1f}" r="15" fill="{mono}"/>'
        else:
            g = "gNodeGold" if k % 2 == 0 else "gNodeBlue"
            s += f'<circle cx="{x:.1f}" cy="{y:.1f}" r="16" fill="url(#{g})"/>'
    return s


def layer_core(mono=None):
    if mono:
        s = f'<path d="{rounded_poly(hex_pts(C, C, R_CORE), 24)}" fill="{mono}"/>'
        s += f'<path d="{rounded_poly(hex_pts(C, C, R_CORE - 18), 16)}" fill="none" stroke="{"#0B1F5E" if mono == WHITE else WHITE}" stroke-width="6"/>'
        return s
    s = f'<path d="{rounded_poly(hex_pts(C, C, R_CORE), 24)}" fill="url(#gCoreRim)"/>'
    s += f'<path d="{rounded_poly(hex_pts(C, C, R_CORE - 18), 16)}" fill="url(#gGold)"/>'
    s += f'<path d="{rounded_poly(hex_pts(C, C, R_CORE - 26), 14)}" fill="url(#gCore)"/>'
    # diagonal light sweep
    s += (f'<g clip-path="url(#cCore)"><path d="M{C - 200},{C - 220} L{C + 220},{C - 220} L{C - 200},{C + 120} Z" fill="#FFFFFF" opacity="0.07"/>'
          f'<path d="M{C - 200},{C + 175} L{C + 220},{C - 70} L{C + 220},{C - 40} L{C - 200},{C + 205} Z" fill="#FFFFFF" opacity="0.05"/></g>')
    return s


def layer_sat(i, mono=None):
    ang, (l, d), icon, _ = SATS[i]
    cx, cy = sat_center(ang)
    if mono:
        ifg = "#0B1F5E" if mono == WHITE else WHITE
        s = f'<path d="{rounded_poly(hex_pts(cx, cy, R_SAT), 13)}" fill="{mono}"/>'
        return s + f'<g transform="translate({cx:.1f},{cy:.1f}) scale(0.92)">{icon(ifg, mono).replace(ORANGE, mono)}</g>'
    s = f'<path d="{rounded_poly(hex_pts(cx, cy, R_SAT), 13)}" fill="url(#gGold)"/>'
    s += f'<path d="{rounded_poly(hex_pts(cx, cy, R_SAT - 6), 10)}" fill="url(#gS{i})"/>'
    s += f'<g clip-path="url(#cS{i})"><rect x="{cx - 100:.1f}" y="{cy - 100:.1f}" width="200" height="85" fill="url(#gShine)"/></g>'
    acc = icon(WHITE, d)
    if i == 0:  # orange tile: keep orange accents readable
        acc = acc.replace(ORANGE, d)
    s += f'<g transform="translate({cx:.1f},{cy:.1f}) scale(0.92)">{acc}</g>'
    return s


def round_path(pts):
    """Closed path through (x, y, radius) vertices, each corner softened by a quadratic curve."""
    n = len(pts)
    d = ""
    for i in range(n):
        (x0, y0, _), (x1, y1, r), (x2, y2, _) = pts[i - 1], pts[i], pts[(i + 1) % n]
        l0, l2 = math.hypot(x0 - x1, y0 - y1), math.hypot(x2 - x1, y2 - y1)
        r = min(r, l0 / 2, l2 / 2)
        a = (x1 + (x0 - x1) * r / l0, y1 + (y0 - y1) * r / l0)
        b = (x1 + (x2 - x1) * r / l2, y1 + (y2 - y1) * r / l2)
        d += ("M" if i == 0 else "L") + f"{a[0]:.2f},{a[1]:.2f} Q{x1},{y1} {b[0]:.2f},{b[1]:.2f} "
    return d + "Z"


# Calligraphic F (after the original model): one continuous shape, large rounded
# top-left shoulder, slanted bar ends, diagonal cut at the foot of the stem.
F_SHAPE = round_path([
    (-74, -96, 46),   # top-left shoulder
    (94, -96, 8),     # top bar, right end
    (80, -54, 8),
    (-16, -54, 12),   # inner corner (fillet)
    (-18, -14, 10),
    (62, -14, 7),     # middle bar, right end
    (50, 22, 7),
    (-22, 22, 10),    # inner corner (fillet)
    (-28, 84, 6),     # stem foot, cut on the diagonal
    (-92, 112, 8),
])
F_WEDGE = "M-13,32 H50 L-13,96 Z"            # orange wedge tucked under the middle bar
F_WEDGE_HI = "M-13,32 H50 L6,56 Z"           # lighter facet


def layer_monogram(mono=None):
    w = "url(#gF)" if not mono else ("#0B1F5E" if mono == WHITE else WHITE)
    g = f'<g transform="translate({C - 2},{C - 6}) scale(1.12) skewX(-10)">'
    if not mono:
        g += f'<path d="{F_SHAPE}" fill="#06143F" opacity="0.35" transform="translate(6,7)"/>'
        g += f'<path d="{F_SHAPE}" fill="{w}"/>'
        g += f'<path d="{F_WEDGE}" fill="url(#gAcc)"/><path d="{F_WEDGE_HI}" fill="#FFD25A" opacity="0.55"/>'
    else:
        g += f'<path d="{F_SHAPE}" fill="{w}"/><path d="{F_WEDGE}" fill="{w}"/>'
    return g + "</g>"


ORDER = ["network", "core"] + [f"sat{i}" for i in range(6)] + ["monogram"]
NAMES = {"network": "Reseau", "core": "Hexagone central", "monogram": "Monogramme F"}
NAMES.update({f"sat{i}": f"Hexagone - {s[3]}" for i, s in enumerate(SATS)})


def emblem(mono=None, only=None):
    parts = {"network": layer_network(mono), "core": layer_core(mono), "monogram": layer_monogram(mono)}
    for i in range(6):
        parts[f"sat{i}"] = layer_sat(i, mono)
    if only:
        return parts[only]
    return "".join(parts[k] for k in ORDER)


def wordmark_parts(x, base, size, mono=None):
    tr = -size * 0.015
    d1, w1 = text_path("Fina", F_BRAND, size, x, base, tracking=tr)
    d2, w2 = text_path("Kop", F_BRAND, size, x + w1 + size * 0.02, base, tracking=tr)
    f1 = mono or "url(#gFina)"
    f2 = mono or "url(#gKop)"
    return [("Texte - Fina", f'<path d="{d1}" fill="{f1}"/>'), ("Texte - Kop", f'<path d="{d2}" fill="{f2}"/>')], w1 + w2 + size * 0.02


def tagline_parts(cx, base, size, mono=None, line=150):
    track = size * 0.42
    _, w = text_path("ERP CORE", F_TAG, size, 0, 0, tracking=track)
    d, _ = text_path("ERP CORE", F_TAG, size, cx - w / 2, base, tracking=track)
    col = mono or NAVY
    y = base - size * 0.36
    gap = size * 0.9
    lx1, lx2 = cx - w / 2 - gap - line, cx - w / 2 - gap
    rx1, rx2 = cx + w / 2 + gap, cx + w / 2 + gap + line
    lf, rf = (mono, mono) if mono else ("url(#gLineL)", "url(#gLineR)")
    lines = (f'<rect x="{lx1:.1f}" y="{y - 2.5:.1f}" width="{line}" height="5" rx="2.5" fill="{lf}"/>'
             f'<rect x="{rx1:.1f}" y="{y - 2.5:.1f}" width="{line}" height="5" rx="2.5" fill="{rf}"/>')
    return [("Slogan - ERP CORE", f'<path d="{d}" fill="{col}"/>'), ("Slogan - filets or", lines)]


def svg(w, h, body, title="FinaKop ERP Core"):
    return (f'<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" '
            f'viewBox="0 0 {w} {h}" width="{w}" height="{h}"><title>{title}</title>{defs()}{body}</svg>')


def layouts(mono=None):
    """Return {name: (w, h, [(layer_name, svg_body), ...])}."""
    out = {}
    em = lambda tf: [(NAMES[k], f'<g transform="{tf}">{emblem(mono, k)}</g>') for k in ORDER]

    out["icone"] = (1000, 1000, em("translate(0,0)"))

    # vertical: emblem 1000 + wordmark + tagline
    size = 230
    _, ww = wordmark_parts(0, 0, size)
    W = int(max(1000, ww + 120))
    base = 1000 + size * 0.78
    wm, _ = wordmark_parts((W - ww) / 2, base, size, mono)
    tg = tagline_parts(W / 2, base + 120, 62, mono, line=170)
    out["logo-vertical"] = (W, int(base + 165), em(f"translate({(W - 1000) / 2},0)") + wm + tg)

    # horizontal: emblem left, wordmark + tagline stacked right
    sc = 0.46
    size = 200
    _, ww = wordmark_parts(0, 0, size)
    x0 = 1000 * sc + 50
    W = int(x0 + ww + 40)
    H = int(1000 * sc + 20)
    base = H / 2 + size * 0.22
    wm, _ = wordmark_parts(x0, base, size, mono)
    tg = tagline_parts(x0 + ww / 2, base + 92, 50, mono, line=110)
    out["logo-horizontal"] = (W, H, em(f"translate(10,10) scale({sc})") + wm + tg)
    return out


def build(outdir):
    os.makedirs(outdir, exist_ok=True)
    files = {}
    for suffix, mono in (("", None), ("-blanc", WHITE), ("-marine", NAVY)):
        for name, (w, h, layers) in layouts(mono).items():
            if suffix == "-marine" and name != "icone":
                continue
            key = f"finakop-erp-{name}{suffix}"
            files[key] = svg(w, h, "".join(b for _, b in layers))
            open(f"{outdir}/{key}.svg", "w").write(files[key])
    return files


if __name__ == "__main__":
    build(sys.argv[1] if len(sys.argv) > 1 else "out2")
