"""ML service + descriptor tests. Run against the live Flask service (and engine.py directly)."""
import io, os, sys, pathlib
import numpy as np, pytest, requests
from PIL import Image, ImageEnhance, ImageOps

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ml"))
ML = os.environ.get("ML_URL", "http://127.0.0.1:5000")
PHOTOS = sorted((ROOT / "photos/pets").glob("*.jpg"))


def post(path, **files):
    return requests.post(ML + path, files=files, timeout=30)


def test_health():
    j = requests.get(ML + "/health", timeout=10).json()
    assert j["status"] == "ok" and j["dim"] == 248 and j["engine"] == "descriptor-v1"


def test_embed_is_deterministic_and_normalised():
    b = PHOTOS[0].read_bytes()
    a1 = post("/embed", image=("a.jpg", b)).json()["embedding"]
    a2 = post("/embed", image=("a.jpg", b)).json()["embedding"]
    assert a1 == a2 and len(a1) == 248
    assert abs(np.linalg.norm(a1) - 1) < 1e-3


def test_compare_identical_is_100_and_symmetric():
    a, b = PHOTOS[0].read_bytes(), PHOTOS[7].read_bytes()
    assert post("/compare", a=("a.jpg", a), b=("b.jpg", a)).json()["score"] == 100
    s1 = post("/compare", a=("a.jpg", a), b=("b.jpg", b)).json()["score"]
    s2 = post("/compare", a=("a.jpg", b), b=("b.jpg", a)).json()["score"]
    assert s1 == s2 and 0 <= s1 < 100


@pytest.mark.parametrize("name,fn", [
    ("bright", lambda im: ImageEnhance.Brightness(im).enhance(1.25)),
    ("flip", lambda im: ImageOps.mirror(im)),
    ("shrink", lambda im: im.resize((im.width // 2, im.height // 2))),
    ("crop", lambda im: im.crop((int(im.width * .05), int(im.height * .05), int(im.width * .95), int(im.height * .95)))),
])
def test_same_pet_after_edit_scores_higher_than_a_different_pet(name, fn):
    orig = PHOTOS[3]
    im = Image.open(orig).convert("RGB")
    buf = io.BytesIO(); fn(im).save(buf, "JPEG"); edited = buf.getvalue()
    same = post("/compare", a=("a.jpg", orig.read_bytes()), b=("b.jpg", edited)).json()["score"]
    others = [post("/compare", a=("a.jpg", orig.read_bytes()), b=("b.jpg", p.read_bytes())).json()["score"] for p in PHOTOS[10:16]]
    assert same > max(others), f"{name}: same={same} others={others}"


def test_ranking_first_for_every_edited_photo_at_least_90_percent():
    """Accuracy check on 20 gallery photos (brightened + mirrored copy must rank its original first)."""
    from engine import make_engine, load_rgb
    eng = make_engine("descriptor")
    sample = PHOTOS[:20]
    gallery = np.stack([eng.embed(load_rgb(p.read_bytes())) for p in PHOTOS])
    hits = 0
    for i, p in enumerate(sample):
        im = ImageOps.mirror(ImageEnhance.Brightness(Image.open(p).convert("RGB")).enhance(1.2))
        buf = io.BytesIO(); im.save(buf, "JPEG")
        v = eng.embed(load_rgb(buf.getvalue()))
        hits += int(np.argmax(gallery @ v) == i)
    assert hits / len(sample) >= 0.9, hits


def test_non_image_rejected():
    assert post("/embed", image=("x.jpg", b"not an image")).status_code == 400


def test_missing_file_rejected():
    assert requests.post(ML + "/embed", timeout=10).status_code == 400
