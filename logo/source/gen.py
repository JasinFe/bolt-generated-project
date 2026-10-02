import math, os
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

OUT = os.environ.get("OUT", "out")
os.makedirs(OUT, exist_ok=True)

NAVY = "#0F2C86"
NAVY_D = "#0A1C5C"
NAVY_L = "#1E50C8"
ORANGE = "#F7A21B"
ORANGE_D = "#EE8A00"
ORANGE_L = "#FFB938"
WHITE = "#FFFFFF"

C = 500


def hex_pts(cx, cy, r):
    return [(cx + r * math.cos(math.radians(90 + 60 * k)), cy - r * math.sin(math.radians(90 + 60 * k))) for k in range(6)]


def rounded_poly(pts, rad):
    n = len(pts)
    d = ""
    for i in range(n):
        p0, p1, p2 = pts[i - 1], pts[i], pts[(i + 1) % n]
        def toward(a, b, t):
            L = math.dist(a, b)
            return (a[0] + (b[0] - a[0]) * t / L, a[1] + (b[1] - a[1]) * t / L)
        a = toward(p1, p0, rad)
        b = toward(p1, p2, rad)
        d += ("M" if i == 0 else "L") + f"{a[0]:.2f},{a[1]:.2f} Q{p1[0]:.2f},{p1[1]:.2f} {b[0]:.2f},{b[1]:.2f} "
    return d + "Z"


DEFS = f"""<defs>
  <linearGradient id="gNavy" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="{NAVY_L}"/><stop offset="1" stop-color="{NAVY_D}"/></linearGradient>
  <linearGradient id="gOrange" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="{ORANGE_L}"/><stop offset="1" stop-color="{ORANGE_D}"/></linearGradient>
  <linearGradient id="gCore" x1="0.15" y1="0" x2="0.85" y2="1">
    <stop offset="0" stop-color="#2358D6"/><stop offset="0.55" stop-color="{NAVY}"/><stop offset="1" stop-color="{NAVY_D}"/></linearGradient>
</defs>"""

# ---------- icons (local coords, box about +-42) ----------
def icon_chart(fg, bg):
    s = f'<rect x="-40" y="14" width="18" height="26" rx="3" fill="{fg}"/>'
    s += f'<rect x="-13" y="0" width="18" height="40" rx="3" fill="{fg}"/>'
    s += f'<rect x="14" y="-14" width="18" height="54" rx="3" fill="{fg}"/>'
    pts = [(-40, -2), (-16, -22), (-2, -12), (24, -34)]
    s += '<polyline points="' + " ".join(f"{x},{y}" for x, y in pts) + f'" fill="none" stroke="{fg}" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>'
    # arrow head
    dx, dy = 1, -26 / 30.0
    L = math.hypot(dx, dy); dx, dy = dx / L, dy / L
    tip = (24 + dx * 14, -34 + dy * 14)
    px, py = -dy, dx
    b = (tip[0] - dx * 18, tip[1] - dy * 18)
    tri = [tip, (b[0] + px * 11, b[1] + py * 11), (b[0] - px * 11, b[1] - py * 11)]
    s += '<polygon points="' + " ".join(f"{x:.1f},{y:.1f}" for x, y in tri) + f'" fill="{fg}" stroke="{fg}" stroke-width="3" stroke-linejoin="round"/>'
    return s


def icon_doc(fg, bg):
    s = f'<path d="M-30,-36 Q-30,-42 -24,-42 H12 L32,-22 V36 Q32,42 26,42 H-24 Q-30,42 -30,36 Z" fill="{fg}"/>'
    s += f'<path d="M12,-42 V-22 H32 Z" fill="{bg}" opacity="0.35"/>'
    for i, w in enumerate([46, 46, 34]):
        s += f'<rect x="-19" y="{-8 + i * 13}" width="{w}" height="6" rx="3" fill="{bg}"/>'
    s += f'<rect x="-19" y="-28" width="20" height="8" rx="3" fill="{ORANGE}"/>'
    return s


def person(cx, top, head, w, fill):
    s = f'<circle cx="{cx}" cy="{top}" r="{head}" fill="{fill}"/>'
    yt = top + head + 5
    s += f'<path d="M{cx - w},{yt + w + 18} L{cx - w},{yt + w} A{w},{w} 0 0 1 {cx + w},{yt + w} L{cx + w},{yt + w + 18} Z" fill="{fill}"/>'
    return s


