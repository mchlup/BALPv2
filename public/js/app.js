(() => {
  const storageKey = 'balp_token';
  const sidebarStorageKey = 'balp_sidebar_collapsed';
  const tabsState = new Map();
  const moduleButtons = new Map();
  let modulesState = [];
  let activeModuleSlug = null;
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

  const resolveModuleIcon = (module) => {
    const icon = module?.ui?.icon;
    if (!icon) return '';
    if (/^https?:\/\//i.test(icon) || icon.startsWith('/')) return icon;
    const normalized = icon.replace(/^\.\//, '').replace(/^\//, '');
    return resolveAssetPath(`modules/${module.slug}/${normalized}`);
  };

  const updateSidebarToggleIcon = (collapsed) => {
    const icon = $('#sidebarToggle .bi');
    if (icon) {
      icon.className = collapsed ? 'bi bi-chevron-double-right' : 'bi bi-chevron-double-left';
    }
    const toggle = $('#sidebarToggle');
    if (toggle) {
      toggle.setAttribute('aria-label', collapsed ? 'Rozbalit menu' : 'Sbalit menu');
    }
  };

  const setSidebarCollapsed = (collapsed) => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    updateSidebarToggleIcon(collapsed);
    try {
      localStorage.setItem(sidebarStorageKey, collapsed ? '1' : '0');
    } catch {}
  };

  const initSidebar = () => {
    const toggle = $('#sidebarToggle');
    toggle?.addEventListener('click', () => {
      const collapsed = !document.body.classList.contains('sidebar-collapsed');
      setSidebarCollapsed(collapsed);
    });
    let stored = null;
    try { stored = localStorage.getItem(sidebarStorageKey); } catch {}
    setSidebarCollapsed(stored === '1');
  };

  const ensureTabContent = (tabKey) => {
    const entry = tabsState.get(tabKey);
    if (!entry) return Promise.resolve();
    if (entry.loaded) return Promise.resolve();
    if (entry.loadingPromise) return entry.loadingPromise;

    entry.loadingPromise = (async () => {
      const { module, tab, pane } = entry;
      pane.innerHTML = '<div class="module-pane-loading">Načítám modul…</div>';

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

  const sortModules = (modules) => {
    return modules.slice().sort((a, b) => {
      const orderDiff = (a.order ?? 100) - (b.order ?? 100);
      if (orderDiff !== 0) return orderDiff;
      const nameA = (a.name || a.slug || '').toLocaleLowerCase('cs');
      const nameB = (b.name || b.slug || '').toLocaleLowerCase('cs');
      return nameA.localeCompare(nameB, 'cs');
    });
  };

  const sortTabs = (tabs) => {
    return tabs.slice().sort((a, b) => {
      const orderDiff = (a.order ?? 100) - (b.order ?? 100);
      if (orderDiff !== 0) return orderDiff;
      const labelA = (a.label || a.slug || '').toLocaleLowerCase('cs');
      const labelB = (b.label || b.slug || '').toLocaleLowerCase('cs');
      return labelA.localeCompare(labelB, 'cs');
    });
  };

  const showEmptyState = (message) => {
    const empty = $('#moduleEmptyState');
    if (empty) {
      if (message) empty.textContent = message;
      empty.classList.remove('d-none');
    }
  };

  const hideEmptyState = () => {
    $('#moduleEmptyState')?.classList.add('d-none');
  };

  const renderModules = (modules) => {
    modulesState = sortModules(modules);
    moduleButtons.clear();
    tabsState.clear();

    const menu = $('#moduleMenu');
    const content = $('#moduleTabsContent');
    const nav = $('#moduleTabNav');
    const moduleTitle = $('#moduleTitle');
    const moduleDescription = $('#moduleDescription');

    if (menu) menu.innerHTML = '';
    if (nav) nav.innerHTML = '';
    if (content) content.innerHTML = '';

    if (moduleTitle) moduleTitle.textContent = 'Vyberte modul';
    if (moduleDescription) {
      moduleDescription.textContent = 'Zvolte modul z nabídky vlevo.';
      moduleDescription.classList.remove('d-none');
    }
    showEmptyState('Vyberte modul z nabídky vlevo.');

    modulesState.forEach((module) => {
      const moduleTabs = sortTabs(module.ui?.tabs || []);
      moduleTabs.forEach((tab) => {
        const tabId = tab.tab_id || `tab-${module.slug}-${tab.slug}`;
        const paneId = tab.pane_id || `pane-${module.slug}-${tab.slug}`;
        const key = `${module.slug}:${tab.slug}`;

        const pane = document.createElement('div');
        pane.className = 'module-pane';
        pane.id = paneId;
        pane.dataset.module = module.slug;
        pane.setAttribute('role', 'tabpanel');
        pane.setAttribute('aria-labelledby', tabId);
        pane.setAttribute('tabindex', '0');
        content?.appendChild(pane);

        tabsState.set(key, {
          module,
          tab,
          pane,
          tabId,
          paneId,
          navButton: null,
          loaded: false,
          loadingPromise: null,
        });
      });

      if (!menu) return;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sidebar-link';
      button.dataset.module = module.slug;
      button.title = module.name || module.slug;

      const iconWrap = document.createElement('span');
      iconWrap.className = 'sidebar-icon';
      const iconUrl = resolveModuleIcon(module);
      if (iconUrl) {
        const img = document.createElement('img');
        img.src = iconUrl;
        img.alt = '';
        iconWrap.appendChild(img);
      } else {
        const fallback = document.createElement('span');
        fallback.textContent = (module.name || module.slug || '?').slice(0, 1).toUpperCase();
        fallback.style.fontWeight = '700';
        fallback.style.color = '#fff';
        iconWrap.appendChild(fallback);
      }

      const label = document.createElement('span');
      label.className = 'sidebar-label';
      label.textContent = module.name || module.slug;

      button.appendChild(iconWrap);
      button.appendChild(label);

      button.addEventListener('click', () => activateModule(module.slug));

      menu.appendChild(button);
      moduleButtons.set(module.slug, button);
    });

    if (!modulesState.length) {
      showEmptyState('Nebyl nalezen žádný modul.');
      activeModuleSlug = null;
      activeTabKey = null;
      return;
    }

    const initialModule = modulesState[0];
    if (initialModule) {
      activateModule(initialModule.slug);
    }
  };

  const activateModule = (slug) => {
    const module = modulesState.find((m) => m.slug === slug);
    if (!module) return;

    moduleButtons.forEach((btn, moduleSlug) => {
      if (!btn) return;
      if (moduleSlug === slug) btn.classList.add('active');
      else btn.classList.remove('active');
    });

    activeModuleSlug = slug;

    const moduleTitle = $('#moduleTitle');
    if (moduleTitle) moduleTitle.textContent = module.name || module.slug;

    const moduleDescription = $('#moduleDescription');
    if (moduleDescription) {
      if (module.description) {
        moduleDescription.textContent = module.description;
        moduleDescription.classList.remove('d-none');
      } else {
        moduleDescription.textContent = '';
        moduleDescription.classList.add('d-none');
      }
    }

    const moduleTabNav = $('#moduleTabNav');
    if (!moduleTabNav) return;
    moduleTabNav.innerHTML = '';

    const moduleTabs = sortTabs(module.ui?.tabs || []);
    moduleTabs.forEach((tab) => {
      const key = `${module.slug}:${tab.slug}`;
      const entry = tabsState.get(key);
      if (!entry) return;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'module-tab-button';
      button.id = entry.tabId;
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-controls', entry.paneId);
      button.setAttribute('aria-selected', 'false');
      button.textContent = tab.label || module.name || module.slug;
      button.addEventListener('click', () => activateTab(key));

      moduleTabNav.appendChild(button);
      entry.navButton = button;
    });

    moduleTabNav.classList.toggle('d-none', moduleTabs.length <= 1);

    if (!moduleTabs.length) {
      showEmptyState('Tento modul nemá žádné dostupné zobrazení.');
      if (activeTabKey) {
        const prevEntry = tabsState.get(activeTabKey);
        prevEntry?.pane?.classList?.remove('active');
        if (prevEntry?.navButton) {
          prevEntry.navButton.classList.remove('active');
          prevEntry.navButton.setAttribute('aria-selected', 'false');
        }
      }
      activeTabKey = null;
      return;
    }

    hideEmptyState();

    let targetKey = `${module.slug}:${moduleTabs[0].slug}`;
    if (activeTabKey && activeTabKey.startsWith(`${module.slug}:`)) {
      targetKey = activeTabKey;
    }
    activateTab(targetKey);
  };

  const activateTab = (tabKey) => {
    const entry = tabsState.get(tabKey);
    if (!entry) return;

    if (activeTabKey === tabKey && entry.pane.classList.contains('active')) {
      ensureTabContent(tabKey).catch(() => {});
      return;
    }

    if (activeTabKey) {
      const prevEntry = tabsState.get(activeTabKey);
      if (prevEntry) {
        prevEntry.pane.classList.remove('active');
        if (prevEntry.navButton) {
          prevEntry.navButton.classList.remove('active');
          prevEntry.navButton.setAttribute('aria-selected', 'false');
        }
      }
    }

    activeTabKey = tabKey;
    entry.pane.classList.add('active');
    if (entry.navButton) {
      entry.navButton.classList.add('active');
      entry.navButton.setAttribute('aria-selected', 'true');
    }

    ensureTabContent(tabKey)
      .finally(() => emitTabReady(entry));
  };

  const loadUsersTab = (force) => {
    const entry = tabsState.get('nastaveni:uzivatele');
    const pane = entry?.pane;
    if (!pane) return;
    if (!force && pane.dataset.loaded === '1') return;

    delete pane.dataset.loaded;
    const placeholder = pane.querySelector('[data-users-placeholder]');
    if (placeholder) {
      placeholder.innerHTML = '<div class="text-muted">Načítám seznam uživatelů…</div>';
    } else {
      pane.innerHTML = '<div class="p-3 text-muted">Načítám…</div>';
    }

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
        try {
          document.dispatchEvent(new CustomEvent('auth:ready', { detail: { authenticated: true } }));
        } catch {}
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
      renderModules(modules);
    } catch (err) {
      showAlert('Načtení seznamu modulů selhalo: ' + err.message, 'danger');
    }
  };

  document.addEventListener('balp:tab-shown', (event) => {
    const detail = event.detail || {};
    if (detail.module === 'nastaveni' && detail.tab === 'uzivatele') {
      loadUsersTab(detail.refresh === true);
    }
  });

  document.addEventListener('DOMContentLoaded', async () => {
    hideAlert();
    initSidebar();
    initAuthUi();
    await loadModules();
    const autoLogged = await tryAutoLogin();
    try {
      document.dispatchEvent(new CustomEvent('auth:ready', { detail: { authenticated: autoLogged } }));
    } catch {}
    if (autoLogged) {
      refreshActiveTab();
    }
  });

  window.BALP = Object.assign(window.BALP || {}, {
    showAlert,
    hideAlert,
  });
})();
