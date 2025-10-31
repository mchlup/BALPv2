(() => {
  const ready = (fn) => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  };

  const storageKey = 'balp_token';
  const getToken = () => { try { return localStorage.getItem(storageKey) || ''; } catch { return ''; } };
  const saveToken = (t) => { try { localStorage.setItem(storageKey, t || ''); } catch {} };

  const apiHeaders = () => {
    const h = { 'Content-Type': 'application/json' };
    const t = getToken();
    if (t) h['Authorization'] = 'Bearer ' + t;
    return h;
  };

  const withTokenUrl = (url) => {
    const u = new URL(url, window.location.origin);
    const t = getToken();
    if (t) u.searchParams.set('token', t);
    u.searchParams.set('_ts', Date.now());
    return u.toString();
  };

  async function apiFetch(url, opts = {}) {
    const target = withTokenUrl(url);
    const resp = await fetch(target, {
      method: opts.method || 'GET',
      headers: { ...apiHeaders(), ...(opts.headers || {}) },
      body: opts.body,
      credentials: 'include',
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

  const escapeHtml = (value) => {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };
  const safeCell = (value) => escapeHtml(value ?? '');
  const safeDate = (value) => (value ? escapeHtml(String(value).substring(0, 10)) : '');

  const showAlert = (msg, type = 'info') => {
    const box = document.getElementById('alert-box');
    if (!box) return;
    box.className = `alert alert-${type}`;
    box.textContent = msg;
    box.classList.remove('d-none');
  };
  const hideAlert = () => {
    const box = document.getElementById('alert-box');
    if (!box) return;
    box.classList.add('d-none');
    box.textContent = '';
  };

  ready(() => {
    const pane = document.getElementById('pane-suroviny');
    if (!pane) return;

    const els = {
      search: document.getElementById('sur-search'),
      limit: document.getElementById('sur-limit'),
      tableBody: document.querySelector('#sur-table tbody'),
      summary: document.getElementById('sur-summary'),
      prev: document.getElementById('sur-prev'),
      next: document.getElementById('sur-next'),
      btnNew: document.getElementById('sur-new'),
      olej: document.getElementById('sur-filter-olej'),
      platnost: document.getElementById('sur-filter-platnost'),
      btnExport: document.getElementById('sur-export'),
      btnReset: document.getElementById('sur-reset'),
    };

    const modalEl = document.getElementById('surModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const formEls = {
      id: document.getElementById('f-id'),
      cislo: document.getElementById('f-cislo'),
      nazev: document.getElementById('f-nazev'),
      sh: document.getElementById('f-sh'),
      sus_sh: document.getElementById('f-sus_sh'),
      sus_hmot: document.getElementById('f-sus_hmot'),
      okp: document.getElementById('f-okp'),
      olej: document.getElementById('f-olej'),
      pozn: document.getElementById('f-pozn'),
      dtod: document.getElementById('f-dtod'),
      dtdo: document.getElementById('f-dtdo'),
    };
    const metaEl = document.getElementById('sur-meta');
    const btn = {
      edit: document.getElementById('btn-sur-edit'),
      save: document.getElementById('btn-sur-save'),
      del: document.getElementById('btn-sur-delete'),
      clone: document.getElementById('btn-sur-clone'),
    };

    const state = {
      search: '',
      limit: 50,
      offset: 0,
      sort_col: 'id',
      sort_dir: 'ASC',
      total: 0,
      olej: '',
      platnost: '',
    };

    if (els.olej) state.olej = els.olej.value;
    if (els.platnost) state.platnost = els.platnost.value;

    const readForm = (id) => {
      const el = formEls[id];
      if (!el) return null;
      const v = el.value;
      if (['sh', 'sus_sh', 'sus_hmot', 'okp'].includes(id)) return v === '' ? null : Number(v);
      if (['olej'].includes(id)) return v === '' ? null : parseInt(v, 10);
      return v === '' ? null : v;
    };

    const setEditMode = (on) => {
      Object.keys(formEls).forEach((key) => {
        if (key === 'id') return;
        const el = formEls[key];
        if (el) el.disabled = !on;
      });
      if (btn.edit) btn.edit.classList.toggle('d-none', on);
      if (btn.save) btn.save.classList.toggle('d-none', !on);
    };

    const fillForm = (row) => {
      const set = (k, v) => { if (formEls[k]) formEls[k].value = v ?? ''; };
      set('id', row.id);
      set('cislo', row.cislo);
      set('nazev', row.nazev);
      set('sh', row.sh);
      set('sus_sh', row.sus_sh);
      set('sus_hmot', row.sus_hmot);
      set('okp', row.okp);
      set('olej', row.olej);
      set('pozn', row.pozn);
      set('dtod', row.dtod ? row.dtod.substring(0, 10) : '');
      set('dtdo', row.dtdo ? row.dtdo.substring(0, 10) : '');
    };

    const renderRows = (items) => {
      if (!els.tableBody) return;
      els.tableBody.innerHTML = '';
      if (!items || !items.length) return;
      for (const r of items) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', r.id);
        tr.style.cursor = 'pointer';
        tr.innerHTML = `
          <td>${safeCell(r.id)}</td>
          <td>${safeCell(r.cislo)}</td>
          <td>${safeCell(r.nazev)}</td>
          <td>${safeCell(r.sh)}</td>
          <td>${safeCell(r.sus_sh)}</td>
          <td>${safeCell(r.sus_hmot)}</td>
          <td>${safeCell(r.okp)}</td>
          <td>${safeCell(r.olej)}</td>
          <td>${safeCell(r.pozn)}</td>`;
        els.tableBody.appendChild(tr);
      }
    };

    const refreshSummary = () => {
      if (!els.summary) return;
      if (!state.total) {
        els.summary.textContent = 'Žádné záznamy';
        return;
      }
      const from = Math.min(state.total, state.offset + 1);
      const to = Math.min(state.total, state.offset + state.limit);
      els.summary.textContent = `${from}–${to} / ${state.total}`;
    };

    const load = async (force = false) => {
      try {
        hideAlert();
        const params = new URLSearchParams({
          search: state.search,
          limit: String(state.limit),
          offset: String(state.offset),
          sort_col: state.sort_col,
          sort_dir: state.sort_dir,
        });
        if (state.olej !== '') params.set('olej', state.olej);
        if (state.platnost) params.set('platnost', state.platnost);
        const data = await apiFetch('/balp2/api/sur_list.php?' + params.toString());
        state.total = data.total || 0;
        renderRows(data.items || []);
        refreshSummary();
      } catch (e) {
        showAlert('Načítání surovin selhalo: ' + e.message, 'danger');
      }
    };

    const openDetail = async (id) => {
      try {
        hideAlert();
        const data = await apiFetch('/balp2/api/sur_get.php?id=' + encodeURIComponent(id));
        fillForm(data.item || {});
        const metaParts = [`ID #${id}`];
        if (data.meta) {
          if (typeof data.meta.usage_polotovary === 'number') metaParts.push(`Polotovary: ${data.meta.usage_polotovary}`);
          if (typeof data.meta.usage_total === 'number') metaParts.push(`Položky v recepturách: ${data.meta.usage_total}`);
          if (data.meta.last_used) metaParts.push(`Poslední platnost od: ${data.meta.last_used}`);
        }
        if (metaEl) metaEl.textContent = metaParts.join(' • ');
        setEditMode(false);
        modal?.show();
      } catch (e) {
        showAlert('Načtení detailu selhalo: ' + e.message, 'danger');
      }
    };

    const newItem = () => {
      fillForm({ id: '', cislo: '', nazev: '', sh: null, sus_sh: null, sus_hmot: null, okp: null, olej: null, pozn: '', dtod: '', dtdo: '' });
      if (metaEl) metaEl.textContent = 'Nová surovina';
      setEditMode(true);
      modal?.show();
    };

    const saveDetail = async () => {
      try {
        const payload = {
          id: formEls.id?.value ? Number(formEls.id.value) : null,
          cislo: readForm('cislo'),
          nazev: readForm('nazev'),
          sh: readForm('sh'),
          sus_sh: readForm('sus_sh'),
          sus_hmot: readForm('sus_hmot'),
          okp: readForm('okp'),
          olej: readForm('olej'),
          pozn: readForm('pozn'),
          dtod: readForm('dtod'),
          dtdo: readForm('dtdo'),
        };
        await apiFetch('/balp2/api/sur_upsert.php', { method: 'POST', body: JSON.stringify(payload) });
        modal?.hide();
        showAlert('Uloženo.', 'success');
        state.offset = 0;
        load(true);
      } catch (e) {
        showAlert('Uložení selhalo: ' + e.message, 'danger');
      }
    };

    const deleteCurrent = async () => {
      const id = formEls.id?.value;
      if (!id) {
        showAlert('Záznam nemá ID.', 'warning');
        return;
      }
      if (!confirm('Opravdu smazat tuto surovinu?')) return;
      try {
        await apiFetch('/balp2/api/sur_delete.php?id=' + encodeURIComponent(id), { method: 'POST' });
        modal?.hide();
        showAlert('Smazáno.', 'success');
        state.offset = 0;
        load(true);
      } catch (e) {
        showAlert('Smazání selhalo: ' + e.message, 'danger');
      }
    };

    const cloneCurrent = async () => {
      const id = formEls.id?.value;
      if (!id) {
        showAlert('Záznam nemá ID.', 'warning');
        return;
      }
      try {
        await apiFetch('/balp2/api/sur_clone.php?id=' + encodeURIComponent(id), { method: 'POST' });
        modal?.hide();
        showAlert('Vytvořena kopie.', 'success');
        state.offset = 0;
        load(true);
      } catch (e) {
        showAlert('Klonování selhalo: ' + e.message, 'danger');
      }
    };

    const resetFilters = () => {
      state.search = '';
      state.limit = 50;
      state.offset = 0;
      state.sort_col = 'id';
      state.sort_dir = 'ASC';
      state.olej = '';
      state.platnost = '';
      if (els.search) els.search.value = '';
      if (els.limit) els.limit.value = '50';
      if (els.olej) els.olej.value = '';
      if (els.platnost) els.platnost.value = '';
      if (els.summary) els.summary.textContent = '—';
      load(true);
    };

    const exportCsv = () => {
      try {
        const params = new URLSearchParams({
          search: state.search,
          sort_col: state.sort_col,
          sort_dir: state.sort_dir,
          all: '1',
        });
        if (state.olej !== '') params.set('olej', state.olej);
        if (state.platnost) params.set('platnost', state.platnost);
        const token = getToken();
        if (token) params.set('token', token);
        const url = '/balp2/api/sur_export_csv.php?' + params.toString();
        window.open(url, '_blank', 'noopener');
      } catch (e) {
        showAlert('Export selhal: ' + (e.message || e), 'danger');
      }
    };

    if (els.search) {
      let t = null;
      els.search.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => {
          state.search = els.search.value.trim();
          state.offset = 0;
          load();
        }, 250);
      });
    }
    if (els.limit) {
      state.limit = parseInt(els.limit.value, 10) || 50;
      els.limit.addEventListener('change', () => {
        state.limit = parseInt(els.limit.value, 10) || 50;
        state.offset = 0;
        load();
      });
    }
    if (els.prev) els.prev.addEventListener('click', () => {
      if (state.offset === 0) return;
      state.offset = Math.max(0, state.offset - state.limit);
      load();
    });
    if (els.next) els.next.addEventListener('click', () => {
      if (state.offset + state.limit >= state.total) return;
      state.offset += state.limit;
      load();
    });
    if (els.olej) {
      els.olej.addEventListener('change', () => {
        state.olej = els.olej.value;
        state.offset = 0;
        load();
      });
    }
    if (els.platnost) {
      els.platnost.addEventListener('change', () => {
        state.platnost = els.platnost.value;
        state.offset = 0;
        load();
      });
    }
    if (els.btnExport) els.btnExport.addEventListener('click', exportCsv);
    if (els.btnReset) els.btnReset.addEventListener('click', resetFilters);
    if (btn.edit) btn.edit.addEventListener('click', () => setEditMode(true));
    if (btn.save) btn.save.addEventListener('click', saveDetail);
    if (btn.del) btn.del.addEventListener('click', deleteCurrent);
    if (btn.clone) btn.clone.addEventListener('click', cloneCurrent);
    if (els.btnNew) els.btnNew.addEventListener('click', newItem);

    if (els.tableBody) {
      els.tableBody.addEventListener('click', (e) => {
        const tr = e.target.closest('tr');
        if (!tr) return;
        const id = tr.getAttribute('data-id');
        if (!id) return;
        openDetail(id);
      });
    }

    document.addEventListener('balp:tab-shown', (ev) => {
      if (ev.detail?.paneId === 'pane-suroviny') load(true);
    });

    window.reloadSurovinyList = () => load(true);
  });
})();
