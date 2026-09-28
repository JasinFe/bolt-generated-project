"""Rendu vidéo avec ffmpeg + libass : fond vert, couleur, transparent, image ou vidéo."""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

BACKGROUNDS = {"green": "0x00FF00", "blue": "0x0000FF", "black": "0x000000", "magenta": "0xFF00FF"}
IMAGE_EXT = {".png", ".jpg", ".jpeg", ".webp", ".bmp"}


def _filter_path(path: str) -> str:
    # Échappement pour un chemin dans un filtergraph ffmpeg
    return path.replace("\\", "/").replace(":", "\\:").replace("'", "\\'")


def is_transparent(bg: str) -> bool:
    return bg.lower() in ("transparent", "alpha", "none")


def default_extension(bg: str) -> str:
    return ".mov" if is_transparent(bg) else ".mp4"


def render(audio: str | None, ass_path: str, output: str, width: int, height: int, fps: int,
           duration: float, background: str = "green", fonts_dir: str | None = None,
           preview_at: float | None = None, crf: int = 18) -> None:
    if not shutil.which("ffmpeg"):
        raise SystemExit("ffmpeg introuvable : installez-le (https://ffmpeg.org) et ajoutez-le au PATH.")
    out_ext = Path(output).suffix.lower()
    transparent = is_transparent(background)
    size = f"{width}x{height}"

    cmd = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"]
    if preview_at is None:
        cmd.append("-stats")
    bg_path = Path(background)
    if transparent:
        cmd += ["-f", "lavfi", "-i", f"color=c=black@0.0:s={size}:r={fps}:d={duration:.3f},format=rgba"]
    elif bg_path.exists():
        if bg_path.suffix.lower() in IMAGE_EXT:
            cmd += ["-loop", "1", "-framerate", str(fps), "-t", f"{duration:.3f}", "-i", str(bg_path)]
        else:
            cmd += ["-stream_loop", "-1", "-t", f"{duration:.3f}", "-i", str(bg_path)]
    else:
        color = BACKGROUNDS.get(background.lower(), background.replace("#", "0x"))
        cmd += ["-f", "lavfi", "-i", f"color=c={color}:s={size}:r={fps}:d={duration:.3f}"]

    with_audio = audio is not None and preview_at is None
    if with_audio:
        cmd += ["-i", audio]

    vf = []
    if bg_path.exists() and not transparent:
        vf.append(f"scale={size}:force_original_aspect_ratio=increase,crop={width}:{height},fps={fps},format=yuv420p")
    ass = f"ass=filename='{_filter_path(ass_path)}'"
    if fonts_dir:
        ass += f":fontsdir='{_filter_path(fonts_dir)}'"
    if transparent:
        ass += ":alpha=1"
    vf.append(ass)
    cmd += ["-filter_complex", f"[0:v]{','.join(vf)}[v]", "-map", "[v]"]

    if preview_at is not None:
        cmd += ["-ss", f"{preview_at:.3f}", "-frames:v", "1", output]
    else:
        if with_audio:
            cmd += ["-map", "1:a"]
        if transparent:
            if out_ext == ".webm":
                cmd += ["-c:v", "libvpx-vp9", "-pix_fmt", "yuva420p", "-b:v", "0", "-crf", "30",
                        "-row-mt", "1", "-auto-alt-ref", "0"]
                cmd += ["-c:a", "libopus"] if with_audio else []
            else:  # .mov ProRes 4444 : compatible Premiere, Final Cut, DaVinci, After Effects
                cmd += ["-c:v", "prores_ks", "-profile:v", "4444", "-pix_fmt", "yuva444p10le",
                        "-vendor", "apl0"]
                cmd += ["-c:a", "pcm_s16le"] if with_audio else []
        else:
            cmd += ["-c:v", "libx264", "-preset", "medium", "-crf", str(crf), "-pix_fmt", "yuv420p"]
            cmd += ["-c:a", "aac", "-b:a", "192k"] if with_audio else []
        cmd += ["-t", f"{duration:.3f}", output]

    print("» Rendu :", output, file=sys.stderr)
    subprocess.run(cmd, check=True)
