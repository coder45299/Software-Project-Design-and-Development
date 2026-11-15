"""Pet Biometrics - ML service (Flask).  PHP calls this over HTTP.

    pip install -r ml/requirements.txt
    python ml/service.py                      # http://127.0.0.1:5000

Endpoints
    GET  /health                -> {status, engine, dim, floor}
    POST /embed   image=<file>  -> {embedding: [...], engine, dim}
    POST /compare a=<file> b=<file> -> {cosine, score}      (0-100, same scale as the site)

Environment
    PETBIO_ENGINE  descriptor (default) | mobilenet
    PETBIO_FLOOR   cosine of two unrelated photos for the chosen engine (default 0.72 for descriptor)
    PETBIO_HOST / PETBIO_PORT   default 127.0.0.1 / 5000
"""
import os
import numpy as np
from flask import Flask, jsonify, request
from engine import make_engine, load_rgb

ENGINE_KIND = os.environ.get("PETBIO_ENGINE", "descriptor")
FLOOR = float(os.environ.get("PETBIO_FLOOR", "0.72" if ENGINE_KIND == "descriptor" else "0.45"))
engine = make_engine(ENGINE_KIND)

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 12 * 1024 * 1024


def _read(field: str) -> np.ndarray:
    f = request.files.get(field)
    if not f:
        raise ValueError(f"Missing file field '{field}'.")
    try:
        im = load_rgb(f.read())
    except Exception:
        raise ValueError("That file is not a readable image.")
    return engine.embed(im)


def _score(a, b) -> float:
    c = float(np.dot(a, b))
    return round(max(0.0, min(1.0, (c - FLOOR) / (1 - FLOOR))) * 100, 1)


@app.errorhandler(ValueError)
def bad(e):
    return jsonify(message=str(e)), 400


@app.errorhandler(413)
def too_big(e):
    return jsonify(message="Image is too large."), 413


@app.get("/health")
def health():
    return jsonify(status="ok", engine=engine.name, dim=int(engine.embed(load_rgb(_blank())).shape[0]), floor=FLOOR)


def _blank() -> bytes:
    import io
    from PIL import Image
    b = io.BytesIO(); Image.new("RGB", (8, 8), (128, 128, 128)).save(b, "PNG"); return b.getvalue()


@app.post("/embed")
def embed():
    v = _read("image")
    return jsonify(embedding=[round(float(x), 5) for x in v], engine=engine.name, dim=len(v))


@app.post("/compare")
def compare():
    a, b = _read("a"), _read("b")
    return jsonify(cosine=round(float(np.dot(a, b)), 4), score=_score(a, b), engine=engine.name)


if __name__ == "__main__":
    app.run(host=os.environ.get("PETBIO_HOST", "127.0.0.1"), port=int(os.environ.get("PETBIO_PORT", "5000")))
