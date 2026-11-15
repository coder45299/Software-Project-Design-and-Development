// D-7 regression: with CONFIG.USE_DEMO = false the dashboard must show what the SERVER returns,
// not the browser's demo data. The API is stubbed at the network layer so the test is deterministic.
const test = require('node:test'), assert = require('node:assert/strict');
const { BASE, launch, ctx } = require('./helpers');
let browser; test.before(async () => { browser = await launch(); }); test.after(async () => { await browser.close(); });

test('D-7: dashboard in server mode renders pets, reminders and alerts from the API', async () => {
  const c = await ctx(browser), page = await c.newPage(), calls = [];
  try {
    await page.route('**/app.js', async r => { const t = (await (await r.fetch()).text()).replace('USE_DEMO: true', 'USE_DEMO: false'); r.fulfill({ contentType: 'application/javascript', body: t }); });
    const json = (p, body) => page.route('**/api/index.php' + p, r => { calls.push(p); r.fulfill({ contentType: 'application/json', body: JSON.stringify(body) }); });
    await json('/pets/mine', { data: [{ pet_id: 901, owner_id: 7, name: 'ServerPet', breed: 'Mixed', age: 2, status: 'owned', species: 'dog' }] });
    await json('/care/upcoming', { data: [{ care_id: 1, pet_id: 901, type: 'Vaccination', next_due_date: '2000-01-01', pet_name: 'ServerPet' }] });
    await json('/notifications', { data: [{ notif_id: 1, type: 'system', title: 'x', message: 'm', is_read: 0 }], unread: 1 });
    await json('/auth/me', { user: { user_id: 7, name: 'Srv', email: 's@x.com', role: 'user' } });
    await page.goto(BASE + '/register.html?tab=login');
    await page.evaluate(() => localStorage.setItem('pb_user', JSON.stringify({ user_id: 7, name: 'Srv User', email: 's@x.com', role: 'user' })));
    await page.goto(BASE + '/dashboard.html'); await page.waitForSelector('#myPets .card');
    const html = await page.textContent('#myPets');
    assert.ok(html.includes('ServerPet'), 'shows the pet returned by /pets/mine');
    assert.ok(!html.includes('Bella') && !html.includes('Max'), 'does not show demo-mode pets');
    assert.ok((await page.textContent('#reminders')).includes('overdue'));
    assert.ok(calls.includes('/pets/mine') && calls.includes('/care/upcoming') && calls.includes('/notifications'));
  } finally { await c.close(); }
});
