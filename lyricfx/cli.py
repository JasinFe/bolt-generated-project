"""Interface en ligne de commande de lyricfx."""

from __future__ import annotations

import argparse
import sys
import tempfile
from pathlib import Path

from . import __version__
from .align import align, group_words, lines_from_lrc_only
from .ass import build_ass
from .lyrics import load_lyrics, fetch_lrclib, parse_lrc, parse_plain, LRC_TIME
from .model import Line, load_timing, save_timing
from .render import default_extension, render
from .styles import list_presets, load_style

SIZES = {
    "landscape": (1920, 1080), "16:9": (1920, 1080), "1080p": (1920, 1080),
    "720p": (1280, 720), "4k": (3840, 2160),
    "portrait": (1080, 1920), "9:16": (1080, 1920), "vertical": (1080, 1920),
    "square": (1080, 1080), "1:1": (1080, 1080),
}


def parse_size(value: str) -> tuple[int, int]:
    if value.lower() in SIZES:
        return SIZES[value.lower()]
    try:
        w, h = value.lower().split("x")
        return int(w), int(h)
    except ValueError:
        raise argparse.ArgumentTypeError(f"taille invalide : {value} (ex. 1920x1080, portrait, square)")


def log(msg: str) -> None:
    print(msg, file=sys.stderr)


# --------------------------------------------------------------------------- synchronisation
def sync_audio(audio: str, lyrics_path: str | None = None, lrclib: str | None = None,
               no_asr: bool = False, model: str = "small", language: str | None = None,
               vocals: bool = False, device: str = "auto", save_lyrics_to: Path | None = None
               ) -> tuple[list[Line], dict]:
    from .asr import audio_duration, separate_vocals, transcribe

    duration = audio_duration(audio)
    lyrics = None
    source = "whisper"
    if lrclib:
        log(f"» Recherche des paroles sur LRCLIB : {lrclib}")
        text = fetch_lrclib(lrclib, duration)
        if not text:
            raise SystemExit("Aucune parole trouvée sur LRCLIB.")
        lyrics = parse_lrc(text) if LRC_TIME.search(text) else parse_plain(text)
        source = "lrclib"
        if save_lyrics_to:
            save_lyrics_to.write_text(text, encoding="utf-8")
            log(f"  paroles enregistrées dans {save_lyrics_to}")
    elif lyrics_path:
        lyrics = load_lyrics(lyrics_path)
        source = Path(lyrics_path).name
    if lyrics is not None and not lyrics:
        raise SystemExit("Le fichier de paroles est vide.")

    if lyrics and all(ll.word_starts for ll in lyrics):
        log("» Paroles déjà synchronisées mot à mot (LRC enrichi).")
        lines = align(lyrics, [], duration)
    elif no_asr:
        if not lyrics:
            raise SystemExit("--no-asr nécessite un fichier .lrc synchronisé.")
        lines = lines_from_lrc_only(lyrics, duration)
    else:
        asr_input = audio
        if vocals:
            asr_input = separate_vocals(audio, Path(tempfile.mkdtemp(prefix="lyricfx_")))
        prompt = " ".join(" ".join(ll.tokens) for ll in lyrics) if lyrics else None
        words, breaks = transcribe(asr_input, model=model, language=language, prompt=prompt, device=device)
        if lyrics:
            lines = align(lyrics, words, duration)
        else:
            if not words:
                raise SystemExit("Whisper n'a reconnu aucun mot. Essayez --vocals ou un modèle plus gros.")
            lines = group_words(words, breaks=breaks)
    meta = {"audio": Path(audio).name, "duration": round(duration, 3), "source": source}
    return lines, meta


def render_lines(audio: str | None, lines: list[Line], duration: float, style: dict, output: Path,
                 size: tuple[int, int], fps: int, background: str, fonts_dir: str | None,
                 preview: float | None, ass_only: bool) -> None:
    w, h = size
    ass_path = output.with_suffix(".ass")
    ass_path.write_text(build_ass(lines, style, w, h), encoding="utf-8")
    log(f"» Sous-titres : {ass_path}")
    if ass_only:
        return
    if preview is not None:
        png = output.with_name(f"{output.stem}_preview_{preview:g}s.png")
        render(None, str(ass_path), str(png), w, h, fps, duration, background, fonts_dir, preview_at=preview)
        log(f"» Aperçu : {png}")
        return
    render(audio, str(ass_path), str(output), w, h, fps, duration, background, fonts_dir)


