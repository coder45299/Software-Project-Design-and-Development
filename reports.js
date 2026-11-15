/* =========================================================
   reports.js - lost & found reports, admin matching.
   Demo mode  : data in localStorage, matching by ml-lite.js in the browser.
   PHP mode   : the same calls go to api/index.php (ML service does the matching).
   Needs: app.js, ml-lite.js
   ========================================================= */
const THRESH = { strong: 75, possible: 55 };
const verdict = s => s >= THRESH.strong ? ['Strong match', 'b-ok'] : s >= THRESH.possible ? ['Possible match', 'b-warn'] : ['Weak match', 'b-gray'];
const TYPE_LABEL = { lost: 'Lost', found: 'Found' };
const STATUS_LABEL = { open: ['Under review', 'b-warn'], matched: ['Match confirmed', 'b-info'], resolved: ['Reunited', 'b-ok'] };

const Reports = (() => {
  const demo = () => CONFIG.USE_DEMO;
  const isAdmin = () => (getUser() || {}).role === 'admin';

  /* API row -> the shape the pages use */
  const fromApi = r => ({
    report_id: +r.report_id, report_type: r.report_type, pet_name: r.pet_name || '', pet_id: r.pet_id ? +r.pet_id : null, species: r.species,
    image: r.image_path, description: r.description || '', phone: r.phone || '', location: r.location, found_date: r.found_date,
    reporter: r.reporter, status: r.status, created_at: r.created_at, admin_note: r.admin_note || '',
    suggestion: r.suggestion || null, matched: r.matched || null, mine: !!r.mine, reporter_id: r.reporter_id ? +r.reporter_id : null
  });

  async function list() {
    if (demo()) return [...Data.reports()].sort((a, b) => b.report_id - a.report_id);
    const res = await api(isAdmin() ? '/admin/reports' : '/lost-reports');
    return res.data.map(fromApi);
  }

  async function create(f, file) {
    const u = getUser();
    if (!demo()) {
      const fd = new FormData(); Object.entries(f).forEach(([k, v]) => { if (v !== undefined && v !== null) fd.append(k, v); }); fd.append('image', file);
      return fromApi((await api('/lost-reports', { method: 'POST', form: fd })).data);
    }
    const image = await fileToDataURL(file, 700);
    if (!image) throw new Error('That file could not be read as an image.');
    const all = Data.reports();
    const r = { report_id: Math.max(0, ...all.map(x => x.report_id)) + 1, report_type: f.report_type, pet_name: f.pet_name || '', pet_id: f.pet_id ? +f.pet_id : null,
      species: f.species, image, reporter: u.name, reporter_id: u.user_id, phone: f.phone, location: f.location, found_date: f.found_date,
      description: f.description || '', status: 'open', created_at: nowStamp() };
    try { r.embedding = await ML.embed(image); } catch (e) { r.embedding = null; r.ml_error = ML.blockedMessage(); }
    all.unshift(r); Data.saveReports(all);
    if (r.pet_id) { const pets = Data.pets(), p = pets.find(x => x.pet_id == r.pet_id); if (p && r.report_type === 'lost') { p.status = 'lost'; Data.savePets(pets); } }
    let best = null;
    if (r.embedding) { best = (await candidates(r))[0] || null; if (best && best.score >= THRESH.possible) { r.suggestion = best; Data.saveReports(all); } }
    Data.addNotif({ to: 'admin', type: 'report', report_id: r.report_id, message: `New ${r.report_type.toUpperCase()} report #${r.report_id}: ${r.pet_name ? r.pet_name + ' (' + r.species + ')' : r.species} near ${r.location}.` + (r.suggestion ? ` Closest photo: ${r.suggestion.label} (${r.suggestion.score}%).` : '') });
    Data.addNotif({ to: 'user', type: 'system', message: `We received your ${r.report_type} report #${r.report_id}. Our team will compare the photo and contact you.` });
    return r;
  }

  /** Admin: ranked look-alikes for one report. Registered pets + opposite-type reports (lost <-> found). */
  async function candidates(r, limit = 6) {
    if (!demo()) { const res = await api('/admin/reports/' + r.report_id); return res.candidates || []; }
    const vec = r.embedding || await ML.embedCached(r.image);
    const pets = Data.pets(), out = [];
    (await ML.rank(vec, pets, { species: r.species, limit: 60 })).forEach(m => {
      const p = pets.find(x => x.pet_id == m.pet_id);
      out.push({ kind: 'pet', id: p.pet_id, label: p.name, sub: `Registered ${p.species.toLowerCase()} · ${p.breed} · ${p.location}`, image: m.image, score: m.score, pet_status: p.status });
    });
    for (const o of Data.reports()) {
      if (o.report_id === r.report_id || o.report_type === r.report_type || o.species !== r.species || o.status === 'resolved') continue;
      const v = o.embedding || await ML.embedCached(o.image);
      out.push({ kind: 'report', id: o.report_id, label: `${TYPE_LABEL[o.report_type]} report #${o.report_id}`, sub: `${o.pet_name ? o.pet_name + ' · ' : ''}${o.location}`, image: o.image, score: ML.score(vec, v) });
    }
    return out.filter(c => c.score >= 25).sort((a, b) => b.score - a.score).slice(0, limit);
  }

  /** Admin: compare the report photo with any other photo the admin uploads. -> {score} */
  async function compareWith(r, file) {
    if (!demo()) { const fd = new FormData(); fd.append('image', file); return await api('/admin/reports/' + r.report_id + '/compare', { method: 'POST', form: fd }); }
    const [a, b] = [r.embedding || await ML.embedCached(r.image), await ML.embed(file)];
    return { score: ML.score(a, b), cosine: ML.cosine(a, b) };
  }

  /** Admin decision. action: confirm | reject | reunite.  cand = {kind,id,label,score} */
  async function decide(r, { action, cand, note = '' }) {
    if (action === 'reunite' && r.matched) cand = r.matched;           // reuse the confirmed match
    if (!demo()) { await api('/admin/reports/' + r.report_id + '/decide', { method: 'POST', body: { action, candidate_kind: cand && cand.kind, candidate_id: cand && cand.id, note } }); return; }
    const all = Data.reports(), me = all.find(x => x.report_id === r.report_id), other = cand && cand.kind === 'report' ? all.find(x => x.report_id === cand.id) : null;
    const pets = Data.pets(), pet = cand && cand.kind === 'pet' ? pets.find(p => p.pet_id == cand.id) : null;
    me.admin_note = note;
    if (action === 'reject') { me.status = 'open'; me.matched = null; Data.saveReports(all); return; }
    me.matched = cand ? { kind: cand.kind, id: cand.id, label: cand.label, score: cand.score } : null;
    me.status = action === 'reunite' ? 'resolved' : 'matched';
    if (other) { other.status = me.status; other.matched = { kind: 'report', id: me.report_id, label: `${TYPE_LABEL[me.report_type]} report #${me.report_id}`, score: cand.score }; }
    if (action === 'reunite') {                                        // the pet is home again: clear 'lost' on the matched pet AND on the reporter's own pet
      let dirty = false;
      [pet, me.pet_id ? pets.find(p => p.pet_id == me.pet_id) : null, other && other.pet_id ? pets.find(p => p.pet_id == other.pet_id) : null].forEach(p => { if (p && p.status === 'lost') { p.status = 'owned'; dirty = true; } });
      if (dirty) Data.savePets(pets);
    }
    Data.saveReports(all);
    if (action === 'confirm') {
      Data.addNotif({ to: 'user', type: 'match', message: `Our team matched your ${me.report_type} report #${me.report_id} with ${cand.label}. We will share contact details shortly.` });
      if (pet) Data.addNotif({ to: 'user', type: 'match', message: `Good news: a photo reported near ${me.location} matches your pet ${pet.name}. Reporter phone: ${me.phone || 'not given'}.` });
    } else Data.addNotif({ to: 'user', type: 'match', message: `Report #${me.report_id} is marked reunited. Thank you for helping!` });
  }

  async function remove(r) {
    if (!demo()) { await api('/lost-reports/' + r.report_id, { method: 'DELETE' }); return; }
    Data.saveReports(Data.reports().filter(x => x.report_id !== r.report_id));
  }

  const ext = r => { const m = /^data:image\/(\w+)/.exec(r.image || ''); return m ? (m[1] === 'jpeg' ? 'jpg' : m[1]) : ((r.image || '').split('.').pop().split('?')[0] || 'jpg'); };
  /** Link + file name for the "Download photo" button */
  const download = r => ({
    href: demo() ? r.image : `${CONFIG.API_BASE}/admin/reports/${r.report_id}/photo`,
    name: `petbio-report-${r.report_id}-${r.report_type}-${(r.species || 'pet').toLowerCase()}.${ext(r)}`
  });

  return { list, create, candidates, compareWith, decide, remove, download, fromApi };
})();
