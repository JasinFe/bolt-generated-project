"""Génération du fichier de sous-titres ASS (karaoké, surlignage, glow, animations)."""

from __future__ import annotations

from .model import Line, Word

ALIGN = {"bottom": 2, "center": 5, "top": 8}


# --------------------------------------------------------------------------- couleurs / temps
def _rgb(color: str) -> tuple[int, int, int]:
    c = color.lstrip("#")
    if len(c) == 3:
        c = "".join(ch * 2 for ch in c)
    return int(c[0:2], 16), int(c[2:4], 16), int(c[4:6], 16)


def _alpha(opacity: float) -> int:
    return max(0, min(255, round((1 - float(opacity)) * 255)))


def style_color(color: str, opacity: float = 1.0) -> str:
    r, g, b = _rgb(color)
    return f"&H{_alpha(opacity):02X}{b:02X}{g:02X}{r:02X}"


def tag_color(color: str) -> str:
    r, g, b = _rgb(color)
    return f"&H{b:02X}{g:02X}{r:02X}&"


def tag_alpha(opacity: float) -> str:
    return f"&H{_alpha(opacity):02X}&"


def cs(t: float) -> int:
    return max(0, round(t * 100))


def ts(c: int) -> str:
    h, rem = divmod(c, 360000)
    m, rem = divmod(rem, 6000)
    s, cc = divmod(rem, 100)
    return f"{h}:{m:02d}:{s:02d}.{cc:02d}"


def _escape(text: str) -> str:
    return text.replace("\\", "").replace("{", "(").replace("}", ")")


# --------------------------------------------------------------------------- mise en page
def split_long(line: Line, max_chars: int) -> list[Line]:
    """Coupe une ligne trop longue en deux (récursivement), au point le plus équilibré."""
    if len(line.text) <= max_chars or len(line.words) < 2:
        return [line]
    best, best_score = 1, None
    for i in range(1, len(line.words)):
        left = len(Line(line.words[:i]).text)
        right = len(Line(line.words[i:]).text)
        score = max(left, right)
        if line.words[i - 1].text.endswith((",", ";", ":", ".", "!", "?")):
            score -= 4  # on préfère couper après une ponctuation
        if best_score is None or score < best_score:
            best, best_score = i, score
    return split_long(Line(line.words[:best]), max_chars) + split_long(Line(line.words[best:]), max_chars)


def chunk(line: Line, n: int, max_gap: float = 0.7) -> list[Line]:
    out: list[Line] = []
    cur: list[Word] = []
    for w in line.words:
        if cur and (len(cur) >= n or w.start - cur[-1].end > max_gap):
            out.append(Line(cur))
            cur = []
        cur.append(w)
    if cur:
        out.append(Line(cur))
    return out


def layout(lines: list[Line], style: dict) -> list[Line]:
    out: list[Line] = []
    for line in lines:
        if style["mode"] == "word":
            out.extend(chunk(line, int(style["words_per_screen"])))
        else:
            out.extend(split_long(line, int(style["max_chars_per_line"])))
    return out


def windows(lines: list[Line], lead: float, tail: float, hold: float) -> list[list[int]]:
    """Fenêtre d'affichage [début, fin] (centisecondes) de chaque ligne, sans chevauchement."""
    res = [[cs(max(0.0, l.start - lead)), cs(l.end + tail)] for l in lines]
    for i in range(len(res) - 1):
        cur, nxt = res[i], res[i + 1]
        sung_end, next_first = cs(lines[i].end), cs(lines[i + 1].start)
        if cur[1] > nxt[0]:
            cut = min(max(sung_end, nxt[0]), next_first)
            cur[1] = nxt[0] = cut
        elif nxt[0] - cur[1] < cs(hold):
            cur[1] = nxt[0]
    return [r for r in res]


