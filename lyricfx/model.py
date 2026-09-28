"""Structures de données : mots et lignes horodatés + (dé)sérialisation JSON."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path


@dataclass
class Word:
    text: str
    start: float | None = None
    end: float | None = None

    @property
    def timed(self) -> bool:
        return self.start is not None and self.end is not None


@dataclass
class Line:
    words: list[Word] = field(default_factory=list)

    @property
    def start(self) -> float:
        return self.words[0].start

    @property
    def end(self) -> float:
        return self.words[-1].end

    @property
    def text(self) -> str:
        return " ".join(w.text for w in self.words)


def save_timing(path: str | Path, lines: list[Line], meta: dict | None = None) -> None:
    """JSON lisible et facile à corriger à la main : un mot par ligne."""
    out = ["{", '  "version": 1,']
    for k, v in (meta or {}).items():
        out.append(f"  {json.dumps(k)}: {json.dumps(v, ensure_ascii=False)},")
    out.append('  "lines": [')
    blocks = []
    for line in lines:
        if not line.words:
            continue
        words = ",\n".join(
            f'      {{"text": {json.dumps(w.text, ensure_ascii=False)}, '
            f'"start": {w.start:.3f}, "end": {w.end:.3f}}}'
            for w in line.words
        )
        blocks.append(f'    {{"text": {json.dumps(line.text, ensure_ascii=False)}, "words": [\n{words}\n    ]}}')
    out.append(",\n".join(blocks))
    out.append("  ]")
    out.append("}")
    Path(path).write_text("\n".join(out) + "\n", encoding="utf-8")


def load_timing(path: str | Path) -> tuple[list[Line], dict]:
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    lines = []
    for raw in data.get("lines", []):
        words = [Word(w["text"], float(w["start"]), float(w["end"])) for w in raw["words"]]
        if words:
            lines.append(Line(words))
    meta = {k: v for k, v in data.items() if k != "lines"}
    return lines, meta
