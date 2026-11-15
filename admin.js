/* =========================================================
   admin.js - everything the admin can control, in one place.
   Demo mode : data in localStorage.   PHP mode : api/index.php (/admin/...).
   Needs: app.js
   ========================================================= */
const AdminData = (() => {
  const demo = () => CONFIG.USE_DEMO;
  const U = () => Store.get('a_users', DEMO.users.map(u => ({ is_active: 1, ...u })));
  const saveU = v => Store.set('a_users', v);
  const R = () => Store.get('a_reqs', DEMO.requests);
  const saveR = v => Store.set('a_reqs', v);

  return {
    async users() {
      if (!demo()) return (await api('/admin/users')).data;
      const pets = Data.pets();
      return U().map(u => ({ ...u, is_active: u.is_active === 0 ? 0 : 1, pets: pets.filter(p => p.owner_id == u.user_id).length }));
    },
    async setRole(id, role) { if (!demo()) return api(`/admin/users/${id}/role`, { method: 'POST', body: { role } }); saveU(U().map(u => u.user_id == id ? { ...u, role } : u)); },
    async setActive(id, active) {
      if (!demo()) return api(`/admin/users/${id}/active`, { method: 'POST', body: { active: active ? 1 : 0 } });
      saveU(U().map(u => u.user_id == id ? { ...u, is_active: active ? 1 : 0 } : u));
    },
    async delUser(id) { if (!demo()) return api('/admin/users/' + id, { method: 'DELETE' }); saveU(U().filter(u => u.user_id != id)); },

    async pets() {
      if (!demo()) return (await api('/admin/pets')).data;
      const us = U(); return Data.pets().map(p => ({ ...p, owner_name: (us.find(u => u.user_id == p.owner_id) || {}).name || 'Unknown' }));
    },
    async setPetStatus(id, status) {
      if (!demo()) return api(`/admin/pets/${id}/status`, { method: 'POST', body: { status } });
      Data.savePets(Data.pets().map(p => p.pet_id == id ? { ...p, status } : p));
    },
    async delPet(id) { if (!demo()) return api('/pets/' + id, { method: 'DELETE' }); Data.savePets(Data.pets().filter(p => p.pet_id != id)); },

    async requests() { return demo() ? R() : (await api('/admin/requests')).data; },
    async decideRequest(id, status) {
      if (!demo()) return api(`/adoption/requests/${id}/decide`, { method: 'POST', body: { status } });
      const all = R(), r = all.find(x => x.request_id == id); r.status = status;
      if (status === 'approved') { const p = Data.pets().find(x => x.name === r.pet); if (p) Data.savePets(Data.pets().map(x => x.pet_id === p.pet_id ? { ...x, status: 'adopted' } : x)); }
      saveR(all);
      Data.addNotif({ to: 'user', type: 'adoption', message: `Your adoption request for ${r.pet} was ${status}.` });
    },

    /** announcement to every regular user, or to one user. Returns how many people got it. */
    async announce(message, userId) {
      if (!demo()) return (await api('/admin/broadcast', { method: 'POST', body: { message, user_id: userId || 0 } })).sent;
      Data.addNotif({ to: 'user', type: 'system', message }); return userId ? 1 : U().filter(u => u.role === 'user' && u.is_active !== 0).length;
    }
  };
})();
