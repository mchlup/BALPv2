(() => {
  const $ = (s, p=document) => p.querySelector(s);
  const $$ = (s, p=document) => Array.from(p.querySelectorAll(s));

  const showAlert = (msg, type='info') => {
    const box = $('#alert-box');
    box.className = `alert alert-${type}`;
    box.textContent = msg;
    box.classList.remove('d-none');
  };
  const hideAlert = () => { const box = $('#alert-box'); box.classList.add('d-none'); box.textContent=''; };

  const storageKey = 'balp_token';
  const saveToken = (t) => { try { localStorage.setItem(storageKey, t || ''); } catch(e) {} };
  const getToken = () => { try { return localStorage.getItem(storageKey) || ''; } catch(e) { return ''; } };
  const clearToken = () => { try { localStorage.removeItem(storageKey); } catch(e) {} };

  const apiHeaders = () => {
    const h = {'Content-Type':'application/json'};
    const t = getToken();
    if (t) h['Authorization'] = 'Bearer ' + t;
    return h;
  };

  async function apiFetch(url, opts={}) {
    const full = url.includes('?') ? `${url}&_ts=${Date.now()}` : `${url}?_ts=${Date.now()}`;
    const token = getToken();
    const withTokenUrl = token ? `${full}&token=${encodeURIComponent(token)}` : full;
    const resp = await fetch(withTokenUrl, {
      method: (opts.method || 'GET'),
      headers: {...apiHeaders(), ...(opts.headers||{})},
      body: opts.body,
      credentials: 'include'
    });
    const text = await resp.text();
    let data = null;
    try { data = JSON.parse(text); } catch {}
    if (!resp.ok) {
      const msg = data?.error || data?.message || text || `HTTP ${resp.status}`;
      throw new Error(msg);
    }
    return data ?? text;
  }

  async function login(username, password) {
    const payload = JSON.stringify({username, password});
    const data = await apiFetch(API_URL + '?action=auth_login', { method: 'POST', body: payload });
    if (!data?.token) throw new Error('Server nevrátil token.');
    saveToken(data.token);
    try { await apiFetch('/balp2/api/token_set_cookie.php?token=' + encodeURIComponent(data.token)); } catch {}
    return data;
  }
  async function authMe() { return apiFetch(API_URL + '?action=auth_me'); }

  function updateUiLoggedIn(user) {
    $('#user-info').textContent = user?.username ? `Přihlášen: ${user.username}` : 'Přihlášeno';
    $('#btn-login').classList.add('d-none');
    $('#btn-logout').classList.remove('d-none');
  }
  function updateUiLoggedOut() {
    $('#user-info').textContent = 'Nepřihlášen';
    $('#btn-login').classList.remove('d-none');
    $('#btn-logout').classList.add('d-none');
  }

  async function tryAutoLogin() {
    try {
      const me = await authMe();
      if (me?.user) { updateUiLoggedIn(me.user); loadAll(); return; }
    } catch {}
    updateUiLoggedOut();
  }

  async function loadAll() {
    $('#recipes-list').textContent = 'Načteno (dummy).';
    $('#dicts-list').textContent = 'Načteno (dummy).';
    $('#prices-list').textContent = 'Načteno (dummy).';
    $('#tables-list').textContent = 'Načteno (dummy).';
    if ($('#pane-suroviny').classList.contains('active')) sur.load(true);
  }

  // ---------- SUROVINY LIST + MODAL ----------
  const sur = {
    state: { search:'', limit:50, offset:0, sort_col:'id', sort_dir:'ASC', total:0, olej:'', platnost:'' },
    els: {},
    init() {
      this.els.search = $('#sur-search');
      this.els.limit = $('#sur-limit');
      this.els.tableBody = $('#sur-table tbody');
      this.els.summary = $('#sur-summary');
      this.els.prev = $('#sur-prev');
      this.els.next = $('#sur-next');
      this.els.btnNew = $('#sur-new');
      this.els.olej = $('#sur-filter-olej');
      this.els.platnost = $('#sur-filter-platnost');
      this.els.btnExport = $('#sur-export');
      this.els.btnReset = $('#sur-reset');

      if (this.els.olej) this.state.olej = this.els.olej.value;
      if (this.els.platnost) this.state.platnost = this.els.platnost.value;

      // Modal elements
      this.modalEl = $('#surModal');
      this.modal = new bootstrap.Modal(this.modalEl);
      this.f = {
        id: $('#f-id'), cislo: $('#f-cislo'), nazev: $('#f-nazev'),
        sh: $('#f-sh'), sus_sh: $('#f-sus_sh'), sus_hmot: $('#f-sus_hmot'), sus_obj: $('#f-sus_obj'),
        okp: $('#f-okp'), olej: $('#f-olej'), pozn: $('#f-pozn'),
        dtod: $('#f-dtod'), dtdo: $('#f-dtdo')
      };
      this.meta = $('#sur-meta');
      this.btn = { edit: $('#btn-sur-edit'), save: $('#btn-sur-save'), del: $('#btn-sur-delete'), clone: $('#btn-sur-clone') };

      // Search debounce
      let t = null;
      this.els.search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { this.state.search = this.els.search.value.trim(); this.state.offset=0; this.load(); }, 250); });
      this.state.limit = parseInt(this.els.limit.value,10)||50;
      this.els.limit.addEventListener('change', () => { this.state.limit = parseInt(this.els.limit.value,10)||50; this.state.offset=0; this.load(); });
      this.els.prev.addEventListener('click', () => { if (this.state.offset===0) return; this.state.offset = Math.max(0, this.state.offset - this.state.limit); this.load(); });
      this.els.next.addEventListener('click', () => { if (this.state.offset + this.state.limit >= this.state.total) return; this.state.offset += this.state.limit; this.load(); });

      if (this.els.olej) {
        this.els.olej.addEventListener('change', () => { this.state.olej = this.els.olej.value; this.state.offset = 0; this.load(); });
      }
      if (this.els.platnost) {
        this.els.platnost.addEventListener('change', () => { this.state.platnost = this.els.platnost.value; this.state.offset = 0; this.load(); });
      }
      if (this.els.btnExport) {
        this.els.btnExport.addEventListener('click', () => this.exportCsv());
      }
      if (this.els.btnReset) {
        this.els.btnReset.addEventListener('click', () => this.resetFilters());
      }

      // Sorting
      $$('#sur-table thead th.sortable').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
          const col = th.getAttribute('data-col');
          if (this.state.sort_col === col) this.state.sort_dir = (this.state.sort_dir === 'ASC' ? 'DESC' : 'ASC');
          else { this.state.sort_col = col; this.state.sort_dir = 'ASC'; }
          this.load();
        });
      });

      // Row click -> open modal
      this.els.tableBody.addEventListener('click', (e) => {
        const tr = e.target.closest('tr'); if (!tr) return;
        const id = tr.getAttribute('data-id'); if (!id) return;
        this.openDetail(id);
      });

      // Buttons in modal
      this.btn.edit.addEventListener('click', () => this.setEditMode(true));
      this.btn.save.addEventListener('click', () => this.saveDetail());
      this.btn.del.addEventListener('click', () => this.deleteCurrent());
      this.btn.clone.addEventListener('click', () => this.cloneCurrent());

      this.els.btnNew.addEventListener('click', () => this.newItem());

      // Load when tab shown
      document.getElementById('tab-suroviny').addEventListener('shown.bs.tab', () => this.load(true));
    },
    async load(force=false) {
      try {
        const params = new URLSearchParams({
          search: this.state.search,
          limit: String(this.state.limit),
          offset: String(this.state.offset),
          sort_col: this.state.sort_col,
          sort_dir: this.state.sort_dir
        });
        if (this.state.olej !== '') params.set('olej', this.state.olej);
        if (this.state.platnost) params.set('platnost', this.state.platnost);
        const q = params.toString();
        const data = await apiFetch('/balp2/api/sur_list.php?' + q);
        this.state.total = data.total || 0;
        if (!Array.isArray(data.items) || data.items.length===0) {
          // fallback pro případ prázdné odezvy (diag) – přepnout dočasně na 'nazev'
          if (this.state.sort_col==='id' && this.state.offset===0 && this.state.search===''){
            this.state.sort_col='nazev'; this.state.sort_dir='ASC'; return this.load(true); }
        }
        this.renderRows(data.items || []); /* EMPTY_RETRY_BY_NAME */
        const from = Math.min(this.state.total, this.state.offset + 1);
        const to = Math.min(this.state.total, this.state.offset + this.state.limit);
        this.els.summary.textContent = this.state.total ? `${from}–${to} / ${this.state.total}` : 'Žádné záznamy';
      } catch (e) { showAlert('Načítání surovin selhalo: ' + e.message, 'danger'); }
    },
    renderRows(items) {
      this.els.tableBody.innerHTML = '';
      if (!items.length) return;
      for (const r of items) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', r.id);
        tr.style.cursor = 'pointer';
        tr.innerHTML = `
          <td>${r.id}</td>
          <td>${r.cislo ?? ''}</td>
          <td>${r.nazev ?? ''}</td>
          <td>${r.sh ?? ''}</td>
          <td>${r.okp ?? ''}</td>
          <td>${r.olej ?? ''}</td>
          <td>${r.pozn ?? ''}</td>
        `;
        this.els.tableBody.appendChild(tr);
      }
    },
    setEditMode(on) {
      const keys = Object.keys(this.f).filter(k => k !== 'id');
      for (const k of keys) this.f[k].disabled = !on;
      this.btn.edit.classList.toggle('d-none', on);
      this.btn.save.classList.toggle('d-none', !on);
    },
    formToPayload() {
      const read = id => {
        const el = this.f[id];
        const v = el.value;
        if (['sh','sus_sh','sus_hmot','sus_obj','okp'].includes(id)) return v === '' ? null : Number(v);
        if (['olej'].includes(id)) return v === '' ? null : parseInt(v,10);
        return v === '' ? null : v;
      };
      return {
        id: this.f.id.value ? Number(this.f.id.value) : null,
        cislo: read('cislo'),
        nazev: read('nazev'),
        sh: read('sh'),
        sus_sh: read('sus_sh'),
        sus_hmot: read('sus_hmot'),
        sus_obj: read('sus_obj'),
        okp: read('okp'),
        olej: read('olej'),
        pozn: read('pozn'),
        dtod: read('dtod'),
        dtdo: read('dtdo'),
      };
    },
    fillForm(row) {
      const set = (k,v) => { if (this.f[k]) this.f[k].value = (v ?? ''); };
      set('id', row.id); set('cislo', row.cislo); set('nazev', row.nazev);
      set('sh', row.sh); set('sus_sh', row.sus_sh); set('sus_hmot', row.sus_hmot); set('sus_obj', row.sus_obj);
      set('okp', row.okp); set('olej', row.olej); set('pozn', row.pozn);
      set('dtod', row.dtod ? row.dtod.substring(0,10) : ''); set('dtdo', row.dtdo ? row.dtdo.substring(0,10) : '');
    },
    async openDetail(id) {
      try {
        hideAlert();
        const data = await apiFetch('/balp2/api/sur_get.php?id=' + encodeURIComponent(id));
        this.fillForm(data.item || {});
        const metaParts = [`ID #${id}`];
        if (data.meta) {
          if (typeof data.meta.usage_polotovary === 'number') metaParts.push(`Polotovary: ${data.meta.usage_polotovary}`);
          if (typeof data.meta.usage_total === 'number') metaParts.push(`Položky v recepturách: ${data.meta.usage_total}`);
          if (data.meta.last_used) metaParts.push(`Poslední platnost od: ${data.meta.last_used}`);
        }
        this.meta.textContent = metaParts.join(' • ');
        this.setEditMode(false);
        this.modal.show();
      } catch (e) { showAlert('Načtení detailu selhalo: ' + e.message, 'danger'); }
    },
    newItem() {
      this.fillForm({ id:'', cislo:'', nazev:'', sh:null, sus_sh:null, sus_hmot:null, sus_obj:null, okp:null, olej:null, pozn:'', dtod:'', dtdo:'' });
      this.meta.textContent = 'Nová surovina';
      this.setEditMode(true);
      this.modal.show();
    },
    async saveDetail() {
      try {
        const payload = this.formToPayload();
        await apiFetch('/balp2/api/sur_upsert.php', { method:'POST', body: JSON.stringify(payload) });
        this.modal.hide(); showAlert('Uloženo.', 'success'); this.load(true);
      } catch (e) { showAlert('Uložení selhalo: ' + e.message, 'danger'); }
    },
    async deleteCurrent() {
      const id = this.f.id.value; if (!id) { showAlert('Záznam nemá ID.', 'warning'); return; }
      if (!confirm('Opravdu smazat tuto surovinu?')) return;
      try { await apiFetch('/balp2/api/sur_delete.php?id=' + encodeURIComponent(id), { method:'POST' });
        this.modal.hide(); showAlert('Smazáno.', 'success'); this.load(true);
      } catch (e) { showAlert('Smazání selhalo: ' + e.message, 'danger'); }
    },
    async cloneCurrent() {
      const id = this.f.id.value; if (!id) { showAlert('Záznam nemá ID.', 'warning'); return; }
      try { await apiFetch('/balp2/api/sur_clone.php?id=' + encodeURIComponent(id), { method:'POST' });
        this.modal.hide(); showAlert('Vytvořena kopie.', 'success'); this.load(true);
      } catch (e) { showAlert('Klonování selhalo: ' + e.message, 'danger'); }
    },
    resetFilters() {
      this.state = { ...this.state, search:'', limit:50, offset:0, sort_col:'id', sort_dir:'ASC', olej:'', platnost:'', total:0 };
      if (this.els.search) this.els.search.value = '';
      if (this.els.limit) { this.els.limit.value = '50'; this.state.limit = 50; }
      if (this.els.olej) this.els.olej.value = '';
      if (this.els.platnost) this.els.platnost.value = '';
      if (this.els.summary) this.els.summary.textContent = '—';
      this.load(true);
    },
    exportCsv() {
      try {
        const params = new URLSearchParams({
          search: this.state.search,
          sort_col: this.state.sort_col,
          sort_dir: this.state.sort_dir,
          all: '1'
        });
        if (this.state.olej !== '') params.set('olej', this.state.olej);
        if (this.state.platnost) params.set('platnost', this.state.platnost);
        const token = getToken();
        if (token) params.set('token', token);
        const url = '/balp2/api/sur_export_csv.php?' + params.toString();
        window.open(url, '_blank', 'noopener');
      } catch (e) {
        showAlert('Export selhal: ' + (e.message || e), 'danger');
      }
    }
  };

    // --------- UŽIVATELÉ ----------
    // Jednorázové lazy načtení obsahu záložky Uživatelé
