(() => {
  const storageKey = 'balp_token';
  const tabsState = new Map();
  let activeTabKey = null;

  const $ = (sel, ctx = document) => ctx.querySelector(sel);

  const escapeHtml = (value) => {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const showAlert = (msg, type = 'info') => {
    const box = $('#alert-box');
    if (!box) return;
    box.className = `alert alert-${type}`;
    box.textContent = msg;
    box.classList.remove('d-none');
  };

  const hideAlert = () => {
    const box = $('#alert-box');
    if (!box) return;
    box.classList.add('d-none');
    box.textContent = '';
  };

  const setToken = (token) => {
    try {
      if (token) localStorage.setItem(storageKey, token);
      else localStorage.removeItem(storageKey);
    } catch {}
  };

  const getToken = () => {
    try { return localStorage.getItem(storageKey) || ''; } catch { return ''; }
  };

  const clearToken = () => setToken('');

  const apiHeaders = () => {
    const headers = { 'Content-Type': 'application/json' };
    const token = getToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;
    return headers;
  };

  const cacheBustedUrl = (url) => (url.includes('?') ? `${url}&_ts=${Date.now()}` : `${url}?_ts=${Date.now()}`);

  const apiFetch = async (url, opts = {}) => {
    const targetUrl = cacheBustedUrl(url);
    const resp = await fetch(targetUrl, {
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
  };

  const login = async (username, password) => {
    const payload = JSON.stringify({ username, password });
    const data = await apiFetch(`${API_URL}?action=auth_login`, { method: 'POST', body: payload });
    if (!data?.token) throw new Error('Server nevrátil token.');
    setToken(data.token);
    try {
      await apiFetch('/balp2/api/token_set_cookie.php', {
        method: 'POST',
        body: JSON.stringify({ token: data.token }),
      });
    } catch {}
    return data;
  };

  const authMe = async () => apiFetch(`${API_URL}?action=auth_me`);

  const updateUiLoggedIn = (user) => {
    const info = $('#user-info');
    if (info) info.textContent = user?.username ? `Přihlášen: ${user.username}` : 'Přihlášeno';
    $('#btn-login')?.classList?.add('d-none');
    $('#btn-logout')?.classList?.remove('d-none');
  };

  const updateUiLoggedOut = () => {
    const elUserInfo = $('#user-info');
    if (elUserInfo) elUserInfo.textContent = 'Nepřihlášen';
    $('#btn-login')?.classList?.remove('d-none');
    $('#btn-logout')?.classList?.add('d-none');
  };

  const ensureCss = (() => {
    const loaded = new Set();
    return (path) => {
      if (!path || loaded.has(path)) return Promise.resolve();
      loaded.add(path);
      return new Promise((resolve, reject) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = resolveAssetPath(path);
        link.onload = () => resolve();
        link.onerror = () => reject(new Error(`Nepodařilo se načíst CSS: ${path}`));
        document.head.appendChild(link);
      });
    };
  })();

  const ensureScript = (() => {
    const loaded = new Set();
    return (path) => {
      if (!path) return Promise.resolve();
      if (loaded.has(path)) return Promise.resolve();
      loaded.add(path);
      return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = resolveAssetPath(path);
        script.async = false;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Nepodařilo se načíst skript: ${path}`));
        document.head.appendChild(script);
      });
    };
  })();

  const resolveAssetPath = (path) => {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    if (path.startsWith('/')) return path;
    return './' + path.replace(/^\.\//, '');
  };

  const ensureTabContent = (tabKey) => {
    const entry = tabsState.get(tabKey);
    if (!entry) return Promise.resolve();
    if (entry.loaded) return Promise.resolve();
    if (entry.loadingPromise) return entry.loadingPromise;

    entry.loadingPromise = (async () => {
      const { module, tab, pane } = entry;
      pane.innerHTML = '<div class="p-3 text-muted">Načítám modul…</div>';

      try {
        const cssAssets = [];
        if (Array.isArray(module.assets?.css)) cssAssets.push(...module.assets.css);
        if (Array.isArray(module.ui?.assets?.css)) cssAssets.push(...module.ui.assets.css);
        if (Array.isArray(tab.assets?.css)) cssAssets.push(...tab.assets.css);
        await Promise.all(cssAssets.map(ensureCss));

        if (tab.view) {
          const resp = await fetch(resolveAssetPath(tab.view));
          if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
          pane.innerHTML = await resp.text();
        } else {
          pane.innerHTML = '<div class="p-3 text-muted">Modul nemá definovaný statický obsah.</div>';
        }

        const jsAssets = [];
        if (Array.isArray(module.assets?.js)) jsAssets.push(...module.assets.js);
        if (Array.isArray(module.ui?.assets?.js)) jsAssets.push(...module.ui.assets.js);
        if (Array.isArray(tab.assets?.js)) jsAssets.push(...tab.assets.js);
        for (const asset of jsAssets) {
          await ensureScript(asset);
        }

        entry.loaded = true;
      } catch (err) {
        pane.innerHTML = `<div class="p-3"><div class="alert alert-danger">Načtení modulu selhalo: ${escapeHtml(err.message || err)}</div></div>`;
        throw err;
      } finally {
        entry.loadingPromise = null;
      }
    })();

    return entry.loadingPromise;
  };

  const emitTabReady = (entry, extraDetail = {}) => {
    if (!entry) return;
    const detail = {
      module: entry.module.slug,
      tab: entry.tab.slug,
      paneId: entry.paneId,
      ...extraDetail,
    };
    document.dispatchEvent(new CustomEvent('balp:tab-shown', { detail }));
  };

  const renderModuleTabs = (modules) => {
    const nav = $('#moduleTabs');
    const content = $('#moduleTabsContent');
    if (!nav || !content) return;
    nav.innerHTML = '';
    content.innerHTML = '';
    tabsState.clear();

    const tabs = [];
    modules.forEach((module) => {
      const moduleTabs = module.ui?.tabs || [];
      moduleTabs.forEach((tab) => {
        tabs.push({ module, tab });
      });
    });

    tabs.sort((a, b) => (a.tab.order ?? 100) - (b.tab.order ?? 100));

    let firstKey = null;

    tabs.forEach(({ module, tab }) => {
      const tabId = tab.tab_id || `tab-${module.slug}-${tab.slug}`;
      const paneId = tab.pane_id || `pane-${module.slug}-${tab.slug}`;
      const key = `${module.slug}:${tab.slug}`;

      const li = document.createElement('li');
      li.className = 'nav-item';
      li.setAttribute('role', 'presentation');

      const button = document.createElement('button');
      button.className = 'nav-link';
      button.id = tabId;
      button.type = 'button';
      button.setAttribute('role', 'tab');
      button.setAttribute('data-bs-toggle', 'tab');
      button.setAttribute('data-bs-target', `#${paneId}`);
      button.textContent = tab.label || module.name || module.slug;

      li.appendChild(button);
      nav.appendChild(li);

      const pane = document.createElement('div');
      pane.className = 'tab-pane fade';
      pane.id = paneId;
      pane.setAttribute('role', 'tabpanel');
      pane.setAttribute('aria-labelledby', tabId);
      pane.innerHTML = '<div class="p-3 text-muted">Načítám…</div>';
      content.appendChild(pane);

      tabsState.set(key, {
        module,
        tab,
        pane,
        button,
        tabId,
        paneId,
        loaded: false,
        loadingPromise: null,
      });

      button.addEventListener('show.bs.tab', () => {
        ensureTabContent(key).catch(() => {});
      });

      button.addEventListener('shown.bs.tab', () => {
        activeTabKey = key;
        const entry = tabsState.get(key);
        ensureTabContent(key)
          .finally(() => emitTabReady(entry));
      });

      if (!firstKey) firstKey = key;
    });

    appendUsersTab(nav, content);

    if (firstKey) {
      const entry = tabsState.get(firstKey);
      if (entry) {
        entry.button.classList.add('active');
        entry.pane.classList.add('show', 'active');
        activeTabKey = firstKey;
        ensureTabContent(firstKey)
          .finally(() => emitTabReady(entry));
      }
    }
  };

  const appendUsersTab = (nav, content) => {
    const tabId = 'users-tab';
    const paneId = 'tab-users';
    const li = document.createElement('li');
    li.className = 'nav-item';
    li.setAttribute('role', 'presentation');

    const button = document.createElement('button');
    button.className = 'nav-link';
    button.id = tabId;
    button.type = 'button';
    button.setAttribute('role', 'tab');
    button.setAttribute('data-bs-toggle', 'tab');
    button.setAttribute('data-bs-target', `#${paneId}`);
    button.textContent = 'Uživatelé';
    li.appendChild(button);
    nav.appendChild(li);

    const pane = document.createElement('div');
    pane.className = 'tab-pane fade';
    pane.id = paneId;
    pane.setAttribute('role', 'tabpanel');
    pane.setAttribute('aria-labelledby', tabId);
    pane.innerHTML = '<div class="p-3 text-muted">Načítám…</div>';
    content.appendChild(pane);

    button.addEventListener('shown.bs.tab', () => loadUsersTab(false));
  };

  const loadUsersTab = (force) => {
    const pane = document.getElementById('tab-users');
    if (!pane) return;
    if (pane.dataset.loaded && !force) return;

    pane.innerHTML = '<div class="p-3 text-muted">Načítám…</div>';
    fetch('../admin_users.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then((r) => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then((html) => {
        pane.innerHTML = html;
        pane.dataset.loaded = '1';
      })
      .catch((err) => {
        pane.innerHTML = `<div class="p-3"><div class="alert alert-danger">Nepodařilo se načíst „Uživatelé“: ${escapeHtml(err.message || err)}</div></div>`;
      });
  };

  const tryAutoLogin = async () => {
    try {
      const me = await authMe();
      if (me?.user) {
        updateUiLoggedIn(me.user);
        return true;
      }
    } catch {}
    updateUiLoggedOut();
    return false;
  };

  const refreshActiveTab = () => {
    if (!activeTabKey) return;
    const entry = tabsState.get(activeTabKey);
    if (!entry) return;
    ensureTabContent(activeTabKey)
      .then(() => emitTabReady(entry, { refresh: true }))
      .catch(() => {});
  };

  const initAuthUi = () => {
    $('#btn-logout')?.addEventListener('click', () => {
      clearToken();
      document.cookie = 'balp_token=; Max-Age=0; path=/;';
      updateUiLoggedOut();
      showAlert('Odhlášeno.', 'secondary');
      refreshActiveTab();
    });

    $('#login-submit')?.addEventListener('click', async () => {
      const username = $('#login-username')?.value?.trim();
      const password = $('#login-password')?.value || '';
      if (!username || !password) {
        showAlert('Vyplňte uživatelské jméno i heslo.', 'warning');
        return;
      }
      try {
        hideAlert();
        await login(username, password);
        const me = await authMe();
        updateUiLoggedIn(me?.user || { username });
        const modalEl = document.getElementById('loginModal');
        const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
        modal?.hide();
        showAlert('Přihlášení proběhlo úspěšně.', 'success');
        refreshActiveTab();
      } catch (err) {
        showAlert('Přihlášení selhalo: ' + err.message, 'danger');
      }
    });
  };

  const loadModules = async () => {
    try {
      const data = await apiFetch(`${API_URL}?action=_modules`);
      const modules = data.modules || [];
      renderModuleTabs(modules);
    } catch (err) {
      showAlert('Načtení seznamu modulů selhalo: ' + err.message, 'danger');
    }
  };

  document.addEventListener('DOMContentLoaded', async () => {
    hideAlert();
    initAuthUi();
    await loadModules();
    await tryAutoLogin();
  });

  window.BALP = Object.assign(window.BALP || {}, {
    showAlert,
    hideAlert,
  });
})();
