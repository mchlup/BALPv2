(() => {
  const pane = document.getElementById('pane-nastaveni');
  if (!pane || pane.dataset.controllerInitialized) {
    return;
  }
  pane.dataset.controllerInitialized = '1';

  const apiBase = (window.API_URL || '/balp2/api.php').replace(/\/api\.php$/i, '');
  const endpoints = {
    load: `${apiBase}/api/config_get.php`,
    save: `${apiBase}/api/config_save.php`,
  };

  const storageKey = 'balp_token';
  const getToken = () => {
    try { return localStorage.getItem(storageKey) || ''; } catch { return ''; }
  };

  const defaultState = {
    original: null,
    loaded: false,
    loading: false,
    saving: false,
  };
  const state = { ...defaultState };

  const elements = {
    form: pane.querySelector('#settings-form'),
    alert: pane.querySelector('#settings-alert'),
    appUrl: pane.querySelector('#cfg-app-url'),
    authEnabled: pane.querySelector('#cfg-auth-enabled'),
    authUserTable: pane.querySelector('#cfg-auth-user-table'),
    authUsernameField: pane.querySelector('#cfg-auth-username-field'),
    authPasswordField: pane.querySelector('#cfg-auth-password-field'),
    authRoleField: pane.querySelector('#cfg-auth-role-field'),
    authPasswordAlgo: pane.querySelector('#cfg-auth-password-algo'),
    authLoginScheme: pane.querySelector('#cfg-auth-login-scheme'),
    authJwtSecret: pane.querySelector('#cfg-auth-jwt-secret'),
    authJwtTtl: pane.querySelector('#cfg-auth-jwt-ttl'),
    tablesContainer: pane.querySelector('#cfg-table-rows'),
    btnAddTable: pane.querySelector('[data-action="add-table"]'),
    btnReload: pane.querySelector('[data-action="reload"]'),
    btnReset: pane.querySelector('[data-action="reset"]'),
  };

  const clearAlert = () => {
    if (!elements.alert) return;
    elements.alert.classList.add('d-none');
    elements.alert.textContent = '';
    elements.alert.className = 'alert d-none';
  };

  const showAlert = (message, type = 'info') => {
    if (!elements.alert) return;
    elements.alert.textContent = message;
    elements.alert.className = `alert alert-${type}`;
    elements.alert.classList.remove('d-none');
  };

  const apiFetch = async (url, options = {}) => {
    const token = getToken();
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token) headers['Authorization'] = 'Bearer ' + token;
    const finalUrl = url.includes('?') ? `${url}&_ts=${Date.now()}` : `${url}?_ts=${Date.now()}`;
    const resp = await fetch(finalUrl, {
      method: options.method || 'POST',
      headers,
      body: options.body,
      credentials: 'include',
    });
    const text = await resp.text();
    let data = null;
    try { data = JSON.parse(text); } catch {}
    if (!resp.ok) {
      const errMsg = data?.error || data?.message || text || `HTTP ${resp.status}`;
      throw new Error(errMsg);
    }
    return data ?? text;
  };

  const resetTableRows = () => {
    if (!elements.tablesContainer) return;
    elements.tablesContainer.innerHTML = '';
  };

  const addTableRow = (key = '', value = '') => {
    if (!elements.tablesContainer) return;
    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center table-map-row';
    row.dataset.row = 'table';

    const colKey = document.createElement('div');
    colKey.className = 'col-sm-5 col-lg-4';
    const inputKey = document.createElement('input');
    inputKey.type = 'text';
    inputKey.className = 'form-control';
    inputKey.setAttribute('data-field', 'table-key');
    inputKey.placeholder = 'Klíč';
    inputKey.value = key;
    colKey.appendChild(inputKey);

    const colValue = document.createElement('div');
    colValue.className = 'col-sm-6 col-lg-6';
    const inputValue = document.createElement('input');
    inputValue.type = 'text';
    inputValue.className = 'form-control';
    inputValue.setAttribute('data-field', 'table-value');
    inputValue.placeholder = 'Tabulka';
    inputValue.value = value;
    colValue.appendChild(inputValue);

    const colActions = document.createElement('div');
    colActions.className = 'col-sm-1 col-lg-2 text-end';
    const btnRemove = document.createElement('button');
    btnRemove.type = 'button';
    btnRemove.className = 'btn btn-outline-danger btn-sm';
    btnRemove.setAttribute('data-action', 'remove-row');
    btnRemove.title = 'Odebrat mapování';
    btnRemove.textContent = '×';
    colActions.appendChild(btnRemove);

    row.append(colKey, colValue, colActions);
    elements.tablesContainer.appendChild(row);
  };

  const populateForm = (config) => {
    if (!config) return;
    const auth = config.auth || {};
    if (elements.appUrl) elements.appUrl.value = config.app_url || '';
    if (elements.authEnabled) elements.authEnabled.checked = !!auth.enabled;
    if (elements.authUserTable) elements.authUserTable.value = auth.user_table || '';
    if (elements.authUsernameField) elements.authUsernameField.value = auth.username_field || '';
    if (elements.authPasswordField) elements.authPasswordField.value = auth.password_field || '';
    if (elements.authRoleField) elements.authRoleField.value = auth.role_field || '';
    if (elements.authPasswordAlgo) elements.authPasswordAlgo.value = auth.password_algo || 'bcrypt';
    if (elements.authLoginScheme) elements.authLoginScheme.value = auth.login_scheme || 'usr_is_plain';
    if (elements.authJwtSecret) elements.authJwtSecret.value = auth.jwt_secret || '';
    if (elements.authJwtTtl) elements.authJwtTtl.value = auth.jwt_ttl_minutes ?? 120;

    resetTableRows();
    const tables = config.tables || {};
    const keys = Object.keys(tables);
    if (keys.length === 0) {
      addTableRow('', '');
    } else {
      keys.forEach((key) => addTableRow(key, tables[key]));
    }
  };

  const gatherTables = () => {
    if (!elements.tablesContainer) return {};
    const rows = elements.tablesContainer.querySelectorAll('[data-row="table"]');
    const result = {};
    rows.forEach((row) => {
      const keyInput = row.querySelector('[data-field="table-key"]');
      const valueInput = row.querySelector('[data-field="table-value"]');
      const key = keyInput ? keyInput.value.trim() : '';
      const value = valueInput ? valueInput.value.trim() : '';
      if (key && value) {
        result[key] = value;
      }
    });
    return result;
  };

  const loadConfig = async (force = false) => {
    if (state.loading) return;
    if (state.loaded && !force) return;
    state.loading = true;
    try {
      clearAlert();
      const data = await apiFetch(endpoints.load, { method: 'GET' });
      const config = data?.config || {};
      state.original = config;
      populateForm(config);
      state.loaded = true;
    } catch (err) {
      showAlert(err.message || 'Načtení konfigurace selhalo.', 'danger');
    } finally {
      state.loading = false;
    }
  };

  const getPayload = () => {
    const auth = {
      enabled: elements.authEnabled ? elements.authEnabled.checked : false,
      user_table: elements.authUserTable ? elements.authUserTable.value.trim() : '',
      username_field: elements.authUsernameField ? elements.authUsernameField.value.trim() : '',
      password_field: elements.authPasswordField ? elements.authPasswordField.value.trim() : '',
      role_field: elements.authRoleField ? elements.authRoleField.value.trim() : '',
      password_algo: elements.authPasswordAlgo ? elements.authPasswordAlgo.value : 'bcrypt',
      login_scheme: elements.authLoginScheme ? elements.authLoginScheme.value : 'usr_is_plain',
      jwt_secret: elements.authJwtSecret ? elements.authJwtSecret.value : '',
      jwt_ttl_minutes: elements.authJwtTtl ? Number(elements.authJwtTtl.value || 120) : 120,
    };
    if (!auth.role_field) auth.role_field = null;

    const payload = {
      app_url: elements.appUrl ? elements.appUrl.value.trim() : '',
      auth,
      tables: gatherTables(),
    };
    return payload;
  };

  const saveConfig = async () => {
    if (state.saving) return;
    state.saving = true;
    try {
      clearAlert();
      const payload = { config: getPayload() };
      const data = await apiFetch(endpoints.save, {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      if (data?.config) {
        state.original = data.config;
        populateForm(data.config);
      }
      showAlert('Konfigurace byla uložena.', 'success');
    } catch (err) {
      showAlert(err.message || 'Uložení selhalo.', 'danger');
    } finally {
      state.saving = false;
    }
  };

  const resetChanges = () => {
    if (state.original) {
      populateForm(state.original);
      showAlert('Změny byly vráceny na poslední uložený stav.', 'secondary');
    } else {
      loadConfig(true);
    }
  };

  elements.form?.addEventListener('submit', (evt) => {
    evt.preventDefault();
    saveConfig();
  });

  elements.btnAddTable?.addEventListener('click', () => {
    addTableRow('', '');
  });

  elements.btnReload?.addEventListener('click', () => {
    loadConfig(true);
  });

  elements.btnReset?.addEventListener('click', () => {
    resetChanges();
  });

  pane.addEventListener('click', (event) => {
    const target = event.target;
    if (target && target.matches('[data-action="remove-row"]')) {
      const row = target.closest('[data-row="table"]');
      if (row) {
        row.remove();
      }
    }
  });

  document.addEventListener('balp:tab-shown', (event) => {
    const detail = event.detail || {};
    if (detail.paneId === 'pane-nastaveni') {
      loadConfig(detail.refresh === true);
    }
  });

  // Inicializace při prvním načtení obsahu
  loadConfig(false);
})();
