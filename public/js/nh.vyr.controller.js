(() => {
  const $ = (s, p = document) => p.querySelector(s);
  const $$ = (s, p = document) => Array.from(p.querySelectorAll(s));

  const escapeHtml = (value) => {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const apiBase = (window.API_URL || '/balp2/api.php').replace(/\/api\.php$/i, '');
  const listEndpoint = apiBase + '/api/nh_vyr_list.php';
  const detailEndpoint = apiBase + '/api/nh_vyr_get.php';
  const exportCsvEndpoint = apiBase + '/api/nh_vyr_export_csv.php';
  const exportPdfEndpoint = apiBase + '/api/nh_vyr_export_pdf.php';
  const detailExportCsvEndpoint = apiBase + '/api/nh_vyr_detail_export_csv.php';
  const detailExportPdfEndpoint = apiBase + '/api/nh_vyr_detail_export_pdf.php';
  const createEndpoint = apiBase + '/api/nh_vyr_create.php';
  const nhSearchEndpoint = apiBase + '/api/nh_vyr_nh.php';
  const nextVpEndpoint = apiBase + '/api/nh_vyr_next_vp.php';
  const ralListEndpoint = apiBase + '/api/vzornik_ral_list.php';

  const storageKey = 'balp_token';
  const getToken = () => { try { return localStorage.getItem(storageKey) || ''; } catch { return ''; } };

  const apiHeaders = () => {
    const headers = { 'Content-Type': 'application/json' };
    const token = getToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;
    return headers;
  };

  const formatNumber = (value, fractionDigits = 3) => {
    if (value === null || value === undefined || value === '') {
      return '';
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
      const minFraction = value % 1 === 0 ? 0 : Math.min(fractionDigits, 3);
      return value.toLocaleString('cs-CZ', {
        maximumFractionDigits: fractionDigits,
        minimumFractionDigits: minFraction,
      });
    }
    const asNumber = Number(String(value).replace(/\s+/g, '').replace(',', '.'));
    if (!Number.isNaN(asNumber) && Number.isFinite(asNumber)) {
      const minFraction = asNumber % 1 === 0 ? 0 : Math.min(fractionDigits, 3);
      return asNumber.toLocaleString('cs-CZ', {
        maximumFractionDigits: fractionDigits,
        minimumFractionDigits: minFraction,
      });
    }
    return String(value);
  };

  const withCacheBuster = (url) => url + (url.includes('?') ? '&' : '?') + '_ts=' + Date.now();

  async function apiFetch(url, opts = {}) {
    const target = withCacheBuster(url);
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
      throw new Error(data?.error || data?.message || text || ('HTTP ' + resp.status));
    }
    return data ?? text;
  }

  const el = {
    tabBtn: document.getElementById('tab-nh-vyr'),
    pane: document.getElementById('pane-nh-vyr'),
    vpFrom: document.getElementById('nh-vyr-od'),
    vpTo: document.getElementById('nh-vyr-do'),
    limit: document.getElementById('nh-vyr-limit'),
    submit: document.getElementById('nh-vyr-submit'),
    reset: document.getElementById('nh-vyr-reset'),
    exportCsv: document.getElementById('nh-vyr-export-csv'),
    exportPdf: document.getElementById('nh-vyr-export-pdf'),
    newBtn: document.getElementById('nh-vyr-new'),
    tableBody: $('#nh-vyr-table tbody'),
    meta: document.getElementById('nh-vyr-meta'),
    prev: document.getElementById('nh-vyr-prev'),
    next: document.getElementById('nh-vyr-next'),
    modalEl: document.getElementById('nhVyrModal'),
    newModalEl: document.getElementById('nhVyrNewModal'),
    newForm: document.getElementById('nh-vyr-new-form'),
    newAlert: document.getElementById('nh-vyr-new-alert'),
    newCisloVp: document.getElementById('nh-vyr-new-cislo-vp'),
    newDatum: document.getElementById('nh-vyr-new-datum'),
    newMnozstvi: document.getElementById('nh-vyr-new-mnozstvi'),
    newNh: document.getElementById('nh-vyr-new-nh'),
    newNhMeta: document.getElementById('nh-vyr-new-nh-meta'),
    newNhSuggestions: document.getElementById('nh-vyr-new-nh-suggestions'),
    newRal: document.getElementById('nh-vyr-new-ral'),
    newRalMeta: document.getElementById('nh-vyr-new-ral-meta'),
    newRalPreview: document.getElementById('nh-vyr-new-ral-preview'),
    newRalPreviewColor: document.getElementById('nh-vyr-new-ral-color'),
    newRalPreviewLabel: document.getElementById('nh-vyr-new-ral-label'),
    newPoznamka: document.getElementById('nh-vyr-new-poznamka'),
    detail: {
      vp: document.getElementById('nh-vyr-detail-vp'),
      subtitle: document.getElementById('nh-vyr-detail-subtitle'),
      datum: document.getElementById('nh-vyr-detail-datum'),
      nh: document.getElementById('nh-vyr-detail-nh'),
      nazev: document.getElementById('nh-vyr-detail-nazev'),
      ralWrapper: document.getElementById('nh-vyr-detail-ral-wrapper'),
      ral: document.getElementById('nh-vyr-detail-ral'),
      ralColor: document.getElementById('nh-vyr-detail-ral-color'),
      mnozstvi: document.getElementById('nh-vyr-detail-mnozstvi'),
      poznamka: document.getElementById('nh-vyr-detail-poznamka'),
      copy: document.getElementById('nh-vyr-detail-copy'),
      exportCsv: document.getElementById('nh-vyr-detail-export-csv'),
      exportPdf: document.getElementById('nh-vyr-detail-export-pdf'),
      recTable: $('#nh-vyr-detail-rec-table tbody'),
      recEmpty: document.getElementById('nh-vyr-detail-rec-empty'),
      zkTable: $('#nh-vyr-detail-zk-table tbody'),
      zkEmpty: document.getElementById('nh-vyr-detail-zk-empty'),
    },
  };

  if (!el.pane) return;

  const state = {
    vpFrom: '',
    vpTo: '',
    limit: 50,
    offset: 0,
    total: 0,
    sort_col: 'cislo_vp',
    sort_dir: 'ASC',
    loading: false,
    lastDetailId: null,
  };

  const createState = {
    nhId: null,
    nhCode: null,
    selectedNh: null,
    suggestions: [],
    suggestionsToken: 0,
    ralId: null,
  };

  const defaultNhMetaText = 'Vyberte NH z nabídky.';
  const defaultRalMetaText = 'Volitelné – odstín RAL se uloží k výrobnímu příkazu.';

  const ralState = {
    items: [],
    loaded: false,
    loading: false,
  };

  let nhSearchTimer = null;

  const formatVpInput = (input) => {
    if (!input) return;
    const digits = (input.value || '').replace(/\D/g, '').slice(0, 6);
    if (!digits) { input.value = ''; return; }
    const left = digits.slice(0, 2);
    const right = digits.slice(2);
    input.value = right ? `${left.padStart(2, '0')}-${right.padStart(4, '0')}`.slice(0, 7) : left;
  };

  const sanitizeVpState = (value) => {
    const digits = (value || '').replace(/\D/g, '').slice(0, 6);
    return digits ? digits.padStart(6, '0') : '';
  };

  const displayVp = (value) => {
    const digits = (value || '').replace(/\D/g, '').slice(0, 6);
    if (!digits) return '';
    const left = digits.slice(0, 2);
    const right = digits.slice(2);
    return `${left.padStart(2, '0')}-${right.padStart(4, '0')}`;
  };

  function setMeta(text) {
    if (el.meta) el.meta.textContent = text || '';
  }

  function setNewAlert(message) {
    if (!el.newAlert) return;
    if (!message) {
      el.newAlert.textContent = '';
      el.newAlert.classList.add('d-none');
    } else {
      el.newAlert.textContent = message;
      el.newAlert.classList.remove('d-none');
    }
  }

  function formatNhLabel(item) {
    if (!item) return '';
    const code = item.cislo ?? item.cislo_nh ?? item.kod ?? '';
    const alt = item.cislo_vt ?? item.cislo_vp ?? '';
    const displayCode = code || alt;
    const name = item.nazev ?? item.nazev_nh ?? '';
    return [displayCode, name].filter(Boolean).join(' – ');
  }

  function hideNhSuggestions() {
    if (!el.newNhSuggestions) return;
    el.newNhSuggestions.classList.add('d-none');
    el.newNhSuggestions.innerHTML = '';
  }

  function renderNhSuggestions(items) {
    if (!el.newNhSuggestions) return;
    if (!Array.isArray(items) || items.length === 0) {
      hideNhSuggestions();
      return;
    }
    const html = items.map((item) => {
      const id = escapeHtml(String(item?.id ?? ''));
      const label = escapeHtml(formatNhLabel(item));
      return `<button type="button" class="list-group-item list-group-item-action" data-id="${id}">${label}</button>`;
    }).join('');
    el.newNhSuggestions.innerHTML = html;
    el.newNhSuggestions.classList.remove('d-none');
  }

  function buildRalLabel(item) {
    if (!item) return '';
    const code = item.ral_cislo ?? item.ral_text ?? item.cislo ?? '';
    const name = item.ral_nazev ?? item.nazev ?? '';
    const parts = [code, name].filter(Boolean);
    if (parts.length) return parts.join(' – ');
    const id = item.ral_id ?? item.id;
    return id ? `ID ${id}` : '';
  }

  function sanitizeColorCss(value) {
    if (value === null || value === undefined) return null;
    const raw = String(value).trim();
    if (/^#([0-9a-fA-F]{6})$/.test(raw)) {
      return raw.toUpperCase();
    }
    if (/^#([0-9a-fA-F]{3})$/.test(raw)) {
      return '#' + raw[1] + raw[1] + raw[2] + raw[2] + raw[3] + raw[3];
    }
    const rgbMatch = raw.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i);
    if (rgbMatch) {
      const r = Math.min(255, parseInt(rgbMatch[1], 10));
      const g = Math.min(255, parseInt(rgbMatch[2], 10));
      const b = Math.min(255, parseInt(rgbMatch[3], 10));
      return `rgb(${r}, ${g}, ${b})`;
    }
    return null;
  }

  function toCssColor(item) {
    if (!item) return null;
    if (item.ral_color) return item.ral_color;
    if (item.hex) return item.hex;
    if (item.color) return item.color;
    if (item.ral_hex) return item.ral_hex;
    const rgb = item.ral_rgb ?? item.rgb;
    if (rgb) {
      const text = String(rgb).trim();
      if (/^\d/.test(text)) {
        return `rgb(${text})`;
      }
      return text;
    }
    const components = item.ral_rgb_components ?? item.rgb_components;
    if (Array.isArray(components) && components.length >= 3) {
      const [r, g, b] = components.slice(0, 3).map((value) => {
        const num = Number(value);
        if (Number.isFinite(num)) {
          return Math.min(255, Math.max(0, Math.round(num)));
        }
        const parsed = parseInt(String(value), 10);
        return Number.isFinite(parsed) ? Math.min(255, Math.max(0, parsed)) : 0;
      });
      return `rgb(${r}, ${g}, ${b})`;
    }
    return null;
  }

  function findRalItem(id) {
    if (id === null || id === undefined) return null;
    const numericId = Number(id);
    if (!Number.isFinite(numericId)) return null;
    return ralState.items.find((item) => Number(item?.id ?? item?.ral_id ?? 0) === numericId) || null;
  }

  function populateRalOptions(items) {
    if (!el.newRal) return;
    const current = createState.ralId !== null ? String(createState.ralId) : (el.newRal.value || '');
    const options = ['<option value="">(Bez odstínu)</option>'];
    items.forEach((item) => {
      const id = item?.id ?? item?.ral_id;
      if (id === null || id === undefined) {
        return;
      }
      const value = String(id);
      const selectedAttr = current !== '' && current === value ? ' selected' : '';
      const label = buildRalLabel(item) || `ID ${value}`;
      options.push(`<option value="${escapeHtml(value)}"${selectedAttr}>${escapeHtml(label)}</option>`);
    });
    el.newRal.innerHTML = options.join('');
    if (current !== '') {
      el.newRal.value = current;
    }
  }

  function updateRalPreview() {
    if (!el.newRalPreview || !el.newRalPreviewColor || !el.newRalPreviewLabel) return;
    const id = createState.ralId;
    const item = id ? findRalItem(id) : null;
    if (!item) {
      el.newRalPreview.classList.add('d-none');
      el.newRalPreviewColor.style.background = '';
      el.newRalPreviewLabel.textContent = '';
      if (el.newRalMeta) el.newRalMeta.textContent = defaultRalMetaText;
      return;
    }
    const label = buildRalLabel(item) || `ID ${item?.id ?? item?.ral_id ?? id}`;
    const colorCss = sanitizeColorCss(toCssColor(item));
    el.newRalPreviewLabel.textContent = label;
    if (colorCss) {
      el.newRalPreviewColor.style.background = colorCss;
      el.newRalPreviewColor.classList.remove('nh-ral-preview-color--empty');
    } else {
      el.newRalPreviewColor.style.background = '';
      el.newRalPreviewColor.classList.add('nh-ral-preview-color--empty');
    }
    el.newRalPreview.classList.remove('d-none');
    if (el.newRalMeta) {
      el.newRalMeta.textContent = `Vybrán odstín RAL: ${label}`;
    }
  }

  async function ensureRalOptions(force = false) {
    if (!el.newRal) return;
    if (ralState.loading) return;
    if (ralState.loaded && !force) return;
    ralState.loading = true;
    if (el.newRalMeta) el.newRalMeta.textContent = 'Načítám odstíny RAL…';
    try {
      const params = new URLSearchParams({ limit: '500' });
      const data = await apiFetch(`${ralListEndpoint}?${params.toString()}`);
      const items = Array.isArray(data?.items) ? data.items : [];
      ralState.items = items;
      ralState.loaded = true;
      populateRalOptions(items);
      if (el.newRalMeta) {
        el.newRalMeta.textContent = items.length
          ? `Načteno ${items.length} odstínů RAL.`
          : 'Žádné odstíny RAL nejsou k dispozici.';
      }
      updateRalPreview();
    } catch (error) {
      console.error(error);
      if (el.newRalMeta) {
        el.newRalMeta.textContent = error?.message || 'Načtení odstínů RAL selhalo.';
      }
    } finally {
      ralState.loading = false;
    }
  }

  function setSelectedNh(item) {
    createState.selectedNh = item || null;
    createState.nhId = item && item.id ? Number(item.id) : null;
    createState.nhCode = item && item.cislo ? String(item.cislo) : (item && item.cislo_nh ? String(item.cislo_nh) : null);
    if (el.newNh) {
      el.newNh.value = formatNhLabel(item);
    }
    if (el.newNhMeta) {
      el.newNhMeta.textContent = createState.nhCode
        ? `Vybráno NH: ${formatNhLabel(item)}`
        : defaultNhMetaText;
    }
    hideNhSuggestions();
  }

  async function fetchNhSuggestions(query) {
    const trimmed = (query || '').trim();
    createState.suggestionsToken += 1;
    const token = createState.suggestionsToken;
    if (!trimmed || trimmed.length < 2) {
      createState.suggestions = [];
      createState.nhCode = null;
      if (el.newNhMeta) el.newNhMeta.textContent = defaultNhMetaText;
      hideNhSuggestions();
      return;
    }
    try {
      if (el.newNhMeta) el.newNhMeta.textContent = 'Vyhledávám…';
      const params = new URLSearchParams({ q: trimmed, limit: '15' });
      const data = await apiFetch(`${nhSearchEndpoint}?${params.toString()}`);
      if (token !== createState.suggestionsToken) return;
      const items = Array.isArray(data?.items) ? data.items : [];
      createState.suggestions = items;
      renderNhSuggestions(items);
      if (el.newNhMeta) {
        el.newNhMeta.textContent = items.length ? defaultNhMetaText : 'Nenalezeno, zkuste upravit hledání.';
      }
    } catch (e) {
      if (token !== createState.suggestionsToken) return;
      createState.suggestions = [];
      hideNhSuggestions();
      if (el.newNhMeta) el.newNhMeta.textContent = e?.message || 'Vyhledávání selhalo.';
    }
  }

  function resetNewForm() {
    createState.nhId = null;
    createState.nhCode = null;
    createState.selectedNh = null;
    createState.suggestions = [];
    createState.suggestionsToken += 1;
    createState.ralId = null;
    if (el.newForm) el.newForm.reset();
    if (el.newCisloVp) el.newCisloVp.value = '';
    if (el.newDatum) el.newDatum.value = '';
    if (el.newMnozstvi) el.newMnozstvi.value = '';
    if (el.newNh) el.newNh.value = '';
    if (el.newRal) el.newRal.value = '';
    if (el.newPoznamka) el.newPoznamka.value = '';
    if (el.newNhMeta) el.newNhMeta.textContent = defaultNhMetaText;
    if (el.newRalMeta) el.newRalMeta.textContent = defaultRalMetaText;
    if (el.newRalPreview) el.newRalPreview.classList.add('d-none');
    if (el.newRalPreviewColor) {
      el.newRalPreviewColor.style.background = '';
      el.newRalPreviewColor.classList.remove('nh-ral-preview-color--empty');
    }
    if (el.newRalPreviewLabel) el.newRalPreviewLabel.textContent = '';
    setNewAlert('');
    hideNhSuggestions();
    if (nhSearchTimer) {
      clearTimeout(nhSearchTimer);
      nhSearchTimer = null;
    }
  }

  async function prefillNextVp() {
    if (!el.newCisloVp) return;
    try {
      const data = await apiFetch(nextVpEndpoint);
      const digits = data?.cislo_vp_digits ?? data?.cislo_vp_raw ?? null;
      const formatted = data?.cislo_vp ?? (digits ? displayVp(digits) : null);
      if (formatted) {
        el.newCisloVp.value = formatted;
      }
    } catch (e) {
      console.warn('Načtení čísla VP selhalo', e);
    }
  }

  function openNewModal() {
    resetNewForm();
    ensureRalOptions();
    prefillNextVp();
    const modalInstance = getNewModalInstance();
    if (modalInstance) {
      modalInstance.show();
      setTimeout(() => {
        if (el.newCisloVp) {
          el.newCisloVp.focus();
        }
      }, 150);
    } else if (el.newModalEl) {
      el.newModalEl.classList.add('show');
    }
  }

  function renderRows(items) {
    if (!el.tableBody) return;
    if (!Array.isArray(items) || items.length === 0) {
      el.tableBody.innerHTML = '<tr><td colspan="7"><em>Žádné výsledky.</em></td></tr>';
      return;
    }
    const rows = items.map((row) => {
      const cisloVp = displayVp(row.cislo_vp ?? row.cisloVP ?? row.vp);
      const datum = row.datum_vyroby ? String(row.datum_vyroby).slice(0, 10) : '';
      const nh = row.cislo_nh ?? '';
      const name = row.nazev_nh ?? row.nazev ?? '';
      const ralLabel = buildRalLabel(row);
      const ralColorCss = sanitizeColorCss(toCssColor(row));
      const ralSwatch = ralColorCss ? `<span class="nh-ral-swatch" style="background:${ralColorCss};"></span>` : '';
      const ralText = ralLabel || (ralColorCss ? ralColorCss : '');
      const ralContent = (ralSwatch || ralText)
        ? `<div class="d-flex align-items-center gap-2">${ralSwatch}${escapeHtml(ralText)}</div>`
        : '—';
      const vyrobitVal = row.vyrobit_g;
      const vyrobit = typeof vyrobitVal === 'number' ? formatNumber(vyrobitVal, 3) : (vyrobitVal ?? '');
      const pozn = row.poznamka ?? '';
      const id = row.id ?? '';
      const idAttr = escapeHtml(String(id ?? ''));
      return `<tr data-id="${idAttr}" style="cursor:pointer">` +
        `<td>${cisloVp || ''}</td>` +
        `<td>${datum}</td>` +
        `<td>${nh}</td>` +
        `<td>${name}</td>` +
        `<td>${ralContent}</td>` +
        `<td class="text-end">${vyrobit}</td>` +
        `<td>${pozn}</td>` +
      `</tr>`;
    }).join('');
    el.tableBody.innerHTML = rows;
  }

  function updatePager() {
    const canPrev = state.offset > 0;
    const canNext = state.offset + state.limit < state.total;
    if (el.prev) el.prev.disabled = !canPrev;
    if (el.next) el.next.disabled = !canNext;
  }

  async function load(force = false) {
    if (state.loading && !force) return false;
    state.loading = true;
    setMeta('Načítám…');
    try {
      const params = new URLSearchParams({
        limit: String(state.limit),
        offset: String(state.offset),
        sort_col: state.sort_col,
        sort_dir: state.sort_dir,
      });
      if (state.vpFrom) params.set('vp_od', displayVp(state.vpFrom));
      if (state.vpTo) params.set('vp_do', displayVp(state.vpTo));
      const data = await apiFetch(`${listEndpoint}?${params.toString()}`);
      state.total = data.total ?? 0;
      renderRows(Array.isArray(data.items) ? data.items : []);
      if (state.total > 0) {
        const from = state.offset + 1;
        const to = Math.min(state.offset + state.limit, state.total);
        setMeta(`Zobrazeno ${from}–${to} z ${state.total}`);
      } else {
        setMeta('Žádné záznamy.');
      }
      updatePager();
      if (el.pane) el.pane.classList.add('loaded');
      return true;
    } catch (e) {
      console.error(e);
      setMeta(e?.message || 'Chyba při načítání');
      if (el.pane) el.pane.classList.remove('loaded');
      return false;
    } finally {
      state.loading = false;
    }
  }

  function syncInputsFromState() {
    if (el.vpFrom) el.vpFrom.value = state.vpFrom ? displayVp(state.vpFrom) : '';
    if (el.vpTo) el.vpTo.value = state.vpTo ? displayVp(state.vpTo) : '';
    if (el.limit) el.limit.value = String(state.limit);
  }

  function applyInputFilters() {
    state.vpFrom = sanitizeVpState(el.vpFrom?.value || '');
    state.vpTo = sanitizeVpState(el.vpTo?.value || '');
  }

  if (el.vpFrom) {
    el.vpFrom.addEventListener('input', () => formatVpInput(el.vpFrom));
    el.vpFrom.addEventListener('blur', () => formatVpInput(el.vpFrom));
  }
  if (el.vpTo) {
    el.vpTo.addEventListener('input', () => formatVpInput(el.vpTo));
    el.vpTo.addEventListener('blur', () => formatVpInput(el.vpTo));
  }

  if (el.limit) {
    state.limit = parseInt(el.limit.value, 10) || 50;
    el.limit.addEventListener('change', () => {
      state.limit = parseInt(el.limit.value, 10) || 50;
      state.offset = 0;
      load();
    });
  }

  if (el.submit) {
    el.submit.addEventListener('click', () => {
      applyInputFilters();
      state.offset = 0;
      load(true);
    });
  }

  if (el.reset) {
    el.reset.addEventListener('click', () => {
      state.vpFrom = '';
      state.vpTo = '';
      state.limit = 50;
      state.offset = 0;
      state.sort_col = 'cislo_vp';
      state.sort_dir = 'ASC';
      syncInputsFromState();
      load(true);
    });
  }

  if (el.newBtn) {
    el.newBtn.addEventListener('click', () => {
      openNewModal();
    });
  }

  if (el.newCisloVp) {
    el.newCisloVp.addEventListener('input', () => formatVpInput(el.newCisloVp));
    el.newCisloVp.addEventListener('blur', () => formatVpInput(el.newCisloVp));
  }

  if (el.newNhSuggestions) {
    el.newNhSuggestions.addEventListener('click', (ev) => {
      const btn = ev.target.closest('[data-id]');
      if (!btn) return;
      const id = btn.getAttribute('data-id');
      const item = createState.suggestions.find((entry) => String(entry?.id ?? '') === String(id ?? ''));
      if (item) {
        setSelectedNh(item);
      }
    });
  }

  if (el.newNh) {
    el.newNh.addEventListener('input', () => {
      createState.nhId = null;
      createState.nhCode = null;
      createState.selectedNh = null;
      setNewAlert('');
      if (nhSearchTimer) clearTimeout(nhSearchTimer);
      nhSearchTimer = setTimeout(() => {
        fetchNhSuggestions(el.newNh.value || '');
      }, 250);
    });
    el.newNh.addEventListener('focus', () => {
      if (createState.suggestions.length > 0) {
        renderNhSuggestions(createState.suggestions);
      }
    });
    el.newNh.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        hideNhSuggestions();
        return;
      }
      if (ev.key === 'Enter') {
        if (createState.suggestions.length > 0) {
          ev.preventDefault();
          setSelectedNh(createState.suggestions[0]);
        }
      }
    });
  }

  if (el.newRal) {
    el.newRal.addEventListener('change', () => {
      const value = el.newRal.value;
      createState.ralId = value ? Number(value) : null;
      setNewAlert('');
      updateRalPreview();
    });
  }

  document.addEventListener('click', (ev) => {
    if (!el.newNh) return;
    if (ev.target === el.newNh) return;
    if (el.newNhSuggestions && el.newNhSuggestions.contains(ev.target)) return;
    hideNhSuggestions();
  });

  if (el.newForm) {
    el.newForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setNewAlert('');
      const vpDigits = sanitizeVpState(el.newCisloVp?.value || '');
      if (!vpDigits || vpDigits.length !== 6) {
        setNewAlert('Zadejte číslo VP ve formátu 00-0000.');
        return;
      }
      if (!createState.nhCode) {
        setNewAlert('Vyberte NH z nabídky.');
        return;
      }
      const formattedVp = displayVp(vpDigits);
      const qtyRaw = el.newMnozstvi?.value ?? '';
      let vyrobitValue = null;
      if (qtyRaw !== '') {
        const parsed = Number(String(qtyRaw).replace(/\s+/g, '').replace(',', '.'));
        if (!Number.isFinite(parsed) || parsed < 0) {
          setNewAlert('Množství musí být nezáporné číslo.');
          return;
        }
        vyrobitValue = parsed;
      }
      const payload = {
        cislo_vp: formattedVp,
        cislo_vp_digits: vpDigits,
        cislo_nh: createState.nhCode,
        nh_id: createState.nhId,
        datum_vyroby: el.newDatum?.value || null,
        vyrobit_g: vyrobitValue,
        poznamka: (el.newPoznamka?.value || '').trim() || null,
      };
      if (createState.ralId) {
        payload.ral_id = createState.ralId;
      }
      if (!payload.datum_vyroby) delete payload.datum_vyroby;
      if (payload.vyrobit_g === null || Number.isNaN(payload.vyrobit_g)) delete payload.vyrobit_g;
      if (!payload.poznamka) delete payload.poznamka;
      try {
        const result = await apiFetch(createEndpoint, {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        const newId = result?.id ?? result?.item?.id ?? null;
        const modalInstance = getNewModalInstance();
        if (modalInstance) {
          modalInstance.hide();
        } else if (el.newModalEl) {
          el.newModalEl.classList.remove('show');
        }
        resetNewForm();
        state.offset = 0;
        await load(true);
        if (newId) {
          openDetail(newId);
        }
      } catch (e) {
        setNewAlert(e?.message || 'Vytvoření selhalo.');
      }
    });
  }

  if (el.prev) {
    el.prev.addEventListener('click', () => {
      if (state.offset === 0) return;
      state.offset = Math.max(0, state.offset - state.limit);
      load();
    });
  }

  if (el.next) {
    el.next.addEventListener('click', () => {
      if (state.offset + state.limit >= state.total) return;
      state.offset += state.limit;
      load();
    });
  }

  const sortHeaders = $$('#nh-vyr-table thead th.sortable');
  sortHeaders.forEach((th) => {
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

  if (el.tableBody) {
    el.tableBody.addEventListener('click', (ev) => {
      const tr = ev.target.closest('tr');
      if (!tr) return;
      const id = tr.getAttribute('data-id');
      if (id) openDetail(id);
    });
  }

  function openExport(url) {
    const params = new URLSearchParams();
    if (state.vpFrom) params.set('vp_od', displayVp(state.vpFrom));
    if (state.vpTo) params.set('vp_do', displayVp(state.vpTo));
    params.set('limit', String(state.limit));
    params.set('offset', String(state.offset));
    params.set('sort_col', state.sort_col);
    params.set('sort_dir', state.sort_dir);
    const token = getToken();
    if (token) params.set('token', token);
    window.open(url + '?' + params.toString(), '_blank');
  }

  if (el.exportCsv) el.exportCsv.addEventListener('click', () => openExport(exportCsvEndpoint));
  if (el.exportPdf) el.exportPdf.addEventListener('click', () => openExport(exportPdfEndpoint));

  const resolveModalCtor = () => {
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      return window.bootstrap.Modal;
    }
    if (typeof bootstrap !== 'undefined' && bootstrap?.Modal) {
      return bootstrap.Modal;
    }
    return null;
  };

  let newModal = null;
  const getNewModalInstance = () => {
    if (!el.newModalEl) return null;
    const ModalCtor = resolveModalCtor();
    if (!ModalCtor) return null;
    if (typeof ModalCtor.getOrCreateInstance === 'function') {
      newModal = ModalCtor.getOrCreateInstance(el.newModalEl);
      return newModal;
    }
    if (!newModal) {
      newModal = new ModalCtor(el.newModalEl);
    }
    return newModal;
  };
  if (el.newModalEl) {
    el.newModalEl.addEventListener('shown.bs.modal', () => {
      if (el.newCisloVp) {
        el.newCisloVp.focus();
      }
    });
    el.newModalEl.addEventListener('hidden.bs.modal', () => {
      resetNewForm();
    });
  }

  let detailModal = null;
  const getDetailModalInstance = () => {
    if (!el.modalEl) return null;
    const ModalCtor = resolveModalCtor();
    if (!ModalCtor) return null;
    if (typeof ModalCtor.getOrCreateInstance === 'function') {
      detailModal = ModalCtor.getOrCreateInstance(el.modalEl);
      return detailModal;
    }
    if (!detailModal) {
      detailModal = new ModalCtor(el.modalEl);
    }
    return detailModal;
  };
  if (el.modalEl) {
    el.modalEl.addEventListener('hidden.bs.modal', () => {
      state.lastDetailId = null;
    });
  }

  function fillDetail(data) {
    const row = data?.item || {};
    const cisloVp = displayVp(row.cislo_vp ?? row.cisloVP ?? row.vp);
    const datum = row.datum_vyroby ? String(row.datum_vyroby).slice(0, 10) : '';
    const nh = row.cislo_nh ?? '';
    const nazev = row.nazev_nh ?? '';
    const vyrobitVal = row.vyrobit_g;
    const vyrobit = typeof vyrobitVal === 'number' ? formatNumber(vyrobitVal, 3) : (vyrobitVal ?? '');
    const pozn = row.poznamka ?? '';
    state.lastDetailId = row.id ?? null;

    if (el.detail.vp) el.detail.vp.textContent = cisloVp || '—';
    if (el.detail.subtitle) el.detail.subtitle.textContent = nh ? `${nh} ${nazev}`.trim() : (nazev || '');
    if (el.detail.datum) el.detail.datum.textContent = datum || '—';
    if (el.detail.nh) el.detail.nh.textContent = nh || '—';
    if (el.detail.nazev) el.detail.nazev.textContent = nazev || '—';
    const detailRalLabel = buildRalLabel(row);
    const detailRalColor = sanitizeColorCss(toCssColor(row));
    if (el.detail.ral) {
      el.detail.ral.textContent = detailRalLabel || (detailRalColor || '—');
    }
    if (el.detail.ralColor) {
      if (detailRalColor) {
        el.detail.ralColor.style.background = detailRalColor;
        el.detail.ralColor.classList.remove('nh-ral-preview-color--empty');
      } else {
        el.detail.ralColor.style.background = '';
        el.detail.ralColor.classList.add('nh-ral-preview-color--empty');
      }
    }
    if (el.detail.ralWrapper) {
      const visible = Boolean(detailRalLabel || detailRalColor);
      el.detail.ralWrapper.classList.toggle('d-none', !visible);
    }
    if (el.detail.mnozstvi) {
      const vyrobitText = formatNumber(row.vyrobit_g, 3) || (vyrobit !== '' ? String(vyrobit) : '');
      el.detail.mnozstvi.textContent = vyrobitText !== '' ? vyrobitText : '—';
    }
    if (el.detail.poznamka) el.detail.poznamka.textContent = pozn || '—';

    if (el.detail.vp) {
      el.detail.vp.dataset.vpDigits = sanitizeVpState(cisloVp);
    }

    const recipe = Array.isArray(data?.rows) ? data.rows : [];
    if (el.detail.recTable) {
      if (recipe.length > 0) {
        el.detail.recTable.innerHTML = recipe.map((item) => {
          const type = item?.typ ?? '';
          const code = item?.cislo ?? '';
          const name = item?.nazev ?? '';
          const qty = formatNumber(item?.mnozstvi, 3);
          const navaz = formatNumber(item?.navazit, 3);
          return `<tr>` +
            `<td>${type || ''}</td>` +
            `<td>${code || ''}</td>` +
            `<td>${name || ''}</td>` +
            `<td class="text-end">${qty}</td>` +
            `<td class="text-end">${navaz}</td>` +
          `</tr>`;
        }).join('');
        if (el.detail.recEmpty) el.detail.recEmpty.classList.add('d-none');
      } else {
        el.detail.recTable.innerHTML = '';
        if (el.detail.recEmpty) el.detail.recEmpty.classList.remove('d-none');
      }
    }

    const tests = Array.isArray(data?.zkousky) ? data.zkousky : [];
    if (el.detail.zkTable) {
      if (tests.length > 0) {
        el.detail.zkTable.innerHTML = tests.map((item) => {
          const date = item?.datum ? String(item.datum).slice(0, 10) : '';
          const type = item?.typ ?? '';
          const result = item?.vysledek ?? '';
          return `<tr>` +
            `<td>${date || ''}</td>` +
            `<td>${type || ''}</td>` +
            `<td>${result || ''}</td>` +
          `</tr>`;
        }).join('');
        if (el.detail.zkEmpty) el.detail.zkEmpty.classList.add('d-none');
      } else {
        el.detail.zkTable.innerHTML = '';
        if (el.detail.zkEmpty) el.detail.zkEmpty.classList.remove('d-none');
      }
    }
  }

  async function openDetail(id) {
    const previousMeta = el.meta ? el.meta.textContent : '';
    try {
      setMeta('Načítám detail…');
      const data = await apiFetch(`${detailEndpoint}?id=${encodeURIComponent(id)}`);
      fillDetail(data);
      const modalInstance = getDetailModalInstance();
      if (modalInstance) {
        modalInstance.show();
      } else if (el.modalEl) {
        el.modalEl.classList.add('show');
      }
    } catch (e) {
      console.error(e);
      setMeta(e?.message || 'Chyba při načtení detailu');
      return;
    }
    setMeta(previousMeta);
  }


  if (el.detail.copy && el.detail.vp) {
    el.detail.copy.addEventListener('click', async () => {
      try {
        const text = el.detail.vp.textContent || '';
        if (!text) return;
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(text);
        }
      } catch (e) {
        console.warn('Kopírování selhalo', e);
      }
    });
  }

  if (el.detail.vp) {
    el.detail.vp.addEventListener('click', (ev) => {
      ev.preventDefault();
      const digits = el.detail.vp.dataset.vpDigits || '';
      if (!digits) return;
      state.vpFrom = digits;
      state.vpTo = digits;
      state.offset = 0;
      syncInputsFromState();
      load(true);
      const modalInstance = getDetailModalInstance();
      if (modalInstance) {
        modalInstance.hide();
      } else if (el.modalEl) {
        el.modalEl.classList.remove('show');
      }
    });
  }

  function openDetailExport(endpoint) {
    if (!state.lastDetailId) return;
    const params = new URLSearchParams({ id: String(state.lastDetailId) });
    const token = getToken();
    if (token) params.set('token', token);
    window.open(endpoint + '?' + params.toString(), '_blank');
  }

  if (el.detail.exportCsv) {
    el.detail.exportCsv.addEventListener('click', () => openDetailExport(detailExportCsvEndpoint));
  }
  if (el.detail.exportPdf) {
    el.detail.exportPdf.addEventListener('click', () => openDetailExport(detailExportPdfEndpoint));
  }

  const ensureTabLoaded = (force = false) => {
    if (!el.pane) return;
    // NEPOSÍLAT na API bez tokenu – zamezí 500 z backendu
    const t = getToken && getToken();
    if (!t) {
      // Počkej až se auth připraví – nasloucháme vlastní události
      return;
    }
    const firstTime = !el.pane.classList.contains('loaded');
    if (firstTime) {
      syncInputsFromState();
      load(true);
      return;
    }
    if (force) {
      load(true);
    }
  };

  const onShown = (ev) => {
    if (ev && ev.target && ev.target.id !== 'tab-nh-vyr') return;
    ensureTabLoaded(false);
  };

  try {
    document.addEventListener('shown.bs.tab', onShown);
  } catch {}
  // Debounce + filtr na duplikované spouštění
  let _tabShownTimer = null;
  document.addEventListener('balp:tab-shown', (ev) => {
    if (!ev?.detail) return;
    if (ev.detail.tab !== 'nh-vyroba') return;
    clearTimeout(_tabShownTimer);
    _tabShownTimer = setTimeout(() => {
      ensureTabLoaded(Boolean(ev.detail.refresh));
    }, 0);
  });
  document.addEventListener('auth:ready', (ev) => {
    if (!el.pane) return;
    if (ev?.detail && ev.detail.authenticated === false) return;
    if (!el.pane.classList.contains('loaded') || (el.tabBtn && el.tabBtn.classList.contains('active'))) {
      ensureTabLoaded(true);
    }
  });
  // Aktivní záložka: spustíme až po AUTH signálu, aby byl k dispozici token
  const ready = () => {
    if (el.tabBtn && el.tabBtn.classList.contains('active')) onShown({ target: el.tabBtn });
  };
  if (getToken && getToken()) {
    ready();
  } else {
    const waitForAuth = (ev) => {
      if (ev?.detail && ev.detail.authenticated === false) return;
      ready();
      document.removeEventListener('auth:ready', waitForAuth);
    };
    document.addEventListener('auth:ready', waitForAuth);
  }

  if ((el.tabBtn && el.tabBtn.classList.contains('active')) || (el.pane && el.pane.classList.contains('active'))) {
    setTimeout(() => ensureTabLoaded(true), 0);
  }
})();