function loadUsersTab(force = false) {
  const pane = document.getElementById('tab-users');
  if (!pane) return;

  if (pane.dataset.loaded && !force) return; // už načteno

  pane.innerHTML = '<div class="p-3 text-muted">Načítám…</div>';

  fetch('../admin_users.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then(html => {
      // Pokud admin_users.php vrací celou stránku, klidně nechte tak – vložíme ji přímo.
      // Máte-li <main id="content">, můžete z něj vytáhnout jen vnitřek:
      // const m = html.match(/<main[^>]*id=["']?content["']?[^>]*>([\s\S]*?)<\/main>/i);
      // pane.innerHTML = m ? m[1] : html;
      pane.innerHTML = html;
      pane.dataset.loaded = '1';
    })
    .catch(err => {
      pane.innerHTML = '<div class="alert alert-danger m-3">Nepodařilo se načíst „Uživatelé“: ' +
                       err.message + '</div>';
    });
}

// Načti, když je záložka poprvé zobrazena
document.addEventListener('DOMContentLoaded', () => {
  const usersTabLink = document.getElementById('users-tab');
  if (usersTabLink) {
    usersTabLink.addEventListener('shown.bs.tab', () => loadUsersTab(false));
  }
});

// Volitelně: ruční refresh někde v UI může volat loadUsersTab(true);

  
  // ---------- POLOTOVARY (list-only) ----------
  const pol = {
    state: { search:'', limit:50, offset:0, sort_col:'id', sort_dir:'ASC', total:0 },
    els: {},
    init(){
      this.els.search = document.getElementById('pol-search');
      this.els.limit = document.getElementById('pol-limit');
      this.els.tbody = document.querySelector('#pol-table tbody');
      this.els.summary = document.getElementById('pol-summary');
      this.els.prev = document.getElementById('pol-prev');
      this.els.next = document.getElementById('pol-next');
      if (this.els.search) {
        let t=null;
        this.els.search.addEventListener('input', ()=>{ clearTimeout(t); t=setTimeout(()=>{ this.state.search=this.els.search.value.trim(); this.state.offset=0; this.load(); },250); });
      }
      if (this.els.limit) this.els.limit.addEventListener('change', ()=>{ this.state.limit=parseInt(this.els.limit.value,10)||50; this.state.offset=0; this.load(); });
      if (this.els.prev) this.els.prev.addEventListener('click', ()=>{ if(this.state.offset===0) return; this.state.offset=Math.max(0,this.state.offset-this.state.limit); this.load(); });
      if (this.els.next) this.els.next.addEventListener('click', ()=>{ if(this.state.offset+this.state.limit>=this.state.total) return; this.state.offset+=this.state.limit; this.load(); });
      document.querySelectorAll('#pol-table thead th.sortable').forEach(th=>{
        th.style.cursor='pointer';
        th.addEventListener('click', ()=>{
          const col=th.getAttribute('data-col');
          if (this.state.sort_col===col) this.state.sort_dir=(this.state.sort_dir==='ASC'?'DESC':'ASC');
          else { this.state.sort_col=col; this.state.sort_dir='ASC'; }
          this.load();
        });
      });
      const tabBtn = document.getElementById('tab-pol');
      tabBtn && tabBtn.addEventListener('shown.bs.tab', ()=> this.load(true));
    },
    async load(force=false){
      try{
        const q = new URLSearchParams({
          search:this.state.search, limit:String(this.state.limit),
          offset:String(this.state.offset), sort_col:this.state.sort_col, sort_dir:this.state.sort_dir
        }).toString();
        const data = await apiFetch('/balp2/api/pol_list.php?'+q);
        this.state.total = data.total || 0;
        this.renderRows(data.items || []);
        const from = Math.min(this.state.total, this.state.offset + 1);
        const to = Math.min(this.state.total, this.state.offset + this.state.limit);
        if (this.els.summary) this.els.summary.textContent = this.state.total ? `${from}–${to} / ${this.state.total}` : 'Žádné záznamy';
      } catch (e) {
        showAlert('Načítání polotovarů selhalo: ' + e.message, 'danger');
      }
    },
    renderRows(items){
      if (!this.els.tbody) return;
      this.els.tbody.innerHTML='';
      for (const r of items) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${r.id}</td>
          <td>${r.cislo ?? ''}</td>
          <td>${r.nazev ?? ''}</td>
          <td>${r.sh ?? ''}</td>
          <td>${r.okp ?? ''}</td>
          <td>${r.olej ?? ''}</td>
          <td>${r.pozn ?? ''}</td>
        `;
        this.els.tbody.appendChild(tr);
      }
    }
  };
  // ---------- /POLOTOVARY ----------
document.addEventListener('DOMContentLoaded', () => {
    hideAlert();
    tryAutoLogin();

    $('#btn-logout').addEventListener('click', async () => {
      clearToken(); document.cookie = 'balp_token=; Max-Age=0; path=/;';
      updateUiLoggedOut(); showAlert('Odhlášeno.', 'secondary');
    });
    $('#login-submit').addEventListener('click', async () => {
      const u = $('#login-username').value.trim();
      const p = $('#login-password').value;
      if (!u || !p) { showAlert('Vyplňte uživatelské jméno i heslo.', 'warning'); return; }
      try {
        hideAlert();
        await login(u, p);
        const me = await authMe();
        updateUiLoggedIn(me?.user || {username:u});
        loadAll();
        const modalEl = document.getElementById('loginModal'); const modal = bootstrap.Modal.getInstance(modalEl); modal?.hide();
        showAlert('Přihlášení proběhlo úspěšně.', 'success');
      } catch (e) { showAlert('Přihlášení selhalo: ' + e.message, 'danger'); }
    });
    $('#btn-reload').addEventListener('click', () => loadAll());

    sur.init();
    pol.init();
  });
})();
