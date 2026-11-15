// Accessibility: axe-core (WCAG 2.0/2.1 A + AA) on every page. Zero critical/serious violations allowed.
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('fs'), path = require('path');
const { BASE, PAGES, launch, ctx, loginUser } = require('./helpers');
const axeSrc = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

let browser; test.before(async () => { browser = await launch(); }); test.after(async () => { await browser.close(); });

for (const p of PAGES) {
  test(`AX ${p}: no critical/serious axe violations`, async () => {
    const c = await ctx(browser), page = await c.newPage();
    await loginUser(page);
    if (p === 'admin') { await page.evaluate(() => localStorage.setItem('pb_user', JSON.stringify({ user_id: 5, name: 'Admin', email: 'admin@petbio.com', role: 'admin' }))); }
    await page.goto(`${BASE}/${p.includes('?') ? p.replace('?', '.html?') : p + '.html'}`); await page.waitForTimeout(600);
    await page.evaluate(axeSrc);
    const res = await page.evaluate(() => axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }));
    const bad = res.violations.filter(v => ['critical', 'serious'].includes(v.impact));
    if (bad.length) fs.writeFileSync(path.join(__dirname, `axe-${p.replace(/\W/g, '_')}.json`), JSON.stringify(bad.map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.slice(0, 6).map(n => [n.target.join(' '), (n.any[0] || n.all[0] || n.none[0] || {}).message]) })), null, 1));
    assert.deepEqual(bad.map(v => `${v.id}(${v.nodes.length})`), [], p);
    await c.close();
  });
}
