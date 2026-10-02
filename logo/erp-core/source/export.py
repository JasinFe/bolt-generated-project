import os, io, sys, shutil
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import cairosvg
from PIL import Image
from psd_tools import PSDImage
from psd_tools.api.layers import PixelLayer
from psd_tools.constants import Compression
import gen2

ROOT = os.path.join(HERE, "..")
for d in ["svg", "png", "ai", "pdf", "eps", "psd"]:
    os.makedirs(f"{ROOT}/{d}", exist_ok=True)

for name, svg in gen2.build(f"{ROOT}/svg").items():
    b = svg.encode()
    for w in ([2000, 1000, 512] if "icone" in name else [3000, 1500]):
        cairosvg.svg2png(bytestring=b, write_to=f"{ROOT}/png/{name}-{w}px.png", output_width=w)
    cairosvg.svg2pdf(bytestring=b, write_to=f"{ROOT}/pdf/{name}.pdf")
    cairosvg.svg2eps(bytestring=b, write_to=f"{ROOT}/eps/{name}.eps")
    shutil.copy(f"{ROOT}/pdf/{name}.pdf", f"{ROOT}/ai/{name}.ai")  # .ai compatible PDF


def make_psd(path, w, h, layers, width):
    scale = width / w
    psd = PSDImage.new("RGBA", (int(w * scale), int(h * scale)))
    for lname, body in layers:
        png = cairosvg.svg2png(bytestring=gen2.svg(w, h, body).encode(), output_width=int(w * scale))
        im = Image.open(io.BytesIO(png)).convert("RGBA")
        bbox = im.getbbox()
        if bbox:
            psd.append(PixelLayer.frompil(im.crop(bbox), psd, lname, bbox[1], bbox[0], compression=Compression.RLE))
    psd.save(path)


for name, (w, h, layers) in gen2.layouts().items():
    make_psd(f"{ROOT}/psd/finakop-erp-{name}.psd", w, h, layers, 2000)
print("done")
