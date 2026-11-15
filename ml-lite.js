/* =========================================================
   ml-lite.js - image embedding + matching that runs in the browser.
   Used by demo mode so Find-My-Pet and the admin match console do real
   image comparison without any server. The PHP backend uses ml/service.py
   instead (same idea, same descriptor - see ml/engine.py).

   How it works
   1. Image -> 64x64 pixels (centre-cropped to a square).
   2. Descriptor = [ colour histogram (HSV, centre-weighted) |
                     coarse colour layout (4x4 grid) |
                     edge-orientation histogram (HOG, 4x4 cells) ]
      averaged with the mirrored image so left/right flips still match.
   3. Similarity = cosine of two descriptors, rescaled so unrelated
      photos land near 0 and the same photo lands near 100.

   This is visual similarity (colour, pattern, shape). It is not true
   biometric identification: two similar-looking pets can score high.
   ========================================================= */
const ML = (() => {
  const N = 64, ENGINE = 'descriptor-v1';
  // cosine of two *unrelated* photos is about FLOOR with this descriptor; measured on the 45 gallery photos
  const FLOOR = 0.72;

  const loadImage = src => new Promise((res, rej) => {
    const im = new Image();
    im.onload = () => res(im); im.onerror = () => rej(new Error('Could not read the image'));
    im.src = src;
  });
  const toURL = async s => (s instanceof Blob) ? URL.createObjectURL(s) : s;

  function pixels(im) {
    const c = document.createElement('canvas'); c.width = c.height = N;
    const g = c.getContext('2d', { willReadFrequently: true });
    const s = Math.min(im.naturalWidth, im.naturalHeight), sx = (im.naturalWidth - s) / 2, sy = (im.naturalHeight - s) / 2;
    g.drawImage(im, sx, sy, s, s, 0, 0, N, N);
    return g.getImageData(0, 0, N, N).data;           // throws SecurityError on file:// (tainted canvas)
  }

  function describe(d, flip) {
    const gray = new Float32Array(N * N), hist = new Float32Array(72), lay = new Float32Array(48);
    const at = (x, y) => (y * N + (flip ? N - 1 - x : x)) * 4;
    const cnt = new Float32Array(16);
    for (let y = 0; y < N; y++) for (let x = 0; x < N; x++) {
      const i = at(x, y), r = d[i] / 255, g = d[i + 1] / 255, b = d[i + 2] / 255;
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b), df = mx - mn, s = mx ? df / mx : 0;
      let h = 0;
      if (df) h = mx === r ? ((g - b) / df + 6) % 6 : mx === g ? (b - r) / df + 2 : (r - g) / df + 4;
      const hb = s < .15 ? 0 : Math.min(7, (h / 6 * 8) | 0), sb = s < .25 ? 0 : s < .6 ? 1 : 2, vb = mx < .33 ? 0 : mx < .66 ? 1 : 2;
      const w = Math.exp(-(((x - 31.5) ** 2) + ((y - 31.5) ** 2)) / (2 * 22 * 22));
      hist[(hb * 3 + sb) * 3 + vb] += w;
      const cell = (y >> 4) * 4 + (x >> 4); cnt[cell]++;
      lay[cell * 3] += r; lay[cell * 3 + 1] += g; lay[cell * 3 + 2] += b;
      gray[y * N + x] = .299 * r + .587 * g + .114 * b;
    }
    for (let c = 0; c < 16; c++) for (let k = 0; k < 3; k++) lay[c * 3 + k] = lay[c * 3 + k] / cnt[c] - .5;
    const hog = new Float32Array(128);
    for (let y = 1; y < N - 1; y++) for (let x = 1; x < N - 1; x++) {
      const gx = gray[y * N + x + 1] - gray[y * N + x - 1], gy = gray[(y + 1) * N + x] - gray[(y - 1) * N + x];
      const m = Math.hypot(gx, gy); if (!m) continue;
      let a = Math.atan2(gy, gx); if (a < 0) a += Math.PI; if (a >= Math.PI) a -= Math.PI;
      hog[((y >> 4) * 4 + (x >> 4)) * 8 + Math.min(7, (a / Math.PI * 8) | 0)] += m;
    }
    for (let c = 0; c < 16; c++) { let s = 1e-6; for (let k = 0; k < 8; k++) s += hog[c * 8 + k] ** 2; s = Math.sqrt(s); for (let k = 0; k < 8; k++) hog[c * 8 + k] /= s; }
    const l2 = (a, w) => { let s = 1e-9; for (const v of a) s += v * v; s = Math.sqrt(s); return Array.from(a, v => v / s * w); };
    let hs = 0; for (const v of hist) hs += v; const hh = Float32Array.from(hist, v => Math.sqrt(v / (hs || 1)));
    return [...l2(hh, 1), ...l2(lay, .8), ...l2(hog, 1)];
  }

  const normalize = v => { let s = 0; for (const x of v) s += x * x; s = Math.sqrt(s) || 1; return v.map(x => +(x / s).toFixed(4)); };

  /** Embed an image (File/Blob/URL/data-URL). Returns a plain array. */
  async function embed(source) {
    const url = await toURL(source), im = await loadImage(url);
    const d = pixels(im), a = describe(d, false), b = describe(d, true);
    return normalize(a.map((v, i) => (v + b[i]) / 2));
  }
  const cosine = (a, b) => { let s = 0; const n = Math.min(a.length, b.length); for (let i = 0; i < n; i++) s += a[i] * b[i]; return s; };   // vectors are unit length
  /** cosine -> 0..100 score */
  const score = (a, b) => Math.round(Math.max(0, Math.min(1, (cosine(a, b) - FLOOR) / (1 - FLOOR))) * 1000) / 10;

  /* ---- cache of embeddings keyed by image URL (gallery photos never change) ---- */
  const hash = str => { let h = 5381; for (let i = 0; i < str.length; i += 7) h = ((h << 5) + h + str.charCodeAt(i)) | 0; return (h >>> 0).toString(36) + str.length; };
  const cacheKey = u => 'emb_' + ENGINE + '_' + (u.startsWith('data:') ? 'd' + hash(u) : u);
  async function embedCached(url) {
    const k = cacheKey(url), hit = Store.get(k); if (hit) return hit;
    const v = await embed(url); try { Store.set(k, v); } catch (e) { /* storage full: fine, recompute next time */ } return v;
  }

  /** Rank registered pets for a query vector. pets: [{pet_id, species,...}] -> [{pet_id, score, image}] best first */
  async function rank(vec, pets, { species = '', limit = 5, minScore = 0 } = {}) {
    const out = [];
    for (const p of pets) {
      if (species && p.species !== species) continue;
      let best = null;
      for (const url of petImgs(p)) {
        try { const s = score(vec, await embedCached(url)); if (!best || s > best.score) best = { pet_id: p.pet_id, score: s, image: url }; } catch (e) { /* skip unreadable image */ }
      }
      if (best && best.score >= minScore) out.push(best);
    }
    return out.sort((a, b) => b.score - a.score).slice(0, limit);
  }

  /** Friendly explanation when the browser blocks pixel access (page opened as file://) */
  const blockedMessage = () => location.protocol === 'file:'
    ? 'Image matching needs the site to be served over http. Run "python -m http.server 8000" in the project folder and open http://localhost:8000.'
    : 'This browser blocked reading the image.';

  return { ENGINE, FLOOR, embed, embedCached, score, cosine, rank, blockedMessage };
})();
