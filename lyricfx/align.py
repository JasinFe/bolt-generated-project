"""Alignement des paroles fournies sur les mots horodatés par la reconnaissance vocale."""

from __future__ import annotations

import difflib
import re
import statistics
import unicodedata

from .lyrics import LyricLine
from .model import Line, Word

DEFAULT_RATE = 0.075  # secondes par « poids » (≈ caractère) quand rien n'est connu
MIN_WORD = 0.08
MAX_WORD = 5.0


def normalize(text: str) -> str:
    text = unicodedata.normalize("NFKD", text.lower())
    text = "".join(c for c in text if not unicodedata.combining(c))
    return re.sub(r"[^0-9a-z]+", "", text)


def _weight(text: str) -> float:
    return len(normalize(text)) + 1.5


def align(lyrics: list[LyricLine], asr: list[Word], duration: float | None = None) -> list[Line]:
    """Associe à chaque mot des paroles un début/fin.

    1. correspondance de séquences (difflib) entre paroles et mots reconnus ;
    2. les horodatages LRC (lignes) servent d'ancres et filtrent les faux appariements ;
    3. les mots restants sont interpolés entre leurs voisins connus.
    """
    flat: list[Word] = []
    line_of: list[int] = []
    for li, ll in enumerate(lyrics):
        for ti, tok in enumerate(ll.tokens):
            w = Word(tok)
            if ll.word_starts:
                w.start = ll.word_starts[ti]
            flat.append(w)
            line_of.append(li)
    if not flat:
        return []

    has_word_times = any(ll.word_starts for ll in lyrics)
    if asr and not has_word_times:
        a = [normalize(w.text) for w in flat]
        b = [normalize(w.text) for w in asr]
        sm = difflib.SequenceMatcher(None, a, b, autojunk=False)
        for op, i1, i2, j1, j2 in sm.get_opcodes():
            if op == "equal" or (op == "replace" and i2 - i1 == j2 - j1):
                for k in range(i2 - i1):
                    flat[i1 + k].start = asr[j1 + k].start
                    flat[i1 + k].end = asr[j1 + k].end
            elif op == "replace":
                # Nombre de mots différent : on répartit sur l'étendue reconnue
                _spread(flat[i1:i2], asr[j1].start, asr[j2 - 1].end)

    # Ancres LRC : début de chaque ligne + garde-fou contre les mauvais appariements
    line_first = {}
    for idx, li in enumerate(line_of):
        line_first.setdefault(li, idx)
    starts = [ll.start for ll in lyrics]
    if any(s is not None for s in starts):
        for idx, w in enumerate(flat):
            li = line_of[idx]
            lo = starts[li]
            hi = next((s for s in starts[li + 1:] if s is not None), None)
            if lo is None or has_word_times:
                continue
            if w.start is not None and (w.start < lo - 1.0 or (hi is not None and w.start > hi + 0.5)):
                w.start = w.end = None
        for li, idx in line_first.items():
            if starts[li] is not None and flat[idx].start is None:
                flat[idx].start = starts[li]

    _interpolate(flat, line_of, duration)
    _sanitize(flat, duration)

    lines: list[Line] = []
    for idx, w in enumerate(flat):
        if idx == 0 or line_of[idx] != line_of[idx - 1]:
            lines.append(Line())
        lines[-1].words.append(w)
    return lines


def _spread(words: list[Word], start: float, end: float) -> None:
    total = sum(_weight(w.text) for w in words)
    t = start
    for w in words:
        share = (end - start) * _weight(w.text) / total
        w.start, w.end = t, t + share * 0.92
        t += share


def _rate(words: list[Word]) -> float:
    samples = [(w.end - w.start) / _weight(w.text) for w in words if w.timed and w.end > w.start]
    return statistics.median(samples) if len(samples) >= 5 else DEFAULT_RATE


def _interpolate(flat: list[Word], line_of: list[int], duration: float | None) -> None:
    rate = _rate(flat)
    n = len(flat)
    i = 0
    while i < n:
        if flat[i].timed:
            i += 1
            continue
        j = i
        while j + 1 < n and not flat[j + 1].timed and flat[j + 1].start is None:
            j += 1
        run = flat[i:j + 1]
        natural = sum(_weight(w.text) for w in run) * rate * 1.35
        anchored = run[0].start is not None
        left = run[0].start if anchored else (flat[i - 1].end if i > 0 else None)
        right = flat[j + 1].start if j + 1 < n else duration

        if left is not None and right is not None:
            available = right - left
            if available <= natural * 1.8 or available <= 0:
                a, b = left, max(right, left + len(run) * MIN_WORD)
            elif anchored or (i > 0 and line_of[i] == line_of[i - 1]):
                a, b = left, left + natural
            elif j + 1 < n and line_of[j] == line_of[j + 1]:
                a, b = right - natural, right
            else:
                a = left + (available - natural) / 2
                b = a + natural
        elif left is not None:
            a, b = left, left + natural
        elif right is not None:
            a, b = max(0.0, right - natural), right
        else:
            raise ValueError("Aucun repère temporel : impossible de synchroniser les paroles.")
        _spread(run, a, b)
        i = j + 1


def _sanitize(flat: list[Word], duration: float | None) -> None:
    prev = None
    for w in flat:
        if prev is not None:
            w.start = max(w.start, prev.start + 0.01)
            if prev.end > w.start:
                prev.end = max(prev.start + MIN_WORD / 2, w.start)
        w.end = min(max(w.end, w.start + MIN_WORD), w.start + MAX_WORD)
        if duration:
            w.end = min(w.end, duration)
        prev = w


def group_words(words: list[Word], max_chars: int = 34, max_gap: float = 0.9,
                breaks: set[int] | None = None) -> list[Line]:
    """Regroupe des mots reconnus (sans paroles fournies) en lignes lisibles."""
    lines: list[Line] = []
    cur = Line()
    for idx, w in enumerate(words):
        if cur.words:
            prev = cur.words[-1]
            too_long = len(cur.text) + 1 + len(w.text) > max_chars
            gap = w.start - prev.end > max_gap
            punct = prev.text.rstrip().endswith((".", "!", "?", "…"))
            if too_long or gap or punct or (breaks and idx in breaks):
                lines.append(cur)
                cur = Line()
        cur.words.append(w)
    if cur.words:
        lines.append(cur)
    return lines


def lines_from_lrc_only(lyrics: list[LyricLine], duration: float | None) -> list[Line]:
    """Sans reconnaissance vocale : répartit les mots dans chaque ligne LRC."""
    if any(ll.start is None for ll in lyrics):
        raise ValueError("Paroles non synchronisées : il faut la reconnaissance vocale (Whisper) ou un fichier .lrc.")
    return align(lyrics, [], duration)
