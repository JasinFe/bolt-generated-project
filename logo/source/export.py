import os, io, shutil, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
os.environ["OUT"] = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "svg")
import cairosvg
from PIL import Image
from psd_tools import PSDImage
from psd_tools.api.layers import PixelLayer
import gen

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
for d in ["png", "ai", "pdf", "eps", "psd"]:
    os.makedirs(f"{ROOT}/{d}", exist_ok=True)

files = gen.build()
for name, svg in files.items():
    b = svg.encode()
    for w in ([2000, 1000, 512] if "icone" in name else [3000, 1500]):
        cairosvg.svg2png(bytestring=b, write_to=f"{ROOT}/png/{name}-{w}px.png", output_width=w)
    cairosvg.svg2pdf(bytestring=b, write_to=f"{ROOT}/pdf/{name}.pdf")
    cairosvg.svg2eps(bytestring=b, write_to=f"{ROOT}/eps/{name}.eps")
    shutil.copy(f"{ROOT}/pdf/{name}.pdf", f"{ROOT}/ai/{name}.ai")  # PDF-compatible .ai, opens editable in Illustrator


def render(body, w, h, scale):
    out = cairosvg.svg2png(bytestring=gen.svg(w, h, body).encode(), output_width=int(w * scale))
    return Image.open(io.BytesIO(out)).convert("RGBA")


def make_psd(path, w, h, layers, scale):
    W, H = int(w * scale), int(h * scale)
    psd = PSDImage.new("RGBA", (W, H))
    for lname, body in layers:
        im = render(body, w, h, scale)
        bbox = im.getbbox()
        if not bbox:
            continue
        crop = im.crop(bbox)
        psd.append(PixelLayer.frompil(crop, psd, lname, bbox[1], bbox[0]))
    psd.save(path)


names = {"network": "Reseau (anneau + connexions)", "core": "Hexagone central", "monogram": "Monogramme F+K"}
for i, s in enumerate(gen.SATS):
    names[f"sat{i}"] = f"Hexagone - {s[4]}"
order = ["network", "core"] + [f"sat{i}" for i in range(6)] + ["monogram"]

make_psd(f"{ROOT}/psd/finakop-icone.psd", 1000, 1000, [(names[k], gen.emblem(only=k)) for k in order], 2)

# horizontal lockup with emblem layers + wordmark layers
sc = 0.42
size = 190
_, ww = gen.wordmark(0, 0, size)
W, H = int(420 + 40 + ww + 30), 440
layers = [(names[k], f'<g transform="translate(10,10) scale({sc})">{gen.emblem(only=k)}</g>') for k in order]
d1, w1 = gen.text_path("Fina", gen.FONT, size, 470, 220 + size * 0.36, tracking=-size * 0.01)
d2, _ = gen.text_path("Kop", gen.FONT, size, 470 + w1 + size * 0.01, 220 + size * 0.36, tracking=-size * 0.01)
layers += [("Texte - Fina", f'<path d="{d1}" fill="{gen.NAVY}"/>'), ("Texte - Kop", f'<path d="{d2}" fill="{gen.ORANGE}"/>')]
make_psd(f"{ROOT}/psd/finakop-logo-horizontal.psd", W, H, layers, 3000 / W)
print("done")