# --------------------------------------------------------------------------- arguments
def add_sync_args(p: argparse.ArgumentParser) -> None:
    g = p.add_argument_group("paroles / synchronisation")
    g.add_argument("--lyrics", help="paroles : .txt (une ligne par ligne chantée) ou .lrc")
    g.add_argument("--lrclib", metavar="\"ARTISTE - TITRE\"", help="récupère les paroles sur lrclib.net")
    g.add_argument("--no-asr", action="store_true", help="n'utilise pas Whisper (fichier .lrc requis)")
    g.add_argument("--model", default="small", help="modèle Whisper : tiny, base, small, medium, large-v3 (défaut : small)")
    g.add_argument("--language", help="langue du chant (fr, en, es…) — auto si omis")
    g.add_argument("--vocals", action="store_true", help="isole la voix avec Demucs avant la transcription")
    g.add_argument("--device", default="auto", help="auto, cpu ou cuda")


def add_render_args(p: argparse.ArgumentParser) -> None:
    g = p.add_argument_group("style / rendu")
    g.add_argument("--preset", default="neon", help="preset de style (voir `lyricfx presets`) ou chemin .json")
    g.add_argument("--style", action="append", default=[], help="fichier JSON de style à fusionner (répétable)")
    g.add_argument("--set", action="append", default=[], metavar="CLÉ=VALEUR",
                   help="surcharge ponctuelle, ex. --set color_sung=#FF00AA --set glow.size=12")
    g.add_argument("--bg", default="green",
                   help="green, blue, black, #RRGGBB, transparent, ou chemin d'une image/vidéo (défaut : green)")
    g.add_argument("--size", type=parse_size, default=(1920, 1080),
                   help="1920x1080, portrait (1080x1920), square, 4k… (défaut : 1920x1080)")
    g.add_argument("--fps", type=int, default=30)
    g.add_argument("--fonts-dir", default=None, help="dossier contenant des polices .ttf/.otf supplémentaires")
    g.add_argument("--preview", type=float, metavar="SECONDES", help="rend uniquement une image PNG à cet instant")
    g.add_argument("--ass-only", action="store_true", help="génère seulement le fichier .ass (sans vidéo)")


def default_fonts_dir(value: str | None) -> str | None:
    if value:
        return value
    local = Path(__file__).resolve().parent.parent / "fonts"
    return str(local) if local.is_dir() and any(local.glob("*.[ot]tf")) else None


def find_lyrics(audio: Path, lyrics_dir: Path | None) -> str | None:
    for folder in filter(None, (lyrics_dir, audio.parent)):
        for ext in (".lrc", ".txt"):
            cand = folder / (audio.stem + ext)
            if cand.exists():
                return str(cand)
    return None


# --------------------------------------------------------------------------- commandes
def cmd_presets(_args) -> int:
    for name, desc in list_presets().items():
        print(f"  {name:<12} {desc}")
    return 0


def cmd_sync(args) -> int:
    out = Path(args.output or Path(args.audio).with_suffix(".json"))
    lines, meta = sync_audio(args.audio, args.lyrics, args.lrclib, args.no_asr, args.model,
                             args.language, args.vocals, args.device, out.with_suffix(".lrc"))
    save_timing(out, lines, meta)
    log(f"✔ Timing enregistré : {out}  ({len(lines)} lignes) — modifiable à la main avant le rendu.")
    return 0


