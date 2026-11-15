const { chromium } = require('playwright'), fs = require('fs'), path = require('path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const PAGES = ['index', 'register', 'adoption', 'pet-details?id=1', 'find-my-pet', 'lost-found', 'dashboard', 'care-tracker', 'notifications', 'pet-form', 'admin', 'admin-login', 'gallery'];
async function launch() { return chromium.launch(); }
/** New isolated browser context = a fresh localStorage = a fresh demo database. */
async function ctx(browser, opts = {}) {
  const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, ...opts });
  if (!process.env.COVERAGE) return c;
  // Optional V8 coverage of our own scripts; merged into a report by tests/coverage-report.js
  const pages = []; c.on('page', p => { pages.push(p); p.coverage.startJSCoverage({ resetOnNavigation: false }).catch(() => {}); });
  const close = c.close.bind(c);
  c.close = async () => {
    const out = [];
    for (const p of pages) { try { out.push(...(await p.coverage.stopJSCoverage()).filter(e => e.url.startsWith(BASE) && /\.js$/.test(e.url))); } catch (e) {} }
    const dir = path.join(__dirname, '..', '.cov'); fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, `e2e-${process.pid}-${Date.now()}-${Math.random().toString(16).slice(2)}.json`), JSON.stringify(out));
    return close();
  };
  return c;
}
async function loginUser(page, email = 'demo@petbio.com') {
  await page.goto(BASE + '/register.html?tab=login');
  await page.evaluate(e => localStorage.setItem('pb_user', JSON.stringify({ user_id: 1, name: 'Demo User', email: e, role: 'user' })), email);
}
async function loginAdmin(page) {
  await page.goto(BASE + '/admin-login.html');
  await page.fill('input[name=email]', 'admin@petbio.com'); await page.fill('input[name=password]', 'admin123');
  await Promise.all([page.waitForURL('**/admin.html'), page.click('#go')]);
}
module.exports = { BASE, PAGES, launch, ctx, loginUser, loginAdmin };
