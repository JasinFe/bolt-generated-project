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


def has_module(name: str) -> bool:
    import importlib.util
    return importlib.util.find_spec(name) is not None


def force_align(audio: str, lines: list[list[str]], model: str = "medium", language: str = "fr",
                device: str = "auto", denoise: bool | None = None) -> list[Word]:
    """Alignement forcé des paroles connues sur l'audio (stable-ts).

    Contrairement à la transcription, Whisper ne « devine » pas le texte : il cherche
    où chaque mot fourni est chanté. Beaucoup plus fiable sur du rap / de la musique chargée.
    Renvoie les mots horodatés (découpage de stable-ts, à reporter sur nos mots).
    """
    try:
        import stable_whisper
    except ImportError as exc:
        raise SystemExit(
            "Moteur d'alignement absent. Installez-le :\n  pip install stable-ts\n"
            "(ou utilisez --engine whisper)"
        ) from exc
    if denoise is None:
        denoise = has_module("demucs")
    print(f"» Alignement forcé des paroles (stable-ts, modèle « {model} », langue {language}"
          f"{', voix isolée par Demucs' if denoise else ''})…", file=sys.stderr)
    if has_module("faster_whisper"):
        opts = {"device": device, "compute_type": "int8" if device == "cpu" else "auto"}
        wm = stable_whisper.load_faster_whisper(model, **opts)
    else:
        wm = stable_whisper.load_model(model, device=None if device == "auto" else device)
    text = "\n".join(" ".join(tokens) for tokens in lines)
    kwargs = {"language": language, "original_split": True}
    if denoise:
        kwargs["denoiser"] = "demucs"
    result = wm.align(audio, text, **kwargs)
    if result is None:
        raise RuntimeError("stable-ts n'a pas réussi à aligner les paroles.")
    words = []
    for seg in result.segments:
        for w in seg.words:
            if w.word.strip():
                words.append(Word(w.word.strip(), float(w.start), float(w.end)))
    return words


def audio_duration(path: str) -> float:
    if not shutil.which("ffprobe"):
        raise SystemExit("ffprobe introuvable : installez ffmpeg (https://ffmpeg.org).")
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", path],
        check=True, capture_output=True, text=True,
    ).stdout.strip()
    return float(out)
