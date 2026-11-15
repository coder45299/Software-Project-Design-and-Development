// Unit tests for the shared logic in app.js, run in jsdom. Inputs come from tests/cases/design.json.
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('fs'), path = require('path'), vm = require('vm'), { JSDOM } = require('jsdom');
const root = path.join(__dirname, '../..');
const design = JSON.parse(fs.readFileSync(path.join(__dirname, '../cases/design.json'), 'utf8'));

// Top-level const/let from app.js must stay visible to later snippets, so run everything as vm scripts in one context.
function load(file = 'app.js') {
  const dom = new JSDOM('<!doctype html><body></body>', { url: 'http://localhost/', runScripts: 'outside-only' });
  const ctx = dom.getInternalVMContext();
  const src = fs.readFileSync(path.join(root, file), 'utf8'); const run = code => new vm.Script(code, { filename: code === src ? path.join(root, file) : 'snippet.js' }).runInContext(ctx);
  run(src);
  return { eval: run, window: dom.window };
}
const bucket = d => d < 0 ? 'overdue' : d <= 14 ? 'soon' : 'upcoming';

test('daysUntil / reminder bucket: boundary table (D-1 regression)', () => {
  const w = load();
  for (const r of design.date_boundaries.rows) {
    const got = w.eval(`daysUntil('${r.due}', new Date('${r.now}'))`);
    assert.equal(got, r.days, `${r.id}: due ${r.due} from ${r.now}`);
    assert.equal(bucket(got), r.bucket, `${r.id} bucket`);
  }
});

test('daysUntil with no clock argument follows the real today (not a fixed date)', () => {
  const w = load();
  assert.equal(w.eval(`daysUntil(rel(0))`), 0);
  assert.equal(w.eval(`daysUntil(rel(-2))`), -2);
  assert.equal(w.eval(`daysUntil(rel(8))`), 8);
  assert.equal(w.eval(`daysUntil(todayISO())`), 0);
});

test('todayISO / rel use the local calendar day', () => {
  const w = load();
  assert.equal(w.eval(`todayISO(new Date(2026, 10, 11, 0, 5))`), '2026-11-11');
  assert.equal(w.eval(`todayISO(new Date(2026, 10, 11, 23, 55))`), '2026-11-11');
  assert.equal(w.eval(`rel(1, new Date(2026, 11, 31, 12))`), '2027-01-01');
  assert.equal(w.eval(`rel(-1, new Date(2028, 2, 1, 12))`), '2028-02-29');
});

test('seeded care records are relative to today, not hard-coded', () => {
  const w = load();
  const care = w.eval('DEMO.care');
  const overdue = care.filter(c => w.eval(`daysUntil('${c.next_due_date}')`) < 0);
  assert.equal(overdue.length, 1);
  assert.equal(overdue[0].type, 'Grooming');
});

test('Data.vaccinated derives from care records (D-2 regression)', () => {
  const w = load();
  assert.equal(w.eval('Data.vaccinated(1)'), 'Yes');          // pet 1 has a Vaccination record in the seed
  assert.equal(w.eval('Data.vaccinated(2)'), 'No record');    // pet 2 only has deworming / checkup
  assert.equal(w.eval('Data.vaccinated(999)'), 'No record');
  w.eval(`Data.saveCare([{record_id:9,pet_id:2,type:'Vaccination',date:rel(+3),notes:'',next_due_date:null}])`);
  assert.equal(w.eval('Data.vaccinated(2)'), 'No record', 'a vaccination dated in the future is not done yet');
  w.eval(`Data.saveCare([{record_id:9,pet_id:2,type:'Vaccination',date:rel(-1),notes:'',next_due_date:null}])`);
  assert.equal(w.eval('Data.vaccinated(2)'), 'Yes');
});

test('escaping helper neutralises HTML (XSS)', () => {
  const w = load();
  const out = w.eval(`esc('<img src=x onerror=alert(1)>"&')`);
  assert.ok(!out.includes('<img') && out.includes('&lt;'));
});

