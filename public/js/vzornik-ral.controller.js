(() => {
  const escapeHtml = (value) => {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const pane = document.getElementById('pane-vzornik-ral');
  if (!pane) return;

  const elements = {
    search: document.getElementById('ral-search'),
    submit: document.getElementById('ral-search-submit'),
    reset: document.getElementById('ral-search-reset'),
    grid: document.getElementById('ral-grid'),
    empty: document.getElementById('ral-empty'),
    meta: document.getElementById('ral-meta'),
  };

  const apiBase = (window.API_URL || '/balp2/api.php').replace(/\/api\.php$/i, '');
  const listEndpoint = apiBase + '/api/vzornik_ral_list.php';

  const storageKey = 'balp_token';
  const getToken = () => {
    try {
      return localStorage.getItem(storageKey) || '';
    } catch (e) {
      return '';
    }
  };

  const apiHeaders = () => {
    const headers = {};
    const token = getToken();
    if (token) {
      headers['Authorization'] = 'Bearer ' + token;
    }
    return headers;
  };

  async function apiFetch(url, opts = {}) {
    const response = await fetch(url, {
      method: opts.method || 'GET',
      headers: { ...apiHeaders(), ...(opts.headers || {}) },
      body: opts.body,
      credentials: 'include',
    });
    const text = await response.text();
    let data = null;
    try {
      data = JSON.parse(text);
    } catch (err) {
      data = null;
    }
    if (!response.ok) {
      const message = data?.error || data?.message || text || ('HTTP ' + response.status);
      throw new Error(message);
    }
    return data ?? {};
  }

  const state = {
    q: '',
    limit: 250,
    offset: 0,
    total: 0,
    loading: false,
    initialized: false,
  };

  const setBusy = (busy) => {
    if (pane) {
      pane.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
  };

  const setMeta = (text) => {
    if (elements.meta) {
      elements.meta.textContent = text || '';
    }
  };

  const showEmpty = (visible, message) => {
    if (!elements.empty) return;
    elements.empty.classList.toggle('d-none', !visible);
    if (message) {
      elements.empty.textContent = message;
    }
  };

  const sanitizeColor = (item) => {
    const hex = typeof item?.hex === 'string' ? item.hex.trim() : '';
    if (hex && /^#?[0-9a-fA-F]{3,8}$/.test(hex)) {
      const normalized = hex.startsWith('#') ? hex : '#' + hex;
      if (/^#[0-9a-fA-F]{6}$/.test(normalized)) {
        return normalized.toUpperCase();
      }
      if (/^#[0-9a-fA-F]{3}$/.test(normalized)) {
        return '#' + normalized[1] + normalized[1] + normalized[2] + normalized[2] + normalized[3] + normalized[3];
      }
    }
    const rgbText = typeof item?.rgb === 'string' ? item.rgb.trim() : '';
    if (rgbText) {
      const matches = rgbText.match(/\d+/g);
      if (matches && matches.length >= 3) {
        const [r, g, b] = matches.slice(0, 3).map((part) => {
          const value = Math.min(255, Math.max(0, parseInt(part, 10) || 0));
          return value;
        });
        return `rgb(${r}, ${g}, ${b})`;
      }
    }
    const components = Array.isArray(item?.rgb_components) ? item.rgb_components : [];
    if (components.length >= 3) {
      const [r, g, b] = components.map((value) => {
        const num = Math.min(255, Math.max(0, parseInt(value, 10) || 0));
        return num;
      });
      return `rgb(${r}, ${g}, ${b})`;
    }
    return '#f8f9fa';
  };

  const renderItems = (items) => {
    if (!elements.grid) return;
    if (!Array.isArray(items) || items.length === 0) {
      elements.grid.innerHTML = '';
      showEmpty(true);
      return;
    }
    const cards = items.map((item) => {
      const colorValue = sanitizeColor(item);
      const label = item?.label || '';
      const code = item?.cislo || '';
      const name = item?.nazev || '';
      const rgb = item?.rgb || '';
      const fallbackLabel = label || [code, name].filter(Boolean).join(' – ');
      const colorAttr = colorValue || '#f8f9fa';
      const displayColor = colorValue || 'Bez barvy';
      const rgbRow = rgb ? `<div class="ral-rgb">RGB: ${escapeHtml(rgb)}</div>` : '';
      const ariaLabel = escapeHtml(fallbackLabel || 'RAL odstín');
      const codeHtml = code ? escapeHtml(code) : '—';
      const nameHtml = name ? escapeHtml(name) : '&nbsp;';
      const colorHtml = escapeHtml(displayColor);
      const idValue = item?.id ?? '—';
      return `<article class="ral-card" tabindex="0" aria-label="${ariaLabel}">
        <div class="ral-color" style="background:${colorAttr};" data-color="${colorHtml}"></div>
        <div class="ral-body">
          <div class="ral-code">${codeHtml}</div>
          <div class="ral-name">${nameHtml}</div>
          ${rgbRow}
          <div class="ral-hint">ID: ${escapeHtml(idValue)}</div>
        </div>
      </article>`;
    }).join('');
    elements.grid.innerHTML = cards;
    showEmpty(false);
  };

  const load = async (force = false) => {
    if (state.loading && !force) return;
    state.loading = true;
    setBusy(true);
    showEmpty(false);
    setMeta('Načítám…');
    try {
      const params = new URLSearchParams({ limit: String(state.limit), offset: String(state.offset) });
      if (state.q) {
        params.set('q', state.q);
      }
      const data = await apiFetch(`${listEndpoint}?${params.toString()}`);
      const items = Array.isArray(data?.items) ? data.items : [];
      state.total = data?.total ?? items.length;
      renderItems(items);
      if (state.total > 0) {
        const info = state.q
          ? `Zobrazeno ${items.length} odstínů (celkem ${state.total})`
          : `Celkem ${state.total} odstínů`;
        setMeta(info);
      } else {
        setMeta('Žádné odstíny k zobrazení.');
        showEmpty(true);
      }
      state.initialized = true;
    } catch (error) {
      console.error(error);
      setMeta(error?.message || 'Načtení odstínů selhalo.');
      renderItems([]);
      showEmpty(true, error?.message || 'Žádné odstíny.');
    } finally {
      state.loading = false;
      setBusy(false);
    }
  };

  const triggerSearch = () => {
    const query = elements.search ? elements.search.value.trim() : '';
    state.q = query;
    state.offset = 0;
    load(true);
  };

  if (elements.submit) {
    elements.submit.addEventListener('click', () => {
      triggerSearch();
    });
  }

  if (elements.reset) {
    elements.reset.addEventListener('click', () => {
      if (elements.search) elements.search.value = '';
      if (state.q === '') {
        load(true);
        return;
      }
      state.q = '';
      state.offset = 0;
      load(true);
    });
  }

  if (elements.search) {
    elements.search.addEventListener('keyup', (event) => {
      if (event.key === 'Enter') {
        triggerSearch();
      }
    });
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting && !state.initialized) {
        load(true);
      }
    });
  }, { rootMargin: '0px', threshold: 0.1 });

  observer.observe(pane);
})();
