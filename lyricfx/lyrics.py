"""Lecture des paroles : texte brut, LRC (ligne ou mot à mot), récupération LRCLIB."""

from __future__ import annotations

import json
import re
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path

LRC_TIME = re.compile(r"\[(\d+):(\d+(?:[.:]\d+)?)\]")
LRC_WORD_TIME = re.compile(r"<(\d+):(\d+(?:[.:]\d+)?)>")
LRC_META = re.compile(r"^\[[a-zA-Z]+:.*\]$")
# Lignes de structure à ignorer : [Refrain], (Couplet 2), [Chorus x2]...
SECTION_TAG = re.compile(r"^\s*[\[(].*[\])]\s*$")


@dataclass
class LyricLine:
    tokens: list[str]
    start: float | None = None  # début de ligne (LRC)
    word_starts: list[float] | None = None  # LRC enrichi : début de chaque mot


def _secs(minutes: str, seconds: str) -> float:
    return int(minutes) * 60 + float(seconds.replace(":", "."))


def parse_plain(text: str) -> list[LyricLine]:
    lines = []
    for raw in text.splitlines():
        raw = raw.strip()
        if not raw or SECTION_TAG.match(raw):
            continue
        lines.append(LyricLine(raw.split()))
    return lines


def parse_lrc(text: str) -> list[LyricLine]:
    lines: list[LyricLine] = []
    for raw in text.splitlines():
        raw = raw.strip()
        if not raw or (LRC_META.match(raw) and not LRC_TIME.match(raw)):
            continue
        stamps = [_secs(m, s) for m, s in LRC_TIME.findall(raw)]
        body = LRC_TIME.sub("", raw).strip()
        if not stamps or not body or SECTION_TAG.match(body):
            continue
        word_starts = None
        if LRC_WORD_TIME.search(body):
            # Format enrichi : <00:12.30>mot <00:12.80>mot ...
            tokens, word_starts = [], []
            for part in re.split(r"(?=<\d+:\d+(?:[.:]\d+)?>)", body):
                m = LRC_WORD_TIME.match(part)
                if not m:
                    continue
                words = part[m.end():].split()
                if not words:
                    continue
                t = _secs(m.group(1), m.group(2))
                for w in words:
                    tokens.append(w)
                    word_starts.append(t)
        else:
            tokens = body.split()
        # Une même ligne peut être répétée : [00:10.00][01:20.00]Refrain
        for t in stamps:
            lines.append(LyricLine(tokens, t, word_starts))
    lines.sort(key=lambda l: l.start)
    return lines


def load_lyrics(path: str | Path) -> list[LyricLine]:
    text = Path(path).read_text(encoding="utf-8-sig")
    if Path(path).suffix.lower() == ".lrc" or LRC_TIME.search(text):
        return parse_lrc(text)
    return parse_plain(text)


def fetch_lrclib(query: str, duration: float | None = None) -> str | None:
    """Cherche les paroles sur lrclib.net (API libre). `query` = "Artiste - Titre".

    Renvoie de préférence les paroles synchronisées (LRC), sinon le texte brut.
    """
    params = {"q": query}
    if " - " in query:
        artist, title = query.split(" - ", 1)
        params = {"artist_name": artist.strip(), "track_name": title.strip()}
    url = "https://lrclib.net/api/search?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers={"User-Agent": "lyricfx/0.1 (https://github.com)"})
    with urllib.request.urlopen(req, timeout=20) as resp:
        results = json.loads(resp.read().decode("utf-8"))
    if not results:
        return None
    if duration:
        results.sort(key=lambda r: abs((r.get("duration") or 0) - duration))
    for r in results:
        if r.get("syncedLyrics"):
            return r["syncedLyrics"]
    return results[0].get("plainLyrics")
