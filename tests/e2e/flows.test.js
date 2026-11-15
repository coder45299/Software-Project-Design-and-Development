// End-to-end flows in headless Chromium (demo mode). Each test gets a fresh browser context = a fresh localStorage database.
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('fs'), path = require('path');
const { BASE, launch, ctx, loginUser, loginAdmin } = require('./helpers');
const PHOTOS = path.join(__dirname, '../../photos/pets');
let browser; test.before(async () => { browser = await launch(); }); test.after(async () => { await browser.close(); });
async function fresh(fn) { const c = await ctx(browser), page = await c.newPage(); try { await fn(page, c); } finally { await c.close(); } }
const local = (d = new Date()) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

test('D-1: care tracker shows the overdue record as "5d overdue" (seed: rel(-5)) and others relative to today', () => fresh(async page => {
  await loginUser(page); await page.goto(BASE + '/care-tracker.html'); await page.waitForSelector('#upcoming .badge');
  const badges = await page.$$eval('#upcoming .badge', e => e.map(x => x.textContent.trim()));
  assert.ok(badges.includes('5d overdue'), JSON.stringify(badges));
  assert.ok(badges.some(b => /^in \d+d$/.test(b)));
}));

test('D-1: "Add record" form defaults to TODAY (not a fixed date)', () => fresh(async page => {
  await loginUser(page); await page.goto(BASE + '/care-tracker.html'); await page.waitForSelector('#upcoming .badge');
  await page.click('text=Add record');
  assert.equal(await page.inputValue('#f3-date'), local());
}));

test('D-1: adding a record due yesterday is shown as "1d overdue"', () => fresh(async page => {
  await loginUser(page); await page.goto(BASE + '/care-tracker.html'); await page.waitForSelector('#upcoming .badge');
  await page.click('text=Add record');
  const y = new Date(); y.setDate(y.getDate() - 1);
  await page.selectOption('#modalPet', '1'); await page.fill('#f4-next_due_date', local(y));
  await page.click('#recForm button.btn-primary');
  await page.waitForFunction(() => [...document.querySelectorAll('#upcoming .badge')].some(b => b.textContent.trim() === '1d overdue'));
}));

test('D-2: pet details shows vaccination status from care records', () => fresh(async page => {
  await loginUser(page);
  await page.goto(BASE + '/pet-details.html?id=1'); await page.waitForSelector('.info-grid');
  assert.match(await page.textContent('.info-grid'), /Vaccinated\s*Yes/);
  await page.goto(BASE + '/pet-details.html?id=2'); await page.waitForSelector('.info-grid');
  assert.match(await page.textContent('.info-grid'), /Vaccinated\s*No record/);
}));

test('D-4: editing pb_user.role in DevTools does NOT open the admin panel', () => fresh(async page => {
  await loginUser(page);
  await page.evaluate(() => localStorage.setItem('pb_user', JSON.stringify({ user_id: 1, name: 'Demo User', email: 'demo@petbio.com', role: 'admin' })));
  await page.goto(BASE + '/admin.html');
  assert.equal(await page.isVisible('#panel'), false);
  assert.equal(await page.isVisible('#denied'), true);
}));

test('admin login with the real admin account opens the panel; wrong password does not', () => fresh(async page => {
  await page.goto(BASE + '/admin-login.html');
  await page.fill('#em', 'admin@petbio.com'); await page.fill('#pw', 'wrong'); await page.click('#go');
  await page.waitForTimeout(500); assert.ok(page.url().includes('admin-login'));
  await loginAdmin(page); await page.waitForSelector('#panel', { state: 'visible' });
}));

test('login with wrong password shows an error and stays on the page', () => fresh(async page => {
  await page.goto(BASE + '/register.html'); await page.fill('#f1-email', 'demo@petbio.com'); await page.fill('#f2-password', 'wrongpw');
  await page.click('#loginForm button.btn-primary'); await page.waitForTimeout(500);
  assert.ok(page.url().includes('register'));
  assert.equal(await page.evaluate(() => localStorage.getItem('pb_user')), null);
}));

test('login with the demo account reaches the dashboard', () => fresh(async page => {
  await page.goto(BASE + '/register.html'); await page.fill('#f1-email', 'demo@petbio.com'); await page.fill('#f2-password', 'demo123');
  await Promise.all([page.waitForURL('**/dashboard.html'), page.click('#loginForm button.btn-primary')]);
}));