# --------------------------------------------------------------------------- écriture ASS
class AssWriter:
    def __init__(self, style: dict, width: int, height: int):
        self.st = style
        self.w, self.h = width, height
        self.k = min(width, height) / 1080
        s = style
        self.fs = round(s["font_size"] * self.k)
        self.an = ALIGN.get(s["position"], 2)
        self.glow = s["glow"]["enabled"]
        self.box = s["box"]["enabled"]
        self.two = int(s["lines_on_screen"]) >= 2 and s["mode"] != "word"
        self.x = width // 2
        line_h = self.fs * 1.25
        next_h = line_h * s["next_line"]["scale"]
        gap = self.fs * 0.15
        mv = s["margin_v"] * self.k
        if self.an == 2:
            self.y_next = height - mv
            self.y_cur = self.y_next - next_h - gap if self.two else height - mv
        elif self.an == 8:
            self.y_cur = mv
            self.y_next = mv + line_h + gap
        else:
            self.y_cur = height / 2 - ((next_h + gap) / 2 if self.two else 0)
            self.y_next = height / 2 + (line_h + gap) / 2
        self.slide_from = self.y_next if self.two else self.y_cur + line_h * 0.6

    # ---- en-tête
    def header(self) -> str:
        s, k = self.st, self.k
        box = s["box"]
        outline = s["outline"] * k
        outline_col = style_color(s["outline_color"])
        shadow = s["shadow"] * k
        bold = -1 if s["bold"] else 0
        italic = -1 if s["italic"] else 0
        spacing = s["letter_spacing"] * k

        def style_line(name, size, primary, secondary, outline_c, outline_w, shadow_w, border_style=1):
            return (f"Style: {name},{s['font']},{size},{primary},{secondary},{outline_c},"
                    f"{style_color(s['shadow_color'], s['shadow_opacity'])},{bold},{italic},0,0,100,100,"
                    f"{spacing:.1f},0,{border_style},{outline_w:.1f},{shadow_w:.1f},{self.an},0,0,0,1")

        styles = [
            style_line("Main", self.fs, style_color(s["color_sung"]),
                       style_color(s["color_unsung"], s["unsung_opacity"]), outline_col, outline, shadow),
        ]
        g = s["glow"]
        styles.append(style_line("Glow", self.fs, "&HFF000000", "&HFF000000",
                                 style_color(g["color"], g["opacity"]), g["size"] * k, 0))
        nl = s["next_line"]
        styles.append(style_line("Next", round(self.fs * nl["scale"]),
                                 style_color(nl["color"] or s["color_unsung"], nl["opacity"]),
                                 style_color(nl["color"] or s["color_unsung"], nl["opacity"]),
                                 style_color(s["outline_color"], nl["opacity"]), outline * nl["scale"], 0))
        # Bandeau : couche séparée (texte invisible, seul le fond « BorderStyle 3 » est dessiné)
        styles.append(style_line("Box", self.fs, "&HFF000000", "&HFF000000",
                                 style_color(box["color"], box["opacity"]), box["padding"] * k, 0,
                                 border_style=3))
        return "\n".join([
            "[Script Info]",
            "; Généré par lyricfx",
            "ScriptType: v4.00+",
            f"PlayResX: {self.w}",
            f"PlayResY: {self.h}",
            "WrapStyle: 2",
            "ScaledBorderAndShadow: yes",
            "YCbCr Matrix: None",
            "",
            "[V4+ Styles]",
            "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, "
            "Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, "
            "Shadow, Alignment, MarginL, MarginR, MarginV, Encoding",
            *styles,
            "",
            "[Events]",
            "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text",
        ])

    # ---- utilitaires texte
    def _word(self, w: Word, last: bool) -> str:
        t = _escape(w.text.upper() if self.st["uppercase"] else w.text)
        return t if last else t + " "

    def _prefix(self, first: bool, last: bool, glow: bool, y: float | None = None,
                slide: bool = True, fade_in: bool = True, fade_out: bool = True) -> str:
        s, a = self.st, self.st["animation"]
        y = self.y_cur if y is None else y
        d = round(a["duration"] * 1000)
        tags = [f"\\an{self.an}"]
        if first and a["in"] == "slide" and slide:
            tags.append(f"\\move({self.x},{self.slide_from:.0f},{self.x},{y:.0f},0,{d})")
        else:
            tags.append(f"\\pos({self.x},{y:.0f})")
        fin = d if first and fade_in and a["in"] != "none" else 0
        fout = d if last and fade_out and a["out"] != "none" else 0
        if fin or fout:
            tags.append(f"\\fad({fin},{fout})")
        if first and a["in"] in ("zoom", "pop"):
            start = 70 if a["in"] == "zoom" else 135
            tags.append(f"\\fscx{start}\\fscy{start}\\t(0,{d},\\fscx100\\fscy100)")
        blur = s["glow"]["blur"] * self.k if glow else s["blur"]
        if blur:
            tags.append(f"\\blur{blur:g}")
        return "{" + "".join(tags) + "}"

    def _event(self, layer: int, start: int, end: int, style: str, text: str) -> str:
        return f"Dialogue: {layer},{ts(start)},{ts(end)},{style},,0,0,0,,{text}"

    # ---- mode karaoké (balayage \kf)
    def _karaoke_text(self, line: Line, start: int, glow: bool) -> str:
        sweep = "\\ko" if glow and self.st["glow"]["when"] == "sung" else "\\kf"
        parts, pos = [], start
        for i, w in enumerate(line.words):
            ws, we = cs(w.start), cs(w.end)
            if ws > pos:
                parts.append(f"{{\\k{ws - pos}}}")
                pos = ws
            dur = max(we - pos, 1)
            parts.append(f"{{{sweep}{dur}}}{self._word(w, i == len(line.words) - 1)}")
            pos += dur
        return "".join(parts)

    # ---- modes highlight / reveal / word (un évènement par mot actif)
    def _state_text(self, line: Line, k: int, glow: bool) -> str:
        s = self.st
        reveal = s["mode"] == "reveal"
        scale = round(s["active_scale"] * 100)
        p = round(s["pop_duration"] * 1000)
        g = s["glow"]
        out = []
        for j, w in enumerate(line.words):
            tags = ""
            if j < k:
                tags = (f"\\3c{tag_color(g['color'])}\\3a{tag_alpha(g['opacity'])}" if glow
                        else f"\\1c{tag_color(s['color_sung'])}\\1a&H00&")
            elif j == k:
                tags = (f"\\3c{tag_color(s['color_active'])}\\3a{tag_alpha(g['opacity'])}" if glow
                        else f"\\1c{tag_color(s['color_active'])}\\1a&H00&")
                if scale != 100:
                    # « pop » : léger dépassement puis retour à la taille active
                    tags += (f"\\fscx100\\fscy100\\t(0,{p},\\fscx{scale + 8}\\fscy{scale + 8})"
                             f"\\t({p},{2 * p},\\fscx{scale}\\fscy{scale})")
            else:
                if j == k + 1 and scale != 100 and k >= 0:
                    tags += "\\fscx100\\fscy100"
                if reveal:
                    tags += "\\alpha&HFF&"
                elif glow:
                    tags += f"\\3c{tag_color(g['color'])}"
                    tags += "\\3a&HFF&" if g["when"] == "sung" else f"\\3a{tag_alpha(g['opacity'])}"
                else:
                    tags += f"\\1c{tag_color(s['color_unsung'])}\\1a{tag_alpha(s['unsung_opacity'])}"
            out.append("{" + tags + "}" + self._word(w, j == len(line.words) - 1))
        return "".join(out)

    def _states(self, line: Line, start: int, end: int) -> list[tuple[int, int, int]]:
        """Liste (k, début, fin) : k = index du mot actif (-1 avant, n après)."""
        n = len(line.words)
        marks = [(-1, start)] + [(j, cs(w.start)) for j, w in enumerate(line.words)]
        last_end = cs(line.words[-1].end)
        if end - last_end > 30:
            marks.append((n, last_end))
        res = []
        for idx, (k, t) in enumerate(marks):
            t_next = marks[idx + 1][1] if idx + 1 < len(marks) else end
            a, b = max(t, start), min(t_next, end)
            if b > a:
                res.append((k, a, b))
        return res

    # ---- assemblage
    def build(self, lines: list[Line]) -> str:
        s = self.st
        disp = layout(lines, s)
        wins = windows(disp, s["lead_in"], s["tail"], s["hold_gap"])
        events: list[str] = []
        for i, (line, (start, end)) in enumerate(zip(disp, wins)):
            if end <= start:
                continue
            # Pas de fondu entre deux lignes qui s'enchaînent sans pause (évite le clignotement)
            fx = {"fade_in": i == 0 or wins[i - 1][1] < start,
                  "fade_out": i + 1 == len(disp) or wins[i + 1][0] > end}
            if self.box:
                plain = "".join(self._word(w, j == len(line.words) - 1) for j, w in enumerate(line.words))
                events.append(self._event(0, start, end, "Box", self._prefix(True, True, False, **fx) + plain))
            layers = [(1, "Glow", True)] if self.glow else []
            layers.append((2, "Main", False))
            if s["mode"] == "karaoke":
                for layer, name, glow in layers:
                    text = self._prefix(True, True, glow, **fx) + self._karaoke_text(line, start, glow)
                    events.append(self._event(layer, start, end, name, text))
            else:
                states = self._states(line, start, end)
                for si, (k, a, b) in enumerate(states):
                    for layer, name, glow in layers:
                        text = self._prefix(si == 0, si == len(states) - 1, glow, **fx) + \
                            self._state_text(line, k, glow)
                        events.append(self._event(layer, a, b, name, text))
            # Aperçu de la ligne suivante
            if self.two and i + 1 < len(disp):
                nxt = disp[i + 1]
                txt = "".join(self._word(w, j == len(nxt.words) - 1) for j, w in enumerate(nxt.words))
                prefix = self._prefix(True, True, False, y=self.y_next, slide=False)
                events.append(self._event(2, start, end, "Next", prefix + txt))
        return self.header() + "\n" + "\n".join(events) + "\n"


def build_ass(lines: list[Line], style: dict, width: int, height: int) -> str:
    return AssWriter(style, width, height).build(lines)
