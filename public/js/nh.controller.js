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
      return `<tr>
        <td>${id!==null?String(id):''}</td>
        <td>${code!==null?String(code):''}</td>
        <td>${name!==null?String(name):''}</td>
        <td>${cat!==null?String(cat):''}</td>
      </tr>`;
    }).join('');
    el.table.innerHTML = rows || `<tr><td colspan="4"><em>Žádné výsledky.</em></td></tr>`;
  }

  async function load() {
    if (state.loading) return;
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
})();
