(() => {
  const $  = (s, p=document) => p.querySelector(s);

  const apiBase = (window.API_URL || '/balp2/api.php').replace(/\/api\.php$/i, '');
  const nhEndpoint = apiBase + '/api/nh_list.php';

  const el = {
    search: $('#nh-search'),
    codeFrom: $('#nh-code-from'),
    codeTo: $('#nh-code-to'),
    active: $('#nh-active'),
    limit:  $('#nh-limit'),
    table:  $('#nh-table tbody'),
    meta:   $('#nh-meta'),
    prev:   $('#nh-prev'),
    next:   $('#nh-next'),
    prev2:  $('#nh-prev-bottom'),
    next2:  $('#nh-next-bottom'),
    tabBtn: $('#tab-nh'),
    pane:   $('#pane-nh'),
    reset:  $('#nh-reset')
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
    codeFrom: '',
    codeTo: '',
    active: '1',
    limit: 50,
    offset: 0,
    total: 0,
    loading: false,
    sort_col: 'cislo',
    sort_dir: 'ASC',
  };

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

  function setMeta(text) {
    if (el.meta) el.meta.textContent = text || '';
  }

  function renderRows(items) {
    const rows = items.map(r => {
      // robustně přečteme potenciální názvy sloupců
      const id   = r.id ?? r.ID ?? r.Id ?? '';
      const code = r.kod ?? r.code ?? r.cislo ?? r.CISLO ?? r.CODE ?? '';
      const vt   = r.cislo_vt ?? r.cislo_vp ?? r.cisloVP ?? r.vp ?? r.vp_cislo ?? '';
      const name = r.nazev ?? r.name ?? r.NAZEV ?? r.NAME ?? '';
      const cat  = r.kategorie_id ?? r.category_id ?? r.kategorie ?? r.CAT ?? '';
      const dtod = r.dtod ?? r.DTOD ?? '';
      const dtdo = r.dtdo ?? r.DTDO ?? '';
      const idCell = safeCell(id);
      const codeCell = safeCell(code);
      const vpCell = safeCell(vp);
      const nameCell = safeCell(name);
      const catCell = safeCell(cat);
      const dtodCell = safeDate(dtod);
      const dtdoCell = safeDate(dtdo);
      return `<tr data-id="${idCell}" style="cursor:pointer">
        <td>${idCell}</td>
        <td>${codeCell}</td>
        <td>${vpCell}</td>
        <td>${nameCell}</td>
        <td>${catCell}</td>
        <td>${dtodCell}</td>
        <td>${dtdoCell}</td>
      </tr>`;
    }).join('');
    const colSpan = 7;
    el.table.innerHTML = rows || `<tr><td colspan="${colSpan}"><em>Žádné výsledky.</em></td></tr>`;
  }

  async function load(force=false) {
    if (state.loading && !force) return;
    state.loading = true;
    setMeta('Načítám…');
    try {
      const params = new URLSearchParams({
        limit: String(state.limit),
        offset: String(state.offset),
        sort_col: state.sort_col,
        sort_dir: state.sort_dir,
      });
      if (state.q) params.set('q', state.q);
      if (state.codeFrom) params.set('cislo_od', state.codeFrom);
      if (state.codeTo) params.set('cislo_do', state.codeTo);
      if (state.active !== '') params.set('active', state.active);
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
  if (el.codeFrom) {
    state.codeFrom = el.codeFrom.value.trim();
    el.codeFrom.addEventListener('input', debounce(() => {
      state.codeFrom = el.codeFrom.value.trim();
      state.offset = 0;
      load();
    }, 300));
  }
  if (el.codeTo) {
    state.codeTo = el.codeTo.value.trim();
    el.codeTo.addEventListener('input', debounce(() => {
      state.codeTo = el.codeTo.value.trim();
      state.offset = 0;
      load();
    }, 300));
  }
  if (el.active) {
    state.active = el.active.value;
    el.active.addEventListener('change', () => {
      state.active = el.active.value;
      state.offset = 0;
      load();
    });
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
  if (el.reset) {
    el.reset.addEventListener('click', () => {
      state.q = '';
      state.codeFrom = '';
      state.codeTo = '';
      state.active = '1';
      state.limit = 50;
      state.offset = 0;
      state.sort_col = 'cislo';
      state.sort_dir = 'ASC';
      if (el.search) el.search.value = '';
      if (el.codeFrom) el.codeFrom.value = '';
      if (el.codeTo) el.codeTo.value = '';
      if (el.active) el.active.value = '1';
      if (el.limit) el.limit.value = '50';
      load(true);
    });
  }
  [el.prev, el.prev2].forEach(b => b && b.addEventListener('click', () => {
    state.offset = Math.max(0, state.offset - state.limit);
    load();
  }));
  [el.next, el.next2].forEach(b => b && b.addEventListener('click', () => {
    if (state.offset + state.limit < state.total) state.offset += state.limit;
    load();
  }));

  // Sorting by header click
  const headerCells = document.querySelectorAll('#nh-table thead th.sortable');
  headerCells.forEach(th => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const col = th.getAttribute('data-col');
      if (!col) return;
      if (state.sort_col === col) {
        state.sort_dir = state.sort_dir === 'ASC' ? 'DESC' : 'ASC';
      } else {
        state.sort_col = col;
        state.sort_dir = 'ASC';
      }
      state.offset = 0;
      load();
    });
  });

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
    cislo_vt: document.getElementById('nh-cislo_vt'),
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
  const odsContainer = document.getElementById('nh-ods-container');
  const recipeEditor = {
    section: document.getElementById('nh-recipe-editor'),
    type: document.getElementById('nh-recipe-type'),
    code: document.getElementById('nh-recipe-code'),
    name: document.getElementById('nh-recipe-name'),
    amount: document.getElementById('nh-recipe-amount'),
    addBtn: document.getElementById('nh-recipe-add'),
    tableBody: document.querySelector('#nh-recipe-table tbody'),
    emptyState: document.getElementById('nh-recipe-empty'),
    codeList: document.getElementById('nh-recipe-code-suggestions'),
    nameList: document.getElementById('nh-recipe-name-suggestions'),
  };
  let draftRecipe = [];
  let isCreating = false;
  if (btnEdit) btnEdit.addEventListener('click', () => setEditMode(true));
  if (btnSave) btnSave.addEventListener('click', () => saveDetail());
  if (btnDel) btnDel.addEventListener('click', () => deleteCurrent());
  if (btnNew) btnNew.addEventListener('click', () => newItem());
  if (modalEl) {
    modalEl.addEventListener('hidden.bs.modal', () => {
      isCreating = false;
      setEditMode(false);
      if (btnDel) btnDel.classList.remove('d-none');
      draftRecipe = [];
      renderDraftRecipe();
      clearRecipeSuggestions();
      updateRecipeEditorVisibility();
    });
  }

  const fmtDate = (value) => {
    if (!value) return '';
    return String(value).substring(0, 10);
  };
  const fmtNumber = (value) => {
    if (value === null || value === undefined || value === '') return '';
    const num = Number(value);
    if (!Number.isFinite(num)) return escapeHtml(value);
    return num.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  const renderOds = (list) => {
    if (!odsContainer) return;
    if (!Array.isArray(list) || list.length === 0) {
      odsContainer.innerHTML = '<div class="text-muted small">Žádné navázané varianty nebyly nalezeny.</div>';
      return;
    }
    const renderPriceSummary = (price) => {
      if (!price) return '<span class="text-muted">—</span>';
      const rows = [];
      if (price.sur_nak !== undefined && price.sur_nak !== null) rows.push(`<div>SuR nákup: <strong>${fmtNumber(price.sur_nak)}</strong></div>`);
      if (price.mat_nak !== undefined && price.mat_nak !== null) rows.push(`<div>Materiál nákup: <strong>${fmtNumber(price.mat_nak)}</strong></div>`);
      if (price.vn_kg !== undefined && price.vn_kg !== null) rows.push(`<div>VN / kg: <strong>${fmtNumber(price.vn_kg)}</strong></div>`);
      if (price.uvn_kg !== undefined && price.uvn_kg !== null) rows.push(`<div>ÚVN / kg: <strong>${fmtNumber(price.uvn_kg)}</strong></div>`);
      if (rows.length === 0) rows.push('<div class="text-muted">—</div>');
      const validity = `${fmtDate(price.dtod) || '—'} – ${fmtDate(price.dtdo) || '—'}`;
      rows.push(`<div class="small text-muted">Platnost: ${escapeHtml(validity)}</div>`);
      return rows.join('');
    };
    const renderPriceSummaryCompact = (price) => {
      if (!price) return '<span class="text-muted">—</span>';
      const parts = [];
      if (price.sur_nak !== undefined && price.sur_nak !== null) parts.push(`SuR nákup: <strong>${fmtNumber(price.sur_nak)}</strong>`);
      if (price.mat_nak !== undefined && price.mat_nak !== null) parts.push(`Materiál nákup: <strong>${fmtNumber(price.mat_nak)}</strong>`);
      if (price.vn_kg !== undefined && price.vn_kg !== null) parts.push(`VN / kg: <strong>${fmtNumber(price.vn_kg)}</strong>`);
      if (price.uvn_kg !== undefined && price.uvn_kg !== null) parts.push(`ÚVN / kg: <strong>${fmtNumber(price.uvn_kg)}</strong>`);
      return parts.length ? parts.join(' · ') : '<span class="text-muted">—</span>';
    };
    const renderPriceHistory = (prices) => {
      if (!Array.isArray(prices) || prices.length === 0) {
        return '<div class="text-muted small">Historie cen není k dispozici.</div>';
      }
      const rows = prices.map((p) => `
        <tr>
          <td>${escapeHtml(fmtDate(p.dtod) || '')}</td>
          <td>${escapeHtml(fmtDate(p.dtdo) || '')}</td>
          <td class="text-end">${fmtNumber(p.sur_nak)}</td>
          <td class="text-end">${fmtNumber(p.mat_nak)}</td>
          <td class="text-end">${fmtNumber(p.vn_kg)}</td>
          <td class="text-end">${fmtNumber(p.uvn_kg)}</td>
        </tr>
      `).join('');
      return `
        <div class="mt-3">
          <div class="fw-semibold small text-uppercase text-muted mb-1">Historie cen</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Platnost od</th>
                  <th>Platnost do</th>
                  <th class="text-end">SuR nákup</th>
                  <th class="text-end">Materiál nákup</th>
                  <th class="text-end">VN / kg</th>
                  <th class="text-end">ÚVN / kg</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      `;
    };
    const renderRecipe = (recipe) => {
      if (!Array.isArray(recipe) || recipe.length === 0) {
        return '<div class="text-muted small">Receptura není evidována.</div>';
      }
      const rows = recipe.map((r) => {
        const typ = r.typ === 'sur' ? 'Surovina' : (r.typ === 'pol' ? 'Polotovar' : '');
        return `
          <tr>
            <td>${escapeHtml(r.techpor ?? '')}</td>
            <td>${escapeHtml(typ)}</td>
            <td>${escapeHtml(r.cislo ?? '')}</td>
            <td>${escapeHtml(r.nazev ?? '')}</td>
            <td class="text-end">${fmtNumber(r.gkg)}</td>
          </tr>
        `;
      }).join('');
      return `
        <div class="mt-3">
          <div class="fw-semibold small text-uppercase text-muted mb-1">Aktuální receptura</div>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Tech. poř.</th>
                  <th>Typ</th>
                  <th>Kód</th>
                  <th>Název</th>
                  <th class="text-end">g / kg</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      `;
    };
    const accordionId = 'nh-ods-accordion';
    odsContainer.innerHTML = `<div class="accordion" id="${accordionId}">${list.map((item, index) => {
      const collapseId = `${accordionId}-collapse-${index}`;
      const headingId = `${accordionId}-heading-${index}`;
      const headerTitle = [item.cislo ? `<strong>${escapeHtml(item.cislo)}</strong>` : '', item.nazev ? escapeHtml(item.nazev) : '']
        .filter(Boolean)
        .join(' – ');
      const validity = `${fmtDate(item.dtod) || '—'} – ${fmtDate(item.dtdo) || '—'}`;
      const note = item.pozn ? `<div class="small mt-2">${escapeHtml(item.pozn)}</div>` : '';
      return `
        <div class="accordion-item">
          <h2 class="accordion-header" id="${headingId}">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="false" aria-controls="${collapseId}">
              <div class="w-100">
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                  <span class="fw-semibold">${headerTitle || 'Bez názvu'}</span>
                  <span class="small text-muted">Platnost: ${escapeHtml(validity)}</span>
                </div>
                <div class="small text-muted mt-1">${renderPriceSummaryCompact(item.active_price)}</div>
              </div>
            </button>
          </h2>
          <div id="${collapseId}" class="accordion-collapse collapse" aria-labelledby="${headingId}" data-bs-parent="#${accordionId}">
            <div class="accordion-body">
              <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                <div>
                  <div class="fw-semibold">${headerTitle || 'Bez názvu'}</div>
                  <div class="small text-muted">Platnost: ${escapeHtml(validity)}</div>
                  ${note}
                </div>
                <div class="text-end small">
                  <div class="fw-semibold text-uppercase text-muted">Aktuální cena</div>
                  ${renderPriceSummary(item.active_price)}
                </div>
              </div>
              ${renderPriceHistory(item.prices)}
              ${renderRecipe(item.recipe)}
            </div>
          </div>
        </div>
      `;
    }).join('')}</div>`;
  };

  const recipeTypeLabels = {
    sur: 'Surovina',
    pol: 'Polotovar',
    other: 'Ostatní',
  };

  const recipeSearchEndpoints = {
    sur: '/api/search_sur.php',
    pol: '/api/search_pol.php',
  };

  const recipeSuggestionState = {
    lastType: '',
    lastQuery: '',
    items: [],
  };

  const clearRecipeSuggestions = () => {
    recipeSuggestionState.lastType = '';
    recipeSuggestionState.lastQuery = '';
    recipeSuggestionState.items = [];
    if (recipeEditor.codeList) recipeEditor.codeList.innerHTML = '';
    if (recipeEditor.nameList) recipeEditor.nameList.innerHTML = '';
  };

  const populateRecipeSuggestions = (items) => {
    if (recipeEditor.codeList) {
      recipeEditor.codeList.innerHTML = '';
      items.forEach((item) => {
        const code = item?.cislo ?? item?.kod ?? item?.code ?? '';
        if (!code) return;
        const option = document.createElement('option');
        option.value = String(code);
        const name = item?.nazev ?? item?.name ?? '';
        if (name) option.textContent = String(name);
        recipeEditor.codeList.appendChild(option);
      });
    }
    if (recipeEditor.nameList) {
      recipeEditor.nameList.innerHTML = '';
      items.forEach((item) => {
        const name = item?.nazev ?? item?.name ?? '';
        if (!name) return;
        const option = document.createElement('option');
        option.value = String(name);
        const code = item?.cislo ?? item?.kod ?? item?.code ?? '';
        if (code) option.textContent = String(code);
        recipeEditor.nameList.appendChild(option);
      });
    }
  };

  const requestRecipeSuggestions = debounce(async (query) => {
    if (!isCreating) return;
    const type = recipeEditor.type?.value || 'sur';
    if (type === 'other') {
      clearRecipeSuggestions();
      return;
    }
    if (!query || query.length < 2) {
      clearRecipeSuggestions();
      return;
    }
    const endpoint = recipeSearchEndpoints[type];
    if (!endpoint) {
      clearRecipeSuggestions();
      return;
    }
    try {
      const url = `${apiBase}${endpoint}?q=${encodeURIComponent(query)}&limit=20`;
      const data = await apiFetch(url);
      const items = Array.isArray(data?.items) ? data.items : [];
      recipeSuggestionState.lastType = type;
      recipeSuggestionState.lastQuery = query;
      recipeSuggestionState.items = items;
      populateRecipeSuggestions(items);
    } catch (error) {
      console.error('Recipe suggestions failed:', error);
      clearRecipeSuggestions();
    }
  }, 250);

  const applySuggestionFromCode = () => {
    if (!recipeEditor.code || !recipeEditor.name) return;
    if (!recipeSuggestionState.items.length) return;
    if ((recipeEditor.type?.value || '') !== recipeSuggestionState.lastType) return;
    const value = recipeEditor.code.value?.trim();
    if (!value) return;
    const item = recipeSuggestionState.items.find((it) => {
      const code = it?.cislo ?? it?.kod ?? it?.code;
      return code && String(code).toLowerCase() === value.toLowerCase();
    });
    if (!item) return;
    const name = item?.nazev ?? item?.name;
    if (name) recipeEditor.name.value = String(name);
  };

  const applySuggestionFromName = () => {
    if (!recipeEditor.code || !recipeEditor.name) return;
    if (!recipeSuggestionState.items.length) return;
    if ((recipeEditor.type?.value || '') !== recipeSuggestionState.lastType) return;
    const value = recipeEditor.name.value?.trim();
    if (!value) return;
    const item = recipeSuggestionState.items.find((it) => {
      const name = it?.nazev ?? it?.name;
      return name && String(name).toLowerCase() === value.toLowerCase();
    });
    if (!item) return;
    const code = item?.cislo ?? item?.kod ?? item?.code;
    if (code) recipeEditor.code.value = String(code);
  };

  const updateRecipeEditorVisibility = () => {
    if (!recipeEditor.section) return;
    const shouldShow = isCreating;
    recipeEditor.section.classList.toggle('d-none', !shouldShow);
  };

  const renderDraftRecipe = () => {
    if (!recipeEditor.tableBody || !recipeEditor.emptyState) return;
    if (!Array.isArray(draftRecipe) || draftRecipe.length === 0) {
      recipeEditor.tableBody.innerHTML = '';
      recipeEditor.emptyState.classList.remove('d-none');
      return;
    }
    recipeEditor.emptyState.classList.add('d-none');
    recipeEditor.tableBody.innerHTML = draftRecipe.map((item, index) => {
      const amount = Number(item.mnozstvi);
      const formattedAmount = Number.isFinite(amount)
        ? amount.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '';
      return `
      <tr data-index="${index}">
        <td>${escapeHtml(recipeTypeLabels[item.typ] || '')}</td>
        <td>${escapeHtml(item.kod ?? '')}</td>
        <td>${escapeHtml(item.nazev ?? '')}</td>
        <td class="text-end">${formattedAmount}</td>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-link text-danger" data-action="remove" title="Odebrat položku">
            &times;
          </button>
        </td>
      </tr>
    `;
    }).join('');
  };

  if (recipeEditor.addBtn) {
    recipeEditor.addBtn.addEventListener('click', () => {
      if (!isCreating) return;
      const typ = recipeEditor.type?.value || 'sur';
      const kod = recipeEditor.code?.value?.trim() || '';
      const nazev = recipeEditor.name?.value?.trim() || '';
      const amountRaw = recipeEditor.amount?.value ?? '';
      const amount = Number(amountRaw);
      if (!Number.isFinite(amount) || amount <= 0) {
        alert('Zadejte prosím množství v g/kg větší než 0.');
        return;
      }
      draftRecipe.push({
        typ,
        kod,
        nazev,
        mnozstvi: amount,
      });
      if (recipeEditor.code) recipeEditor.code.value = '';
      if (recipeEditor.name) recipeEditor.name.value = '';
      if (recipeEditor.amount) recipeEditor.amount.value = '';
      clearRecipeSuggestions();
      renderDraftRecipe();
      if (recipeEditor.code) recipeEditor.code.focus();
    });
  }

  if (recipeEditor.tableBody) {
    recipeEditor.tableBody.addEventListener('click', (event) => {
      const target = event.target.closest('[data-action="remove"]');
      if (!target) return;
      if (!isCreating) return;
      const row = target.closest('tr');
      const index = row ? parseInt(row.getAttribute('data-index'), 10) : NaN;
      if (!Number.isInteger(index)) return;
      draftRecipe.splice(index, 1);
      renderDraftRecipe();
    });
  }

  if (recipeEditor.type) {
    recipeEditor.type.addEventListener('change', () => {
      if (!isCreating) return;
      clearRecipeSuggestions();
      const followUpQuery = recipeEditor.code?.value?.trim() || recipeEditor.name?.value?.trim() || '';
      if (followUpQuery.length >= 2) {
        requestRecipeSuggestions(followUpQuery);
      }
    });
  }

  if (recipeEditor.code) {
    recipeEditor.code.addEventListener('input', () => {
      if (!isCreating) return;
      requestRecipeSuggestions(recipeEditor.code.value?.trim() || '');
    });
    recipeEditor.code.addEventListener('change', applySuggestionFromCode);
    recipeEditor.code.addEventListener('blur', applySuggestionFromCode);
  }

  if (recipeEditor.name) {
    recipeEditor.name.addEventListener('input', () => {
      if (!isCreating) return;
      requestRecipeSuggestions(recipeEditor.name.value?.trim() || '');
    });
    recipeEditor.name.addEventListener('change', applySuggestionFromName);
    recipeEditor.name.addEventListener('blur', applySuggestionFromName);
  }

  function setEditMode(on) {
    Object.keys(f).filter(k => k !== 'id').forEach(k => { if (f[k]) f[k].disabled = !on; });
    if (btnEdit) btnEdit.classList.toggle('d-none', on || isCreating);
    if (btnSave) btnSave.classList.toggle('d-none', !on);
    if (btnDel) btnDel.classList.toggle('d-none', isCreating);
    updateRecipeEditorVisibility();
  }
  function fillForm(row) {
    const set = (k, v) => { if (f[k]) f[k].value = (v ?? ''); };
    set('id', row.id ?? '');
    set('kod', row.kod ?? row.code ?? row.cislo ?? '');
    set('cislo_vt', row.cislo_vt ?? row.cislo_vp ?? row.cisloVP ?? row.vp ?? row.vp_cislo ?? '');
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
      cislo_vt: read('cislo_vt'),
      pozn: read('pozn'),
      dtod: read('dtod'),
      dtdo: read('dtdo'),
    };
    if (isCreating && Array.isArray(draftRecipe) && draftRecipe.length) {
      payload.recipe_items = draftRecipe.map((item, index) => ({
        typ: item.typ || null,
        kod: item.kod || null,
        nazev: item.nazev || null,
        mnozstvi: Number(item.mnozstvi) || null,
        techpor: index + 1,
      }));
    }
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
      renderOds(data.ods || []);
      if (detailMeta) detailMeta.textContent = `ID #${id}`;
      setEditMode(false);
      isCreating = false;
      draftRecipe = [];
      renderDraftRecipe();
      updateRecipeEditorVisibility();
      modal.show();
    } catch (e) {
      console.error('Detail load failed:', e);
      if (el.meta) el.meta.textContent = e.message || 'Chyba při načítání detailu';
    }
  }
  function newItem() {
    if (!modal) return;
    const today = new Date();
    const formatDate = (d) => {
      if (!(d instanceof Date) || Number.isNaN(d.getTime())) return '';
      return d.toISOString().slice(0, 10);
    };
    fillForm({
      id: '',
      kod: '',
      cislo_vt: '',
      nazev: '',
      pozn: '',
      dtod: formatDate(today),
      dtdo: '9999-12-31',
    });
    renderOds([]);
    if (detailMeta) detailMeta.textContent = 'Nová NH';
    isCreating = true;
    setEditMode(true);
    draftRecipe = [];
    renderDraftRecipe();
    clearRecipeSuggestions();
    updateRecipeEditorVisibility();
    if (f.kod) {
      f.kod.focus();
      f.kod.select?.();
    }
    modal.show();
  }
  async function saveDetail() {
    try {
      const payload = formToPayload();
      await apiFetch(apiBase + '/api/nh_upsert.php', { method: 'POST', body: JSON.stringify(payload) });
      if (modal) modal.hide();
      state.offset = 0;
      isCreating = false;
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
