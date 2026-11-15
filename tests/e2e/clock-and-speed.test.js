// Extra checks: time travel (no fixed date anywhere) and page-load speed.
// Browser tests (Playwright, headless Chromium) against the demo-mode site. Each test gets a fresh browser context = fresh demo database.
const test = require('node:test'), assert = require('node:assert/strict');
const { BASE, launch, ctx, loginUser, loginAdmin } = require('./helpers');
let browser; test.before(async () => { browser = await launch(); }); test.after(async () => { await browser.close(); });
async function fresh(login = true) { const c = await ctx(browser), page = await c.newPage(); if (login) await loginUser(page); return { c, page }; }

test('D-1 the page keeps working when the clock moves forward 2 years (no fixed date)', async () => {
  const c = await ctx(browser), page = await c.newPage();
  await page.clock.install({ time: new Date('2028-11-12T10:00:00') });
  await loginUser(page); await page.goto(BASE + '/care-tracker.html'); await page.waitForSelector('.reminder');
  const txt = (await page.locator('.reminder').allInnerTexts()).join(' | ');
  assert.match(txt, /Grooming[\s\S]*5d overdue/);
  await c.close();
});

test('Performance: every page loads (DOMContentLoaded) in under 2 s on localhost', async () => {
  const { c, page } = await fresh();
  for (const p of ['index', 'adoption', 'gallery', 'dashboard']) {
    const t0 = Date.now(); await page.goto(`${BASE}/${p}.html`, { waitUntil: 'domcontentloaded' }); assert.ok(Date.now() - t0 < 2000, `${p} ${Date.now() - t0}ms`);
  }
  await c.close();
});

