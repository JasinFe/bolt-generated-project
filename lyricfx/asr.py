"""Reconnaissance vocale avec horodatage par mot (faster-whisper ou openai-whisper)."""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

from .model import Word


def separate_vocals(audio: str, workdir: str | Path) -> str:
    """Isole la voix avec Demucs (améliore nettement la précision sur la musique)."""
    workdir = Path(workdir)
    cmd = [sys.executable, "-m", "demucs", "--two-stems=vocals", "-o", str(workdir), audio]
    print("» Séparation de la voix (Demucs)…", file=sys.stderr)
    subprocess.run(cmd, check=True)
    found = list(workdir.rglob(f"{Path(audio).stem}/vocals.wav"))
    if not found:
        raise RuntimeError("Demucs n'a pas produit de piste vocals.wav")
    return str(found[0])


def transcribe(audio: str, model: str = "small", language: str | None = None,
               prompt: str | None = None, device: str = "auto") -> tuple[list[Word], set[int]]:
    """Renvoie la liste des mots reconnus et les index où commence un nouveau segment."""
    if prompt:
        prompt = prompt[:600]
    try:
        from faster_whisper import WhisperModel
    except ImportError:
        WhisperModel = None

    words: list[Word] = []
    breaks: set[int] = set()
    print(f"» Transcription Whisper (modèle « {model} »)…", file=sys.stderr)
    if WhisperModel is not None:
        wm = WhisperModel(model, device=device, compute_type="auto" if device != "cpu" else "int8")
        segments, _ = wm.transcribe(
            audio, language=language, word_timestamps=True, initial_prompt=prompt,
            condition_on_previous_text=False, vad_filter=False,
        )
        for seg in segments:
            breaks.add(len(words))
            for w in seg.words or []:
                if w.word.strip():
                    words.append(Word(w.word.strip(), float(w.start), float(w.end)))
        return words, breaks

    try:
        import whisper
    except ImportError as exc:
        raise SystemExit(
            "Aucun moteur Whisper installé. Installez-en un :\n  pip install faster-whisper"
        ) from exc
    wm = whisper.load_model(model, device=None if device == "auto" else device)
    result = wm.transcribe(audio, language=language, word_timestamps=True,
                           initial_prompt=prompt, condition_on_previous_text=False)
    for seg in result["segments"]:
        breaks.add(len(words))
        for w in seg.get("words", []):
            if w["word"].strip():
                words.append(Word(w["word"].strip(), float(w["start"]), float(w["end"])))
    return words, breaks


def audio_duration(path: str) -> float:
    if not shutil.which("ffprobe"):
        raise SystemExit("ffprobe introuvable : installez ffmpeg (https://ffmpeg.org).")
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", path],
        check=True, capture_output=True, text=True,
    ).stdout.strip()
    return float(out)
