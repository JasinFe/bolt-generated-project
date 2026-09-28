"""Interface en ligne de commande de lyricfx."""

from __future__ import annotations

import argparse
import sys
import tempfile
from pathlib import Path

from . import __version__
from .align import align, group_words, lines_from_lrc_only, map_by_chars
from .ass import build_ass
from .lyrics import load_lyrics, fetch_lrclib, parse_lrc, parse_plain, LRC_TIME
from .model import Line, Word, load_timing, save_timing
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
               no_asr: bool = False, model: str | None = None, language: str | None = None,
               vocals: bool | None = None, device: str = "auto", save_lyrics_to: Path | None = None,
               engine: str = "auto") -> tuple[list[Line], dict]:
    from .asr import audio_duration, force_align, has_module, separate_vocals, transcribe

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
    elif lyrics and (engine == "align" or (engine == "auto" and has_module("stable_whisper"))):
        # Alignement forcé : le plus précis quand on a les paroles
        if not language:
            language = "fr"
            log("  (langue non précisée : français par défaut — utilisez --language en, es… sinon)")
        timed = force_align(audio, [ll.tokens for ll in lyrics], model or "medium", language, device, vocals)
        tokens = [tok for ll in lyrics for tok in ll.tokens]
        lines = align(lyrics, [], duration, token_times=map_by_chars(tokens, timed))
        source += " + alignement forcé"
    else:
        if lyrics and engine == "auto":
            log("  Astuce : `pip install stable-ts` active l'alignement forcé, bien plus précis.")
        asr_input = audio
        if vocals:
            asr_input = separate_vocals(audio, Path(tempfile.mkdtemp(prefix="lyricfx_")))
        prompt = " ".join(" ".join(ll.tokens) for ll in lyrics) if lyrics else None
        words, breaks = transcribe(asr_input, model=model or "small", language=language, prompt=prompt,
                                   device=device)
        if lyrics:
            lines = align(lyrics, words, duration)
        else:
            if not words:
                raise SystemExit("Whisper n'a reconnu aucun mot. Essayez --vocals ou un modèle plus gros.")
            lines = group_words(words, breaks=breaks)
    meta = {"audio": Path(audio).name, "duration": round(duration, 3), "source": source}
    return lines, meta


def shift(lines: list[Line], offset: float) -> list[Line]:
    if not offset:
        return lines
    return [Line([Word(w.text, max(0.0, w.start + offset), max(0.0, w.end + offset)) for w in l.words], l.echo)
            for l in lines]


def render_lines(audio: str | None, lines: list[Line], duration: float, style: dict, output: Path,
                 size: tuple[int, int], fps: int, background: str, fonts_dir: str | None,
                 preview: float | None, ass_only: bool, offset: float = 0.0) -> Path | None:
    w, h = size
    lines = shift(lines, offset)
    ass_path = output.with_suffix(".ass")
    ass_path.write_text(build_ass(lines, style, w, h), encoding="utf-8")
    log(f"» Sous-titres : {ass_path}")
    if ass_only:
        return ass_path
    if preview is not None:
        png = output.with_name(f"{output.stem}_preview_{preview:g}s.png")
        render(None, str(ass_path), str(png), w, h, fps, duration, background, fonts_dir, preview_at=preview)
        log(f"» Aperçu : {png}")
        return png
    render(audio, str(ass_path), str(output), w, h, fps, duration, background, fonts_dir)
    return output


# --------------------------------------------------------------------------- arguments
def add_sync_args(p: argparse.ArgumentParser) -> None:
    g = p.add_argument_group("paroles / synchronisation")
    g.add_argument("--lyrics", help="paroles : .txt (une ligne par ligne chantée) ou .lrc")
    g.add_argument("--lrclib", metavar="\"ARTISTE - TITRE\"", help="récupère les paroles sur lrclib.net")
    g.add_argument("--no-asr", action="store_true", help="n'utilise pas Whisper (fichier .lrc requis)")
    g.add_argument("--engine", choices=["auto", "align", "whisper"], default="auto",
                   help="align = alignement forcé des paroles (stable-ts, le plus précis) ; "
                        "whisper = transcription puis rapprochement ; auto = align si possible")
    g.add_argument("--model", default=None,
                   help="modèle Whisper : tiny, base, small, medium, large-v3 (défaut : medium en align, small sinon)")
    g.add_argument("--language", help="langue du chant (fr, en, es…) — auto si omis")
    g.add_argument("--vocals", action="store_true", default=None,
                   help="isole la voix avec Demucs (automatique en mode align si demucs est installé)")
    g.add_argument("--no-vocals", dest="vocals", action="store_false", help="n'isole pas la voix")
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
    g.add_argument("--offset", type=float, default=0.0, metavar="SECONDES",
                   help="décale tout le texte : négatif = plus tôt, positif = plus tard (ex. -0.2)")
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