def icon_team(fg, bg):
    s = person(-25, -14, 9, 15, fg) + person(25, -14, 9, 15, fg)
    # front person with background-colored halo
    s += f'<g stroke="{bg}" stroke-width="5">' + person(0, -20, 12, 20, fg) + '</g>'
    s += person(0, -20, 12, 20, fg)
    return s


def icon_shield(fg, bg):
    s = f'<path d="M0,-44 L34,-31 V-4 C34,20 19,35 0,44 C-19,35 -34,20 -34,-4 V-31 Z" fill="{fg}"/>'
    s += f'<polyline points="-15,1 -4,13 17,-12" fill="none" stroke="{bg}" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>'
    return s


def icon_pie(fg, bg):
    r = 37
    s = f'<path d="M-3,3 L-3,{3 - r} A{r},{r} 0 1 0 {r - 3},3 Z" fill="{fg}"/>'
    s += f'<path d="M4,-4 L4,{-4 - r} A{r},{r} 0 0 1 {4 + r},-4 Z" fill="{ORANGE if fg == WHITE and bg != ORANGE_D else fg}"/>'
    return s


def icon_calc(fg, bg):
    s = f'<rect x="-30" y="-42" width="60" height="84" rx="9" fill="{fg}"/>'
    s += f'<rect x="-21" y="-33" width="42" height="18" rx="3" fill="{bg}"/>'
    for r in range(3):
        for c in range(3):
            col = ORANGE if (r == 2 and c == 2) else bg
            s += f'<rect x="{-21 + c * 16}" y="{-6 + r * 15}" width="10" height="10" rx="2" fill="{col}"/>'
    return s


R_CORE, R_SAT, D = 200, 88, 345
SATS = [  # angle, fill, icon, icon-bg (for cut-outs)
    (90, "url(#gOrange)", icon_chart, ORANGE_D, "Croissance"),
    (30, "url(#gNavy)", icon_doc, NAVY, "Documents"),
    (-30, "url(#gOrange)", icon_team, ORANGE, "Equipe"),
    (-90, "url(#gNavy)", icon_shield, NAVY, "Securite"),
    (-150, "url(#gOrange)", icon_pie, ORANGE, "Analyse"),
    (150, "url(#gNavy)", icon_calc, NAVY, "Comptabilite"),
]


def layer_network(mono=None):
    col = mono or NAVY
    s = f'<circle cx="{C}" cy="{C}" r="{D}" fill="none" stroke="{col}" stroke-width="6" opacity="{1 if mono else 0.9}"/>'
    for k in range(6):
        a = math.radians(60 * k)
        ca, sa = math.cos(a), -math.sin(a)
        x1, y1 = C + 196 * ca, C + 196 * sa
        x2, y2 = C + (D - 12) * ca, C + (D - 12) * sa
        s += f'<line x1="{x1:.1f}" y1="{y1:.1f}" x2="{x2:.1f}" y2="{y2:.1f}" stroke="{col}" stroke-width="6" stroke-linecap="round"/>'
        node = mono or (ORANGE if k % 2 == 0 else NAVY)
        s += f'<circle cx="{C + D * ca:.1f}" cy="{C + D * sa:.1f}" r="15" fill="{node}"/>'
        s += f'<circle cx="{C + D * ca:.1f}" cy="{C + D * sa:.1f}" r="6" fill="{mono and "none" or WHITE}"/>' if not mono else ""
    return s


def fk_monogram(mono=None):
    w = mono or WHITE
    o = mono or ORANGE
    stem = "M-62,-95 H-16 V95 H-62 Z"
    top = "M-16,-95 H72 L60,-53 H-16 Z"
    mid = "M-16,-20 H46 L36,18 H-16 Z"
    leg = "M0,30 H42 L94,95 H52 Z"
    g = f'<g transform="translate({C - 14},{C}) skewX(-11)">'
    g += f'<path d="{stem} {top} {mid}" fill="{w}"/>'
    g += f'<path d="{leg}" fill="{o}"/>'
    return g + "</g>"


def layer_core(mono=None):
    fill = mono or "url(#gCore)"
    s = f'<path d="{rounded_poly(hex_pts(C, C, R_CORE), 22)}" fill="{fill}"/>'
    if not mono:
        p = hex_pts(C, C, R_CORE - 1)
        # subtle light facet on the upper-left half
        facet = [p[0], p[1], p[2], (C + 60, C + 40)]
        s += f'<clipPath id="coreClip"><path d="{rounded_poly(hex_pts(C, C, R_CORE), 22)}"/></clipPath>'
        s += '<path clip-path="url(#coreClip)" d="M' + " L".join(f"{x:.1f},{y:.1f}" for x, y in facet) + 'Z" fill="#FFFFFF" opacity="0.06"/>'
    return s


