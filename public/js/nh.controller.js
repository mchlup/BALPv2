(() => {
  const $  = (s, p=document) => p.querySelector(s);

  const apiBase = (window.API_URL || '/balp2/api.php').replace(/\/api\.php$/i, '');
  const nhEndpoint = apiBase + '/api/nh_list.php';

  const el = {
    search: $('#nh-search'),
    limit:  $('#nh-limit'),
    table:  $('#nh-table tbody'),
    meta:   $('#nh-meta'),
    prev:   $('#nh-prev'),
    next:   $('#nh-next'),
    prev2:  $('#nh-prev-bottom'),
    next2:  $('#nh-next-bottom'),
    tabBtn: $('#tab-nh'),
    pane:   $('#pane-nh')
  };
  if (!el.pane) return; // stránka NH není přítomná

  const storageKey = 'balp_token';
  const getToken = () => { try { return localStorage.getItem(storageKey) || ''; } catch { return ''; } };
  const apiHeaders = () => {
    const h = {'Content-Type':'application/json'};
    const t = getToken();
    if (t) h['Authorization'] = 'Bearer ' + t;
    return h;
  };
  async function apiFetch(url, opts={}) {
    const full = url + (url.includes('?') ? '&' : '?') + '_ts=' + Date.now();
    const t = getToken();
    const withToken = t ? full + '&token=' + encodeURIComponent(t) : full;
    const resp = await fetch(withToken, {
      method: opts.method || 'GET',
      headers: {...apiHeaders(), ...(opts.headers || {})},
      body: opts.body,
      credentials: 'include'
    });
    const text = await resp.text();
    let data = null;
    try { data = JSON.parse(text); } catch {}
    if (!resp.ok) {
      throw new Error(data?.error || data?.message || text || ('HTTP ' + resp.status));
    }
    return data ?? text;
  }

  const state = {
    q: '',
    limit: 50,
    offset: 0,
    total: 0,
    loading: false,
  };

  function setMeta(text) {
    if (el.meta) el.meta.textContent = text || '';
  }

  function renderRows(items) {
    const rows = items.map(r => {
      // robustně přečteme potenciální názvy sloupců
      const id   = r.id ?? r.ID ?? r.Id ?? '';
      const code = r.kod ?? r.code ?? r.cislo ?? r.CISLO ?? r.CODE ?? '';
      const name = r.nazev ?? r.name ?? r.NAZEV ?? r.NAME ?? '';
      const cat  = r.kategorie_id ?? r.category_id ?? r.kategorie ?? r.CAT ?? '';
      return `<tr data-id="${id!==null?String(id):''}" style="cursor:pointer">
        <td>${id!==null?String(id):''}</td>
        <td>${code!==null?String(code):''}</td>
        <td>${name!==null?String(name):''}</td>
        <td>${cat!==null?String(cat):''}</td>
      </tr>`;
    }).join('');
    el.table.innerHTML = rows || `<tr><td colspan="4"><em>Žádné výsledky.</em></td></tr>`;
  }

  async function load(force=false) {
    if (state.loading && !force) return;
    state.loading = true;
    setMeta('Načítám…');
    try {
      const params = new URLSearchParams({
        limit: String(state.limit),
        offset: String(state.offset),
      });
      if (state.q) params.set('q', state.q);
      const data = await apiFetch(`${nhEndpoint}?${params.toString()}`);
      state.total = data.total ?? 0;
      renderRows(Array.isArray(data.items) ? data.items : []);
      const hasItems = state.total > 0;
      const from = hasItems ? (state.offset + 1) : 0;
      const to = hasItems ? Math.min(state.offset + state.limit, state.total) : 0;
      setMeta(hasItems ? `Zobrazeno ${from}–${to} z ${state.total}` : 'Žádné záznamy.');
      updatePager();
    } catch (e) {
      setMeta(e?.message || 'Chyba při načítání');
      console.error(e);
    } finally {
      state.loading = false;
    }
  }

  function updatePager() {
    const canPrev = state.offset > 0;
    const canNext = state.offset + state.limit < state.total;
    [el.prev, el.prev2].forEach(b => b && (b.disabled = !canPrev));
    [el.next, el.next2].forEach(b => b && (b.disabled = !canNext));
  }

  // Debounce helper
  function debounce(fn, ms=300) {
    let t=null; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), ms); };
  }

  // Bind events
  if (el.search) {
    el.search.addEventListener('input', debounce(() => {
      state.q = el.search.value.trim();
      state.offset = 0;
      load();
    }, 300));
  }
  if (el.limit) {
    el.limit.addEventListener('change', () => {
      const v = parseInt(el.limit.value, 10);
      state.limit = isNaN(v) ? 50 : v;
      state.offset = 0;
      load();
    });
    // init from default select
    const v = parseInt(el.limit.value, 10);
    state.limit = isNaN(v) ? 50 : v;
  }
  [el.prev, el.prev2].forEach(b => b && b.addEventListener('click', () => {
    state.offset = Math.max(0, state.offset - state.limit);
    load();
  }));
  [el.next, el.next2].forEach(b => b && b.addEventListener('click', () => {
    if (state.offset + state.limit < state.total) state.offset += state.limit;
    load();
  }));

  // Načtení při prvním zobrazení záložky
  const onShown = (ev) => {
    if (ev && ev.target && ev.target.id !== 'tab-nh') return;
    if (!el.pane.classList.contains('loaded')) {
      el.pane.classList.add('loaded');
      load();
    }
  };
  // Pokud používáte Bootstrap 5 tab events:
  try {
    document.addEventListener('shown.bs.tab', onShown);
  } catch {}
  // Když už je NH aktivní hned po načtení
  if (el.tabBtn && el.tabBtn.classList.contains('active')) onShown({target: el.tabBtn});

  // Bind events pro NH detail modal
  if (el.table) {
    el.table.addEventListener('click', (e) => {
      const tr = e.target.closest('tr');
      if (!tr) return;
      const id = tr.getAttribute('data-id');
      if (id) openDetail(id);
    });
  }
  const modalEl = document.getElementById('nhModal');
  let modal = null;
  if (modalEl) {
    let ModalCtor = null;
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      ModalCtor = window.bootstrap.Modal;
    } else if (typeof bootstrap !== 'undefined' && bootstrap && typeof bootstrap.Modal === 'function') {
      ModalCtor = bootstrap.Modal;
    }
    if (ModalCtor) {
      modal = new ModalCtor(modalEl);
    }
  }
  const f = {
    id: document.getElementById('nh-id'),
    kod: document.getElementById('nh-kod'),
    nazev: document.getElementById('nh-nazev'),
    kategorie_id: document.getElementById('nh-kategorie_id'),
    pozn: document.getElementById('nh-poznamka'),
    dtod: document.getElementById('nh-dtod'),
    dtdo: document.getElementById('nh-dtdo'),
  };
  const detailMeta = document.getElementById('nh-detail-meta');
  const btnEdit = document.getElementById('btn-nh-edit');
  const btnSave = document.getElementById('btn-nh-save');
  const btnDel = document.getElementById('btn-nh-delete');
  const btnNew = document.getElementById('nh-new');
  if (btnEdit) btnEdit.addEventListener('click', () => setEditMode(true));
  if (btnSave) btnSave.addEventListener('click', () => saveDetail());
  if (btnDel) btnDel.addEventListener('click', () => deleteCurrent());
  if (btnNew) btnNew.addEventListener('click', () => newItem());

  function setEditMode(on) {
    Object.keys(f).filter(k => k !== 'id').forEach(k => { if (f[k]) f[k].disabled = !on; });
    if (btnEdit) btnEdit.classList.toggle('d-none', on);
    if (btnSave) btnSave.classList.toggle('d-none', !on);
  }
  function fillForm(row) {
    const set = (k, v) => { if (f[k]) f[k].value = (v ?? ''); };
    set('id', row.id ?? '');
    set('kod', row.kod ?? row.code ?? row.cislo ?? '');
    set('nazev', row.nazev ?? row.name ?? '');
    set('kategorie_id', row.kategorie_id ?? row.kategorie ?? '');
    set('pozn', row.pozn ?? '');
    set('dtod', row.dtod ? String(row.dtod).substring(0, 10) : '');
    set('dtdo', row.dtdo ? String(row.dtdo).substring(0, 10) : '');
  }
  function formToPayload() {
    const read = (id) => {
      const v = f[id]?.value ?? '';
      if (id === 'kategorie_id') return v === '' ? null : parseInt(v, 10);
      return v === '' ? null : v;
    };
    const payload = {
      kod: read('kod'),
      nazev: read('nazev'),
      kategorie_id: read('kategorie_id'),
      pozn: read('pozn'),
      dtod: read('dtod'),
      dtdo: read('dtdo'),
    };
    const idVal = f.id?.value;
    if (idVal) payload.id = Number(idVal);
    return payload;
  }
  async function openDetail(id) {
    if (!modal) return;
    try {
      const data = await apiFetch(apiBase + '/api/nh_get.php?id=' + encodeURIComponent(id));
      const row = data.item ?? data;
      fillForm(row);
      if (detailMeta) detailMeta.textContent = `ID #${id}`;
      setEditMode(false);
      modal.show();
    } catch (e) {
      console.error('Detail load failed:', e);
      if (el.meta) el.meta.textContent = e.message || 'Chyba při načítání detailu';
    }
  }
  function newItem() {
    if (!modal) return;
    fillForm({});
    if (detailMeta) detailMeta.textContent = 'Nová NH';
    setEditMode(true);
    modal.show();
  }
  async function saveDetail() {
    try {
      const payload = formToPayload();
      await apiFetch(apiBase + '/api/nh_upsert.php', { method: 'POST', body: JSON.stringify(payload) });
      if (modal) modal.hide();
      state.offset = 0;
      await load(true);
    } catch (e) {
      console.error('Save failed:', e);
      alert('Uložení selhalo: ' + (e.message || e));
    }
  }
  async function deleteCurrent() {
    const id = f.id?.value;
    if (!id) {
      alert('Záznam nemá ID.');
      return;
    }
    if (!confirm('Opravdu smazat tuto položku NH?')) return;
    try {
      await apiFetch(apiBase + '/api/nh_delete.php?id=' + encodeURIComponent(id), { method: 'POST' });
      if (modal) modal.hide();
      state.offset = 0;
      await load(true);
      if (detailMeta) detailMeta.textContent = '';
    } catch (e) {
      console.error('Delete failed:', e);
      alert('Smazání selhalo: ' + (e.message || e));
    }
  }
})();