def cmd_editor(_args) -> int:
    import webbrowser
    page = Path(__file__).resolve().parent / "editor" / "editor.html"
    log(f"» Ouverture de l'éditeur de calage : {page}")
    if not webbrowser.open(page.as_uri()):
        log("  Ouvrez ce fichier dans votre navigateur (Chrome, Firefox, Edge…).")
    return 0


def pick_moment(lines: list[Line]) -> float:
    """Un instant représentatif : au milieu d'une ligne bien fournie, vers le premier tiers."""
    cands = [l for l in lines if len(l.words) >= 4] or lines
    line = cands[len(cands) // 3]
    return round(line.start + 0.6 * (line.end - line.start), 2)


def cmd_gallery(args) -> int:
    import subprocess
    lines, meta = load_timing(args.timing)
    at = args.at if args.at is not None else pick_moment(lines)
    names = args.presets.split(",") if args.presets else list(list_presets())
    duration = meta.get("duration") or max(l.end for l in lines) + 2
    w, h = args.size
    tmp = Path(tempfile.mkdtemp(prefix="lyricfx_gallery_"))
    pngs = []
    for name in names:
        style = load_style(name, None, args.set)
        png = render_lines(None, lines, duration, style, tmp / f"{name}.mp4", args.size, 30, args.bg,
                           default_fonts_dir(args.fonts_dir), at, False, args.offset)
        pngs.append((name, png))
    cols = 3 if w >= h else 5
    tw = 640 if w >= h else 300
    th = round(tw * h / w / 2) * 2
    rows = -(-len(pngs) // cols)
    out = Path(args.output or Path(args.timing).with_name(Path(args.timing).stem + "_galerie.png"))

    def build(labels: bool) -> list[str]:
        cmd = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"]
        for _, png in pngs:
            cmd += ["-i", str(png)]
        chains, refs, layout_ = [], [], []
        for i, (name, _) in enumerate(pngs):
            label = (f",drawtext=text='{name}':x=10:y=8:fontsize={max(14, tw // 28)}:fontcolor=white"
                     f":box=1:boxcolor=black@0.65:boxborderw=6") if labels else ""
            chains.append(f"[{i}]scale={tw}:{th}{label},pad={tw + 4}:{th + 4}:2:2:black[t{i}]")
            refs.append(f"[t{i}]")
            layout_.append(f"{(i % cols) * (tw + 4)}_{(i // cols) * (th + 4)}")
        grid = (f"{''.join(refs)}xstack=inputs={len(pngs)}:layout={'|'.join(layout_)}"
                f":fill=black,pad={cols * (tw + 4)}:{rows * (th + 4)}:0:0:black")
        return cmd + ["-filter_complex", ";".join(chains + [grid]), "-frames:v", "1", str(out)]

    if len(pngs) == 1:
        out.write_bytes(pngs[0][1].read_bytes())
    elif subprocess.run(build(True)).returncode != 0:
        subprocess.run(build(False), check=True)
    log(f"✔ Galerie des styles à {at:g} s : {out}")
    return 0


def cmd_sync(args) -> int:
    out = Path(args.output or Path(args.audio).with_suffix(".json"))
    lines, meta = sync_audio(args.audio, args.lyrics, args.lrclib, args.no_asr, args.model,
                             args.language, args.vocals, args.device, out.with_suffix(".lrc"), args.engine)
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
                 args.fps, args.bg, default_fonts_dir(args.fonts_dir), args.preview, args.ass_only, args.offset)
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
                                         args.device, out_dir / f"{audio_p.stem}.lrc", args.engine)
                save_timing(timing, lines, meta)
                log(f"» Timing : {timing}")
            from .asr import audio_duration
            render_lines(None if args.no_audio else audio, lines, audio_duration(audio), style,
                         out_dir / f"{audio_p.stem}{ext}", args.size, args.fps, args.bg,
                         default_fonts_dir(args.fonts_dir), args.preview, args.ass_only, args.offset)
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

    p = sub.add_parser("editor", help="ouvre l'éditeur de calage manuel (navigateur)")
    p.set_defaults(func=cmd_editor)

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

    p = sub.add_parser("gallery", help="compare tous les styles sur une image (à partir d'un timing JSON)")
    p.add_argument("timing", help="fichier JSON produit par `sync` ou par l'éditeur")
    p.add_argument("--at", type=float, help="instant à montrer, en secondes (auto sinon)")
    p.add_argument("--presets", help="liste séparée par des virgules (défaut : tous)")
    p.add_argument("--set", action="append", default=[], metavar="CLÉ=VALEUR")
    p.add_argument("--bg", default="green")
    p.add_argument("--size", type=parse_size, default=(1920, 1080))
    p.add_argument("--offset", type=float, default=0.0)
    p.add_argument("--fonts-dir", default=None)
    p.add_argument("-o", "--output", help="image PNG de sortie")
    p.set_defaults(func=cmd_gallery)

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
