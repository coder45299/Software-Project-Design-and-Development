// Merges V8 coverage collected during the browser tests (tests/.cov/e2e-*.json) and prints line coverage per source file.
const fs = require('fs'), path = require('path');
const dir = path.join(__dirname, '.cov'), root = path.join(__dirname, '..');
// photos.js is a generated data file (a list of photo names), so --exclude-data leaves it out of the percentage
const files = ['app.js', 'reports.js', 'admin.js', 'ml-lite.js', 'photos.js'].filter(f => !(process.argv.includes('--exclude-data') && f === 'photos.js'));
const used = Object.fromEntries(files.map(f => [f, new Uint8Array(fs.readFileSync(path.join(root, f)).length)]));
for (const j of fs.readdirSync(dir).filter(f => f.startsWith('e2e-'))) for (const e of JSON.parse(fs.readFileSync(path.join(dir, j)))) {
  const f = path.basename(new URL(e.url).pathname); if (!used[f]) continue;
  // V8 gives nested ranges; the innermost range that contains a byte decides whether it ran.
  const state = new Int8Array(used[f].length).fill(-1), order = [];
  for (const fn of e.functions) for (const r of fn.ranges) order.push(r);
  order.sort((a, b) => (b.endOffset - b.startOffset) - (a.endOffset - a.startOffset));
  for (const r of order) state.fill(r.count > 0 ? 1 : 0, r.startOffset, r.endOffset);
  for (let i = 0; i < state.length; i++) if (state[i] === 1) used[f][i] = 1;
}
let T = 0, C = 0; const rows = [];
for (const f of files) {
  const src = fs.readFileSync(path.join(root, f), 'utf8'); let off = 0, tot = 0, cov = 0;
  for (const line of src.split('\n')) { const len = Buffer.byteLength(line) + 1; const code = line.trim() && !/^\s*(\/\/|\/\*|\*)/.test(line);
    if (code) { tot++; let hit = false; for (let i = off; i < off + len; i++) if (used[f][i]) { hit = true; break; } if (hit) cov++; } off += len; }
  rows.push([f, cov, tot]); T += tot; C += cov;
}
for (const [f, c, t] of rows) console.log(f.padEnd(12), `${c}/${t}`.padStart(10), (100 * c / t).toFixed(1) + '%');
console.log('TOTAL'.padEnd(12), `${C}/${T}`.padStart(10), (100 * C / T).toFixed(1) + '%');