def cmd_render(args) -> int:
    lines, meta = load_timing(args.timing)
    style = load_style(args.preset, args.style, args.set)
    ext = default_extension(args.bg)
    out = Path(args.output or Path(args.timing).with_suffix(ext))
    duration = meta.get("duration") or max(l.end for l in lines) + 2
    if args.audio:
        from .asr import audio_duration
        duration = audio_duration(args.audio)
    render_lines(None if args.no_audio else args.audio, lines, duration, style, out, args.size,
                 args.fps, args.bg, default_fonts_dir(args.fonts_dir), args.preview, args.ass_only)
    log("✔ Terminé.")
    return 0


def cmd_make(args) -> int:
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    style = load_style(args.preset, args.style, args.set)
    ext = default_extension(args.bg) if not args.format else "." + args.format.lstrip(".")
    failures = 0
    for audio in args.audio:
        audio_p = Path(audio)
        log(f"\n═══ {audio_p.name} ═══")
        try:
            timing = out_dir / f"{audio_p.stem}.json"
            if timing.exists() and not args.resync:
                log(f"» Timing existant réutilisé : {timing} (--resync pour recalculer)")
                lines, meta = load_timing(timing)
            else:
                lyrics = args.lyrics if len(args.audio) == 1 and args.lyrics else \
                    find_lyrics(audio_p, Path(args.lyrics_dir) if args.lyrics_dir else None)
                if lyrics:
                    log(f"» Paroles : {lyrics}")
                elif not args.lrclib:
                    log("» Pas de fichier de paroles trouvé : transcription automatique.")
                lines, meta = sync_audio(audio, lyrics, args.lrclib if len(args.audio) == 1 else None,
                                         args.no_asr, args.model, args.language, args.vocals,
                                         args.device, out_dir / f"{audio_p.stem}.lrc")
                save_timing(timing, lines, meta)
                log(f"» Timing : {timing}")
            from .asr import audio_duration
            render_lines(None if args.no_audio else audio, lines, audio_duration(audio), style,
                         out_dir / f"{audio_p.stem}{ext}", args.size, args.fps, args.bg,
                         default_fonts_dir(args.fonts_dir), args.preview, args.ass_only)
        except (Exception, SystemExit) as exc:  # on continue le lot
            failures += 1
            log(f"✘ Échec pour {audio_p.name} : {exc}")
    log(f"\n✔ {len(args.audio) - failures}/{len(args.audio)} fichier(s) traité(s) → {out_dir}")
    return 1 if failures else 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="lyricfx",
        description="Sous-titres karaoké stylés (fond vert / transparent) à partir d'un audio.",
    )
    parser.add_argument("--version", action="version", version=f"lyricfx {__version__}")
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("presets", help="liste les styles disponibles")
    p.set_defaults(func=cmd_presets)

    p = sub.add_parser("sync", help="audio (+ paroles) → timing mot à mot (JSON éditable)")
    p.add_argument("audio")
    p.add_argument("-o", "--output", help="fichier JSON de sortie (défaut : <audio>.json)")
    add_sync_args(p)
    p.set_defaults(func=cmd_sync)

    p = sub.add_parser("render", help="timing JSON → vidéo karaoké stylée")
    p.add_argument("timing", help="fichier JSON produit par `sync`")
    p.add_argument("--audio", help="audio à inclure dans la vidéo (recommandé)")
    p.add_argument("--no-audio", action="store_true", help="vidéo muette")
    p.add_argument("-o", "--output", help="fichier vidéo (.mp4, .mov, .webm)")
    add_render_args(p)
    p.set_defaults(func=cmd_render)

    p = sub.add_parser("make", help="tout-en-un, pour un ou plusieurs audios")
    p.add_argument("audio", nargs="+")
    p.add_argument("--out-dir", default="out", help="dossier de sortie (défaut : out/)")
    p.add_argument("--lyrics-dir", help="dossier où chercher <nom>.lrc / <nom>.txt")
    p.add_argument("--format", help="extension vidéo forcée : mp4, mov, webm")
    p.add_argument("--resync", action="store_true", help="recalcule le timing même s'il existe")
    p.add_argument("--no-audio", action="store_true", help="vidéo muette")
    add_sync_args(p)
    add_render_args(p)
    p.set_defaults(func=cmd_make)

    args = parser.parse_args(argv)
    return args.func(args)