test('admin gate: editing pb_user.role alone does not grant admin (D-4 regression)', () => {
  const w = load();
  w.eval(`Store.set('user', {user_id: 1, name:'Demo User', email:'demo@petbio.com', role:'user'})`);
  assert.equal(w.eval('adminLocalCheck()'), false);
  w.eval(`Store.set('user', {user_id: 1, name:'Demo User', email:'demo@petbio.com', role:'admin'})`);   // DevTools edit
  assert.equal(w.eval('adminLocalCheck()'), false);
  w.eval(`Store.set('user', {user_id: 5, name:'Admin', email:'admin@petbio.com', role:'admin'})`);      // real admin login
  assert.equal(w.eval('adminLocalCheck()'), true);
  w.eval(`Store.set('a_users', DEMO.users.map(u => u.user_id == 5 ? {...u, role:'user'} : u))`);       // demoted in the user table
  assert.equal(w.eval('adminLocalCheck()'), false);
});

test('notification audience: admin-only items are hidden from users', () => {
  const w = load();
  w.eval(`Store.set('user', {user_id:1, role:'user'})`);
  assert.equal(w.eval(`forMe({to:'admin'})`), false);
  assert.equal(w.eval(`forMe({to:'user'})`), true);
  w.eval(`Store.set('user', {user_id:5, role:'admin'})`);
  assert.equal(w.eval(`forMe({to:'admin'})`), true);
  assert.equal(w.eval(`forMe({to:'user'})`), false);
});

test('Find My Pet scoring is deterministic (D-3 regression): same input -> same score', () => {
  const w = load('ml-lite.js');
  w.eval(`var a = Array.from({length:248}, (_, i) => Math.abs(Math.sin(i + 1))), b = Array.from({length:248}, (_, i) => Math.abs(Math.cos(i + 2)));`);
  const s1 = w.eval('ML.score(a, b)'), s2 = w.eval('ML.score(a, b)');
  assert.equal(s1, s2);
  assert.equal(w.eval('ML.score(a, a)'), 100);
  assert.ok(s1 >= 0 && s1 <= 100);
});

test('demo login: wrong password rejected, right one accepted, unknown e-mail rejected', () => {
  const w = load();
  assert.throws(() => w.eval(`demoAuth.login('demo@petbio.com', 'wrong')`), /Wrong email or password/);
  assert.throws(() => w.eval(`demoAuth.login('nobody@x.com', 'demo123')`), /Wrong email or password/);
  assert.equal(w.eval(`demoAuth.login('DEMO@petbio.com ', 'demo123').user_id`), 1);
  assert.equal(w.eval(`demoAuth.login('admin@petbio.com', 'admin123').role`), 'admin');
  assert.throws(() => w.eval(`demoAuth.login('admin@petbio.com', 'demo123')`));
});

test('demo register follows the same rules as the PHP API (design.json table)', () => {
  for (const r of design.register.rows) {
    const w = load();   // fresh storage per row
    const call = () => w.eval(`demoAuth.register(${JSON.stringify({ name: r.name, email: 'x@y.com', password: r.password, phone: r.phone })})`);
    if (r.http === 201) assert.ok(call().user_id, r.id); else assert.throws(call, undefined, `${r.id}: ${r.why}`);
  }
  for (const e of design.register.bad_emails) {
    const w = load(); assert.throws(() => w.eval(`demoAuth.register(${JSON.stringify({ name: 'A', email: e, password: 'abcdefgh' })})`), undefined, e);
  }
});

test('demo register: duplicate e-mail rejected, new user can log in, suspended user cannot', () => {
  const w = load();
  w.eval(`demoAuth.register({name:'Neo', email:'neo@x.com', password:'abcdefgh'})`);
  assert.throws(() => w.eval(`demoAuth.register({name:'Neo2', email:'NEO@x.com', password:'abcdefgh'})`), /already registered/);
  assert.equal(w.eval(`demoAuth.login('neo@x.com','abcdefgh').name`), 'Neo');
  w.eval(`Store.set('a_users', demoAuth.users().map(u => u.email === 'neo@x.com' ? {...u, is_active: 0} : u))`);
  assert.throws(() => w.eval(`demoAuth.login('neo@x.com','abcdefgh')`), /suspended/);
});