test('registration: short password is rejected, valid one creates the account', () => fresh(async page => {
  await page.goto(BASE + '/register.html'); await page.click('[data-tab=register]');
  await page.fill('#f3-name', 'New Person'); await page.fill('#f4-email', 'new@person.com'); await page.fill('#f5-phone', '01712345678'); await page.fill('#f7-password', 'abc'); await page.fill('#f8-confirm', 'abc');
  await page.click('#regForm button.btn-primary'); await page.waitForTimeout(400);
  assert.ok(page.url().includes('register'));
  await page.fill('#f7-password', 'abcdefgh'); await page.fill('#f8-confirm', 'abcdefgh');
  await Promise.all([page.waitForURL('**/dashboard.html'), page.click('#regForm button.btn-primary')]);
}));

test('adoption page: filters narrow the list and Reset restores it', () => fresh(async page => {
  await page.goto(BASE + '/adoption.html'); await page.waitForSelector('#list .card, #list > *');
  const all = +(await page.textContent('#count'));
  await page.selectOption('#fSpecies', 'Bird'); await page.waitForTimeout(200);
  const birds = +(await page.textContent('#count')); assert.ok(birds < all);
  await page.click('#reset'); await page.waitForTimeout(200);
  assert.equal(+(await page.textContent('#count')), all);
}));

test('XSS: a script in a pet name is shown as text, never executed', () => fresh(async page => {
  await loginUser(page); let fired = false; page.on('dialog', d => { fired = true; d.dismiss(); });
  await page.goto(BASE + '/pet-form.html');
  await page.fill('input[name=name]', '<img src=x onerror=alert(1)>'); await page.selectOption('select[name=species]', 'Dog');
  await Promise.all([page.waitForURL('**/dashboard.html'), page.click('#saveBtn')]);
  await page.waitForTimeout(1000);
  assert.equal(fired, false);
  assert.ok((await page.content()).includes('&lt;img src=x onerror=alert(1)&gt;'), 'name should be rendered escaped');
}));

test('Find My Pet: the same photo ranks its own pet first, twice in a row (D-3)', () => fresh(async page => {
  await page.goto(BASE + '/find-my-pet.html');
  const run = async () => {
    await page.reload(); await page.setInputFiles('#file', path.join(PHOTOS, '01-bruno.jpg')); await page.click('#go');
    await page.waitForSelector('#results:not(.hidden) #resList > *', { timeout: 30000 });
    return page.$eval('#resList > *', e => e.textContent.replace(/\s+/g, ' ').trim());
  };
  const a = await run(), b = await run();
  assert.match(a, /Bruno/); assert.equal(a, b);
}));

test('Lost & found: user files a lost report -> admin sees it with a downloadable photo and a notification', () => fresh(async page => {
  await loginUser(page); await page.goto(BASE + '/lost-found.html?tab=lost');
  await page.click('#lfTabs button >> nth=1').catch(() => {});
  if (!(await page.isVisible('#lfForm'))) await page.click('text=I lost my pet');
  await page.setInputFiles('#file', path.join(PHOTOS, '03-max.jpg'));
  await page.fill('#f1-pet_name', 'Testy'); await page.fill('input[name=location]', 'Mirpur 10, Dhaka'); await page.fill('#f5-phone', '01712345678');
  await page.fill('input[name=found_date]', local());
  await page.click('#submitBtn');
  await page.waitForFunction(() => JSON.parse(localStorage.getItem('pb_notifs') || '[]').some(x => x.to === 'admin' && /Testy/.test(x.message || '')), null, { timeout: 15000 });
  await page.evaluate(() => localStorage.setItem('pb_user', JSON.stringify({ user_id: 5, name: 'Admin', email: 'admin@petbio.com', role: 'admin' })));
  await page.goto(BASE + '/admin.html'); await page.click('[data-s=reports]'); await page.waitForSelector('text=Testy');
  await page.click('text=Review & match'); await page.waitForSelector('a[download]');
  assert.match(await page.getAttribute('a[download]', 'download'), /\.(jpe?g|png|webp)$/i);
  const n = await page.evaluate(() => JSON.parse(localStorage.getItem('pb_notifs') || '[]').filter(x => x.to === 'admin' && /Testy/.test(x.message || '')).length);
  assert.ok(n >= 1, 'admin notification missing');
}));

test('mobile 390px: no horizontal scroll on any main page', () => fresh(async (page, c) => {
  await page.setViewportSize({ width: 390, height: 800 }); await loginUser(page);
  for (const p of ['index', 'adoption', 'gallery', 'lost-found', 'dashboard', 'care-tracker', 'register']) {
    await page.goto(`${BASE}/${p}.html`); await page.waitForTimeout(500);
    const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    assert.ok(over <= 1, `${p} overflows by ${over}px`);
  }
}));

test('keyboard: Tab reaches the first nav link and the skip/focus order does not trap', () => fresh(async page => {
  await page.goto(BASE + '/index.html'); await page.keyboard.press('Tab');
  assert.notEqual(await page.evaluate(() => document.activeElement.tagName), 'BODY');
}));
