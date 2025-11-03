(() => {
  const pane = document.getElementById('pane-databaze');
  if (!pane || pane.dataset.controllerInitialized) {
    return;
  }
  pane.dataset.controllerInitialized = '1';

  const apiBase = (window.API_URL || '/balp2/api.php').replace(/\/api\.php$/i, '');
  const endpoints = {
    load: `${apiBase}/api/config_get.php`,
    save: `${apiBase}/api/config_save.php`,
    test: `${apiBase}/api/db_connection_test.php`,
    backup: `${apiBase}/api/db_backup.php`,
    restore: `${apiBase}/api/db_restore.php`,
    optimize: `${apiBase}/api/db_optimize.php`,
  };

  const storageKey = 'balp_token';
  const getToken = () => {
    try { return localStorage.getItem(storageKey) || ''; } catch { return ''; }
  };

  const elements = {
    alert: pane.querySelector('#db-alert'),
    form: pane.querySelector('#db-settings-form'),
    driver: pane.querySelector('#db-driver'),
    host: pane.querySelector('#db-host'),
    port: pane.querySelector('#db-port'),
    database: pane.querySelector('#db-database'),
    username: pane.querySelector('#db-username'),
    password: pane.querySelector('#db-password'),
    charset: pane.querySelector('#db-charset'),
    collation: pane.querySelector('#db-collation'),
    dsn: pane.querySelector('#db-dsn'),
    reload: pane.querySelector('[data-action="reload"]'),
    test: pane.querySelector('[data-action="test-connection"]'),
    showDsn: pane.querySelector('[data-action="show-dsn"]'),
    backup: pane.querySelector('[data-action="backup"]'),
    restore: pane.querySelector('[data-action="restore"]'),
    optimize: pane.querySelector('[data-action="optimize"]'),
    restoreFile: pane.querySelector('#db-restore-file'),
    testOutput: pane.querySelector('#db-test-output'),
    maintenanceOutput: pane.querySelector('#db-maintenance-output'),
  };

  const state = {
    loading: false,
    saving: false,
    original: null,
  };

  const clearAlert = () => {
    if (!elements.alert) return;
    elements.alert.className = 'alert d-none';
    elements.alert.textContent = '';
  };

  const showAlert = (message, type = 'info') => {
    if (!elements.alert) return;
    elements.alert.textContent = message;
    elements.alert.className = `alert alert-${type}`;
    elements.alert.classList.remove('d-none');
  };

  const appendLog = (container, message, type = 'info') => {
    if (!container) return;
    const ts = new Date().toLocaleTimeString();
    container.innerHTML = `<span class="text-${type === 'danger' ? 'danger' : type === 'success' ? 'success' : 'muted'}">[${ts}] ${message}</span>`;
  };

  const apiFetch = async (url, options = {}) => {
    const token = getToken();
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token) headers['Authorization'] = 'Bearer ' + token;
    const method = options.method || 'POST';
    const finalUrl = method === 'GET'
      ? (url.includes('?') ? `${url}&_ts=${Date.now()}` : `${url}?_ts=${Date.now()}`)
      : url;
    const resp = await fetch(finalUrl, {
      method,
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

  const populateForm = (config) => {
    const db = config?.db || {};
    if (elements.driver) elements.driver.value = db.driver || 'mysql';
    if (elements.host) elements.host.value = db.host || '';
    if (elements.port) elements.port.value = db.port ?? '';
    if (elements.database) elements.database.value = db.database || '';
    if (elements.username) elements.username.value = db.username || '';
    if (elements.password) elements.password.value = db.password || '';
    if (elements.charset) elements.charset.value = db.charset || '';
    if (elements.collation) elements.collation.value = db.collation || '';
    if (elements.dsn) elements.dsn.value = config?.db_dsn || '';
  };

  const getDbPayload = () => {
    const db = {
      driver: elements.driver ? elements.driver.value.trim() || 'mysql' : 'mysql',
      host: elements.host ? elements.host.value.trim() : '',
      port: elements.port ? Number(elements.port.value || 3306) : 3306,
      database: elements.database ? elements.database.value.trim() : '',
      username: elements.username ? elements.username.value.trim() : '',
      password: elements.password ? elements.password.value : '',
      charset: elements.charset ? elements.charset.value.trim() || 'utf8mb4' : 'utf8mb4',
      collation: elements.collation ? elements.collation.value.trim() || 'utf8mb4_czech_ci' : 'utf8mb4_czech_ci',
    };
    const payload = { db };
    const dsnValue = elements.dsn ? elements.dsn.value.trim() : '';
    if (dsnValue) {
      payload.db_dsn = dsnValue;
    }
    return payload;
  };

  const loadConfig = async (force = false) => {
    if (state.loading) return;
    if (state.original && !force) return;
    state.loading = true;
    try {
      clearAlert();
      const data = await apiFetch(endpoints.load, { method: 'GET' });
      const config = data?.config || {};
      state.original = config;
      populateForm(config);
    } catch (err) {
      showAlert(err.message || 'Načtení konfigurace selhalo.', 'danger');
    } finally {
      state.loading = false;
    }
  };

  const saveConfig = async () => {
    if (state.saving) return;
    state.saving = true;
    try {
      clearAlert();
      const payload = getDbPayload();
      const body = { config: { db: payload.db } };
      if (payload.db_dsn) {
        body.config.db_dsn = payload.db_dsn;
      }
      const data = await apiFetch(endpoints.save, {
        method: 'POST',
        body: JSON.stringify(body),
      });
      if (data?.config) {
        state.original = data.config;
        populateForm(data.config);
      }
      showAlert('Databázové nastavení bylo uloženo.', 'success');
    } catch (err) {
      showAlert(err.message || 'Uložení selhalo.', 'danger');
    } finally {
      state.saving = false;
    }
  };

  const formatDsn = () => {
    const payload = getDbPayload();
    if (payload.db_dsn) return payload.db_dsn;
    const db = payload.db;
    return `${db.driver}:host=${db.host || '127.0.0.1'};port=${db.port || 3306};dbname=${db.database || ''};charset=${db.charset || 'utf8mb4'}`;
  };

  const testConnection = async () => {
    try {
      appendLog(elements.testOutput, 'Testuji připojení...', 'info');
      const payload = getDbPayload();
      const body = { db: payload.db };
      if (payload.db_dsn) body.db_dsn = payload.db_dsn;
      const data = await apiFetch(endpoints.test, {
        method: 'POST',
        body: JSON.stringify(body),
      });
      const version = data?.server_version ? ` (MySQL ${data.server_version})` : '';
      appendLog(elements.testOutput, `Připojení úspěšné${version}.`, 'success');
      showAlert('Spojení s databází je funkční.', 'success');
    } catch (err) {
      appendLog(elements.testOutput, `Chyba: ${err.message}`, 'danger');
      showAlert(err.message || 'Test připojení selhal.', 'danger');
    }
  };

  const downloadBase64 = (filename, mime, base64) => {
    try {
      const binary = atob(base64);
      const len = binary.length;
      const bytes = new Uint8Array(len);
      for (let i = 0; i < len; i += 1) {
        bytes[i] = binary.charCodeAt(i);
      }
      const blob = new Blob([bytes], { type: mime || 'application/octet-stream' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename || 'backup.json';
      document.body.appendChild(link);
      link.click();
      setTimeout(() => {
        URL.revokeObjectURL(url);
        link.remove();
      }, 0);
      appendLog(elements.maintenanceOutput, `Záloha byla vytvořena jako ${filename}.`, 'success');
    } catch (err) {
      appendLog(elements.maintenanceOutput, `Nepodařilo se připravit soubor: ${err.message}`, 'danger');
    }
  };

  const handleBackup = async () => {
    try {
      appendLog(elements.maintenanceOutput, 'Probíhá záloha databáze...', 'info');
      const data = await apiFetch(endpoints.backup, { method: 'POST', body: JSON.stringify({}) });
      if (data?.file?.content) {
        downloadBase64(data.file.name || 'backup.json', data.file.mime || 'application/json', data.file.content);
      } else {
        appendLog(elements.maintenanceOutput, 'Server nevrátil žádný soubor.', 'danger');
      }
    } catch (err) {
      appendLog(elements.maintenanceOutput, `Záloha selhala: ${err.message}`, 'danger');
      showAlert(err.message || 'Záloha se nepodařila.', 'danger');
    }
  };

  const arrayBufferToBase64 = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 1) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
  };

  const handleRestore = async (file) => {
    if (!file) return;
    try {
      appendLog(elements.maintenanceOutput, `Nahrávám soubor ${file.name}...`, 'info');
      const buffer = await file.arrayBuffer();
      const base64 = arrayBufferToBase64(buffer);
      const data = await apiFetch(endpoints.restore, {
        method: 'POST',
        body: JSON.stringify({ content: base64, filename: file.name }),
      });
      appendLog(elements.maintenanceOutput, `Obnova dokončena: tabulky ${data.tables}, řádků ${data.rows}.`, 'success');
      showAlert('Data byla úspěšně obnovena.', 'success');
    } catch (err) {
      appendLog(elements.maintenanceOutput, `Obnova selhala: ${err.message}`, 'danger');
      showAlert(err.message || 'Obnova databáze se nezdařila.', 'danger');
    } finally {
      if (elements.restoreFile) {
        elements.restoreFile.value = '';
      }
    }
  };

  const handleOptimize = async () => {
    try {
      appendLog(elements.maintenanceOutput, 'Optimalizuji tabulky...', 'info');
      const data = await apiFetch(endpoints.optimize, {
        method: 'POST',
        body: JSON.stringify({}),
      });
      if (Array.isArray(data?.results)) {
        const rows = data.results
          .map((item) => `${item.table}: ${item.status === 'ok' ? 'OK' : 'Chyba'}${item.message ? ` – ${item.message}` : ''}`)
          .join('\n');
        appendLog(elements.maintenanceOutput, rows || 'Optimalizace dokončena.', 'success');
      } else {
        appendLog(elements.maintenanceOutput, 'Optimalizace dokončena.', 'success');
      }
      showAlert('Optimalizace tabulek proběhla.', 'success');
    } catch (err) {
      appendLog(elements.maintenanceOutput, `Optimalizace selhala: ${err.message}`, 'danger');
      showAlert(err.message || 'Optimalizace selhala.', 'danger');
    }
  };

  elements.form?.addEventListener('submit', (event) => {
    event.preventDefault();
    saveConfig();
  });

  elements.reload?.addEventListener('click', () => {
    state.original = null;
    loadConfig(true);
  });

  elements.test?.addEventListener('click', () => {
    testConnection();
  });

  elements.showDsn?.addEventListener('click', () => {
    const dsn = formatDsn();
    appendLog(elements.testOutput, `Aktuální DSN: ${dsn}`, 'info');
  });

  elements.backup?.addEventListener('click', () => {
    handleBackup();
  });

  elements.restore?.addEventListener('click', () => {
    elements.restoreFile?.click();
  });

  elements.restoreFile?.addEventListener('change', (event) => {
    const file = event.target?.files ? event.target.files[0] : null;
    if (file) {
      handleRestore(file);
    }
  });

  elements.optimize?.addEventListener('click', () => {
    handleOptimize();
  });

  document.addEventListener('balp:tab-shown', (event) => {
    const detail = event.detail || {};
    if (detail.paneId === 'pane-databaze') {
      loadConfig(detail.refresh === true);
    }
  });

  loadConfig(false);
})();
