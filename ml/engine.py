"""Image embedding engines used by the ML service.

descriptor  (default)  colour + layout + edge-orientation descriptor, numpy + Pillow only. Works offline.
                       Same algorithm as ml-lite.js, so demo mode and the PHP backend behave alike.
mobilenet   (opt-in)   MobileNetV2 (ImageNet) pooled features through TensorFlow/Keras. Much better at
                       telling animals apart, needs `pip install tensorflow` and downloads weights once.

Choose with the PETBIO_ENGINE environment variable.  After switching engines run
POST /api/admin/reindex so every stored photo is re-embedded - vectors from different
engines cannot be compared.
"""
import io, os
import numpy as np
from PIL import Image, ImageOps

N = 64


def load_rgb(data: bytes) -> Image.Image:
    im = Image.open(io.BytesIO(data))
    im = ImageOps.exif_transpose(im)          # phone photos carry a rotation flag
    return im.convert("RGB")


def _square(im: Image.Image, size: int) -> Image.Image:
    w, h = im.size
    s = min(w, h)
    box = ((w - s) // 2, (h - s) // 2, (w - s) // 2 + s, (h - s) // 2 + s)
    return im.resize((size, size), Image.BOX if s > size * 2 else Image.BILINEAR, box=box)


def _l2(a, w=1.0):
    a = np.asarray(a, dtype=np.float32).ravel()
    return a / (np.sqrt((a * a).sum()) + 1e-9) * w


def _describe(px: np.ndarray, flip: bool) -> np.ndarray:
    if flip:
        px = px[:, ::-1]
    rgb = px.astype(np.float32) / 255.0
    r, g, b = rgb[..., 0], rgb[..., 1], rgb[..., 2]
    mx, mn = rgb.max(2), rgb.min(2)
    df = mx - mn
    s = np.where(mx > 0, df / np.maximum(mx, 1e-9), 0)
    d = np.maximum(df, 1e-9)
    h = np.where(mx == r, ((g - b) / d) % 6, np.where(mx == g, (b - r) / d + 2, (r - g) / d + 4))
    h = np.where(df > 0, h, 0)
    hb = np.where(s < .15, 0, np.minimum(7, (h / 6 * 8).astype(int)))
    sb = np.where(s < .25, 0, np.where(s < .6, 1, 2))
    vb = np.where(mx < .33, 0, np.where(mx < .66, 1, 2))
    yy, xx = np.mgrid[0:N, 0:N]
    w = np.exp(-(((xx - 31.5) ** 2) + ((yy - 31.5) ** 2)) / (2 * 22 * 22))
    hist = np.bincount(((hb * 3 + sb) * 3 + vb).ravel(), weights=w.ravel(), minlength=72)
    hist = np.sqrt(hist / (hist.sum() or 1))
    lay = rgb.reshape(4, 16, 4, 16, 3).mean((1, 3)) - .5
    gray = .299 * r + .587 * g + .114 * b
    gx = gray[1:-1, 2:] - gray[1:-1, :-2]
    gy = gray[2:, 1:-1] - gray[:-2, 1:-1]
    mag = np.hypot(gx, gy)
    ang = np.arctan2(gy, gx) % np.pi
    ab = np.minimum(7, (ang / np.pi * 8).astype(int))
    cy, cx = np.mgrid[1:N - 1, 1:N - 1]
    cell = (cy >> 4) * 4 + (cx >> 4)
    hog = np.bincount((cell * 8 + ab).ravel(), weights=mag.ravel(), minlength=128).reshape(16, 8)
    hog = hog / (np.sqrt((hog ** 2).sum(1, keepdims=True)) + 1e-3)
    return np.concatenate([_l2(hist, 1.0), _l2(lay, .8), _l2(hog, 1.0)])


class DescriptorEngine:
    name = "descriptor-v1"

    def embed(self, im: Image.Image) -> np.ndarray:
        px = np.asarray(_square(im, N))
        v = (_describe(px, False) + _describe(px, True)) / 2
        return v / (np.linalg.norm(v) + 1e-9)


class MobileNetEngine:
    name = "mobilenetv2-imagenet"

    def __init__(self):
        import tensorflow as tf                     # imported lazily: only needed for this engine
        self.tf = tf
        self.model = tf.keras.applications.MobileNetV2(weights="imagenet", include_top=False, pooling="avg",
                                                       input_shape=(224, 224, 3))

    def embed(self, im: Image.Image) -> np.ndarray:
        x = np.asarray(_square(im, 224), dtype=np.float32)[None]
        x = self.tf.keras.applications.mobilenet_v2.preprocess_input(x)
        v = self.model.predict(x, verbose=0)[0]
        return v / (np.linalg.norm(v) + 1e-9)


def make_engine(kind: str = "descriptor"):
    return MobileNetEngine() if kind == "mobilenet" else DescriptorEngine()