def layer_sat(i, mono=None):
    ang, fill, icon, ibg, _ = SATS[i]
    a = math.radians(ang)
    cx, cy = C + D * math.cos(a), C - D * math.sin(a)
    s = f'<path d="{rounded_poly(hex_pts(cx, cy, R_SAT), 12)}" fill="{mono or fill}"/>'
    if mono:
        ifg = "#0B1F5E" if mono == WHITE else WHITE
        s += f'<g transform="translate({cx:.1f},{cy:.1f})">{icon(ifg, mono).replace(ORANGE, mono)}</g>'
    else:
        s += f'<g transform="translate({cx:.1f},{cy:.1f})">{icon(WHITE, ibg)}</g>'
    return s


def emblem(mono=None, only=None):
    parts = {"network": layer_network(mono), "core": layer_core(mono)}
    for i in range(6):
        parts[f"sat{i}"] = layer_sat(i, mono)
    parts["monogram"] = fk_monogram(None if not mono else ("#0B1F5E" if mono == WHITE else WHITE))
    if mono:
        # in mono, monogram is knocked out of the core
        pass
    if only:
        return parts[only]
    return "".join(parts.values())


# ---------- wordmark ----------
def text_path(text, fontfile, size, x, y, tracking=0):
    f = TTFont(fontfile)
    gs = f.getGlyphSet(); cmap = f.getBestCmap(); upm = f["head"].unitsPerEm
    sc = size / upm
    out = []
    cx = x
    hm = f["hmtx"]
    for ch in text:
        gn = cmap[ord(ch)]
        pen = SVGPathPen(gs)
        tp = TransformPen(pen, (sc, 0, 0, -sc, cx, y))
        gs[gn].draw(tp)
        out.append(pen.getCommands())
        cx += hm[gn][0] * sc + tracking
    return " ".join(out), cx - x - tracking


FONT = "Mont800.ttf"


def wordmark(x, y, size, mono=None):
    d1, w1 = text_path("Fina", FONT, size, x, y, tracking=-size * 0.01)
    d2, w2 = text_path("Kop", FONT, size, x + w1 + size * 0.01, y, tracking=-size * 0.01)
    c1 = mono or NAVY
    c2 = mono or ORANGE
    return f'<path d="{d1}" fill="{c1}"/><path d="{d2}" fill="{c2}"/>', w1 + w2


def svg(w, h, body, title="FinaKop"):
    return (f'<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" '
            f'viewBox="0 0 {w} {h}" width="{w}" height="{h}"><title>{title}</title>{DEFS}{body}</svg>')


def build():
    files = {}
    files["finakop-icone"] = svg(1000, 1000, emblem())
    files["finakop-icone-blanc"] = svg(1000, 1000, emblem(mono=WHITE))
    files["finakop-icone-marine"] = svg(1000, 1000, emblem(mono=NAVY))

    # horizontal lockup: emblem scaled to 0.42 -> 420px tall
    sc = 0.42
    size = 190
    wm, ww = wordmark(0, 0, size)
    W = int(420 + 40 + ww + 30)
    H = 440
    body = f'<g transform="translate(10,10) scale({sc})">{emblem()}</g>'
    wm, ww = wordmark(470, 220 + size * 0.36, size)
    files["finakop-logo-horizontal"] = svg(W, H, body + wm)
    wmw, _ = wordmark(470, 220 + size * 0.36, size, mono=WHITE)
    files["finakop-logo-horizontal-blanc"] = svg(W, H, f'<g transform="translate(10,10) scale({sc})">{emblem(mono=WHITE)}</g>' + wmw)

    # vertical lockup
    size = 200
    _, ww = wordmark(0, 0, size)
    W = int(max(1000, ww + 80))
    wm, _ = wordmark((W - ww) / 2, 1000 + 40 + size * 0.73, size)
    H = int(1000 + 40 + size * 0.73 + 75)
    body = f'<g transform="translate({(W - 1000) / 2},0)">{emblem()}</g>'
    files["finakop-logo-vertical"] = svg(W, H, body + wm)
    wmw, _ = wordmark((W - ww) / 2, 1000 + 40 + size * 0.73, size, mono=WHITE)
    files["finakop-logo-vertical-blanc"] = svg(W, H, f'<g transform="translate({(W - 1000) / 2},0)">{emblem(mono=WHITE)}</g>' + wmw)

    for k, v in files.items():
        open(f"{OUT}/{k}.svg", "w").write(v)
    return files


if __name__ == "__main__":
    build()
