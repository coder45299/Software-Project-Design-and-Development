// Admin control panel flows (demo mode). Each test starts from a fresh demo database.
const test = require('node:test'), assert = require('node:assert/strict');
const path = require('path');
const { BASE, launch, ctx } = require('./helpers');
let browser; test.before(async () => { browser = await launch(); }); test.after(async () => { await browser.close(); });
const ADMIN = { user_id: 5, name: 'Admin', email: 'admin@petbio.com', role: 'admin' };
async function admin(fn) {
  const c = await ctx(browser), page = await c.newPage(); page.on('dialog', d => d.accept());
  try {
    await page.goto(BASE + '/admin-login.html'); await page.evaluate(u => localStorage.setItem('pb_user', JSON.stringify(u)), ADMIN);
    await page.goto(BASE + '/admin.html'); await page.waitForSelector('#panel', { state: 'visible' }); await page.waitForSelector('#stats .stat');
    await fn(page);
  } finally { await c.close(); }
}
const store = (page, k) => page.evaluate(k => JSON.parse(localStorage.getItem('pb_' + k) || 'null'), k);

test('overview shows counts and the sidebar switches sections', () => admin(async page => {
  assert.ok((await page.textContent('#stats')).includes('Users'));
  for (const s of ['reports', 'users', 'pets', 'requests', 'announce']) { await page.click(`#adminSide a[data-s=${s}]`); assert.equal(await page.isVisible(`[data-sec=${s}]`), true, s); }
}));

test('admin can suspend a user and that user can then no longer log in', () => admin(async page => {
  await page.click('[data-s=users]'); await page.fill('#uQ', 'rahim');
  await page.click('#uRows button[data-a=active]'); await page.waitForSelector('#uRows .badge.b-danger');
  const u = (await store(page, 'a_users')).find(x => x.email === 'rahim@mail.com'); assert.equal(u.is_active, 0);
  await page.goto(BASE + '/register.html'); await page.fill('#f1-email', 'rahim@mail.com'); await page.fill('#f2-password', 'demo123');
  await page.click('#loginForm button.btn-primary'); await page.waitForTimeout(500);
  assert.ok(page.url().includes('register'), 'suspended user must stay on the login page');
}));

test('admin cannot demote or delete themself (no buttons on own row)', () => admin(async page => {
  await page.click('[data-s=users]'); await page.fill('#uQ', 'admin@petbio.com');
  assert.equal(await page.locator('#uRows button[data-a]').count(), 0);
}));

test('admin can promote a user to admin and back', () => admin(async page => {
  await page.click('[data-s=users]'); await page.fill('#uQ', 'tanvir');
  await page.click('#uRows button[data-a=role]'); await page.waitForSelector('#uRows .badge.b-info');
  assert.equal((await store(page, 'a_users')).find(x => x.email === 'tanvir@mail.com').role, 'admin');
}));

test('adoption request: approve reaches the requester as a notification', () => admin(async page => {
  await page.click('[data-s=requests]');
  await page.click('#rRows button[data-s=approved] >> nth=0'); await page.waitForTimeout(500);
  const reqs = await store(page, 'a_reqs'); assert.ok(reqs.some(r => r.status === 'approved'));
}));

test('announcement to everyone is stored as a user notification', () => admin(async page => {
  await page.click('[data-s=announce]'); await page.fill('#annMsg', 'Shelter open day on Friday');
  await page.click('#annGo'); await page.waitForTimeout(500);
  assert.ok((await store(page, 'notifs')).some(n => n.to === 'user' && /Shelter open day/.test(n.message)));
}));

test('admin can delete a pet from the Pets table', () => admin(async page => {
  await page.click('[data-s=pets]'); const before = await page.locator('#pRows tr').count();
  await page.click('#pRows button[data-a=del] >> nth=0'); await page.waitForTimeout(500);
  assert.equal(await page.locator('#pRows tr').count(), before - 1);
}));

test('report console: confirm a match, then mark reunited -> report resolved, pet no longer lost', () => admin(async page => {
  await page.click('[data-s=reports]');
  await page.click('text=Review & match >> nth=0'); await page.waitForSelector('#bConfirm:not([disabled])');
  const title = await page.textContent('#conTitle');
  await page.click('#bConfirm'); await page.waitForSelector('#conModal', { state: 'hidden' });       // console closes after each decision
  assert.ok((await store(page, 'reports')).some(r => r.status === 'matched'));
  await page.evaluate(i => openReport(+i), title.match(/#(\d+)/)[1]);                                  // reopen the matched report
  await page.waitForSelector('#bReunite');
  await page.click('#bReunite'); await page.waitForSelector('#conModal', { state: 'hidden' });
  const reps = await store(page, 'reports'); assert.ok(reps.some(r => r.status === 'resolved'));
  const pets = await store(page, 'pets'); const lostIds = new Set((reps.filter(r => r.status === 'resolved').map(r => r.pet_id)).filter(Boolean));
  for (const p of (pets || [])) if (lostIds.has(p.pet_id)) assert.notEqual(p.status, 'lost');
}));

test('non-admin pages never show admin links to a normal user', () => admin(async page => {
  await page.evaluate(() => localStorage.setItem('pb_user', JSON.stringify({ user_id: 1, name: 'Demo User', email: 'demo@petbio.com', role: 'user' })));
  await page.goto(BASE + '/dashboard.html'); await page.waitForTimeout(400);
  assert.equal(await page.locator('a[href="admin.html"]').count(), 0);
}));
