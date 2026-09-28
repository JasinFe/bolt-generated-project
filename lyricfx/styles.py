"""Styles : valeurs par défaut, presets JSON et surcharges en ligne de commande."""

from __future__ import annotations

import copy
import json
from pathlib import Path

PRESETS_DIR = Path(__file__).parent / "presets"

# Toutes les tailles sont exprimées en pixels pour une vidéo de référence 1080p ;
# elles sont mises à l'échelle automatiquement selon la résolution de sortie.
DEFAULTS: dict = {
    "name": "default",
    "description": "",
    # karaoke  : remplissage progressif de la ligne (balayage)
    # highlight: mot courant coloré + « pop »
    # reveal   : les mots apparaissent au moment où ils sont chantés
    # word     : quelques mots à la fois, en gros (style TikTok / Reels)
    "mode": "karaoke",
    "font": "DejaVu Sans",
    "font_size": 80,
    "bold": True,
    "italic": False,
    "uppercase": False,
    "letter_spacing": 0,
    "color_unsung": "#FFFFFF",
    "color_sung": "#29B6F6",
    "color_active": "#FFE600",
    "unsung_opacity": 1.0,
    "outline": 4,
    "outline_color": "#000000",
    "shadow": 0,
    "shadow_color": "#000000",
    "shadow_opacity": 0.6,
    "blur": 0.6,
    "glow": {"enabled": False, "color": "#29B6F6", "size": 8, "blur": 10,
             "opacity": 0.9, "when": "always"},  # when: always | sung
    "box": {"enabled": False, "color": "#000000", "opacity": 0.55, "padding": 18},
    "position": "bottom",  # bottom | center | top
    "align": "center",  # center | left | right | alternate (gauche/droite une ligne sur deux)
    "margin_v": 110,
    "margin_h": 90,
    "tilt": 0,  # inclinaison du texte en degrés
    "tilt_alternate": False,  # alterne +/− d'une ligne à l'autre
    "palette": [],  # couleurs d'accent qui changent à chaque ligne, ex. ["#FFE600", "#00E5FF"]
    # Chœurs / réponses (lignes entre parenthèses dans les paroles)
    "echo": {"scale": 0.8, "italic": True, "color": "#FF6FB1"},
    # Mot actif sur une pastille colorée (style CapCut) — modes highlight / word
    "active_box": {"enabled": False, "color": "#7B2FF7", "size": 14, "text_color": "#FFFFFF"},
    # Décalage RVB façon glitch
    "rgb_split": {"enabled": False, "offset": 5, "opacity": 0.75, "colors": ["#FF1744", "#00E5FF"]},
    "max_chars_per_line": 32,
    "lines_on_screen": 1,  # 2 = affiche aussi la ligne suivante
    "next_line": {"scale": 0.8, "opacity": 0.55, "color": None},
    "words_per_screen": 3,  # mode « word »
    "lead_in": 0.45,  # affichage avant le premier mot (s)
    "tail": 0.4,  # affichage après le dernier mot (s)
    "hold_gap": 1.2,  # si la ligne suivante arrive dans ce délai, on reste affiché
    "animation": {"in": "fade", "out": "fade", "duration": 0.18},  # fade | slide | zoom | pop | none
    "active_scale": 1.15,
    "pop_duration": 0.1,
}


def deep_merge(base: dict, extra: dict) -> dict:
    out = copy.deepcopy(base)
    for k, v in extra.items():
        if isinstance(v, dict) and isinstance(out.get(k), dict):
            out[k] = deep_merge(out[k], v)
        else:
            out[k] = v
    return out


def list_presets() -> dict[str, str]:
    res = {}
    for p in sorted(PRESETS_DIR.glob("*.json")):
        data = json.loads(p.read_text(encoding="utf-8"))
        res[p.stem] = data.get("description", "")
    return res


def load_style(preset: str | None = None, style_files: list[str] | None = None,
               overrides: list[str] | None = None) -> dict:
    style = copy.deepcopy(DEFAULTS)
    if preset:
        path = Path(preset)
        if not path.exists():
            path = PRESETS_DIR / f"{preset}.json"
        if not path.exists():
            raise SystemExit(f"Preset inconnu : {preset}. Disponibles : {', '.join(list_presets())}")
        style = deep_merge(style, json.loads(path.read_text(encoding="utf-8")))
    for f in style_files or []:
        style = deep_merge(style, json.loads(Path(f).read_text(encoding="utf-8")))
    for ov in overrides or []:
        key, _, raw = ov.partition("=")
        if not _:
            raise SystemExit(f"Surcharge invalide « {ov} » (attendu : clé=valeur, ex. glow.color=#FF00FF)")
        try:
            value = json.loads(raw)
        except json.JSONDecodeError:
            value = raw
        target = style
        parts = key.strip().split(".")
        for p in parts[:-1]:
            target = target.setdefault(p, {})
        target[parts[-1]] = value
    return style
