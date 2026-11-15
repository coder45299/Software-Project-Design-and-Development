// Static checks: JS syntax, inline-script syntax in every page, PHP syntax, Python compile.
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const root = path.join(__dirname, '..'); let bad = 0;
const fail = (m) => { console.error('FAIL', m); bad++; };
for (const f of fs.readdirSync(root).filter(f => f.endsWith('.js'))) {
  try { execFileSync('node', ['--check', path.join(root, f)], { stdio: 'pipe' }); console.log('ok  ', f); } catch (e) { fail(f + '\n' + e.stderr); }
}
for (const f of fs.readdirSync(root).filter(f => f.endsWith('.html'))) {
  const html = fs.readFileSync(path.join(root, f), 'utf8');
  [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].forEach((m, i) => {
    try { new Function(m[1]); } catch (e) { fail(`${f} inline script #${i + 1}: ${e.message}`); }
  });
  // duplicate id attributes (static ones only) break label/for and getElementById
  const ids = [...html.replace(/<script[\s\S]*?<\/script>/g, '').matchAll(/<[a-z][^>]*?\sid="([^"$]+)"/g)].map(m => m[1]);
  const dup = ids.filter((x, i) => ids.indexOf(x) !== i); if (dup.length) fail(`${f}: duplicate id(s) ${[...new Set(dup)]}`);
  // every <label for> must point at an existing id
  for (const m of html.matchAll(/<label[^>]*\sfor="([^"$]+)"/g)) if (!ids.includes(m[1]) && !html.includes(`id="${m[1]}"`)) fail(`${f}: <label for="${m[1]}"> has no matching control`);
  console.log('ok  ', f);
}
try { execFileSync('php', ['-l', path.join(root, 'api/index.php')], { stdio: 'pipe' }); console.log('ok   api/index.php'); } catch (e) { fail('php -l'); }
for (const f of ['ml/engine.py', 'ml/service.py']) {
  try { execFileSync('python3', ['-m', 'py_compile', path.join(root, f)], { stdio: 'pipe' }); console.log('ok  ', f); } catch (e) { fail(f); }
}
process.exit(bad ? 1 : 0);
